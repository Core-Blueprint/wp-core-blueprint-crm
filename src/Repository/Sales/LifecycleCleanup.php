<?php
declare(strict_types=1);

namespace CB\CRM\Repository\Sales;

use CB\CRM\Content\Entity;
use CB\CRM\Database\Schema;
use CB\CRM\Domain\Sales\Rules;

defined( 'ABSPATH' ) || exit;

/**
 * Internal lifecycle cleanup for Sales identity references.
 *
 * Identity removal is called inside Lifecycle's EXISTING transaction. It must
 * not start/commit a nested transaction or bypass canonical CRM deletion.
 * Sales rows with other CRM participants remain; orphaned Sales records and
 * their history are removed together. No other CRM or Work rows are touched.
 */
final class LifecycleCleanup {
	public static function for_deleted_identity( string $party_type, int $party_id ): bool {
		global $wpdb;
		if ( ! Rules::valid_party_type( $party_type ) || $party_id <= 0 ) {
			return false;
		}
		if ( ! self::sales_schema_ready() ) {
			return true; // The 1.5 schema has no writable Sales records.
		}

		$links_table = Schema::sales_participants_table();
		$links = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT entity_type, entity_id FROM {$links_table} WHERE party_type = %s AND party_id = %d FOR UPDATE",
				$party_type,
				$party_id
			),
			ARRAY_A
		);
		if ( ! is_array( $links ) ) {
			return false;
		}

		$affected = [];
		foreach ( $links as $link ) {
			$type = $link['entity_type'] ?? null;
			$id = $link['entity_id'] ?? null;
			if ( ! is_string( $type ) || ! Rules::valid_entity_type( $type )
				|| ( ! is_int( $id ) && ! ctype_digit( (string) $id ) )
				|| (int) $id <= 0 ) {
				return false;
			}
			$key = $type . ':' . (int) $id;
			$affected[ $key ] = [ $type, (int) $id ];
		}

		if ( false === $wpdb->delete(
			$links_table,
			[ 'party_type' => $party_type, 'party_id' => $party_id ],
			[ '%s', '%d' ]
		) ) {
			return false;
		}

		foreach ( $affected as [ $type, $id ] ) {
			// The only mutator in Wave 1A is creation; participant additions to
			// existing records are not supported without additional locking.
			$remaining = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT id FROM {$links_table} WHERE entity_type = %s AND entity_id = %d LIMIT 1 FOR UPDATE",
					$type,
					$id
				),
				ARRAY_A
			);
			if ( is_array( $remaining ) ) {
				continue;
			}
			if ( null !== $remaining || '' !== (string) ( $wpdb->last_error ?? '' ) ) {
				return false;
			}
			if ( false === $wpdb->delete(
				Schema::sales_events_table(),
				[ 'entity_type' => $type, 'entity_id' => $id ],
				[ '%s', '%d' ]
			) ) {
				return false;
			}
			$table = Rules::LEAD === $type
				? Schema::sales_leads_table()
				: Schema::sales_opportunities_table();
			if ( false === $wpdb->delete( $table, [ 'id' => $id ], [ '%d' ] ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Remove WordPress-user IDs from Sales owners/events on account deletion.
	 *
	 * Called separately from deletion of CRM Contacts; CRM identity remains
	 * canonical even if its linked WordPress login has been deleted.
	 */
	public static function for_deleted_user( int $user_id ): bool {
		global $wpdb;
		if ( $user_id <= 0 ) {
			return false;
		}
		if ( ! self::sales_schema_ready() ) {
			return true;
		}
		if ( false === $wpdb->query( 'START TRANSACTION' ) ) {
			return false;
		}
		try {
			foreach ( [ Schema::sales_leads_table(), Schema::sales_opportunities_table() ] as $table ) {
				if ( false === $wpdb->update(
					$table,
					[ 'owner_user_id' => 0 ],
					[ 'owner_user_id' => $user_id ],
					[ '%d' ],
					[ '%d' ]
				) ) {
					throw new \RuntimeException( 'sales_owner_anonymization_failed' );
				}
			}
			if ( false === $wpdb->update(
				Schema::sales_events_table(),
				[ 'actor_user_id' => 0 ],
				[ 'actor_user_id' => $user_id ],
				[ '%d' ],
				[ '%d' ]
			) ) {
				throw new \RuntimeException( 'sales_actor_anonymization_failed' );
			}
			if ( false === $wpdb->query( 'COMMIT' ) ) {
				throw new \RuntimeException( 'sales_anonymization_commit_failed' );
			}
			return true;
		} catch ( \Throwable $error ) {
			$wpdb->query( 'ROLLBACK' );
			return false;
		}
	}

	private static function sales_schema_ready(): bool {
		return defined( 'CB_CRM_SCHEMA_VERSION' )
			&& version_compare( (string) get_option( Schema::OPTION, '' ), '1.6', '>=' )
			&& version_compare( CB_CRM_SCHEMA_VERSION, '1.6', '>=' );
	}
}

<?php
declare(strict_types=1);

namespace CB\CRM\Repository\Sales;

use CB\CRM\Content\Entity;
use CB\CRM\Database\Schema;
use CB\CRM\Domain\Sales\Access;
use CB\CRM\Domain\Sales\Rules;

defined( 'ABSPATH' ) || exit;

/**
 * Internal, capability-gated Sales creation boundary.
 *
 * Not a public API, REST endpoint or per-owner visibility implementation.
 * Callers cannot supply the actor, owner, status, outcome or conversion source.
 * This repository deliberately does not create WordPress accounts or CRM identities.
 */
final class Records {
	private const MAX_PARTICIPANTS = 50;

	/** @param array<string,mixed> $input
	 *  @param array<int,array<string,mixed>> $participants
	 */
	public static function create_lead( array $input, array $participants ): int|\WP_Error {
		return self::create( Rules::LEAD, $input, $participants );
	}

	/** @param array<string,mixed> $input
	 *  @param array<int,array<string,mixed>> $participants
	 */
	public static function create_opportunity( array $input, array $participants ): int|\WP_Error {
		return self::create( Rules::OPPORTUNITY, $input, $participants );
	}

	/**
	 * Internal staff read, not a customer-facing or row-private projection.
	 *
	 * @return array<string,mixed>|\WP_Error|null
	 */
	public static function get( string $type, int $id ): array|\WP_Error|null {
		global $wpdb;
		if ( ! Access::can_view() || ! self::schema_ready() ) {
			return self::error( 'crm_sales_forbidden', 'CRM Sales is unavailable.' );
		}
		if ( ! Rules::valid_entity_type( $type ) || $id <= 0 ) {
			return self::error( 'crm_sales_invalid_input', 'Invalid CRM Sales reference.' );
		}
		$table = self::table_for( $type );
		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d LIMIT 1", $id ),
			ARRAY_A
		);
		return is_array( $row ) ? $row : null;
	}

	/** @param array<string,mixed> $input
	 *  @param array<int,array<string,mixed>> $participants
	 */
	private static function create( string $type, array $input, array $participants ): int|\WP_Error {
		global $wpdb;
		if ( ! Access::can_manage() || ! self::schema_ready() ) {
			return self::error( 'crm_sales_forbidden', 'CRM Sales writes are unavailable.' );
		}
		$actor = get_current_user_id();
		if ( $actor <= 0 ) {
			return self::error( 'crm_sales_forbidden', 'A logged-in CRM actor is required.' );
		}

		$allowed = Rules::LEAD === $type
			? [ 'title', 'pipeline_id', 'stage_id' ]
			: [ 'title', 'pipeline_id', 'stage_id', 'amount_minor', 'currency' ];
		if ( array_diff( array_keys( $input ), $allowed )
			|| ! is_string( $input['title'] ?? null )
			|| ! is_int( $input['pipeline_id'] ?? null )
			|| ! is_int( $input['stage_id'] ?? null ) ) {
			return self::error( 'crm_sales_invalid_input', 'Invalid or unsupported CRM Sales fields.' );
		}

		$title = trim( sanitize_text_field( $input['title'] ) );
		$pipeline_id = $input['pipeline_id'];
		$stage_id = $input['stage_id'];
		if ( '' === $title || mb_strlen( $title ) > 190 || $pipeline_id <= 0 || $stage_id <= 0 ) {
			return self::error( 'crm_sales_invalid_input', 'Invalid CRM Sales title or stage.' );
		}
		if ( count( $participants ) > self::MAX_PARTICIPANTS || ! Rules::valid_participants( $participants ) ) {
			return self::error( 'crm_sales_invalid_participants', 'Invalid CRM Sales participant set.' );
		}

		$minor = $input['amount_minor'] ?? null;
		$currency = $input['currency'] ?? null;
		if ( Rules::OPPORTUNITY === $type
			&& ( ( null !== $minor && ! is_int( $minor ) )
				|| ( null !== $currency && ! is_string( $currency ) )
				|| ! Rules::valid_estimate( $minor, $currency ) ) ) {
			return self::error( 'crm_sales_invalid_estimate', 'Invalid opportunity estimate.' );
		}
		$now = current_time( 'mysql', true );
		if ( false === $wpdb->query( 'START TRANSACTION' ) ) {
			return self::error( 'crm_sales_storage_failed', 'Could not start CRM Sales transaction.' );
		}
		try {
			$pipeline = $wpdb->get_row(
				$wpdb->prepare(
					'SELECT id, entity_type, is_active FROM ' . Schema::sales_pipelines_table() . ' WHERE id = %d LIMIT 1 FOR UPDATE',
					$pipeline_id
				),
				ARRAY_A
			);
			if ( ! is_array( $pipeline )
				|| $type !== ( $pipeline['entity_type'] ?? null )
				|| 1 !== (int) ( $pipeline['is_active'] ?? 0 ) ) {
				throw new \DomainException( 'crm_sales_invalid_pipeline' );
			}
			$stage = $wpdb->get_row(
				$wpdb->prepare(
					'SELECT id, pipeline_id, is_active FROM ' . Schema::sales_stages_table() . ' WHERE id = %d LIMIT 1 FOR UPDATE',
					$stage_id
				),
				ARRAY_A
			);
			if ( ! is_array( $stage )
				|| $pipeline_id !== (int) ( $stage['pipeline_id'] ?? 0 )
				|| 1 !== (int) ( $stage['is_active'] ?? 0 ) ) {
				throw new \DomainException( 'crm_sales_invalid_stage' );
			}

			// WordPress post identities stay canonical; never copy or create PII.
			foreach ( $participants as $participant ) {
				$party_type = $participant['party_type'];
				$party_id = $participant['party_id'];
				$post_status = get_post_status( $party_id );
				if ( ! Entity::valid_owner( $party_type, $party_id )
					|| ! is_string( $post_status )
					|| in_array( $post_status, [ 'trash', 'auto-draft' ], true ) ) {
					throw new \DomainException( 'crm_sales_invalid_identity' );
				}
			}

			$data = [
				'title' => $title,
				'owner_user_id' => $actor,
				'pipeline_id' => $pipeline_id,
				'stage_id' => $stage_id,
				'revision' => 1,
				'created_at' => $now,
				'updated_at' => $now,
			];
			$formats = [ '%s', '%d', '%d', '%d', '%d', '%s', '%s' ];
			if ( Rules::LEAD === $type ) {
				$data['status'] = Rules::LEAD_OPEN;
				$formats[] = '%s';
			} else {
				$data['outcome'] = Rules::DEAL_OPEN;
				$data['amount_minor'] = $minor;
				$data['currency'] = $currency ?? '';
				array_push( $formats, '%s', '%d', '%s' );
			}
			if ( false === $wpdb->insert( self::table_for( $type ), $data, $formats ) || (int) $wpdb->insert_id <= 0 ) {
				throw new \RuntimeException( 'crm_sales_record_insert_failed' );
			}
			$id = (int) $wpdb->insert_id;

			foreach ( $participants as $participant ) {
				if ( false === $wpdb->insert(
					Schema::sales_participants_table(),
					[
						'entity_type' => $type,
						'entity_id' => $id,
						'party_type' => $participant['party_type'],
						'party_id' => $participant['party_id'],
						'role' => $participant['role'] ?? 'other',
						'created_at' => $now,
					],
					[ '%s', '%d', '%s', '%d', '%s', '%s' ]
				) ) {
					throw new \RuntimeException( 'crm_sales_participant_insert_failed' );
				}
			}
			if ( false === $wpdb->insert(
				Schema::sales_events_table(),
				[
					'entity_type' => $type,
					'entity_id' => $id,
					'event_type' => 'created',
					'to_stage_id' => $stage_id,
					'to_outcome' => Rules::LEAD === $type ? Rules::LEAD_OPEN : Rules::DEAL_OPEN,
					'actor_user_id' => $actor,
					'revision' => 1,
					'created_at' => $now,
				],
				[ '%s', '%d', '%s', '%d', '%s', '%d', '%d', '%s' ]
			) ) {
				throw new \RuntimeException( 'crm_sales_event_insert_failed' );
			}
			if ( false === $wpdb->query( 'COMMIT' ) ) {
				throw new \RuntimeException( 'crm_sales_commit_failed' );
			}
			return $id;
		} catch ( \Throwable $error ) {
			$wpdb->query( 'ROLLBACK' );
			$code = $error instanceof \DomainException ? $error->getMessage() : 'crm_sales_storage_failed';
			return self::error( $code, 'CRM Sales record was not created.' );
		}
	}

	private static function table_for( string $type ): string {
		return Rules::LEAD === $type ? Schema::sales_leads_table() : Schema::sales_opportunities_table();
	}

	private static function schema_ready(): bool {
		return defined( 'CB_CRM_SCHEMA_VERSION' )
			&& CB_CRM_SCHEMA_VERSION === (string) get_option( Schema::OPTION, '' );
	}

	private static function error( string $code, string $message ): \WP_Error {
		return new \WP_Error( $code, $message );
	}
}

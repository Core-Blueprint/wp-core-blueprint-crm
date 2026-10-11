<?php
declare(strict_types=1);

namespace CB\CRM\Repository;

defined( 'ABSPATH' ) || exit;

/**
 * Bounded, progressing WP-Cron backstop for the two CRM recovery queues.
 *
 * Only scans option names; schedules are handled by the owning repositories.
 * An invalid/persistently blocked intent still advances the cursor, so it
 * cannot indefinitely hide later intents. Never deletes/mutates the intent.
 */
final class RecoveryQueueScanner {
	private const PAGE_SIZE = 20;
	private const QUEUES = [
		'identity' => [ 'cb_crm_delete_intent_', 'cb_crm_recovery_cursor_identity' ],
		'user' => [ 'cb_crm_user_anonymize_intent_', 'cb_crm_recovery_cursor_user' ],
	];

	/** @return list<string> */
	public static function next_names( string $queue ): array {
		global $wpdb;
		if ( ! isset( self::QUEUES[ $queue ] ) ) {
			return [];
		}
		[ $prefix, $cursor_key ] = self::QUEUES[ $queue ];
		$stored = get_option( $cursor_key, 0 );
		if ( ! is_int( $stored ) && ( ! is_string( $stored ) || ! ctype_digit( $stored ) ) ) {
			error_log( '[Core Blueprint CRM] Recovery queue cursor is invalid.' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			return [];
		}
		$after = (int) $stored;
		if ( $after < 0 ) {
			return [];
		}
		$read = static function ( int $after ) use ( $wpdb, $prefix ): ?array {
			$wpdb->last_error = '';
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT option_id, option_name FROM {$wpdb->options}
					WHERE option_id > %d AND option_name LIKE %s
					ORDER BY option_id ASC LIMIT %d",
					$after,
					$wpdb->esc_like( $prefix ) . '%',
					self::PAGE_SIZE
				),
				ARRAY_A
			);
			return is_array( $rows ) && '' === (string) $wpdb->last_error ? $rows : null;
		};

		$rows = $read( $after );
		if ( null === $rows ) {
			error_log( '[Core Blueprint CRM] Recovery queue inventory read failed.' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			return [];
		}
		// Wrap only after exhausting newer option IDs. The next request will
		// then progress through the low-ID slice, rather than re-reading it
		// every time as the historical first-20 implementation did.
		if ( [] === $rows && $after > 0 ) {
			$rows = $read( 0 );
			if ( null === $rows ) {
				return [];
			}
			$after = 0;
		}
		if ( [] === $rows ) {
			return [];
		}

		$names = [];
		$cursor = $after;
		foreach ( $rows as $row ) {
			$id = $row['option_id'] ?? null;
			$name = $row['option_name'] ?? null;
			if ( ! is_numeric( $id ) || (int) $id <= $cursor
				|| ! is_string( $name ) || ! str_starts_with( $name, $prefix ) ) {
				error_log( '[Core Blueprint CRM] Recovery queue inventory row is invalid.' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				return [];
			}
			$cursor = (int) $id;
			$names[] = $name;
		}

		// Cursor persistence is required BEFORE scheduling any page. An
		// unsuccessful write must not masquerade as fair queue progress.
		if ( $cursor !== (int) $stored ) {
			update_option( $cursor_key, $cursor, false );
			if ( (int) get_option( $cursor_key, -1 ) !== $cursor ) {
				error_log( '[Core Blueprint CRM] Recovery queue cursor could not be saved.' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				return [];
			}
		}
		return $names;
	}
}

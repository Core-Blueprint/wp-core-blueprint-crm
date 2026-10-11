<?php
declare(strict_types=1);

namespace CB\CRM\Repository;

use CB\CRM\Database\Schema;
use CB\CRM\Governance;
use CB\CRM\Repository\Sales\LifecycleCleanup;

defined( 'ABSPATH' ) || exit;

/**
 * Durable, idempotent Sales owner/actor anonymisation after WP user deletion.
 *
 * Unlike a CRM Contact, a WordPress login is not the canonical CRM identity.
 * Intents contain only the numeric login ID. This is single-site-only until
 * network/site deletion semantics have separate integration coverage.
 */
final class UserAnonymizationRecovery {
	private const PREFIX = 'cb_crm_user_anonymize_intent_';
	private const HOOK = 'cb_crm_user_anonymize_reconcile';
	private const RETRY_SECONDS = 300;
	private const REPAIR_LIMIT = 20;

	public static function init(): void {
		add_action( 'delete_user', [ __CLASS__, 'before_delete' ], 10, 1 );
		add_action( 'wpmu_delete_user', [ __CLASS__, 'before_delete' ], 10, 1 );
		// Lifecycle::deleted_user handles the after-delete callback once.
		add_action( self::HOOK, [ __CLASS__, 'retry' ], 10, 1 );
		add_action( 'admin_init', [ __CLASS__, 'reschedule_pending' ], 25, 0 );
	}

	/** Pre-delete hook: persists intent before the irreversible WP operation. */
	public static function before_delete( int $user_id ): void {
		if ( ! self::supported() || $user_id <= 0 ) {
			return;
		}
		if ( ! self::remember( $user_id ) ) {
			self::report_failure( $user_id );
		}
	}

	/** After-delete hook: attempts the cleanup and keeps intent on failure. */
	public static function deleted_user( int $user_id ): void {
		if ( ! self::supported() || $user_id <= 0 ) {
			return;
		}
		// A third-party deletion caller may omit the pre-delete hook.
		if ( ! self::remember( $user_id ) || ! self::retry( $user_id ) ) {
			self::report_failure( $user_id );
		}
	}

	/** Cron or deliberate internal replay. No write while WP login exists. */
	public static function retry( int $user_id ): bool {
		global $wpdb;
		if ( ! self::supported() || $user_id <= 0 ) {
			return false;
		}
		$key = self::key( $user_id );
		$intent = get_option( $key, null );
		if ( null === $intent ) {
			return true; // Already acknowledged, including a duplicate cron event.
		}
		if ( ! self::valid( $intent, $user_id ) ) {
			self::report_failure( $user_id );
			return false;
		}

		// Do NOT use get_userdata() here: caches or site-specific roles could
		// make a living WordPress login appear missing or vice versa.
		$wpdb->last_error = '';
		$existing = $wpdb->get_var(
			$wpdb->prepare( "SELECT ID FROM {$wpdb->users} WHERE ID = %d LIMIT 1", $user_id )
		);
		if ( '' !== (string) $wpdb->last_error ) {
			self::report_failure( $user_id );
			return false;
		}
		if ( null !== $existing ) {
			return true; // Aborted deletion. Keep intent and retry later.
		}

		if ( ! LifecycleCleanup::for_deleted_user( $user_id ) ) {
			self::report_failure( $user_id );
			return false;
		}
		if ( ! delete_option( $key ) ) {
			self::report_failure( $user_id );
			return false;
		}
		return true;
	}

	/**
	 * Admin-side bounded fallback when the WP-Cron event was lost.
	 * Never mutates Sales data or trusts option payloads as an identity.
	 */
	public static function reschedule_pending(): void {
		global $wpdb;
		if ( ! self::supported() || ! current_user_can( 'cb_manage_crm' ) ) {
			return;
		}
		$names = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s ORDER BY option_id ASC LIMIT %d",
				$wpdb->esc_like( self::PREFIX ) . '%',
				self::REPAIR_LIMIT
			)
		);
		if ( ! is_array( $names ) ) {
			return;
		}
		foreach ( $names as $name ) {
			if ( ! is_string( $name ) || ! preg_match( '/^cb_crm_user_anonymize_intent_([1-9][0-9]*)$/D', $name, $matches ) ) {
				continue;
			}
			$id = (int) $matches[1];
			if ( self::valid( get_option( $name, null ), $id ) ) {
				self::schedule( $id );
			}
		}
	}

	private static function remember( int $user_id ): bool {
		$key = self::key( $user_id );
		$intent = get_option( $key, null );
		if ( null === $intent ) {
			$data = [ 'version' => 1, 'user_id' => $user_id ];
			if ( ! add_option( $key, $data, '', false ) ) {
				$intent = get_option( $key, null );
				if ( ! self::valid( $intent, $user_id ) ) {
					return false;
				}
			}
		} elseif ( ! self::valid( $intent, $user_id ) ) {
			return false;
		}
		return self::schedule( $user_id );
	}

	private static function schedule( int $user_id ): bool {
		if ( wp_next_scheduled( self::HOOK, [ $user_id ] ) ) {
			return true;
		}
		return true === wp_schedule_single_event( time() + self::RETRY_SECONDS, self::HOOK, [ $user_id ] );
	}

	private static function valid( mixed $intent, int $user_id ): bool {
		return is_array( $intent ) && 1 === ( $intent['version'] ?? null )
			&& $user_id > 0 && $user_id === ( $intent['user_id'] ?? null );
	}

	private static function key( int $id ): string {
		return self::PREFIX . $id;
	}

	private static function supported(): bool {
		// Site removal is NOT a network-login deletion on Multisite.
		return ! is_multisite() && defined( 'CB_CRM_SCHEMA_VERSION' )
			&& version_compare( CB_CRM_SCHEMA_VERSION, '1.6', '>=' )
			&& version_compare( (string) get_option( Schema::OPTION, '' ), '1.6', '>=' );
	}

	private static function report_failure( int $user_id ): void {
		Governance::record_cleanup_failed( 'user', $user_id );
		self::schedule( $user_id );
		error_log( sprintf( '[Core Blueprint CRM] Sales user anonymization recovery pending for WordPress user #%d.', $user_id ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
	}
}

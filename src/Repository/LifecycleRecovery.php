<?php
declare(strict_types=1);

namespace CB\CRM\Repository;

use CB\CRM\Content\Entity;
use CB\CRM\Content\PostTypes;
use CB\CRM\Governance;
use CB\CRM\Lifecycle;

defined( 'ABSPATH' ) || exit;

/**
 * Durable cleanup intent around non-transactional WordPress post deletion.
 * Intents must not live in postmeta: WordPress destroys that before deleted_post.
 * This is an internal hook/cron boundary, never a public Sales API.
 */
final class LifecycleRecovery {
	private const PREFIX = 'cb_crm_delete_intent_';
	private const HOOK = 'cb_crm_lifecycle_reconcile';
	private const RETRY_SECONDS = 300;

	public static function init(): void {
		add_filter( 'pre_delete_post', [ __CLASS__, 'pre_delete_post' ], 10, 3 );
		add_action( self::HOOK, [ __CLASS__, 'retry' ], 10, 1 );
		add_action( 'admin_init', [ __CLASS__, 'reschedule_pending' ], 20, 0 );
	}

	/** @return mixed Preserve a prior WordPress veto; false vetoes an unsafe delete. */
	public static function pre_delete_post( mixed $check, \WP_Post $post, bool $force_delete ): mixed {
		unset( $force_delete ); // A custom post type may be permanently deleted without force=true.
		if ( null !== $check ) {
			return $check;
		}
		$type = match ( $post->post_type ) {
			PostTypes::CONTACT => Entity::CONTACT,
			PostTypes::ORGANIZATION => Entity::ORGANIZATION,
			default => '',
		};
		if ( '' === $type ) {
			return null;
		}
		if ( ! self::remember( $type, (int) $post->ID ) ) {
			Governance::record_cleanup_failed( $type, (int) $post->ID );
			error_log( sprintf( '[Core Blueprint CRM] Deletion blocked: durable cleanup intent unavailable for %s #%d.', $type, $post->ID ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			return false;
		}
		return null;
	}

	/** Only delete an intent after the relational cleanup committed. */
	public static function complete( string $type, int $id ): bool {
		$key = self::key( $id );
		$pending = get_option( $key, null );
		if ( null === $pending ) {
			return true; // Legacy direct callbacks have no intent to acknowledge.
		}
		return self::valid( $pending, $type, $id ) && delete_option( $key );
	}

	/**
	 * A second check inside the publicly callable internal recovery boundary:
	 * callers must not bypass the durable intent OR the uncached post check.
	 */
	public static function may_reconcile( string $type, int $id ): bool {
		global $wpdb;
		if ( ! self::valid( get_option( self::key( $id ), null ), $type, $id ) ) {
			return false;
		}
		$wpdb->last_error = '';
		$found = $wpdb->get_var(
			$wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE ID = %d LIMIT 1", $id )
		);
		return null === $found && '' === (string) $wpdb->last_error;
	}

	/** Cron or explicit operator retry; never delete data while the post exists. */
	public static function retry( int $id ): bool {
		global $wpdb;
		if ( $id <= 0 ) {
			return false;
		}
		$pending = get_option( self::key( $id ), null );
		if ( null === $pending ) {
			return true; // Already completed.
		}
		if ( ! self::valid( $pending, null, $id ) ) {
			Governance::record_cleanup_failed( 'recovery', $id );
			return false; // Do not discard corrupt/unrecognized intent data.
		}
		$type = $pending['type'];
		$wpdb->last_error = '';
		$found = $wpdb->get_var(
			$wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE ID = %d LIMIT 1", $id )
		);
		if ( '' !== (string) $wpdb->last_error ) {
			self::retry_failed( $type, $id );
			return false;
		}
		if ( null !== $found ) {
			// Delete may have been vetoed or is still underway, including Trash.
			// Keep intent to avoid losing recovery if deletion completes later.
			return true;
		}
		if ( ! Lifecycle::reconcile_deleted_identity( $type, $id ) ) {
			self::retry_failed( $type, $id );
			return false;
		}
		if ( ! self::complete( $type, $id ) ) {
			self::retry_failed( $type, $id );
			return false;
		}
		Governance::record_record_deleted( $type, $id );
		return true;
	}

	public static function retry_failed( string $type, int $id ): void {
		Governance::record_cleanup_failed( $type, $id );
		if ( self::valid( get_option( self::key( $id ), null ), $type, $id ) ) {
			self::schedule( $id );
		}
		error_log( sprintf( '[Core Blueprint CRM] Relational deletion recovery pending for %s #%d.', $type, $id ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
	}

	/**
	 * Bounded admin backstop for a lost WP-Cron event. No Sales reads or
	 * mutations occur here; indexed wp_options option_name lookup only.
	 */
	public static function reschedule_pending(): void {
		if ( ! function_exists( 'current_user_can' ) || ! current_user_can( 'cb_manage_crm' ) ) {
			return;
		}
		$names = RecoveryQueueScanner::next_names( 'identity' );
		foreach ( $names as $name ) {
			if ( ! is_string( $name ) || ! preg_match( '/^cb_crm_delete_intent_([1-9][0-9]*)$/D', $name, $matches ) ) {
				continue;
			}
			$id = (int) $matches[1];
			if ( self::valid( get_option( $name, null ), null, $id ) ) {
				self::schedule( $id );
			}
		}
	}

	private static function remember( string $type, int $id ): bool {
		if ( $id <= 0 ) {
			return false;
		}
		$key = self::key( $id );
		$pending = get_option( $key, null );
		if ( null === $pending ) {
			if ( ! add_option( $key, [ 'version' => 1, 'type' => $type, 'id' => $id ], '', false )
				&& ! self::valid( get_option( $key, null ), $type, $id ) ) {
				return false;
			}
		} elseif ( ! self::valid( $pending, $type, $id ) ) {
			return false;
		}
		return self::schedule( $id );
	}

	private static function schedule( int $id ): bool {
		if ( wp_next_scheduled( self::HOOK, [ $id ] ) ) {
			return true;
		}
		return true === wp_schedule_single_event( time() + self::RETRY_SECONDS, self::HOOK, [ $id ] );
	}

	private static function valid( mixed $pending, ?string $type, int $id ): bool {
		return is_array( $pending )
			&& 1 === ( $pending['version'] ?? null )
			&& $id > 0
			&& $id === ( $pending['id'] ?? null )
			&& in_array( $pending['type'] ?? null, [ Entity::CONTACT, Entity::ORGANIZATION ], true )
			&& ( null === $type || $type === $pending['type'] );
	}

	private static function key( int $id ): string {
		return self::PREFIX . $id;
	}
}

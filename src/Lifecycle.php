<?php
declare(strict_types=1);

namespace CB\CRM;

use CB\CRM\Content\ContactIdentity;
use CB\CRM\Content\Entity;
use CB\CRM\Content\Meta;
use CB\CRM\Content\PostTypes;
use CB\CRM\Database\Schema;
use CB\CRM\Repository\Activity;

defined( 'ABSPATH' ) || exit;

/**
 * Keep extension-owned CRM data aligned with WordPress object lifecycle.
 *
 * WordPress owns posts/users. CRM owns the relational tables attached to those
 * objects, so permanent deletion must remove those rows explicitly. Trash is
 * intentionally untouched so records remain fully restorable.
 */
final class Lifecycle {
	public static function init(): void {
		add_action( 'deleted_post', [ __CLASS__, 'deleted_post' ], 20, 2 );
		add_action( 'deleted_user', [ __CLASS__, 'deleted_user' ], 20, 1 );
		add_action( 'wpmu_delete_user', [ __CLASS__, 'deleted_user' ], 20, 1 );
	}

	public static function deleted_post( int $post_id, \WP_Post $post ): void {
		$owner_type = self::owner_type( $post );
		if ( '' === $owner_type ) {
			return;
		}

		if ( ! self::cleanup_record( $owner_type, $post_id ) ) {
			Governance::record_cleanup_failed( $owner_type, $post_id );
			error_log( sprintf( '[Core Blueprint CRM] Relational cleanup failed after deleting %s #%d.', $owner_type, $post_id ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			return;
		}

		Governance::record_record_deleted( $owner_type, $post_id );
	}

	public static function deleted_user( int $user_id ): void {
		if ( $user_id <= 0 ) {
			return;
		}

		foreach ( ContactIdentity::contact_ids_for_user( $user_id ) as $contact_id ) {
			if ( PostTypes::CONTACT !== get_post_type( $contact_id ) ) {
				continue;
			}
			delete_post_meta( $contact_id, Meta::WP_USER_ID );
			if ( ContactIdentity::EMAIL_WP === sanitize_key( (string) get_post_meta( $contact_id, Meta::EMAIL_MODE, true ) ) ) {
				update_post_meta( $contact_id, Meta::EMAIL_MODE, ContactIdentity::EMAIL_CRM );
			}
			Governance::record_data_updated( Entity::CONTACT, $contact_id, 'wordpress_user' );
			Activity::record(
				Entity::CONTACT,
				$contact_id,
				'wordpress_user_unlinked',
				__( 'Linked WordPress account was deleted', 'core-blueprint-crm' ),
				'wordpress',
				get_current_user_id(),
				'user',
				(string) $user_id
			);
		}
	}

	private static function owner_type( \WP_Post $post ): string {
		return match ( $post->post_type ) {
			PostTypes::CONTACT      => Entity::CONTACT,
			PostTypes::ORGANIZATION => Entity::ORGANIZATION,
			PostTypes::SERVICE      => Entity::SERVICE,
			default                 => '',
		};
	}

	private static function cleanup_record( string $owner_type, int $owner_id ): bool {
		global $wpdb;

		$wpdb->query( 'START TRANSACTION' );
		try {
			$owner_tables = [
				Schema::contact_methods_table(),
				Schema::addresses_table(),
				Schema::names_table(),
				Schema::service_assignments_table(),
				Schema::notes_table(),
				Schema::activities_table(),
			];
			foreach ( $owner_tables as $table ) {
				if ( false === $wpdb->delete( $table, [ 'owner_type' => $owner_type, 'owner_id' => $owner_id ], [ '%s', '%d' ] ) ) {
					throw new \RuntimeException( 'owner_cleanup_failed' );
				}
			}

			if ( Entity::CONTACT === $owner_type ) {
				if ( false === $wpdb->delete( Schema::organization_relations_table(), [ 'contact_id' => $owner_id ], [ '%d' ] ) ) {
					throw new \RuntimeException( 'contact_relation_cleanup_failed' );
				}
			}
			if ( Entity::ORGANIZATION === $owner_type ) {
				if ( false === $wpdb->delete( Schema::organization_relations_table(), [ 'organization_id' => $owner_id ], [ '%d' ] ) ) {
					throw new \RuntimeException( 'organization_relation_cleanup_failed' );
				}
			}
			if ( Entity::SERVICE === $owner_type ) {
				if ( false === $wpdb->delete( Schema::service_assignments_table(), [ 'service_id' => $owner_id ], [ '%d' ] ) ) {
					throw new \RuntimeException( 'service_assignment_cleanup_failed' );
				}
			}

			$wpdb->query( 'COMMIT' );
			return true;
		} catch ( \Throwable $e ) {
			$wpdb->query( 'ROLLBACK' );
			return false;
		}
	}
}

<?php
declare(strict_types=1);

namespace CB\CRM\Application;

use CB\CRM\Content\ContactIdentity;
use CB\CRM\Content\PostTypes;

defined( 'ABSPATH' ) || exit;

/**
 * Canonical structured-identity snapshot -> CRM Contact provisioner.
 *
 * Email is the only automatic reconciliation key. A unique existing email
 * match is reused. Ambiguous matches fail closed. New Contacts are created
 * only when a valid email is present.
 */
final class ContactSnapshotProvisioner {
	private const LOCK_PREFIX = 'cb_crm_snapshot_provision_';
	private const LOCK_TTL = 30;

	/**
	 * @param array{
	 *   first_name:mixed,
	 *   name_prefix?:mixed,
	 *   last_name:mixed,
	 *   email:mixed,
	 *   phone?:mixed
	 * } $snapshot
	 * @return array{contact_id:int,created:bool}|\WP_Error
	 */
	public static function ensure( array $snapshot, int $post_author_id = 0 ): array|\WP_Error {
		$identity = self::normalize( $snapshot );
		if ( $identity instanceof \WP_Error ) {
			return $identity;
		}

		$matches = ContactIdentity::find_by_email( $identity['email'] );
		if ( count( $matches ) > 1 ) {
			return new \WP_Error( 'crm_snapshot_identity_conflict' );
		}
		if ( 1 === count( $matches ) ) {
			return [ 'contact_id' => (int) $matches[0], 'created' => false ];
		}

		$lock = self::acquire_lock( $identity['email'] );
		if ( $lock instanceof \WP_Error ) {
			return $lock;
		}

		try {
			$matches = ContactIdentity::find_by_email( $identity['email'] );
			if ( count( $matches ) > 1 ) {
				return new \WP_Error( 'crm_snapshot_identity_conflict' );
			}
			if ( 1 === count( $matches ) ) {
				return [ 'contact_id' => (int) $matches[0], 'created' => false ];
			}

			$title = trim( implode( ' ', array_filter(
				[ $identity['first_name'], $identity['name_prefix'], $identity['last_name'] ],
				static fn ( string $part ): bool => '' !== $part
			) ) );
			if ( '' === $title ) {
				return new \WP_Error( 'crm_snapshot_identity_invalid' );
			}

			$contact_id = wp_insert_post(
				[
					'post_type'   => PostTypes::CONTACT,
					'post_status' => 'draft',
					'post_title'  => $title,
					'post_author' => max( 0, $post_author_id ),
				],
				true
			);
			if ( is_wp_error( $contact_id ) || (int) $contact_id <= 0 ) {
				return new \WP_Error( 'crm_snapshot_create_failed' );
			}
			$contact_id = (int) $contact_id;

			$methods = [
				[
					'method_type' => 'email',
					'label'       => '',
					'value'       => $identity['email'],
					'is_primary'  => 1,
				],
			];
			if ( '' !== $identity['phone'] ) {
				$methods[] = [
					'method_type' => 'phone',
					'label'       => '',
					'value'       => $identity['phone'],
					'is_primary'  => 1,
				];
			}

			$result = RecordUpdater::update_contact(
				$contact_id,
				[
					'first_name'     => $identity['first_name'],
					'name_prefix'    => $identity['name_prefix'],
					'last_name'      => $identity['last_name'],
					'contact_methods'=> $methods,
				]
			);
			if ( is_wp_error( $result ) ) {
				wp_delete_post( $contact_id, true );
				return $result;
			}

			$published = wp_update_post( [ 'ID' => $contact_id, 'post_status' => 'publish' ], true );
			if ( is_wp_error( $published ) ) {
				wp_delete_post( $contact_id, true );
				return new \WP_Error( 'crm_snapshot_publish_failed' );
			}

			$matches = ContactIdentity::find_by_email( $identity['email'] );
			if ( [ $contact_id ] !== array_values( $matches ) ) {
				wp_delete_post( $contact_id, true );
				return new \WP_Error( count( $matches ) > 1 ? 'crm_snapshot_identity_conflict' : 'crm_snapshot_identity_link_failed' );
			}

			return [ 'contact_id' => $contact_id, 'created' => true ];
		} finally {
			self::release_lock( $identity['email'], $lock );
		}
	}

	/**
	 * @param array<string,mixed> $snapshot
	 * @return array{first_name:string,name_prefix:string,last_name:string,email:string,phone:string}|\WP_Error
	 */
	private static function normalize( array $snapshot ): array|\WP_Error {
		$first_name = self::scalar_text( $snapshot['first_name'] ?? '' );
		$name_prefix = self::scalar_text( $snapshot['name_prefix'] ?? '' );
		$last_name = self::scalar_text( $snapshot['last_name'] ?? '' );
		$email = is_scalar( $snapshot['email'] ?? null )
			? strtolower( sanitize_email( (string) $snapshot['email'] ) )
			: '';
		$phone = self::scalar_text( $snapshot['phone'] ?? '' );

		if ( '' === $first_name || '' === $last_name || ! is_email( $email ) ) {
			return new \WP_Error( 'crm_snapshot_identity_invalid' );
		}

		return compact( 'first_name', 'name_prefix', 'last_name', 'email', 'phone' );
	}

	private static function scalar_text( mixed $value ): string {
		return is_scalar( $value ) ? sanitize_text_field( trim( (string) $value ) ) : '';
	}

	/** @return string|\WP_Error */
	private static function acquire_lock( string $email ): string|\WP_Error {
		$option = self::lock_option( $email );
		$handle = (string) wp_json_encode(
			[
				'token'   => wp_generate_uuid4(),
				'expires' => time() + self::LOCK_TTL,
			]
		);
		if ( '' === $handle ) {
			return new \WP_Error( 'crm_snapshot_lock_failed' );
		}
		if ( add_option( $option, $handle, '', false ) ) {
			return $handle;
		}

		$existing = get_option( $option, '' );
		if ( ! is_string( $existing ) || '' === $existing ) {
			return new \WP_Error( 'crm_snapshot_provision_busy' );
		}
		$payload = json_decode( $existing, true );
		$expires = is_array( $payload ) ? (int) ( $payload['expires'] ?? 0 ) : 0;
		if ( $expires > time() ) {
			return new \WP_Error( 'crm_snapshot_provision_busy' );
		}
		if ( ! self::delete_lock_value( $option, $existing ) || ! add_option( $option, $handle, '', false ) ) {
			return new \WP_Error( 'crm_snapshot_provision_busy' );
		}
		return $handle;
	}

	private static function release_lock( string $email, string $handle ): void {
		self::delete_lock_value( self::lock_option( $email ), $handle );
	}

	private static function delete_lock_value( string $option, string $value ): bool {
		global $wpdb;
		if ( ! isset( $wpdb->options ) ) {
			return false;
		}
		$deleted = $wpdb->delete(
			$wpdb->options,
			[ 'option_name' => $option, 'option_value' => $value ],
			[ '%s', '%s' ]
		);
		if ( 1 !== $deleted ) {
			return false;
		}
		wp_cache_delete( $option, 'options' );
		return true;
	}

	private static function lock_option( string $email ): string {
		return self::LOCK_PREFIX . substr( hash( 'sha256', strtolower( $email ) ), 0, 40 );
	}

	private function __construct() {}
}

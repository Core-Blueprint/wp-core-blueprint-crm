<?php
declare(strict_types=1);

namespace CB\CRM\Application;

use CB\CRM\Content\ContactIdentity;
use CB\CRM\Content\PostTypes;

defined( 'ABSPATH' ) || exit;

/**
 * Canonical WP User -> CRM Contact provisioning service.
 *
 * A WordPress user link is authoritative. Email is only a collision signal:
 * this service never silently claims an existing Contact by email.
 */
final class ContactProvisioner {
	private const LOCK_PREFIX = 'cb_crm_identity_provision_';
	private const LOCK_TTL = 30;

	/** @return array{contact_id:int,created:bool}|\WP_Error */
	public static function ensure_for_user( int $user_id, int $post_author_id = 0 ): array|\WP_Error {
		$user = $user_id > 0 ? get_userdata( $user_id ) : false;
		if ( ! $user instanceof \WP_User ) {
			return new \WP_Error( 'crm_identity_user_not_found' );
		}

		$linked = ContactIdentity::contact_ids_for_user( $user_id );
		if ( count( $linked ) > 1 ) {
			return new \WP_Error( 'crm_identity_conflict' );
		}
		if ( 1 === count( $linked ) ) {
			return [ 'contact_id' => (int) $linked[0], 'created' => false ];
		}

		$lock = self::acquire_lock( $user_id );
		if ( is_wp_error( $lock ) ) {
			return $lock;
		}

		try {
			// Re-check identity after acquiring the atomic lease. Another request may
			// have linked the user while this request was waiting for the lock.
			$linked = ContactIdentity::contact_ids_for_user( $user_id );
			if ( count( $linked ) > 1 ) {
				return new \WP_Error( 'crm_identity_conflict' );
			}
			if ( 1 === count( $linked ) ) {
				return [ 'contact_id' => (int) $linked[0], 'created' => false ];
			}

			$email = sanitize_email( (string) $user->user_email );
			if ( '' !== $email && [] !== ContactIdentity::find_by_email( $email ) ) {
				return new \WP_Error( 'crm_identity_match_requires_review' );
			}

			$title = trim( (string) $user->display_name );
			if ( '' === $title ) {
				$title = '' !== (string) $user->user_login ? (string) $user->user_login : $email;
			}

			$contact_id = wp_insert_post(
				[
					'post_type'   => PostTypes::CONTACT,
					'post_status' => 'draft',
					'post_title'  => sanitize_text_field( $title ),
					'post_author' => max( 0, $post_author_id ),
				],
				true
			);
			if ( is_wp_error( $contact_id ) || (int) $contact_id <= 0 ) {
				return new \WP_Error( 'crm_identity_create_failed' );
			}
			$contact_id = (int) $contact_id;

			$result = RecordUpdater::update_contact(
				$contact_id,
				[
					'wp_user_id' => $user_id,
					'first_name' => (string) get_user_meta( $user_id, 'first_name', true ),
					'last_name'  => (string) get_user_meta( $user_id, 'last_name', true ),
					'email_mode' => ContactIdentity::EMAIL_WP,
				]
			);
			if ( is_wp_error( $result ) ) {
				wp_delete_post( $contact_id, true );
				return $result;
			}

			$published = wp_update_post( [ 'ID' => $contact_id, 'post_status' => 'publish' ], true );
			if ( is_wp_error( $published ) ) {
				wp_delete_post( $contact_id, true );
				return new \WP_Error( 'crm_identity_publish_failed' );
			}

			$linked_after = ContactIdentity::contact_ids_for_user( $user_id );
			if ( [ $contact_id ] !== array_values( $linked_after ) ) {
				// Never delete another Contact to resolve a race. Roll back only the
				// record created by this request and fail closed for operator review.
				wp_delete_post( $contact_id, true );
				return new \WP_Error( count( $linked_after ) > 1 ? 'crm_identity_conflict' : 'crm_identity_link_failed' );
			}

			return [ 'contact_id' => $contact_id, 'created' => true ];
		} finally {
			self::release_lock( $user_id, $lock );
		}
	}

	/** @return string|\WP_Error */
	private static function acquire_lock( int $user_id ): string|\WP_Error {
		$option = self::lock_option( $user_id );
		$handle = (string) wp_json_encode(
			[
				'token'   => wp_generate_uuid4(),
				'expires' => time() + self::LOCK_TTL,
			]
		);
		if ( '' === $handle ) {
			return new \WP_Error( 'crm_identity_lock_failed' );
		}

		if ( add_option( $option, $handle, '', false ) ) {
			self::release_on_shutdown( $user_id, $handle );
			return $handle;
		}

		$existing = get_option( $option, '' );
		if ( ! is_string( $existing ) || '' === $existing ) {
			return new \WP_Error( 'crm_identity_provision_busy' );
		}
		$payload = json_decode( $existing, true );
		$expires = is_array( $payload ) ? (int) ( $payload['expires'] ?? 0 ) : 0;
		if ( $expires > time() ) {
			return new \WP_Error( 'crm_identity_provision_busy' );
		}

		if ( ! self::delete_lock_value( $option, $existing ) || ! add_option( $option, $handle, '', false ) ) {
			return new \WP_Error( 'crm_identity_provision_busy' );
		}
		self::release_on_shutdown( $user_id, $handle );
		return $handle;
	}

	private static function release_lock( int $user_id, string $handle ): void {
		self::delete_lock_value( self::lock_option( $user_id ), $handle );
	}

	private static function release_on_shutdown( int $user_id, string $handle ): void {
		register_shutdown_function(
			static function () use ( $user_id, $handle ): void {
				self::release_lock( $user_id, $handle );
			}
		);
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

	private static function lock_option( int $user_id ): string {
		return self::LOCK_PREFIX . $user_id;
	}
}

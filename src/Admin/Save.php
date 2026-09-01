<?php
declare(strict_types=1);

namespace CB\CRM\Admin;

use CB\CRM\Capabilities;
use CB\CRM\Content\Entity;
use CB\CRM\Content\Meta;
use CB\CRM\Governance;
use CB\CRM\Repository\Activity;
use CB\CRM\Repository\Addresses;
use CB\CRM\Repository\ContactMethods;
use CB\CRM\Repository\Names;
use CB\CRM\Repository\Notes;
use CB\CRM\Repository\Organizations;
use CB\CRM\Repository\Services;

defined( 'ABSPATH' ) || exit;

final class Save {
	public static function init(): void {
		add_action( 'save_post', [ __CLASS__, 'record' ], 20, 3 );
	}

	public static function record( int $post_id, \WP_Post $post, bool $update ): void {
		unset( $update );
		$owner_type = Entity::owner_type_for_post( $post_id );
		if ( '' === $owner_type || wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) ) {
			return;
		}
		if ( ! isset( $_POST['cb_crm_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( (string) wp_unslash( $_POST['cb_crm_nonce'] ) ), 'cb_crm_save_record' ) ) {
			return;
		}
		if ( ! current_user_can( Capabilities::MANAGE ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		if ( 'trash' === $post->post_status ) {
			return;
		}

		$areas = [];
		$details = isset( $_POST['cb_crm_details'] ) && is_array( $_POST['cb_crm_details'] ) ? wp_unslash( $_POST['cb_crm_details'] ) : [];
		self::save_details( $owner_type, $post_id, $details );
		$areas[] = 'details';

		if ( isset( $_POST['cb_crm_contact_methods'] ) && is_array( $_POST['cb_crm_contact_methods'] ) ) {
			ContactMethods::replace( $owner_type, $post_id, wp_unslash( $_POST['cb_crm_contact_methods'] ) );
			$areas[] = 'contact_methods';
		}
		if ( isset( $_POST['cb_crm_addresses'] ) && is_array( $_POST['cb_crm_addresses'] ) ) {
			Addresses::replace( $owner_type, $post_id, wp_unslash( $_POST['cb_crm_addresses'] ) );
			$areas[] = 'addresses';
		}
		if ( isset( $_POST['cb_crm_names'] ) && is_array( $_POST['cb_crm_names'] ) ) {
			Names::replace( $owner_type, $post_id, wp_unslash( $_POST['cb_crm_names'] ) );
			$areas[] = 'names';
		}
		if ( Entity::CONTACT === $owner_type && isset( $_POST['cb_crm_organizations'] ) && is_array( $_POST['cb_crm_organizations'] ) ) {
			Organizations::replace_for_contact( $post_id, wp_unslash( $_POST['cb_crm_organizations'] ) );
			$areas[] = 'organizations';
		}
		if ( in_array( $owner_type, [ Entity::CONTACT, Entity::ORGANIZATION ], true ) && isset( $_POST['cb_crm_services'] ) && is_array( $_POST['cb_crm_services'] ) ) {
			Services::replace( $owner_type, $post_id, wp_unslash( $_POST['cb_crm_services'] ) );
			$areas[] = 'services';
		}

		$note = isset( $_POST['cb_crm_new_note'] ) ? sanitize_textarea_field( (string) wp_unslash( $_POST['cb_crm_new_note'] ) ) : '';
		if ( '' !== $note ) {
			$note_id = Notes::add( $owner_type, $post_id, get_current_user_id(), $note );
			if ( $note_id > 0 ) {
				Governance::record_note_created( $owner_type, $post_id, $note_id );
				Activity::record( $owner_type, $post_id, 'note_added', __( 'CRM note added', 'core-blueprint-crm' ), 'crm', get_current_user_id(), 'note', (string) $note_id );
			}
		}

		if ( $areas ) {
			Activity::record(
				$owner_type,
				$post_id,
				'record_updated',
				__( 'CRM record details updated', 'core-blueprint-crm' ),
				'crm',
				get_current_user_id(),
				'post',
				(string) $post_id,
				[ 'areas' => implode( ',', array_unique( $areas ) ) ]
			);
		}
	}

	/** @param array<string,mixed> $details */
	private static function save_details( string $owner_type, int $post_id, array $details ): void {
		update_post_meta( $post_id, Meta::STATUS, sanitize_key( (string) ( $details['status'] ?? '' ) ) );

		if ( Entity::CONTACT === $owner_type ) {
			update_post_meta( $post_id, Meta::FIRST_NAME, sanitize_text_field( (string) ( $details['first_name'] ?? '' ) ) );
			update_post_meta( $post_id, Meta::LAST_NAME, sanitize_text_field( (string) ( $details['last_name'] ?? '' ) ) );
			update_post_meta( $post_id, Meta::JOB_TITLE, sanitize_text_field( (string) ( $details['job_title'] ?? '' ) ) );
			$user_id = absint( $details['wp_user_id'] ?? 0 );
			if ( $user_id > 0 && ! get_userdata( $user_id ) ) {
				$user_id = 0;
			}
			update_post_meta( $post_id, Meta::WP_USER_ID, $user_id );
			$email_mode = sanitize_key( (string) ( $details['email_mode'] ?? 'crm' ) );
			update_post_meta( $post_id, Meta::EMAIL_MODE, in_array( $email_mode, [ 'crm', 'wp_user' ], true ) ? $email_mode : 'crm' );
		}

		if ( Entity::ORGANIZATION === $owner_type ) {
			update_post_meta( $post_id, Meta::LEGAL_NAME, sanitize_text_field( (string) ( $details['legal_name'] ?? '' ) ) );
		}
	}
}

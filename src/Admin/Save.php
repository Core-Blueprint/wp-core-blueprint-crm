<?php
declare(strict_types=1);

namespace CB\CRM\Admin;

use CB\CRM\Capabilities;
use CB\CRM\Content\ContactIdentity;
use CB\CRM\Content\Entity;
use CB\CRM\Content\Meta;
use CB\CRM\Content\RecordStatus;
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
	/** @var array<int,string[]> */
	private static array $failed_areas = [];

	public static function init(): void {
		add_action( 'save_post', [ __CLASS__, 'record' ], 20, 3 );
		add_filter( 'redirect_post_location', [ __CLASS__, 'redirect_post_location' ], 99, 2 );
		add_action( 'admin_notices', [ __CLASS__, 'admin_notice' ] );
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

		$areas    = [];
		$failures = [];
		$details  = isset( $_POST['cb_crm_details'] ) && is_array( $_POST['cb_crm_details'] ) ? wp_unslash( $_POST['cb_crm_details'] ) : [];
		$failures = array_merge( $failures, self::save_details( $owner_type, $post_id, $details ) );
		$areas[] = 'details';
		Governance::record_data_updated( $owner_type, $post_id, 'details' );

		if ( isset( $_POST['cb_crm_contact_methods_present'] ) ) {
			$rows = isset( $_POST['cb_crm_contact_methods'] ) && is_array( $_POST['cb_crm_contact_methods'] ) ? wp_unslash( $_POST['cb_crm_contact_methods'] ) : [];
			if ( ContactMethods::replace( $owner_type, $post_id, $rows ) ) {
				$areas[] = 'contact_methods';
				Governance::record_data_updated( $owner_type, $post_id, 'contact_methods' );
			} else {
				$failures[] = 'contact_methods';
			}
		}
		if ( isset( $_POST['cb_crm_addresses_present'] ) ) {
			$rows = isset( $_POST['cb_crm_addresses'] ) && is_array( $_POST['cb_crm_addresses'] ) ? wp_unslash( $_POST['cb_crm_addresses'] ) : [];
			if ( Addresses::replace( $owner_type, $post_id, $rows ) ) {
				$areas[] = 'addresses';
				Governance::record_data_updated( $owner_type, $post_id, 'addresses' );
			} else {
				$failures[] = 'addresses';
			}
		}
		if ( isset( $_POST['cb_crm_names_present'] ) ) {
			$rows = isset( $_POST['cb_crm_names'] ) && is_array( $_POST['cb_crm_names'] ) ? wp_unslash( $_POST['cb_crm_names'] ) : [];
			if ( Names::replace( $owner_type, $post_id, $rows ) ) {
				$areas[] = 'names';
				Governance::record_data_updated( $owner_type, $post_id, 'names' );
			} else {
				$failures[] = 'names';
			}
		}
		if ( Entity::CONTACT === $owner_type && isset( $_POST['cb_crm_organizations_present'] ) ) {
			$rows = isset( $_POST['cb_crm_organizations'] ) && is_array( $_POST['cb_crm_organizations'] ) ? wp_unslash( $_POST['cb_crm_organizations'] ) : [];
			if ( Organizations::replace_for_contact( $post_id, $rows ) ) {
				$areas[] = 'organizations';
				Governance::record_data_updated( $owner_type, $post_id, 'organizations' );
			} else {
				$failures[] = 'organizations';
			}
		}
		if ( in_array( $owner_type, [ Entity::CONTACT, Entity::ORGANIZATION ], true ) && isset( $_POST['cb_crm_services_present'] ) ) {
			$rows = isset( $_POST['cb_crm_services'] ) && is_array( $_POST['cb_crm_services'] ) ? wp_unslash( $_POST['cb_crm_services'] ) : [];
			if ( Services::replace( $owner_type, $post_id, $rows ) ) {
				$areas[] = 'services';
				Governance::record_data_updated( $owner_type, $post_id, 'services' );
			} else {
				$failures[] = 'services';
			}
		}

		$note = isset( $_POST['cb_crm_new_note'] ) ? sanitize_textarea_field( (string) wp_unslash( $_POST['cb_crm_new_note'] ) ) : '';
		if ( '' !== $note ) {
			$note_id = Notes::add( $owner_type, $post_id, get_current_user_id(), $note );
			if ( $note_id > 0 ) {
				Governance::record_note_created( $owner_type, $post_id, $note_id );
				Activity::record( $owner_type, $post_id, 'note_added', __( 'CRM note added', 'core-blueprint-crm' ), 'crm', get_current_user_id(), 'note', (string) $note_id );
			} else {
				$failures[] = 'note';
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

		if ( $failures ) {
			self::$failed_areas[ $post_id ] = array_values( array_unique( $failures ) );
		}
	}

	public static function redirect_post_location( string $location, int $post_id ): string {
		if ( empty( self::$failed_areas[ $post_id ] ) ) {
			return $location;
		}
		return add_query_arg( 'cb_crm_save_error', implode( ',', self::$failed_areas[ $post_id ] ), $location );
	}

	public static function admin_notice(): void {
		if ( ! isset( $_GET['cb_crm_save_error'] ) || ! current_user_can( Capabilities::MANAGE ) ) {
			return;
		}
		$screen = get_current_screen();
		if ( ! $screen || ! in_array( (string) $screen->post_type, [ \CB\CRM\Content\PostTypes::CONTACT, \CB\CRM\Content\PostTypes::ORGANIZATION, \CB\CRM\Content\PostTypes::SERVICE ], true ) ) {
			return;
		}
		$areas = array_filter( array_map( 'sanitize_key', explode( ',', (string) wp_unslash( $_GET['cb_crm_save_error'] ) ) ) );
		if ( ! $areas ) {
			return;
		}
		$labels = [
			'wordpress_user'  => __( 'WordPress account link', 'core-blueprint-crm' ),
			'contact_methods' => __( 'contact methods', 'core-blueprint-crm' ),
			'addresses'       => __( 'addresses', 'core-blueprint-crm' ),
			'names'           => __( 'names and aliases', 'core-blueprint-crm' ),
			'organizations'   => __( 'organization relationships', 'core-blueprint-crm' ),
			'services'        => __( 'service assignments', 'core-blueprint-crm' ),
			'note'            => __( 'note', 'core-blueprint-crm' ),
		];
		$failed = [];
		foreach ( $areas as $area ) {
			$failed[] = $labels[ $area ] ?? $area;
		}
		printf(
			'<div class="notice notice-error is-dismissible"><p>%s</p></div>',
			esc_html( sprintf( __( 'The post was saved, but CRM could not save: %s. Review the record and try again.', 'core-blueprint-crm' ), implode( ', ', $failed ) ) )
		);
	}

	/** @param array<string,mixed> $details @return string[] */
	private static function save_details( string $owner_type, int $post_id, array $details ): array {
		$failures = [];
		$status = RecordStatus::normalize( (string) ( $details['status'] ?? RecordStatus::ACTIVE ) );
		update_post_meta( $post_id, Meta::STATUS, $status );

		if ( Entity::CONTACT === $owner_type ) {
			update_post_meta( $post_id, Meta::FIRST_NAME, sanitize_text_field( (string) ( $details['first_name'] ?? '' ) ) );
			update_post_meta( $post_id, Meta::LAST_NAME, sanitize_text_field( (string) ( $details['last_name'] ?? '' ) ) );
			update_post_meta( $post_id, Meta::JOB_TITLE, sanitize_text_field( (string) ( $details['job_title'] ?? '' ) ) );

			$stored_user_id = ContactIdentity::linked_user_id( $post_id );
			$user_id = absint( $details['wp_user_id'] ?? 0 );
			if ( $user_id > 0 && ! get_userdata( $user_id ) ) {
				$user_id = 0;
				$failures[] = 'wordpress_user';
			} elseif ( $user_id > 0 && ! ContactIdentity::can_link_user_to_contact( $user_id, $post_id ) ) {
				$user_id = $stored_user_id;
				$failures[] = 'wordpress_user';
			}
			update_post_meta( $post_id, Meta::WP_USER_ID, $user_id );

			$email_mode = sanitize_key( (string) ( $details['email_mode'] ?? ContactIdentity::EMAIL_CRM ) );
			if ( ! in_array( $email_mode, [ ContactIdentity::EMAIL_CRM, ContactIdentity::EMAIL_WP ], true ) ) {
				$email_mode = ContactIdentity::EMAIL_CRM;
			}
			if ( ContactIdentity::EMAIL_WP === $email_mode && 0 === $user_id ) {
				$email_mode = ContactIdentity::EMAIL_CRM;
			}
			update_post_meta( $post_id, Meta::EMAIL_MODE, $email_mode );
		}

		if ( Entity::ORGANIZATION === $owner_type ) {
			update_post_meta( $post_id, Meta::LEGAL_NAME, sanitize_text_field( (string) ( $details['legal_name'] ?? '' ) ) );
		}

		return $failures;
	}
}

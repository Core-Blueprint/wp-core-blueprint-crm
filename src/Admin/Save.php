<?php
declare(strict_types=1);

namespace CB\CRM\Admin;

use CB\CRM\Application\Actions\UpdateContact;
use CB\CRM\Application\Actions\UpdateOrganization;
use CB\CRM\Capabilities;
use CB\CRM\Content\Entity;
use CB\CRM\Content\Meta;
use CB\CRM\Content\RecordStatus;
use CB\CRM\Governance;
use CB\CRM\Repository\Activity;
use CB\CRM\Repository\Notes;

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

		if ( Entity::CONTACT === $owner_type || Entity::ORGANIZATION === $owner_type ) {
			$result = Entity::CONTACT === $owner_type
				? UpdateContact::execute( $post_id, self::record_input( $owner_type ) )
				: UpdateOrganization::execute( $post_id, self::record_input( $owner_type ) );
			if ( is_wp_error( $result ) ) {
				$data = $result->get_error_data();
				$failures = is_array( $data ) && isset( $data['failed_areas'] ) && is_array( $data['failed_areas'] )
					? array_filter( array_map( 'sanitize_key', $data['failed_areas'] ) )
					: [ 'details' ];
				self::$failed_areas[ $post_id ] = array_values( array_unique( $failures ) );
			}
			self::save_note( $owner_type, $post_id );
			return;
		}

		// Services keep their existing native save path. Phase 5 exposes only
		// Contact and Organization canonical update actions.
		$details = isset( $_POST['cb_crm_details'] ) && is_array( $_POST['cb_crm_details'] )
			? wp_unslash( $_POST['cb_crm_details'] )
			: [];
		$status_value = isset( $details['status'] ) && is_scalar( $details['status'] )
			? (string) $details['status']
			: RecordStatus::ACTIVE;
		$status = RecordStatus::normalize( $status_value );
		update_post_meta( $post_id, Meta::STATUS, $status );
		Governance::record_data_updated( $owner_type, $post_id, 'details' );
		Activity::record(
			$owner_type,
			$post_id,
			'record_updated',
			__( 'CRM record details updated', 'core-blueprint-crm' ),
			'crm',
			get_current_user_id(),
			'post',
			(string) $post_id,
			[ 'areas' => 'details' ]
		);
		self::save_note( $owner_type, $post_id );
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
			'tags'            => __( 'CRM Tags', 'core-blueprint-crm' ),
			'note'            => __( 'note', 'core-blueprint-crm' ),
			'details'         => 'details',
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

	/** @return array<string,mixed> */
	private static function record_input( string $owner_type ): array {
		$details = isset( $_POST['cb_crm_details'] ) && is_array( $_POST['cb_crm_details'] ) ? wp_unslash( $_POST['cb_crm_details'] ) : [];
		$input = [
			'status' => $details['status'] ?? RecordStatus::ACTIVE,
		];
		if ( Entity::CONTACT === $owner_type ) {
			$input['first_name']  = $details['first_name'] ?? '';
			$input['last_name']   = $details['last_name'] ?? '';
			$input['job_title']   = $details['job_title'] ?? '';
			$input['wp_user_id']  = $details['wp_user_id'] ?? 0;
			$input['email_mode']  = $details['email_mode'] ?? 'crm';
		}
		if ( Entity::ORGANIZATION === $owner_type ) {
			$input['legal_name'] = $details['legal_name'] ?? '';
		}
		if ( isset( $_POST['cb_crm_contact_methods_present'] ) ) {
			$input['contact_methods'] = isset( $_POST['cb_crm_contact_methods'] ) && is_array( $_POST['cb_crm_contact_methods'] ) ? wp_unslash( $_POST['cb_crm_contact_methods'] ) : [];
		}
		if ( isset( $_POST['cb_crm_addresses_present'] ) ) {
			$input['addresses'] = isset( $_POST['cb_crm_addresses'] ) && is_array( $_POST['cb_crm_addresses'] ) ? wp_unslash( $_POST['cb_crm_addresses'] ) : [];
		}
		if ( Entity::CONTACT === $owner_type && isset( $_POST['cb_crm_names_present'] ) ) {
			$input['names'] = isset( $_POST['cb_crm_names'] ) && is_array( $_POST['cb_crm_names'] ) ? wp_unslash( $_POST['cb_crm_names'] ) : [];
		}
		if ( Entity::CONTACT === $owner_type && isset( $_POST['cb_crm_organizations_present'] ) ) {
			$input['organizations'] = isset( $_POST['cb_crm_organizations'] ) && is_array( $_POST['cb_crm_organizations'] ) ? wp_unslash( $_POST['cb_crm_organizations'] ) : [];
		}
		if ( isset( $_POST['cb_crm_services_present'] ) ) {
			$input['services'] = isset( $_POST['cb_crm_services'] ) && is_array( $_POST['cb_crm_services'] ) ? wp_unslash( $_POST['cb_crm_services'] ) : [];
		}
		return $input;
	}

	private static function save_note( string $owner_type, int $post_id ): void {
		$note = isset( $_POST['cb_crm_new_note'] ) ? sanitize_textarea_field( (string) wp_unslash( $_POST['cb_crm_new_note'] ) ) : '';
		if ( '' === $note ) {
			return;
		}
		$note_id = Notes::add( $owner_type, $post_id, get_current_user_id(), $note );
		if ( $note_id > 0 ) {
			Governance::record_note_created( $owner_type, $post_id, $note_id );
			Activity::record( $owner_type, $post_id, 'note_added', __( 'CRM note added', 'core-blueprint-crm' ), 'crm', get_current_user_id(), 'note', (string) $note_id );
			return;
		}
		$existing = self::$failed_areas[ $post_id ] ?? [];
		$existing[] = 'note';
		self::$failed_areas[ $post_id ] = array_values( array_unique( $existing ) );
	}
}

<?php
declare(strict_types=1);

namespace CB\CRM\Integration\Builders\Bricks;

use CB\CRM\Application\Actions\UpdateContact;
use CB\CRM\Application\Actions\UpdateOrganization;

defined( 'ABSPATH' ) || exit;

final class FormActions {
	public const UPDATE_CONTACT      = 'cb-crm-update-contact';
	public const UPDATE_ORGANIZATION = 'cb-crm-update-organization';

	private const CONTACT_GROUP      = 'cbCrmUpdateContact';
	private const ORGANIZATION_GROUP = 'cbCrmUpdateOrganization';

	public static function init(): void {
		add_filter( 'bricks/elements/form/controls', [ self::class, 'controls' ] );
		add_filter( 'bricks/elements/form/control_groups', [ self::class, 'control_groups' ] );
		add_action( 'bricks/form/action/' . self::UPDATE_CONTACT, [ self::class, 'update_contact' ] );
		add_action( 'bricks/form/action/' . self::UPDATE_ORGANIZATION, [ self::class, 'update_organization' ] );
	}

	/** @param array<string,mixed> $controls
	 *  @return array<string,mixed>
	 */
	public static function controls( array $controls ): array {
		if ( ! isset( $controls['actions']['options'] ) || ! is_array( $controls['actions']['options'] ) ) {
			return $controls;
		}

		$controls['actions']['options'][ self::UPDATE_CONTACT ]      = __( 'CRM: Edit Contact', 'core-blueprint-crm' );
		$controls['actions']['options'][ self::UPDATE_ORGANIZATION ] = __( 'CRM: Edit Organization', 'core-blueprint-crm' );

		$contact = __( 'Contact', 'core-blueprint-crm' );
		$controls['cbCrmUpdateContactId']        = self::field_control( self::CONTACT_GROUP, $contact . ' · ID' );
		$controls['cbCrmUpdateContactTitle']     = self::field_control( self::CONTACT_GROUP, __( 'Display name', 'core-blueprint-crm' ) );
		$controls['cbCrmUpdateContactFirstName'] = self::field_control( self::CONTACT_GROUP, __( 'First name', 'core-blueprint-crm' ) );
		$controls['cbCrmUpdateContactLastName']  = self::field_control( self::CONTACT_GROUP, __( 'Last name', 'core-blueprint-crm' ) );
		$controls['cbCrmUpdateContactJobTitle']  = self::field_control( self::CONTACT_GROUP, __( 'Job title', 'core-blueprint-crm' ) );
		$controls['cbCrmUpdateContactStatus']    = self::field_control( self::CONTACT_GROUP, __( 'Status', 'core-blueprint-crm' ) );

		$organization = __( 'Organization', 'core-blueprint-crm' );
		$controls['cbCrmUpdateOrganizationId']        = self::field_control( self::ORGANIZATION_GROUP, $organization . ' · ID' );
		$controls['cbCrmUpdateOrganizationTitle']     = self::field_control( self::ORGANIZATION_GROUP, __( 'Organization name', 'core-blueprint-crm' ) );
		$controls['cbCrmUpdateOrganizationLegalName'] = self::field_control( self::ORGANIZATION_GROUP, __( 'Legal name', 'core-blueprint-crm' ) );
		$controls['cbCrmUpdateOrganizationStatus']    = self::field_control( self::ORGANIZATION_GROUP, __( 'Status', 'core-blueprint-crm' ) );
		return $controls;
	}

	/** @param array<string,mixed> $groups
	 *  @return array<string,mixed>
	 */
	public static function control_groups( array $groups ): array {
		$groups[ self::CONTACT_GROUP ] = [
			'title'    => __( 'CRM: Edit Contact', 'core-blueprint-crm' ),
			'required' => [ 'actions', '=', self::UPDATE_CONTACT ],
		];
		$groups[ self::ORGANIZATION_GROUP ] = [
			'title'    => __( 'CRM: Edit Organization', 'core-blueprint-crm' ),
			'required' => [ 'actions', '=', self::UPDATE_ORGANIZATION ],
		];
		return $groups;
	}

	public static function update_contact( object $form ): void {
		$contact_id = self::record_id( FormFields::value( $form, 'cbCrmUpdateContactId' ) );
		$input = self::mapped_input( $form, [
			'cbCrmUpdateContactTitle'     => 'title',
			'cbCrmUpdateContactFirstName' => 'first_name',
			'cbCrmUpdateContactLastName'  => 'last_name',
			'cbCrmUpdateContactJobTitle'  => 'job_title',
			'cbCrmUpdateContactStatus'    => 'status',
		] );
		self::apply_result( $form, self::UPDATE_CONTACT, UpdateContact::execute( $contact_id, $input ), __( 'No contacts found.', 'core-blueprint-crm' ) );
	}

	public static function update_organization( object $form ): void {
		$organization_id = self::record_id( FormFields::value( $form, 'cbCrmUpdateOrganizationId' ) );
		$input = self::mapped_input( $form, [
			'cbCrmUpdateOrganizationTitle'     => 'title',
			'cbCrmUpdateOrganizationLegalName' => 'legal_name',
			'cbCrmUpdateOrganizationStatus'    => 'status',
		] );
		self::apply_result( $form, self::UPDATE_ORGANIZATION, UpdateOrganization::execute( $organization_id, $input ), __( 'No organizations found.', 'core-blueprint-crm' ) );
	}

	/** @param array<string,string> $mapping
	 *  @return array<string,mixed>
	 */
	private static function mapped_input( object $form, array $mapping ): array {
		$input = [];
		foreach ( $mapping as $setting_key => $input_key ) {
			if ( FormFields::has_mapping( $form, $setting_key ) ) {
				$input[ $input_key ] = FormFields::value( $form, $setting_key );
			}
		}
		return $input;
	}

	/** @return array<string,mixed> */
	private static function field_control( string $group, string $label ): array {
		return [ 'group' => $group, 'label' => $label, 'type' => 'select', 'options' => [], 'map_fields' => true ];
	}

	private static function record_id( mixed $value ): int {
		return is_scalar( $value ) && is_numeric( $value ) ? absint( $value ) : 0;
	}

	private static function apply_result( object $form, string $action, array|\WP_Error $result, string $not_found_message ): void {
		if ( ! is_wp_error( $result ) || ! method_exists( $form, 'set_result' ) ) {
			return;
		}

		$code = $result->get_error_code();
		if ( 'crm_forbidden' === $code ) {
			$message = __( 'You do not have permission to manage CRM data.', 'core-blueprint-crm' );
		} elseif ( in_array( $code, [ 'crm_contact_not_found', 'crm_organization_not_found', 'crm_record_not_found' ], true ) ) {
			$message = $not_found_message;
		} else {
			$data = $result->get_error_data();
			$failed = is_array( $data ) && isset( $data['failed_areas'] ) && is_array( $data['failed_areas'] )
				? array_filter( array_map( 'sanitize_key', $data['failed_areas'] ) )
				: [];
			$message = sprintf(
				__( 'The post was saved, but CRM could not save: %s. Review the record and try again.', 'core-blueprint-crm' ),
				implode( ', ', $failed ) ?: __( 'CRM', 'core-blueprint-crm' )
			);
		}

		$form->set_result( [ 'action' => $action, 'type' => 'error', 'message' => $message ] );
	}
}

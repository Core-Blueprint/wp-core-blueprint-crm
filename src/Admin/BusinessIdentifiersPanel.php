<?php
declare(strict_types=1);

namespace CB\CRM\Admin;

use CB\CRM\Content\Entity;
use CB\CRM\PanelRegistry;
use CB\CRM\Repository\BusinessIdentifiers;

defined( 'ABSPATH' ) || exit;

/** Organization-only editor for reusable legal/business identifiers. */
final class BusinessIdentifiersPanel {
	public static function init(): void {
		add_action( 'cb_crm_register_panels', [ __CLASS__, 'register' ] );
	}

	public static function register(): void {
		PanelRegistry::register( [
			'id'         => 'business-identifiers',
			'label'      => __( 'Business Identifiers', 'core-blueprint-crm' ),
			'post_types' => [ Entity::ORGANIZATION ],
			'render'     => [ __CLASS__, 'render' ],
		] );
	}

	public static function render( string $owner_type, int $post_id ): void {
		if ( Entity::ORGANIZATION !== $owner_type ) {
			return;
		}

		$identifiers = BusinessIdentifiers::for_organization( $post_id );
		?>
		<input type="hidden" name="cb_crm_business_identifiers_present" value="1">
		<div class="cb-crm-repeater" data-cb-crm-repeater data-next-index="<?php echo esc_attr( (string) count( $identifiers ) ); ?>">
			<table class="widefat striped cb-crm-repeatable-table">
				<thead><tr>
					<th><?php esc_html_e( 'Key', 'core-blueprint-crm' ); ?></th>
					<th><?php esc_html_e( 'Value', 'core-blueprint-crm' ); ?></th>
					<th><?php esc_html_e( 'Actions', 'core-blueprint-crm' ); ?></th>
				</tr></thead>
				<tbody data-cb-crm-rows>
					<?php $index = 0; foreach ( $identifiers as $key => $value ) { self::row( $index++, $key, $value ); } ?>
				</tbody>
			</table>
			<template><?php self::row( '__INDEX__', '', '' ); ?></template>
			<div class="cb-crm-repeater-toolbar">
				<button type="button" class="button" data-cb-crm-add-row><?php esc_html_e( 'Add identifier', 'core-blueprint-crm' ); ?></button>
			</div>
		</div>
		<p class="description"><?php esc_html_e( 'Use reusable legal identifiers such as vat, registration_number or eori.', 'core-blueprint-crm' ); ?></p>
		<?php
	}

	private static function row( int|string $index, string $key, string $value ): void {
		$prefix = 'cb_crm_business_identifiers[' . (string) $index . ']';
		?>
		<tr data-cb-crm-row>
			<td><input type="text" name="<?php echo esc_attr( $prefix . '[key]' ); ?>" value="<?php echo esc_attr( $key ); ?>" placeholder="vat"></td>
			<td><input type="text" class="regular-text" name="<?php echo esc_attr( $prefix . '[value]' ); ?>" value="<?php echo esc_attr( $value ); ?>"></td>
			<td><button type="button" class="button-link-delete" data-cb-crm-remove-row><?php esc_html_e( 'Remove', 'core-blueprint-crm' ); ?></button></td>
		</tr>
		<?php
	}
}

<?php
declare(strict_types=1);

namespace CB\CRM\Admin\Panels;

use CB\CRM\Content\Entity;
use CB\CRM\PanelRegistry;
use CB\CRM\Repository\Addresses;

defined( 'ABSPATH' ) || exit;

final class AddressesPanel {
	public static function register(): void {
		PanelRegistry::register( [
			'id'         => 'addresses',
			'label'      => __( 'Addresses', 'core-blueprint-crm' ),
			'post_types' => [ Entity::CONTACT, Entity::ORGANIZATION ],
			'render'     => [ \CB\CRM\Admin\Panels::class, 'addresses' ],
		] );
	}

	public static function render( string $owner_type, int $post_id ): void {
		$rows = Addresses::for_owner( $owner_type, $post_id );
		?>
		<input type="hidden" name="cb_crm_addresses_present" value="1">
		<div class="cb-crm-repeater" data-cb-crm-repeater data-next-index="<?php echo esc_attr( (string) count( $rows ) ); ?>">
			<div class="cb-crm-address-list" data-cb-crm-rows>
				<?php foreach ( $rows as $i => $row ) { self::row( $i, $row ); } ?>
			</div>
			<template><?php self::row( '__INDEX__', [] ); ?></template>
			<div class="cb-crm-repeater-toolbar"><button type="button" class="button" data-cb-crm-add-row><?php esc_html_e( 'Add address', 'core-blueprint-crm' ); ?></button></div>
		</div>
		<?php
	}

	/** @param array<string,mixed> $row */
	private static function row( int|string $index, array $row ): void {
		$prefix = 'cb_crm_addresses[' . (string) $index . ']';
		?>
		<div class="cb-crm-address-card" data-cb-crm-row>
			<div class="cb-crm-address-grid">
				<div class="cb-crm-address-field"><label><?php esc_html_e( 'Label', 'core-blueprint-crm' ); ?><input type="text" name="<?php echo esc_attr( $prefix . '[label]' ); ?>" value="<?php echo esc_attr( (string) ( $row['label'] ?? '' ) ); ?>" placeholder="<?php echo esc_attr__( 'e.g. Work or Home', 'core-blueprint-crm' ); ?>"></label></div>
				<div class="cb-crm-address-field is-wide"><label><?php esc_html_e( 'Address line 1', 'core-blueprint-crm' ); ?><input type="text" name="<?php echo esc_attr( $prefix . '[address_line_1]' ); ?>" value="<?php echo esc_attr( (string) ( $row['address_line_1'] ?? '' ) ); ?>"></label></div>
				<div class="cb-crm-address-field is-wide"><label><?php esc_html_e( 'Address line 2', 'core-blueprint-crm' ); ?><input type="text" name="<?php echo esc_attr( $prefix . '[address_line_2]' ); ?>" value="<?php echo esc_attr( (string) ( $row['address_line_2'] ?? '' ) ); ?>"></label></div>
				<div class="cb-crm-address-field is-small"><label><?php esc_html_e( 'Postal code', 'core-blueprint-crm' ); ?><input type="text" name="<?php echo esc_attr( $prefix . '[postal_code]' ); ?>" value="<?php echo esc_attr( (string) ( $row['postal_code'] ?? '' ) ); ?>"></label></div>
				<div class="cb-crm-address-field"><label><?php esc_html_e( 'City', 'core-blueprint-crm' ); ?><input type="text" name="<?php echo esc_attr( $prefix . '[city]' ); ?>" value="<?php echo esc_attr( (string) ( $row['city'] ?? '' ) ); ?>"></label></div>
				<div class="cb-crm-address-field"><label><?php esc_html_e( 'Region', 'core-blueprint-crm' ); ?><input type="text" name="<?php echo esc_attr( $prefix . '[region]' ); ?>" value="<?php echo esc_attr( (string) ( $row['region'] ?? '' ) ); ?>"></label></div>
				<div class="cb-crm-address-field is-small"><label><?php esc_html_e( 'Country code', 'core-blueprint-crm' ); ?><input type="text" maxlength="2" name="<?php echo esc_attr( $prefix . '[country]' ); ?>" value="<?php echo esc_attr( (string) ( $row['country'] ?? '' ) ); ?>" placeholder="NL"></label></div>
			</div>
			<div class="cb-crm-address-actions">
				<label><input type="checkbox" name="<?php echo esc_attr( $prefix . '[is_primary]' ); ?>" value="1" <?php checked( ! empty( $row['is_primary'] ) ); ?>> <?php esc_html_e( 'Primary address', 'core-blueprint-crm' ); ?></label>
				<button type="button" class="button-link-delete" data-cb-crm-remove-row><?php esc_html_e( 'Remove address', 'core-blueprint-crm' ); ?></button>
			</div>
		</div>
		<?php
	}
}

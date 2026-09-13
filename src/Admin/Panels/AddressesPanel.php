<?php
declare(strict_types=1);

namespace CB\CRM\Admin\Panels;

use CB\CRM\Content\Entity;
use CB\CRM\Integration\ContactDataSources;
use CB\CRM\Integration\WooCustomerWorkspace;
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
		if ( Entity::CONTACT === $owner_type ) {
			self::woo_address_card( $post_id, 'billing' );
			self::woo_address_card( $post_id, 'shipping' );
		}
	}

	/** @param array<string,mixed> $row */
	private static function row( int|string $index, array $row ): void {
		$prefix = 'cb_crm_addresses[' . (string) $index . ']';
		?>
		<div class="cb-crm-address-card" data-cb-crm-row>
			<div class="cb-crm-source-card-header"><span></span><span class="cb-crm-source-badges"><?php SourceBadge::render( 'crm', 'addresses' ); ?></span></div>
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

	private static function woo_address_card( int $post_id, string $kind ): void {
		$customer = ContactDataSources::woo_customer( $post_id );
		if ( ! is_object( $customer ) || ! in_array( $kind, [ 'billing', 'shipping' ], true ) ) {
			return;
		}
		$fields = 'billing' === $kind
			? [
				'billing_address_1' => [ __( 'Address line 1', 'woocommerce' ), 'text', 'wide' ],
				'billing_address_2' => [ __( 'Address line 2', 'woocommerce' ), 'text', 'wide' ],
				'billing_postcode' => [ __( 'Postcode / ZIP', 'woocommerce' ), 'text', 'small' ],
				'billing_city' => [ __( 'Town / City', 'woocommerce' ), 'text', '' ],
				'billing_state' => [ __( 'State / County', 'woocommerce' ), 'text', '' ],
				'billing_country' => [ __( 'Country / Region', 'woocommerce' ), 'country', 'small' ],
			]
			: [
				'shipping_first_name' => [ __( 'First name', 'woocommerce' ), 'text', '' ],
				'shipping_last_name' => [ __( 'Last name', 'woocommerce' ), 'text', '' ],
				'shipping_company' => [ __( 'Company', 'woocommerce' ), 'text', '' ],
				'shipping_address_1' => [ __( 'Address line 1', 'woocommerce' ), 'text', 'wide' ],
				'shipping_address_2' => [ __( 'Address line 2', 'woocommerce' ), 'text', 'wide' ],
				'shipping_postcode' => [ __( 'Postcode / ZIP', 'woocommerce' ), 'text', 'small' ],
				'shipping_city' => [ __( 'Town / City', 'woocommerce' ), 'text', '' ],
				'shipping_state' => [ __( 'State / County', 'woocommerce' ), 'text', '' ],
				'shipping_country' => [ __( 'Country / Region', 'woocommerce' ), 'country', 'small' ],
			];
		if ( 'shipping' === $kind && is_callable( [ $customer, 'get_shipping_phone' ] ) && is_callable( [ $customer, 'set_shipping_phone' ] ) ) {
			$fields = array_merge(
				array_slice( $fields, 0, 3, true ),
				[ 'shipping_phone' => [ __( 'Phone', 'woocommerce' ), 'tel', '' ] ],
				array_slice( $fields, 3, null, true )
			);
		}

		$values = [];
		$has_value = false;
		foreach ( $fields as $key => $_definition ) {
			$values[ $key ] = ContactDataSources::woo_value( $post_id, $key );
			$has_value = $has_value || '' !== $values[ $key ];
		}
		$can_edit = WooCustomerWorkspace::can_edit_customer();
		if ( ! $has_value && ! $can_edit ) {
			return;
		}
		$countries = WooCustomerWorkspace::countries();
		$title = 'billing' === $kind ? __( 'Billing address', 'woocommerce' ) : __( 'Shipping address', 'woocommerce' );
		?>
		<div class="cb-crm-address-card cb-crm-source-address-card">
			<div class="cb-crm-source-card-header"><strong><?php echo esc_html( $title ); ?></strong><span class="cb-crm-source-badges"><?php SourceBadge::render( 'woocommerce', $kind . '_address' ); ?></span></div>
			<div class="cb-crm-address-grid">
				<?php foreach ( $fields as $key => $definition ) :
					$value = $values[ $key ];
					$css = '' !== $definition[2] ? ' is-' . $definition[2] : '';
					$id = 'cb-crm-woo-' . str_replace( '_', '-', $key );
					?>
					<div class="cb-crm-address-field<?php echo esc_attr( $css ); ?>"><label for="<?php echo esc_attr( $id ); ?>"><?php echo esc_html( $definition[0] ); ?></label>
						<?php if ( 'country' === $definition[1] && [] !== $countries ) : ?>
							<select id="<?php echo esc_attr( $id ); ?>" name="cb_crm_woo_customer[<?php echo esc_attr( $key ); ?>]" <?php disabled( ! $can_edit ); ?>><option value=""></option><?php foreach ( $countries as $code => $country_label ) : ?><option value="<?php echo esc_attr( $code ); ?>" <?php selected( $value, $code ); ?>><?php echo esc_html( $country_label ); ?></option><?php endforeach; ?></select>
						<?php else : ?>
							<input type="<?php echo esc_attr( $definition[1] ); ?>" id="<?php echo esc_attr( $id ); ?>" name="cb_crm_woo_customer[<?php echo esc_attr( $key ); ?>]" value="<?php echo esc_attr( $value ); ?>" <?php disabled( ! $can_edit ); ?>>
						<?php endif; ?>
					</div>
				<?php endforeach; ?>
			</div>
		</div>
		<?php
	}
}

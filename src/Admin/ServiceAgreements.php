<?php
declare(strict_types=1);

namespace CB\CRM\Admin;

use CB\CRM\Content\Entity;
use CB\CRM\PanelRegistry;
use CB\CRM\Repository\ServiceAgreements as Agreements;
use CB\Work\PublicApi\Services as WorkServices;
use CB\Work\PublicApi\TaxRates as WorkTaxRates;

defined( 'ABSPATH' ) || exit;

final class ServiceAgreements {
	public static function init(): void {
		add_action( 'cb_crm_register_panels', [ self::class, 'register' ], 20 );
	}

	public static function register(): void {
		PanelRegistry::register( [
			'id'         => 'service-agreements',
			'label'      => __( 'Service Agreements', 'core-blueprint-crm' ),
			'post_types' => [ Entity::CONTACT, Entity::ORGANIZATION ],
			'render'     => [ self::class, 'render' ],
			'context'    => 'normal',
			'priority'   => 'default',
		] );
	}

	public static function render( string $customer_type, int $customer_id ): void {
		if ( ! class_exists( WorkServices::class ) || ! class_exists( WorkTaxRates::class ) ) {
			echo '<p class="description">' . esc_html__( 'Core Blueprint Work is not available. Service agreements remain inactive because CRM never provides a fallback service or VAT catalog.', 'core-blueprint-crm' ) . '</p>';
			return;
		}

		$rows     = Agreements::for_owner( $customer_type, $customer_id );
		$services = WorkServices::all( 250, [ 'publish', 'draft', 'private' ] );
		$rates    = WorkTaxRates::all( true );

		echo '<input type="hidden" name="cb_crm_service_agreements_present" value="1">';
		echo '<div class="cb-crm-repeater" data-cb-crm-repeater data-next-index="' . esc_attr( (string) count( $rows ) ) . '"><div data-cb-crm-rows>';
		foreach ( $rows as $i => $row ) {
			self::row( $i, $row, $services, $rates );
		}
		echo '</div><template>';
		self::row( '__INDEX__', [], $services, $rates );
		echo '</template><p><button type="button" class="button" data-cb-crm-add-row>' . esc_html__( 'Add service agreement', 'core-blueprint-crm' ) . '</button></p></div>';
	}

	/**
	 * @param array<string,mixed> $row
	 * @param array<int,array<string,mixed>> $services
	 * @param array<int,array<string,mixed>> $rates
	 */
	private static function row( int|string $index, array $row, array $services, array $rates ): void {
		$prefix        = 'cb_crm_service_agreements[' . $index . ']';
		$agreement_id  = absint( $row['id'] ?? 0 );
		$service_id    = absint( $row['work_service_id'] ?? 0 );
		$selected_rate = absint( $row['custom_tax_rate_id'] ?? 0 );

		echo '<div class="postbox" data-cb-crm-row><div class="inside">';
		echo '<input type="hidden" name="' . esc_attr( $prefix . '[id]' ) . '" value="' . esc_attr( (string) $agreement_id ) . '">';
		echo '<table class="form-table" role="presentation"><tbody>';
		echo '<tr><th>' . esc_html__( 'Work service', 'core-blueprint-crm' ) . '</th><td><select name="' . esc_attr( $prefix . '[work_service_id]' ) . '"><option value="0">—</option>';

		$found = false;
		foreach ( $services as $service ) {
			$id = absint( $service['id'] ?? 0 );
			if ( $id <= 0 ) {
				continue;
			}
			if ( $id === $service_id ) {
				$found = true;
			}
			echo '<option value="' . esc_attr( (string) $id ) . '" ' . selected( $service_id, $id, false ) . '>' . esc_html( (string) ( $service['title'] ?? '#' . $id ) ) . '</option>';
		}
		if ( $service_id > 0 && ! $found ) {
			echo '<option value="' . esc_attr( (string) $service_id ) . '" selected>' . esc_html( sprintf( __( 'Unavailable Work service #%d', 'core-blueprint-crm' ), $service_id ) ) . '</option>';
		}
		echo '</select></td></tr>';

		echo '<tr><th>' . esc_html__( 'Status', 'core-blueprint-crm' ) . '</th><td><select name="' . esc_attr( $prefix . '[status]' ) . '">';
		foreach ( Agreements::status_labels() as $value => $label ) {
			echo '<option value="' . esc_attr( $value ) . '" ' . selected( (string) ( $row['status'] ?? 'active' ), $value, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select></td></tr>';

		echo '<tr><th>' . esc_html__( 'Validity', 'core-blueprint-crm' ) . '</th><td><input type="date" name="' . esc_attr( $prefix . '[valid_from]' ) . '" value="' . esc_attr( (string) ( $row['valid_from'] ?? '' ) ) . '"> – <input type="date" name="' . esc_attr( $prefix . '[valid_until]' ) . '" value="' . esc_attr( (string) ( $row['valid_until'] ?? '' ) ) . '"></td></tr>';

		$mode = (string) ( $row['pricing_mode'] ?? Agreements::PRICING_INHERIT );
		echo '<tr><th>' . esc_html__( 'Pricing', 'core-blueprint-crm' ) . '</th><td><select name="' . esc_attr( $prefix . '[pricing_mode]' ) . '"><option value="inherit" ' . selected( $mode, 'inherit', false ) . '>' . esc_html__( 'Use Work service default', 'core-blueprint-crm' ) . '</option><option value="custom" ' . selected( $mode, 'custom', false ) . '>' . esc_html__( 'Customer-specific override', 'core-blueprint-crm' ) . '</option></select></td></tr>';

		$amount = isset( $row['custom_amount_minor'] ) && null !== $row['custom_amount_minor']
			? number_format( (int) $row['custom_amount_minor'] / 100, 2, '.', '' )
			: '';
		echo '<tr><th>' . esc_html__( 'Custom amount', 'core-blueprint-crm' ) . '</th><td><input type="text" inputmode="decimal" name="' . esc_attr( $prefix . '[custom_amount]' ) . '" value="' . esc_attr( $amount ) . '"> <input type="text" maxlength="3" size="4" name="' . esc_attr( $prefix . '[custom_currency]' ) . '" value="' . esc_attr( (string) ( $row['custom_currency'] ?? 'EUR' ) ) . '"></td></tr>';

		$tax_mode = (string) ( $row['custom_tax_mode'] ?? '' );
		echo '<tr><th>' . esc_html__( 'VAT override', 'core-blueprint-crm' ) . '</th><td><select name="' . esc_attr( $prefix . '[custom_tax_mode]' ) . '"><option value="">' . esc_html__( 'Use Work service default', 'core-blueprint-crm' ) . '</option><option value="exclusive" ' . selected( $tax_mode, 'exclusive', false ) . '>' . esc_html__( 'Excluding VAT', 'core-blueprint-crm' ) . '</option><option value="inclusive" ' . selected( $tax_mode, 'inclusive', false ) . '>' . esc_html__( 'Including VAT', 'core-blueprint-crm' ) . '</option><option value="exempt" ' . selected( $tax_mode, 'exempt', false ) . '>' . esc_html__( 'VAT exempt', 'core-blueprint-crm' ) . '</option></select> <select name="' . esc_attr( $prefix . '[custom_tax_rate_id]' ) . '"><option value="0">—</option>';

		$available_ids = array_map( static fn( array $rate ): int => absint( $rate['id'] ?? 0 ), WorkTaxRates::available() );
		foreach ( $rates as $rate ) {
			$id = absint( $rate['id'] ?? 0 );
			if ( $id <= 0 ) {
				continue;
			}
			$disabled = $id !== $selected_rate && ! in_array( $id, $available_ids, true );
			$label = trim( (string) ( $rate['label'] ?? '' ) . ' ' . ( isset( $rate['rate_bp'] ) ? ( (float) $rate['rate_bp'] / 100 ) . '%' : '' ) );
			echo '<option value="' . esc_attr( (string) $id ) . '" ' . selected( $selected_rate, $id, false ) . ' ' . disabled( $disabled, true, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select></td></tr>';

		echo '<tr><th>' . esc_html__( 'Notes', 'core-blueprint-crm' ) . '</th><td><textarea class="widefat" rows="2" name="' . esc_attr( $prefix . '[notes]' ) . '">' . esc_textarea( (string) ( $row['notes'] ?? '' ) ) . '</textarea></td></tr>';
		echo '</tbody></table><p><button type="button" class="button-link-delete" data-cb-crm-remove-row>' . esc_html__( 'Remove agreement', 'core-blueprint-crm' ) . '</button></p></div></div>';
	}
}

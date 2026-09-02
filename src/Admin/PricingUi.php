<?php
declare(strict_types=1);

namespace CB\CRM\Admin;

use CB\CRM\Capabilities;
use CB\CRM\Content\Entity;
use CB\CRM\Content\PostTypes;
use CB\CRM\Content\ServicePricing;
use CB\CRM\Governance;
use CB\CRM\PanelRegistry;
use CB\CRM\Repository\Activity;
use CB\CRM\Repository\Services;
use CB\CRM\Repository\TaxRates;

defined( 'ABSPATH' ) || exit;

final class PricingUi {
	public static function init(): void {
		add_action( 'cb_crm_register_panels', [ __CLASS__, 'register_panels' ], 20 );
		add_action( 'add_meta_boxes', [ __CLASS__, 'replace_meta_boxes' ], 30 );
		add_action( 'save_post', [ __CLASS__, 'save_service_pricing' ], 30, 3 );
		add_action( 'admin_enqueue_scripts', [ __CLASS__, 'enqueue' ] );
	}

	public static function register_panels(): void {
		PanelRegistry::register( [
			'id'         => 'service-pricing',
			'label'      => __( 'Standard Pricing', 'core-blueprint-crm' ),
			'post_types' => [ Entity::SERVICE ],
			'render'     => [ __CLASS__, 'render_service_pricing' ],
			'context'    => 'normal',
			'priority'   => 'high',
		] );
	}

	public static function replace_meta_boxes(): void {
		remove_meta_box( 'cb-crm-panel-names', PostTypes::SERVICE, 'normal' );

		foreach ( [ PostTypes::CONTACT, PostTypes::ORGANIZATION ] as $post_type ) {
			remove_meta_box( 'cb-crm-panel-services', $post_type, 'normal' );
			add_meta_box(
				'cb-crm-panel-services',
				__( 'Services', 'core-blueprint-crm' ),
				[ __CLASS__, 'render_service_assignments' ],
				$post_type,
				'normal',
				'default'
			);
			add_filter(
				'postbox_classes_' . $post_type . '_cb-crm-panel-services',
				static function ( array $classes ): array {
					$classes[] = 'cb-core-form-scope';
					return array_values( array_unique( $classes ) );
				}
			);
		}
	}

	public static function enqueue(): void {
		$screen = get_current_screen();
		if ( ! $screen || ! in_array( (string) $screen->post_type, [ PostTypes::CONTACT, PostTypes::ORGANIZATION, PostTypes::SERVICE ], true ) ) {
			return;
		}
		wp_enqueue_style( 'cb-crm-pricing', CB_CRM_URL . 'assets/pricing.css', [ 'cb-crm-admin' ], CB_CRM_VERSION );
		wp_enqueue_script( 'cb-crm-pricing', CB_CRM_URL . 'assets/pricing.js', [ 'cb-crm-admin' ], CB_CRM_VERSION, true );
	}

	public static function render_service_pricing( string $owner_type, int $post_id ): void {
		unset( $owner_type );
		$pricing = ServicePricing::get( $post_id );
		$rates   = TaxRates::all( true );
		wp_nonce_field( 'cb_crm_save_pricing', 'cb_crm_pricing_nonce' );
		?>
		<div class="cb-crm-pricing-grid">
			<div class="cb-crm-field">
				<label for="cb-crm-service-price"><strong><?php esc_html_e( 'Standard price', 'core-blueprint-crm' ); ?></strong></label>
				<input id="cb-crm-service-price" class="regular-text" type="text" inputmode="decimal" name="cb_crm_service_pricing[amount]" value="<?php echo esc_attr( ServicePricing::amount_input( $pricing['amount_minor'] ) ); ?>" placeholder="49.95">
				<p class="description"><?php esc_html_e( 'Leave empty when this service has no standard price.', 'core-blueprint-crm' ); ?></p>
			</div>
			<div class="cb-crm-field is-small">
				<label for="cb-crm-service-currency"><strong><?php esc_html_e( 'Currency', 'core-blueprint-crm' ); ?></strong></label>
				<input id="cb-crm-service-currency" type="text" maxlength="3" name="cb_crm_service_pricing[currency]" value="<?php echo esc_attr( $pricing['currency'] ); ?>" placeholder="EUR">
			</div>
			<div class="cb-crm-field">
				<label for="cb-crm-service-tax-mode"><strong><?php esc_html_e( 'Price is', 'core-blueprint-crm' ); ?></strong></label>
				<select id="cb-crm-service-tax-mode" name="cb_crm_service_pricing[tax_mode]" data-cb-crm-tax-mode>
					<?php foreach ( ServicePricing::tax_mode_labels() as $value => $label ) : ?>
						<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $pricing['tax_mode'], $value ); ?>><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
				</select>
			</div>
			<div class="cb-crm-field" data-cb-crm-tax-rate-field <?php echo ServicePricing::TAX_EXEMPT === $pricing['tax_mode'] ? 'hidden' : ''; ?>>
				<label for="cb-crm-service-tax-rate"><strong><?php esc_html_e( 'Tax rate', 'core-blueprint-crm' ); ?></strong></label>
				<?php self::tax_rate_select( 'cb_crm_service_pricing[tax_rate_id]', 'cb-crm-service-tax-rate', $pricing['tax_rate_id'], $rates ); ?>
				<p class="description">
					<a href="<?php echo esc_url( TaxRatesPage::url() ); ?>"><?php esc_html_e( 'Manage tax rates', 'core-blueprint-crm' ); ?></a>
				</p>
			</div>
		</div>
		<?php if ( ! $rates ) : ?>
			<div class="notice notice-info inline"><p><?php esc_html_e( 'No tax rates are configured yet. Add a tax rate before using VAT-inclusive or VAT-exclusive pricing.', 'core-blueprint-crm' ); ?> <a href="<?php echo esc_url( TaxRatesPage::url() ); ?>"><?php esc_html_e( 'Add tax rate', 'core-blueprint-crm' ); ?></a></p></div>
		<?php endif; ?>
		<?php
	}

	public static function save_service_pricing( int $post_id, \WP_Post $post, bool $update ): void {
		unset( $update );
		if ( PostTypes::SERVICE !== $post->post_type || wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) || 'trash' === $post->post_status ) {
			return;
		}
		if ( ! isset( $_POST['cb_crm_pricing_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( (string) wp_unslash( $_POST['cb_crm_pricing_nonce'] ) ), 'cb_crm_save_pricing' ) ) {
			return;
		}
		if ( ! current_user_can( Capabilities::MANAGE ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		$input = isset( $_POST['cb_crm_service_pricing'] ) && is_array( $_POST['cb_crm_service_pricing'] ) ? wp_unslash( $_POST['cb_crm_service_pricing'] ) : [];
		if ( ServicePricing::save( $post_id, $input ) ) {
			Governance::record_data_updated( Entity::SERVICE, $post_id, 'pricing' );
			Activity::record( Entity::SERVICE, $post_id, 'pricing_updated', __( 'Service pricing updated', 'core-blueprint-crm' ), 'crm', get_current_user_id(), 'post', (string) $post_id );
		}
	}

	public static function render_service_assignments( \WP_Post $post ): void {
		$owner_type = Entity::owner_type_for_post( $post->ID );
		if ( ! in_array( $owner_type, [ Entity::CONTACT, Entity::ORGANIZATION ], true ) ) {
			return;
		}
		$rows = Services::for_owner( $owner_type, (int) $post->ID );
		$options = get_posts( [
			'post_type'   => PostTypes::SERVICE,
			'post_status' => [ 'publish', 'draft', 'private' ],
			'numberposts' => -1,
			'orderby'     => 'title',
			'order'       => 'ASC',
		] );
		$rates = TaxRates::all( true );
		echo '<input type="hidden" name="cb_crm_services_present" value="1">';

		if ( ! $options && ! $rows ) {
			?>
			<div class="cb-crm-empty-state">
				<strong><?php esc_html_e( 'No services are available yet.', 'core-blueprint-crm' ); ?></strong>
				<p><?php esc_html_e( 'Create and save a Service before assigning it to this record.', 'core-blueprint-crm' ); ?></p>
				<a class="button button-primary" href="<?php echo esc_url( admin_url( 'post-new.php?post_type=' . PostTypes::SERVICE ) ); ?>"><?php esc_html_e( 'Create service', 'core-blueprint-crm' ); ?></a>
			</div>
			<?php
			return;
		}
		?>
		<div class="cb-crm-repeater cb-crm-service-repeater" data-cb-crm-repeater data-next-index="<?php echo esc_attr( (string) count( $rows ) ); ?>">
			<div class="cb-crm-service-list" data-cb-crm-rows>
				<?php foreach ( $rows as $index => $row ) { self::service_assignment_row( $index, $row, $options, $rates ); } ?>
			</div>
			<template><?php self::service_assignment_row( '__INDEX__', [], $options, $rates ); ?></template>
			<div class="cb-crm-repeater-toolbar"><button type="button" class="button" data-cb-crm-add-row><?php esc_html_e( 'Add service', 'core-blueprint-crm' ); ?></button></div>
		</div>
		<?php
	}

	/**
	 * @param int|string $index
	 * @param array<string,mixed> $row
	 * @param \WP_Post[] $options
	 * @param array<int,array<string,mixed>> $rates
	 */
	private static function service_assignment_row( int|string $index, array $row, array $options, array $rates ): void {
		$service_id  = absint( $row['service_id'] ?? 0 );
		$mode        = in_array( (string) ( $row['pricing_mode'] ?? 'inherit' ), [ 'inherit', 'custom' ], true ) ? (string) $row['pricing_mode'] : 'inherit';
		$custom_tax  = ServicePricing::normalize_tax_mode( (string) ( $row['custom_tax_mode'] ?? ServicePricing::TAX_EXCLUSIVE ) );
		$custom_rate = absint( $row['custom_tax_rate_id'] ?? 0 );
		$custom_amount = isset( $row['custom_amount_minor'] ) ? (int) $row['custom_amount_minor'] : null;
		$custom_currency = ServicePricing::normalize_currency( (string) ( $row['custom_currency'] ?? ServicePricing::DEFAULT_CURRENCY ) );
		$summary = $service_id > 0 ? Services::pricing_summary( array_merge( $row, [ 'service_id' => $service_id ] ) ) : __( 'Choose a service to see its standard pricing.', 'core-blueprint-crm' );
		?>
		<div class="cb-crm-service-card" data-cb-crm-row data-cb-crm-service-card>
			<div class="cb-crm-service-grid">
				<div class="cb-crm-field is-wide">
					<label><strong><?php esc_html_e( 'Service', 'core-blueprint-crm' ); ?></strong></label>
					<select name="cb_crm_services[<?php echo esc_attr( (string) $index ); ?>][service_id]" data-cb-crm-service-select>
						<option value="0"><?php esc_html_e( 'Choose a service…', 'core-blueprint-crm' ); ?></option>
						<?php
						$found = false;
						foreach ( $options as $service ) :
							$pricing_summary = ServicePricing::summary( ServicePricing::get( (int) $service->ID ) );
							if ( (int) $service->ID === $service_id ) { $found = true; }
							?>
							<option value="<?php echo esc_attr( (string) $service->ID ); ?>" data-price-summary="<?php echo esc_attr( $pricing_summary ); ?>" <?php selected( $service_id, (int) $service->ID ); ?>><?php echo esc_html( $service->post_title ); ?></option>
						<?php endforeach; ?>
						<?php if ( $service_id > 0 && ! $found ) : ?>
							<option value="<?php echo esc_attr( (string) $service_id ); ?>" selected><?php echo esc_html( sprintf( __( 'Unavailable service #%d', 'core-blueprint-crm' ), $service_id ) ); ?></option>
						<?php endif; ?>
					</select>
				</div>
				<div class="cb-crm-field">
					<label><strong><?php esc_html_e( 'Status', 'core-blueprint-crm' ); ?></strong></label>
					<select name="cb_crm_services[<?php echo esc_attr( (string) $index ); ?>][status]">
						<?php foreach ( Services::status_labels() as $value => $label ) : ?>
							<option value="<?php echo esc_attr( $value ); ?>" <?php selected( (string) ( $row['status'] ?? 'active' ), $value ); ?>><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
				</div>
				<div class="cb-crm-field"><label><strong><?php esc_html_e( 'From', 'core-blueprint-crm' ); ?></strong></label><input type="date" name="cb_crm_services[<?php echo esc_attr( (string) $index ); ?>][started_at]" value="<?php echo esc_attr( (string) ( $row['started_at'] ?? '' ) ); ?>"></div>
				<div class="cb-crm-field"><label><strong><?php esc_html_e( 'Until', 'core-blueprint-crm' ); ?></strong></label><input type="date" name="cb_crm_services[<?php echo esc_attr( (string) $index ); ?>][ended_at]" value="<?php echo esc_attr( (string) ( $row['ended_at'] ?? '' ) ); ?>"></div>
				<div class="cb-crm-field is-wide">
					<label><strong><?php esc_html_e( 'Pricing', 'core-blueprint-crm' ); ?></strong></label>
					<select name="cb_crm_services[<?php echo esc_attr( (string) $index ); ?>][pricing_mode]" data-cb-crm-pricing-mode>
						<option value="inherit" <?php selected( $mode, 'inherit' ); ?>><?php esc_html_e( 'Use service pricing', 'core-blueprint-crm' ); ?></option>
						<option value="custom" <?php selected( $mode, 'custom' ); ?>><?php esc_html_e( 'Custom pricing', 'core-blueprint-crm' ); ?></option>
					</select>
					<p class="description" data-cb-crm-service-price-summary><?php echo esc_html( $summary ); ?></p>
				</div>
			</div>

			<div class="cb-crm-custom-pricing" data-cb-crm-custom-pricing <?php echo 'custom' !== $mode ? 'hidden' : ''; ?>>
				<div class="cb-crm-service-grid">
					<div class="cb-crm-field"><label><strong><?php esc_html_e( 'Custom price', 'core-blueprint-crm' ); ?></strong></label><input type="text" inputmode="decimal" name="cb_crm_services[<?php echo esc_attr( (string) $index ); ?>][custom_amount]" value="<?php echo esc_attr( ServicePricing::amount_input( $custom_amount ) ); ?>" placeholder="39.95"></div>
					<div class="cb-crm-field is-small"><label><strong><?php esc_html_e( 'Currency', 'core-blueprint-crm' ); ?></strong></label><input type="text" maxlength="3" name="cb_crm_services[<?php echo esc_attr( (string) $index ); ?>][custom_currency]" value="<?php echo esc_attr( $custom_currency ); ?>" placeholder="EUR"></div>
					<div class="cb-crm-field"><label><strong><?php esc_html_e( 'Price is', 'core-blueprint-crm' ); ?></strong></label><select name="cb_crm_services[<?php echo esc_attr( (string) $index ); ?>][custom_tax_mode]" data-cb-crm-tax-mode><?php foreach ( ServicePricing::tax_mode_labels() as $value => $label ) : ?><option value="<?php echo esc_attr( $value ); ?>" <?php selected( $custom_tax, $value ); ?>><?php echo esc_html( $label ); ?></option><?php endforeach; ?></select></div>
					<div class="cb-crm-field" data-cb-crm-tax-rate-field <?php echo ServicePricing::TAX_EXEMPT === $custom_tax ? 'hidden' : ''; ?>><label><strong><?php esc_html_e( 'Tax rate', 'core-blueprint-crm' ); ?></strong></label><?php self::tax_rate_select( 'cb_crm_services[' . $index . '][custom_tax_rate_id]', '', $custom_rate, $rates ); ?></div>
				</div>
			</div>

			<div class="cb-crm-field cb-crm-agreement-notes">
				<label><strong><?php esc_html_e( 'Agreement notes', 'core-blueprint-crm' ); ?></strong></label>
				<textarea rows="2" class="widefat" name="cb_crm_services[<?php echo esc_attr( (string) $index ); ?>][notes]" placeholder="<?php echo esc_attr__( 'Optional context about this service or price agreement.', 'core-blueprint-crm' ); ?>"><?php echo esc_textarea( (string) ( $row['notes'] ?? '' ) ); ?></textarea>
			</div>
			<div class="cb-crm-service-actions"><button type="button" class="button-link-delete" data-cb-crm-remove-row><?php esc_html_e( 'Remove service', 'core-blueprint-crm' ); ?></button></div>
		</div>
		<?php
	}

	/** @param array<int,array<string,mixed>> $rates */
	private static function tax_rate_select( string $name, string $id, int $selected, array $rates ): void {
		printf( '<select %s name="%s">', '' !== $id ? 'id="' . esc_attr( $id ) . '"' : '', esc_attr( $name ) );
		echo '<option value="0">' . esc_html__( 'Select tax rate…', 'core-blueprint-crm' ) . '</option>';
		foreach ( $rates as $rate ) {
			$rate_id = (int) $rate['id'];
			$label = TaxRates::display_label( $rate );
			$inactive = empty( $rate['is_active'] );
			if ( $inactive ) {
				$label .= ' — ' . __( 'Inactive', 'core-blueprint-crm' );
			}
			echo '<option value="' . esc_attr( (string) $rate_id ) . '" ' . selected( $selected, $rate_id, false ) . ( $inactive && $selected !== $rate_id ? ' disabled' : '' ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select>';
	}
}

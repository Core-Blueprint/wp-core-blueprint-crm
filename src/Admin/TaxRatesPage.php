<?php
declare(strict_types=1);

namespace CB\CRM\Admin;

use CB\Core\UI\FormComposition;
use CB\CRM\Capabilities;
use CB\CRM\Governance;
use CB\CRM\Repository\TaxRates;

defined( 'ABSPATH' ) || exit;

final class TaxRatesPage {
	public const SLUG = 'core-blueprint-crm-tax-rates';

	public static function init(): void {
		add_action( 'admin_menu', [ __CLASS__, 'register_menu' ], 6 );
		add_action( 'admin_post_cb_crm_add_tax_rate', [ __CLASS__, 'add_rate' ] );
		add_action( 'admin_post_cb_crm_toggle_tax_rate', [ __CLASS__, 'toggle_rate' ] );
		add_action( 'admin_enqueue_scripts', [ __CLASS__, 'enqueue' ] );
	}

	public static function register_menu(): void {
		add_submenu_page(
			Menu::TOP_LEVEL_SLUG,
			__( 'Tax Rates', 'core-blueprint-crm' ),
			__( 'Tax Rates', 'core-blueprint-crm' ),
			Capabilities::MANAGE,
			self::SLUG,
			[ __CLASS__, 'render' ]
		);
	}

	public static function url(): string {
		return admin_url( 'admin.php?page=' . self::SLUG );
	}

	public static function enqueue(): void {
		$page = isset( $_GET['page'] ) ? sanitize_key( (string) wp_unslash( $_GET['page'] ) ) : '';
		if ( self::SLUG !== $page ) {
			return;
		}
		FormComposition::enqueue( FormComposition::PRESENTATION_WP_NATIVE );
		wp_enqueue_style( 'cb-crm-pricing', CB_CRM_URL . 'assets/pricing.css', [], CB_CRM_VERSION );
		wp_enqueue_script( 'cb-crm-tax-rates', CB_CRM_URL . 'assets/tax-rates.js', [], CB_CRM_VERSION, true );
	}

	public static function add_rate(): void {
		self::guard( 'cb_crm_add_tax_rate' );
		$input = isset( $_POST['tax_rate'] ) && is_array( $_POST['tax_rate'] ) ? wp_unslash( $_POST['tax_rate'] ) : [];
		$id = TaxRates::create( $input );
		if ( $id > 0 ) {
			Governance::record_tax_rate_changed( $id, 'created' );
			self::redirect( 'created' );
		}
		self::redirect( 'invalid' );
	}

	public static function toggle_rate(): void {
		self::guard( 'cb_crm_toggle_tax_rate' );
		$id = isset( $_POST['tax_rate_id'] ) ? absint( $_POST['tax_rate_id'] ) : 0;
		$active = isset( $_POST['active'] ) && '1' === (string) $_POST['active'];
		if ( $id > 0 && TaxRates::set_active( $id, $active ) ) {
			Governance::record_tax_rate_changed( $id, $active ? 'activated' : 'deactivated' );
			self::redirect( $active ? 'activated' : 'deactivated' );
		}
		self::redirect( 'invalid' );
	}

	public static function render(): void {
		if ( ! current_user_can( Capabilities::MANAGE ) ) {
			wp_die( esc_html__( 'You do not have permission to manage CRM tax rates.', 'core-blueprint-crm' ) );
		}
		$rates = TaxRates::all( true );
		$notice = isset( $_GET['cb_crm_tax_notice'] ) ? sanitize_key( (string) wp_unslash( $_GET['cb_crm_tax_notice'] ) ) : '';
		?>
		<div class="wrap cb-crm-tax-rates-page">
			<h1><?php esc_html_e( 'Tax Rates', 'core-blueprint-crm' ); ?></h1>
			<p class="description"><?php esc_html_e( 'Manage reusable VAT/tax rates for CRM service pricing. Existing rates are deactivated rather than deleted so historical references remain intact.', 'core-blueprint-crm' ); ?></p>
			<?php self::notice( $notice ); ?>

			<div class="cb-crm-settings-grid">
				<div class="postbox">
					<div class="inside">
						<h2><?php esc_html_e( 'Add tax rate', 'core-blueprint-crm' ); ?></h2>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" data-cb-crm-tax-rate-form>
							<input type="hidden" name="action" value="cb_crm_add_tax_rate">
							<?php wp_nonce_field( 'cb_crm_add_tax_rate' ); ?>
							<table class="form-table" role="presentation"><tbody>
								<tr><th><label for="cb-crm-tax-label"><?php esc_html_e( 'Label', 'core-blueprint-crm' ); ?></label></th><td><input type="text" id="cb-crm-tax-label" class="regular-text" name="tax_rate[label]" required placeholder="NL Standard" data-cb-crm-tax-label></td></tr>
								<tr><th><label for="cb-crm-tax-code"><?php esc_html_e( 'Code', 'core-blueprint-crm' ); ?></label></th><td><input type="text" id="cb-crm-tax-code" class="regular-text" name="tax_rate[code]" placeholder="nl-standard" data-cb-crm-tax-code><p class="description"><?php esc_html_e( 'Generated automatically from the label. Change it only when you need a different stable internal code.', 'core-blueprint-crm' ); ?></p></td></tr>
								<tr><th><label for="cb-crm-tax-country"><?php esc_html_e( 'Country code', 'core-blueprint-crm' ); ?></label></th><td><input type="text" id="cb-crm-tax-country" class="small-text" name="tax_rate[country_code]" maxlength="2" placeholder="NL"><p class="description"><?php esc_html_e( 'Optional ISO 3166-1 alpha-2 country code.', 'core-blueprint-crm' ); ?></p></td></tr>
								<tr><th><label for="cb-crm-tax-rate"><?php esc_html_e( 'Rate', 'core-blueprint-crm' ); ?></label></th><td><input type="text" id="cb-crm-tax-rate" class="small-text" name="tax_rate[rate]" inputmode="decimal" required placeholder="21"><span class="description"> %</span></td></tr>
								<tr><th><label for="cb-crm-tax-from"><?php esc_html_e( 'Valid from', 'core-blueprint-crm' ); ?></label></th><td><input id="cb-crm-tax-from" type="date" name="tax_rate[valid_from]"></td></tr>
								<tr><th><label for="cb-crm-tax-until"><?php esc_html_e( 'Valid until', 'core-blueprint-crm' ); ?></label></th><td><input id="cb-crm-tax-until" type="date" name="tax_rate[valid_until]"></td></tr>
							</tbody></table>
							<?php submit_button( __( 'Add tax rate', 'core-blueprint-crm' ) ); ?>
						</form>
					</div>
				</div>

				<div class="postbox">
					<div class="inside">
						<h2><?php esc_html_e( 'Configured rates', 'core-blueprint-crm' ); ?></h2>
						<?php if ( ! $rates ) : ?>
							<p><?php esc_html_e( 'No tax rates configured yet.', 'core-blueprint-crm' ); ?></p>
						<?php else : ?>
							<table class="widefat striped cb-crm-tax-rates-table">
								<thead><tr><th><?php esc_html_e( 'Code', 'core-blueprint-crm' ); ?></th><th><?php esc_html_e( 'Label', 'core-blueprint-crm' ); ?></th><th><?php esc_html_e( 'Country', 'core-blueprint-crm' ); ?></th><th><?php esc_html_e( 'Rate', 'core-blueprint-crm' ); ?></th><th><?php esc_html_e( 'Validity', 'core-blueprint-crm' ); ?></th><th><?php esc_html_e( 'Status', 'core-blueprint-crm' ); ?></th><th><?php esc_html_e( 'Actions', 'core-blueprint-crm' ); ?></th></tr></thead>
								<tbody>
								<?php foreach ( $rates as $rate ) : ?>
									<tr>
										<td><code><?php echo esc_html( (string) $rate['code'] ); ?></code></td>
										<td><?php echo esc_html( (string) $rate['label'] ); ?></td>
										<td><?php echo esc_html( (string) $rate['country_code'] ); ?></td>
										<td><?php echo esc_html( TaxRates::format_rate_bp( (int) $rate['rate_bp'] ) . '%' ); ?></td>
										<td><?php echo esc_html( self::validity( $rate ) ); ?></td>
										<td><?php echo ! empty( $rate['is_active'] ) ? esc_html__( 'Active', 'core-blueprint-crm' ) : esc_html__( 'Inactive', 'core-blueprint-crm' ); ?></td>
										<td>
											<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
												<input type="hidden" name="action" value="cb_crm_toggle_tax_rate"><input type="hidden" name="tax_rate_id" value="<?php echo esc_attr( (string) $rate['id'] ); ?>"><input type="hidden" name="active" value="<?php echo empty( $rate['is_active'] ) ? '1' : '0'; ?>">
												<?php wp_nonce_field( 'cb_crm_toggle_tax_rate' ); ?>
												<button class="button" type="submit"><?php echo empty( $rate['is_active'] ) ? esc_html__( 'Activate', 'core-blueprint-crm' ) : esc_html__( 'Deactivate', 'core-blueprint-crm' ); ?></button>
											</form>
										</td>
									</tr>
								<?php endforeach; ?>
								</tbody>
							</table>
						<?php endif; ?>
					</div>
				</div>
			</div>
		</div>
		<?php
	}

	private static function guard( string $nonce_action ): void {
		if ( ! current_user_can( Capabilities::MANAGE ) ) {
			wp_die( esc_html__( 'You do not have permission to manage CRM tax rates.', 'core-blueprint-crm' ) );
		}
		check_admin_referer( $nonce_action );
	}

	private static function redirect( string $notice ): never {
		wp_safe_redirect( add_query_arg( 'cb_crm_tax_notice', sanitize_key( $notice ), self::url() ) );
		exit;
	}

	private static function notice( string $notice ): void {
		$messages = [
			'created'     => [ 'success', __( 'Tax rate added.', 'core-blueprint-crm' ) ],
			'activated'   => [ 'success', __( 'Tax rate activated.', 'core-blueprint-crm' ) ],
			'deactivated' => [ 'success', __( 'Tax rate deactivated. Existing references remain intact.', 'core-blueprint-crm' ) ],
			'invalid'     => [ 'error', __( 'The tax rate could not be saved. Check the code, percentage and validity dates.', 'core-blueprint-crm' ) ],
		];
		if ( ! isset( $messages[ $notice ] ) ) {
			return;
		}
		[ $type, $message ] = $messages[ $notice ];
		printf( '<div class="notice notice-%s is-dismissible"><p>%s</p></div>', esc_attr( $type ), esc_html( $message ) );
	}

	/** @param array<string,mixed> $rate */
	private static function validity( array $rate ): string {
		$from = (string) ( $rate['valid_from'] ?? '' );
		$until = (string) ( $rate['valid_until'] ?? '' );
		if ( '' === $from && '' === $until ) {
			return __( 'No date limit', 'core-blueprint-crm' );
		}
		if ( '' !== $from && '' !== $until ) {
			return $from . ' → ' . $until;
		}
		return '' !== $from ? sprintf( __( 'From %s', 'core-blueprint-crm' ), $from ) : sprintf( __( 'Until %s', 'core-blueprint-crm' ), $until );
	}
}

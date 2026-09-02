<?php
declare(strict_types=1);

namespace CB\CRM\Admin;

use CB\CRM\Capabilities;
use CB\CRM\Content\PostTypes;
use CB\CRM\Repository\TaxRates;

defined( 'ABSPATH' ) || exit;

final class Menu {
	public const TOP_LEVEL_SLUG = 'core-blueprint-crm';

	public static function init(): void {
		add_action( 'admin_menu', [ __CLASS__, 'register' ], 5 );
	}

	public static function register(): void {
		add_menu_page(
			__( 'CRM', 'core-blueprint-crm' ),
			__( 'CRM', 'core-blueprint-crm' ),
			Capabilities::MANAGE,
			self::TOP_LEVEL_SLUG,
			[ __CLASS__, 'render_dashboard' ],
			'dashicons-groups',
			26.4
		);

		add_submenu_page( self::TOP_LEVEL_SLUG, __( 'Overview', 'core-blueprint-crm' ), __( 'Overview', 'core-blueprint-crm' ), Capabilities::MANAGE, self::TOP_LEVEL_SLUG, [ __CLASS__, 'render_dashboard' ] );
		add_submenu_page( self::TOP_LEVEL_SLUG, __( 'Contacts', 'core-blueprint-crm' ), __( 'Contacts', 'core-blueprint-crm' ), Capabilities::MANAGE, 'edit.php?post_type=' . PostTypes::CONTACT );
		add_submenu_page( self::TOP_LEVEL_SLUG, __( 'Organizations', 'core-blueprint-crm' ), __( 'Organizations', 'core-blueprint-crm' ), Capabilities::MANAGE, 'edit.php?post_type=' . PostTypes::ORGANIZATION );
		add_submenu_page( self::TOP_LEVEL_SLUG, __( 'Services', 'core-blueprint-crm' ), __( 'Services', 'core-blueprint-crm' ), Capabilities::MANAGE, 'edit.php?post_type=' . PostTypes::SERVICE );
		add_submenu_page( self::TOP_LEVEL_SLUG, __( 'Tags', 'core-blueprint-crm' ), __( 'Tags', 'core-blueprint-crm' ), Capabilities::MANAGE, 'edit-tags.php?taxonomy=' . PostTypes::TAG . '&post_type=' . PostTypes::CONTACT );
	}

	public static function render_dashboard(): void {
		if ( ! current_user_can( Capabilities::MANAGE ) ) {
			wp_die( esc_html__( 'You do not have permission to manage CRM data.', 'core-blueprint-crm' ) );
		}
		$items = [
			[ __( 'Contacts', 'core-blueprint-crm' ), self::count( PostTypes::CONTACT ), admin_url( 'edit.php?post_type=' . PostTypes::CONTACT ), __( 'People and their customer context.', 'core-blueprint-crm' ) ],
			[ __( 'Organizations', 'core-blueprint-crm' ), self::count( PostTypes::ORGANIZATION ), admin_url( 'edit.php?post_type=' . PostTypes::ORGANIZATION ), __( 'Companies, institutions and other organizations.', 'core-blueprint-crm' ) ],
			[ __( 'Services', 'core-blueprint-crm' ), self::count( PostTypes::SERVICE ), admin_url( 'edit.php?post_type=' . PostTypes::SERVICE ), __( 'Services that can be linked to contacts and organizations.', 'core-blueprint-crm' ) ],
		];
		$active_tax_rates = count( TaxRates::all( false ) );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Core Blueprint CRM', 'core-blueprint-crm' ); ?></h1>
			<p class="description"><?php esc_html_e( 'Self-hosted customer relationship management for contacts, organizations, services and customer context.', 'core-blueprint-crm' ); ?></p>
			<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:16px;max-width:1000px;margin-top:20px;">
				<?php foreach ( $items as [ $label, $count, $url, $description ] ) : ?>
					<div class="postbox" style="margin:0;">
						<div class="inside">
							<h2 style="margin-top:0;"><?php echo esc_html( $label ); ?></h2>
							<p style="font-size:28px;margin:8px 0;"><strong><?php echo esc_html( (string) $count ); ?></strong></p>
							<p><?php echo esc_html( $description ); ?></p>
							<p><a class="button" href="<?php echo esc_url( $url ); ?>"><?php esc_html_e( 'Manage', 'core-blueprint-crm' ); ?></a></p>
						</div>
					</div>
				<?php endforeach; ?>
			</div>

			<h2 style="margin-top:28px;"><?php esc_html_e( 'Configuration', 'core-blueprint-crm' ); ?></h2>
			<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:16px;max-width:1000px;">
				<div class="postbox" style="margin:0;max-width:320px;">
					<div class="inside">
						<h2 style="margin-top:0;"><?php esc_html_e( 'Tax Rates', 'core-blueprint-crm' ); ?></h2>
						<p style="font-size:28px;margin:8px 0;"><strong><?php echo esc_html( (string) $active_tax_rates ); ?></strong> <span style="font-size:13px;font-weight:400;color:#646970;"><?php esc_html_e( 'active', 'core-blueprint-crm' ); ?></span></p>
						<p><?php esc_html_e( 'Reusable VAT/tax rates for service pricing.', 'core-blueprint-crm' ); ?></p>
						<p><a class="button" href="<?php echo esc_url( TaxRatesPage::url() ); ?>"><?php esc_html_e( 'Manage', 'core-blueprint-crm' ); ?></a></p>
					</div>
				</div>
			</div>
		</div>
		<?php
	}

	private static function count( string $post_type ): int {
		$counts = wp_count_posts( $post_type );
		$total  = 0;
		foreach ( get_object_vars( $counts ) as $status => $count ) {
			if ( ! in_array( $status, [ 'trash', 'auto-draft' ], true ) ) {
				$total += (int) $count;
			}
		}
		return $total;
	}
}

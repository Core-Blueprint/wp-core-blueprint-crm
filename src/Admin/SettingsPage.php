<?php
declare(strict_types=1);

namespace CB\CRM\Admin;

use CB\CRM\Capabilities;
use CB\CRM\Integration\Suite;
use CoreBlueprint\Core\Admin\SettingsRegistry;

defined( 'ABSPATH' ) || exit;

final class SettingsPage {
	private static bool $initialized = false;

	public static function init(): void {
		if ( self::$initialized ) {
			return;
		}
		self::$initialized = true;

		add_action( 'core_blueprint_register_settings', [ self::class, 'register' ] );
	}

	public static function register(): void {
		SettingsRegistry::register(
			Suite::ID,
			[
				'label'        => __( 'CRM', 'core-blueprint-crm' ),
				'description'  => __( 'Customer identity, organizations, context and customer-specific service agreements. The service catalog and VAT are managed by Core Blueprint Work.', 'core-blueprint-crm' ),
				'group'        => SettingsRegistry::GROUP_BUSINESS,
				'capability'   => Capabilities::MANAGE,
				'renderer'     => [ self::class, 'render' ],
				'requirements' => [
					'components' => [ 'buttons', 'panels' ],
				],
			]
		);
	}

	public static function render(): void {
		if ( ! current_user_can( Capabilities::MANAGE ) ) {
			wp_die( esc_html__( 'You do not have permission to manage CRM data.', 'core-blueprint-crm' ) );
		}
		?>
		<section class="cb-core-panel">
			<h2><?php esc_html_e( 'Core Blueprint CRM', 'core-blueprint-crm' ); ?></h2>
			<p><?php esc_html_e( 'Customer identity, organizations, context and customer-specific service agreements. The service catalog and VAT are managed by Core Blueprint Work.', 'core-blueprint-crm' ); ?></p>
			<p>
				<a class="button cb-core-button cb-core-button--primary" href="<?php echo esc_url( admin_url( 'admin.php?page=' . Menu::TOP_LEVEL_SLUG ) ); ?>"><?php esc_html_e( 'Manage', 'core-blueprint-crm' ); ?></a>
			</p>
		</section>
		<?php
	}
}

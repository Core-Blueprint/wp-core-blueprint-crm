<?php
/**
 * Plugin Name:       Core Blueprint CRM
 * Plugin URI:        https://coreblueprint.io
 * Description:       Self-hosted customer relationship management for people, organizations, services and customer context.
 * Version:           0.1.0-rc2
 * Author:            Core Blueprint
 * Author URI:        https://coreblueprint.io
 * License:           GPL-2.0+
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       core-blueprint-crm
 * Domain Path:       /languages
 * Requires at least: 7.0
 * Requires PHP:      8.4
 *
 * @package CB_CRM
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

define( 'CB_CRM_VERSION', '0.1.0-rc2' );
define( 'CB_CRM_SCHEMA_VERSION', '1.1' );
define( 'CB_CRM_REQUIRED_API', '1.0' );
define( 'CB_CRM_REQUIRED_BASE', '1.0.0-rc3.40' );
define( 'CB_CRM_FILE', __FILE__ );
define( 'CB_CRM_DIR', plugin_dir_path( __FILE__ ) );
define( 'CB_CRM_URL', plugin_dir_url( __FILE__ ) );
define( 'CB_CRM_BASENAME', plugin_basename( __FILE__ ) );

spl_autoload_register( static function ( string $class ): void {
	$prefix = 'CB\\CRM\\';
	if ( 0 !== strncmp( $class, $prefix, strlen( $prefix ) ) ) {
		return;
	}
	$relative = substr( $class, strlen( $prefix ) );
	$file = CB_CRM_DIR . 'src/' . str_replace( '\\', '/', $relative ) . '.php';
	if ( is_file( $file ) ) {
		require_once $file;
	}
} );

add_action( 'init', static function (): void {
	load_plugin_textdomain( 'core-blueprint-crm', false, dirname( CB_CRM_BASENAME ) . '/languages' );
}, 1 );

function cb_crm_api_compatible( string $available, string $required ): bool {
	if ( 1 !== preg_match( '/^(\\d+)\\.(\\d+)$/', $available, $a ) || 1 !== preg_match( '/^(\\d+)\\.(\\d+)$/', $required, $r ) ) {
		return false;
	}
	return (int) $a[1] === (int) $r[1] && (int) $a[2] >= (int) $r[2];
}

function cb_crm_base_ready(): bool {
	if ( ! defined( 'CB_CORE_API_VERSION' ) || ! defined( 'CB_CORE_VERSION' ) ) {
		return false;
	}
	if ( ! cb_crm_api_compatible( (string) CB_CORE_API_VERSION, CB_CRM_REQUIRED_API ) ) {
		return false;
	}
	if ( version_compare( (string) CB_CORE_VERSION, CB_CRM_REQUIRED_BASE, '<' ) ) {
		return false;
	}
	return class_exists( '\\CB\\Core\\ExtensionRegistry' )
		&& class_exists( '\\CB\\Core\\Database\\SchemaRegistry' );
}

function cb_crm_dependency_message(): string {
	if ( ! defined( 'CB_CORE_API_VERSION' ) || ! defined( 'CB_CORE_VERSION' ) ) {
		return __( 'Core Blueprint CRM requires an active Core Blueprint Base plugin.', 'core-blueprint-crm' );
	}
	if ( ! cb_crm_api_compatible( (string) CB_CORE_API_VERSION, CB_CRM_REQUIRED_API ) ) {
		return sprintf(
			__( 'Core Blueprint CRM requires Core API %1$s or a newer compatible minor version. This site provides %2$s.', 'core-blueprint-crm' ),
			CB_CRM_REQUIRED_API,
			(string) CB_CORE_API_VERSION
		);
	}
	return sprintf(
		__( 'Core Blueprint CRM requires Core Blueprint Base %1$s or newer. This site provides %2$s.', 'core-blueprint-crm' ),
		CB_CRM_REQUIRED_BASE,
		(string) CB_CORE_VERSION
	);
}

function cb_crm_activate(): void {
	if ( ! cb_crm_base_ready() ) {
		if ( ! function_exists( 'deactivate_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		deactivate_plugins( CB_CRM_BASENAME );
		wp_die(
			esc_html( sprintf( 'Core Blueprint CRM requires Core Blueprint Base %s or newer with Core API 1.x.', CB_CRM_REQUIRED_BASE ) ),
			esc_html( 'Core Blueprint dependency required' ),
			[ 'back_link' => true ]
		);
	}
	\CB\CRM\Install::activate();
}
register_activation_hook( __FILE__, 'cb_crm_activate' );

// Register extension-owned tables before Base's central schema sweep at plugins_loaded priority 5.
add_action( 'plugins_loaded', static function (): void {
	if ( cb_crm_base_ready() ) {
		\CB\CRM\Database\Schema::register();
	}
}, 4 );

add_action( 'plugins_loaded', static function (): void {
	if ( ! cb_crm_base_ready() ) {
		if ( is_admin() ) {
			add_action( 'admin_notices', static function (): void {
				if ( current_user_can( 'activate_plugins' ) ) {
					printf( '<div class="notice notice-error"><p><strong>%s</strong> %s</p></div>', esc_html__( 'Core Blueprint CRM:', 'core-blueprint-crm' ), esc_html( cb_crm_dependency_message() ) );
				}
			} );
		}
		return;
	}
	\CB\CRM\Plugin::boot();
}, 30 );

<?php
/**
 * Plugin Name:       Core Blueprint CRM
 * Plugin URI:        https://coreblueprint.io
 * Description:       Self-hosted customer relationship management for contacts, organizations and customer-specific commercial agreements.
 * Version:           1.0.0-rc1
 * Author:            Core Blueprint
 * Author URI:        https://coreblueprint.io
 * License:           GPL-2.0+
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       core-blueprint-crm
 * Domain Path:       /languages
 * Requires at least: 7.0
 * Requires PHP:      8.4
 * Requires Plugins: core-blueprint
 *
 * @package CB_CRM
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

if ( defined( 'CB_CRM_FILE' ) ) {
	return;
}

define( 'CB_CRM_NAME', 'Core Blueprint CRM' );
define( 'CB_CRM_VERSION', '1.0.0-rc1' );
define( 'CB_CRM_SCHEMA_VERSION', '1.5' );
define( 'CB_CRM_MIN_PHP', '8.4' );
define( 'CB_CRM_REQUIRED_API', '1.0' );
define( 'CB_CRM_FILE', __FILE__ );
define( 'CB_CRM_DIR', plugin_dir_path( __FILE__ ) );
define( 'CB_CRM_URL', plugin_dir_url( __FILE__ ) );
define( 'CB_CRM_BASENAME', plugin_basename( __FILE__ ) );

/* Bootstrap v1 earliest-safe PHP boundary. */
if ( version_compare( PHP_VERSION, CB_CRM_MIN_PHP, '<' ) ) {
	register_activation_hook( __FILE__, static function () {
		if ( ! function_exists( 'deactivate_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		deactivate_plugins( CB_CRM_BASENAME );
		wp_die(
			esc_html( sprintf( 'PHP %1$s or newer is required. This server runs PHP %2$s.', CB_CRM_MIN_PHP, PHP_VERSION ) ),
			esc_html( 'Core Blueprint requirements not met' ),
			[
				'link_url'  => admin_url( 'plugins.php' ),
				'link_text' => __( 'Plugins' ),
			]
		);
	} );

	add_action( 'admin_notices', static function () {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}
		printf(
			'<div class="notice notice-error"><p><strong>%s</strong> %s</p></div>',
			esc_html( CB_CRM_NAME . ':' ),
			esc_html( sprintf( 'PHP %1$s or newer is required. This server runs PHP %2$s.', CB_CRM_MIN_PHP, PHP_VERSION ) )
		);
	} );
	return;
}

spl_autoload_register( static function ( string $class ): void {
	$prefix = 'CB\\CRM\\';
	$length = strlen( $prefix );
	if ( 0 !== strncmp( $class, $prefix, $length ) ) {
		return;
	}
	$relative = substr( $class, $length );
	$file     = CB_CRM_DIR . 'src/' . str_replace( '\\', '/', $relative ) . '.php';
	if ( is_file( $file ) ) {
		require_once $file;
	}
} );

add_action( 'init', static function (): void {
	load_plugin_textdomain( 'core-blueprint-crm', false, dirname( CB_CRM_BASENAME ) . '/languages' );
}, 1 );

/** Lightweight Base contract required only for canonical suite registration. */
function cb_crm_registration_contract_ready(): bool {
	return class_exists( '\\CB\\Core\\ExtensionRegistry' );
}

/** Product-specific public Base services consumed by CRM runtime. */
function cb_crm_product_contracts_ready(): bool {
	return class_exists( '\\CB\\Core\\Database\\SchemaRegistry' )
		&& class_exists( '\\CB\\Core\\Governance\\Audit' )
		&& class_exists( '\\CB\\Core\\Governance\\EventRegistry' );
}

/** CRM product readiness after generic Bootstrap v1 has passed. */
function cb_crm_product_ready(): bool {
	return cb_crm_registration_contract_ready() && cb_crm_product_contracts_ready();
}

/** Canonical current-request readiness for CRM product/public runtime. */
function cb_crm_runtime_ready(): bool {
	return \CB\CRM\Support\Requirements::runtime_ready() && cb_crm_product_ready();
}

/* Every CRM management-capability path fails closed while product runtime is unavailable. */
add_filter( 'map_meta_cap', static function ( array $caps, string $cap ): array {
	if ( 'cb_manage_crm' === $cap && ! cb_crm_runtime_ready() ) {
		return [ 'do_not_allow' ];
	}
	return $caps;
}, 10, 2 );

/** Translation-safe operator message for the current dependency state. */
function cb_crm_dependency_message(): string {
	if ( ! \CB\CRM\Support\Requirements::runtime_ready() ) {
		return \CB\CRM\Support\Requirements::operator_message();
	}
	return __( 'Required Core Blueprint Base contracts are unavailable.', 'core-blueprint-crm' );
}

function cb_crm_fail_activation( string $message ): void {
	if ( ! function_exists( 'deactivate_plugins' ) ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
	}
	deactivate_plugins( CB_CRM_BASENAME );
	wp_die(
		esc_html( $message ),
		esc_html( 'Core Blueprint requirements not met' ),
		[
			'link_url'  => admin_url( 'plugins.php' ),
			'link_text' => __( 'Plugins' ),
		]
	);
}

function cb_crm_activate(): void {
	if ( ! \CB\CRM\Support\Requirements::runtime_ready() ) {
		cb_crm_fail_activation( \CB\CRM\Support\Requirements::activation_message() );
	}
	if ( ! cb_crm_product_ready() ) {
		cb_crm_fail_activation( 'Required Core Blueprint Base contracts are unavailable.' );
	}
	\CB\CRM\Install::activate();
}
register_activation_hook( __FILE__, 'cb_crm_activate' );

/* CRM must keep schema registration at priority 4 before Base migration priority 5. */
add_action( 'plugins_loaded', static function (): void {
	if (
		\CB\CRM\Support\Requirements::runtime_ready()
		&& class_exists( '\\CB\\Core\\Database\\SchemaRegistry' )
	) {
		\CB\CRM\Database\Schema::register();
	}
}, 4 );

/* CRM retains its existing product boot timing at plugins_loaded:30. */
add_action( 'plugins_loaded', static function (): void {
	if ( ! \CB\CRM\Support\Requirements::runtime_ready() ) {
		if ( is_admin() ) {
			add_action( 'admin_notices', static function (): void {
				if ( ! current_user_can( 'activate_plugins' ) ) {
					return;
				}
				printf(
					'<div class="notice notice-error"><p><strong>%s</strong> %s</p></div>',
					esc_html__( 'Core Blueprint CRM:', 'core-blueprint-crm' ),
					esc_html( \CB\CRM\Support\Requirements::operator_message() )
				);
			} );
		}
		return;
	}

	// Lightweight suite identity attaches immediately after generic Bootstrap
	// readiness, before CRM-specific Base contract/runtime gates.
	if ( cb_crm_registration_contract_ready() ) {
		\CB\CRM\Integration\Suite::init();
	}

	if ( ! cb_crm_product_ready() ) {
		if ( is_admin() ) {
			add_action( 'admin_notices', static function (): void {
				if ( ! current_user_can( 'activate_plugins' ) ) {
					return;
				}
				printf(
					'<div class="notice notice-error"><p><strong>%s</strong> %s</p></div>',
					esc_html__( 'Core Blueprint CRM:', 'core-blueprint-crm' ),
					esc_html( cb_crm_dependency_message() )
				);
			} );
		}
		return;
	}

	\CB\CRM\Plugin::boot();
}, 30 );

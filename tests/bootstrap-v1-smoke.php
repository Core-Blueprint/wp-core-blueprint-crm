<?php
declare(strict_types=1);

$root = dirname( __DIR__ );
$entry = file_get_contents( $root . '/core-blueprint-crm.php' );
$plugin = file_get_contents( $root . '/src/Plugin.php' );
$requirements = file_get_contents( $root . '/src/Support/Requirements.php' );
$tools = file_get_contents( $root . '/tools/check' );

foreach ( [ 'entry' => $entry, 'plugin' => $plugin, 'requirements' => $requirements, 'tools' => $tools ] as $name => $source ) {
	if ( false === $source ) {
		fwrite( STDERR, "FAIL: could not read {$name}.\n" );
		exit( 1 );
	}
}

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', $root . '/' );
}
if ( ! defined( 'CB_CRM_MIN_PHP' ) ) {
	define( 'CB_CRM_MIN_PHP', '8.4' );
}
if ( ! defined( 'CB_CRM_REQUIRED_API' ) ) {
	define( 'CB_CRM_REQUIRED_API', '1.0' );
}
require_once $root . '/src/Support/Requirements.php';

$compatible = \CB\CRM\Support\Requirements::api_compatible( '1.0', '1.0' )
	&& \CB\CRM\Support\Requirements::api_compatible( '1.8', '1.2' )
	&& ! \CB\CRM\Support\Requirements::api_compatible( '2.0', '1.0' )
	&& ! \CB\CRM\Support\Requirements::api_compatible( '1.0', '1.1' )
	&& ! \CB\CRM\Support\Requirements::api_compatible( 'garbage', '1.0' );

$php_gate = strpos( $entry, "version_compare( PHP_VERSION, CB_CRM_MIN_PHP, '<' )" );
$autoload = strpos( $entry, 'spl_autoload_register' );
$suite_init = strpos( $entry, '\\CB\\CRM\\Integration\\Suite::init();' );
$product_boot = strpos( $entry, '\\CB\\CRM\\Plugin::boot();' );

$checks = [
	'API compatibility follows same-major sufficient-minor semantics' => $compatible,
	'minimum PHP gate precedes product autoload' => false !== $php_gate && false !== $autoload && $php_gate < $autoload,
	'Base dependency is not expressed through Requires Plugins' => ! str_contains( $entry, 'Requires Plugins:' ),
	'failed activation explicitly deactivates the CRM plugin' => str_contains( $entry, 'deactivate_plugins( CB_CRM_BASENAME );' ),
	'failed activation exposes canonical Plugins destination' => str_contains( $entry, "'link_url'  => admin_url( 'plugins.php' )" ),
	'lightweight registration has its own ExtensionRegistry readiness boundary' => str_contains( $entry, 'function cb_crm_registration_contract_ready(): bool' ) && str_contains( $entry, "class_exists( '\\\\CB\\\\Core\\\\ExtensionRegistry' )" ),
	'product contracts are separate from registration readiness' => str_contains( $entry, 'function cb_crm_product_contracts_ready(): bool' ) && str_contains( $entry, 'SchemaRegistry' ) && str_contains( $entry, 'Governance\\Audit' ) && str_contains( $entry, 'Governance\\EventRegistry' ),
	'suite registration is attached before product boot' => false !== $suite_init && false !== $product_boot && $suite_init < $product_boot,
	'Plugin boot no longer owns suite registration' => ! str_contains( $plugin, 'Suite::init();' ) && ! str_contains( $plugin, 'Integration\\Suite' ),
	'schema registration keeps its priority-4 boundary' => str_contains( $entry, "}, 4 );" ) && str_contains( $entry, '\\CB\\CRM\\Database\\Schema::register();' ),
	'normal runtime remains inert when required public Base services disappear' => str_contains( $entry, 'if ( ! $registration_ready || ! cb_crm_product_contracts_ready() )' ),
	'Bootstrap smoke is wired into tools/check' => str_contains( $tools, 'bootstrap-v1-smoke.php' ),
];

foreach ( $checks as $label => $passed ) {
	if ( ! $passed ) {
		fwrite( STDERR, 'Bootstrap v1 conformance failed: ' . $label . "\n" );
		exit( 1 );
	}
}

echo "CRM Bootstrap v1 smoke passed.\n";

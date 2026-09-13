<?php
declare(strict_types=1);

$root         = dirname( __DIR__ );
$entry        = file_get_contents( $root . '/core-blueprint-crm.php' );
$requirements = file_get_contents( $root . '/src/Support/Requirements.php' );
$plugin       = file_get_contents( $root . '/src/Plugin.php' );
$install      = file_get_contents( $root . '/src/Install.php' );
$lifecycle    = file_get_contents( $root . '/src/Lifecycle.php' );
$suite        = file_get_contents( $root . '/src/Integration/Suite.php' );
$tools        = file_get_contents( $root . '/tools/check' );

foreach ( [
	'entry' => $entry,
	'requirements' => $requirements,
	'plugin' => $plugin,
	'install' => $install,
	'lifecycle' => $lifecycle,
	'suite' => $suite,
	'tools' => $tools,
] as $name => $source ) {
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

$php_gate     = strpos( $entry, "version_compare( PHP_VERSION, CB_CRM_MIN_PHP, '<' )" );
$autoload     = strpos( $entry, 'spl_autoload_register' );
$suite_init   = strpos( $entry, '\\CB\\CRM\\Integration\\Suite::init();' );
$product_gate = false !== $suite_init ? strpos( $entry, 'if ( ! cb_crm_product_ready() )', $suite_init ) : false;
$product_boot = false !== $product_gate ? strpos( $entry, '\\CB\\CRM\\Plugin::boot();', $product_gate ) : false;
$activation   = strpos( $entry, 'function cb_crm_fail_activation' );
$deactivate   = false !== $activation ? strpos( $entry, 'deactivate_plugins( CB_CRM_BASENAME );', $activation ) : false;
$die          = false !== $activation ? strpos( $entry, 'wp_die(', $activation ) : false;

$canonical_php       = 'PHP %1$s or newer is required. This server runs PHP %2$s.';
$canonical_base      = 'Core Blueprint must be installed and active.';
$canonical_api       = 'Core API %1$s or a newer compatible minor version is required. Available Core API: %2$s.';
$canonical_contracts = 'Required Core Blueprint Base contracts are unavailable.';

$checks = [
	'Base-required plugin declares exact versionless native WordPress dependency' => 1 === preg_match( '/^[ \t]*\*[ \t]*Requires Plugins:[ \t]*core-blueprint[ \t]*$/m', $entry ),
	'API compatibility follows same-major sufficient-minor semantics' => $compatible,
	'minimum PHP gate precedes product autoload' => false !== $php_gate && false !== $autoload && $php_gate < $autoload,
	'root autoloader uses PHP-floor-safe prefix matching' => str_contains( $entry, 'strncmp( $class, $prefix, $length )' ) && ! str_contains( $entry, 'str_starts_with( $class' ),
	'early PHP failure uses canonical factual body' => str_contains( $entry, "'{$canonical_php}'" ),
	'activation title is canonical' => str_contains( $entry, "'Core Blueprint requirements not met'" ) && ! str_contains( $entry, 'Core Blueprint dependency required' ),
	'activation return destination is Plugins' => str_contains( $entry, "'link_url'  => admin_url( 'plugins.php' )" ) && str_contains( $entry, "'link_text' => __( 'Plugins' )" ),
	'failed activation deactivates before wp_die' => false !== $deactivate && false !== $die && $deactivate < $die,
	'normal activation consumes raw issue-specific Bootstrap message' => str_contains( $entry, 'Requirements::activation_message()' ),
	'generic Bootstrap owns canonical Base-missing copy' => str_contains( $requirements, "'{$canonical_base}'" ),
	'generic Bootstrap owns canonical API-incompatible copy' => str_contains( $requirements, "'{$canonical_api}'" ),
	'generic Bootstrap exposes separate raw and translated messages' => str_contains( $requirements, 'public static function activation_message()' ) && str_contains( $requirements, 'public static function operator_message()' ),
	'generic Bootstrap does not absorb ExtensionRegistry' => ! str_contains( $requirements, 'ExtensionRegistry' ),
	'generic Bootstrap does not absorb SchemaRegistry' => ! str_contains( $requirements, 'SchemaRegistry' ),
	'generic Bootstrap does not absorb governance product contracts' => ! str_contains( $requirements, 'Governance\\' ),
	'product readiness owns Base service contracts separately' => str_contains( $entry, 'function cb_crm_product_ready(): bool' ) && str_contains( $entry, 'SchemaRegistry' ) && str_contains( $entry, 'Governance\\Audit' ) && str_contains( $entry, 'Governance\\EventRegistry' ),
	'product contract failure uses canonical factual body' => str_contains( $entry, "'{$canonical_contracts}'" ) && str_contains( $suite, "'{$canonical_contracts}'" ),
	'lightweight suite registration precedes the runtime product gate' => false !== $suite_init && false !== $product_gate && $suite_init < $product_gate,
	'product runtime remains behind the runtime product gate' => false !== $product_gate && false !== $product_boot && $product_gate < $product_boot,
	'schema registration preserves CRM priority 4' => str_contains( $entry, '\\CB\\CRM\\Database\\Schema::register();' ) && str_contains( $entry, '}, 4 );' ),
	'product boot preserves CRM priority 30' => str_contains( $entry, '\\CB\\CRM\\Plugin::boot();' ) && str_contains( $entry, '}, 30 );' ),
	'restored Base can recover on a later normal request' => str_contains( $entry, 'Requirements::runtime_ready()' ) && ! str_contains( $requirements, 'static $ready' ) && ! str_contains( $requirements, 'static $issues' ),
	'Install has no duplicate generic dependency guard' => ! str_contains( $install, 'CB_CORE_API_VERSION' ) && ! str_contains( $install, 'PHP_VERSION' ) && ! str_contains( $install, 'wp_die(' ),
	'Lifecycle has no duplicate generic dependency guard' => ! str_contains( $lifecycle, 'CB_CORE_API_VERSION' ) && ! str_contains( $lifecycle, 'PHP_VERSION' ) && ! str_contains( $lifecycle, 'wp_die(' ),
	'Plugin has no duplicate generic dependency guard' => ! str_contains( $plugin, 'CB_CORE_API_VERSION' ) && ! str_contains( $plugin, 'PHP_VERSION' ) && ! str_contains( $plugin, 'wp_die(' ),
	'Bootstrap smoke is wired into tools/check' => str_contains( $tools, 'bootstrap-v1-smoke.php' ),
];

foreach ( $checks as $label => $passed ) {
	if ( ! $passed ) {
		fwrite( STDERR, 'Bootstrap v1 conformance failed: ' . $label . "\n" );
		exit( 1 );
	}
}

echo "CRM Bootstrap v1 smoke passed.\n";

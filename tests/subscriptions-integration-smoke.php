<?php
declare(strict_types=1);

$root = dirname( __DIR__ );
$failed = false;

$fail = static function ( string $message ) use ( &$failed ): void {
	fwrite( STDERR, $message . "\n" );
	$failed = true;
};

$adapter   = file_get_contents( $root . '/src/Integration/Subscriptions.php' );
$plugin    = file_get_contents( $root . '/src/Plugin.php' );
$bootstrap = file_get_contents( $root . '/core-blueprint-crm.php' );
$tools     = file_get_contents( $root . '/tools/check' );

$checks = [
	'Subscriptions adapter is booted' => str_contains( $plugin, 'Subscriptions::init();' ),
	'adapter consumes the public Subscriptions query contract' => str_contains( $adapter, 'CB\\Subscriptions\\Frontend\\Queries' ) && str_contains( $adapter, 'Queries::for_user' ),
	'adapter does not reach into Subscriptions domain internals' => ! str_contains( $adapter, 'CB\\Subscriptions\\Subscription' ) && ! str_contains( $adapter, 'wc_get_orders' ) && ! str_contains( $adapter, 'wc_get_order(' ),
	'adapter resolves CRM identity through the canonical WordPress-user link' => str_contains( $adapter, 'ContactIdentity::linked_user_id' ),
	'adapter is Contact-only and read-only' => str_contains( $adapter, "'post_types' => [ Entity::CONTACT ]" ) && ! str_contains( $adapter, 'update_post_meta' ) && ! str_contains( $adapter, 'wp_insert_post' ),
	'adapter follows the Subscriptions staff authorization boundary' => str_contains( $adapter, "current_user_can( 'manage_woocommerce' )" ) && str_contains( $adapter, "current_user_can( 'manage_options' )" ),
	'adapter does not create parallel subscription storage' => ! str_contains( $adapter, 'CREATE TABLE' ) && ! str_contains( $adapter, 'register_post_meta' ),
	'integration does not change CRM candidate or schema version' => str_contains( $bootstrap, "CB_CRM_VERSION', '1.0.0-rc1'" ) && str_contains( $bootstrap, "CB_CRM_SCHEMA_VERSION', '1.5'" ),
	'permanent subscriptions smoke is wired into tools/check' => str_contains( $tools, 'subscriptions-integration-smoke.php' ),
];

foreach ( $checks as $label => $passed ) {
	if ( ! $passed ) {
		$fail( 'Subscriptions integration conformance failed: ' . $label );
	}
}

exit( $failed ? 1 : 0 );

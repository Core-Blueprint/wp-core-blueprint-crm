<?php
declare(strict_types=1);

/**
 * Disposable-only fixture for WordPress-native CRM recovery operator CLI.
 * Seed and verify phases run after actual Base + CRM activation.
 */
if ( ! defined( 'WP_CLI' ) || ! WP_CLI
	|| ! defined( 'CB_CRM_INTEGRATION_DISPOSABLE' ) || true !== CB_CRM_INTEGRATION_DISPOSABLE
	|| ! defined( 'DB_NAME' ) || ! preg_match( '/^cb_crm_it_[a-z0-9]{8}$/D', DB_NAME )
	|| ! defined( 'DB_HOST' ) || '127.0.0.1:3307' !== DB_HOST
	|| ! defined( 'ABSPATH' ) || ! str_starts_with( ABSPATH, '/tmp/core-blueprint-tests/core-blueprint-crm-sales/run.' )
	|| is_multisite()
) {
	fwrite( STDERR, "CRM recovery oversight integration: non-disposable site refused.\n" );
	exit( 1 );
}
$phase = getenv( 'CB_CRM_OVERSIGHT_PHASE' );
$assert = static function ( bool $ok, string $message ): void {
	if ( ! $ok ) { fwrite( STDERR, 'CRM recovery oversight integration FAIL: ' . $message . "\n" ); exit( 1 ); }
};
$assert( in_array( $phase, [ 'seed', 'verify' ], true ), 'invalid phase' );
$prefix = 'cb_crm_user_anonymize_intent_';
global $wpdb;
if ( 'seed' === $phase ) {
	for ( $id = 700000000; $id < 700000025; ++$id ) {
		$exists = $wpdb->get_var( $wpdb->prepare( "SELECT ID FROM {$wpdb->users} WHERE ID = %d LIMIT 1", $id ) );
		$assert( null === $exists && '' === (string) $wpdb->last_error, 'fixture user ID already exists' );
		$assert( add_option( $prefix . $id, [ 'version' => 1, 'user_id' => $id ], '', false ), 'failed to seed a bounded synthetic intent' );
	}
	$assert( add_option( 'cb_crm_delete_intent_700001000', [ 'version' => 99, 'type' => 'contact', 'id' => 700001000 ], '', false ), 'failed to seed one corrupt intent' );
	echo "PASS: 25 synthetic, non-existent user IDs and one invalid post intent seeded in disposable WordPress\n";
	return;
}
$assert( false === get_option( $prefix . '700000024', false ), 'explicit confirmed operator replay left its intent behind' );
$assert( is_array( get_option( $prefix . '700000023', false ) ), 'single-ID operator replay removed another intent' );
$assert( is_array( get_option( 'cb_crm_delete_intent_700001000', false ) ), 'malformed intent was incorrectly discarded' );
echo "CRM recovery oversight paginated WP-CLI inventory and confirmed retry: PASS\n";

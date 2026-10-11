<?php
declare(strict_types=1);

/**
 * Deterministic two-WP-CLI-process test: deletion wins just before a Sales
 * identity locking read. Run only in a freshly generated CRM integration DB.
 */
$gate_dir = getenv( 'CB_CRM_RACE_GATE_DIR' );
if ( ! defined( 'WP_CLI' ) || ! WP_CLI
	|| ! defined( 'CB_CRM_INTEGRATION_DISPOSABLE' ) || true !== CB_CRM_INTEGRATION_DISPOSABLE
	|| ! defined( 'DB_NAME' ) || ! preg_match( '/^cb_crm_it_[a-z0-9]{8}$/D', DB_NAME )
	|| ! defined( 'DB_HOST' ) || '127.0.0.1:3307' !== DB_HOST
	|| ! defined( 'ABSPATH' ) || ! str_starts_with( ABSPATH, '/tmp/core-blueprint-tests/core-blueprint-crm-sales/run.' )
	|| ! is_string( $gate_dir ) || ! is_dir( $gate_dir )
	|| realpath( $gate_dir ) !== realpath( dirname( rtrim( ABSPATH, '/' ) ) )
) {
	fwrite( STDERR, "CRM concurrency integration refuses a non-disposable environment.\n" );
	exit( 1 );
}

use CB\CRM\Database\Schema;
use CB\CRM\Repository\Sales\Records;

global $wpdb;
$fail = static function ( string $message ): never {
	fwrite( STDERR, 'CRM Sales concurrency integration FAIL: ' . $message . "\n" );
	exit( 1 );
};
$assert = static function ( bool $ok, string $message ) use ( $fail ): void {
	if ( ! $ok ) { $fail( $message ); }
};
$phase = getenv( 'CB_CRM_RACE_PHASE' );
$assert( in_array( $phase, [ 'prepare', 'create', 'delete', 'verify' ], true ), 'unrecognized race phase' );
$assert( '1.6' === (string) get_option( Schema::OPTION, '' ), 'schema 1.6 not ready' );
$post_engine = $wpdb->get_var( $wpdb->prepare(
	'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s',
	DB_NAME,
	$wpdb->posts
) );
$assert( 'InnoDB' === $post_engine, 'wp_posts must support row-level InnoDB locking for this concurrency contract' );
$admin = get_user_by( 'login', 'crm-it-admin' );
$assert( $admin instanceof WP_User, 'integration administrator not found' );
wp_set_current_user( (int) $admin->ID );
$assert( current_user_can( 'cb_manage_crm_sales' ), 'Sales capability unavailable' );
$key = 'cb_crm_concurrency_test_identity';

if ( 'prepare' === $phase ) {
	$assert( false === get_option( $key, false ), 'race fixture is already initialized' );
	$id = wp_insert_post( [
		'post_type' => 'cb_crm_contact',
		'post_status' => 'publish',
		'post_title' => 'Disposable Concurrency Contact',
	], true );
	$assert( is_int( $id ) && $id > 0, 'contact creation failed' );
	$assert( add_option( $key, $id, '', false ), 'race contact ID not stored' );
	echo "PASS: isolated race Contact created\n";
	return;
}
$id = (int) get_option( $key, 0 );
$assert( $id > 0, 'race manifest missing' );

if ( 'create' === $phase ) {
	$pipeline = $wpdb->get_row( $wpdb->prepare(
		'SELECT id FROM ' . Schema::sales_pipelines_table() . ' WHERE pipeline_key = %s',
		'default_leads'
	), ARRAY_A );
	$assert( is_array( $pipeline ) && (int) $pipeline['id'] > 0, 'Lead pipeline missing' );
	$stage = $wpdb->get_row( $wpdb->prepare(
		'SELECT id FROM ' . Schema::sales_stages_table() . ' WHERE pipeline_id = %d AND stage_key = %s',
		(int) $pipeline['id'], 'new'
	), ARRAY_A );
	$assert( is_array( $stage ) && (int) $stage['id'] > 0, 'Lead stage missing' );

	// Prime this process's WP object cache to model a stale pre-delete read.
	$assert( get_post( $id ) instanceof WP_Post, 'contact disappeared before race' );
	$signaled = false;
	add_filter( 'query', static function ( string $sql ) use ( $id, $gate_dir, &$signaled, $fail ): string {
		if ( $signaled || ! str_contains( $sql, 'SELECT post_type, post_status FROM' )
			|| ! str_contains( $sql, 'FOR UPDATE' ) || ! str_contains( $sql, 'WHERE ID = ' . $id ) ) {
			return $sql;
		}
		$signaled = true;
		if ( false === file_put_contents( $gate_dir . '/race_identity_before_lock', 'ready' ) ) {
			$fail( 'unable to signal canonical identity query' );
		}
		$deadline = microtime( true ) + 20;
		while ( ! is_file( $gate_dir . '/race_go' ) && microtime( true ) < $deadline ) {
			usleep( 50000 );
		}
		if ( ! is_file( $gate_dir . '/race_go' ) ) {
			$fail( 'timed out waiting for concurrent WordPress deletion' );
		}
		return $sql;
	}, 10, 1 );

	$result = Records::create_lead(
		[ 'title' => 'Must never reference deleted contact', 'pipeline_id' => (int) $pipeline['id'], 'stage_id' => (int) $stage['id'] ],
		[ [ 'party_type' => 'contact', 'party_id' => $id ] ]
	);
	$assert( $signaled, 'Sales repository did not attempt a canonical identity row lock' );
	$assert(
		$result instanceof WP_Error && 'crm_sales_invalid_identity' === $result->get_error_code(),
		'concurrent deleted identity not rejected by actual Sales repository'
	);
	echo "PASS: concurrent Sales create fails closed after WordPress deletion despite stale WP cache\n";
	return;
}

if ( 'delete' === $phase ) {
	$assert( get_post( $id ) instanceof WP_Post, 'concurrent delete must begin with live Contact' );
	$deleted = wp_delete_post( $id, true );
	$assert( $deleted instanceof WP_Post, 'WordPress permanent deletion refused' );
	$assert( null === $wpdb->get_var( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE ID = %d LIMIT 1", $id ) ), 'WordPress Contact still in table' );
	$assert( false === get_option( 'cb_crm_delete_intent_' . $id, false ), 'Contact deletion left an unacknowledged recovery intent' );
	echo "PASS: independent WordPress request permanently removed Contact\n";
	return;
}

$assert( null === $wpdb->get_var( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE ID = %d LIMIT 1", $id ) ), 'Contact restored unexpectedly' );
$assert( 0 === (int) $wpdb->get_var( $wpdb->prepare(
	'SELECT COUNT(*) FROM ' . Schema::sales_participants_table() . ' WHERE party_type = %s AND party_id = %d',
	'contact', $id
) ), 'orphaned Sales participant survived cross-request deletion race' );
echo "CRM Sales concurrent delete-before-lock integration: PASS\n";

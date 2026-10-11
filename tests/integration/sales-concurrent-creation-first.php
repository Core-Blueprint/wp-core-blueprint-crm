<?php
declare(strict_types=1);

/**
 * Two independent WP-CLI processes, creation-first inverse of deletion-first.
 * Only run through the isolated CRM integration runner with a new test DB.
 */
$root = getenv( 'CB_CRM_RACE_GATE_DIR' );
if ( ! defined( 'WP_CLI' ) || ! WP_CLI
	|| ! defined( 'CB_CRM_INTEGRATION_DISPOSABLE' ) || true !== CB_CRM_INTEGRATION_DISPOSABLE
	|| ! defined( 'DB_NAME' ) || ! preg_match( '/^cb_crm_it_[a-z0-9]{8}$/D', DB_NAME )
	|| ! defined( 'DB_HOST' ) || '127.0.0.1:3307' !== DB_HOST
	|| ! defined( 'ABSPATH' ) || ! str_starts_with( ABSPATH, '/tmp/core-blueprint-tests/core-blueprint-crm-sales/run.' )
	|| ! is_string( $root ) || ! is_dir( $root )
	|| realpath( $root ) !== realpath( dirname( rtrim( ABSPATH, '/' ) ) )
) {
	fwrite( STDERR, "CRM creation-first race: refusing non-disposable WordPress environment.\n" );
	exit( 1 );
}

use CB\CRM\Database\Schema;
use CB\CRM\Repository\Sales\Records;

global $wpdb;
$fail = static function ( string $message ): never {
	fwrite( STDERR, 'CRM creation-first concurrency FAIL: ' . $message . "\n" );
	exit( 1 );
};
$assert = static function ( bool $value, string $message ) use ( $fail ): void {
	if ( ! $value ) { $fail( $message ); }
};
$phase = getenv( 'CB_CRM_RACE_PHASE' );
$assert( in_array( $phase, [ 'prepare', 'create', 'delete', 'verify' ], true ), 'invalid race phase' );
$assert( '1.6' === (string) get_option( Schema::OPTION, '' ), 'Sales schema 1.6 not committed' );
$engine = $wpdb->get_var( $wpdb->prepare(
	'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s',
	DB_NAME, $wpdb->posts
) );
$assert( 'InnoDB' === $engine, 'canonical WordPress posts storage must be InnoDB' );
$actor = get_user_by( 'login', 'crm-it-admin' );
$assert( $actor instanceof WP_User, 'test actor missing' );
wp_set_current_user( (int) $actor->ID );
$assert( current_user_can( 'cb_manage_crm_sales' ), 'Sales privilege unavailable' );

$identity_key = 'cb_crm_creation_first_test_identity';
$record_key = 'cb_crm_creation_first_test_lead_id';

if ( 'prepare' === $phase ) {
	$assert( false === get_option( $identity_key, false ), 'fixture cannot be reused' );
	$id = wp_insert_post( [
		'post_type' => 'cb_crm_contact',
		'post_status' => 'publish',
		'post_title' => 'Disposable Creation-first Contact',
	], true );
	$assert( is_int( $id ) && $id > 0, 'could not create Contact' );
	$assert( add_option( $identity_key, $id, '', false ), 'could not persist race Contact id' );
	echo "PASS: creation-first Contact seeded\n";
	return;
}
$id = (int) get_option( $identity_key, 0 );
$assert( $id > 0, 'missing test Contact manifest' );

if ( 'create' === $phase ) {
	$pipeline = $wpdb->get_row( $wpdb->prepare(
		'SELECT id FROM ' . Schema::sales_pipelines_table() . ' WHERE pipeline_key = %s',
		'default_leads'
	), ARRAY_A );
	$assert( is_array( $pipeline ) && (int) $pipeline['id'] > 0, 'default Lead pipeline missing' );
	$stage = $wpdb->get_row( $wpdb->prepare(
		'SELECT id FROM ' . Schema::sales_stages_table() . ' WHERE pipeline_id = %d AND stage_key = %s',
		(int) $pipeline['id'], 'new'
	), ARRAY_A );
	$assert( is_array( $stage ) && (int) $stage['id'] > 0, 'default Lead stage missing' );

	$at_lead_insert = false;
	add_filter( 'query', static function ( string $sql ) use ( $root, $wpdb, &$at_lead_insert, $fail ): string {
		// Records locks the WP post BEFORE its INSERT. A query filter here
		// synchronizes at the last safe location before the Sales write.
		if ( $at_lead_insert || ! str_contains( strtoupper( $sql ), 'INSERT INTO' )
			|| ! str_contains( $sql, Schema::sales_leads_table() ) ) {
			return $sql;
		}
		$at_lead_insert = true;
		if ( false === file_put_contents( $root . '/creation_first_identity_locked', 'ready' ) ) {
			$fail( 'unable to signal post-lock pause' );
		}
		$deadline = microtime( true ) + 25;
		while ( ! is_file( $root . '/creation_first_go' ) && microtime( true ) < $deadline ) {
			usleep( 50000 );
		}
		if ( ! is_file( $root . '/creation_first_go' ) ) {
			$fail( 'timed out waiting for deletion to begin' );
		}
		return $sql;
	}, 10, 1 );

	$lead_id = Records::create_lead(
		[
			'title' => 'Committed before concurrent Contact deletion',
			'pipeline_id' => (int) $pipeline['id'],
			'stage_id' => (int) $stage['id'],
		],
		[ [ 'party_type' => 'contact', 'party_id' => $id ] ]
	);
	$assert( $at_lead_insert, 'Sales did not reach the post-lock point' );
	$assert( is_int( $lead_id ) && $lead_id > 0, 'Sales transaction must complete before deletion' );
	$assert( add_option( $record_key, $lead_id, '', false ), 'Lead manifest not recorded' );
	echo "PASS: canonical identity row remained locked through Sales commit\n";
	return;
}
if ( 'delete' === $phase ) {
	$signaled = false;
	add_filter( 'pre_delete_post', static function ( mixed $previous, WP_Post $post ) use ( $id, $root, &$signaled, $fail ): mixed {
		if ( null !== $previous || (int) $post->ID !== $id ) { return $previous; }
		$signaled = true;
		if ( false === file_put_contents( $root . '/creation_first_delete_entered', 'ready' ) ) {
			$fail( 'could not signal WordPress deletion attempt' );
		}
		return null;
	}, 5, 2 );
	$deleted = wp_delete_post( $id, true );
	$assert( $signaled, 'WordPress did not enter its permanent deletion hook' );
	$assert( $deleted instanceof WP_Post, 'permanent Contact deletion failed' );
	$assert( null === $wpdb->get_var( $wpdb->prepare(
		"SELECT ID FROM {$wpdb->posts} WHERE ID = %d LIMIT 1", $id
	) ), 'WordPress Contact unexpectedly remains' );
	$assert( false === get_option( 'cb_crm_delete_intent_' . $id, false ), 'cleanup recovery intent unexpectedly pending' );
	echo "PASS: WordPress deletion completed after locked Sales transaction committed\n";
	return;
}

$lead_id = (int) get_option( $record_key, 0 );
$assert( $lead_id > 0, 'committed Lead ID missing' );
$assert( null === $wpdb->get_var( $wpdb->prepare(
	"SELECT ID FROM {$wpdb->posts} WHERE ID = %d LIMIT 1", $id
) ), 'Contact unexpectedly exists after both transactions' );
$assert( 0 === (int) $wpdb->get_var( $wpdb->prepare(
	'SELECT COUNT(*) FROM ' . Schema::sales_participants_table() . ' WHERE party_type = %s AND party_id = %d',
	'contact', $id
) ), 'orphaned Sales participant after Contact deletion' );
$assert( 0 === (int) $wpdb->get_var( $wpdb->prepare(
	'SELECT COUNT(*) FROM ' . Schema::sales_leads_table() . ' WHERE id = %d',
	$lead_id
) ), 'Lead with zero participants survived Contact deletion' );
$assert( 0 === (int) $wpdb->get_var( $wpdb->prepare(
	'SELECT COUNT(*) FROM ' . Schema::sales_events_table() . ' WHERE entity_type = %s AND entity_id = %d',
	'lead', $lead_id
) ), 'orphaned Sales history after cleanup' );
$assert( false === get_option( 'cb_crm_delete_intent_' . $id, false ), 'unexpected pending cleanup recovery' );
echo "CRM Sales creation-first then delete concurrency: PASS\n";

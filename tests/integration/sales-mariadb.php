<?php
declare(strict_types=1);

/**
 * Execute ONLY via WP-CLI eval-file in the disposable database created by
 * tools/check-sales-integration. Never run against production or staging.
 */
if ( ! defined( 'WP_CLI' ) || ! WP_CLI || ! defined( 'CB_CRM_INTEGRATION_DISPOSABLE' ) ) {
	fwrite( STDERR, "CRM Sales integration requires its isolated test runner.\n" );
	exit( 1 );
}

use CB\CRM\Application\Sales\DefaultPipelines;
use CB\CRM\Content\PostTypes;
use CB\CRM\Database\Schema;
use CB\CRM\Repository\LifecycleRecovery;
use CB\CRM\Repository\Sales\Records;

global $wpdb;
$fail = static function ( string $message ): never {
	fwrite( STDERR, "CRM Sales MariaDB integration FAIL: {$message}\n" );
	exit( 1 );
};
$assert = static function ( bool $pass, string $message ) use ( $fail ): void {
	if ( ! $pass ) {
		$fail( $message );
	}
};

$assert( str_starts_with( DB_NAME, 'cb_crm_it_' ), 'unexpected database: refusing integration writes' );
$assert( str_contains( (string) DB_HOST, '127.0.0.1:3307' ), 'not using the dedicated loopback MariaDB port' );
$assert( defined( 'CB_CRM_SCHEMA_VERSION' ) && '1.6' === CB_CRM_SCHEMA_VERSION, 'CRM source must be schema 1.6' );
$assert( '1.6' === (string) get_option( Schema::OPTION ), 'Base schema migration did not complete' );
$assert( function_exists( 'cb_crm_runtime_ready' ) && cb_crm_runtime_ready(), 'Base/CRM product runtime not ready' );

$admin = get_user_by( 'login', 'crm-it-admin' );
$assert( $admin instanceof WP_User, 'disposable WordPress administrator missing' );
wp_set_current_user( $admin->ID );
$assert( current_user_can( 'cb_manage_crm_sales' ), 'versioned Sales administrator grant missing' );

$sales_tables = [
	Schema::sales_pipelines_table(),
	Schema::sales_stages_table(),
	Schema::sales_leads_table(),
	Schema::sales_opportunities_table(),
	Schema::sales_participants_table(),
	Schema::sales_events_table(),
];
foreach ( $sales_tables as $table ) {
	$engine = $wpdb->get_var( $wpdb->prepare(
		'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s',
		DB_NAME,
		$table
	) );
	$assert( 'InnoDB' === $engine, "missing/non-InnoDB Sales table: {$table}" );
}
echo "PASS: six real MariaDB/InnoDB Sales tables and schema marker 1.6\n";

// Seed installation and reinstallation must preserve existing custom labels.
$assert( '1' === (string) get_option( DefaultPipelines::OPTION ), 'Sales default pipeline installation marker missing' );
$pipelines = Schema::sales_pipelines_table();
$stages = Schema::sales_stages_table();
$lead_pipeline = $wpdb->get_row(
	$wpdb->prepare( "SELECT id FROM {$pipelines} WHERE pipeline_key = %s", 'default_leads' ),
	ARRAY_A
);
$assert( is_array( $lead_pipeline ), 'Lead pipeline not installed' );
$pipeline_id = (int) $lead_pipeline['id'];
$stage = $wpdb->get_row(
	$wpdb->prepare( "SELECT id FROM {$stages} WHERE pipeline_id = %d AND stage_key = %s", $pipeline_id, 'new' ),
	ARRAY_A
);
$assert( is_array( $stage ), 'default Lead stage missing' );
$stage_id = (int) $stage['id'];
$assert( false !== $wpdb->update( $stages, [ 'name' => 'Custom qualification label' ], [ 'id' => $stage_id ], [ '%s' ], [ '%d' ] ), 'could not customize test stage' );
delete_option( DefaultPipelines::OPTION );
$assert( DefaultPipelines::maybe_install(), 'reseed after interrupted marker must succeed' );
$assert(
	'Custom qualification label' === $wpdb->get_var( $wpdb->prepare( "SELECT name FROM {$stages} WHERE id = %d", $stage_id ) ),
	'seed retry overwrote an existing user label'
);
$assert( 2 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$pipelines}" ), 'duplicate default pipeline introduced' );
echo "PASS: default pipeline seed retry preserves configured values\n";

// A rolled-back transaction must leave no Sales row.
$key = 'it_rollback_' . bin2hex( random_bytes( 5 ) );
$now = current_time( 'mysql', true );
$assert( false !== $wpdb->query( 'START TRANSACTION' ), 'could not start real InnoDB transaction' );
$inserted = $wpdb->insert( $pipelines, [
	'pipeline_key' => $key,
	'entity_type' => 'lead',
	'name' => 'Rolled-back fixture',
	'is_active' => 1,
	'sort_order' => 999,
	'created_at' => $now,
	'updated_at' => $now,
], [ '%s', '%s', '%s', '%d', '%d', '%s', '%s' ] );
$assert( false !== $inserted, 'fixture insert within transaction failed' );
$assert( false !== $wpdb->query( 'ROLLBACK' ), 'InnoDB rollback failed' );
$assert(
	0 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$pipelines} WHERE pipeline_key = %s", $key ) ),
	'InnoDB ROLLBACK did not remove the inserted pipeline'
);
echo "PASS: real InnoDB transaction rollback\n";

// A unique-key collision must reject a second row without changing the first.
$existing_key = 'default_leads';
$wpdb->suppress_errors( true );
$duplicate = $wpdb->insert( $pipelines, [
	'pipeline_key' => $existing_key,
	'entity_type' => 'lead',
	'name' => 'Duplicate',
	'is_active' => 1,
	'sort_order' => 999,
	'created_at' => $now,
	'updated_at' => $now,
], [ '%s', '%s', '%s', '%d', '%d', '%s', '%s' ] );
$wpdb->suppress_errors( false );
$assert( false === $duplicate, 'pipeline unique key allowed duplicate' );
$assert( 2 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$pipelines}" ), 'unique-key test modified pipeline count' );
echo "PASS: real MariaDB unique-key constraint\n";

// WordPress post status changes must not clean up Sales participants.
$contact_id = wp_insert_post( [
	'post_type' => PostTypes::CONTACT,
	'post_status' => 'publish',
	'post_title' => 'Test Contact - no real personal data',
], true );
$assert( is_int( $contact_id ) && $contact_id > 0, 'could not create CRM Contact' );
$lead_id = Records::create_lead(
	[ 'title' => 'Disposable integration Lead', 'pipeline_id' => $pipeline_id, 'stage_id' => $stage_id ],
	[ [ 'party_type' => 'contact', 'party_id' => $contact_id ] ]
);
$assert( is_int( $lead_id ) && $lead_id > 0, 'could not create Sale Lead via real repository' );
$participant_table = Schema::sales_participants_table();
$event_table = Schema::sales_events_table();
$assert( wp_trash_post( $contact_id ) instanceof WP_Post, 'WordPress could not trash CRM Contact' );
$assert( 'trash' === get_post_status( $contact_id ), 'CRM Contact was not trashed' );
$assert( 1 === (int) $wpdb->get_var( $wpdb->prepare(
	"SELECT COUNT(*) FROM {$participant_table} WHERE entity_type = %s AND entity_id = %d",
	'lead',
	$lead_id
) ), 'trashing Contact removed Sales participant' );
$assert( false === get_option( 'cb_crm_delete_intent_' . $contact_id, false ), 'trashing created an irreversible delete intent' );
$assert( wp_untrash_post( $contact_id ) instanceof WP_Post, 'could not restore Contact after Trash test' );
echo "PASS: WordPress Trash leaves Sales identity relations untouched\n";

// Simulate a real InnoDB failure inside CRM post-deletion cleanup.
// The WordPress Contact must remain deleted, with a durable recovery intent.
// The trigger is created only in the randomly named disposable test database.
$trigger = 'cb_crm_it_block_event_delete';
$created_trigger = false;
try {
	$wpdb->query( "CREATE TRIGGER {$trigger} BEFORE DELETE ON {$event_table} FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'integration test rollback'" );
	$assert( '' === (string) $wpdb->last_error, 'could not install isolated fault-injection trigger' );
	$created_trigger = true;
	$wpdb->suppress_errors( true );
	$deleted = wp_delete_post( $contact_id, true );
	$wpdb->suppress_errors( false );
	$assert( $deleted instanceof WP_Post, 'WordPress permanent deletion was unexpectedly vetoed' );
	$assert( null === $wpdb->get_var( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE ID = %d", $contact_id ) ), 'WordPress Contact survived permanent deletion' );
	$assert( false !== get_option( 'cb_crm_delete_intent_' . $contact_id, false ), 'failed cleanup lost durable recovery intent' );
	$assert( 1 === (int) $wpdb->get_var( $wpdb->prepare(
		"SELECT COUNT(*) FROM {$participant_table} WHERE entity_type = %s AND entity_id = %d",
		'lead', $lead_id
	) ), 'failed InnoDB cleanup did not roll back participant deletion' );
} finally {
	$wpdb->suppress_errors( false );
	if ( $created_trigger ) {
		$wpdb->query( "DROP TRIGGER IF EXISTS {$trigger}" );
	}
}
$assert( LifecycleRecovery::retry( $contact_id ), 'durable retry after InnoDB failure did not complete' );
$assert( false === get_option( 'cb_crm_delete_intent_' . $contact_id, false ), 'recovery did not acknowledge intent' );
$assert( 0 === (int) $wpdb->get_var( $wpdb->prepare(
	"SELECT COUNT(*) FROM {$participant_table} WHERE entity_type = %s AND entity_id = %d",
	'lead', $lead_id
) ), 'retry did not delete orphaned participant' );
$assert( 0 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$event_table} WHERE entity_type = %s AND entity_id = %d", 'lead', $lead_id ) ), 'retry retained orphaned Sales event' );
$assert( 0 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM " . Schema::sales_leads_table() . " WHERE id = %d", $lead_id ) ), 'retry retained orphaned Sales Lead' );
$assert( LifecycleRecovery::retry( $contact_id ), 'second retry is not idempotent' );
echo "PASS: genuine WordPress deleted_post + InnoDB failure + durable recovery + idempotency\n";
echo "CRM Sales MariaDB integration: PASS\n";

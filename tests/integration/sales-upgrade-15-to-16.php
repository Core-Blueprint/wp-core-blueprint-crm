<?php
declare(strict_types=1);

/**
 * Two-phase REAL database test, solely for disposable CRM schema 1.5 -> 1.6.
 * Invoked via WP-CLI "wp eval require..." after runner-specific guard setup.
 */
if ( ! defined( 'WP_CLI' ) || ! WP_CLI
	|| ! defined( 'CB_CRM_INTEGRATION_DISPOSABLE' ) || true !== CB_CRM_INTEGRATION_DISPOSABLE
	|| ! defined( 'DB_NAME' ) || ! preg_match( '/^cb_crm_up_[a-z0-9]{8}$/D', DB_NAME )
	|| ! defined( 'DB_HOST' ) || '127.0.0.1:3307' !== DB_HOST
	|| ! defined( 'ABSPATH' ) || ! str_starts_with( ABSPATH, '/tmp/core-blueprint-tests/core-blueprint-crm-sales-upgrade/run.' )
) {
	fwrite( STDERR, "CRM Sales schema upgrade: refusing non-disposable WordPress environment.\n" );
	exit( 1 );
}

use CB\CRM\Database\Schema;

global $wpdb;

$fail = static function ( string $message ): never {
	fwrite( STDERR, 'CRM Sales schema upgrade FAIL: ' . $message . "\n" );
	exit( 1 );
};
$assert = static function ( bool $condition, string $message ) use ( $fail ): void {
	if ( ! $condition ) {
		$fail( $message );
	}
};

$phase = getenv( 'CB_CRM_UPGRADE_PHASE' );
$assert( in_array( $phase, [ 'seed', 'lock', 'blocked', 'unlock', 'verify' ], true ), 'unknown upgrade phase' );
$expected_schema = 'verify' === $phase ? '1.6' : '1.5';
$assert( defined( 'CB_CRM_SCHEMA_VERSION' ) && CB_CRM_SCHEMA_VERSION === $expected_schema, 'wrong CRM source schema for phase' );
$assert( (string) get_option( Schema::OPTION, '' ) === $expected_schema, 'Base schema registry has not committed expected schema' );
$assert( function_exists( 'cb_crm_runtime_ready' ) && cb_crm_runtime_ready(), 'CRM runtime not ready' );

$admin = get_user_by( 'login', 'crm-it-admin' );
$assert( $admin instanceof WP_User, 'test administrator unavailable' );
wp_set_current_user( (int) $admin->ID );
$assert( current_user_can( 'cb_manage_crm' ), 'test administrator has no effective CRM permission' );

$legacy = [
	'methods' => Schema::contact_methods_table(),
	'addresses' => Schema::addresses_table(),
	'names' => Schema::names_table(),
	'identifiers' => Schema::business_identifiers_table(),
	'relations' => Schema::organization_relations_table(),
	'agreements' => Schema::service_agreements_table(),
	'documents' => Schema::document_links_table(),
	'notes' => Schema::notes_table(),
	'activities' => Schema::activities_table(),
];
$snapshot = static function () use ( $wpdb, $legacy, $assert ): array {
	$hashes = [];
	foreach ( $legacy as $kind => $table ) {
		$wpdb->last_error = '';
		$rows = $wpdb->get_results( 'SELECT * FROM ' . $table . ' ORDER BY id ASC', ARRAY_A );
		$assert( is_array( $rows ) && '' === (string) $wpdb->last_error, 'cannot read legacy table ' . $kind );
		$hashes[ $kind ] = hash( 'sha256', wp_json_encode( $rows ) );
	}
	return $hashes;
};

$manifest_option = 'cb_crm_sales_upgrade_fixture';
$lock_key = 'cb_core_schema_lock_' . md5( 'core-blueprint-crm' );
$lock_owner = 'crm-upgrade-test-' . DB_NAME;

if ( 'lock' === $phase ) {
	$assert( is_array( get_option( $manifest_option, false ) ), 'legacy seed missing before lock injection' );
	$assert( false === get_option( $lock_key, false ), 'refusing to replace another migration lock' );
	$assert(
		add_option( $lock_key, [ 'owner' => $lock_owner, 'acquired_at' => time() ], '', false ),
		'could not hold isolated Base schema lock'
	);
	echo "PASS: isolated Base migration lock held before CRM 1.6 bootstrap\n";
	return;
}
if ( 'seed' === $phase ) {
	$assert( false === get_option( $manifest_option, false ), 'fixture already present, will not overwrite' );
	$assert( '1' === (string) get_option( 'cb_crm_install_version', '' ), 'schema 1.5 should have role policy v1' );
	$assert( ! get_role( 'administrator' )->has_cap( 'cb_manage_crm_sales' ), 'Sales management unexpectedly present in 1.5' );

	$contact = wp_insert_post( [
		'post_type' => 'cb_crm_contact',
		'post_status' => 'publish',
		'post_title' => 'Legacy CRM Contact (synthetic)',
	], true );
	$organization = wp_insert_post( [
		'post_type' => 'cb_crm_org',
		'post_status' => 'publish',
		'post_title' => 'Legacy CRM Organization (synthetic)',
	], true );
	$document = wp_insert_post( [
		'post_type' => 'post',
		'post_status' => 'publish',
		'post_title' => 'Legacy document reference (synthetic)',
	], true );
	$assert( is_int( $contact ) && $contact > 0, 'could not create legacy Contact' );
	$assert( is_int( $organization ) && $organization > 0, 'could not create legacy Organization' );
	$assert( is_int( $document ) && $document > 0, 'could not create legacy Document' );
	$now = current_time( 'mysql', true );
	$data = [
		'methods' => [ 'owner_type' => 'contact', 'owner_id' => $contact, 'method_type' => 'email', 'value' => 'synthetic@example.invalid', 'created_at' => $now, 'updated_at' => $now ],
		'addresses' => [ 'owner_type' => 'organization', 'owner_id' => $organization, 'address_line_1' => '1 Synthetic Road', 'city' => 'Testville', 'country' => 'NL', 'created_at' => $now, 'updated_at' => $now ],
		'names' => [ 'owner_type' => 'contact', 'owner_id' => $contact, 'name' => 'Synthetic Contact Alias', 'created_at' => $now, 'updated_at' => $now ],
		'identifiers' => [ 'organization_id' => $organization, 'identifier_type' => 'internal', 'value' => 'ONLY-TEST-001', 'created_at' => $now, 'updated_at' => $now ],
		'relations' => [ 'contact_id' => $contact, 'organization_id' => $organization, 'role_title' => 'Primary synthetic relationship', 'created_at' => $now, 'updated_at' => $now ],
		'agreements' => [ 'work_service_id' => 70001, 'customer_type' => 'organization', 'customer_id' => $organization, 'status' => 'active', 'notes' => 'Synthetic legacy agreement, no real Work service', 'created_at' => $now, 'updated_at' => $now ],
		'documents' => [ 'owner_type' => 'contact', 'owner_id' => $contact, 'document_id' => $document, 'relation_type' => 'test_fixture', 'created_at' => $now ],
		'notes' => [ 'owner_type' => 'organization', 'owner_id' => $organization, 'author_user_id' => $admin->ID, 'body' => 'Synthetic legacy note', 'created_at' => $now, 'updated_at' => $now ],
		'activities' => [ 'owner_type' => 'contact', 'owner_id' => $contact, 'event_type' => 'synthetic_created', 'summary' => 'Synthetic legacy activity', 'created_at' => $now ],
	];
	foreach ( $data as $kind => $row ) {
		$wpdb->last_error = '';
		$assert( false !== $wpdb->insert( $legacy[ $kind ], $row ), 'legacy fixture insert failed for ' . $kind . ': ' . (string) $wpdb->last_error );
	}
	$manifest = [
		'version' => 1,
		'contact_id' => $contact,
		'organization_id' => $organization,
		'document_id' => $document,
		'legacy_hashes' => $snapshot(),
	];
	$assert( add_option( $manifest_option, $manifest, '', false ), 'unable to persist seed hashes' );
	echo "PASS: v1.5 WordPress CRM identity, 9 relational table fixtures and role policy v1 seeded\n";
	return;
}

$manifest = get_option( $manifest_option, false );
$assert( is_array( $manifest ) && 1 === ( $manifest['version'] ?? null ), 'versioned legacy fixture manifest missing' );
$assert( 9 === count( $manifest['legacy_hashes'] ?? [] ), 'unexpected legacy fixture table count' );
$assert( hash_equals(
	hash( 'sha256', wp_json_encode( $manifest['legacy_hashes'] ) ),
	hash( 'sha256', wp_json_encode( $snapshot() ) )
), 'one or more v1.5 CRM relational tables changed during migration' );
$assert( get_post_type( (int) $manifest['contact_id'] ) === 'cb_crm_contact', 'legacy Contact missing after upgrade' );
$assert( get_post_type( (int) $manifest['organization_id'] ) === 'cb_crm_org', 'legacy Organization missing after upgrade' );
$assert( get_post_type( (int) $manifest['document_id'] ) === 'post', 'legacy document missing after upgrade' );
$assert( get_post_status( (int) $manifest['contact_id'] ) === 'publish', 'legacy Contact status changed' );
$assert( get_post_status( (int) $manifest['organization_id'] ) === 'publish', 'legacy Organization status changed' );
echo "PASS: all nine v1.5 CRM relational tables and three WordPress records preserved\n";

// A held Base schema lock must prevent marker advancement and ALL Sales writes.
// This checks the real Base lock, not an artificially edited version option.
if ( 'blocked' === $phase ) {
	$assert( '1.5' === (string) get_option( Schema::OPTION, '' ), 'failed migration prematurely committed 1.6' );
	$held = get_option( $lock_key, false );
	$assert( is_array( $held ) && $lock_owner === ( $held['owner'] ?? '' ), 'expected owned migration lock absent' );
	$assert( '1' !== (string) get_option( 'cb_crm_sales_defaults_version', '' ), 'Sales defaults seeded before schema was ready' );
	$found_sales = $wpdb->get_var(
		$wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $wpdb->prefix . 'cb_crm_sales_pipelines' ) )
	);
	$assert( null === $found_sales, 'Sales DDL was applied despite an active Base migration lock' );
	$attempt = \CB\CRM\Repository\Sales\Records::create_lead(
		[ 'title' => 'Must remain unavailable', 'pipeline_id' => 1, 'stage_id' => 1 ],
		[]
	);
	$assert( $attempt instanceof WP_Error, 'Sales write allowed before schema reconciliation' );
	echo "PASS: held migration lock keeps marker at 1.5 and Sales writes fail closed\n";
	return;
}
if ( 'unlock' === $phase ) {
	$assert( '1.5' === (string) get_option( Schema::OPTION, '' ), 'unexpected migration before lock release' );
	$held = get_option( $lock_key, false );
	$assert( is_array( $held ) && $lock_owner === ( $held['owner'] ?? '' ), 'refusing to delete an unowned lock' );
	$assert( delete_option( $lock_key ), 'could not release owned Base migration lock' );
	echo "PASS: only isolated owned migration lock released for retry\n";
	return;
}

$assert( '2' === (string) get_option( 'cb_crm_install_version', '' ), 'CRM role policy was not migrated from v1 to v2' );
foreach ( [ 'cb_manage_crm', 'cb_view_crm_sales', 'cb_manage_crm_sales' ] as $cap ) {
	$assert( get_role( 'administrator' )->has_cap( $cap ), 'administrator role lacks migrated cap ' . $cap );
	$assert( current_user_can( $cap ), 'admin effective capability denied after upgrade: ' . $cap );
}
$assert( \CoreBlueprint\Core\Permissions\PrivilegedAccessRegistry::is_approved( get_userdata( $admin->ID ) ), 'Base privileged identity approval lost during Sales migration' );
echo "PASS: versioned role policy migration preserves signed Base trust approval\n";

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
		DB_NAME, $table
	) );
	$assert( 'InnoDB' === $engine, 'missing or non-InnoDB new Sales table ' . $table );
}
$assert( '1' === (string) get_option( 'cb_crm_sales_defaults_version', '' ), 'default pipeline marker missing after upgrade' );
$assert( 2 === (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Schema::sales_pipelines_table() ), 'duplicate or missing Sales pipelines' );
$assert( 7 === (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Schema::sales_stages_table() ), 'duplicate or missing Sales stages' );
echo "PASS: schema 1.6 and six new InnoDB Sales tables, exactly 2 pipelines and 7 stages\n";
echo "CRM Sales real 1.5-to-1.6 upgrade integration: PASS\n";

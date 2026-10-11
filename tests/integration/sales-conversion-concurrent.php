<?php
declare(strict_types=1);

/**
 * B2 two-process conversion contender, only from the isolated integration runner.
 * Phases: prepare, convert, verify. The test database is always disposable.
 */
if ( ! defined( 'WP_CLI' ) || ! WP_CLI
	|| ! defined( 'CB_CRM_INTEGRATION_DISPOSABLE' ) || ! CB_CRM_INTEGRATION_DISPOSABLE
	|| ! str_starts_with( (string) DB_NAME, 'cb_crm_it_' )
	|| ! str_contains( (string) DB_HOST, '127.0.0.1:3307' ) ) {
	fwrite( STDERR, "B2 concurrent fixture refuses a non-disposable database.\n" );
	exit( 1 );
}

use CB\CRM\Content\PostTypes;
use CB\CRM\Database\Schema;
use CB\CRM\Repository\Sales\Conversion;
use CB\CRM\Repository\Sales\Records;
use CB\CRM\Repository\Sales\Transitions;

global $wpdb;
$assert = static function ( bool $ok, string $message ): void {
	if ( ! $ok ) { fwrite( STDERR, "CRM B2 concurrent FAIL: {$message}\n" ); exit( 1 ); }
};
$admin = get_user_by( 'login', 'crm-it-admin' );
$assert( $admin instanceof WP_User, 'approved operator unavailable' );
wp_set_current_user( $admin->ID );
$assert( current_user_can( 'cb_manage_crm_sales' ), 'Sales capability unavailable' );
$phase = getenv( 'CB_CRM_B2_PHASE' );
$key = 'cb_crm_b2_race_fixture';

if ( 'prepare' === $phase ) {
	$contact = wp_insert_post( [
		'post_type' => PostTypes::CONTACT,
		'post_status' => 'publish',
		'post_title' => 'Synthetic B2 concurrency Contact',
	], true );
	$assert( is_int( $contact ) && $contact > 0, 'could not create synthetic Contact' );
	$pipelines = Schema::sales_pipelines_table();
	$stages = Schema::sales_stages_table();
	$lead_pipeline = (int) $wpdb->get_var( $wpdb->prepare(
		"SELECT id FROM {$pipelines} WHERE pipeline_key = %s", 'default_leads'
	) );
	$opp_pipeline = (int) $wpdb->get_var( $wpdb->prepare(
		"SELECT id FROM {$pipelines} WHERE pipeline_key = %s", 'default_opportunities'
	) );
	$lead_stage = (int) $wpdb->get_var( $wpdb->prepare(
		"SELECT id FROM {$stages} WHERE pipeline_id = %d AND stage_key = %s", $lead_pipeline, 'new'
	) );
	$opp_stage = (int) $wpdb->get_var( $wpdb->prepare(
		"SELECT id FROM {$stages} WHERE pipeline_id = %d AND stage_key = %s", $opp_pipeline, 'discovery'
	) );
	$assert( $lead_stage > 0 && $opp_stage > 0, 'default stage unavailable' );
	$lead = Records::create_lead(
		[ 'title' => 'Synthetic B2 concurrent conversion', 'pipeline_id' => $lead_pipeline, 'stage_id' => $lead_stage ],
		[ [ 'party_type' => 'contact', 'party_id' => $contact ] ]
	);
	$assert( is_int( $lead ) && $lead > 0, 'could not create concurrency Lead' );
	$assert( 2 === Transitions::set_lead_status( $lead, 'qualified', 1 ), 'could not qualify concurrency Lead' );
	$assert( update_option( $key, [
		'lead' => $lead,
		'revision' => 2,
		'pipeline' => $opp_pipeline,
		'stage' => $opp_stage,
	], false ), 'could not register disposable race fixture' );
	echo "PASS: prepared concurrent B2 Lead #{$lead}\n";
	return;
}

$fixture = get_option( $key, null );
$assert( is_array( $fixture ) && isset( $fixture['lead'], $fixture['revision'], $fixture['pipeline'], $fixture['stage'] ), 'race fixture unavailable' );
$lead = (int) $fixture['lead'];
if ( 'convert' === $phase ) {
	$gate = (string) getenv( 'CB_CRM_B2_GATE_DIR' );
	$worker = (string) getenv( 'CB_CRM_B2_WORKER' );
	$assert( 1 === preg_match( '#^/tmp/core-blueprint-tests/core-blueprint-crm-sales/run\.[A-Za-z0-9]{8}$#D', $gate ), 'unsafe gate directory' );
	$assert( in_array( $worker, [ '1', '2' ], true ), 'invalid worker' );
	$assert( 0 !== file_put_contents( "{$gate}/b2_ready_{$worker}", 'ready' ), 'cannot signal readiness' );
	$released = false;
	for ( $attempt = 0; $attempt < 300; ++$attempt ) {
		if ( is_file( "{$gate}/b2_go" ) ) { $released = true; break; }
		usleep( 100000 );
	}
	$assert( $released, 'simultaneous conversion gate timed out' );
	$id = Conversion::convert(
		$lead,
		(int) $fixture['revision'],
		(int) $fixture['pipeline'],
		(int) $fixture['stage']
	);
	$assert( is_int( $id ) && $id > 0, 'concurrent conversion returned error ' . ( $id instanceof WP_Error ? $id->get_error_code() : '' ) );
	echo "CONVERTED_ID={$id}\n";
	return;
}
if ( 'verify' === $phase ) {
	$leads = Schema::sales_leads_table();
	$deals = Schema::sales_opportunities_table();
	$events = Schema::sales_events_table();
	$links = Schema::sales_participants_table();
	$lead_row = $wpdb->get_row( $wpdb->prepare(
		"SELECT status, converted_opportunity_id, revision FROM {$leads} WHERE id = %d", $lead
	), ARRAY_A );
	$assert( is_array( $lead_row ) && 'converted' === $lead_row['status']
		&& 3 === (int) $lead_row['revision'] && (int) $lead_row['converted_opportunity_id'] > 0,
		'concurrent Lead changed more than once' );
	$id = (int) $lead_row['converted_opportunity_id'];
	$assert( 1 === (int) $wpdb->get_var( $wpdb->prepare(
		"SELECT COUNT(*) FROM {$deals} WHERE source_lead_id = %d", $lead
	) ), 'two concurrent processes created duplicate Opportunity' );
	$assert( $id === (int) $wpdb->get_var( $wpdb->prepare(
		"SELECT id FROM {$deals} WHERE source_lead_id = %d", $lead
	) ), 'reciprocal conversion link broken' );
	$assert( 1 === (int) $wpdb->get_var( $wpdb->prepare(
		"SELECT COUNT(*) FROM {$events} WHERE entity_type = %s AND entity_id = %d AND event_type = %s",
		'lead', $lead, 'converted'
	) ), 'concurrent processes created duplicate conversion events' );
	$assert( 1 === (int) $wpdb->get_var( $wpdb->prepare(
		"SELECT COUNT(*) FROM {$events} WHERE entity_type = %s AND entity_id = %d AND event_type = %s",
		'opportunity', $id, 'created'
	) ), 'Opportunity creation event count is wrong' );
	$assert( 1 === (int) $wpdb->get_var( $wpdb->prepare(
		"SELECT COUNT(*) FROM {$links} WHERE entity_type = %s AND entity_id = %d",
		'opportunity', $id
	) ), 'concurrent processes duplicated participants' );
	echo "PASS: two WordPress processes produced exactly one linked Opportunity #{$id} and one conversion event\n";
	return;
}
$assert( false, 'unknown B2 concurrent phase' );

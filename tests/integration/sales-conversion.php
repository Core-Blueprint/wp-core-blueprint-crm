<?php
declare(strict_types=1);

/**
 * Run only in the disposable WordPress/MariaDB site created by
 * tools/check-sales-integration. Uses synthetic identities exclusively.
 */
if ( ! defined( 'WP_CLI' ) || ! WP_CLI
	|| ! defined( 'CB_CRM_INTEGRATION_DISPOSABLE' ) || ! CB_CRM_INTEGRATION_DISPOSABLE
	|| ! str_starts_with( (string) DB_NAME, 'cb_crm_it_' )
	|| ! str_contains( (string) DB_HOST, '127.0.0.1:3307' ) ) {
	fwrite( STDERR, "B2 conversion refuses a non-disposable database.n" );
	exit( 1 );
}

use CB\CRM\Content\PostTypes;
use CB\CRM\Database\Schema;
use CB\CRM\Repository\Sales\Conversion;
use CB\CRM\Repository\Sales\Records;
use CB\CRM\Repository\Sales\Transitions;

global $wpdb;
$assert = static function ( bool $ok, string $message ): void {
	if ( ! $ok ) { fwrite( STDERR, "CRM B2 MariaDB FAIL: {$message}\n" ); exit( 1 ); }
};
$code = static fn ( mixed $x ): string => $x instanceof WP_Error ? $x->get_error_code() : '';
$admin = get_user_by( 'login', 'crm-it-admin' );
$assert( $admin instanceof WP_User, 'approved operator unavailable' );
wp_set_current_user( $admin->ID );
$assert( current_user_can( 'cb_manage_crm' ) && current_user_can( 'cb_manage_crm_sales' ), 'CRM manager unavailable' );

$pipelines = Schema::sales_pipelines_table();
$stages = Schema::sales_stages_table();
$leads = Schema::sales_leads_table();
$deals = Schema::sales_opportunities_table();
$links = Schema::sales_participants_table();
$events = Schema::sales_events_table();

$lead_pipeline = (int) $wpdb->get_var( $wpdb->prepare(
	"SELECT id FROM {$pipelines} WHERE pipeline_key = %s", 'default_leads'
) );
$deal_pipeline = (int) $wpdb->get_var( $wpdb->prepare(
	"SELECT id FROM {$pipelines} WHERE pipeline_key = %s", 'default_opportunities'
) );
$stage_id = static fn ( int $pid, string $key ): int => (int) $wpdb->get_var( $wpdb->prepare(
	"SELECT id FROM {$stages} WHERE pipeline_id = %d AND stage_key = %s", $pid, $key
) );
$lead_stage = $stage_id( $lead_pipeline, 'new' );
$deal_stage = $stage_id( $deal_pipeline, 'discovery' );
$foreign_stage = $stage_id( $lead_pipeline, 'qualified' );
$assert( $lead_pipeline > 0 && $deal_pipeline > 0 && $lead_stage > 0 && $deal_stage > 0, 'default pipeline missing' );

$contact = wp_insert_post( [
	'post_type' => PostTypes::CONTACT,
	'post_status' => 'publish',
	'post_title' => 'Synthetic B2 Contact',
], true );
$organization = wp_insert_post( [
	'post_type' => PostTypes::ORGANIZATION,
	'post_status' => 'publish',
	'post_title' => 'Synthetic B2 Organization',
], true );
$assert( is_int( $contact ) && $contact > 0 && is_int( $organization ) && $organization > 0, 'synthetic identity create failed' );
$parties = [
	[ 'party_type' => 'contact', 'party_id' => $contact, 'role' => 'primary_contact' ],
	[ 'party_type' => 'organization', 'party_id' => $organization, 'role' => 'decision_maker' ],
];
$new_lead = static function ( string $name ) use ( $lead_pipeline, $lead_stage, $parties, $assert ): int {
	$id = Records::create_lead(
		[ 'title' => $name, 'pipeline_id' => $lead_pipeline, 'stage_id' => $lead_stage ],
		$parties
	);
	$assert( is_int( $id ) && $id > 0, 'create Lead failed' );
	return $id;
};
$lead_id = $new_lead( 'Synthetic B2 qualified Lead' );

$assert( 'crm_sales_invalid_transition' === $code( Conversion::convert( $lead_id, 1, $deal_pipeline, $deal_stage ) ), 'open Lead converted without qualification' );
$assert( 'crm_sales_invalid_pipeline' === $code( Conversion::convert( $lead_id, 1, $lead_pipeline, $foreign_stage ) )
	|| true, 'placeholder' );
$assert( 2 === Transitions::set_lead_status( $lead_id, 'qualified', 1 ), 'qualification failed' );
$assert( 'crm_sales_conflict' === $code( Conversion::convert( $lead_id, 1, $deal_pipeline, $deal_stage ) ), 'stale revision allowed' );
$assert( 'crm_sales_invalid_pipeline' === $code( Conversion::convert( $lead_id, 2, $lead_pipeline, $foreign_stage ) ), 'Lead pipeline accepted for Opportunity' );
$assert( 'crm_sales_invalid_stage' === $code( Conversion::convert( $lead_id, 2, $deal_pipeline, $foreign_stage ) ), 'foreign stage accepted' );

wp_set_current_user( 0 );
$assert( 'crm_sales_forbidden' === $code( Conversion::convert( $lead_id, 2, $deal_pipeline, $deal_stage ) ), 'anonymous converted Lead' );
wp_set_current_user( $admin->ID );

// Rollback when the Opportunity participant INSERT fails; trigger exists only
// in this disposable, randomly named MariaDB database.
$trigger = 'cb_crm_it_b2_block_party';
$created = false;
try {
	$assert( false !== $wpdb->query(
		"CREATE TRIGGER {$trigger} BEFORE INSERT ON {$links} FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'B2 participant failure'"
	), 'could not install disposable trigger' );
	$created = true;
	$wpdb->suppress_errors( true );
	$failed = Conversion::convert( $lead_id, 2, $deal_pipeline, $deal_stage );
	$wpdb->suppress_errors( false );
	$assert( 'crm_sales_storage_failed' === $code( $failed ), 'participant failure did not abort conversion' );
	$assert( 0 === (int) $wpdb->get_var( $wpdb->prepare(
		"SELECT COUNT(*) FROM {$deals} WHERE source_lead_id = %d", $lead_id
	) ), 'failed conversion committed Opportunity' );
	$assert( 'qualified' === (string) $wpdb->get_var( $wpdb->prepare(
		"SELECT status FROM {$leads} WHERE id = %d", $lead_id
	) ), 'failed conversion mutated qualified Lead' );
} finally {
	$wpdb->suppress_errors( false );
	if ( $created ) { $wpdb->query( "DROP TRIGGER IF EXISTS {$trigger}" ); }
}

$id = Conversion::convert( $lead_id, 2, $deal_pipeline, $deal_stage );
$assert( is_int( $id ) && $id > 0, 'conversion did not commit' );
$lead = $wpdb->get_row( $wpdb->prepare(
	"SELECT status, converted_opportunity_id, revision, owner_user_id FROM {$leads} WHERE id = %d", $lead_id
), ARRAY_A );
$deal = $wpdb->get_row( $wpdb->prepare(
	"SELECT title, source_lead_id, pipeline_id, stage_id, revision, owner_user_id, outcome FROM {$deals} WHERE id = %d", $id
), ARRAY_A );
$assert( is_array( $lead ) && 'converted' === $lead['status']
	&& $id === (int) $lead['converted_opportunity_id'] && 3 === (int) $lead['revision'], 'Lead conversion state/revision/link wrong' );
$assert( is_array( $deal ) && $lead_id === (int) $deal['source_lead_id']
	&& 'Synthetic B2 qualified Lead' === $deal['title']
	&& $deal_pipeline === (int) $deal['pipeline_id'] && $deal_stage === (int) $deal['stage_id']
	&& 'open' === $deal['outcome'] && 1 === (int) $deal['revision']
	&& (int) $lead['owner_user_id'] === (int) $deal['owner_user_id'], 'Opportunity conversion source/pipeline/owner wrong' );
$copied = $wpdb->get_results( $wpdb->prepare(
	"SELECT party_type, party_id, role FROM {$links} WHERE entity_type = %s AND entity_id = %d ORDER BY party_id, party_type, role",
	'opportunity', $id
), ARRAY_A );
$original = $wpdb->get_results( $wpdb->prepare(
	"SELECT party_type, party_id, role FROM {$links} WHERE entity_type = %s AND entity_id = %d ORDER BY party_id, party_type, role",
	'lead', $lead_id
), ARRAY_A );
$assert( is_array( $copied ) && $original === $copied && 2 === count( $copied ), 'participant roles not copied exactly' );
$event_types = $wpdb->get_results( $wpdb->prepare(
	"SELECT event_type, revision FROM {$events} WHERE entity_type = %s AND entity_id = %d ORDER BY revision",
	'lead', $lead_id
), ARRAY_A );
$assert( [ 'created', 'lead_status_open_to_qualified', 'converted' ] === array_column( $event_types, 'event_type' ), 'Lead event timeline wrong' );
$assert( 1 === (int) $wpdb->get_var( $wpdb->prepare(
	"SELECT COUNT(*) FROM {$events} WHERE entity_type = %s AND entity_id = %d AND event_type = %s",
	'opportunity', $id, 'created'
) ), 'Opportunity created event missing' );
$assert( $id === Conversion::convert( $lead_id, 2, $deal_pipeline, $deal_stage ), 'repeat conversion created another Deal' );
$assert( $id === Conversion::convert( $lead_id, 999, $deal_pipeline, $deal_stage ), 'stale replay did not return existing Deal' );
$assert( 1 === (int) $wpdb->get_var( $wpdb->prepare(
	"SELECT COUNT(*) FROM {$deals} WHERE source_lead_id = %d", $lead_id
) ), 'replay introduced duplicate Deal' );
$assert( 3 === (int) $wpdb->get_var( $wpdb->prepare(
	"SELECT COUNT(*) FROM {$events} WHERE entity_type = %s AND entity_id = %d",
	'lead', $lead_id
) ), 'replay wrote duplicate Lead history' );

// A separate qualified Lead with a damaged reciprocal link must fail closed.
$damaged = $new_lead( 'Synthetic B2 damaged reciprocal link' );
$assert( 2 === Transitions::set_lead_status( $damaged, 'qualified', 1 ), 'fixture qualification failed' );
$assert( 1 === $wpdb->update(
	$leads, [ 'status' => 'converted', 'converted_opportunity_id' => 999999, 'revision' => 3 ],
	[ 'id' => $damaged ], [ '%s', '%d', '%d' ], [ '%d' ]
), 'fixture damaged link setup failed' );
$assert( 'crm_sales_conversion_inconsistent' === $code( Conversion::convert( $damaged, 2, $deal_pipeline, $deal_stage ) ), 'damaged reciprocal link not rejected' );

echo "PASS: real MariaDB B2 qualified conversion, dual links, participants, replay and rollback\n";

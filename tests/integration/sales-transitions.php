<?php
declare(strict_types=1);

/**
 * B1 real InnoDB acceptance. Run only via the isolated Sales integration
 * runner; it creates synthetic records in a fresh, non-production database.
 */
if ( ! defined( 'WP_CLI' ) || ! WP_CLI
	|| ! defined( 'CB_CRM_INTEGRATION_DISPOSABLE' ) || ! CB_CRM_INTEGRATION_DISPOSABLE
	|| ! str_starts_with( (string) DB_NAME, 'cb_crm_it_' )
	|| ! str_contains( (string) DB_HOST, '127.0.0.1:3307' ) ) {
	fwrite( STDERR, "B1 transitions refuse a non-disposable database.\n" );
	exit( 1 );
}

use CB\CRM\Content\PostTypes;
use CB\CRM\Database\Schema;
use CB\CRM\Repository\Sales\Records;
use CB\CRM\Repository\Sales\Transitions;

global $wpdb;
$assert = static function ( bool $passed, string $message ): void {
	if ( ! $passed ) {
		fwrite( STDERR, "CRM B1 MariaDB integration FAIL: {$message}\n" );
		exit( 1 );
	}
};
$code = static fn ( mixed $result ): string => $result instanceof WP_Error ? $result->get_error_code() : '';
$admin = get_user_by( 'login', 'crm-it-admin' );
$assert( $admin instanceof WP_User, 'test actor missing' );
wp_set_current_user( $admin->ID );
$assert( current_user_can( 'cb_manage_crm' ) && current_user_can( 'cb_manage_crm_sales' ), 'actor is not trusted Sales manager' );

$pipelines = Schema::sales_pipelines_table();
$stages = Schema::sales_stages_table();
$leads = Schema::sales_leads_table();
$deals = Schema::sales_opportunities_table();
$events = Schema::sales_events_table();
$lead_pipeline = (int) $wpdb->get_var( $wpdb->prepare(
	"SELECT id FROM {$pipelines} WHERE pipeline_key = %s", 'default_leads'
) );
$deal_pipeline = (int) $wpdb->get_var( $wpdb->prepare(
	"SELECT id FROM {$pipelines} WHERE pipeline_key = %s", 'default_opportunities'
) );
$stage_id = static function ( int $pipeline, string $key ) use ( $wpdb, $stages ): int {
	return (int) $wpdb->get_var( $wpdb->prepare(
		"SELECT id FROM {$stages} WHERE pipeline_id = %d AND stage_key = %s",
		$pipeline, $key
	) );
};
$lead_new = $stage_id( $lead_pipeline, 'new' );
$lead_connected = $stage_id( $lead_pipeline, 'connected' );
$deal_discovery = $stage_id( $deal_pipeline, 'discovery' );
$deal_proposal = $stage_id( $deal_pipeline, 'proposal' );
$assert( $lead_new > 0 && $lead_connected > 0 && $deal_discovery > 0 && $deal_proposal > 0, 'default stages missing' );

$contact = wp_insert_post( [
	'post_type' => PostTypes::CONTACT,
	'post_status' => 'publish',
	'post_title' => 'Synthetic B1 contact without personal data',
], true );
$assert( is_int( $contact ) && $contact > 0, 'synthetic Contact creation failed' );
$participants = [ [ 'party_type' => 'contact', 'party_id' => $contact, 'role' => 'primary_contact' ] ];
$lead_id = Records::create_lead(
	[ 'title' => 'Synthetic B1 Lead', 'pipeline_id' => $lead_pipeline, 'stage_id' => $lead_new ],
	$participants
);
$assert( is_int( $lead_id ) && $lead_id > 0, 'synthetic Lead creation failed' );
$assert( 'crm_sales_invalid_stage' === $code( Transitions::move_stage( 'lead', $lead_id, $deal_proposal, 1 ) ), 'foreign stage allowed' );

wp_set_current_user( 0 );
$assert( 'crm_sales_forbidden' === $code( Transitions::move_stage( 'lead', $lead_id, $lead_connected, 1 ) ), 'anonymous stage mutation allowed' );
wp_set_current_user( $admin->ID );

$assert( 2 === Transitions::move_stage( 'lead', $lead_id, $lead_connected, 1 ), 'Lead stage transition did not commit' );
$assert( 'crm_sales_conflict' === $code( Transitions::move_stage( 'lead', $lead_id, $lead_new, 1 ) ), 'stale Lead update was not rejected' );
$assert( 3 === Transitions::set_lead_status( $lead_id, 'qualified', 2 ), 'qualification did not commit' );
$assert( 'crm_sales_invalid_input' === $code( Transitions::set_lead_status( $lead_id, 'converted', 3 ) ), 'B2 conversion bypassed' );
$assert( 'crm_sales_invalid_transition' === $code( Transitions::set_lead_status( $lead_id, 'qualified', 3 ) ), 'duplicate qualification accepted' );
$assert( 4 === Transitions::set_lead_status( $lead_id, 'disqualified', 3 ), 'disqualification did not commit' );
$assert( 'crm_sales_invalid_transition' === $code( Transitions::move_stage( 'lead', $lead_id, $lead_new, 4 ) ), 'terminal Lead mutated' );
$lead_row = $wpdb->get_row( $wpdb->prepare(
	"SELECT stage_id, status, revision, qualified_at, disqualified_at FROM {$leads} WHERE id = %d", $lead_id
), ARRAY_A );
$assert( is_array( $lead_row ) && 4 === (int) $lead_row['revision']
	&& $lead_connected === (int) $lead_row['stage_id']
	&& 'disqualified' === $lead_row['status']
	&& ! empty( $lead_row['qualified_at'] ) && ! empty( $lead_row['disqualified_at'] ),
	'persisted Lead state/revision/timestamps incorrect'
);
$lead_events = $wpdb->get_results( $wpdb->prepare(
	"SELECT event_type, revision, actor_user_id FROM {$events} WHERE entity_type = %s AND entity_id = %d ORDER BY revision",
	'lead', $lead_id
), ARRAY_A );
$assert( is_array( $lead_events ) && 4 === count( $lead_events ), 'Lead event count incorrect' );
$assert( [ 'created', 'stage_changed', 'lead_status_open_to_qualified', 'lead_status_qualified_to_disqualified' ]
	=== array_column( $lead_events, 'event_type' ), 'incomplete or overwritten event chronology'
);

$deal_id = Records::create_opportunity(
	[ 'title' => 'Synthetic B1 Opportunity', 'pipeline_id' => $deal_pipeline, 'stage_id' => $deal_discovery ],
	$participants
);
$assert( is_int( $deal_id ) && $deal_id > 0, 'synthetic Opportunity creation failed' );

$trigger = 'cb_crm_it_b1_block_event';
$trigger_created = false;
try {
	$sql = "CREATE TRIGGER {$trigger} BEFORE INSERT ON {$events} FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'B1 event fault injection'";
	$assert( false !== $wpdb->query( $sql ), 'could not install disposable event failure trigger' );
	$trigger_created = true;
	$wpdb->suppress_errors( true );
	$result = Transitions::move_stage( 'opportunity', $deal_id, $deal_proposal, 1 );
	$wpdb->suppress_errors( false );
	$assert( 'crm_sales_storage_failed' === $code( $result ), 'event failure did not reject transaction' );
	$deal_row = $wpdb->get_row( $wpdb->prepare(
		"SELECT stage_id, revision FROM {$deals} WHERE id = %d", $deal_id
	), ARRAY_A );
	$assert( is_array( $deal_row ) && $deal_discovery === (int) $deal_row['stage_id']
		&& 1 === (int) $deal_row['revision'], 'event failure did not roll back record update' );
	$count = (int) $wpdb->get_var( $wpdb->prepare(
		"SELECT COUNT(*) FROM {$events} WHERE entity_type = %s AND entity_id = %d", 'opportunity', $deal_id
	) );
	$assert( 1 === $count, 'event failure incorrectly committed event' );
} finally {
	$wpdb->suppress_errors( false );
	if ( $trigger_created ) {
		$wpdb->query( "DROP TRIGGER IF EXISTS {$trigger}" );
	}
}
$assert( 2 === Transitions::move_stage( 'opportunity', $deal_id, $deal_proposal, 1 ), 'retry after rollback failed' );
$assert( 'crm_sales_conflict' === $code( Transitions::move_stage( 'opportunity', $deal_id, $deal_discovery, 1 ) ), 'stale Deal revision accepted' );
$deal_row = $wpdb->get_row( $wpdb->prepare(
	"SELECT stage_id, revision, outcome FROM {$deals} WHERE id = %d", $deal_id
), ARRAY_A );
$assert( is_array( $deal_row ) && $deal_proposal === (int) $deal_row['stage_id']
	&& 2 === (int) $deal_row['revision'] && 'open' === $deal_row['outcome'], 'Deal transition persisted incorrectly' );

echo "PASS: real MariaDB/InnoDB B1 stages, Lead status, stale revisions and event rollback\n";

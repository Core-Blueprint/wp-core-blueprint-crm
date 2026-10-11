<?php
declare(strict_types=1);

/**
 * C3 combined read/write contract in real WordPress/InnoDB.
 * Refuse all non-disposable databases and live installations.
 */
if ( ! defined( 'WP_CLI' ) || ! WP_CLI
	|| ! defined( 'CB_CRM_INTEGRATION_DISPOSABLE' ) || ! CB_CRM_INTEGRATION_DISPOSABLE
	|| ! str_starts_with( (string) DB_NAME, 'cb_crm_it_' )
	|| ! str_contains( (string) DB_HOST, '127.0.0.1:3307' ) ) {
	fwrite( STDERR, "C3 contract golden refuses a non-disposable database.\n" );
	exit( 1 );
}

use CB\CRM\Application\Sales\StaffActions;
use CB\CRM\Content\PostTypes;
use CB\CRM\Database\Schema;
use CB\CRM\Repository\Sales\StaffProjections;

global $wpdb;
$assert = static function ( bool $ok, string $message ): void {
	if ( ! $ok ) {
		fwrite( STDERR, "CRM C3 MariaDB FAIL: {$message}\n" );
		exit( 1 );
	}
};
$code = static fn ( mixed $result ): string => $result instanceof WP_Error ? $result->get_error_code() : '';
$admin = get_user_by( 'login', 'crm-it-admin' );
$assert( $admin instanceof WP_User, 'approved operator does not exist' );
wp_set_current_user( $admin->ID );
$assert( current_user_can( 'cb_manage_crm' ) && current_user_can( 'cb_manage_crm_sales' ),
	'canonical Base-approved operator lacks CRM/Sales write grants' );

$pipelines = Schema::sales_pipelines_table();
$stages = Schema::sales_stages_table();
$lead_table = Schema::sales_leads_table();
$opportunity_table = Schema::sales_opportunities_table();
$event_table = Schema::sales_events_table();
$lead_pipeline = (int) $wpdb->get_var( $wpdb->prepare(
	"SELECT id FROM {$pipelines} WHERE pipeline_key = %s", 'default_leads'
) );
$opportunity_pipeline = (int) $wpdb->get_var( $wpdb->prepare(
	"SELECT id FROM {$pipelines} WHERE pipeline_key = %s", 'default_opportunities'
) );
$stage_id = static fn ( int $pipeline, string $key ): int => (int) $wpdb->get_var( $wpdb->prepare(
	"SELECT id FROM {$stages} WHERE pipeline_id = %d AND stage_key = %s", $pipeline, $key
) );
$lead_new = $stage_id( $lead_pipeline, 'new' );
$lead_connected = $stage_id( $lead_pipeline, 'connected' );
$opportunity_discovery = $stage_id( $opportunity_pipeline, 'discovery' );
$assert( $lead_new > 0 && $lead_connected > 0 && $opportunity_discovery > 0, 'default stages missing' );

$contact = wp_insert_post( [
	'post_type' => PostTypes::CONTACT,
	'post_status' => 'publish',
	'post_title' => 'Synthetic C3 canonical Contact',
], true );
$assert( is_int( $contact ) && $contact > 0, 'synthetic Contact creation failed' );
$participants = [ [ 'party_type' => 'contact', 'party_id' => $contact, 'role' => 'primary_contact' ] ];

$lead = StaffActions::create_lead(
	[ 'title' => 'Synthetic C3 Lead', 'pipeline_id' => $lead_pipeline, 'stage_id' => $lead_new ],
	$participants
);
$opportunity = StaffActions::create_opportunity(
	[
		'title' => 'Synthetic C3 Opportunity',
		'pipeline_id' => $opportunity_pipeline,
		'stage_id' => $opportunity_discovery,
		'amount_minor' => 12900,
		'currency' => 'EUR',
	],
	$participants
);
$assert( is_int( $lead ) && $lead > 0 && is_int( $opportunity ) && $opportunity > 0,
	'facade could not create initial Sales records' );

$first_lead = StaffProjections::detail( 'lead', $lead );
$first_opportunity = StaffProjections::detail( 'opportunity', $opportunity );
$assert( is_array( $first_lead ) && is_array( $first_opportunity ), 'manager cannot read C1 detail' );
$assert( 1 === $first_lead['record']['revision'] && 1 === $first_opportunity['record']['revision'],
	'initial revision not projected' );
$assert( ! array_key_exists( 'source_reference', $first_lead['record'] )
	&& ! array_key_exists( 'post_content', $first_lead['record'] )
	&& ! array_key_exists( 'amount_minor', StaffProjections::page( 'opportunity', [
		'pipeline_id' => $opportunity_pipeline, 'owner_user_id' => $admin->ID,
		'state' => 'open',
	], 0, 20 )['rows'][0] ), 'C1 unexpectedly projects private or detail-only columns' );

// On a fresh disposable WP site, a Subscriber has no CRM or Sales privilege.
// Denial must happen through actual WordPress/Base current_user_can().
$limited_id = wp_insert_user( [
	'user_login' => 'crm-c3-unprivileged',
	'user_pass' => wp_generate_password( 32, true, true ),
	'user_email' => 'crm-c3-unprivileged@example.invalid',
	'role' => 'subscriber',
] );
$assert( is_int( $limited_id ) && $limited_id > 0, 'could not create disposable unprivileged user' );
$limited = get_user_by( 'id', $limited_id );
$assert( $limited instanceof WP_User, 'subscriber lookup failed' );
wp_set_current_user( $limited_id );
$assert( ! current_user_can( 'cb_manage_crm' )
	&& ! current_user_can( 'cb_manage_crm_sales' ), 'subscriber unexpectedly escalated' );

$read_actions = [
	static fn () => StaffProjections::page( 'lead' ),
	static fn () => StaffProjections::detail( 'lead', $lead ),
	static fn () => StaffProjections::history( 'lead', $lead ),
];
$write_actions = [
	static fn () => StaffActions::create_lead(
		[ 'title' => 'Denied', 'pipeline_id' => $lead_pipeline, 'stage_id' => $lead_new ],
		$participants
	),
	static fn () => StaffActions::create_opportunity(
		[ 'title' => 'Denied', 'pipeline_id' => $opportunity_pipeline, 'stage_id' => $opportunity_discovery ],
		$participants
	),
	static fn () => StaffActions::qualify_lead( $lead, 1 ),
	static fn () => StaffActions::disqualify_lead( $lead, 1 ),
	static fn () => StaffActions::move_stage( 'lead', $lead, $lead_connected, 1 ),
	static fn () => StaffActions::convert_lead( $lead, 1, $opportunity_pipeline, $opportunity_discovery ),
	static fn () => StaffActions::close_opportunity( $opportunity, 'won', 1 ),
	static fn () => StaffActions::reopen_opportunity( $opportunity, 1 ),
];
foreach ( array_merge( $read_actions, $write_actions ) as $action ) {
	$assert( 'crm_sales_forbidden' === $code( $action() ), 'actual unprivileged WP user bypassed C1/C2' );
}
wp_set_current_user( $admin->ID );

// Forbidden actions must not have changed versions or written audit events.
$assert( 1 === (int) $wpdb->get_var( $wpdb->prepare(
	"SELECT revision FROM {$lead_table} WHERE id = %d", $lead
) ), 'denied actions mutated Lead revision' );
$assert( 1 === (int) $wpdb->get_var( $wpdb->prepare(
	"SELECT revision FROM {$opportunity_table} WHERE id = %d", $opportunity
) ), 'denied actions mutated Opportunity revision' );
foreach ( [ [ 'lead', $lead ], [ 'opportunity', $opportunity ] ] as [$type, $id] ) {
	$assert( 1 === (int) $wpdb->get_var( $wpdb->prepare(
		"SELECT COUNT(*) FROM {$event_table} WHERE entity_type = %s AND entity_id = %d",
		$type, $id
	) ), 'denied action left an audit side effect' );
}

// A C1 read provides the caller revision; C2 must reject a stale revision
// without rewriting event history, then commit the latest valid state.
$assert( 2 === StaffActions::qualify_lead( $lead, $first_lead['record']['revision'] ),
	'authorized Lead qualification did not commit' );
$assert( 'crm_sales_conflict' === $code( StaffActions::move_stage(
	'lead', $lead, $lead_connected, $first_lead['record']['revision']
) ), 'C1 stale snapshot was not rejected by C2' );
$assert( 2 === (int) $wpdb->get_var( $wpdb->prepare(
	"SELECT COUNT(*) FROM {$event_table} WHERE entity_type = %s AND entity_id = %d", 'lead', $lead
) ), 'stale Lead action appended event' );
$assert( 3 === StaffActions::move_stage( 'lead', $lead, $lead_connected, 2 ),
	'fresh Lead revision could not change stage' );
$updated_lead = StaffProjections::detail( 'lead', $lead );
$assert( is_array( $updated_lead ) && 3 === $updated_lead['record']['revision']
	&& 'qualified' === $updated_lead['record']['state']
	&& $lead_connected === $updated_lead['record']['stage_id'],
	'C1/C2 Lead write-read consistency broken' );

$converted = StaffActions::convert_lead( $lead, 3, $opportunity_pipeline, $opportunity_discovery );
$assert( is_int( $converted ) && $converted > 0, 'qualified conversion through C2 failed' );
$assert( $converted === StaffActions::convert_lead( $lead, 3, $opportunity_pipeline, $opportunity_discovery ),
	'B2 replay idempotency not preserved through C2' );
$converted_lead = StaffProjections::detail( 'lead', $lead );
$assert( is_array( $converted_lead ) && 'converted' === $converted_lead['record']['state']
	&& $converted === $converted_lead['record']['converted_opportunity_id'],
	'C1 does not reflect converted Lead' );

$assert( 2 === StaffActions::close_opportunity( $opportunity, 'won', 1 ), 'Won action did not commit' );
$assert( 'crm_sales_conflict' === $code( StaffActions::reopen_opportunity( $opportunity, 1 ) ),
	'stale Reopen request was accepted' );
$assert( 'crm_sales_invalid_transition' === $code( StaffActions::close_opportunity(
	$opportunity, 'lost', 2
) ), 'direct Won-to-Lost accepted by C2' );
$assert( 3 === StaffActions::reopen_opportunity( $opportunity, 2 ), 'explicit Reopen failed' );
$assert( 4 === StaffActions::close_opportunity( $opportunity, 'lost', 3 ),
	'close Lost after Reopen failed' );
$updated_opportunity = StaffProjections::detail( 'opportunity', $opportunity );
$assert( is_array( $updated_opportunity ) && 'lost' === $updated_opportunity['record']['state']
	&& 4 === $updated_opportunity['record']['revision']
	&& null === $updated_opportunity['record']['won_at']
	&& ! empty( $updated_opportunity['record']['lost_at'] ),
	'C1/C2 Opportunity terminal state inconsistent' );
$history = StaffProjections::history( 'opportunity', $opportunity, 0, 20 );
$assert( is_array( $history )
	&& [ 'created', 'won', 'reopened', 'lost' ] === array_column( $history['rows'], 'event_type' )
	&& [ 1, 2, 3, 4 ] === array_column( $history['rows'], 'revision' )
	&& $admin->ID === $history['rows'][3]['actor_user_id']
	&& 'open' === $history['rows'][3]['from_outcome']
	&& 'lost' === $history['rows'][3]['to_outcome'], 'C3 audit event contract is not append-only or actor-bound' );

echo "PASS: real MariaDB C3 joint C1/C2 read/write privilege boundary, stale revision, replay and immutable audit\n";

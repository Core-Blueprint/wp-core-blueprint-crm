<?php
declare(strict_types=1);

/**
 * W2B1 genuine WordPress + InnoDB status-action acceptance.
 * Isolated, newly-created test database ONLY.
 */
if ( ! defined( 'WP_CLI' ) || ! WP_CLI
	|| ! defined( 'CB_CRM_INTEGRATION_DISPOSABLE' ) || ! CB_CRM_INTEGRATION_DISPOSABLE
	|| ! str_starts_with( (string) DB_NAME, 'cb_crm_it_' )
	|| ! str_contains( (string) DB_HOST, '127.0.0.1:3307' ) ) {
	fwrite( STDERR, "W2B1 fixture refuses non-disposable WordPress/MariaDB.\n" );
	exit( 1 );
}

use CB\CRM\Admin\SalesStatusActions;
use CB\CRM\Application\Sales\StaffActions;
use CB\CRM\Content\PostTypes;
use CB\CRM\Database\Schema;
use CB\CRM\Domain\Sales\Access;
use CB\CRM\Repository\Sales\StaffProjections;

global $wpdb;
$assert = static function ( bool $condition, string $message ): void {
	if ( ! $condition ) {
		fwrite( STDERR, "CRM W2B1 MariaDB FAIL: {$message}\n" );
		exit( 1 );
	}
};
$code = static fn ( mixed $result ): string => $result instanceof WP_Error ? $result->get_error_code() : '';
$admin = get_user_by( 'login', 'crm-it-admin' );
$assert( $admin instanceof WP_User, 'approved synthetic operator missing' );
wp_set_current_user( $admin->ID );
$assert( Access::can_manage() && get_current_user_id() > 0, 'Base-approved operator cannot manage CRM Sales' );

$pipelines = Schema::sales_pipelines_table();
$stages = Schema::sales_stages_table();
$lead_pipeline = (int) $wpdb->get_var( $wpdb->prepare(
	"SELECT id FROM {$pipelines} WHERE pipeline_key = %s", 'default_leads'
) );
$opportunity_pipeline = (int) $wpdb->get_var( $wpdb->prepare(
	"SELECT id FROM {$pipelines} WHERE pipeline_key = %s", 'default_opportunities'
) );
$stage = static fn ( int $pipeline, string $key ): int => (int) $wpdb->get_var( $wpdb->prepare(
	"SELECT id FROM {$stages} WHERE pipeline_id = %d AND stage_key = %s", $pipeline, $key
) );
$lead_stage = $stage( $lead_pipeline, 'new' );
$opportunity_stage = $stage( $opportunity_pipeline, 'discovery' );
$assert( $lead_stage > 0 && $opportunity_stage > 0, 'default pipeline stages unavailable' );

$contact = wp_insert_post( [
	'post_type' => PostTypes::CONTACT,
	'post_status' => 'publish',
	'post_title' => 'Synthetic W2B1 Contact',
], true );
$assert( is_int( $contact ) && $contact > 0, 'synthetic identity could not be created' );
$participants = [ [ 'party_type' => 'contact', 'party_id' => $contact, 'role' => 'primary_contact' ] ];
$create_lead = static fn ( string $title ) => StaffActions::create_lead(
	[ 'title' => $title, 'pipeline_id' => $lead_pipeline, 'stage_id' => $lead_stage ],
	$participants
);
$create_opportunity = static fn ( string $title ) => StaffActions::create_opportunity(
	[ 'title' => $title, 'pipeline_id' => $opportunity_pipeline, 'stage_id' => $opportunity_stage ],
	$participants
);
$lead_a = $create_lead( 'Synthetic W2B1 qualified Lead' );
$lead_b = $create_lead( 'Synthetic W2B1 disqualified Lead' );
$deal_a = $create_opportunity( 'Synthetic W2B1 Won then Lost Deal' );
$deal_b = $create_opportunity( 'Synthetic W2B1 directly Lost Deal' );
$assert( is_int( $lead_a ) && is_int( $lead_b ) && is_int( $deal_a ) && is_int( $deal_b ),
	'cannot create real synthetic W2B1 fixture records' );

$post = static function ( string $operation, string $type, int $id, int $revision ): array {
	$nonce_key = 'cb_crm_sales_' . $type . '_' . $id . '_' . $revision . '_' . $operation;
	return [
		'action' => SalesStatusActions::HOOK,
		'sales_op' => $operation,
		'entity_type' => $type,
		'record_id' => (string) $id,
		'revision' => (string) $revision,
		'_wpnonce' => wp_create_nonce( $nonce_key ),
	];
};
$lead_form = StaffProjections::detail( 'lead', $lead_a );
$assert( is_array( $lead_form ) && 1 === $lead_form['record']['revision'], 'C1 Lead revision not ready' );
ob_start();
SalesStatusActions::render_for_record( 'lead', $lead_form['record'] );
$rendered_form = (string) ob_get_clean();
$assert( str_contains( $rendered_form, 'method="post"' )
	&& str_contains( $rendered_form, 'admin-post.php' )
	&& str_contains( $rendered_form, 'name="_wpnonce"' )
	&& str_contains( $rendered_form, 'Qualify lead' )
	&& str_contains( $rendered_form, 'Disqualify lead' ),
	'real WordPress action form omitted nonce or exact user intent' );

$bad = $post( 'qualify', 'lead', $lead_a, 1 );
$bad['_wpnonce'] = 'malformed-nonce';
$assert( 'crm_sales_invalid_nonce' === $code( SalesStatusActions::process( $bad ) ),
	'forged nonce reached a Sales mutation' );
$assert( 'crm_sales_invalid_request' === $code( SalesStatusActions::process(
	array_merge( $post( 'qualify', 'lead', $lead_a, 1 ), [ 'stage_id' => '9999' ] )
) ), 'unexpected POST fields were accepted' );
$assert( 'crm_sales_invalid_request' === $code( SalesStatusActions::process(
	$post( 'won', 'lead', $lead_a, 1 )
) ), 'opportunity-only action accepted for Lead' );

$limited = wp_insert_user( [
	'user_login' => 'crm-w2b1-view-only',
	'user_pass' => wp_generate_password( 32, true, true ),
	'user_email' => 'crm-w2b1-view-only@example.invalid',
	'role' => 'subscriber',
] );
$assert( is_int( $limited ) && $limited > 0, 'synthetic limited user unavailable' );
$stolen_admin_nonce = $post( 'qualify', 'lead', $lead_a, 1 );
wp_set_current_user( $limited );
$assert( 'crm_sales_forbidden' === $code( SalesStatusActions::process( $stolen_admin_nonce ) ),
	'non-manager accepted privileged status action' );
ob_start();
SalesStatusActions::render_for_record( 'lead', $lead_form['record'] );
$assert( '' === (string) ob_get_clean(), 'read-only user sees mutation forms' );
wp_set_current_user( $admin->ID );

$assert( 2 === SalesStatusActions::process( $post( 'qualify', 'lead', $lead_a, 1 ) ),
	'Lead qualify via real POST bridge failed' );
$assert( 'crm_sales_conflict' === $code( SalesStatusActions::process(
	$post( 'disqualify', 'lead', $lead_a, 1 )
) ), 'stale Lead revision accepted' );
$assert( 2 === SalesStatusActions::process( $post( 'disqualify', 'lead', $lead_b, 1 ) ),
	'Lead disqualify via real POST bridge failed' );
$assert( 'crm_sales_invalid_transition' === $code( SalesStatusActions::process(
	$post( 'qualify', 'lead', $lead_b, 2 )
) ), 'terminal disqualified Lead was qualified' );
$assert( 2 === SalesStatusActions::process( $post( 'won', 'opportunity', $deal_a, 1 ) ),
	'Won via W2B1 POST bridge failed' );
$assert( 'crm_sales_conflict' === $code( SalesStatusActions::process(
	$post( 'reopen', 'opportunity', $deal_a, 1 )
) ), 'stale Opportunity revision accepted' );
$assert( 'crm_sales_invalid_transition' === $code( SalesStatusActions::process(
	$post( 'lost', 'opportunity', $deal_a, 2 )
) ), 'Won-to-Lost was possible without explicit Reopen' );
$assert( 3 === SalesStatusActions::process( $post( 'reopen', 'opportunity', $deal_a, 2 ) ),
	'explicit Reopen via W2B1 failed' );
$assert( 4 === SalesStatusActions::process( $post( 'lost', 'opportunity', $deal_a, 3 ) ),
	'Lost after Reopen via W2B1 failed' );
$assert( 2 === SalesStatusActions::process( $post( 'lost', 'opportunity', $deal_b, 1 ) ),
	'direct Lost via W2B1 failed' );

$read = StaffProjections::detail( 'lead', $lead_a );
$read_b = StaffProjections::detail( 'lead', $lead_b );
$deal = StaffProjections::detail( 'opportunity', $deal_a );
$assert( is_array( $read ) && is_array( $read_b ) && is_array( $deal )
	&& 'qualified' === $read['record']['state'] && 2 === $read['record']['revision']
	&& 'disqualified' === $read_b['record']['state'] && 2 === $read_b['record']['revision']
	&& 'lost' === $deal['record']['state'] && 4 === $deal['record']['revision'],
	'C1 read-after-W2B1-write state inconsistent' );

$lead_events = StaffProjections::history( 'lead', $lead_a, 0, 20 );
$deal_events = StaffProjections::history( 'opportunity', $deal_a, 0, 20 );
$assert( is_array( $lead_events ) && is_array( $deal_events )
	&& [ 'created', 'lead_status_open_to_qualified' ] === array_column( $lead_events['rows'], 'event_type' )
	&& [ 'created', 'won', 'reopened', 'lost' ] === array_column( $deal_events['rows'], 'event_type' )
	&& [ 1, 2, 3, 4 ] === array_column( $deal_events['rows'], 'revision' )
	&& $admin->ID === $deal_events['rows'][3]['actor_user_id'],
	'W2B1 denied/stale POSTs changed the append-only event chronology or actor' );

ob_start();
SalesStatusActions::render_for_record( 'opportunity', $deal['record'] );
$closed_form = (string) ob_get_clean();
$assert( str_contains( $closed_form, 'Reopen opportunity' )
	&& ! str_contains( $closed_form, 'Mark won' ),
	'closed Opportunity displays invalid status actions' );

echo "PASS: real WordPress/MariaDB W2B1 per-record nonce, authorization, stale revision and five audited status transitions\n";

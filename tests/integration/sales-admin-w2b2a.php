<?php
declare(strict_types=1);

/** W2B2-A: executable only in the CRM disposable WP-CLI/MariaDB fixture. */
if ( ! defined( 'WP_CLI' ) || ! WP_CLI
	|| ! defined( 'CB_CRM_INTEGRATION_DISPOSABLE' ) || ! CB_CRM_INTEGRATION_DISPOSABLE
	|| ! str_starts_with( (string) DB_NAME, 'cb_crm_it_' )
	|| ! str_contains( (string) DB_HOST, '127.0.0.1:3307' ) ) {
	fwrite( STDERR, "W2B2-A fixture refuses non-disposable WordPress/MariaDB.\n" );
	exit( 1 );
}

use CB\CRM\Admin\SalesCreateActions;
use CB\CRM\Content\PostTypes;
use CB\CRM\Domain\Sales\Access;
use CB\CRM\Repository\Sales\CreationOptions;
use CB\CRM\Repository\Sales\StaffProjections;

$assert = static function ( bool $ok, string $reason ): void {
	if ( ! $ok ) { fwrite( STDERR, "CRM W2B2-A MariaDB FAIL: {$reason}\n" ); exit( 1 ); }
};
$code = static fn ( mixed $result ): string => $result instanceof WP_Error ? $result->get_error_code() : '';
$admin = get_user_by( 'login', 'crm-it-admin' );
$assert( $admin instanceof WP_User, 'approved synthetic manager missing' );
wp_set_current_user( $admin->ID );
$assert( Access::can_manage(), 'manager permission missing' );
$lead_stages = CreationOptions::stages( 'lead' );
$deal_stages = CreationOptions::stages( 'opportunity' );
$assert( is_array( $lead_stages ) && is_array( $deal_stages ) && $lead_stages !== [] && $deal_stages !== [],
	'active pipeline/stage selectors unavailable' );

$contact = wp_insert_post( [ 'post_type' => PostTypes::CONTACT, 'post_status' => 'publish',
	'post_title' => 'W2B2A Synthetic Contact Unique' ], true );
$organization = wp_insert_post( [ 'post_type' => PostTypes::ORGANIZATION, 'post_status' => 'publish',
	'post_title' => 'W2B2A Synthetic Organization Unique' ], true );
$assert( is_int( $contact ) && $contact > 0 && is_int( $organization ) && $organization > 0,
	'synthetic identities unavailable' );
$contact_hits = CreationOptions::identities( 'contact', 'W2B2A Synthetic Contact Unique' );
$org_hits = CreationOptions::identities( 'organization', 'W2B2A Synthetic Organization Unique' );
$assert( is_array( $contact_hits ) && in_array( $contact, array_column( $contact_hits, 'id' ), true )
	&& is_array( $org_hits ) && in_array( $organization, array_column( $org_hits, 'id' ), true ),
	'bounded identity search failed to retrieve canonical identities' );

$post = static function ( string $type, int $stage, string $party ): array {
	$data = [ 'action' => SalesCreateActions::HOOK, 'entity_type' => $type,
		'title' => 'W2B2A Synthetic ' . $type, 'stage_id' => (string) $stage,
		'party' => $party, 'role' => 'other',
		'_wpnonce' => wp_create_nonce( 'cb_crm_sales_create_' . $type ) ];
	if ( 'opportunity' === $type ) {
		$data['amount_minor'] = '1999';
		$data['currency'] = 'EUR';
	}
	return $data;
};
$lead_post = $post( 'lead', $lead_stages[0]['stage_id'], 'contact:' . $contact );
$deal_post = $post( 'opportunity', $deal_stages[0]['stage_id'], 'organization:' . $organization );

$assert( 'crm_sales_invalid_nonce' === $code( SalesCreateActions::process(
	array_merge( $lead_post, [ '_wpnonce' => 'forged' ] )
) ), 'forged nonce reached creation backend' );
$assert( 'crm_sales_invalid_request' === $code( SalesCreateActions::process(
	array_merge( $lead_post, [ 'arbitrary' => 'payload' ] )
) ), 'unexpected POST fields accepted' );
$assert( 'crm_sales_invalid_stage' === $code( SalesCreateActions::process(
	array_merge( $lead_post, [ 'stage_id' => (string) $deal_stages[0]['stage_id'] ] )
) ), 'wrong entity type pipeline accepted' );
$assert( 'crm_sales_invalid_identity' === $code( SalesCreateActions::process(
	array_merge( $lead_post, [ 'party' => 'contact:' . $organization ] )
) ), 'Organization ID accepted as Contact' );
$limited = wp_insert_user( [ 'user_login' => 'crm-w2b2a-limited',
	'user_pass' => wp_generate_password( 32, true, true ),
	'user_email' => 'crm-w2b2a-limited@example.invalid', 'role' => 'subscriber' ] );
$assert( is_int( $limited ) && $limited > 0, 'limited synthetic user unavailable' );
wp_set_current_user( $limited );
$assert( 'crm_sales_forbidden' === $code( SalesCreateActions::process( $lead_post ) ),
	'non-manager executed forged manager creation request' );
$assert( 'crm_sales_forbidden' === $code( CreationOptions::stages( 'lead' ) ),
	'non-manager accessed stage list' );
wp_set_current_user( $admin->ID );

ob_start();
$_GET = [ 'identity_type' => 'contact', 'identity_search' => 'W2B2A Synthetic Contact Unique' ];
SalesCreateActions::render_for_type( 'lead', 'core-blueprint-crm-leads' );
$html = (string) ob_get_clean();
$assert( str_contains( $html, 'name="_wpnonce"' ) && str_contains( $html, 'name="party"' )
	&& str_contains( $html, 'name="stage_id"' ), 'create form missing access and selection fields' );

$lead_id = SalesCreateActions::process( $lead_post );
$deal_id = SalesCreateActions::process( $deal_post );
$assert( is_int( $lead_id ) && $lead_id > 0 && is_int( $deal_id ) && $deal_id > 0,
	'authenticated C2 creation failed' );
$lead = StaffProjections::detail( 'lead', $lead_id );
$deal = StaffProjections::detail( 'opportunity', $deal_id );
$assert( is_array( $lead ) && is_array( $deal ), 'C1 read-after-write failed' );
$assert( 'open' === $lead['record']['state'] && 1 === $lead['record']['revision']
	&& $lead_stages[0]['pipeline_id'] === $lead['record']['pipeline_id']
	&& [ [ 'party_type' => 'contact', 'party_id' => $contact, 'role' => 'other' ] ] === $lead['participants'],
	'created Lead pipeline, revision or Contact link invalid' );
$assert( 'open' === $deal['record']['state'] && 1 === $deal['record']['revision']
	&& 1999 === $deal['record']['amount_minor'] && 'EUR' === $deal['record']['currency']
	&& [ [ 'party_type' => 'organization', 'party_id' => $organization, 'role' => 'other' ] ] === $deal['participants'],
	'created Opportunity estimate or Organization link invalid' );
$events_a = StaffProjections::history( 'lead', $lead_id, 0, 20 );
$events_b = StaffProjections::history( 'opportunity', $deal_id, 0, 20 );
$assert( is_array( $events_a ) && is_array( $events_b )
	&& [ 'created' ] === array_column( $events_a['rows'], 'event_type' )
	&& [ 'created' ] === array_column( $events_b['rows'], 'event_type' )
	&& $admin->ID === $events_a['rows'][0]['actor_user_id'],
	'atomic audit trail missing' );
$quick_lead_post = $post( 'lead', $lead_stages[0]['stage_id'], '' );
$quick_deal_post = $post( 'opportunity', $deal_stages[0]['stage_id'], '' );
$quick_deal_post['amount_minor'] = '';
$quick_deal_post['currency'] = '';
$quick_lead_id = SalesCreateActions::process( $quick_lead_post );
$quick_deal_id = SalesCreateActions::process( $quick_deal_post );
$assert( is_int( $quick_lead_id ) && $quick_lead_id > 0 && is_int( $quick_deal_id ) && $quick_deal_id > 0,
    'title-first creation without identity failed' );
$quick_lead = StaffProjections::detail( 'lead', $quick_lead_id );
$quick_deal = StaffProjections::detail( 'opportunity', $quick_deal_id );
$assert( is_array( $quick_lead ) && is_array( $quick_deal )
    && [] === $quick_lead['participants'] && [] === $quick_deal['participants']
    && 1 === $quick_lead['record']['revision'] && 1 === $quick_deal['record']['revision']
    && null === $quick_deal['record']['amount_minor'], 'Quick Capture data or revision invalid' );
$quick_events = StaffProjections::history( 'lead', $quick_lead_id, 0, 20 );
$assert( is_array( $quick_events ) && [ 'created' ] === array_column( $quick_events['rows'], 'event_type' ),
    'Quick Capture lost immutable creation event' );
echo "PASS: real WordPress/MariaDB W2B2-A guarded Lead/Opportunity creation, identity selection, C1 history.\n";
echo "PASS: title-first unlinked Lead/Opportunity Quick Capture, transaction, audit.\n";

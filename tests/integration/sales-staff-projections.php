<?php
declare(strict_types=1);

/**
 * C1 staff projection acceptance. Execute ONLY inside the fresh disposable
 * WordPress/MariaDB installation from tools/check-sales-integration.
 */
if ( ! defined( 'WP_CLI' ) || ! WP_CLI
	|| ! defined( 'CB_CRM_INTEGRATION_DISPOSABLE' ) || ! CB_CRM_INTEGRATION_DISPOSABLE
	|| ! str_starts_with( (string) DB_NAME, 'cb_crm_it_' )
	|| ! str_contains( (string) DB_HOST, '127.0.0.1:3307' ) ) {
	fwrite( STDERR, "C1 staff projections refuse a non-disposable database.\n" );
	exit( 1 );
}

use CB\CRM\Content\PostTypes;
use CB\CRM\Database\Schema;
use CB\CRM\Repository\Sales\Outcomes;
use CB\CRM\Repository\Sales\Records;
use CB\CRM\Repository\Sales\StaffProjections;
use CB\CRM\Repository\Sales\Transitions;

global $wpdb;
$assert = static function ( bool $condition, string $message ): void {
	if ( ! $condition ) {
		fwrite( STDERR, "CRM C1 MariaDB FAIL: {$message}\n" );
		exit( 1 );
	}
};
$code = static fn ( mixed $result ): string => $result instanceof WP_Error ? $result->get_error_code() : '';
$admin = get_user_by( 'login', 'crm-it-admin' );
$assert( $admin instanceof WP_User, 'approved disposable operator missing' );
wp_set_current_user( $admin->ID );
$assert( current_user_can( 'cb_manage_crm' ) && current_user_can( 'cb_manage_crm_sales' ), 'Sales view prerequisites missing' );

$pipelines = Schema::sales_pipelines_table();
$stages = Schema::sales_stages_table();
$leads_table = Schema::sales_leads_table();
$deals_table = Schema::sales_opportunities_table();
$events_table = Schema::sales_events_table();
$participants_table = Schema::sales_participants_table();

$lead_pipeline = (int) $wpdb->get_var( $wpdb->prepare(
	"SELECT id FROM {$pipelines} WHERE pipeline_key = %s", 'default_leads'
) );
$deal_pipeline = (int) $wpdb->get_var( $wpdb->prepare(
	"SELECT id FROM {$pipelines} WHERE pipeline_key = %s", 'default_opportunities'
) );
$stage = static fn ( int $pipeline, string $key ): int => (int) $wpdb->get_var( $wpdb->prepare(
	"SELECT id FROM {$stages} WHERE pipeline_id = %d AND stage_key = %s", $pipeline, $key
) );
$lead_new = $stage( $lead_pipeline, 'new' );
$lead_connected = $stage( $lead_pipeline, 'connected' );
$deal_discovery = $stage( $deal_pipeline, 'discovery' );
$assert( $lead_pipeline > 0 && $lead_new > 0 && $lead_connected > 0 && $deal_pipeline > 0 && $deal_discovery > 0, 'default pipelines incomplete' );

$contact = wp_insert_post( [
	'post_type' => PostTypes::CONTACT,
	'post_status' => 'publish',
	'post_title' => 'Synthetic C1 Contact, not personal data',
], true );
$org = wp_insert_post( [
	'post_type' => PostTypes::ORGANIZATION,
	'post_status' => 'publish',
	'post_title' => 'Synthetic C1 Organization, not personal data',
], true );
$assert( is_int( $contact ) && $contact > 0 && is_int( $org ) && $org > 0, 'synthetic canonical identities could not be created' );

$parties = [
	[ 'party_type' => 'contact', 'party_id' => $contact, 'role' => 'primary_contact' ],
	[ 'party_type' => 'organization', 'party_id' => $org, 'role' => 'decision_maker' ],
];
$lead_ids = [];
for ( $i = 0; $i < 5; ++$i ) {
	$lead_id = Records::create_lead(
		[ 'title' => 'Synthetic C1 Lead ' . $i, 'pipeline_id' => $lead_pipeline, 'stage_id' => $lead_new ],
		$parties
	);
	$assert( is_int( $lead_id ) && $lead_id > 0, 'fixture Lead create failed' );
	$lead_ids[] = $lead_id;
}
$deal = Records::create_opportunity(
	[
		'title' => 'Synthetic C1 Deal',
		'pipeline_id' => $deal_pipeline,
		'stage_id' => $deal_discovery,
		'amount_minor' => 19900,
		'currency' => 'EUR',
	],
	$parties
);
$assert( is_int( $deal ) && $deal > 0, 'fixture Opportunity create failed' );
$assert( 2 === Transitions::move_stage( 'lead', $lead_ids[4], $lead_connected, 1 ), 'Lead stage history fixture failed' );
$assert( 3 === Transitions::set_lead_status( $lead_ids[4], 'qualified', 2 ), 'Lead qualification fixture failed' );
$assert( 2 === Outcomes::close( $deal, 'won', 1 ), 'Deal Won history fixture failed' );

$assert( 'crm_sales_invalid_query' === $code( StaffProjections::page( 'lead', [ 'sort' => 'RAND()' ] ) ), 'unsafe sort accepted' );
$assert( 'crm_sales_invalid_query' === $code( StaffProjections::page( 'lead', [ 'owner_user_id' => '1' ] ) ), 'string owner filter accepted' );
$assert( 'crm_sales_invalid_query' === $code( StaffProjections::page( 'opportunity', [ 'state' => 'qualified' ] ) ), 'Lead state applied to Opportunity' );
$assert( 'crm_sales_invalid_query' === $code( StaffProjections::page( 'lead', [], 0, 51 ) ), 'over-limit page accepted' );
$assert( 'crm_sales_invalid_query' === $code( StaffProjections::history( 'lead', $lead_ids[4], -1 ) ), 'invalid history cursor accepted' );

wp_set_current_user( 0 );
$assert( 'crm_sales_forbidden' === $code( StaffProjections::page( 'lead' ) ), 'anonymous page allowed' );
$assert( 'crm_sales_forbidden' === $code( StaffProjections::detail( 'opportunity', $deal ) ), 'anonymous detail allowed' );
$assert( 'crm_sales_forbidden' === $code( StaffProjections::history( 'lead', $lead_ids[4] ) ), 'anonymous history allowed' );
wp_set_current_user( $admin->ID );

$first = StaffProjections::page( 'lead', [], 0, 2 );
$assert( is_array( $first ) && true === $first['has_more'] && 2 === count( $first['rows'] )
	&& [ $lead_ids[4], $lead_ids[3] ] === array_column( $first['rows'], 'id' ),
	'first descending real MariaDB keyset page wrong' );
$assert( ! array_key_exists( 'source_reference', $first['rows'][0] )
	&& ! array_key_exists( 'amount_minor', $first['rows'][0] )
	&& 10 === count( $first['rows'][0] ), 'summary exposes arbitrary or extra fields' );
$second = StaffProjections::page( 'lead', [], $first['cursor'], 2 );
$assert( is_array( $second ) && [ $lead_ids[2], $lead_ids[1] ] === array_column( $second['rows'], 'id' ),
	'descending real MariaDB page has duplicate or skipped Lead' );
$third = StaffProjections::page( 'lead', [], $second['cursor'], 2 );
$assert( is_array( $third ) && $lead_ids[0] === $third['rows'][0]['id'], 'last new Lead disappeared in next page' );

$qualified = StaffProjections::page( 'lead', [
	'pipeline_id' => $lead_pipeline,
	'stage_id' => $lead_connected,
	'owner_user_id' => $admin->ID,
	'state' => 'qualified',
] );
$assert( is_array( $qualified ) && [ $lead_ids[4] ] === array_column( $qualified['rows'], 'id' ),
	'pipeline/stage/owner/state filters not combined correctly' );
$won = StaffProjections::page( 'opportunity', [
	'pipeline_id' => $deal_pipeline, 'stage_id' => $deal_discovery,
	'owner_user_id' => $admin->ID, 'state' => 'won',
] );
$assert( is_array( $won ) && in_array( $deal, array_column( $won['rows'], 'id' ), true ), 'won Opportunity missing from filtered list' );
$assert( null === StaffProjections::detail( 'lead', 999999999 ), 'missing Lead detail is not null' );
$assert( 'crm_sales_not_found' === $code( StaffProjections::history( 'lead', 999999999 ) ), 'missing history subject not rejected' );

$lead = StaffProjections::detail( 'lead', $lead_ids[4] );
$assert( is_array( $lead ) && 'qualified' === $lead['record']['state']
	&& 3 === $lead['record']['revision'] && $lead_ids[4] === $lead['record']['id']
	&& 2 === count( $lead['participants'] ), 'Lead detail/participant size incorrect' );
$assert( [
	[ 'party_type' => 'contact', 'party_id' => $contact, 'role' => 'primary_contact' ],
	[ 'party_type' => 'organization', 'party_id' => $org, 'role' => 'decision_maker' ],
] === $lead['participants'], 'participant projection not reference-only' );
$assert( ! array_key_exists( 'source_reference', $lead['record'] ), 'Lead source_reference leaked' );
$deal_detail = StaffProjections::detail( 'opportunity', $deal );
$assert( is_array( $deal_detail ) && 'won' === $deal_detail['record']['state']
	&& 19900 === $deal_detail['record']['amount_minor']
	&& 'EUR' === $deal_detail['record']['currency']
	&& ! empty( $deal_detail['record']['won_at'] ) && null === $deal_detail['record']['lost_at'],
	'Opportunity scalar detail missing or outcome inconsistent' );

$history_page1 = StaffProjections::history( 'lead', $lead_ids[4], 0, 2 );
$assert( is_array( $history_page1 ) && [ 'created', 'stage_changed' ] === array_column( $history_page1['rows'], 'event_type' )
	&& $history_page1['has_more'] && $history_page1['cursor'] > 0,
	'bounded Lead event history page one wrong' );
$history_page2 = StaffProjections::history( 'lead', $lead_ids[4], $history_page1['cursor'], 2 );
$assert( is_array( $history_page2 ) && [ 'lead_status_open_to_qualified' ] === array_column( $history_page2['rows'], 'event_type' )
	&& ! $history_page2['has_more'] && $history_page2['cursor'] > $history_page1['cursor'],
	'Lead event history cursor failed' );
$deal_history = StaffProjections::history( 'opportunity', $deal, 0, 2 );
$assert( is_array( $deal_history ) && [ 'created', 'won' ] === array_column( $deal_history['rows'], 'event_type' )
	&& 'open' === $deal_history['rows'][1]['from_outcome']
	&& 'won' === $deal_history['rows'][1]['to_outcome']
	&& $admin->ID === $deal_history['rows'][1]['actor_user_id'], 'Opportunity outcome audit projection wrong' );
$assert( 9 === count( $deal_history['rows'][0] ), 'unexpected audit payload exposed' );

// Explicit bounded projections must not mutate commercial records or event logs.
$assert( 3 === (int) $wpdb->get_var( $wpdb->prepare(
	"SELECT revision FROM {$leads_table} WHERE id = %d", $lead_ids[4]
) ), 'staff read modified Lead revision' );
$assert( 2 === (int) $wpdb->get_var( $wpdb->prepare(
	"SELECT COUNT(*) FROM {$events_table} WHERE entity_type = %s AND entity_id = %d", 'opportunity', $deal
) ), 'staff read appended unexpected Opportunity history' );
$assert( 2 === (int) $wpdb->get_var( $wpdb->prepare(
	"SELECT COUNT(*) FROM {$participants_table} WHERE entity_type = %s AND entity_id = %d", 'lead', $lead_ids[4]
) ), 'staff read modified canonical participant links' );

echo "PASS: real MariaDB C1 bounded staff Lead/Opportunity pages, filters, details and keyset audit history\n";

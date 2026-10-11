<?php
declare(strict_types=1);

/**
 * Wave 1C/C2 real WordPress & MariaDB facade acceptance.
 * Synthetic identities only; refuses any non-disposable database.
 */
if ( ! defined( 'WP_CLI' ) || ! WP_CLI
	|| ! defined( 'CB_CRM_INTEGRATION_DISPOSABLE' ) || ! CB_CRM_INTEGRATION_DISPOSABLE
	|| ! str_starts_with( (string) DB_NAME, 'cb_crm_it_' )
	|| ! str_contains( (string) DB_HOST, '127.0.0.1:3307' ) ) {
	fwrite( STDERR, "C2 staff action integration refuses a non-disposable database.\n" );
	exit( 1 );
}

use CB\CRM\Application\Sales\StaffActions;
use CB\CRM\Content\PostTypes;
use CB\CRM\Database\Schema;
use CB\CRM\Repository\Sales\StaffProjections;

global $wpdb;
$assert = static function ( bool $condition, string $message ): void {
	if ( ! $condition ) {
		fwrite( STDERR, "CRM C2 MariaDB FAIL: {$message}\n" );
		exit( 1 );
	}
};
$code = static fn ( mixed $result ): string => $result instanceof WP_Error ? $result->get_error_code() : '';
$admin = get_user_by( 'login', 'crm-it-admin' );
$assert( $admin instanceof WP_User, 'approved disposable Sales operator missing' );
wp_set_current_user( $admin->ID );
$assert( current_user_can( 'cb_manage_crm' ) && current_user_can( 'cb_manage_crm_sales' ), 'operator lacks CRM/Sales action capabilities' );

$pipelines = Schema::sales_pipelines_table();
$stages = Schema::sales_stages_table();
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
$deal_proposal = $stage( $deal_pipeline, 'proposal' );
$assert( $lead_new > 0 && $lead_connected > 0 && $deal_discovery > 0 && $deal_proposal > 0, 'default Sales stage lookup failed' );

$contact = wp_insert_post( [
	'post_type' => PostTypes::CONTACT,
	'post_status' => 'publish',
	'post_title' => 'Synthetic C2 Contact',
], true );
$organization = wp_insert_post( [
	'post_type' => PostTypes::ORGANIZATION,
	'post_status' => 'publish',
	'post_title' => 'Synthetic C2 Organization',
], true );
$assert( is_int( $contact ) && $contact > 0 && is_int( $organization ) && $organization > 0, 'synthetic CRM identities unavailable' );
$parties = [
	[ 'party_type' => 'contact', 'party_id' => $contact, 'role' => 'primary_contact' ],
	[ 'party_type' => 'organization', 'party_id' => $organization, 'role' => 'decision_maker' ],
];

$assert( 'crm_sales_invalid_input' === $code( StaffActions::create_lead(
	[ 'title' => 'Invalid Lead', 'pipeline_id' => $lead_pipeline, 'stage_id' => $lead_new, 'owner_user_id' => 9999 ],
	$parties
) ), 'caller-supplied owner metadata bypassed repository validation' );
$assert( 'crm_sales_invalid_input' === $code( StaffActions::create_opportunity(
	[ 'title' => 'Invalid Deal', 'pipeline_id' => $deal_pipeline, 'stage_id' => $deal_discovery, 'outcome' => 'won' ],
	$parties
) ), 'caller-supplied Won outcome bypassed repository validation' );
$assert( 'crm_sales_invalid_input' === $code( StaffActions::move_stage( 'unknown', 11, $lead_connected, 1 ) ), 'invalid record type bypassed transition contract' );
$assert( 'crm_sales_invalid_input' === $code( StaffActions::close_opportunity( 100, 'open', 1 ) ), 'closing with open outcome bypassed closing contract' );
$assert( 'crm_sales_not_found' === $code( StaffActions::qualify_lead( 99999999, 1 ) ), 'unknown Lead should remain not found' );

wp_set_current_user( 0 );
$assert( 'crm_sales_forbidden' === $code( StaffActions::create_lead(
	[ 'title' => 'Denied Lead', 'pipeline_id' => $lead_pipeline, 'stage_id' => $lead_new ], $parties
) ), 'anonymous facade Lead creation allowed' );
$assert( 'crm_sales_forbidden' === $code( StaffActions::convert_lead( 999, 1, $deal_pipeline, $deal_discovery ) ), 'anonymous conversion allowed' );
$assert( 'crm_sales_forbidden' === $code( StaffActions::close_opportunity( 999, 'won', 1 ) ), 'anonymous closing allowed' );
wp_set_current_user( $admin->ID );

// Full lifecycle through the facade only. Use C1 narrow read projections to
// verify state and event chronology; never read raw Sales row in this fixture.
$lead = StaffActions::create_lead(
	[ 'title' => 'Synthetic C2 qualified Lead', 'pipeline_id' => $lead_pipeline, 'stage_id' => $lead_new ],
	$parties
);
$assert( is_int( $lead ) && $lead > 0, 'facade could not create Lead' );
$assert( 2 === StaffActions::move_stage( 'lead', $lead, $lead_connected, 1 ), 'facade did not move Lead stage' );
$assert( 'crm_sales_conflict' === $code( StaffActions::qualify_lead( $lead, 1 ) ), 'stale revision was not passed to the repository' );
$assert( 3 === StaffActions::qualify_lead( $lead, 2 ), 'facade qualification did not commit' );
$assert( 'crm_sales_invalid_transition' === $code( StaffActions::qualify_lead( $lead, 3 ) ), 'duplicate qualification accepted' );
$assert( 'crm_sales_invalid_pipeline' === $code( StaffActions::convert_lead( $lead, 3, $lead_pipeline, $lead_new ) ),
	'Lead pipeline was accepted for converted Opportunity' );
$converted = StaffActions::convert_lead( $lead, 3, $deal_pipeline, $deal_discovery );
$assert( is_int( $converted ) && $converted > 0, 'facade conversion did not create Opportunity' );
$assert( $converted === StaffActions::convert_lead( $lead, 3, $deal_pipeline, $deal_discovery ), 'converted Lead replay created different Opportunity' );
$assert( $converted === StaffActions::convert_lead( $lead, 999, $deal_pipeline, $deal_discovery ), 'stale post-conversion replay changed semantics' );
$assert( 'crm_sales_invalid_transition' === $code( StaffActions::disqualify_lead( $lead, 4 ) ), 'converted Lead was allowed to disqualify' );
$assert( 'crm_sales_invalid_transition' === $code( StaffActions::move_stage( 'lead', $lead, $lead_new, 4 ) ), 'converted Lead stage mutated' );

$lead_detail = StaffProjections::detail( 'lead', $lead );
$converted_detail = StaffProjections::detail( 'opportunity', $converted );
$assert( is_array( $lead_detail ) && is_array( $converted_detail )
	&& 'converted' === $lead_detail['record']['state']
	&& $converted === $lead_detail['record']['converted_opportunity_id']
	&& 4 === $lead_detail['record']['revision']
	&& $lead === $converted_detail['record']['source_lead_id']
	&& 'open' === $converted_detail['record']['state']
	&& 2 === count( $converted_detail['participants'] ),
	'facade conversion or participant links not preserved in C1 projection'
);

$disqualified = StaffActions::create_lead(
	[ 'title' => 'Synthetic C2 disqualified Lead', 'pipeline_id' => $lead_pipeline, 'stage_id' => $lead_new ],
	$parties
);
$assert( is_int( $disqualified ) && $disqualified > 0, 'facade disqualified fixture create failed' );
$assert( 2 === StaffActions::disqualify_lead( $disqualified, 1 ), 'facade disqualification failed' );
$assert( 'crm_sales_invalid_transition' === $code( StaffActions::convert_lead(
	$disqualified, 2, $deal_pipeline, $deal_discovery
) ), 'disqualified Lead was converted' );

$direct = StaffActions::create_opportunity(
	[
		'title' => 'Synthetic C2 direct Deal',
		'pipeline_id' => $deal_pipeline,
		'stage_id' => $deal_discovery,
		'amount_minor' => 12345,
		'currency' => 'EUR',
	],
	$parties
);
$assert( is_int( $direct ) && $direct > 0 && $direct !== $converted, 'facade direct Opportunity create failed' );
$assert( 2 === StaffActions::move_stage( 'opportunity', $direct, $deal_proposal, 1 ), 'facade Opportunity stage move failed' );
$assert( 'crm_sales_conflict' === $code( StaffActions::close_opportunity( $direct, 'won', 1 ) ), 'facade stale close accepted' );
$assert( 3 === StaffActions::close_opportunity( $direct, 'won', 2 ), 'facade close Won failed' );
$assert( 'crm_sales_invalid_transition' === $code( StaffActions::move_stage( 'opportunity', $direct, $deal_discovery, 3 ) ), 'closed Opportunity stage changed' );
$assert( 'crm_sales_invalid_transition' === $code( StaffActions::close_opportunity( $direct, 'lost', 3 ) ), 'direct Won-to-Lost accepted' );
$assert( 4 === StaffActions::reopen_opportunity( $direct, 3 ), 'facade explicit Reopen failed' );
$assert( 'crm_sales_invalid_transition' === $code( StaffActions::reopen_opportunity( $direct, 4 ) ), 'duplicate Reopen accepted' );
$assert( 5 === StaffActions::close_opportunity( $direct, 'lost', 4 ), 'facade close Lost after reopen failed' );

$deal_detail = StaffProjections::detail( 'opportunity', $direct );
$assert( is_array( $deal_detail ) && 'lost' === $deal_detail['record']['state']
	&& $deal_proposal === $deal_detail['record']['stage_id']
	&& 5 === $deal_detail['record']['revision']
	&& 12345 === $deal_detail['record']['amount_minor']
	&& 'EUR' === $deal_detail['record']['currency']
	&& null === $deal_detail['record']['won_at']
	&& is_string( $deal_detail['record']['lost_at'] ) && '' !== $deal_detail['record']['lost_at'],
	'facade closed Deal projection inconsistent'
);

$lead_events = StaffProjections::history( 'lead', $lead, 0, 20 );
$deal_events = StaffProjections::history( 'opportunity', $direct, 0, 20 );
$assert( is_array( $lead_events ) && is_array( $deal_events ), 'C1 audit projection failed' );
$assert(
	[ 'created', 'stage_changed', 'lead_status_open_to_qualified', 'converted' ]
	=== array_column( $lead_events['rows'], 'event_type' ),
	'facade Lead lifecycle did not preserve complete audit history'
);
$assert(
	[ 'created', 'stage_changed', 'won', 'reopened', 'lost' ]
	=== array_column( $deal_events['rows'], 'event_type' ),
	'facade Opportunity outcome audit history incorrect'
);
$assert( $admin->ID === $deal_events['rows'][4]['actor_user_id']
	&& 5 === $deal_events['rows'][4]['revision']
	&& 'open' === $deal_events['rows'][4]['from_outcome']
	&& 'lost' === $deal_events['rows'][4]['to_outcome'],
	'facade outcome event provenance was changed'
);

// Assert the original repository failures remain typed through the facade.
$assert( 'crm_sales_conflict' === $code( StaffActions::reopen_opportunity( $direct, 4 ) ), 'stale Reopen conflict not preserved' );

echo "PASS: real MariaDB C2 guarded staff facade creation, qualification, conversion, stages and Won/Lost/Reopen with C1 audit\n";

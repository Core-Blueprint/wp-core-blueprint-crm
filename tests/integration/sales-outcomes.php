<?php
declare(strict_types=1);

/**
 * B3 real InnoDB acceptance, exclusively via the random disposable
 * WordPress/MariaDB environment provided by tools/check-sales-integration.
 */
if ( ! defined( 'WP_CLI' ) || ! WP_CLI
	|| ! defined( 'CB_CRM_INTEGRATION_DISPOSABLE' ) || ! CB_CRM_INTEGRATION_DISPOSABLE
	|| ! str_starts_with( (string) DB_NAME, 'cb_crm_it_' )
	|| ! str_contains( (string) DB_HOST, '127.0.0.1:3307' ) ) {
	fwrite( STDERR, "B3 integration refuses non-disposable MariaDB.\n" );
	exit( 1 );
}

use CB\CRM\Content\PostTypes;
use CB\CRM\Database\Schema;
use CB\CRM\Repository\Sales\Outcomes;
use CB\CRM\Repository\Sales\Records;
use CB\CRM\Repository\Sales\Transitions;

global $wpdb;
$assert = static function ( bool $ok, string $message ): void {
	if ( ! $ok ) {
		fwrite( STDERR, "CRM B3 MariaDB FAIL: {$message}\n" );
		exit( 1 );
	}
};
$code = static fn ( mixed $result ): string => $result instanceof WP_Error ? $result->get_error_code() : '';
$admin = get_user_by( 'login', 'crm-it-admin' );
$assert( $admin instanceof WP_User, 'disposable approved operator unavailable' );
wp_set_current_user( $admin->ID );
$assert( current_user_can( 'cb_manage_crm' ) && current_user_can( 'cb_manage_crm_sales' ), 'Sales manager not authorized' );

$pipelines = Schema::sales_pipelines_table();
$stages = Schema::sales_stages_table();
$deals = Schema::sales_opportunities_table();
$events = Schema::sales_events_table();
$deal_pipeline = (int) $wpdb->get_var( $wpdb->prepare(
	"SELECT id FROM {$pipelines} WHERE pipeline_key = %s", 'default_opportunities'
) );
$discovery = (int) $wpdb->get_var( $wpdb->prepare(
	"SELECT id FROM {$stages} WHERE pipeline_id = %d AND stage_key = %s", $deal_pipeline, 'discovery'
) );
$proposal = (int) $wpdb->get_var( $wpdb->prepare(
	"SELECT id FROM {$stages} WHERE pipeline_id = %d AND stage_key = %s", $deal_pipeline, 'proposal'
) );
$assert( $deal_pipeline > 0 && $discovery > 0 && $proposal > 0, 'default Opportunity pipeline unavailable' );

$contact = wp_insert_post( [
	'post_type' => PostTypes::CONTACT,
	'post_status' => 'publish',
	'post_title' => 'Synthetic B3 Contact, no real personal data',
], true );
$assert( is_int( $contact ) && $contact > 0, 'could not create synthetic Contact' );
$participant = [ [ 'party_type' => 'contact', 'party_id' => $contact, 'role' => 'primary_contact' ] ];
$deal = Records::create_opportunity(
	[ 'title' => 'Synthetic B3 outcome history', 'pipeline_id' => $deal_pipeline, 'stage_id' => $discovery ],
	$participant
);
$assert( is_int( $deal ) && $deal > 0, 'could not create Opportunity' );

wp_set_current_user( 0 );
$assert( 'crm_sales_forbidden' === $code( Outcomes::close( $deal, 'won', 1 ) ), 'anonymous closure accepted' );
wp_set_current_user( $admin->ID );

$assert( 'crm_sales_invalid_input' === $code( Outcomes::close( $deal, 'open', 1 ) ), 'close accepted open status' );
$assert( 'crm_sales_invalid_transition' === $code( Outcomes::reopen( $deal, 1 ) ), 'open Deal reopened' );
$assert( 2 === Outcomes::close( $deal, 'won', 1 ), 'closing Won did not commit' );
$assert( 'crm_sales_conflict' === $code( Outcomes::close( $deal, 'lost', 1 ) ), 'stale close accepted' );
$assert( 'crm_sales_invalid_transition' === $code( Outcomes::close( $deal, 'lost', 2 ) ), 'won-to-lost direct mutation accepted' );
$assert( 'crm_sales_invalid_transition' === $code( Transitions::move_stage( 'opportunity', $deal, $proposal, 2 ) ), 'terminal Won stage move accepted' );

$won_row = $wpdb->get_row( $wpdb->prepare(
	"SELECT outcome, won_at, lost_at, revision, stage_id FROM {$deals} WHERE id = %d", $deal
), ARRAY_A );
$assert( is_array( $won_row ) && 'won' === $won_row['outcome']
	&& ! empty( $won_row['won_at'] ) && null === $won_row['lost_at']
	&& 2 === (int) $won_row['revision'] && $discovery === (int) $won_row['stage_id'],
	'Won outcome/revision/timestamps/stage incorrect'
);

// Genuine rollback after the Opportunity UPDATE and before event persistence.
$trigger = 'cb_crm_it_b3_block_event';
$trigger_created = false;
try {
	$assert( false !== $wpdb->query(
		"CREATE TRIGGER {$trigger} BEFORE INSERT ON {$events} FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'B3 outcome fault injection'"
	), 'could not create isolated event trigger' );
	$trigger_created = true;
	$wpdb->suppress_errors( true );
	$failed = Outcomes::reopen( $deal, 2 );
	$wpdb->suppress_errors( false );
	$assert( 'crm_sales_storage_failed' === $code( $failed ), 'event failure did not abort Reopen' );
	$after_failure = $wpdb->get_row( $wpdb->prepare(
		"SELECT outcome, won_at, lost_at, revision FROM {$deals} WHERE id = %d", $deal
	), ARRAY_A );
	$assert( is_array( $after_failure ) && 'won' === $after_failure['outcome']
		&& $won_row['won_at'] === $after_failure['won_at']
		&& null === $after_failure['lost_at'] && 2 === (int) $after_failure['revision'],
		'event failure committed a partially reopened Deal'
	);
	$assert( 2 === (int) $wpdb->get_var( $wpdb->prepare(
		"SELECT COUNT(*) FROM {$events} WHERE entity_type = %s AND entity_id = %d", 'opportunity', $deal
	) ), 'event failure committed an extra audit event' );
} finally {
	$wpdb->suppress_errors( false );
	if ( $trigger_created ) { $wpdb->query( "DROP TRIGGER IF EXISTS {$trigger}" ); }
}

$assert( 3 === Outcomes::reopen( $deal, 2 ), 'reopen after rollback did not commit' );
$reopened = $wpdb->get_row( $wpdb->prepare(
	"SELECT outcome, won_at, lost_at, revision, stage_id FROM {$deals} WHERE id = %d", $deal
), ARRAY_A );
$assert( is_array( $reopened ) && 'open' === $reopened['outcome']
	&& null === $reopened['won_at'] && null === $reopened['lost_at']
	&& 3 === (int) $reopened['revision'] && $discovery === (int) $reopened['stage_id'],
	'Reopen did not clear current closure markers while keeping pipeline stage'
);
$assert( 4 === Transitions::move_stage( 'opportunity', $deal, $proposal, 3 ), 'reopened Deal could not change stage' );
$assert( 5 === Outcomes::close( $deal, 'lost', 4 ), 'closing Lost did not commit' );
$assert( 'crm_sales_invalid_transition' === $code( Outcomes::close( $deal, 'won', 5 ) ), 'Lost could not remain terminal' );
$lost = $wpdb->get_row( $wpdb->prepare(
	"SELECT outcome, won_at, lost_at, revision, stage_id FROM {$deals} WHERE id = %d", $deal
), ARRAY_A );
$assert( is_array( $lost ) && 'lost' === $lost['outcome']
	&& null === $lost['won_at'] && ! empty( $lost['lost_at'] )
	&& 5 === (int) $lost['revision'] && $proposal === (int) $lost['stage_id'],
	'Lost outcome/revision/timestamps/stage incorrect'
);
$assert( 6 === Outcomes::reopen( $deal, 5 ), 'Lost Opportunity did not reopen' );
$assert( 7 === Outcomes::close( $deal, 'won', 6 ), 'reopened Lost Deal could not be Won' );

$rows = $wpdb->get_results( $wpdb->prepare(
	"SELECT event_type, from_outcome, to_outcome, revision, actor_user_id FROM {$events}
	WHERE entity_type = %s AND entity_id = %d ORDER BY revision",
	'opportunity', $deal
), ARRAY_A );
$assert( is_array( $rows ) && 7 === count( $rows ), 'audit history event count incorrect' );
$assert(
	[ 'created', 'won', 'reopened', 'stage_changed', 'lost', 'reopened', 'won' ]
	=== array_column( $rows, 'event_type' ),
	'event chronology missing Won/Lost/Reopen'
);
$expected_pairs = [
	[ '', 'open' ],
	[ 'open', 'won' ],
	[ 'won', 'open' ],
	[ '', '' ],
	[ 'open', 'lost' ],
	[ 'lost', 'open' ],
	[ 'open', 'won' ],
];
foreach ( $rows as $i => $event ) {
	$assert( $i + 1 === (int) $event['revision'], 'event revision sequence incorrect' );
	$assert( $admin->ID === (int) $event['actor_user_id'], 'event actor not canonical' );
	$assert( $expected_pairs[$i] === [ $event['from_outcome'], $event['to_outcome'] ], 'event from/to outcome history corrupted' );
}
echo "PASS: real MariaDB B3 Won/Lost/Reopen, revision, immutable event chronology and rollback\n";

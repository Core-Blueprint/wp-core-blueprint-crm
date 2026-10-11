<?php
declare(strict_types=1);

/**
 * Real WordPress 7 / MariaDB 10.11 single-site deletion and recovery fixture.
 * Invoke only through the disposable fresh-install integration runner.
 */
if ( ! defined( 'WP_CLI' ) || ! WP_CLI
	|| ! defined( 'CB_CRM_INTEGRATION_DISPOSABLE' ) || true !== CB_CRM_INTEGRATION_DISPOSABLE
	|| ! defined( 'DB_NAME' ) || ! preg_match( '/^cb_crm_it_[a-z0-9]{8}$/D', DB_NAME )
	|| ! defined( 'DB_HOST' ) || '127.0.0.1:3307' !== DB_HOST
	|| ! defined( 'ABSPATH' ) || ! str_starts_with( ABSPATH, '/tmp/core-blueprint-tests/core-blueprint-crm-sales/run.' )
	|| is_multisite()
) {
	fwrite( STDERR, "CRM user anonymization integration refuses non-disposable/multisite environment.\n" );
	exit( 1 );
}

use CB\CRM\Database\Schema;
use CB\CRM\Repository\Sales\Records;
use CB\CRM\Repository\UserAnonymizationRecovery;

global $wpdb;
$fail = static function ( string $message ): never {
	fwrite( STDERR, 'CRM Sales anonymization integration FAIL: ' . $message . "\n" );
	exit( 1 );
};
$assert = static function ( bool $ok, string $message ) use ( $fail ): void {
	if ( ! $ok ) { $fail( $message ); }
};
$assert( '1.6' === (string) get_option( Schema::OPTION, '' ), 'schema 1.6 not ready' );
$actor = get_user_by( 'login', 'crm-it-admin' );
$assert( $actor instanceof WP_User, 'disposable privileged test actor missing' );
wp_set_current_user( (int) $actor->ID );
$assert( current_user_can( 'cb_manage_crm_sales' ), 'Sales test actor not approved by Base' );

$nonce = substr( DB_NAME, -8 );
$target = wp_create_user( 'crm-recovery-' . $nonce, bin2hex( random_bytes( 20 ) ), 'recovery-' . $nonce . '@example.invalid' );
$control = wp_create_user( 'crm-control-' . $nonce, bin2hex( random_bytes( 20 ) ), 'control-' . $nonce . '@example.invalid' );
$assert( is_int( $target ) && $target > 0, 'unable to create disposable deleted test login' );
$assert( is_int( $control ) && $control > 0 && $control !== $target, 'unable to create distinct disposable control login' );
$contact = wp_insert_post( [
	'post_type' => 'cb_crm_contact',
	'post_status' => 'publish',
	'post_title' => 'Synthetic Sale Participant - no actual personal information',
], true );
$assert( is_int( $contact ) && $contact > 0, 'could not create test-only CRM identity' );

$pipeline = $wpdb->get_row( $wpdb->prepare(
	'SELECT id FROM ' . Schema::sales_pipelines_table() . ' WHERE pipeline_key = %s', 'default_leads'
), ARRAY_A );
$assert( is_array( $pipeline ), 'missing Sales Lead pipeline' );
$stage = $wpdb->get_row( $wpdb->prepare(
	'SELECT id FROM ' . Schema::sales_stages_table() . ' WHERE pipeline_id = %d AND stage_key = %s',
	(int) $pipeline['id'], 'new'
), ARRAY_A );
$assert( is_array( $stage ), 'missing default Lead stage' );
$parameters = [ 'title' => 'Synthetic deleted staff Lead', 'pipeline_id' => (int) $pipeline['id'], 'stage_id' => (int) $stage['id'] ];
$participants = [ [ 'party_type' => 'contact', 'party_id' => $contact ] ];
$lead_id = Records::create_lead( $parameters, $participants );
$control_id = Records::create_lead( [ 'title' => 'Synthetic control Lead', 'pipeline_id' => (int) $pipeline['id'], 'stage_id' => (int) $stage['id'] ], $participants );
$assert( is_int( $lead_id ) && $lead_id > 0, 'target synthetic Lead creation failed' );
$assert( is_int( $control_id ) && $control_id > 0, 'control synthetic Lead creation failed' );

$leads_table = Schema::sales_leads_table();
$events_table = Schema::sales_events_table();
$assert( false !== $wpdb->update( $leads_table, [ 'owner_user_id' => $target ], [ 'id' => $lead_id ], [ '%d' ], [ '%d' ] ), 'target owner fixture failed' );
$assert( false !== $wpdb->update( $leads_table, [ 'owner_user_id' => $control ], [ 'id' => $control_id ], [ '%d' ], [ '%d' ] ), 'control owner fixture failed' );
$assert( false !== $wpdb->update( $events_table, [ 'actor_user_id' => $target ], [ 'entity_type' => 'lead', 'entity_id' => $lead_id ], [ '%d' ], [ '%s', '%d' ] ), 'target actor fixture failed' );
$assert( false !== $wpdb->update( $events_table, [ 'actor_user_id' => $control ], [ 'entity_type' => 'lead', 'entity_id' => $control_id ], [ '%d' ], [ '%s', '%d' ] ), 'control actor fixture failed' );

$owner = static fn ( int $id ): int => (int) $wpdb->get_var(
	$wpdb->prepare( "SELECT owner_user_id FROM {$leads_table} WHERE id = %d", $id )
);
$event_actor = static fn ( int $id ): int => (int) $wpdb->get_var(
	$wpdb->prepare( "SELECT actor_user_id FROM {$events_table} WHERE entity_type = %s AND entity_id = %d", 'lead', $id )
);
$assert( $target === $owner( $lead_id ) && $target === $event_actor( $lead_id ), 'target fixture failed to create identifying refs' );
$assert( $control === $owner( $control_id ) && $control === $event_actor( $control_id ), 'control fixture cannot be tested' );

require_once ABSPATH . 'wp-admin/includes/user.php';
$trigger = 'cb_crm_it_fail_user_anonymize';
$installed = false;
try {
	$wpdb->last_error = '';
	$result = $wpdb->query(
		"CREATE TRIGGER {$trigger} BEFORE UPDATE ON {$events_table} FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'deliberate anonymisation rollback'"
	);
	$assert( false !== $result && '' === (string) $wpdb->last_error, 'unable to install disposable SQL failure trigger' );
	$installed = true;
	$wpdb->suppress_errors( true );
	$deleted = wp_delete_user( $target );
	$wpdb->suppress_errors( false );
	$assert( true === $deleted, 'WordPress rejected test login deletion' );
	$assert( null === $wpdb->get_var( $wpdb->prepare( "SELECT ID FROM {$wpdb->users} WHERE ID = %d LIMIT 1", $target ) ), 'target WordPress login still exists' );
	$key = 'cb_crm_user_anonymize_intent_' . $target;
	$assert( is_array( get_option( $key, null ) ), 'failed post-delete SQL did not retain durable intent' );
	$assert( $target === $owner( $lead_id ) && $target === $event_actor( $lead_id ), 'SQL rollback failed to preserve original atomic data' );
	$assert( $control === $owner( $control_id ) && $control === $event_actor( $control_id ), 'failed update mutated unrelated user refs' );
	echo "PASS: failed WordPress deleted_user SQL anonymization retained a durable intent and rolled back\n";
} finally {
	$wpdb->suppress_errors( false );
	if ( $installed ) {
		$wpdb->query( "DROP TRIGGER IF EXISTS {$trigger}" );
	}
}

$assert( UserAnonymizationRecovery::retry( $target ), 'recovery after SQL failure did not complete' );
$assert( 0 === $owner( $lead_id ) && 0 === $event_actor( $lead_id ), 'retry did not anonymize both user references' );
$assert( $control === $owner( $control_id ) && $control === $event_actor( $control_id ), 'retry altered unrelated user references' );
$assert( false === get_option( 'cb_crm_user_anonymize_intent_' . $target, false ), 'successful retry did not acknowledge intent' );
$assert( UserAnonymizationRecovery::retry( $target ), 'duplicate retry was not idempotent' );
$assert( 0 === $owner( $lead_id ) && 0 === $event_actor( $lead_id ), 'duplicate retry changed an already minimized row' );
$assert( get_post_type( $contact ) === 'cb_crm_contact', 'WordPress login deletion unexpectedly removed canonical CRM Contact' );
echo "PASS: replay minimized Sales owner/actor IDs, preserved unrelated refs and retained CRM Contact\n";
echo "CRM Sales durable deleted-user anonymization WordPress/MariaDB integration: PASS\n";

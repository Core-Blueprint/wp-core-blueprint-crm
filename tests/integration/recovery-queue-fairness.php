<?php
declare(strict_types=1);

/**
 * Real WP options and cron fairness with >20 inert/malformed oldest intents.
 * This fixture cannot run on anything except a fresh isolated test WordPress.
 */
if ( ! defined( 'WP_CLI' ) || ! WP_CLI
	|| ! defined( 'CB_CRM_INTEGRATION_DISPOSABLE' ) || true !== CB_CRM_INTEGRATION_DISPOSABLE
	|| ! defined( 'DB_NAME' ) || ! preg_match( '/^cb_crm_it_[a-z0-9]{8}$/D', DB_NAME )
	|| ! defined( 'DB_HOST' ) || '127.0.0.1:3307' !== DB_HOST
	|| ! defined( 'ABSPATH' ) || ! str_starts_with( ABSPATH, '/tmp/core-blueprint-tests/core-blueprint-crm-sales/run.' )
	|| is_multisite()
) {
	fwrite( STDERR, "CRM recovery scheduler fairness: refusing a non-disposable WordPress environment.\n" );
	exit( 1 );
}
$phase = getenv( 'CB_CRM_FAIRNESS_PHASE' );
if ( ! in_array( $phase, [ 'seed', 'verify' ], true ) ) {
	fwrite( STDERR, "CRM recovery scheduler fairness: phase invalid.\n" );
	exit( 1 );
}
$assert = static function ( bool $ok, string $msg ): void {
	if ( ! $ok ) {
		fwrite( STDERR, 'CRM recovery scheduler fairness FAIL: ' . $msg . "\n" );
		exit( 1 );
	}
};

$prefixes = [
	'identity' => [ 'cb_crm_delete_intent_', 'cb_crm_lifecycle_reconcile', 'cb_crm_recovery_cursor_identity' ],
	'user' => [ 'cb_crm_user_anonymize_intent_', 'cb_crm_user_anonymize_reconcile', 'cb_crm_recovery_cursor_user' ],
];
if ( 'seed' === $phase ) {
	foreach ( $prefixes as $queue => [ $prefix ] ) {
		for ( $n = 0; $n < 45; ++$n ) {
			$id = ( 'user' === $queue ? 710000000 : 720000000 ) + $n;
			// The oldest synthetic intent is intentionally malformed. It must
			// not prevent cursor progress through the remaining 44 valid ones.
			$value = 0 === $n
				? [ 'version' => 99, 'id' => $id ]
				: ( 'user' === $queue
					? [ 'version' => 1, 'user_id' => $id ]
					: [ 'version' => 1, 'type' => 'contact', 'id' => $id ] );
			$assert( add_option( $prefix . $id, $value, '', false ), 'could not seed synthetic option' );
		}
	}
	echo "PASS: 90 synthetic WordPress option intents seeded, including two permanently invalid first entries\n";
	return;
}

$admin = get_user_by( 'login', 'crm-it-admin' );
$assert( $admin instanceof WP_User, 'Base-approved test administrator absent' );

\CB\CRM\Repository\LifecycleRecovery::reschedule_pending();
\CB\CRM\Repository\UserAnonymizationRecovery::reschedule_pending();
foreach ( $prefixes as $queue => [ $prefix, $hook, $cursor_key ] ) {
	$assert( false === get_option( $cursor_key, false ), 'unauthorized request changed ' . $queue . ' cursor' );
	$assert( false === wp_next_scheduled( $hook, [ ( 'user' === $queue ? 710000044 : 720000044 ) ] ), 'unauthorized request scheduled recovery' );
}
wp_set_current_user( (int) $admin->ID );
$assert( current_user_can( 'cb_manage_crm' ), 'operator is not approved for CRM recovery' );

foreach ( [ 1, 2, 3 ] as $pass ) {
	\CB\CRM\Repository\LifecycleRecovery::reschedule_pending();
	\CB\CRM\Repository\UserAnonymizationRecovery::reschedule_pending();
	foreach ( $prefixes as $queue => [ $prefix, $hook, $cursor_key ] ) {
		$id = ( 'user' === $queue ? 710000000 : 720000000 );
		$last = $id + 44;
		if ( 1 === $pass ) {
			$assert( false === wp_next_scheduled( $hook, [ $last ] ), $queue . ' exceeded first page bound' );
		} elseif ( 2 === $pass ) {
			$assert( false === wp_next_scheduled( $hook, [ $last ] ), $queue . ' exceeded second page bound' );
		} else {
			$assert( false !== wp_next_scheduled( $hook, [ $last ] ), $queue . ' starved option beyond 40 entries' );
		}
		$assert( (int) get_option( $cursor_key, 0 ) > 0, $queue . ' cursor was not saved' );
		$assert( is_array( get_option( $prefix . $id, false ) ), $queue . ' invalid oldest entry was silently removed' );
		$assert( false === wp_next_scheduled( $hook, [ $id ] ), $queue . ' invalid first entry was scheduled' );
	}
}
foreach ( $prefixes as $queue => [ $prefix, $hook, $cursor_key ] ) {
	$before = (int) get_option( $cursor_key, 0 );
	( 'user' === $queue
		? \CB\CRM\Repository\UserAnonymizationRecovery::reschedule_pending()
		: \CB\CRM\Repository\LifecycleRecovery::reschedule_pending() );
	$assert( (int) get_option( $cursor_key, 0 ) < $before, $queue . ' cursor failed to wrap after exhaust' );
	$assert( false === wp_next_scheduled( $hook, [ ( 'user' === $queue ? 710000000 : 720000000 ) ] ), 'malformed first intent scheduled during wrap' );
}
echo "CRM both recovery queues real WordPress pagination and non-starvation: PASS\n";

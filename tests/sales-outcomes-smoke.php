<?php
declare(strict_types=1);

/** Deterministic B3 outcome persistence tests, no live WP/DB writes. */
$root = dirname( __DIR__ );
define( 'ABSPATH', $root . '/' );
define( 'ARRAY_A', 'ARRAY_A' );
define( 'CB_CRM_SCHEMA_VERSION', '1.6' );

final class WP_Error {
	public function __construct( private string $code, private string $message = '' ) {}
	public function get_error_code(): string { return $this->code; }
}
$GLOBALS['b3_ready'] = false;
$GLOBALS['b3_caps'] = [];
$GLOBALS['b3_actor'] = 17;
$GLOBALS['b3_schema'] = '1.6';
function cb_crm_runtime_ready(): bool { return $GLOBALS['b3_ready']; }
function current_user_can( string $capability ): bool { return in_array( $capability, $GLOBALS['b3_caps'], true ); }
function get_current_user_id(): int { return $GLOBALS['b3_actor']; }
function get_option( string $key, mixed $default = '' ): mixed {
	return 'cb_crm_db_version' === $key ? $GLOBALS['b3_schema'] : $default;
}
function current_time( string $format, bool $gmt = false ): string {
	if ( 'mysql' !== $format || ! $gmt ) { throw new RuntimeException( 'Expected UTC date.' ); }
	return '2026-10-11 17:00:00';
}

final class B3_Fake_Wpdb {
	public string $prefix = 'wp_';
	public string $last_error = '';
	public int $queries = 0;
	public bool $fail_read = false;
	public bool $fail_update = false;
	public bool $stale_update = false;
	public bool $fail_insert = false;
	public bool $fail_commit = false;
	public array $opportunities = [
		2 => [ 'id' => 2, 'stage_id' => 21, 'outcome' => 'open', 'won_at' => null, 'lost_at' => null, 'revision' => 1 ],
		3 => [ 'id' => 3, 'stage_id' => 22, 'outcome' => 'won', 'won_at' => '2026-10-10 10:00:00', 'lost_at' => null, 'revision' => 4 ],
		4 => [ 'id' => 4, 'stage_id' => 23, 'outcome' => 'lost', 'won_at' => null, 'lost_at' => '2026-10-10 11:00:00', 'revision' => 5 ],
		5 => [ 'id' => 5, 'stage_id' => 23, 'outcome' => 'won', 'won_at' => null, 'lost_at' => null, 'revision' => 6 ],
		6 => [ 'id' => 6, 'stage_id' => 23, 'outcome' => 'open', 'won_at' => '2026-10-10 10:00:00', 'lost_at' => null, 'revision' => 1 ],
	];
	public array $events = [];
	private ?array $snapshot = null;

	public function prepare( string $sql, mixed ...$values ): string {
		foreach ( $values as $value ) {
			if ( ! is_int( $value ) || ! str_contains( $sql, '%d' ) ) {
				throw new RuntimeException( 'Unprepared numeric SQL' );
			}
			$sql = preg_replace( '/%d/', (string) $value, $sql, 1 ) ?? '';
		}
		return $sql;
	}
	public function get_row( string $sql, string $format ): ?array {
		++$this->queries;
		if ( ARRAY_A !== $format || ! str_contains( $sql, 'FOR UPDATE' )
			|| ! str_contains( $sql, 'cb_crm_sales_opportunities' )
			|| ! preg_match( '/WHERE id = (\d+) LIMIT 1/', $sql, $m ) ) {
			throw new RuntimeException( 'Unexpected or unlocked Opportunity SELECT.' );
		}
		if ( $this->fail_read ) {
			$this->last_error = 'injected SELECT failure';
			return null;
		}
		return $this->opportunities[ (int) $m[1] ] ?? null;
	}
	public function update( string $table, array $values, array $where, array $formats, array $where_formats ): int|false {
		++$this->queries;
		if ( ! str_contains( $table, 'cb_crm_sales_opportunities' )
			|| count( $values ) !== count( $formats ) || count( $where ) !== count( $where_formats ) ) {
			throw new RuntimeException( 'Unexpected SQL UPDATE formats.' );
		}
		if ( $this->fail_update ) { return false; }
		if ( $this->stale_update ) { return 0; }
		$id = $where['id'];
		if ( ! isset( $this->opportunities[$id] ) ) { return 0; }
		foreach ( $where as $key => $value ) {
			if ( $this->opportunities[$id][$key] !== $value ) { return 0; }
		}
		$this->opportunities[$id] = array_replace( $this->opportunities[$id], $values );
		return 1;
	}
	public function insert( string $table, array $data, array $formats ): int|false {
		++$this->queries;
		if ( ! str_contains( $table, 'cb_crm_sales_events' ) || count( $data ) !== count( $formats ) ) {
			throw new RuntimeException( 'Unexpected Sales event insert.' );
		}
		if ( $this->fail_insert ) { return false; }
		$this->events[] = $data;
		return 1;
	}
	public function query( string $sql ): int|false {
		++$this->queries;
		switch ( $sql ) {
			case 'START TRANSACTION':
				$this->snapshot = [ $this->opportunities, $this->events ];
				return 1;
			case 'ROLLBACK':
				if ( null !== $this->snapshot ) {
					[ $this->opportunities, $this->events ] = $this->snapshot;
				}
				$this->snapshot = null;
				return 1;
			case 'COMMIT':
				if ( $this->fail_commit ) { return false; }
				$this->snapshot = null;
				return 1;
			default: throw new RuntimeException( 'Unexpected transaction SQL.' );
		}
	}
}
$GLOBALS['wpdb'] = new B3_Fake_Wpdb();
require $root . '/src/Capabilities.php';
require $root . '/src/Database/Schema.php';
require $root . '/src/Domain/Sales/Access.php';
require $root . '/src/Domain/Sales/Rules.php';
require $root . '/src/Repository/Sales/Outcomes.php';

use CB\CRM\Repository\Sales\Outcomes;

$assert = static function ( bool $condition, string $message ): void {
	if ( ! $condition ) {
		fwrite( STDERR, "CRM B3 outcome smoke FAIL: {$message}\n" );
		exit( 1 );
	}
};
$code = static fn ( mixed $result ): string => $result instanceof WP_Error ? $result->get_error_code() : '';
$wpdb = $GLOBALS['wpdb'];

$assert( 'crm_sales_forbidden' === $code( Outcomes::close( 2, 'won', 1 ) ), 'unready runtime blocked' );
$assert( 0 === $wpdb->queries, 'unready request made no SQL query' );
$GLOBALS['b3_ready'] = true;
$assert( 'crm_sales_forbidden' === $code( Outcomes::close( 2, 'won', 1 ) ), 'missing capabilities blocked' );
$GLOBALS['b3_caps'] = [ 'cb_manage_crm', 'cb_view_crm_sales' ];
$assert( 'crm_sales_forbidden' === $code( Outcomes::close( 2, 'won', 1 ) ), 'viewer cannot change outcome' );
$GLOBALS['b3_caps'] = [ 'cb_manage_crm_sales' ];
$assert( 'crm_sales_forbidden' === $code( Outcomes::close( 2, 'won', 1 ) ), 'Sales manager without canonical CRM denied' );
$GLOBALS['b3_caps'] = [ 'cb_manage_crm', 'cb_manage_crm_sales' ];
$GLOBALS['b3_schema'] = '1.5';
$assert( 'crm_sales_forbidden' === $code( Outcomes::close( 2, 'won', 1 ) ), 'wrong schema denied' );
$GLOBALS['b3_schema'] = '1.6';
$GLOBALS['b3_actor'] = 0;
$assert( 'crm_sales_forbidden' === $code( Outcomes::close( 2, 'won', 1 ) ), 'anonymous denied' );
$GLOBALS['b3_actor'] = 17;

$assert( 'crm_sales_invalid_input' === $code( Outcomes::close( 2, 'open', 1 ) ), 'open cannot be a close action' );
$assert( 'crm_sales_invalid_input' === $code( Outcomes::close( 2, 'converted', 1 ) ), 'Lead state not accepted' );
$assert( 'crm_sales_invalid_input' === $code( Outcomes::close( 0, 'won', 1 ) ), 'zero ID refused' );
$assert( 'crm_sales_invalid_input' === $code( Outcomes::reopen( 2, 0 ) ), 'zero revision refused' );
$assert( 'crm_sales_not_found' === $code( Outcomes::close( 999, 'won', 1 ) ), 'missing record' );
$assert( 'crm_sales_conflict' === $code( Outcomes::close( 2, 'won', 2 ) ), 'stale revision' );
$assert( 'crm_sales_invalid_transition' === $code( Outcomes::reopen( 2, 1 ) ), 'cannot reopen open Opportunity' );
$assert( 'crm_sales_invalid_transition' === $code( Outcomes::close( 3, 'lost', 4 ) ), 'cannot change won directly to lost' );
$assert( 'crm_sales_invalid_transition' === $code( Outcomes::close( 4, 'won', 5 ) ), 'cannot change lost directly to won' );
$assert( 'crm_sales_outcome_inconsistent' === $code( Outcomes::reopen( 5, 6 ) ), 'damaged closed-state timestamp denied' );
$assert( 'crm_sales_outcome_inconsistent' === $code( Outcomes::close( 6, 'won', 1 ) ), 'damaged open-state timestamp denied' );
$assert( [] === $wpdb->events, 'invalid transitions did not write audit events' );

$assert( 2 === Outcomes::close( 2, 'won', 1 ), 'closing as won successful' );
$assert( 'won' === $wpdb->opportunities[2]['outcome'], 'won outcome persisted' );
$assert( '2026-10-11 17:00:00' === $wpdb->opportunities[2]['won_at'] && null === $wpdb->opportunities[2]['lost_at'], 'won timestamp only' );
$assert( 21 === $wpdb->opportunities[2]['stage_id'], 'commercial closing preserves stage' );
$assert( 'won' === $wpdb->events[0]['event_type'] && 'open' === $wpdb->events[0]['from_outcome'] && 'won' === $wpdb->events[0]['to_outcome'], 'won audit from/to' );
$assert( 17 === $wpdb->events[0]['actor_user_id'] && 2 === $wpdb->events[0]['revision'], 'trusted actor and new revision logged' );
$assert( 'crm_sales_conflict' === $code( Outcomes::reopen( 2, 1 ) ), 'old revision cannot reopen' );
$assert( 'crm_sales_invalid_transition' === $code( Outcomes::close( 2, 'won', 2 ) ), 'duplicate won forbidden' );
$assert( 3 === Outcomes::reopen( 2, 2 ), 'explicit reopening of won successful' );
$assert( 'open' === $wpdb->opportunities[2]['outcome'], 'reopened outcome persisted' );
$assert( null === $wpdb->opportunities[2]['won_at'] && null === $wpdb->opportunities[2]['lost_at'], 'reopening clears active close dates' );
$assert( 'reopened' === $wpdb->events[1]['event_type'] && 'won' === $wpdb->events[1]['from_outcome'] && 'open' === $wpdb->events[1]['to_outcome'], 'reopen event preserves historical won' );
$assert( 4 === Outcomes::close( 2, 'lost', 3 ), 'reopened can close as lost' );
$assert( null === $wpdb->opportunities[2]['won_at'] && '2026-10-11 17:00:00' === $wpdb->opportunities[2]['lost_at'], 'lost timestamp only' );
$assert( 'lost' === $wpdb->events[2]['event_type'] && 'open' === $wpdb->events[2]['from_outcome'], 'lost event stored' );
$assert( 6 === Outcomes::reopen( 4, 5 ), 'explicit reopening of lost succeeds' );
$assert( 'lost' === $wpdb->events[3]['from_outcome'], 'lost history preserved after reopen' );
$assert( 4 === count( $wpdb->events ), 'exactly one event per successful transition' );

$before = serialize( [ $wpdb->opportunities, $wpdb->events ] );
$wpdb->fail_insert = true;
$assert( 'crm_sales_storage_failed' === $code( Outcomes::reopen( 2, 4 ) ), 'event failure aborts outcome' );
$assert( $before === serialize( [ $wpdb->opportunities, $wpdb->events ] ), 'event insert failure rolls back outcome' );
$wpdb->fail_insert = false;
$wpdb->fail_update = true;
$assert( 'crm_sales_storage_failed' === $code( Outcomes::reopen( 2, 4 ) ), 'update failure aborted' );
$assert( $before === serialize( [ $wpdb->opportunities, $wpdb->events ] ), 'update failure unchanged' );
$wpdb->fail_update = false;
$wpdb->stale_update = true;
$assert( 'crm_sales_conflict' === $code( Outcomes::reopen( 2, 4 ) ), 'write-time revision mismatch rejected' );
$assert( $before === serialize( [ $wpdb->opportunities, $wpdb->events ] ), 'write conflict unchanged' );
$wpdb->stale_update = false;
$wpdb->fail_commit = true;
$assert( 'crm_sales_storage_failed' === $code( Outcomes::reopen( 2, 4 ) ), 'commit failure aborted' );
$assert( $before === serialize( [ $wpdb->opportunities, $wpdb->events ] ), 'commit failure rolled back' );
$wpdb->fail_commit = false;
$wpdb->fail_read = true;
$assert( 'crm_sales_storage_failed' === $code( Outcomes::reopen( 2, 4 ) ), 'locked read failure refused' );
$assert( $before === serialize( [ $wpdb->opportunities, $wpdb->events ] ), 'locked read failure unchanged' );

echo "CRM Sales B3 Won/Lost/Reopen outcome audit smoke passed.\n";

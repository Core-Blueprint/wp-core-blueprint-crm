<?php
declare(strict_types=1);

/**
 * Isolated B1 domain/persistence fixture. No WordPress site or database writes.
 * The separate real WordPress/InnoDB fixture proves actual rollback behavior.
 */
$root = dirname( __DIR__ );
define( 'ABSPATH', $root . '/' );
define( 'ARRAY_A', 'ARRAY_A' );
define( 'CB_CRM_SCHEMA_VERSION', '1.6' );

final class WP_Error {
	public function __construct( private string $code, private string $message = '' ) {}
	public function get_error_code(): string { return $this->code; }
}

$GLOBALS['b1_ready'] = false;
$GLOBALS['b1_caps'] = [];
$GLOBALS['b1_user'] = 17;
$GLOBALS['b1_schema'] = '1.6';
function cb_crm_runtime_ready(): bool { return $GLOBALS['b1_ready']; }
function current_user_can( string $capability ): bool { return in_array( $capability, $GLOBALS['b1_caps'], true ); }
function get_current_user_id(): int { return $GLOBALS['b1_user']; }
function get_option( string $key, mixed $default = '' ): mixed {
	return 'cb_crm_db_version' === $key ? $GLOBALS['b1_schema'] : $default;
}
function current_time( string $type, bool $gmt = false ): string {
	if ( 'mysql' !== $type || ! $gmt ) { throw new RuntimeException( 'Expected UTC' ); }
	return '2026-10-11 13:00:00';
}

final class B1_Fake_Wpdb {
	public string $prefix = 'wp_';
	public string $last_error = '';
	public array $records = [
		'lead' => [
			7 => [ 'id' => 7, 'pipeline_id' => 10, 'stage_id' => 20, 'revision' => 1, 'status' => 'open' ],
			8 => [ 'id' => 8, 'pipeline_id' => 10, 'stage_id' => 20, 'revision' => 1, 'status' => 'converted' ],
		],
		'opportunity' => [
			9 => [ 'id' => 9, 'pipeline_id' => 11, 'stage_id' => 21, 'revision' => 1, 'outcome' => 'open' ],
			10 => [ 'id' => 10, 'pipeline_id' => 11, 'stage_id' => 21, 'revision' => 1, 'outcome' => 'won' ],
		],
	];
	public array $pipelines = [
		10 => [ 'id' => 10, 'entity_type' => 'lead', 'is_active' => 1 ],
		11 => [ 'id' => 11, 'entity_type' => 'opportunity', 'is_active' => 1 ],
	];
	public array $stages = [
		20 => [ 'id' => 20, 'pipeline_id' => 10, 'is_active' => 1 ],
		23 => [ 'id' => 23, 'pipeline_id' => 10, 'is_active' => 1 ],
		25 => [ 'id' => 25, 'pipeline_id' => 10, 'is_active' => 0 ],
		21 => [ 'id' => 21, 'pipeline_id' => 11, 'is_active' => 1 ],
		24 => [ 'id' => 24, 'pipeline_id' => 11, 'is_active' => 1 ],
	];
	public array $events = [];
	public int $queries = 0;
	public bool $fail_update = false;
	public bool $stale_update = false;
	public bool $fail_event = false;
	public bool $fail_commit = false;
	public bool $fail_read = false;
	private ?array $snapshot = null;

	public function prepare( string $sql, mixed ...$values ): string {
		foreach ( $values as $value ) {
			if ( ! is_int( $value ) || ! str_contains( $sql, '%d' ) ) {
				throw new RuntimeException( 'Unexpected unbound SQL value' );
			}
			$sql = preg_replace( '/%d/', (string) $value, $sql, 1 ) ?? '';
		}
		return $sql;
	}
	public function get_row( string $sql, string $format ): ?array {
		++$this->queries;
		if ( ARRAY_A !== $format || ! str_contains( $sql, 'FOR UPDATE' )
			|| ! preg_match( '/WHERE id = ([0-9]+) LIMIT 1/', $sql, $match ) ) {
			throw new RuntimeException( 'Expected locked Sales read' );
		}
		if ( $this->fail_read ) {
			$this->last_error = 'injected locked-read failure';
			return null;
		}
		$id = (int) $match[1];
		if ( str_contains( $sql, 'cb_crm_sales_pipelines' ) ) { return $this->pipelines[ $id ] ?? null; }
		if ( str_contains( $sql, 'cb_crm_sales_stages' ) ) { return $this->stages[ $id ] ?? null; }
		if ( str_contains( $sql, 'cb_crm_sales_leads' ) ) { return $this->records['lead'][ $id ] ?? null; }
		if ( str_contains( $sql, 'cb_crm_sales_opportunities' ) ) { return $this->records['opportunity'][ $id ] ?? null; }
		throw new RuntimeException( 'Unknown Sales table' );
	}
	public function update( string $table, array $values, array $where, array $formats, array $where_formats ): int|false {
		++$this->queries;
		if ( count( $values ) !== count( $formats ) || count( $where ) !== count( $where_formats ) ) {
			throw new RuntimeException( 'Update format mismatch' );
		}
		if ( $this->fail_update ) { return false; }
		if ( $this->stale_update ) { return 0; }
		$type = str_contains( $table, 'cb_crm_sales_leads' ) ? 'lead' : 'opportunity';
		$id = $where['id'];
		if ( ! isset( $this->records[ $type ][ $id ] ) ) { return 0; }
		if ( $this->records[ $type ][ $id ]['revision'] !== $where['revision'] ) { return 0; }
		$this->records[ $type ][ $id ] = array_replace( $this->records[ $type ][ $id ], $values );
		return 1;
	}
	public function insert( string $table, array $values, array $formats ): int|false {
		++$this->queries;
		if ( ! str_contains( $table, 'cb_crm_sales_events' ) || count( $values ) !== count( $formats ) ) {
			throw new RuntimeException( 'Unexpected event insert' );
		}
		if ( $this->fail_event ) { return false; }
		$this->events[] = $values;
		return 1;
	}
	public function query( string $sql ): int|false {
		++$this->queries;
		switch ( $sql ) {
			case 'START TRANSACTION':
				$this->snapshot = [ $this->records, $this->events ];
				return 1;
			case 'ROLLBACK':
				if ( null !== $this->snapshot ) {
					[ $this->records, $this->events ] = $this->snapshot;
				}
				$this->snapshot = null;
				return 1;
			case 'COMMIT':
				if ( $this->fail_commit ) { return false; }
				$this->snapshot = null;
				return 1;
			default:
				throw new RuntimeException( 'Unexpected SQL transaction' );
		}
	}
}

$GLOBALS['wpdb'] = new B1_Fake_Wpdb();
require $root . '/src/Capabilities.php';
require $root . '/src/Database/Schema.php';
require $root . '/src/Domain/Sales/Access.php';
require $root . '/src/Domain/Sales/Rules.php';
require $root . '/src/Repository/Sales/Transitions.php';

use CB\CRM\Repository\Sales\Transitions;

$assert = static function ( bool $pass, string $description ): void {
	if ( ! $pass ) {
		fwrite( STDERR, "CRM B1 transition smoke FAIL: {$description}\n" );
		exit( 1 );
	}
};
$code = static fn ( mixed $value ): string => $value instanceof WP_Error ? $value->get_error_code() : '';
$wpdb = $GLOBALS['wpdb'];
$manager = [ 'cb_manage_crm', 'cb_manage_crm_sales' ];

$assert( 'crm_sales_forbidden' === $code( Transitions::move_stage( 'lead', 7, 23, 1 ) ), 'runtime denial' );
$assert( 0 === $wpdb->queries, 'denial touched no storage' );
$GLOBALS['b1_ready'] = true;
$assert( 'crm_sales_forbidden' === $code( Transitions::move_stage( 'lead', 7, 23, 1 ) ), 'staff denial' );
$GLOBALS['b1_caps'] = [ 'cb_manage_crm', 'cb_view_crm_sales' ];
$assert( 'crm_sales_forbidden' === $code( Transitions::move_stage( 'lead', 7, 23, 1 ) ), 'read-only staff denial' );
$GLOBALS['b1_caps'] = [ 'cb_manage_crm_sales' ];
$assert( 'crm_sales_forbidden' === $code( Transitions::move_stage( 'lead', 7, 23, 1 ) ), 'Sales grant alone denied' );
$GLOBALS['b1_caps'] = $manager;
$GLOBALS['b1_schema'] = '1.5';
$assert( 'crm_sales_forbidden' === $code( Transitions::move_stage( 'lead', 7, 23, 1 ) ), 'schema not ready' );
$GLOBALS['b1_schema'] = '1.6';
$GLOBALS['b1_user'] = 0;
$assert( 'crm_sales_forbidden' === $code( Transitions::move_stage( 'lead', 7, 23, 1 ) ), 'anonymous actor' );
$GLOBALS['b1_user'] = 17;
$assert( 'crm_sales_invalid_input' === $code( Transitions::move_stage( 'contact', 7, 23, 1 ) ), 'wrong type' );
$assert( 'crm_sales_invalid_input' === $code( Transitions::move_stage( 'lead', 7, 0, 1 ) ), 'wrong stage ID' );
$assert( 'crm_sales_invalid_input' === $code( Transitions::move_stage( 'lead', 7, 23, 0 ) ), 'revision missing' );
$assert( 'crm_sales_not_found' === $code( Transitions::move_stage( 'lead', 999, 23, 1 ) ), 'missing record' );
$assert( 'crm_sales_conflict' === $code( Transitions::move_stage( 'lead', 7, 23, 2 ) ), 'stale read rejected' );
$assert( 'crm_sales_no_change' === $code( Transitions::move_stage( 'lead', 7, 20, 1 ) ), 'same-stage no-op rejected' );
$assert( 'crm_sales_invalid_stage' === $code( Transitions::move_stage( 'lead', 7, 21, 1 ) ), 'cross-pipeline stage rejected' );
$assert( 'crm_sales_invalid_stage' === $code( Transitions::move_stage( 'lead', 7, 25, 1 ) ), 'inactive stage rejected' );
$wpdb->pipelines[10]['is_active'] = 0;
$assert( 'crm_sales_invalid_pipeline' === $code( Transitions::move_stage( 'lead', 7, 23, 1 ) ), 'inactive pipeline rejected' );
$wpdb->pipelines[10]['is_active'] = 1;
$assert( 'crm_sales_invalid_transition' === $code( Transitions::move_stage( 'lead', 8, 23, 1 ) ), 'converted Lead stage blocked' );
$assert( 'crm_sales_invalid_transition' === $code( Transitions::move_stage( 'opportunity', 10, 24, 1 ) ), 'won Opportunity stage blocked' );

$assert( 2 === Transitions::move_stage( 'lead', 7, 23, 1 ), 'Lead moved to active stage' );
$assert( 23 === $wpdb->records['lead'][7]['stage_id'], 'stage persisted' );
$assert( 2 === $wpdb->records['lead'][7]['revision'], 'revision advanced' );
$assert( 1 === count( $wpdb->events ), 'single event' );
$assert( 'stage_changed' === $wpdb->events[0]['event_type'], 'event type' );
$assert( 20 === $wpdb->events[0]['from_stage_id'] && 23 === $wpdb->events[0]['to_stage_id'], 'old/new stage' );
$assert( 17 === $wpdb->events[0]['actor_user_id'], 'canonical actor' );
$assert( 'crm_sales_conflict' === $code( Transitions::move_stage( 'lead', 7, 20, 1 ) ), 'stale replay rejected' );
$assert( 1 === count( $wpdb->events ), 'stale replay did not log event' );

$assert( 'crm_sales_invalid_input' === $code( Transitions::set_lead_status( 7, 'converted', 2 ) ), 'B2 conversion cannot be bypassed' );
$assert( 3 === Transitions::set_lead_status( 7, 'qualified', 2 ), 'Lead qualified' );
$assert( 'qualified' === $wpdb->records['lead'][7]['status'], 'qualification persisted' );
$assert( 'lead_status_open_to_qualified' === $wpdb->events[1]['event_type'], 'status transition event source preserved' );
$assert( 'crm_sales_invalid_transition' === $code( Transitions::set_lead_status( 7, 'qualified', 3 ) ), 'duplicate qualification blocked' );
$assert( 4 === Transitions::set_lead_status( 7, 'disqualified', 3 ), 'qualified Lead disqualified' );
$assert( 'crm_sales_invalid_transition' === $code( Transitions::move_stage( 'lead', 7, 20, 4 ) ), 'terminal Lead immutable' );
$assert( 'crm_sales_invalid_transition' === $code( Transitions::set_lead_status( 7, 'qualified', 4 ) ), 'disqualified terminal' );
$assert( 2 === Transitions::move_stage( 'opportunity', 9, 24, 1 ), 'Opportunity moved while open' );
$assert( 24 === $wpdb->records['opportunity'][9]['stage_id'], 'Opportunity stage persisted' );

$before = serialize( [ $wpdb->records, $wpdb->events ] );
$wpdb->fail_event = true;
$assert( 'crm_sales_storage_failed' === $code( Transitions::move_stage( 'opportunity', 9, 21, 2 ) ), 'event failure rejected' );
$assert( $before === serialize( [ $wpdb->records, $wpdb->events ] ), 'event failure rolled back update' );
$wpdb->fail_event = false;
$wpdb->fail_update = true;
$assert( 'crm_sales_storage_failed' === $code( Transitions::move_stage( 'opportunity', 9, 21, 2 ) ), 'update failure rejected' );
$assert( $before === serialize( [ $wpdb->records, $wpdb->events ] ), 'update failure unchanged' );
$wpdb->fail_update = false;
$wpdb->stale_update = true;
$assert( 'crm_sales_conflict' === $code( Transitions::move_stage( 'opportunity', 9, 21, 2 ) ), 'write-time concurrent conflict' );
$wpdb->stale_update = false;
$wpdb->fail_commit = true;
$assert( 'crm_sales_storage_failed' === $code( Transitions::move_stage( 'opportunity', 9, 21, 2 ) ), 'commit failure rejected' );
$assert( $before === serialize( [ $wpdb->records, $wpdb->events ] ), 'commit failure rolled back' );
$wpdb->fail_commit = false;
$wpdb->fail_read = true;
$assert( 'crm_sales_storage_failed' === $code( Transitions::move_stage( 'opportunity', 9, 21, 2 ) ), 'DB locked-read errors fail closed' );
$wpdb->fail_read = false;
$assert( $before === serialize( [ $wpdb->records, $wpdb->events ] ), 'read failure unchanged' );

echo "CRM Sales B1 revision-checked transition smoke passed.\n";

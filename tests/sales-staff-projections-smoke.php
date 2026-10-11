<?php
declare(strict_types=1);

/** Standalone Wave 1C/C1 bounded Sales projection test, no database writes. */
$root = dirname( __DIR__ );
define( 'ABSPATH', $root . '/' );
define( 'ARRAY_A', 'ARRAY_A' );
define( 'CB_CRM_SCHEMA_VERSION', '1.6' );

final class WP_Error {
	public function __construct( private string $code, private string $message = '' ) {}
	public function get_error_code(): string { return $this->code; }
}
$GLOBALS['c1_ready'] = false;
$GLOBALS['c1_caps'] = [];
$GLOBALS['c1_actor'] = 17;
$GLOBALS['c1_schema'] = '1.6';
function cb_crm_runtime_ready(): bool { return $GLOBALS['c1_ready']; }
function current_user_can( string $capability ): bool { return in_array( $capability, $GLOBALS['c1_caps'], true ); }
function get_current_user_id(): int { return $GLOBALS['c1_actor']; }
function get_option( string $key, mixed $default = '' ): mixed {
	return 'cb_crm_db_version' === $key ? $GLOBALS['c1_schema'] : $default;
}

final class C1_Fake_Wpdb {
	public string $prefix = 'wp_';
	public string $last_error = '';
	public int $queries = 0;
	public string $fail = '';
	public bool $invalid_page_row = false;
	public array $sql = [];
	public array $records = [
		'lead' => [],
		'opportunity' => [],
	];
	public array $participants = [
		[ 'id' => 1, 'entity_type' => 'lead', 'entity_id' => 11, 'party_type' => 'contact', 'party_id' => 42, 'role' => 'primary_contact' ],
		[ 'id' => 2, 'entity_type' => 'lead', 'entity_id' => 11, 'party_type' => 'organization', 'party_id' => 43, 'role' => 'decision_maker' ],
	];
	public array $events = [
		[ 'id' => 31, 'entity_type' => 'lead', 'entity_id' => 11, 'event_type' => 'created', 'from_stage_id' => null, 'to_stage_id' => 3, 'from_outcome' => '', 'to_outcome' => '', 'actor_user_id' => 17, 'revision' => 1, 'created_at' => '2026-10-11 09:00:00' ],
		[ 'id' => 32, 'entity_type' => 'lead', 'entity_id' => 11, 'event_type' => 'stage_changed', 'from_stage_id' => 3, 'to_stage_id' => 5, 'from_outcome' => '', 'to_outcome' => '', 'actor_user_id' => 18, 'revision' => 2, 'created_at' => '2026-10-11 10:00:00' ],
		[ 'id' => 33, 'entity_type' => 'lead', 'entity_id' => 11, 'event_type' => 'lead_status_open_to_qualified', 'from_stage_id' => null, 'to_stage_id' => null, 'from_outcome' => '', 'to_outcome' => '', 'actor_user_id' => 17, 'revision' => 3, 'created_at' => '2026-10-11 11:00:00' ],
	];
	public function __construct() {
		foreach ( [ 6, 7, 8, 9, 10, 11 ] as $id ) {
			$this->records['lead'][$id] = [
				'id' => $id, 'title' => 'Test Lead ' . $id, 'owner_user_id' => 0 === $id % 2 ? 0 : 17,
				'pipeline_id' => 2, 'stage_id' => $id % 2 ? 5 : 3,
				'status' => $id % 2 ? 'qualified' : 'open',
				'next_action_on' => null, 'revision' => 3,
				'created_at' => '2026-10-11 09:00:00', 'updated_at' => '2026-10-11 11:00:00',
				'source' => 'referral', 'source_reference' => 'private-import-reference',
				'converted_opportunity_id' => null, 'qualified_at' => null, 'disqualified_at' => null,
				'private_email' => 'must-not-project@example.invalid',
			];
		}
		$this->records['opportunity'][21] = [
			'id' => 21, 'title' => 'Test Deal', 'owner_user_id' => 17,
			'pipeline_id' => 4, 'stage_id' => 6, 'outcome' => 'won',
			'next_action_on' => null, 'revision' => 4,
			'created_at' => '2026-10-11 09:00:00', 'updated_at' => '2026-10-11 12:00:00',
			'amount_minor' => 119900, 'currency' => 'EUR', 'expected_close_on' => null,
			'source_lead_id' => 11, 'won_at' => '2026-10-11 12:00:00', 'lost_at' => null,
			'private_note' => 'not selected',
		];
		$this->records['opportunity'][22] = array_replace( $this->records['opportunity'][21], [
			'id' => 22, 'outcome' => 'open', 'revision' => 1, 'source_lead_id' => null, 'won_at' => null,
		] );
	}

	public function prepare( string $query, mixed ...$args ): string {
		foreach ( $args as $arg ) {
			if ( is_int( $arg ) ) {
				if ( ! str_contains( $query, '%d' ) ) { throw new RuntimeException( 'Unexpected integer binding.' ); }
				$query = preg_replace( '/%d/', (string) $arg, $query, 1 ) ?? '';
			} elseif ( is_string( $arg ) ) {
				if ( ! str_contains( $query, '%s' ) ) { throw new RuntimeException( 'Unexpected string binding.' ); }
				$query = preg_replace( '/%s/', "'" . str_replace( "'", "''", $arg ) . "'", $query, 1 ) ?? '';
			} else {
				throw new RuntimeException( 'Unexpected SQL binding type.' );
			}
		}
		if ( preg_match( '/%[ds]/', $query ) ) { throw new RuntimeException( 'Unbound SQL placeholder.' ); }
		return $query;
	}
	public function get_results( string $sql, string $mode ): ?array {
		++$this->queries;
		$this->sql[] = $sql;
		if ( ARRAY_A !== $mode ) { throw new RuntimeException( 'Expected associative SQL results.' ); }
		if ( $this->fail === 'page' && str_contains( $sql, 'ORDER BY id DESC' ) ) {
			$this->last_error = 'injected page failure'; return null;
		}
		if ( $this->fail === 'participants' && str_contains( $sql, 'cb_crm_sales_participants' ) ) {
			$this->last_error = 'injected participants failure'; return null;
		}
		if ( $this->fail === 'history' && str_contains( $sql, 'cb_crm_sales_events' ) ) {
			$this->last_error = 'injected audit failure'; return null;
		}
		if ( ! preg_match( '/ LIMIT (\d+)$/', $sql, $match ) ) { throw new RuntimeException( 'Unbounded C1 query.' ); }
		$limit = (int) $match[1];
		if ( $limit > 51 || $limit < 1 || stripos( $sql, ' OFFSET ' ) !== false || str_contains( $sql, 'SELECT *' ) || str_contains( $sql, ' JOIN ' ) ) {
			throw new RuntimeException( 'C1 SQL must be bounded and narrow.' );
		}
		if ( str_contains( $sql, 'cb_crm_sales_participants' ) ) {
			preg_match( "/entity_type = '([^']+)' AND entity_id = (\d+)/", $sql, $m );
			return array_slice( array_map( static fn ( array $p ): array => [
				'party_type' => $p['party_type'], 'party_id' => $p['party_id'], 'role' => $p['role'],
			], array_values( array_filter( $this->participants, static fn ( array $p ): bool =>
				$p['entity_type'] === $m[1] && $p['entity_id'] === (int) $m[2] ) ) ), 0, $limit );
		}
		if ( str_contains( $sql, 'cb_crm_sales_events' ) ) {
			preg_match( "/entity_type = '([^']+)' AND entity_id = (\d+)/", $sql, $m );
			preg_match( '/\bid > (\d+)/', $sql, $cursor );
			$after = isset( $cursor[1] ) ? (int) $cursor[1] : 0;
			$rows = array_values( array_filter( $this->events, static fn ( array $event ): bool =>
				$event['entity_type'] === $m[1] && $event['entity_id'] === (int) $m[2] && $event['id'] > $after ) );
			usort( $rows, static fn ( array $a, array $b ): int => $a['id'] <=> $b['id'] );
			return array_slice( $rows, 0, $limit );
		}
		$type = str_contains( $sql, 'cb_crm_sales_leads' ) ? 'lead' : 'opportunity';
		$rows = array_values( $this->records[$type] );
		foreach ( [ 'pipeline_id', 'stage_id', 'owner_user_id' ] as $key ) {
			if ( preg_match( '/\b' . $key . ' = (\d+)/', $sql, $m ) ) {
				$rows = array_values( array_filter( $rows, static fn ( array $r ): bool => $r[$key] === (int) $m[1] ) );
			}
		}
		$state_key = 'lead' === $type ? 'status' : 'outcome';
		if ( preg_match( "/\b{$state_key} = '([^']+)'/", $sql, $m ) ) {
			$rows = array_values( array_filter( $rows, static fn ( array $r ): bool => $r[$state_key] === $m[1] ) );
		}
		if ( preg_match( '/\bid < (\d+)/', $sql, $m ) ) {
			$rows = array_values( array_filter( $rows, static fn ( array $r ): bool => $r['id'] < (int) $m[1] ) );
		}
		usort( $rows, static fn ( array $a, array $b ): int => $b['id'] <=> $a['id'] );
		$rows = array_slice( $rows, 0, $limit );
		$rows = array_map( static fn ( array $r ): array => [
			'id' => $r['id'], 'title' => $r['title'], 'owner_user_id' => $r['owner_user_id'],
			'pipeline_id' => $r['pipeline_id'], 'stage_id' => $r['stage_id'],
			'state' => $r[$state_key], 'next_action_on' => $r['next_action_on'],
			'revision' => $r['revision'], 'created_at' => $r['created_at'], 'updated_at' => $r['updated_at'],
		], $rows );
		if ( $this->invalid_page_row && [] !== $rows ) { $rows[0]['id'] = 0; }
		return $rows;
	}
	public function get_row( string $sql, string $mode ): ?array {
		++$this->queries;
		$this->sql[] = $sql;
		if ( ARRAY_A !== $mode || ! preg_match( '/WHERE id = (\d+) LIMIT 1/', $sql, $m )
			|| str_contains( $sql, 'SELECT *' ) ) { throw new RuntimeException( 'Unsafe detail SQL.' ); }
		if ( 'detail' === $this->fail ) {
			$this->last_error = 'injected detail failure'; return null;
		}
		$type = str_contains( $sql, 'cb_crm_sales_leads' ) ? 'lead' : 'opportunity';
		$record = $this->records[$type][ (int) $m[1] ] ?? null;
		if ( null === $record ) { return null; }
		$fields = [
			'id', 'title', 'owner_user_id', 'pipeline_id', 'stage_id', 'next_action_on',
			'revision', 'created_at', 'updated_at',
		];
		if ( 'lead' === $type ) {
			$fields = array_merge( $fields, [ 'source', 'converted_opportunity_id', 'qualified_at', 'disqualified_at' ] );
		} else {
			$fields = array_merge( $fields, [ 'source_lead_id', 'amount_minor', 'currency', 'expected_close_on', 'won_at', 'lost_at' ] );
		}
		$row = array_intersect_key( $record, array_flip( $fields ) );
		$row['state'] = $record[ 'lead' === $type ? 'status' : 'outcome' ];
		return $row;
	}
	public function get_var( string $sql ): int|null {
		++$this->queries;
		$this->sql[] = $sql;
		if ( ! preg_match( '/SELECT id FROM \w+ WHERE id = (\d+) LIMIT 1/', $sql, $m ) ) {
			throw new RuntimeException( 'Unexpected history record existence SQL.' );
		}
		if ( 'exists' === $this->fail ) {
			$this->last_error = 'injected subject lookup failure'; return null;
		}
		$type = str_contains( $sql, 'cb_crm_sales_leads' ) ? 'lead' : 'opportunity';
		return $this->records[$type][ (int) $m[1] ]['id'] ?? null;
	}
}
$GLOBALS['wpdb'] = new C1_Fake_Wpdb();
require $root . '/src/Capabilities.php';
require $root . '/src/Database/Schema.php';
require $root . '/src/Domain/Sales/Access.php';
require $root . '/src/Domain/Sales/Rules.php';
require $root . '/src/Repository/Sales/StaffProjections.php';

use CB\CRM\Repository\Sales\StaffProjections;

$assert = static function ( bool $ok, string $message ): void {
	if ( ! $ok ) { fwrite( STDERR, "CRM C1 projections smoke FAIL: {$message}\n" ); exit( 1 ); }
};
$code = static fn ( mixed $result ): string => $result instanceof WP_Error ? $result->get_error_code() : '';
$wpdb = $GLOBALS['wpdb'];
$assert( 'crm_sales_forbidden' === $code( StaffProjections::page( 'lead' ) ) && 0 === $wpdb->queries, 'runtime denied before SQL' );
$GLOBALS['c1_ready'] = true;
$assert( 'crm_sales_forbidden' === $code( StaffProjections::detail( 'lead', 11 ) ), 'missing capability denied' );
$GLOBALS['c1_caps'] = [ 'cb_view_crm_sales' ];
$assert( 'crm_sales_forbidden' === $code( StaffProjections::history( 'lead', 11 ) ), 'Sales-only viewing denied' );
$GLOBALS['c1_caps'] = [ 'cb_manage_crm', 'cb_view_crm_sales' ];
$GLOBALS['c1_actor'] = 0;
$assert( 'crm_sales_forbidden' === $code( StaffProjections::page( 'lead' ) ), 'anonymous denied' );
$GLOBALS['c1_actor'] = 17;
$GLOBALS['c1_schema'] = '1.5';
$assert( 'crm_sales_forbidden' === $code( StaffProjections::detail( 'lead', 11 ) ), 'incorrect schema denied' );
$GLOBALS['c1_schema'] = '1.6';
$assert( 0 === $wpdb->queries, 'all forbidden reads stayed SQL-free' );

foreach ( [ [ 'lead', [ 'sort' => 'RAND()' ] ], [ 'lead', [ 'owner_user_id' => '17' ] ],
	[ 'lead', [ 'pipeline_id' => 0 ] ], [ 'lead', [ 'state' => 'won' ] ],
	[ 'opportunity', [ 'state' => 'qualified' ] ], [ 'lead', [ 'state' => 'open OR 1=1' ] ],
	[ 'lead', [ 'stage_id' => -1 ] ], [ 'lead', [ 'owner_user_id' => -1 ] ] ] as [$type, $filters] ) {
	$assert( 'crm_sales_invalid_query' === $code( StaffProjections::page( $type, $filters ) ), 'malformed filter accepted' );
}
$assert( 'crm_sales_invalid_query' === $code( StaffProjections::page( 'lead', [], 0, 51 ) ), 'unbounded page allowed' );
$assert( 'crm_sales_invalid_query' === $code( StaffProjections::page( 'lead', [], 0, 0 ) ), 'zero page allowed' );
$assert( 'crm_sales_invalid_query' === $code( StaffProjections::page( 'lead', [], -1, 2 ) ), 'negative cursor allowed' );
$assert( 'crm_sales_invalid_query' === $code( StaffProjections::page( 'unknown' ) ), 'unknown Sales entity allowed' );
$assert( 'crm_sales_invalid_query' === $code( StaffProjections::detail( 'lead', 0 ) ), 'detail invalid ID allowed' );
$assert( 'crm_sales_invalid_query' === $code( StaffProjections::history( 'lead', 11, 0, 51 ) ), 'audit history unbounded' );
$assert( 0 === $wpdb->queries, 'invalid queries did not hit SQL' );

$first = StaffProjections::page( 'lead', [], 0, 2 );
$assert( is_array( $first ) && [ 11, 10 ] === array_column( $first['rows'], 'id' )
	&& 10 === $first['cursor'] && true === $first['has_more'], 'descending keyset page one' );
$second = StaffProjections::page( 'lead', [], $first['cursor'], 2 );
$assert( is_array( $second ) && [ 9, 8 ] === array_column( $second['rows'], 'id' )
	&& 8 === $second['cursor'] && true === $second['has_more'], 'descending keyset page two' );
$third = StaffProjections::page( 'lead', [], $second['cursor'], 2 );
$assert( is_array( $third ) && [ 7, 6 ] === array_column( $third['rows'], 'id' )
	&& false === $third['has_more'] && 6 === $third['cursor'], 'descending last page' );
$empty = StaffProjections::page( 'lead', [], $third['cursor'], 2 );
$assert( [] === $empty['rows'] && 6 === $empty['cursor'] && false === $empty['has_more'], 'empty page preserves cursor' );
$assert( ! array_key_exists( 'source_reference', $first['rows'][0] )
	&& ! array_key_exists( 'amount_minor', $first['rows'][0] )
	&& ! array_key_exists( 'private_email', $first['rows'][0] ), 'list projection leaked excluded detail/PII' );
$assert( 10 === count( $first['rows'][0] ), 'list projection field count is not explicit' );

$qualified = StaffProjections::page( 'lead', [ 'pipeline_id' => 2, 'stage_id' => 5, 'owner_user_id' => 17, 'state' => 'qualified' ], 0, 20 );
$assert( is_array( $qualified ) && [ 11, 9, 7 ] === array_column( $qualified['rows'], 'id' ), 'combined exact filters failed' );
$anonymized = StaffProjections::page( 'lead', [ 'owner_user_id' => 0, 'state' => 'open' ] );
$assert( is_array( $anonymized ) && [ 10, 8, 6 ] === array_column( $anonymized['rows'], 'id' ), 'anonymized owner metadata filtered incorrectly' );
$deals = StaffProjections::page( 'opportunity', [ 'state' => 'won', 'owner_user_id' => 17 ] );
$assert( is_array( $deals ) && [ 21 ] === array_column( $deals['rows'], 'id' ), 'Opportunity outcome filter incorrect' );

$lead = StaffProjections::detail( 'lead', 11 );
$assert( is_array( $lead ) && 11 === $lead['record']['id'] && 'qualified' === $lead['record']['state'], 'Lead detail missing' );
$assert( 2 === count( $lead['participants'] )
	&& [ 'party_type' => 'contact', 'party_id' => 42, 'role' => 'primary_contact' ] === $lead['participants'][0], 'only canonical participant references projected' );
$assert( ! array_key_exists( 'private_email', $lead['record'] )
	&& ! array_key_exists( 'source_reference', $lead['record'] ), 'Lead detail exposed excluded provenance or PII' );
$deal = StaffProjections::detail( 'opportunity', 21 );
$assert( is_array( $deal ) && 'won' === $deal['record']['state']
	&& 119900 === $deal['record']['amount_minor'] && 'EUR' === $deal['record']['currency']
	&& 11 === $deal['record']['source_lead_id'] && [] === $deal['participants'], 'Opportunity detail scalar contract broken' );
$assert( ! array_key_exists( 'private_note', $deal['record'] ), 'Opportunity arbitrary note leaked' );
$assert( null === StaffProjections::detail( 'lead', 404 ), 'missing detail must be null' );

$history = StaffProjections::history( 'lead', 11, 0, 2 );
$assert( is_array( $history ) && [ 31, 32 ] === array_column( $history['rows'], 'id' )
	&& 32 === $history['cursor'] && true === $history['has_more'], 'audit first page' );
$more_history = StaffProjections::history( 'lead', 11, 32, 2 );
$assert( is_array( $more_history ) && [ 33 ] === array_column( $more_history['rows'], 'id' )
	&& 33 === $more_history['cursor'] && false === $more_history['has_more'], 'audit after-cursor page' );
$assert( 17 === $history['rows'][0]['actor_user_id']
	&& 9 === count( $history['rows'][0] ), 'audit projection must contain only nine public staff metadata fields' );
$assert( 'crm_sales_not_found' === $code( StaffProjections::history( 'lead', 404 ) ), 'history for nonexistent Lead was not rejected' );

$wpdb->fail = 'page';
$assert( 'crm_sales_read_failed' === $code( StaffProjections::page( 'lead' ) ), 'page SQL fault not fail-closed' );
$wpdb->fail = 'detail';
$assert( 'crm_sales_read_failed' === $code( StaffProjections::detail( 'lead', 11 ) ), 'detail SQL fault not fail-closed' );
$wpdb->fail = 'participants';
$assert( 'crm_sales_read_failed' === $code( StaffProjections::detail( 'lead', 11 ) ), 'participants SQL fault not fail-closed' );
$wpdb->fail = 'exists';
$assert( 'crm_sales_read_failed' === $code( StaffProjections::history( 'lead', 11 ) ), 'history existence SQL fault not fail-closed' );
$wpdb->fail = 'history';
$assert( 'crm_sales_read_failed' === $code( StaffProjections::history( 'lead', 11 ) ), 'audit SQL fault not fail-closed' );
$wpdb->fail = '';
$wpdb->invalid_page_row = true;
$assert( 'crm_sales_invalid_row' === $code( StaffProjections::page( 'lead' ) ), 'invalid row accepted' );
$wpdb->invalid_page_row = false;
$wpdb->participants = array_merge( $wpdb->participants, array_fill( 0, 49,
	[ 'id' => 9, 'entity_type' => 'lead', 'entity_id' => 11, 'party_type' => 'contact', 'party_id' => 99, 'role' => 'other' ] ) );
$assert( 'crm_sales_invalid_row' === $code( StaffProjections::detail( 'lead', 11 ) ), 'over-limit participants accepted' );

foreach ( $wpdb->sql as $sql ) {
	$assert( ! str_contains( $sql, 'SELECT *' ) && ! str_contains( $sql, ' JOIN ' )
		&& ! str_contains( $sql, ' OFFSET ' ) && ! str_contains( $sql, 'COUNT(*)' ), 'unrestricted read query emitted' );
}
echo "CRM Sales C1 bounded staff list/detail/history projections smoke passed.\n";

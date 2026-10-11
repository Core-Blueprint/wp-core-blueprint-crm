<?php
declare(strict_types=1);

/** Standalone B2 transactional/replay test. No WordPress or database writes. */
$root = dirname( __DIR__ );
define( 'ABSPATH', $root . '/' );
define( 'ARRAY_A', 'ARRAY_A' );
define( 'CB_CRM_SCHEMA_VERSION', '1.6' );

final class WP_Error {
	public function __construct( private string $code ) {}
	public function get_error_code(): string { return $this->code; }
}
$GLOBALS['b2_ready'] = false;
$GLOBALS['b2_caps'] = [];
$GLOBALS['b2_user'] = 17;
$GLOBALS['b2_schema'] = '1.6';
function cb_crm_runtime_ready(): bool { return $GLOBALS['b2_ready']; }
function current_user_can( string $capability ): bool { return in_array( $capability, $GLOBALS['b2_caps'], true ); }
function get_current_user_id(): int { return $GLOBALS['b2_user']; }
function get_option( string $key, mixed $default = '' ): mixed {
	return 'cb_crm_db_version' === $key ? $GLOBALS['b2_schema'] : $default;
}
function current_time( string $format, bool $gmt = false ): string {
	if ( 'mysql' !== $format || ! $gmt ) { throw new RuntimeException( 'Expected UTC date.' ); }
	return '2026-10-11 16:00:00';
}

final class B2_Fake_Wpdb {
	public string $prefix = 'wp_';
	public string $posts = 'wp_posts';
	public string $last_error = '';
	public int $insert_id = 0;
	public int $queries = 0;
	public array $pipelines = [
		10 => [ 'id' => 10, 'entity_type' => 'lead', 'is_active' => 1 ],
		11 => [ 'id' => 11, 'entity_type' => 'opportunity', 'is_active' => 1 ],
		12 => [ 'id' => 12, 'entity_type' => 'opportunity', 'is_active' => 0 ],
	];
	public array $stages = [
		21 => [ 'id' => 21, 'pipeline_id' => 11, 'is_active' => 1 ],
		22 => [ 'id' => 22, 'pipeline_id' => 10, 'is_active' => 1 ],
		23 => [ 'id' => 23, 'pipeline_id' => 11, 'is_active' => 0 ],
	];
	public array $posts_map = [
		7 => [ 'post_type' => 'cb_crm_contact', 'post_status' => 'publish' ],
		42 => [ 'post_type' => 'cb_crm_org', 'post_status' => 'publish' ],
		9 => [ 'post_type' => 'cb_crm_contact', 'post_status' => 'trash' ],
	];
	public array $leads = [
		4 => [ 'id' => 4, 'title' => 'Synthetic Lead', 'owner_user_id' => 15, 'status' => 'qualified', 'converted_opportunity_id' => null, 'revision' => 2 ],
		5 => [ 'id' => 5, 'title' => 'Open Lead', 'owner_user_id' => 15, 'status' => 'open', 'converted_opportunity_id' => null, 'revision' => 1 ],
		6 => [ 'id' => 6, 'title' => 'Orphaned', 'owner_user_id' => 15, 'status' => 'qualified', 'converted_opportunity_id' => null, 'revision' => 1 ],
		8 => [ 'id' => 8, 'title' => 'Dangling', 'owner_user_id' => 15, 'status' => 'converted', 'converted_opportunity_id' => 999, 'revision' => 3 ],
		10 => [ 'id' => 10, 'title' => 'Trashed', 'owner_user_id' => 15, 'status' => 'qualified', 'converted_opportunity_id' => null, 'revision' => 1 ],
	];
	public array $opportunities = [];
	public array $participants = [
		[ 'entity_type' => 'lead', 'entity_id' => 4, 'party_type' => 'contact', 'party_id' => 7, 'role' => 'primary_contact' ],
		[ 'entity_type' => 'lead', 'entity_id' => 4, 'party_type' => 'organization', 'party_id' => 42, 'role' => 'decision_maker' ],
		[ 'entity_type' => 'lead', 'entity_id' => 5, 'party_type' => 'contact', 'party_id' => 7, 'role' => 'other' ],
		[ 'entity_type' => 'lead', 'entity_id' => 10, 'party_type' => 'contact', 'party_id' => 9, 'role' => 'other' ],
	];
	public array $events = [];
	public string $fail_insert = '';
	public bool $fail_update = false;
	public bool $fail_commit = false;
	public bool $fail_read = false;
	private ?array $snapshot = null;
	private int $next_id = 100;

	public function prepare( string $sql, mixed ...$values ): string {
		foreach ( $values as $value ) {
			if ( is_int( $value ) ) { $sql = preg_replace( '/%d/', (string) $value, $sql, 1 ) ?? ''; }
			elseif ( is_string( $value ) ) { $sql = preg_replace( '/%s/', "'" . str_replace( "'", "''", $value ) . "'", $sql, 1 ) ?? ''; }
			else { throw new RuntimeException( 'Unsafe SQL fixture input.' ); }
		}
		if ( str_contains( $sql, '%d' ) || str_contains( $sql, '%s' ) ) {
			throw new RuntimeException( 'Unbound SQL argument.' );
		}
		return $sql;
	}
	public function get_row( string $sql, string $format ): ?array {
		++$this->queries;
		if ( ARRAY_A !== $format || ! str_contains( $sql, 'FOR UPDATE' ) ) { throw new RuntimeException( 'Unprotected SQL read.' ); }
		if ( $this->fail_read ) { $this->last_error = 'injected SELECT failure'; return null; }
		if ( str_contains( $sql, 'wp_posts' ) ) {
			if ( ! preg_match( '/WHERE ID = (\d+)/', $sql, $m ) ) { throw new RuntimeException( 'Invalid wp_posts lookup.' ); }
			return $this->posts_map[ (int) $m[1] ] ?? null;
		}
		if ( str_contains( $sql, 'cb_crm_sales_pipelines' ) ) {
			preg_match( '/WHERE id = (\d+)/', $sql, $m ); return $this->pipelines[ (int) $m[1] ] ?? null;
		}
		if ( str_contains( $sql, 'cb_crm_sales_stages' ) ) {
			preg_match( '/WHERE id = (\d+)/', $sql, $m ); return $this->stages[ (int) $m[1] ] ?? null;
		}
		if ( str_contains( $sql, 'cb_crm_sales_leads' ) ) {
			preg_match( '/WHERE id = (\d+)/', $sql, $m ); return $this->leads[ (int) $m[1] ] ?? null;
		}
		if ( str_contains( $sql, 'cb_crm_sales_opportunities' ) ) {
			if ( preg_match( '/WHERE source_lead_id = (\d+)/', $sql, $m ) ) {
				foreach ( $this->opportunities as $row ) {
					if ( (int) ( $row['source_lead_id'] ?? 0 ) === (int) $m[1] ) { return $row; }
				}
				return null;
			}
			preg_match( '/WHERE id = (\d+)/', $sql, $m ); return $this->opportunities[ (int) $m[1] ] ?? null;
		}
		throw new RuntimeException( 'Unknown B2 table for get_row.' );
	}
	public function get_results( string $sql, string $format ): array {
		++$this->queries;
		if ( ARRAY_A !== $format || ! str_contains( $sql, 'FOR UPDATE' )
			|| ! preg_match( "/entity_type = 'lead' AND entity_id = (\d+)/", $sql, $m ) ) {
			throw new RuntimeException( 'Participant read was not locked.' );
		}
		if ( $this->fail_read ) { $this->last_error = 'injected participant read failure'; return []; }
		$rows = array_values( array_filter( $this->participants, static fn ( array $r ): bool => 'lead' === $r['entity_type'] && (int) $r['entity_id'] === (int) $m[1] ) );
		usort( $rows, static fn ( array $a, array $b ): int => [ $a['party_id'], $a['party_type'], $a['role'] ] <=> [ $b['party_id'], $b['party_type'], $b['role'] ] );
		return $rows;
	}
	public function insert( string $table, array $values, array $formats ): int|false {
		++$this->queries;
		if ( count( $values ) !== count( $formats ) ) { throw new RuntimeException( 'INSERT format mismatch.' ); }
		$kind = str_contains( $table, 'sales_opportunities' ) ? 'opportunity' :
			( str_contains( $table, 'sales_participants' ) ? 'participant' : 'event' );
		if ( $kind === $this->fail_insert ||
			( 'lead_event' === $this->fail_insert && 'event' === $kind && 'lead' === ( $values['entity_type'] ?? '' ) ) ) {
			return false;
		}
		if ( 'opportunity' === $kind ) {
			foreach ( $this->opportunities as $row ) {
				if ( $row['source_lead_id'] === $values['source_lead_id'] ) { return false; }
			}
			$this->insert_id = $this->next_id++;
			$this->opportunities[ $this->insert_id ] = [ 'id' => $this->insert_id ] + $values;
		} elseif ( 'participant' === $kind ) {
			$this->participants[] = $values;
		} else {
			$this->events[] = $values;
		}
		return 1;
	}
	public function update( string $table, array $values, array $where, array $formats, array $where_formats ): int|false {
		++$this->queries;
		if ( ! str_contains( $table, 'sales_leads' ) || count( $values ) !== count( $formats )
			|| count( $where ) !== count( $where_formats ) ) { throw new RuntimeException( 'Unexpected conversion update.' ); }
		if ( $this->fail_update ) { return false; }
		$row = $this->leads[ $where['id'] ] ?? null;
		if ( ! is_array( $row ) ) { return 0; }
		foreach ( $where as $key => $value ) {
			if ( $row[ $key ] !== $value ) { return 0; }
		}
		$this->leads[ $where['id'] ] = array_replace( $row, $values );
		return 1;
	}
	public function query( string $sql ): int|false {
		++$this->queries;
		switch ( $sql ) {
			case 'START TRANSACTION':
				$this->snapshot = [ $this->leads, $this->opportunities, $this->participants, $this->events, $this->next_id ];
				return 1;
			case 'ROLLBACK':
				if ( null !== $this->snapshot ) {
					[ $this->leads, $this->opportunities, $this->participants, $this->events, $this->next_id ] = $this->snapshot;
				}
				$this->snapshot = null;
				return 1;
			case 'COMMIT':
				if ( $this->fail_commit ) { return false; }
				$this->snapshot = null;
				return 1;
			default:
				throw new RuntimeException( 'Unexpected B2 transaction command.' );
		}
	}
}
$GLOBALS['wpdb'] = new B2_Fake_Wpdb();
require $root . '/src/Capabilities.php';
require $root . '/src/Content/PostTypes.php';
require $root . '/src/Content/Entity.php';
require $root . '/src/Database/Schema.php';
require $root . '/src/Domain/Sales/Access.php';
require $root . '/src/Domain/Sales/Rules.php';
require $root . '/src/Repository/Sales/Conversion.php';

use CB\CRM\Repository\Sales\Conversion;

$assert = static function ( bool $pass, string $msg ): void {
	if ( ! $pass ) { fwrite( STDERR, "CRM B2 conversion smoke FAIL: {$msg}\n" ); exit( 1 ); }
};
$code = static fn ( mixed $r ): string => $r instanceof WP_Error ? $r->get_error_code() : '';
$wpdb = $GLOBALS['wpdb'];
$convert = static fn ( int $lead = 4, int $rev = 2, int $pipe = 11, int $stage = 21 ): int|WP_Error =>
	Conversion::convert( $lead, $rev, $pipe, $stage );

$assert( 'crm_sales_forbidden' === $code( $convert() ) && 0 === $wpdb->queries, 'runtime denial before SQL' );
$GLOBALS['b2_ready'] = true;
$assert( 'crm_sales_forbidden' === $code( $convert() ), 'missing capabilities' );
$GLOBALS['b2_caps'] = [ 'cb_manage_crm', 'cb_view_crm_sales' ];
$assert( 'crm_sales_forbidden' === $code( $convert() ), 'read-only role blocked' );
$GLOBALS['b2_caps'] = [ 'cb_manage_crm_sales' ];
$assert( 'crm_sales_forbidden' === $code( $convert() ), 'missing canonical CRM cap blocked' );
$GLOBALS['b2_caps'] = [ 'cb_manage_crm', 'cb_manage_crm_sales' ];
$GLOBALS['b2_schema'] = '1.5';
$assert( 'crm_sales_forbidden' === $code( $convert() ), 'wrong schema blocked' );
$GLOBALS['b2_schema'] = '1.6';
$GLOBALS['b2_user'] = 0;
$assert( 'crm_sales_forbidden' === $code( $convert() ), 'anonymous blocked' );
$GLOBALS['b2_user'] = 17;

$assert( 'crm_sales_invalid_input' === $code( $convert( 0 ) ), 'invalid Lead ID blocked' );
$assert( 'crm_sales_invalid_input' === $code( $convert( 4, 0 ) ), 'missing revision blocked' );
$assert( 'crm_sales_invalid_input' === $code( $convert( 4, 2, 11, 0 ) ), 'missing stage blocked' );
$assert( 'crm_sales_not_found' === $code( $convert( 777 ) ), 'missing Lead blocked' );
$assert( 'crm_sales_conflict' === $code( $convert( 4, 1 ) ), 'stale revision blocked' );
$assert( 'crm_sales_invalid_transition' === $code( $convert( 5, 1 ) ), 'open Lead cannot convert' );
$assert( 'crm_sales_invalid_participants' === $code( $convert( 6, 1 ) ), 'Lead with no parties cannot convert' );
$assert( 'crm_sales_invalid_identity' === $code( $convert( 10, 1 ) ), 'trashed Contact cannot convert' );
$assert( 'crm_sales_conversion_inconsistent' === $code( $convert( 8, 2 ) ), 'damaged replay link must fail closed' );
$assert( 'crm_sales_invalid_pipeline' === $code( $convert( 4, 2, 10, 22 ) ), 'Lead pipeline cannot own converted Opportunity' );
$assert( 'crm_sales_invalid_pipeline' === $code( $convert( 4, 2, 12, 21 ) ), 'disabled Opportunity pipeline blocked' );
$assert( 'crm_sales_invalid_stage' === $code( $convert( 4, 2, 11, 22 ) ), 'cross-pipeline stage blocked' );
$assert( 'crm_sales_invalid_stage' === $code( $convert( 4, 2, 11, 23 ) ), 'inactive stage blocked' );
$assert( [] === $wpdb->opportunities && [] === $wpdb->events, 'validation does not create Sales records' );

$baseline = serialize( [ $wpdb->leads, $wpdb->opportunities, $wpdb->participants, $wpdb->events ] );
foreach ( [ 'opportunity', 'participant', 'event', 'lead_event' ] as $step ) {
	$wpdb->fail_insert = $step;
	$assert( 'crm_sales_storage_failed' === $code( $convert() ), "injected {$step} failure rejected" );
	$assert( $baseline === serialize( [ $wpdb->leads, $wpdb->opportunities, $wpdb->participants, $wpdb->events ] ), "{$step} failure rolled back all Sales writes" );
}
$wpdb->fail_insert = '';
$wpdb->fail_update = true;
$assert( 'crm_sales_storage_failed' === $code( $convert() ), 'Lead update failure rejected' );
$assert( $baseline === serialize( [ $wpdb->leads, $wpdb->opportunities, $wpdb->participants, $wpdb->events ] ), 'Lead update rolled back' );
$wpdb->fail_update = false;
$wpdb->fail_commit = true;
$assert( 'crm_sales_storage_failed' === $code( $convert() ), 'commit failure rejected' );
$assert( $baseline === serialize( [ $wpdb->leads, $wpdb->opportunities, $wpdb->participants, $wpdb->events ] ), 'commit failure rolled back' );
$wpdb->fail_commit = false;
$wpdb->fail_read = true;
$assert( 'crm_sales_storage_failed' === $code( $convert() ), 'locked read failure rejected' );
$wpdb->fail_read = false;
$assert( $baseline === serialize( [ $wpdb->leads, $wpdb->opportunities, $wpdb->participants, $wpdb->events ] ), 'read failure left no writes' );

$opportunity_id = $convert();
$assert( is_int( $opportunity_id ) && $opportunity_id > 0, 'first conversion succeeded' );
$assert( $opportunity_id === $wpdb->leads[4]['converted_opportunity_id'], 'Lead reciprocal link saved' );
$assert( 'converted' === $wpdb->leads[4]['status'] && 3 === $wpdb->leads[4]['revision'], 'Lead status and revision saved' );
$assert( 4 === $wpdb->opportunities[$opportunity_id]['source_lead_id'], 'Opportunity source link saved' );
$assert( 'Synthetic Lead' === $wpdb->opportunities[$opportunity_id]['title'], 'Lead title copied without PII lookup' );
$assert( 15 === $wpdb->opportunities[$opportunity_id]['owner_user_id'], 'canonical owner preserved' );
$assert( 1 === $wpdb->opportunities[$opportunity_id]['revision'], 'Opportunity revision initialized' );
$assert( 2 === count( array_filter( $wpdb->participants, static fn ( array $p ) => 'opportunity' === $p['entity_type'] ) ), 'both parties copied' );
$assert( 2 === count( $wpdb->events ), 'two conversion events inserted' );
$assert( 'created' === $wpdb->events[0]['event_type'] && 'converted' === $wpdb->events[1]['event_type'], 'event types correct' );
$assert( 17 === $wpdb->events[0]['actor_user_id'] && 3 === $wpdb->events[1]['revision'], 'canonical actor and revision preserved' );

$after = serialize( [ $wpdb->leads, $wpdb->opportunities, $wpdb->participants, $wpdb->events ] );
$assert( $opportunity_id === $convert(), 'exact replay returns same Opportunity' );
$assert( $opportunity_id === $convert( 4, 999 ), 'stale post-success replay is safe' );
$assert( $after === serialize( [ $wpdb->leads, $wpdb->opportunities, $wpdb->participants, $wpdb->events ] ), 'replay writes nothing' );

echo "CRM Sales B2 qualified conversion/replay/rollback smoke passed.\n";

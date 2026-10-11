<?php
declare(strict_types=1);

/**
 * Deterministic, isolated persistence regression. No real WP site or DB writes.
 * Production WordPress/MariaDB transactional migration still needs staging.
 */
$root = dirname( __DIR__ );
define( 'ABSPATH', $root . '/' );
define( 'ARRAY_A', 'ARRAY_A' );
define( 'CB_CRM_SCHEMA_VERSION', '1.6' );

final class WP_Error {
	public function __construct( private string $code, private string $message = '' ) {}
	public function get_error_code(): string { return $this->code; }
}

$GLOBALS['sales_ready'] = false;
$GLOBALS['sales_authorized'] = false;
$GLOBALS['sales_allowed_caps'] = [ 'cb_manage_crm', 'cb_view_crm_sales', 'cb_manage_crm_sales' ];
$GLOBALS['sales_actor'] = 17;
$GLOBALS['sales_schema_version'] = '1.6';
// Simulates a WordPress cached identity map. The SQL map below can diverge.
$GLOBALS['sales_identity'] = [
	42 => [ 'type' => 'cb_crm_org', 'status' => 'publish' ],
	7 => [ 'type' => 'cb_crm_contact', 'status' => 'publish' ],
	8 => [ 'type' => 'cb_crm_contact', 'status' => 'trash' ],
	9 => [ 'type' => 'post', 'status' => 'publish' ],
];
$GLOBALS['sales_sql_identity'] = $GLOBALS['sales_identity'];
function cb_crm_runtime_ready(): bool { return $GLOBALS['sales_ready']; }
function current_user_can( string $capability ): bool {
	return $GLOBALS['sales_authorized'] && in_array( $capability, $GLOBALS['sales_allowed_caps'], true );
}
function get_current_user_id(): int { return $GLOBALS['sales_actor']; }
function get_option( string $key, mixed $default = '' ): mixed {
	return 'cb_crm_db_version' === $key ? $GLOBALS['sales_schema_version'] : $default;
}
function sanitize_key( string $key ): string {
	return strtolower( preg_replace( '/[^a-z0-9_\-]/', '', $key ) ?? '' );
}
function sanitize_text_field( string $text ): string { return trim( strip_tags( $text ) ); }
function current_time( string $type, bool $gmt = false ): string {
	if ( 'mysql' !== $type || ! $gmt ) { throw new RuntimeException( 'Expected UTC time.' ); }
	return '2026-10-11 09:00:00';
}
function get_post_type( int $id ): string|false {
	return $GLOBALS['sales_identity'][ $id ]['type'] ?? false;
}
function get_post_status( int $id ): string|false {
	return $GLOBALS['sales_identity'][ $id ]['status'] ?? false;
}

final class CRM_Sales_Fake_Wpdb {
	public string $prefix = 'wp_';
	public string $posts = 'wp_posts';
	public string $last_error = '';
	public bool $fail_identity_read = false;
	public array $locked_identity_ids = [];
	public int $insert_id = 0;
	/** @var array<string,list<array<string,mixed>>> */
	public array $rows = [];
	/** @var array<int,array<string,mixed>> */
	public array $pipelines = [
		10 => [ 'id' => 10, 'entity_type' => 'lead', 'is_active' => 1 ],
		11 => [ 'id' => 11, 'entity_type' => 'opportunity', 'is_active' => 1 ],
		12 => [ 'id' => 12, 'entity_type' => 'lead', 'is_active' => 0 ],
	];
	/** @var array<int,array<string,mixed>> */
	public array $stages = [
		20 => [ 'id' => 20, 'pipeline_id' => 10, 'is_active' => 1 ],
		21 => [ 'id' => 21, 'pipeline_id' => 11, 'is_active' => 1 ],
		22 => [ 'id' => 22, 'pipeline_id' => 10, 'is_active' => 0 ],
	];
	public bool $fail_commit = false;
	public string $fail_insert = '';
	public int $query_count = 0;
	private ?array $snapshot = null;
	private int $next_id = 1;

	public function prepare( string $sql, mixed ...$values ): string {
		foreach ( $values as $value ) {
			if ( ! is_int( $value ) ) { throw new RuntimeException( 'Unexpected unsafe SQL argument.' ); }
			if ( ! str_contains( $sql, '%d' ) ) { throw new RuntimeException( 'Expected SQL placeholder.' ); }
			$sql = preg_replace( '/%d/', (string) $value, $sql, 1 ) ?? '';
		}
		return $sql;
	}
	public function get_row( string $sql, string $format ): ?array {
		++$this->query_count;
		if ( ARRAY_A !== $format || ! preg_match( '/WHERE (?:id|ID) = ([0-9]+) LIMIT 1/', $sql, $m ) ) {
			throw new RuntimeException( 'Unexpected Sales read SQL.' );
		}
		$id = (int) $m[1];
		if ( str_contains( $sql, 'FROM wp_posts' ) ) {
			if ( ! str_contains( $sql, 'FOR UPDATE' ) ) {
				throw new RuntimeException( 'Identity read must lock the canonical WordPress row.' );
			}
			$this->locked_identity_ids[] = $id;
			if ( $this->fail_identity_read ) {
				$this->last_error = 'simulated database identity read failure';
				return null;
			}
			return isset( $GLOBALS['sales_sql_identity'][ $id ] )
				? [ 'post_type' => $GLOBALS['sales_sql_identity'][ $id ]['type'], 'post_status' => $GLOBALS['sales_sql_identity'][ $id ]['status'] ]
				: null;
		}
		if ( str_contains( $sql, 'cb_crm_sales_pipelines' ) ) {
			return $this->pipelines[ $id ] ?? null;
		}
		if ( str_contains( $sql, 'cb_crm_sales_stages' ) ) {
			return $this->stages[ $id ] ?? null;
		}
		if ( str_contains( $sql, 'cb_crm_sales_leads' ) || str_contains( $sql, 'cb_crm_sales_opportunities' ) ) {
			$table = str_contains( $sql, 'cb_crm_sales_leads' ) ? 'wp_cb_crm_sales_leads' : 'wp_cb_crm_sales_opportunities';
			foreach ( $this->rows[ $table ] ?? [] as $row ) {
				if ( $row['id'] === $id ) { return $row; }
			}
			return null;
		}
		throw new RuntimeException( 'Unknown Sales table.' );
	}
	public function insert( string $table, array $data, array $formats ): int|false {
		++$this->query_count;
		if ( $this->fail_insert === $table ) { return false; }
		if ( count( $formats ) !== count( $data ) ) {
			throw new RuntimeException( 'Sales SQL insert formatting is invalid.' );
		}
		$id = $this->next_id++;
		$this->insert_id = $id;
		$this->rows[ $table ][] = [ 'id' => $id ] + $data;
		return 1;
	}
	public function query( string $sql ): int|false {
		++$this->query_count;
		if ( 'START TRANSACTION' === $sql ) {
			$this->snapshot = [ $this->rows, $this->next_id ];
			return 1;
		}
		if ( 'ROLLBACK' === $sql ) {
			if ( null !== $this->snapshot ) {
				[ $this->rows, $this->next_id ] = $this->snapshot;
			}
			$this->snapshot = null;
			return 1;
		}
		if ( 'COMMIT' === $sql ) {
			if ( $this->fail_commit ) { return false; }
			$this->snapshot = null;
			return 1;
		}
		throw new RuntimeException( 'Unexpected Sales transaction SQL.' );
	}
}
$GLOBALS['wpdb'] = new CRM_Sales_Fake_Wpdb();
require $root . '/src/Capabilities.php';
require $root . '/src/Content/PostTypes.php';
require $root . '/src/Content/Entity.php';
require $root . '/src/Database/Schema.php';
require $root . '/src/Domain/Sales/Access.php';
require $root . '/src/Domain/Sales/Rules.php';
require $root . '/src/Repository/Sales/Records.php';

use CB\CRM\Repository\Sales\Records;

$assert = static function ( bool $condition, string $message ): void {
	if ( ! $condition ) {
		fwrite( STDERR, 'CRM Sales persistence smoke failed: ' . $message . "\n" );
		exit( 1 );
	}
};
$wpdb = $GLOBALS['wpdb'];
$lead = [ 'title' => 'Private lead', 'pipeline_id' => 10, 'stage_id' => 20 ];
$parties = [ [ 'party_type' => 'organization', 'party_id' => 42 ] ];
$code = static fn ( mixed $result ): string => $result instanceof WP_Error ? $result->get_error_code() : '';

$assert( 'crm_sales_forbidden' === $code( Records::create_lead( $lead, $parties ) ), 'unready runtime cannot write' );
$assert( 0 === $wpdb->query_count, 'denied action did not query storage' );
$GLOBALS['sales_ready'] = true;
$assert( 'crm_sales_forbidden' === $code( Records::create_lead( $lead, $parties ) ), 'unauthorised staff cannot write' );
$GLOBALS['sales_authorized'] = true;
$GLOBALS['sales_schema_version'] = '1.5';
$assert( 'crm_sales_forbidden' === $code( Records::create_lead( $lead, $parties ) ), 'unmigrated schema cannot write' );
$GLOBALS['sales_schema_version'] = '1.6';
$GLOBALS['sales_actor'] = 0;
$assert( 'crm_sales_forbidden' === $code( Records::create_lead( $lead, $parties ) ), 'anonymous actor refused' );
$GLOBALS['sales_actor'] = 17;

$assert( 'crm_sales_invalid_input' === $code( Records::create_lead( [ 'title' => 'Bad', 'pipeline_id' => '10', 'stage_id' => 20 ], $parties ) ), 'numeric string ID rejected' );
$assert( 'crm_sales_invalid_input' === $code( Records::create_lead( $lead + [ 'owner_user_id' => 999 ], $parties ) ), 'owner impersonation rejected' );
$assert( 'crm_sales_invalid_participants' === $code( Records::create_lead( $lead, [] ) ), 'orphan record rejected' );
$assert( 'crm_sales_invalid_participants' === $code( Records::create_lead( $lead, [ $parties[0], $parties[0] ] ) ), 'duplicate relation rejected' );

$assert( 'crm_sales_invalid_pipeline' === $code( Records::create_lead( [ 'title' => 'Wrong pipeline', 'pipeline_id' => 11, 'stage_id' => 20 ], $parties ) ), 'deal pipeline cannot hold a Lead' );
$assert( 'crm_sales_invalid_pipeline' === $code( Records::create_lead( [ 'title' => 'Disabled', 'pipeline_id' => 12, 'stage_id' => 20 ], $parties ) ), 'inactive pipeline rejected' );
$assert( 'crm_sales_invalid_stage' === $code( Records::create_lead( [ 'title' => 'Wrong stage', 'pipeline_id' => 10, 'stage_id' => 21 ], $parties ) ), 'foreign stage rejected' );
$assert( 'crm_sales_invalid_stage' === $code( Records::create_lead( [ 'title' => 'Inactive stage', 'pipeline_id' => 10, 'stage_id' => 22 ], $parties ) ), 'inactive stage rejected' );
$assert( 'crm_sales_invalid_identity' === $code( Records::create_lead( $lead, [ [ 'party_type' => 'contact', 'party_id' => 999 ] ] ) ), 'nonexistent contact rejected' );
$assert( 'crm_sales_invalid_identity' === $code( Records::create_lead( $lead, [ [ 'party_type' => 'contact', 'party_id' => 8 ] ] ) ), 'trashed contact rejected' );
$assert( 'crm_sales_invalid_identity' === $code( Records::create_lead( $lead, [ [ 'party_type' => 'contact', 'party_id' => 9 ] ] ) ), 'non-CRM post rejected' );
$assert( [] === $wpdb->rows, 'failed validation did not persist rows' );

// Cached WP identity may survive a concurrent permanent deletion. The
// authoritative FOR UPDATE DB read must nevertheless reject a new Sales link.
$previous_sql_contact = $GLOBALS['sales_sql_identity'][7];
unset( $GLOBALS['sales_sql_identity'][7] );
$assert( 'cb_crm_contact' === get_post_type( 7 ), 'fixture retains stale cached Contact' );
$assert( 'crm_sales_invalid_identity' === $code( Records::create_lead( $lead, [ [ 'party_type' => 'contact', 'party_id' => 7 ] ] ) ), 'stale WordPress identity cache does not permit Sales insert' );
$GLOBALS['sales_sql_identity'][7] = $previous_sql_contact;
$wpdb->fail_identity_read = true;
$assert( 'crm_sales_storage_failed' === $code( Records::create_lead( $lead, $parties ) ), 'canonical identity read error fails closed' );
$wpdb->fail_identity_read = false;
$assert( [] === $wpdb->rows, 'concurrent deletion and database-error paths did not persist Sales records' );

$id = Records::create_lead( $lead, $parties );
$assert( is_int( $id ) && $id > 0, 'Lead created' );
$assert( 17 === $wpdb->rows['wp_cb_crm_sales_leads'][0]['owner_user_id'], 'actor is canonical owner' );
$assert( 'open' === $wpdb->rows['wp_cb_crm_sales_leads'][0]['status'], 'created Lead starts open' );
$assert( 1 === count( $wpdb->rows['wp_cb_crm_sales_participants'] ), 'participant created' );
$assert( in_array( 42, $wpdb->locked_identity_ids, true ), 'canonical identity row selected FOR UPDATE' );
$assert( 'other' === $wpdb->rows['wp_cb_crm_sales_participants'][0]['role'], 'default role persisted' );
$assert( 1 === count( $wpdb->rows['wp_cb_crm_sales_events'] ), 'created event stored atomically' );
$assert( 'created' === $wpdb->rows['wp_cb_crm_sales_events'][0]['event_type'], 'created audit event type' );
$assert( '' === $wpdb->rows['wp_cb_crm_sales_events'][0]['to_outcome'], 'Lead status must not masquerade as deal outcome' );
$assert( is_array( Records::get( 'lead', $id ) ), 'authorised staff can read Lead' );
$assert( null === Records::get( 'lead', 999 ), 'missing Sales record is null' );
$GLOBALS['sales_allowed_caps'] = [ 'cb_manage_crm', 'cb_view_crm_sales' ];
$assert( is_array( Records::get( 'lead', $id ) ), 'Sales viewer can read Lead' );
$assert( 'crm_sales_forbidden' === $code( Records::create_lead( $lead, $parties ) ), 'Sales viewer cannot create Lead' );
$GLOBALS['sales_allowed_caps'] = [ 'cb_manage_crm', 'cb_manage_crm_sales' ];
$assert( is_array( Records::get( 'lead', $id ) ), 'Sales manager read needs no separate viewer grant' );
$GLOBALS['sales_allowed_caps'] = [ 'cb_manage_crm', 'cb_view_crm_sales', 'cb_manage_crm_sales' ];

$deal = [ 'title' => 'Proposal', 'pipeline_id' => 11, 'stage_id' => 21, 'amount_minor' => 129900, 'currency' => 'EUR' ];
$assert( 'crm_sales_invalid_input' === $code( Records::create_opportunity( $deal + [ 'invalid' => true ], $parties ) ), 'unexpected opportunity field rejected' );
$assert( 'crm_sales_invalid_estimate' === $code( Records::create_opportunity( array_replace( $deal, [ 'amount_minor' => -5 ] ), $parties ) ), 'negative estimate rejected' );
$assert( 'crm_sales_invalid_input' === $code( Records::create_opportunity( $deal + [ 'owner_user_id' => 55 ], $parties ) ), 'owner override rejected' );
$deal_id = Records::create_opportunity( $deal, [ $parties[0], [ 'party_type' => 'contact', 'party_id' => 7, 'role' => 'decision_maker' ] ] );
$assert( is_int( $deal_id ) && $deal_id > 0, 'Opportunity created' );
$assert( 129900 === $wpdb->rows['wp_cb_crm_sales_opportunities'][0]['amount_minor'], 'amount stored in minor units' );
$assert( 'EUR' === $wpdb->rows['wp_cb_crm_sales_opportunities'][0]['currency'], 'ISO currency stored' );
$assert( 'open' === $wpdb->rows['wp_cb_crm_sales_events'][1]['to_outcome'], 'Opportunity outcome belongs to deal history' );
$assert( 3 === count( $wpdb->rows['wp_cb_crm_sales_participants'] ), 'multiple opportunity participants supported' );
$assert( [ 7, 42 ] === array_slice( $wpdb->locked_identity_ids, -2 ), 'multi-participant identity row locks acquired in stable ascending ID order' );

$before = serialize( $wpdb->rows );
$wpdb->fail_insert = 'wp_cb_crm_sales_participants';
$assert( 'crm_sales_storage_failed' === $code( Records::create_lead( $lead, $parties ) ), 'participant insert failure rejects record' );
$assert( $before === serialize( $wpdb->rows ), 'failed participant insert rolled back main record' );
$wpdb->fail_insert = 'wp_cb_crm_sales_events';
$assert( 'crm_sales_storage_failed' === $code( Records::create_lead( $lead, $parties ) ), 'event insert failure rejects record' );
$assert( $before === serialize( $wpdb->rows ), 'failed event insert rolled back all records' );
$wpdb->fail_insert = '';
$wpdb->fail_commit = true;
$assert( 'crm_sales_storage_failed' === $code( Records::create_lead( $lead, $parties ) ), 'failed commit rejects record' );
$assert( $before === serialize( $wpdb->rows ), 'failed commit rolled back all records' );
$wpdb->fail_commit = false;

$GLOBALS['sales_authorized'] = false;
$assert( 'crm_sales_forbidden' === $code( Records::get( 'lead', $id ) ), 'unauthorised read fails closed' );
$assert( 'crm_sales_forbidden' === $code( Records::get( 'opportunity', $deal_id ) ), 'unauthorised deal read fails closed' );

echo "CRM Sales records persistence smoke passed.\n";

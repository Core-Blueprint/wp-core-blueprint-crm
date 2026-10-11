<?php
declare(strict_types=1);

/** Isolated Sales data-seeding regression, not a real MariaDB integration test. */
$root = dirname( __DIR__ );
define( 'ABSPATH', $root . '/' );
define( 'ARRAY_A', 'ARRAY_A' );
define( 'CB_CRM_SCHEMA_VERSION', '1.6' );

$GLOBALS['crm_sales_options'] = [
	'cb_crm_db_version' => '1.5',
];
$GLOBALS['crm_sales_fail_option_update'] = false;
function get_option( string $key, mixed $default = '' ): mixed {
	return $GLOBALS['crm_sales_options'][ $key ] ?? $default;
}
function update_option( string $key, mixed $value, bool $autoload = false ): bool {
	if ( $GLOBALS['crm_sales_fail_option_update'] ) {
		return false;
	}
	$GLOBALS['crm_sales_options'][ $key ] = $value;
	return true;
}
function current_time( string $type, bool $gmt = false ): string {
	if ( 'mysql' !== $type || ! $gmt ) {
		throw new RuntimeException( 'Expected UTC timestamp.' );
	}
	return '2026-10-11 09:00:00';
}

final class CRM_Defaults_Fake_Db {
	public string $prefix = 'wp_';
	public string $last_error = '';
	public int $insert_id = 0;
	public int $queries = 0;
	public bool $fail_commit = false;
	public string $fail_insert_on_table = '';
	public int $fail_stage_at = 0;
	public bool $fail_read = false;
	/** @var array<string,array<int,array<string,mixed>>> */
	public array $tables = [
		'wp_cb_crm_sales_pipelines' => [],
		'wp_cb_crm_sales_stages' => [],
	];
	private ?array $snapshot = null;
	private int $next_id = 1;
	private int $stage_inserts = 0;

	public function prepare( string $sql, mixed ...$values ): string {
		$index = 0;
		$result = preg_replace_callback( '/%[ds]/', static function ( array $m ) use ( &$index, $values ): string {
			$value = $values[ $index++ ] ?? null;
			if ( '%d' === $m[0] ) {
				if ( ! is_int( $value ) ) { throw new RuntimeException( 'Non-integer bound as SQL ID.' ); }
				return (string) $value;
			}
			if ( ! is_string( $value ) ) { throw new RuntimeException( 'Non-string SQL key.' ); }
			return "'" . str_replace( "'", "''", $value ) . "'";
		}, $sql );
		if ( $index !== count( $values ) || null === $result ) {
			throw new RuntimeException( 'Invalid SQL placeholders.' );
		}
		return $result;
	}

	public function get_row( string $sql, string $format ): ?array {
		++$this->queries;
		$this->last_error = '';
		if ( ARRAY_A !== $format ) {
			throw new RuntimeException( 'Expected ARRAY_A.' );
		}
		if ( $this->fail_read ) {
			$this->last_error = 'select_failed';
			return null;
		}
		if ( preg_match( "/FROM wp_cb_crm_sales_pipelines WHERE pipeline_key = '([^']+)' LIMIT 1 FOR UPDATE/", $sql, $m ) ) {
			foreach ( $this->tables['wp_cb_crm_sales_pipelines'] as $row ) {
				if ( $row['pipeline_key'] === $m[1] ) {
					return [ 'id' => (string) $row['id'], 'entity_type' => $row['entity_type'] ];
				}
			}
			return null;
		}
		if ( preg_match( "/FROM wp_cb_crm_sales_stages WHERE pipeline_id = (\d+) AND stage_key = '([^']+)' LIMIT 1 FOR UPDATE/", $sql, $m ) ) {
			foreach ( $this->tables['wp_cb_crm_sales_stages'] as $row ) {
				if ( (int) $row['pipeline_id'] === (int) $m[1] && $row['stage_key'] === $m[2] ) {
					return [ 'id' => (string) $row['id'] ];
				}
			}
			return null;
		}
		throw new RuntimeException( 'Unknown Sales SQL lookup.' );
	}

	public function insert( string $table, array $data, array $formats ): int|false {
		++$this->queries;
		if ( count( $data ) !== count( $formats ) ) {
			throw new RuntimeException( 'INSERT placeholder mismatch.' );
		}
		if ( ! isset( $this->tables[ $table ] ) || $table === $this->fail_insert_on_table ) {
			return false;
		}
		if ( 'wp_cb_crm_sales_stages' === $table ) {
			++$this->stage_inserts;
			if ( $this->fail_stage_at > 0 && $this->stage_inserts === $this->fail_stage_at ) {
				return false;
			}
		}
		foreach ( $this->tables[ $table ] as $row ) {
			if ( 'wp_cb_crm_sales_pipelines' === $table && $row['pipeline_key'] === $data['pipeline_key'] ) {
				return false;
			}
			if ( 'wp_cb_crm_sales_stages' === $table
				&& $row['pipeline_id'] === $data['pipeline_id']
				&& $row['stage_key'] === $data['stage_key'] ) {
				return false;
			}
		}
		$this->insert_id = $this->next_id++;
		$this->tables[ $table ][] = [ 'id' => $this->insert_id ] + $data;
		return 1;
	}

	public function query( string $sql ): int|false {
		++$this->queries;
		if ( 'START TRANSACTION' === $sql ) {
			$this->snapshot = [ $this->tables, $this->next_id, $this->stage_inserts ];
			return 1;
		}
		if ( 'COMMIT' === $sql ) {
			if ( $this->fail_commit ) {
				return false;
			}
			$this->snapshot = null;
			return 1;
		}
		if ( 'ROLLBACK' === $sql ) {
			if ( null !== $this->snapshot ) {
				[ $this->tables, $this->next_id, $this->stage_inserts ] = $this->snapshot;
			}
			$this->snapshot = null;
			return 1;
		}
		throw new RuntimeException( 'Unexpected transaction statement.' );
	}
}

require_once $root . '/src/Database/Schema.php';
require_once $root . '/src/Application/Sales/DefaultPipelines.php';

use CB\CRM\Application\Sales\DefaultPipelines as Defaults;

$assert = static function ( bool $condition, string $message ): void {
	if ( ! $condition ) {
		fwrite( STDERR, "CRM Sales default pipelines smoke failed: {$message}\n" );
		exit( 1 );
	}
};
$GLOBALS['wpdb'] = new CRM_Defaults_Fake_Db();
$db = $GLOBALS['wpdb'];

$assert( ! Defaults::maybe_install(), 'schema 1.5 must never be seeded' );
$assert( 0 === $db->queries, 'schema gate did not touch tables' );
$GLOBALS['crm_sales_options']['cb_crm_db_version'] = '1.6';

$assert( Defaults::maybe_install(), 'first seed completes' );
$assert( 2 === count( $db->tables['wp_cb_crm_sales_pipelines'] ), 'one typed default per commercial entity' );
$assert( 7 === count( $db->tables['wp_cb_crm_sales_stages'] ), 'four qualification and three deal stages' );
$assert( '1' === $GLOBALS['crm_sales_options'][ Defaults::OPTION ], 'completion marker written only after commit' );
$assert( [ 'new', 'attempting_contact', 'connected', 'qualified' ] === array_column(
	array_slice( $db->tables['wp_cb_crm_sales_stages'], 0, 4 ), 'stage_key'
), 'lead stages use stable canonical keys' );
$assert( [ 'discovery', 'proposal', 'decision' ] === array_column(
	array_slice( $db->tables['wp_cb_crm_sales_stages'], 4 ), 'stage_key'
), 'deal stages use stable canonical keys' );

$baseline = serialize( $db->tables );
$count = $db->queries;
$assert( Defaults::maybe_install(), 'marked installation is no-op' );
$assert( $count === $db->queries, 'marked installation does not touch SQL' );

$db->tables['wp_cb_crm_sales_pipelines'][0]['name'] = 'Custom Qualification';
$db->tables['wp_cb_crm_sales_stages'][0]['name'] = 'Intake';
$db->tables['wp_cb_crm_sales_stages'][0]['is_active'] = 0;
$edited = serialize( $db->tables );
unset( $GLOBALS['crm_sales_options'][ Defaults::OPTION ] );
$assert( Defaults::maybe_install(), 'retry of already-seeded default records succeeds' );
$assert( $edited === serialize( $db->tables ), 'retry preserves custom names and disabled stage' );
$assert( '1' === $GLOBALS['crm_sales_options'][ Defaults::OPTION ], 'retry repairs marker' );

// A partial seed failure must be atomic, have no marker and be retryable.
unset( $GLOBALS['crm_sales_options'][ Defaults::OPTION ] );
$GLOBALS['wpdb'] = new CRM_Defaults_Fake_Db();
$db = $GLOBALS['wpdb'];
$db->fail_stage_at = 3;
$assert( ! Defaults::maybe_install(), 'failed third stage insert rejects seed' );
$assert( [] === $db->tables['wp_cb_crm_sales_pipelines'], 'pipeline inserts rolled back' );
$assert( [] === $db->tables['wp_cb_crm_sales_stages'], 'stage inserts rolled back' );
$assert( ! isset( $GLOBALS['crm_sales_options'][ Defaults::OPTION ] ), 'failed seed has no completion marker' );
$db->fail_stage_at = 0;
$assert( Defaults::maybe_install(), 'failed seed can retry from scratch' );

// Detect reserved-key ownership conflicts instead of rewriting user data.
unset( $GLOBALS['crm_sales_options'][ Defaults::OPTION ] );
$db->tables['wp_cb_crm_sales_pipelines'][0]['entity_type'] = 'opportunity';
$conflicting = serialize( $db->tables );
$assert( ! Defaults::maybe_install(), 'typed pipeline key collision fails closed' );
$assert( $conflicting === serialize( $db->tables ), 'key collision leaves existing data untouched' );

// If COMMIT fails, no rows or completion marker may survive.
unset( $GLOBALS['crm_sales_options'][ Defaults::OPTION ] );
$GLOBALS['wpdb'] = new CRM_Defaults_Fake_Db();
$db = $GLOBALS['wpdb'];
$db->fail_commit = true;
$assert( ! Defaults::maybe_install(), 'commit failure blocks installation' );
$assert( [] === $db->tables['wp_cb_crm_sales_pipelines'], 'commit failure rolled back Sales pipelines' );
$assert( ! isset( $GLOBALS['crm_sales_options'][ Defaults::OPTION ] ), 'commit failure leaves marker absent' );
$db->fail_commit = false;

// If WP option persistence fails after commit, next retry preserves rows.
$GLOBALS['crm_sales_fail_option_update'] = true;
$assert( ! Defaults::maybe_install(), 'failed completion marker surfaces a failure' );
$persisted = serialize( $db->tables );
$GLOBALS['crm_sales_fail_option_update'] = false;
$assert( Defaults::maybe_install(), 'marker persistence can safely retry' );
$assert( $persisted === serialize( $db->tables ), 'retry after option failure did not duplicate records' );

// Query failure is not mistaken for a missing pipeline.
unset( $GLOBALS['crm_sales_options'][ Defaults::OPTION ] );
$db->fail_read = true;
$before = serialize( $db->tables );
$assert( ! Defaults::maybe_install(), 'read failure rejects seed' );
$assert( $before === serialize( $db->tables ), 'failed read makes no writes' );

echo "CRM Sales default pipelines smoke passed.\n";

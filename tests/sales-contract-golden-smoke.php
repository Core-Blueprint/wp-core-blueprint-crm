<?php
declare(strict_types=1);

/**
 * C3 joint read/write contract acceptance with actual C1/C2 classes.
 * No WordPress installation, SQL server, external service or data mutation.
 */
namespace CB\CRM\Repository\Sales {
	final class Records {
		public static function create_lead( array $input, array $participants ): int|\WP_Error {
			$GLOBALS['c3_writes'][] = [ 'create_lead', $input, $participants ];
			return 101;
		}
		public static function create_opportunity( array $input, array $participants ): int|\WP_Error {
			$GLOBALS['c3_writes'][] = [ 'create_opportunity', $input, $participants ];
			return 201;
		}
	}
	final class Transitions {
		public static function set_lead_status( int $id, string $status, int $revision ): int|\WP_Error {
			$GLOBALS['c3_writes'][] = [ 'lead_status', $id, $status, $revision ];
			return 2 === $revision ? new \WP_Error( 'crm_sales_conflict' ) : $revision + 1;
		}
		public static function move_stage( string $type, int $id, int $stage_id, int $revision ): int|\WP_Error {
			$GLOBALS['c3_writes'][] = [ 'move_stage', $type, $id, $stage_id, $revision ];
			return $revision + 1;
		}
	}
	final class Conversion {
		public static function convert( int $id, int $revision, int $pipeline, int $stage ): int|\WP_Error {
			$GLOBALS['c3_writes'][] = [ 'convert', $id, $revision, $pipeline, $stage ];
			return 301;
		}
	}
	final class Outcomes {
		public static function close( int $id, string $outcome, int $revision ): int|\WP_Error {
			$GLOBALS['c3_writes'][] = [ 'close', $id, $outcome, $revision ];
			return $revision + 1;
		}
		public static function reopen( int $id, int $revision ): int|\WP_Error {
			$GLOBALS['c3_writes'][] = [ 'reopen', $id, $revision ];
			return $revision + 1;
		}
	}
}

namespace {
	use CB\CRM\Application\Sales\StaffActions;
	use CB\CRM\Repository\Sales\StaffProjections;

	$root = dirname( __DIR__ );
	define( 'ABSPATH', $root . '/' );
	define( 'ARRAY_A', 'ARRAY_A' );
	define( 'CB_CRM_SCHEMA_VERSION', '1.6' );
	final class WP_Error {
		public function __construct( private string $code ) {}
		public function get_error_code(): string { return $this->code; }
	}
	$GLOBALS['c3_ready'] = false;
	$GLOBALS['c3_caps'] = [];
	$GLOBALS['c3_actor'] = 19;
	$GLOBALS['c3_schema'] = '1.6';
	$GLOBALS['c3_writes'] = [];
	function cb_crm_runtime_ready(): bool { return $GLOBALS['c3_ready']; }
	function current_user_can( string $capability ): bool { return in_array( $capability, $GLOBALS['c3_caps'], true ); }
	function get_current_user_id(): int { return $GLOBALS['c3_actor']; }
	function get_option( string $name, mixed $default = '' ): mixed {
		return 'cb_crm_db_version' === $name ? $GLOBALS['c3_schema'] : $default;
	}
	final class C3_Fake_Wpdb {
		public string $prefix = 'wp_';
		public string $last_error = '';
		public array $reads = [];

		public function prepare( string $query, mixed ...$args ): string {
			foreach ( $args as $arg ) {
				$placeholder = is_int( $arg ) ? '%d' : '%s';
				if ( ! str_contains( $query, $placeholder ) ) {
					throw new \RuntimeException( 'Unbound fixture SQL parameter.' );
				}
				$value = is_int( $arg ) ? (string) $arg : "'" . str_replace( "'", "''", (string) $arg ) . "'";
				$query = preg_replace( '/' . $placeholder . '/', $value, $query, 1 ) ?? '';
			}
			return $query;
		}
		public function get_results( string $sql, string $mode ): array {
			$this->reads[] = $sql;
			if ( ARRAY_A !== $mode || str_contains( $sql, 'SELECT *' )
				|| str_contains( $sql, ' JOIN ' ) || str_contains( $sql, ' OFFSET ' )
				|| ! preg_match( '/LIMIT ([0-9]+)$/', $sql, $m )
				|| (int) $m[1] > 51 ) {
				throw new \RuntimeException( 'Unbounded or broad C1 query.' );
			}
			if ( str_contains( $sql, 'sales_events' ) ) {
				return [
					[ 'id' => 90, 'event_type' => 'created', 'from_stage_id' => null, 'to_stage_id' => 3,
						'from_outcome' => '', 'to_outcome' => '', 'actor_user_id' => 19,
						'revision' => 1, 'created_at' => '2026-10-11 12:00:00' ],
				];
			}
			if ( str_contains( $sql, 'sales_participants' ) ) {
				return [ [ 'party_type' => 'contact', 'party_id' => 42, 'role' => 'primary_contact' ] ];
			}
			return [ [
				'id' => 41, 'title' => 'Synthetic, potentially confidential title',
				'owner_user_id' => 17, 'pipeline_id' => 2, 'stage_id' => 3,
				'state' => 'open', 'next_action_on' => null, 'revision' => 1,
				'created_at' => '2026-10-11 12:00:00', 'updated_at' => '2026-10-11 12:00:00',
			] ];
		}
		public function get_row( string $sql, string $mode ): ?array {
			$this->reads[] = $sql;
			if ( ARRAY_A !== $mode || ! str_contains( $sql, 'WHERE id = 41 LIMIT 1' )
				|| str_contains( $sql, 'SELECT *' ) ) {
				throw new \RuntimeException( 'C1 detail selected unexpected SQL.' );
			}
			return [
				'id' => 41, 'title' => 'Synthetic, potentially confidential title',
				'owner_user_id' => 17, 'pipeline_id' => 2, 'stage_id' => 3,
				'state' => 'open', 'next_action_on' => null, 'revision' => 1,
				'created_at' => '2026-10-11 12:00:00', 'updated_at' => '2026-10-11 12:00:00',
				'source' => 'referral', 'converted_opportunity_id' => null,
				'qualified_at' => null, 'disqualified_at' => null,
			];
		}
		public function get_var( string $sql ): int {
			$this->reads[] = $sql;
			return 41;
		}
	}
	$GLOBALS['wpdb'] = new C3_Fake_Wpdb();
	require $root . '/src/Capabilities.php';
	require $root . '/src/Database/Schema.php';
	require $root . '/src/Domain/Sales/Access.php';
	require $root . '/src/Domain/Sales/Rules.php';
	require $root . '/src/Repository/Sales/StaffProjections.php';
	require $root . '/src/Application/Sales/StaffActions.php';

	$assert = static function ( bool $ok, string $message ): void {
		if ( ! $ok ) {
			fwrite( STDERR, "CRM C3 contract golden smoke FAIL: {$message}\n" );
			exit( 1 );
		}
	};
	$code = static fn ( mixed $result ): string => $result instanceof WP_Error ? $result->get_error_code() : '';
	$lead = [ 'title' => 'Synthetic', 'pipeline_id' => 2, 'stage_id' => 3 ];
	$parties = [ [ 'party_type' => 'contact', 'party_id' => 42, 'role' => 'primary_contact' ] ];
	$readers = [
		static fn () => StaffProjections::page( 'lead', [], 0, 2 ),
		static fn () => StaffProjections::detail( 'lead', 41 ),
		static fn () => StaffProjections::history( 'lead', 41, 0, 2 ),
	];
	$writers = [
		static fn () => StaffActions::create_lead( $lead, $parties ),
		static fn () => StaffActions::create_opportunity( $lead, $parties ),
		static fn () => StaffActions::qualify_lead( 41, 1 ),
		static fn () => StaffActions::disqualify_lead( 41, 1 ),
		static fn () => StaffActions::move_stage( 'lead', 41, 4, 1 ),
		static fn () => StaffActions::convert_lead( 41, 1, 7, 8 ),
		static fn () => StaffActions::close_opportunity( 51, 'won', 1 ),
		static fn () => StaffActions::reopen_opportunity( 51, 2 ),
	];
	$assert_denied = static function ( string $label, bool $read_denied, bool $write_denied ) use
		( $assert, $code, $readers, $writers ): void {
		$reads = count( $GLOBALS['wpdb']->reads );
		$writes = count( $GLOBALS['c3_writes'] );
		if ( $read_denied ) {
			foreach ( $readers as $reader ) {
				$assert( 'crm_sales_forbidden' === $code( $reader() ), "{$label}: reader must fail closed" );
			}
			$assert( $reads === count( $GLOBALS['wpdb']->reads ), "{$label}: unauthorized SQL read" );
		}
		if ( $write_denied ) {
			foreach ( $writers as $writer ) {
				$assert( 'crm_sales_forbidden' === $code( $writer() ), "{$label}: writer must fail closed" );
			}
			$assert( $writes === count( $GLOBALS['c3_writes'] ), "{$label}: unauthorized write delegate" );
		}
	};

	$assert_denied( 'runtime unavailable', true, true );
	$GLOBALS['c3_ready'] = true;
	$assert_denied( 'no permissions', true, true );
	$GLOBALS['c3_caps'] = [ 'cb_view_crm_sales', 'cb_manage_crm_sales' ];
	$assert_denied( 'Sales grants but no canonical CRM', true, true );
	$GLOBALS['c3_caps'] = [ 'cb_manage_crm' ];
	$assert_denied( 'CRM-only', true, true );
	$GLOBALS['c3_caps'] = [ 'cb_manage_crm', 'cb_view_crm_sales' ];
	$assert_denied( 'read-only Sales user', false, true );
	$page = StaffProjections::page( 'lead', [], 0, 2 );
	$detail = StaffProjections::detail( 'lead', 41 );
	$history = StaffProjections::history( 'lead', 41, 0, 2 );
	$assert( is_array( $page ) && is_array( $detail ) && is_array( $history ), 'read-only Sales user cannot use C1' );
	$assert( 10 === count( $page['rows'][0] ) && 1 === count( $detail['participants'] )
		&& 9 === count( $history['rows'][0] ), 'read-only projections leaked or omitted fields' );
	$assert( ! array_key_exists( 'source_reference', $detail['record'] )
		&& ! array_key_exists( 'post_content', $detail['record'] )
		&& ! array_key_exists( 'amount_minor', $page['rows'][0] ), 'C1 exposes unnecessary data' );

	$GLOBALS['c3_caps'] = [ 'cb_manage_crm', 'cb_manage_crm_sales' ];
	$assert( is_array( StaffProjections::page( 'lead' ) ), 'manager without viewer grant must read C1' );
	$assert( 101 === StaffActions::create_lead( $lead, $parties ), 'manager must delegate create' );
	$assert( 2 === StaffActions::qualify_lead( 41, 1 ), 'manager must delegate qualified mutation' );
	$assert( 'crm_sales_conflict' === $code( StaffActions::qualify_lead( 41, 2 ) ), 'stale revision error modified' );
	$assert( [
		[ 'create_lead', $lead, $parties ],
		[ 'lead_status', 41, 'qualified', 1 ],
		[ 'lead_status', 41, 'qualified', 2 ],
	] === $GLOBALS['c3_writes'], 'facade forwarding added hidden actions or changed parameters' );

	$GLOBALS['c3_actor'] = 0;
	$assert_denied( 'anonymous with capabilities', true, true );
	$GLOBALS['c3_actor'] = 19;
	$GLOBALS['c3_schema'] = '1.5';
	$assert_denied( 'outdated schema with capabilities', true, true );

	foreach ( [
		[ StaffProjections::class, [ 'detail', 'history', 'page' ] ],
		[ StaffActions::class, [
			'close_opportunity', 'convert_lead', 'create_lead', 'create_opportunity',
			'disqualify_lead', 'move_stage', 'qualify_lead', 'reopen_opportunity',
		] ],
	] as [$class, $expected] ) {
		$actual = array_map(
			static fn ( \ReflectionMethod $method ): string => $method->name,
			( new \ReflectionClass( $class ) )->getMethods( \ReflectionMethod::IS_PUBLIC )
		);
		sort( $actual );
		$assert( $expected === $actual, "{$class}: unexpected public method or dispatcher" );
	}

	foreach ( $GLOBALS['wpdb']->reads as $sql ) {
		$assert( ! str_contains( $sql, 'SELECT *' )
			&& ! str_contains( $sql, ' JOIN ' ) && ! str_contains( $sql, ' OFFSET ' ),
			'C1 emitted unrestricted SQL' );
	}
	echo "CRM Sales C3 joint read/write authorization and contract golden smoke passed.\n";
}

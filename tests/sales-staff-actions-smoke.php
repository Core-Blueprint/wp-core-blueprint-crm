<?php
declare(strict_types=1);

namespace CB\CRM\Repository\Sales {
	final class Records {
		public static function create_lead( array $input, array $participants ): int|\WP_Error {
			$GLOBALS['c2_calls'][] = [ 'create_lead', $input, $participants ];
			return 'bad' === ( $input['title'] ?? '' ) ? new \WP_Error( 'crm_sales_invalid_input' ) : 101;
		}
		public static function create_opportunity( array $input, array $participants ): int|\WP_Error {
			$GLOBALS['c2_calls'][] = [ 'create_opportunity', $input, $participants ];
			return 201;
		}
	}
	final class Transitions {
		public static function move_stage( string $type, int $id, int $stage, int $revision ): int|\WP_Error {
			$GLOBALS['c2_calls'][] = [ 'move_stage', $type, $id, $stage, $revision ];
			return 999 === $revision ? new \WP_Error( 'crm_sales_conflict' ) : $revision + 1;
		}
		public static function set_lead_status( int $id, string $status, int $revision ): int|\WP_Error {
			$GLOBALS['c2_calls'][] = [ 'set_lead_status', $id, $status, $revision ];
			return 999 === $revision ? new \WP_Error( 'crm_sales_conflict' ) : $revision + 1;
		}
	}
	final class Conversion {
		public static function convert( int $id, int $revision, int $pipeline, int $stage ): int|\WP_Error {
			$GLOBALS['c2_calls'][] = [ 'convert', $id, $revision, $pipeline, $stage ];
			return 999 === $revision ? new \WP_Error( 'crm_sales_conversion_inconsistent' ) : 301;
		}
	}
	final class Outcomes {
		public static function close( int $id, string $outcome, int $revision ): int|\WP_Error {
			$GLOBALS['c2_calls'][] = [ 'close', $id, $outcome, $revision ];
			return 'open' === $outcome ? new \WP_Error( 'crm_sales_invalid_input' ) : $revision + 1;
		}
		public static function reopen( int $id, int $revision ): int|\WP_Error {
			$GLOBALS['c2_calls'][] = [ 'reopen', $id, $revision ];
			return 999 === $revision ? new \WP_Error( 'crm_sales_invalid_transition' ) : $revision + 1;
		}
	}
}

namespace {
	use CB\CRM\Application\Sales\StaffActions;

	$root = dirname( __DIR__ );
	define( 'ABSPATH', $root . '/' );
	define( 'CB_CRM_SCHEMA_VERSION', '1.6' );

	final class WP_Error {
		public function __construct( private string $code ) {}
		public function get_error_code(): string { return $this->code; }
	}

	$GLOBALS['c2_ready'] = false;
	$GLOBALS['c2_caps'] = [];
	$GLOBALS['c2_actor'] = 17;
	$GLOBALS['c2_schema'] = '1.6';
	$GLOBALS['c2_calls'] = [];

	function cb_crm_runtime_ready(): bool { return $GLOBALS['c2_ready']; }
	function current_user_can( string $cap ): bool { return in_array( $cap, $GLOBALS['c2_caps'], true ); }
	function get_current_user_id(): int { return $GLOBALS['c2_actor']; }
	function get_option( string $key, mixed $fallback = '' ): mixed {
		return 'cb_crm_db_version' === $key ? $GLOBALS['c2_schema'] : $fallback;
	}
	require $root . '/src/Capabilities.php';
	require $root . '/src/Database/Schema.php';
	require $root . '/src/Domain/Sales/Rules.php';
	require $root . '/src/Domain/Sales/Access.php';
	require $root . '/src/Application/Sales/StaffActions.php';

	$assert = static function ( bool $ok, string $message ): void {
		if ( ! $ok ) {
			fwrite( STDERR, "CRM C2 staff action facade smoke FAIL: {$message}\n" );
			exit( 1 );
		}
	};
	$code = static fn ( mixed $result ): string => $result instanceof WP_Error ? $result->get_error_code() : '';
	$actions = [
		static fn () => \CB\CRM\Application\Sales\StaffActions::create_lead( [ 'title' => 'Synthetic' ], [] ),
		static fn () => \CB\CRM\Application\Sales\StaffActions::create_opportunity( [ 'title' => 'Synthetic' ], [] ),
		static fn () => \CB\CRM\Application\Sales\StaffActions::qualify_lead( 11, 1 ),
		static fn () => \CB\CRM\Application\Sales\StaffActions::disqualify_lead( 11, 1 ),
		static fn () => \CB\CRM\Application\Sales\StaffActions::move_stage( 'lead', 11, 7, 1 ),
		static fn () => \CB\CRM\Application\Sales\StaffActions::convert_lead( 11, 2, 3, 4 ),
		static fn () => \CB\CRM\Application\Sales\StaffActions::close_opportunity( 22, 'won', 1 ),
		static fn () => \CB\CRM\Application\Sales\StaffActions::reopen_opportunity( 22, 2 ),
	];

	$assert_denied = static function ( string $label ) use ( $actions, $assert, $code ): void {
		$count = count( $GLOBALS['c2_calls'] );
		foreach ( $actions as $index => $action ) {
			$assert( 'crm_sales_forbidden' === $code( $action() ), "{$label}: action {$index} should be denied" );
		}
		$assert( $count === count( $GLOBALS['c2_calls'] ), "{$label}: delegated a forbidden mutation" );
	};

	$assert_denied( 'unready runtime' );
	$GLOBALS['c2_ready'] = true;
	$assert_denied( 'no capabilities' );
	$GLOBALS['c2_caps'] = [ 'cb_view_crm_sales', 'cb_manage_crm' ];
	$assert_denied( 'read-only CRM staff' );
	$GLOBALS['c2_caps'] = [ 'cb_manage_crm_sales' ];
	$assert_denied( 'Sales manager without CRM permission' );
	$GLOBALS['c2_caps'] = [ 'cb_manage_crm', 'cb_manage_crm_sales' ];
	$GLOBALS['c2_actor'] = 0;
	$assert_denied( 'anonymous manager' );
	$GLOBALS['c2_actor'] = 17;
	$GLOBALS['c2_schema'] = '1.5';
	$assert_denied( 'incorrect schema' );
	$GLOBALS['c2_schema'] = '1.6';

	$lead = [ 'title' => 'Synthetic Lead', 'pipeline_id' => 2, 'stage_id' => 8 ];
	$deal = [ 'title' => 'Synthetic Deal', 'pipeline_id' => 4, 'stage_id' => 12, 'amount_minor' => 12345, 'currency' => 'EUR' ];
	$parties = [ [ 'party_type' => 'contact', 'party_id' => 42, 'role' => 'primary_contact' ] ];
	$assert( 101 === StaffActions::create_lead( $lead, $parties ), 'create Lead delegated' );
	$assert( 201 === StaffActions::create_opportunity( $deal, $parties ), 'create Opportunity delegated' );
	$assert( 3 === StaffActions::qualify_lead( 101, 2 ), 'qualification delegated to versioned Lead mutation' );
	$assert( 4 === StaffActions::disqualify_lead( 102, 3 ), 'disqualification delegated' );
	$assert( 5 === StaffActions::move_stage( 'lead', 101, 9, 4 ), 'stage move delegated' );
	$assert( 301 === StaffActions::convert_lead( 101, 5, 4, 12 ), 'qualified conversion delegated' );
	$assert( 7 === StaffActions::close_opportunity( 301, 'won', 6 ), 'close delegated' );
	$assert( 8 === StaffActions::reopen_opportunity( 301, 7 ), 'explicit reopen delegated' );

	$expected = [
		[ 'create_lead', $lead, $parties ],
		[ 'create_opportunity', $deal, $parties ],
		[ 'set_lead_status', 101, 'qualified', 2 ],
		[ 'set_lead_status', 102, 'disqualified', 3 ],
		[ 'move_stage', 'lead', 101, 9, 4 ],
		[ 'convert', 101, 5, 4, 12 ],
		[ 'close', 301, 'won', 6 ],
		[ 'reopen', 301, 7 ],
	];
	$assert( $expected === $GLOBALS['c2_calls'], 'facade changed IDs, arguments, actor, order or state contract' );

	$assert( 'crm_sales_invalid_input' === $code( StaffActions::create_lead( [ 'title' => 'bad' ], $parties ) ),
		'repository invalid input error was not propagated unchanged' );
	$assert( 'crm_sales_conflict' === $code( StaffActions::qualify_lead( 101, 999 ) ),
		'repository stale revision error not propagated' );
	$assert( 'crm_sales_conflict' === $code( StaffActions::move_stage( 'opportunity', 301, 12, 999 ) ),
		'repository stage conflict not propagated' );
	$assert( 'crm_sales_conversion_inconsistent' === $code( StaffActions::convert_lead( 101, 999, 4, 12 ) ),
		'repository conversion error not propagated' );
	$assert( 'crm_sales_invalid_input' === $code( StaffActions::close_opportunity( 301, 'open', 5 ) ),
		'invalid closing outcome not propagated' );
	$assert( 'crm_sales_invalid_transition' === $code( StaffActions::reopen_opportunity( 301, 999 ) ),
		'invalid reopen state not propagated' );

	$reflection = new \ReflectionClass( StaffActions::class );
	$public = array_map( static fn ( \ReflectionMethod $method ): string => $method->name, $reflection->getMethods( \ReflectionMethod::IS_PUBLIC ) );
	sort( $public );
	$assert( [
		'close_opportunity', 'convert_lead', 'create_lead', 'create_opportunity', 'disqualify_lead',
		'move_stage', 'qualify_lead', 'reopen_opportunity',
	] === $public, 'facade exposes unexpected arbitrary method/dispatcher' );

	echo "CRM Sales C2 guarded staff action facade delegation smoke passed.\n";
}

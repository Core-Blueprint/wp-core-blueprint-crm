<?php
declare(strict_types=1);

/** W2B1 isolated POST+nonce+facade safety smoke. */
namespace CB\CRM\Domain\Sales {
	final class Access {
		public static bool $manager = false;
		public static function can_manage(): bool { return self::$manager; }
	}
}
namespace CB\CRM\Application\Sales {
	final class StaffActions {
		public static array $calls = [];
		public static function qualify_lead( int $id, int $rev ): int|\WP_Error {
			self::$calls[] = [ 'qualify', $id, $rev ];
			return 99 === $rev ? new \WP_Error( 'crm_sales_conflict' ) : $rev + 1;
		}
		public static function disqualify_lead( int $id, int $rev ): int|\WP_Error {
			self::$calls[] = [ 'disqualify', $id, $rev ];
			return $rev + 1;
		}
		public static function close_opportunity( int $id, string $outcome, int $rev ): int|\WP_Error {
			self::$calls[] = [ 'close', $id, $outcome, $rev ];
			return $rev + 1;
		}
		public static function reopen_opportunity( int $id, int $rev ): int|\WP_Error {
			self::$calls[] = [ 'reopen', $id, $rev ];
			return $rev + 1;
		}
	}
}
namespace {
	use CB\CRM\Admin\SalesStatusActions as Transport;
	use CB\CRM\Application\Sales\StaffActions as Facade;
	use CB\CRM\Domain\Sales\Access;

	define( 'ABSPATH', dirname( __DIR__ ) . '/' );
	final class WP_Error {
		public function __construct( private string $code ) {}
		public function get_error_code(): string { return $this->code; }
	}
	final class W2B1_Died extends \Exception {}
	$GLOBALS['w2b1_actor'] = 17;
	$GLOBALS['w2b1_hooks'] = [];
	function __( string $label, string $domain = '' ): string { return $label; }
	function esc_html( string $s ): string { return htmlspecialchars( $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ); }
	function esc_attr( string $s ): string { return esc_html( $s ); }
	function esc_url( string $s ): string { return esc_html( $s ); }
	function esc_html__( string $s, string $d = '' ): string { return esc_html( $s ); }
	function esc_attr__( string $s, string $d = '' ): string { return esc_attr( $s ); }
	function esc_html_e( string $s, string $d = '' ): void { echo esc_html( $s ); }
	function wp_unslash( string $s ): string { return stripslashes( $s ); }
	function wp_nonce_field( string $action, string $name, bool $referer, bool $display ): string {
		$html = '<input type="hidden" name="' . $name . '" value="' . esc_attr( 'valid_' . $action ) . '">';
		if ( $display ) { echo $html; }
		return $html;
	}
	function wp_verify_nonce( string $nonce, string $action ): int|false {
		return hash_equals( 'valid_' . $action, $nonce ) ? 1 : false;
	}
	function get_current_user_id(): int { return $GLOBALS['w2b1_actor']; }
	function admin_url( string $path ): string { return 'https://example.invalid/wp-admin/' . $path; }
	function add_action( string $hook, callable $callback ): void { $GLOBALS['w2b1_hooks'][$hook] = $callback; }
	function wp_die( string $message, string $title = '', array $args = [] ): never {
		throw new W2B1_Died( $message, (int) ( $args['response'] ?? 403 ) );
	}

	require dirname( __DIR__ ) . '/src/Domain/Sales/Rules.php';
	require dirname( __DIR__ ) . '/src/Admin/SalesStatusActions.php';
	$check = static function ( bool $ok, string $why ): void {
		if ( ! $ok ) { fwrite( STDERR, "CRM W2B1 Admin status actions FAIL: {$why}\n" ); exit( 1 ); }
	};
	$code = static fn ( mixed $value ): string => $value instanceof WP_Error ? $value->get_error_code() : '';
	$request = static function ( string $op, string $type, string $id = '41', string $revision = '2' ): array {
		return [
			'action' => Transport::HOOK,
			'sales_op' => $op,
			'entity_type' => $type,
			'record_id' => $id,
			'revision' => $revision,
			'_wpnonce' => 'valid_cb_crm_sales_' . $type . '_' . $id . '_' . $revision . '_' . $op,
		];
	};
	Transport::init();
	$check( isset( $GLOBALS['w2b1_hooks']['admin_post_' . Transport::HOOK] )
		&& ! isset( $GLOBALS['w2b1_hooks']['admin_post_nopriv_' . Transport::HOOK] ),
		'authenticated admin_post only contract' );

	$payload = $request( 'qualify', 'lead' );
	$check( 'crm_sales_forbidden' === $code( Transport::process( $payload ) )
		&& [] === Facade::$calls, 'missing manager grant still delegated' );
	Access::$manager = true;
	$GLOBALS['w2b1_actor'] = 0;
	$check( 'crm_sales_forbidden' === $code( Transport::process( $payload ) )
		&& [] === Facade::$calls, 'anonymous actor still delegated' );
	$GLOBALS['w2b1_actor'] = 17;

	foreach ( [
		array_merge( $payload, [ 'arbitrary' => 'SQL' ] ),
		array_diff_key( $payload, [ '_wpnonce' => true ] ),
		array_merge( $payload, [ 'record_id' => [ '41' ] ] ),
		array_merge( $payload, [ 'sales_op' => 'convert' ] ),
		array_merge( $payload, [ 'entity_type' => 'opportunity' ] ),
		array_merge( $payload, [ 'record_id' => '-41' ] ),
		array_merge( $payload, [ 'record_id' => '0' ] ),
		array_merge( $payload, [ 'record_id' => '999999999999999999999999999' ] ),
		array_merge( $payload, [ 'revision' => '0' ] ),
		array_merge( $payload, [ 'revision' => '2.5' ] ),
		array_merge( $payload, [ 'action' => 'fake' ] ),
	] as $bad ) {
		$check( 'crm_sales_invalid_request' === $code( Transport::process( $bad ) ),
			'malformed, ambiguous or unexpected POST accepted' );
	}
	$check( [] === Facade::$calls, 'invalid POST touched C2' );
	$forged = array_merge( $payload, [ '_wpnonce' => 'valid_cb_crm_sales_opportunity_41_2_won' ] );
	$check( 'crm_sales_invalid_nonce' === $code( Transport::process( $forged ) )
		&& [] === Facade::$calls, 'nonce replay across type/action accepted' );

	$cases = [
		[ 'qualify', 'lead', 3, [ 'qualify', 41, 2 ] ],
		[ 'disqualify', 'lead', 3, [ 'disqualify', 41, 2 ] ],
		[ 'won', 'opportunity', 3, [ 'close', 41, 'won', 2 ] ],
		[ 'lost', 'opportunity', 3, [ 'close', 41, 'lost', 2 ] ],
		[ 'reopen', 'opportunity', 3, [ 'reopen', 41, 2 ] ],
	];
	foreach ( $cases as [$op, $type, $wanted, $call] ) {
		$check( $wanted === Transport::process( $request( $op, $type ) ), "action {$op} failed" );
		$check( $call === Facade::$calls[count( Facade::$calls ) - 1], "action {$op} rewrote repository argument" );
	}
	$check( 'crm_sales_conflict' === $code( Transport::process( $request( 'qualify', 'lead', '41', '99' ) ) ),
		'stale revision WP_Error not preserved' );

	$render = static function ( string $type, string $state, int $revision = 2 ): string {
		ob_start();
		Transport::render_for_record( $type, [ 'id' => 41, 'revision' => $revision, 'state' => $state ] );
		return (string) ob_get_clean();
	};
	$lead = $render( 'lead', 'open' );
	$check( str_contains( $lead, 'Qualify lead' ) && str_contains( $lead, 'Disqualify lead' )
		&& ! str_contains( $lead, 'Mark won' ) && str_contains( $lead, 'admin-post.php' )
		&& str_contains( $lead, 'valid_cb_crm_sales_lead_41_2_qualify' ),
		'Lead open forms do not bind action/type/id/revision' );
	$check( str_contains( $render( 'lead', 'qualified' ), 'Disqualify lead' )
		&& ! str_contains( $render( 'lead', 'qualified' ), 'Qualify lead' )
		&& '' === $render( 'lead', 'converted' ),
		'terminal/qualified Lead forms violate Rules transitions' );
	$check( str_contains( $render( 'opportunity', 'open' ), 'Mark won' )
		&& str_contains( $render( 'opportunity', 'open' ), 'Mark lost' )
		&& '' === $render( 'opportunity', 'missing' )
		&& str_contains( $render( 'opportunity', 'lost' ), 'Reopen opportunity' )
		&& ! str_contains( $render( 'opportunity', 'lost' ), 'Mark won' ),
		'Opportunity closed/open forms violate outcome state' );
	Access::$manager = false;
	$check( '' === $render( 'lead', 'open' ), 'read-only viewer shown a writable form' );

	$_SERVER['REQUEST_METHOD'] = 'GET';
	try { Transport::handle(); $check( false, 'GET request was accepted' ); }
	catch ( W2B1_Died $e ) { $check( 405 === $e->getCode(), 'GET was not method-rejected' ); }

	echo "CRM Sales W2B1 nonce-gated status action transport and form smoke passed.\n";
}

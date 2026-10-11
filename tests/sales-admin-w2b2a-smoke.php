<?php
declare(strict_types=1);

/** W2B2-A: strict create transport and manager-only render smoke. */
namespace CB\CRM\Domain\Sales {
	final class Access {
		public static bool $manager = false;
		public static function can_manage(): bool { return self::$manager; }
	}
}
namespace CB\CRM\Application\Sales {
	final class StaffActions {
		public static array $calls = [];
		public static function create_lead( array $input, array $participants ): int|\WP_Error {
			self::$calls[] = [ 'lead', $input, $participants ]; return 17;
		}
		public static function create_opportunity( array $input, array $participants ): int|\WP_Error {
			self::$calls[] = [ 'opportunity', $input, $participants ]; return 18;
		}
	}
}
namespace CB\CRM\Repository\Sales {
	final class CreationOptions {
		public static function stages( string $type ): array {
			return [ [ 'pipeline_id' => 'lead' === $type ? 8 : 9, 'pipeline_name' => 'Default',
				'stage_id' => 'lead' === $type ? 81 : 91, 'stage_name' => 'New' ] ];
		}
		public static function identities( string $type, string $search ): array {
			return [ [ 'id' => 55, 'title' => '<b>Identity & Co</b>' ] ];
		}
	}
}
namespace {
	use CB\CRM\Admin\SalesCreateActions as Create;
	use CB\CRM\Application\Sales\StaffActions as Facade;
	use CB\CRM\Domain\Sales\Access;

	define( 'ABSPATH', dirname( __DIR__ ) . '/' );
	final class WP_Error {
		public function __construct( private string $code ) {}
		public function get_error_code(): string { return $this->code; }
	}
	$GLOBALS['actor_w2b2a'] = 42;
	$GLOBALS['hooks_w2b2a'] = [];
	if ( ! function_exists( 'mb_strlen' ) ) { function mb_strlen( string $s ): int { return strlen( $s ); } }
	function __( string $s, string $domain = '' ): string { return $s; }
	function esc_html( string $s ): string { return htmlspecialchars( $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ); }
	function esc_html__( string $s, string $d = '' ): string { return esc_html( $s ); }
	function esc_attr( string $s ): string { return esc_html( $s ); }
	function esc_url( string $s ): string { return esc_html( $s ); }
	function esc_html_e( string $s, string $d = '' ): void { echo esc_html( $s ); }
	function wp_unslash( string $s ): string { return stripslashes( $s ); }
	function sanitize_text_field( string $s ): string { return trim( strip_tags( $s ) ); }
	function selected( mixed $selected, mixed $current, bool $echo = true ): string { return $selected === $current ? ' selected="selected"' : ''; }
	function get_current_user_id(): int { return $GLOBALS['actor_w2b2a']; }
	function wp_verify_nonce( string $nonce, string $action ): int|false { return hash_equals( 'valid_' . $action, $nonce ) ? 1 : false; }
	function wp_nonce_field( string $action, string $name, bool $referer, bool $display ): string {
		$html = '<input type="hidden" name="' . $name . '" value="valid_' . $action . '">';
		if ( $display ) { echo $html; } return $html;
	}
	function admin_url( string $path ): string { return 'https://example.invalid/wp-admin/' . $path; }
	function add_query_arg( array $args, string $url ): string { return $url . '?' . http_build_query( $args ); }
	function add_action( string $hook, callable $callback ): void { $GLOBALS['hooks_w2b2a'][$hook] = $callback; }
	function wp_die( string $message, string $title = '', array $args = [] ): void { throw new \RuntimeException( $message ); }

	require dirname( __DIR__ ) . '/src/Domain/Sales/Rules.php';
	require dirname( __DIR__ ) . '/src/Admin/SalesCreateActions.php';
	$check = static function ( bool $ok, string $why ): void {
		if ( ! $ok ) { fwrite( STDERR, "CRM W2B2-A FAIL: {$why}\n" ); exit( 1 ); }
	};
	$error = static fn ( mixed $result ): string => $result instanceof WP_Error ? $result->get_error_code() : '';
	$post = static fn ( string $type ): array => array_merge( [
		'action' => Create::HOOK, 'entity_type' => $type, 'title' => 'A new record',
		'stage_id' => 'lead' === $type ? '81' : '91', 'party' => 'contact:55',
		'role' => 'primary_contact', '_wpnonce' => 'valid_cb_crm_sales_create_' . $type,
	], 'opportunity' === $type ? [ 'amount_minor' => '1200', 'currency' => 'EUR' ] : [] );
	Create::init();
	$check( isset( $GLOBALS['hooks_w2b2a']['admin_post_' . Create::HOOK] )
		&& ! isset( $GLOBALS['hooks_w2b2a']['admin_post_nopriv_' . Create::HOOK] ), 'anonymous hook registered' );
	$check( 'crm_sales_forbidden' === $error( Create::process( $post( 'lead' ) ) ), 'manager gate missing' );
	Access::$manager = true;
	$GLOBALS['actor_w2b2a'] = 0;
	$check( 'crm_sales_forbidden' === $error( Create::process( $post( 'lead' ) ) ), 'anonymous actor allowed' );
	$GLOBALS['actor_w2b2a'] = 42;
	foreach ( [
		array_merge( $post( 'lead' ), [ 'anything' => 'x' ] ),
		array_diff_key( $post( 'lead' ), [ 'role' => true ] ),
		array_merge( $post( 'lead' ), [ 'stage_id' => [ '81' ] ] ),
		array_merge( $post( 'lead' ), [ 'party' => 'contact:0' ] ),
		array_merge( $post( 'lead' ), [ 'party' => 'contact:99999999999999999999' ] ),
		array_merge( $post( 'lead' ), [ 'party' => 'other:55' ] ),
		array_merge( $post( 'lead' ), [ 'stage_id' => '91' ] ),
		array_merge( $post( 'lead' ), [ 'stage_id' => '01' ] ),
		array_merge( $post( 'lead' ), [ 'role' => 'admin' ] ),
		array_merge( $post( 'lead' ), [ '_wpnonce' => 'valid_cb_crm_sales_create_opportunity' ] ),
		array_merge( $post( 'lead' ), [ 'title' => '' ] ),
		array_merge( $post( 'opportunity' ), [ 'amount_minor' => '-10' ] ),
		array_merge( $post( 'opportunity' ), [ 'currency' => 'EURO' ] ),
		array_merge( $post( 'opportunity' ), [ 'currency' => 'eur' ] ),
	] as $bad ) {
		$check( Create::process( $bad ) instanceof WP_Error, 'invalid input reached C2' );
	}
	$check( [] === Facade::$calls, 'malformed POST delegated' );
	$check( 17 === Create::process( $post( 'lead' ) ), 'Lead creation failed' );
	$check( 18 === Create::process( $post( 'opportunity' ) ), 'Opportunity creation failed' );
	$check( [ 'lead', [ 'title' => 'A new record', 'pipeline_id' => 8, 'stage_id' => 81 ],
		[ [ 'party_type' => 'contact', 'party_id' => 55, 'role' => 'primary_contact' ] ] ] === Facade::$calls[0],
		'Lead facade args mutated' );
	$check( 1200 === Facade::$calls[1][1]['amount_minor'] && 'EUR' === Facade::$calls[1][1]['currency'],
		'Opportunity estimate lost' );
	$empty_amount = $post( 'opportunity' );
	$empty_amount['amount_minor'] = '';
	$empty_amount['currency'] = '';
	$check( 18 === Create::process( $empty_amount ), 'nullable estimate rejected' );
	$check( null === Facade::$calls[2][1]['amount_minor'], 'nullable estimate not preserved' );

	$_GET = [ 'identity_type' => 'contact', 'identity_search' => 'Identity' ];
	ob_start(); Create::render_for_type( 'lead', 'core-blueprint-crm-leads' ); $html = (string) ob_get_clean();
	$check( str_contains( $html, 'admin-post.php' ) && str_contains( $html, 'name="_wpnonce"' )
		&& str_contains( $html, 'name="party"' ) && str_contains( $html, 'Pipeline and stage' )
		&& ! str_contains( $html, '<b>Identity & Co</b>' ) && str_contains( $html, '&lt;b&gt;Identity'),
		'rendered creation form missing security/escape contracts' );
	Access::$manager = false;
	try { Create::render_for_type( 'lead', 'core-blueprint-crm-leads' ); $check( false, 'viewer rendered create' ); }
	catch ( \RuntimeException $expected ) {}
	echo "CRM Sales W2B2-A create transport smoke passed.\n";
}

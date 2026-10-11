<?php
declare(strict_types=1);

/**
 * W2A: native CRM Sales screens render only through C1.
 * Standalone WordPress stubs; no DB, accounts, external services or files.
 */
namespace CoreBlueprint\Core\UI {
	final class AdminTheme {
		public static array $screens = [];
		public static function register_screen( string $hook ): void {
			self::$screens[] = $hook;
		}
	}
}
namespace CB\CRM {
	final class Capabilities {
		public const MANAGE = 'cb_manage_crm';
		public const VIEW_SALES = 'cb_view_crm_sales';
		public const MANAGE_SALES = 'cb_manage_crm_sales';
	}
}
namespace CB\CRM\Content {
	final class PostTypes {
		public const CONTACT = 'cb_crm_contact';
		public const ORGANIZATION = 'cb_crm_org';
		public const TAG = 'cb_crm_tag';
	}
}
namespace CB\CRM\Domain\Sales {
	final class Access {
		public static bool $allowed = false;
		public static function can_view(): bool { return self::$allowed; }
	}
}
namespace CB\CRM\Admin {
	final class UserLinks { public const PAGE_SLUG = 'core-blueprint-crm-users'; }
}
namespace CB\CRM\Repository\Sales {
	final class StaffProjections {
		public static array $calls = [];
		public static bool $fail = false;

		public static function page( string $type, array $filters, int $after, int $limit ): array|\WP_Error {
			self::$calls[] = [ 'page', $type, $filters, $after, $limit ];
			if ( self::$fail ) { return new \WP_Error( 'crm_sales_read_failed' ); }
			return [
				'rows' => $after ? [] : [ [
					'id' => 41,
					'title' => '<script>alert("x")</script> & Co',
					'state' => 'open', 'owner_user_id' => 19,
					'next_action_on' => null, 'updated_at' => '2026-10-11 12:00:00',
				] ],
				'cursor' => 41,
				'has_more' => ! $after,
			];
		}
		public static function detail( string $type, int $id ): array|\WP_Error|null {
			self::$calls[] = [ 'detail', $type, $id ];
			if ( self::$fail ) { return new \WP_Error( 'crm_sales_read_failed' ); }
			return 41 === $id ? [
				'record' => [
					'id' => 41, 'title' => '<script>alert("x")</script> & Co',
					'state' => 'open', 'owner_user_id' => 19, 'pipeline_id' => 2, 'stage_id' => 3,
					'revision' => 1, 'next_action_on' => null,
					'created_at' => '2026-10-11 09:00:00', 'updated_at' => '2026-10-11 12:00:00',
					'source' => 'referral', 'converted_opportunity_id' => null,
					'amount_minor' => 12900, 'currency' => 'EUR', 'source_lead_id' => null,
				],
				'participants' => [
					[ 'party_type' => 'contact', 'party_id' => 8, 'role' => 'primary_contact' ],
				],
			] : null;
		}
		public static function history( string $type, int $id, int $after, int $limit ): array|\WP_Error {
			self::$calls[] = [ 'history', $type, $id, $after, $limit ];
			return [
				'rows' => [ [
					'id' => 92, 'event_type' => 'created', 'revision' => 1,
					'actor_user_id' => 19, 'created_at' => '2026-10-11 09:00:00',
				] ],
				'cursor' => 92, 'has_more' => false,
			];
		}
	}
}
namespace {
	define( 'ABSPATH', dirname( __DIR__ ) . '/' );
	define( 'CB_CRM_URL', 'https://example.invalid/wp-content/plugins/core-blueprint-crm/' );
	define( 'CB_CRM_VERSION', '1.0.0-rc1' );

	final class WP_Error {
		public function __construct( private string $code ) {}
		public function get_error_code(): string { return $this->code; }
	}
	final class WP_Screen {
		public string $taxonomy = '';
		public string $post_type = '';
		public string $base = 'admin_page';
	}
	final class Sales_W2A_Denied extends \Exception {}
	$GLOBALS['w2a_actor'] = 19;
	$GLOBALS['w2a_menus'] = [];
	$GLOBALS['w2a_styles'] = [];
	$GLOBALS['w2a_scripts'] = [];
	$GLOBALS['w2a_screen'] = new WP_Screen();

	function __( string $value, string $domain = '' ): string { return $value; }
	function esc_html( string $s ): string { return htmlspecialchars( $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ); }
	function esc_attr( string $s ): string { return esc_html( $s ); }
	function esc_url( string $s ): string { return esc_html( $s ); }
	function esc_html__( string $s, string $d = '' ): string { return esc_html( __( $s, $d ) ); }
	function esc_attr__( string $s, string $d = '' ): string { return esc_html__( $s, $d ); }
	function esc_html_e( string $s, string $d = '' ): void { echo esc_html__( $s, $d ); }
	function esc_attr_e( string $s, string $d = '' ): void { echo esc_attr__( $s, $d ); }
	function selected( mixed $actual, mixed $target, bool $echo = true ): string {
		$out = $actual === $target ? ' selected="selected"' : '';
		if ( $echo ) { echo $out; }
		return $out;
	}
	function wp_die( string $message ): never { throw new Sales_W2A_Denied( $message ); }
	function wp_unslash( string $s ): string { return stripslashes( $s ); }
	function sanitize_key( string $key ): string {
		return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $key ) ) ?? '';
	}
	function get_current_user_id(): int { return $GLOBALS['w2a_actor']; }
	function current_user_can( string $capability ): bool { return true; }
	function get_current_screen(): WP_Screen { return $GLOBALS['w2a_screen']; }
	function admin_url( string $path ): string { return 'https://example.invalid/wp-admin/' . $path; }
	function add_query_arg( array $params, string $url ): string {
		return $url . '?' . http_build_query( $params, '', '&', PHP_QUERY_RFC3986 );
	}
	function add_action( string $name, mixed $callback, int $priority = 10 ): void {}
	function add_filter( string $name, mixed $callback, int $priority = 10, int $accepted_args = 1 ): void {}
	function add_menu_page( mixed ...$args ): string {
		$GLOBALS['w2a_menus'][] = [ 'top', $args ];
		return 'toplevel_page_' . $args[3];
	}
	function add_submenu_page( mixed ...$args ): string {
		$GLOBALS['w2a_menus'][] = [ 'sub', $args ];
		return 'crm_page_' . $args[4];
	}
	function wp_enqueue_style( string $handle, string $src, array $deps, string $version ): void {
		$GLOBALS['w2a_styles'][] = $handle;
	}
	function wp_enqueue_script( string $handle, mixed ...$args ): void {
		$GLOBALS['w2a_scripts'][] = $handle;
	}

	require dirname( __DIR__ ) . '/src/Domain/Sales/Rules.php';
	require dirname( __DIR__ ) . '/src/Admin/SalesPage.php';
	require dirname( __DIR__ ) . '/src/Admin/Menu.php';
	require dirname( __DIR__ ) . '/src/Admin/Assets.php';

	$assert = static function ( bool $ok, string $message ): void {
		if ( ! $ok ) {
			fwrite( STDERR, "CRM W2A admin screens smoke FAIL: {$message}\n" );
			exit( 1 );
		}
	};
	$render = static function ( callable $callback ): string {
		ob_start();
		try {
			$callback();
			return (string) ob_get_clean();
		} catch ( \Throwable $error ) {
			ob_end_clean();
			throw $error;
		}
	};
	$menu = \CB\CRM\Admin\Menu::class;
	$page = \CB\CRM\Admin\SalesPage::class;
	$source = \CB\CRM\Repository\Sales\StaffProjections::class;
	$access = \CB\CRM\Domain\Sales\Access::class;

	$menu::register();
	$items = array_values( array_filter(
		$GLOBALS['w2a_menus'],
		static fn ( array $row ): bool => 'sub' === $row[0] &&
			in_array( $row[1][4], [ $menu::SALES_LEADS_SLUG, $menu::SALES_OPPORTUNITIES_SLUG ], true )
	) );
	$assert( 2 === count( $items ), 'missing Leads or Opportunities native CRM submenu' );
	foreach ( $items as $item ) {
		$assert( $menu::TOP_LEVEL_SLUG === $item[1][0], 'Sales page registered outside canonical CRM workspace' );
		$assert( \CB\CRM\Capabilities::VIEW_SALES === $item[1][3], 'Sales read menu escalated beyond view grant' );
		$assert( is_callable( $item[1][5] ), 'Sales menu render callback missing' );
	}
	$assert( in_array( 'crm_page_' . $menu::SALES_LEADS_SLUG, \CoreBlueprint\Core\UI\AdminTheme::$screens, true )
		&& in_array( 'crm_page_' . $menu::SALES_OPPORTUNITIES_SLUG, \CoreBlueprint\Core\UI\AdminTheme::$screens, true ),
		'Base AdminTheme failed to register both Sales screens' );

	$_GET = [ 'page' => $menu::SALES_LEADS_SLUG ];
	$assert( $menu::CONTEXT_LEADS === $menu::screen_context(), 'Lead screen context missing' );
	$assert( $menu::TOP_LEVEL_SLUG === $menu::parent_file( '' ), 'Lead parent navigation missing' );
	$assert( $menu::SALES_LEADS_SLUG === $menu::submenu_file( '' ), 'Lead submenu not active' );
	\CB\CRM\Admin\Assets::enqueue();
	$assert( [ 'cb-crm-admin' ] === $GLOBALS['w2a_styles']
		&& [] === $GLOBALS['w2a_scripts'], 'Sales list must only load existing CSS, not editor scripts' );

	$_GET = [ 'page' => $menu::SALES_OPPORTUNITIES_SLUG ];
	$assert( $menu::CONTEXT_OPPORTUNITIES === $menu::screen_context(), 'Opportunity screen context missing' );
	$assert( $menu::SALES_OPPORTUNITIES_SLUG === $menu::submenu_file( '' ), 'Opportunity submenu inactive' );

	$access::$allowed = false;
	foreach ( [ [ $page, 'render_leads' ], [ $page, 'render_opportunities' ] ] as $callback ) {
		$before = count( $source::$calls );
		try {
			$render( $callback );
			$assert( false, 'unauthorized render unexpectedly succeeded' );
		} catch ( Sales_W2A_Denied $denied ) {}
		$assert( $before === count( $source::$calls ), 'unauthorized page reached Sales projection' );
	}
	$access::$allowed = true;
	$GLOBALS['w2a_actor'] = 0;
	try {
		$render( [ $page, 'render_leads' ] );
		$assert( false, 'anonymous viewer unexpectedly succeeded' );
	} catch ( Sales_W2A_Denied $denied ) {}
	$GLOBALS['w2a_actor'] = 19;

	$_GET = [ 'page' => $menu::SALES_LEADS_SLUG, 'state' => 'open', 'mine' => '1' ];
	$html = $render( [ $page, 'render_leads' ] );
	$assert( str_contains( $html, 'cb-crm-sales-list' )
		&& str_contains( $html, '&lt;script&gt;alert(&quot;x&quot;)&lt;/script&gt;' )
		&& ! str_contains( $html, '<script>' )
		&& str_contains( $html, 'href="https://example.invalid/wp-admin/admin.php?page=core-blueprint-crm-leads&amp;record=41"' ),
		'Lead listing did not escape title or link to canonical detail page' );
	$assert( [ 'page', 'lead', [ 'state' => 'open', 'owner_user_id' => 19 ], 0, 20 ] === $source::$calls[count($source::$calls)-1],
		'Lead filter failed to pass exact C1 arguments' );
	$assert( str_contains( $html, 'after=41' ) && str_contains( $html, 'state=open' )
		&& str_contains( $html, 'mine=1' ), 'list keyset next page dropped filters' );

	$_GET = [ 'page' => $menu::SALES_OPPORTUNITIES_SLUG, 'state' => 'won' ];
	$render( [ $page, 'render_opportunities' ] );
	$assert( [ 'page', 'opportunity', [ 'state' => 'won' ], 0, 20 ] === $source::$calls[count($source::$calls)-1],
		'Opportunity page query was not type-constrained' );

	$_GET = [ 'page' => $menu::SALES_LEADS_SLUG, 'record' => '41', 'history_after' => '90' ];
	$detail = $render( [ $page, 'render_leads' ] );
	$assert( str_contains( $detail, 'cb-crm-sales-detail' )
		&& str_contains( $detail, 'Activity history' )
		&& str_contains( $detail, 'contact #8' )
		&& ! str_contains( $detail, '<script>' )
		&& ! str_contains( $detail, 'type="submit"' ), 'W2A detail must remain escaped and read-only' );
	$assert( [ 'history', 'lead', 41, 90, 20 ] === $source::$calls[count($source::$calls)-1],
		'history cursor not passed through C1' );

	foreach ( [
		[ 'state' => 'won' ], [ 'state' => ['open'] ],
		[ 'mine' => '2' ], [ 'mine' => ['1'] ],
		[ 'after' => '9999999999999999999999999' ], [ 'after' => '-1' ],
		[ 'record' => ['41'] ], [ 'record' => '0' ],
		[ 'record' => '41', 'history_after' => 'not-a-number' ],
	] as $input ) {
		$_GET = array_merge( [ 'page' => $menu::SALES_LEADS_SLUG ], $input );
		$before = count( $source::$calls );
		$render( [ $page, 'render_leads' ] );
		$assert( $before === count( $source::$calls ) || isset( $input['history_after'] ),
			'invalid Sales URL caused unintended data read' );
	}
	$source::$fail = true;
	$_GET = [ 'page' => $menu::SALES_LEADS_SLUG ];
	$fail_html = $render( [ $page, 'render_leads' ] );
	$assert( str_contains( $fail_html, 'Unable to display CRM Sales records' ),
		'failed Sales repository read was silently rendered' );

	echo "CRM Sales W2A native read-only Admin screens and permission boundary smoke passed.\n";
}

<?php
declare(strict_types=1);

namespace {
	$actions = [];
	$filters = [];
	$caps = [
		'cb_manage_crm' => true,
		'edit_post' => true,
		'manage_woocommerce' => false,
		'manage_options' => false,
	];

	if ( ! defined( 'ABSPATH' ) ) {
		define( 'ABSPATH', __DIR__ . '/' );
	}

	class WP_Post {
		public function __construct( public string $post_status = 'publish' ) {}
	}

	class WC_Customer {
		/** @var array<int,array<string,string>> */
		public static array $store = [
			7 => [
				'billing_first_name' => 'Buyer',
				'billing_last_name' => 'Example',
				'billing_company' => 'Example BV',
				'billing_email' => 'buyer@example.test',
				'billing_phone' => '+31 6 12345678',
				'billing_address_1' => 'Main Street 1',
				'billing_address_2' => '',
				'billing_city' => 'Amsterdam',
				'billing_state' => 'NH',
				'billing_postcode' => '1000 AA',
				'billing_country' => 'NL',
				'shipping_first_name' => 'Buyer',
				'shipping_last_name' => 'Example',
				'shipping_company' => 'Example BV',
				'shipping_phone' => '',
				'shipping_address_1' => 'Main Street 1',
				'shipping_address_2' => '',
				'shipping_city' => 'Amsterdam',
				'shipping_state' => 'NH',
				'shipping_postcode' => '1000 AA',
				'shipping_country' => 'NL',
			],
		];
		public static int $save_count = 0;
		/** @var array<string,string> */
		private array $pending = [];

		public function __construct( private int $id ) {}
		public function get_id(): int { return isset( self::$store[ $this->id ] ) ? $this->id : 0; }
		public function __call( string $name, array $args ): mixed {
			if ( str_starts_with( $name, 'get_' ) ) {
				$key = substr( $name, 4 );
				return $this->pending[ $key ] ?? self::$store[ $this->id ][ $key ] ?? '';
			}
			if ( str_starts_with( $name, 'set_' ) ) {
				$key = substr( $name, 4 );
				$this->pending[ $key ] = (string) ( $args[0] ?? '' );
				return null;
			}
			throw new \BadMethodCallException( $name );
		}
		public function save(): int {
			self::$store[ $this->id ] = array_merge( self::$store[ $this->id ] ?? [], $this->pending );
			self::$save_count++;
			$this->pending = [];
			return $this->id;
		}
	}

	function add_action( string $hook, mixed $callback, int $priority = 10, int $accepted_args = 1 ): void {
		global $actions;
		$actions[ $hook ][] = [ $callback, $priority, $accepted_args ];
	}
	function add_filter( string $hook, mixed $callback, int $priority = 10, int $accepted_args = 1 ): void {
		global $filters;
		$filters[ $hook ][] = [ $callback, $priority, $accepted_args ];
	}
	function current_user_can( string $capability, mixed ...$args ): bool {
		global $caps;
		unset( $args );
		return ! empty( $caps[ $capability ] );
	}
	function wp_is_post_autosave( int $post_id ): bool { unset( $post_id ); return false; }
	function wp_is_post_revision( int $post_id ): bool { unset( $post_id ); return false; }
	function wp_verify_nonce( string $nonce, string $action ): bool { return 'nonce' === $nonce && 'cb_crm_save_record' === $action; }
	function wp_unslash( mixed $value ): mixed { return $value; }
	function sanitize_text_field( string $value ): string { return trim( $value ); }
	function wc_clean( string $value ): string { return trim( $value ); }
	function absint( mixed $value ): int { return abs( (int) $value ); }
	function __( string $text, string $domain = 'default' ): string { unset( $domain ); return $text; }
	function esc_html__( string $text, string $domain = 'default' ): string { unset( $domain ); return $text; }
	function esc_html( string $text ): string { return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' ); }
	function esc_attr( string $text ): string { return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' ); }
	function disabled( mixed $disabled, mixed $current = true, bool $display = true ): string {
		$result = $disabled == $current ? ' disabled="disabled"' : '';
		if ( $display ) { echo $result; }
		return $result;
	}
	function selected( mixed $selected, mixed $current = true, bool $display = true ): string {
		$result = $selected == $current ? ' selected="selected"' : '';
		if ( $display ) { echo $result; }
		return $result;
	}
	function add_query_arg( string $key, string $value, string $location ): string { return $location . ( str_contains( $location, '?' ) ? '&' : '?' ) . rawurlencode( $key ) . '=' . rawurlencode( $value ); }
	function get_current_user_id(): int { return 123; }
}

namespace CB\CRM {
	final class Capabilities { public const MANAGE = 'cb_manage_crm'; }
	final class PanelRegistry {
		/** @var array<string,mixed>|null */
		public static ?array $registered = null;
		public static function register( array $definition ): bool { self::$registered = $definition; return true; }
	}
}

namespace CB\CRM\Content {
	final class ContactIdentity {
		public static int $linked_user_id = 7;
		public static function linked_user_id( int $contact_id ): int { unset( $contact_id ); return self::$linked_user_id; }
	}
	final class Entity { public const CONTACT = 'contact'; }
	final class PostTypes { public const CONTACT = 'cb_crm_contact'; }
}

namespace CB\CRM\Repository {
	final class Activity {
		/** @var array<int,mixed> */
		public static array $last = [];
		public static function record( mixed ...$args ): void { self::$last = $args; }
	}
}

namespace {
	require_once dirname( __DIR__ ) . '/src/Integration/WooCustomerWorkspace.php';

	use CB\CRM\Content\ContactIdentity;
	use CB\CRM\Integration\WooCustomerWorkspace;
	use CB\CRM\PanelRegistry;
	use CB\CRM\Repository\Activity;

	$failed = false;
	$assert = static function ( bool $condition, string $message ) use ( &$failed ): void {
		if ( ! $condition ) {
			fwrite( STDERR, $message . "\n" );
			$failed = true;
		}
	};

	WooCustomerWorkspace::init();
	$save_hook = $actions['save_post_cb_crm_contact'][0] ?? null;
	$assert( is_array( $save_hook ) && 15 === $save_hook[1] && 3 === $save_hook[2], 'Woo customer save must register on the Contact-specific save hook before generic CRM identity persistence.' );

	WooCustomerWorkspace::register_panel();
	$assert( 'woocommerce-customer' === ( PanelRegistry::$registered['id'] ?? '' ), 'Woo customer workspace panel must register for CRM Contacts.' );
	$assert( 'normal' === ( PanelRegistry::$registered['context'] ?? '' ), 'Woo customer workspace belongs in the main Contact workspace.' );

	ob_start();
	WooCustomerWorkspace::render( 'contact', 44 );
	$readonly_html = (string) ob_get_clean();
	$assert( str_contains( $readonly_html, 'buyer@example.test' ), 'Woo customer workspace must read live WC_Customer billing data.' );
	$assert( str_contains( $readonly_html, 'disabled="disabled"' ), 'CRM-only operators must receive read-only Woo customer fields.' );

	$caps['manage_woocommerce'] = true;
	ob_start();
	WooCustomerWorkspace::render( 'contact', 44 );
	$editable_html = (string) ob_get_clean();
	$assert( ! str_contains( $editable_html, 'disabled="disabled"' ), 'Woo-authorized operators must be able to edit Woo customer fields.' );
	$assert( str_contains( $editable_html, 'cb_crm_woo_customer[billing_phone]' ), 'Billing phone must be managed from the CRM customer workspace.' );
	$assert( str_contains( $editable_html, 'cb_crm_woo_customer[shipping_address_1]' ), 'Shipping address must be managed from the CRM customer workspace.' );

	$_POST = [
		'cb_crm_nonce' => 'nonce',
		'cb_crm_woo_customer_present' => '1',
		'cb_crm_woo_customer_user_id' => '7',
		'cb_crm_details' => [ 'wp_user_id' => '7' ],
		'cb_crm_woo_customer' => [
			'billing_phone' => '+31 6 87654321',
			'billing_company' => 'Updated BV',
		],
	];
	WooCustomerWorkspace::save_customer( 44, new WP_Post( 'publish' ), true );
	$assert( '+31 6 87654321' === WC_Customer::$store[7]['billing_phone'], 'CRM workspace writes must persist through WC_Customer.' );
	$assert( 'Updated BV' === WC_Customer::$store[7]['billing_company'], 'Woo company data must remain Woo-owned and editable through WC_Customer.' );
	$assert( 1 === WC_Customer::$save_count, 'Changed Woo customer data must save exactly once.' );
	$assert( 'woocommerce_customer_updated' === ( Activity::$last[2] ?? '' ), 'Woo customer edits must leave CRM operator activity provenance.' );

	$before = WC_Customer::$store[7]['billing_phone'];
	$_POST['cb_crm_details']['wp_user_id'] = '8';
	$_POST['cb_crm_woo_customer']['billing_phone'] = 'should-not-cross-identities';
	WooCustomerWorkspace::save_customer( 44, new WP_Post( 'publish' ), true );
	$assert( $before === WC_Customer::$store[7]['billing_phone'], 'Pending CRM identity changes must suppress Woo customer writes until the Contact reloads.' );
	$assert( 1 === WC_Customer::$save_count, 'Pending identity switch must not invoke Woo persistence.' );

	unset( $_POST['cb_crm_details'] );
	ContactIdentity::$linked_user_id = 8;
	WooCustomerWorkspace::save_customer( 44, new WP_Post( 'publish' ), true );
	$assert( $before === WC_Customer::$store[7]['billing_phone'], 'Stale Woo workspace payload must never cross to a different persisted CRM identity.' );
	$assert( 1 === WC_Customer::$save_count, 'Persisted identity mismatch must not invoke Woo persistence.' );

	ContactIdentity::$linked_user_id = 0;
	ob_start();
	WooCustomerWorkspace::render( 'contact', 44 );
	$unlinked_html = (string) ob_get_clean();
	$assert( ! str_contains( $unlinked_html, 'cb_crm_woo_customer_present' ), 'Unlinked CRM Contacts must remain inert and must not expose a writable Woo payload.' );

	$workspace_source = (string) file_get_contents( dirname( __DIR__ ) . '/src/Integration/WooCustomerWorkspace.php' );
	$details_source = (string) file_get_contents( dirname( __DIR__ ) . '/src/Admin/Panels/DetailsPanel.php' );
	$assert( str_contains( $workspace_source, 'new \\WC_Customer' ), 'Woo customer reads/writes must use the WooCommerce customer object.' );
	$assert( ! str_contains( $workspace_source, 'update_user_meta(' ) && ! str_contains( $workspace_source, 'update_post_meta(' ), 'Woo customer workspace must not bypass WooCommerce data stores with raw meta writes.' );
	$assert( ! str_contains( $workspace_source, 'wc_get_order(' ) && ! str_contains( $workspace_source, 'wc_get_orders(' ), 'Woo customer workspace must not mutate or repurpose historical order snapshots.' );
	$assert( str_contains( $details_source, 'get_edit_user_link( $linked )' ), 'Linked CRM Contacts must expose the canonical WordPress user-profile deeplink.' );

	exit( $failed ? 1 : 0 );
}

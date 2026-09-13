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

	function add_action( string $hook, mixed $callback, int $priority = 10, int $accepted_args = 1 ): void { global $actions; $actions[ $hook ][] = [ $callback, $priority, $accepted_args ]; }
	function add_filter( string $hook, mixed $callback, int $priority = 10, int $accepted_args = 1 ): void { global $filters; $filters[ $hook ][] = [ $callback, $priority, $accepted_args ]; }
	function current_user_can( string $capability, mixed ...$args ): bool { global $caps; unset( $args ); return ! empty( $caps[ $capability ] ); }
	function wp_is_post_autosave( int $post_id ): bool { unset( $post_id ); return false; }
	function wp_is_post_revision( int $post_id ): bool { unset( $post_id ); return false; }
	function wp_verify_nonce( string $nonce, string $action ): bool { return 'nonce' === $nonce && 'cb_crm_save_record' === $action; }
	function wp_unslash( mixed $value ): mixed { return $value; }
	function sanitize_text_field( string $value ): string { return trim( $value ); }
	function sanitize_key( string $value ): string { return strtolower( preg_replace( '/[^a-z0-9_\-]/', '', $value ) ); }
	function wc_clean( string $value ): string { return trim( $value ); }
	function absint( mixed $value ): int { return abs( (int) $value ); }
	function __( string $text, string $domain = 'default' ): string { unset( $domain ); return $text; }
	function esc_html( string $text ): string { return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' ); }
	function esc_attr( string $text ): string { return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' ); }
	function add_query_arg( string $key, string $value, string $location ): string { return $location . ( str_contains( $location, '?' ) ? '&' : '?' ) . rawurlencode( $key ) . '=' . rawurlencode( $value ); }
	function get_current_user_id(): int { return 123; }
}

namespace CB\CRM {
	final class Capabilities { public const MANAGE = 'cb_manage_crm'; }
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
		/** @var array<int,mixed> */ public static array $last = [];
		public static function record( mixed ...$args ): void { self::$last = $args; }
	}
}

namespace CB\CRM\Integration {
	final class ContactDataSources {
		public static function woo_customer( int $contact_id ): ?object {
			unset( $contact_id );
			$user_id = \CB\CRM\Content\ContactIdentity::$linked_user_id;
			return $user_id > 0 && isset( \WC_Customer::$store[ $user_id ] ) ? new \WC_Customer( $user_id ) : null;
		}
	}
}

namespace {
	require_once dirname( __DIR__ ) . '/src/Integration/WooCustomerWorkspace.php';

	use CB\CRM\Content\ContactIdentity;
	use CB\CRM\Integration\WooCustomerWorkspace;
	use CB\CRM\Repository\Activity;

	$failed = false;
	$assert = static function ( bool $condition, string $message ) use ( &$failed ): void {
		if ( ! $condition ) { fwrite( STDERR, $message . "\n" ); $failed = true; }
	};

	WooCustomerWorkspace::init();
	$save_hook = $actions['save_post_cb_crm_contact'][0] ?? null;
	$assert( is_array( $save_hook ) && 15 === $save_hook[1] && 3 === $save_hook[2], 'Woo persistence must stay on the Contact-specific save hook before generic CRM identity persistence.' );
	$assert( ! isset( $actions['cb_crm_register_panels'] ), 'Woo customer data must no longer register a duplicate standalone CRM panel.' );

	ob_start();
	WooCustomerWorkspace::render_binding( 44 );
	$binding = (string) ob_get_clean();
	$assert( str_contains( $binding, 'cb_crm_woo_customer_present' ) && str_contains( $binding, 'cb_crm_woo_customer_user_id' ) && str_contains( $binding, 'value="7"' ), 'Unified Contact workspace must bind Woo edits to the rendered linked identity.' );
	$assert( ! WooCustomerWorkspace::can_edit_customer(), 'CRM-only operators must not receive Woo write authority.' );

	$caps['manage_woocommerce'] = true;
	$assert( WooCustomerWorkspace::can_edit_customer(), 'Woo-authorized operators must receive Woo write authority.' );
	$_POST = [
		'cb_crm_nonce' => 'nonce',
		'cb_crm_woo_customer_present' => '1',
		'cb_crm_woo_customer_user_id' => '7',
		'cb_crm_details' => [ 'wp_user_id' => '7' ],
		'cb_crm_woo_customer' => [
			'billing_phone' => '+31 6 87654321',
			'billing_address_1' => 'Updated Street 2',
			'billing_company' => 'Updated BV',
		],
	];
	WooCustomerWorkspace::save_customer( 44, new WP_Post( 'publish' ), true );
	$assert( '+31 6 87654321' === WC_Customer::$store[7]['billing_phone'], 'Unified CRM fields must persist Woo phone through WC_Customer.' );
	$assert( 'Updated Street 2' === WC_Customer::$store[7]['billing_address_1'], 'Unified CRM address fields must persist through WC_Customer.' );
	$assert( 'Updated BV' === WC_Customer::$store[7]['billing_company'], 'Woo company data must remain Woo-owned.' );
	$assert( 1 === WC_Customer::$save_count, 'Changed Woo customer data must save exactly once.' );
	$assert( 'woocommerce_customer_updated' === ( Activity::$last[2] ?? '' ), 'Woo customer edits must leave CRM operator activity provenance.' );

	$before = WC_Customer::$store[7]['billing_phone'];
	$_POST['cb_crm_details']['wp_user_id'] = '8';
	$_POST['cb_crm_woo_customer']['billing_phone'] = 'should-not-cross-identities';
	WooCustomerWorkspace::save_customer( 44, new WP_Post( 'publish' ), true );
	$assert( $before === WC_Customer::$store[7]['billing_phone'], 'Pending CRM identity changes must suppress Woo writes until the Contact reloads.' );
	$assert( 1 === WC_Customer::$save_count, 'Pending identity switch must not invoke Woo persistence.' );

	unset( $_POST['cb_crm_details'] );
	ContactIdentity::$linked_user_id = 8;
	WooCustomerWorkspace::save_customer( 44, new WP_Post( 'publish' ), true );
	$assert( $before === WC_Customer::$store[7]['billing_phone'], 'Stale Woo payload must never cross to a different persisted CRM identity.' );
	$assert( 1 === WC_Customer::$save_count, 'Persisted identity mismatch must not invoke Woo persistence.' );

	$workspace_source = (string) file_get_contents( dirname( __DIR__ ) . '/src/Integration/WooCustomerWorkspace.php' );
	$details_source = (string) file_get_contents( dirname( __DIR__ ) . '/src/Admin/Panels/DetailsPanel.php' );
	$methods_source = (string) file_get_contents( dirname( __DIR__ ) . '/src/Admin/Panels/ContactMethodsPanel.php' );
	$addresses_source = (string) file_get_contents( dirname( __DIR__ ) . '/src/Admin/Panels/AddressesPanel.php' );
	$assert( ! str_contains( $workspace_source, 'PanelRegistry::register' ) && ! str_contains( $workspace_source, 'register_panel' ), 'Woo data must be composed into CRM panels, not duplicated in a Woo Customer panel.' );
	$assert( ! str_contains( $workspace_source, 'update_user_meta(' ) && ! str_contains( $workspace_source, 'update_post_meta(' ), 'Woo persistence must not bypass WooCommerce data stores with raw meta writes.' );
	$assert( ! str_contains( $workspace_source, 'wc_get_order(' ) && ! str_contains( $workspace_source, 'wc_get_orders(' ), 'Woo customer workspace must not mutate historical order snapshots.' );
	$assert( str_contains( $details_source, 'WooCustomerWorkspace::render_binding' ) && str_contains( $details_source, 'get_edit_user_link( $linked )' ), 'CRM Details must own the unified Woo binding and canonical WordPress profile link.' );
	$assert( str_contains( $methods_source, 'ContactDataSources::external_contact_methods' ) && str_contains( $methods_source, 'cb_crm_woo_customer[' ), 'Contact Methods must surface live connected sources and route Woo edits to the Woo persistence contract.' );
	$assert( str_contains( $addresses_source, "woo_address_card( \$post_id, 'billing' )" ) && str_contains( $addresses_source, 'cb_crm_woo_customer[' ), 'Addresses must surface live Woo billing data in the unified panel.' );

	exit( $failed ? 1 : 0 );
}

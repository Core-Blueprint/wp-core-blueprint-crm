<?php
declare(strict_types=1);

namespace {
	$actions = [];
	$orders  = [];
	if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', __DIR__ . '/' ); }
	class WP_Error {
		public function __construct( private string $code = '' ) {}
		public function get_error_code(): string { return $this->code; }
	}
	class WC_Order {
		public function __construct( private int $customer_id, private string $status = 'pending' ) {}
		public function get_customer_id(): int { return $this->customer_id; }
		public function get_status(): string { return $this->status; }
	}
	function add_action( string $hook, mixed $callback, int $priority = 10, int $accepted_args = 1 ): void { global $actions; $actions[ $hook ][] = [ $callback, $priority, $accepted_args ]; }
	function sanitize_key( string $value ): string { return strtolower( preg_replace( '/[^a-z0-9_-]/', '', $value ) ?? '' ); }
	function is_wp_error( mixed $value ): bool { return $value instanceof WP_Error; }
	function get_current_user_id(): int { return 0; }
	function __( string $text, string $domain = '' ): string { unset( $domain ); return $text; }
	function wc_get_order( int $order_id ): WC_Order|false { global $orders; return $orders[ $order_id ] ?? false; }
}

namespace CB\CRM\Application {
	final class ContactProvisioner {
		public static int $calls = 0;
		public static array|\WP_Error $result = [ 'contact_id' => 0, 'created' => false ];
		public static function ensure_for_user( int $user_id, int $post_author_id = 0 ): array|\WP_Error {
			unset( $user_id, $post_author_id );
			self::$calls++;
			return self::$result;
		}
	}
}

namespace CB\CRM\Content {
	final class Entity { public const CONTACT = 'contact'; }
	final class ContactIdentity {
		public static ?array $linked = null;
		public static function find_by_user_id( int $user_id ): ?array { unset( $user_id ); return self::$linked; }
		public static function linked_user_id( int $contact_id ): int { unset( $contact_id ); return 0; }
	}
}

namespace CB\CRM {
	final class Governance {
		public static array $failures = [];
		public static function record_order_activity( int $contact_id, int $order_id, string $from, string $to ): void { unset( $contact_id, $order_id, $from, $to ); }
		public static function record_order_provision_failed( int $order_id, int $user_id, string $reason, string $source ): void { self::$failures[] = [ $order_id, $user_id, $reason, $source ]; }
	}
	final class PanelRegistry { public static function register( array $args ): void { unset( $args ); } }
}

namespace CB\CRM\Repository {
	final class Activity { public static function record( mixed ...$args ): void { unset( $args ); } }
}

namespace {
	require_once dirname( __DIR__ ) . '/src/Integration/WooCommerce.php';

	use CB\CRM\Application\ContactProvisioner;
	use CB\CRM\Content\ContactIdentity;
	use CB\CRM\Governance;
	use CB\CRM\Integration\WooCommerce;

	$failed = false;
	$assert = static function ( bool $condition, string $message ) use ( &$failed ): void {
		if ( ! $condition ) { fwrite( STDERR, $message . "\n" ); $failed = true; }
	};

	WooCommerce::init();
	$assert( isset( $actions['woocommerce_order_status_changed'] ), 'Order-status trigger must be registered.' );
	$assert( isset( $actions['woocommerce_payment_complete'] ), 'Payment-complete fallback must be registered.' );
	$assert( isset( $actions['woocommerce_update_order'] ), 'Durable order-update reconciliation must be registered.' );

	$method = new \ReflectionMethod( WooCommerce::class, 'reconcile_order' );
	$method->setAccessible( true );

	$orders[500] = new WC_Order( 7, 'pending' );
	ContactIdentity::$linked = null;
	ContactProvisioner::$calls = 0;
	$result = $method->invoke( null, 500, 'order_update' );
	$assert( 0 === $result && 0 === ContactProvisioner::$calls, 'Non-qualifying statuses must not provision.' );

	$orders[501] = new WC_Order( 0, 'completed' );
	ContactProvisioner::$calls = 0;
	$result = $method->invoke( null, 501, 'order_update' );
	$assert( 0 === $result && 0 === ContactProvisioner::$calls, 'Completed guest/unbound orders must remain inert.' );

	$orders[502] = new WC_Order( 7, 'completed' );
	ContactIdentity::$linked = [ 'contact_id' => 33, 'ambiguous' => false ];
	ContactProvisioner::$calls = 0;
	$result = $method->invoke( null, 502, 'order_update' );
	$assert( 33 === $result && 0 === ContactProvisioner::$calls, 'Existing canonical links must be reused idempotently.' );

	$orders[503] = new WC_Order( 7, 'completed' );
	ContactIdentity::$linked = [ 'contact_id' => 33, 'ambiguous' => true ];
	Governance::$failures = [];
	$result = $method->invoke( null, 503, 'order_update' );
	$assert( 0 === $result, 'Ambiguous identity must fail closed.' );
	$assert( [ [ 503, 7, 'crm_identity_conflict', 'order_update' ] ] === Governance::$failures, 'Ambiguous reconciliation must be auditable.' );

	$orders[504] = new WC_Order( 7, 'processing' );
	ContactIdentity::$linked = null;
	ContactProvisioner::$calls = 0;
	ContactProvisioner::$result = [ 'contact_id' => 91, 'created' => true ];
	$result = $method->invoke( null, 504, 'order_update' );
	$assert( 91 === $result && 1 === ContactProvisioner::$calls, 'Processing reconciliation must provision an unlinked registered customer.' );

	$orders[505] = new WC_Order( 7, 'completed' );
	ContactIdentity::$linked = null;
	ContactProvisioner::$calls = 0;
	ContactProvisioner::$result = [ 'contact_id' => 92, 'created' => true ];
	WooCommerce::payment_complete( 505 );
	$assert( 1 === ContactProvisioner::$calls, 'Payment-complete must converge on reconciliation.' );

	$orders[506] = new WC_Order( 7, 'completed' );
	ContactIdentity::$linked = null;
	ContactProvisioner::$calls = 0;
	Governance::$failures = [];
	ContactProvisioner::$result = new WP_Error( 'forced_failure' );
	WooCommerce::order_updated( 506 );
	$assert( 1 === ContactProvisioner::$calls, 'Order-update reconciliation must invoke provisioning exactly once.' );
	$assert( [ [ 506, 7, 'forced_failure', 'order_update' ] ] === Governance::$failures, 'Reconciliation failures must leave an audit trail.' );

	$orders[507] = new WC_Order( 7, 'completed' );
	ContactIdentity::$linked = null;
	ContactProvisioner::$calls = 0;
	Governance::$failures = [];
	ContactProvisioner::$result = new WP_Error( 'crm_identity_provision_busy' );
	WooCommerce::order_updated( 507 );
	$assert( 1 === ContactProvisioner::$calls, 'Lock contention must return cleanly through reconciliation.' );
	$assert( [] === Governance::$failures, 'Expected transient lock contention must not create warning-level audit noise.' );

	exit( $failed ? 1 : 0 );
}

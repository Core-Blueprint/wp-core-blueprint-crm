<?php
declare(strict_types=1);

namespace {
	$transients = [];
	if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', __DIR__ . '/' ); }
	class WP_Post { public string $post_status = 'publish'; }
	function __( string $text, string $domain = '' ): string { unset( $domain ); return $text; }
	function add_action( ...$args ): void { unset( $args ); }
	function sanitize_key( string $value ): string { return strtolower( preg_replace( '/[^a-z0-9_-]/', '', $value ) ?? '' ); }
	function sanitize_text_field( string $value ): string { return trim( $value ); }
	function get_current_user_id(): int { return 0; }
	function get_transient( string $key ): mixed { global $transients; return $transients[ $key ] ?? false; }
	function set_transient( string $key, mixed $value, int $expiration ): bool { global $transients; unset( $expiration ); $transients[ $key ] = $value; return true; }
	function get_post_type( int $post_id ): string { unset( $post_id ); return ''; }
}

namespace CoreBlueprint\Core\Governance {
	final class Audit {
		public static array $records = [];
		public static function record( string $event, string $severity, array $context = [] ): void { self::$records[] = [ $event, $severity, $context ]; }
	}
	final class EventRegistry { public static function register( array $args ): void { unset( $args ); } }
}

namespace CB\CRM\Content {
	final class Entity {
		public static function owner_type_for_post( int $post_id ): string { unset( $post_id ); return ''; }
		public static function valid_owner( string $owner_type, int $owner_id ): bool { unset( $owner_type, $owner_id ); return false; }
	}
	final class PostTypes { public const CONTACT = 'cb_crm_contact'; }
}

namespace {
	require_once dirname( __DIR__ ) . '/src/Governance.php';

	use CoreBlueprint\Core\Governance\Audit;
	use CB\CRM\Governance;

	$failed = false;
	$assert = static function ( bool $condition, string $message ) use ( &$failed ): void {
		if ( ! $condition ) { fwrite( STDERR, $message . "\n" ); $failed = true; }
	};

	Governance::record_order_provision_failed( 700, 12, 'crm_identity_conflict', 'order_status' );
	Governance::record_order_provision_failed( 700, 12, 'crm_identity_conflict', 'payment_complete' );
	$assert( 1 === count( Audit::$records ), 'Repeated identical provisioning failures for one order/user/reason must be deduplicated across Woo signals.' );
	$assert( Governance::ORDER_ACTIVITY === Audit::$records[0][0] && 'warning' === Audit::$records[0][1], 'Provisioning failure must remain a warning-level CRM order activity audit.' );
	$assert( 'crm_identity_conflict' === Audit::$records[0][2]['reason'], 'Audit must preserve the fail-closed reason code.' );

	Governance::record_order_provision_failed( 700, 12, 'crm_identity_match_requires_review', 'order_update' );
	$assert( 2 === count( Audit::$records ), 'A different fail-closed reason must remain separately auditable.' );

	Governance::record_order_provision_failed( 701, 12, 'crm_identity_conflict', 'order_update' );
	$assert( 3 === count( Audit::$records ), 'The same reason on another order must remain separately auditable.' );

	exit( $failed ? 1 : 0 );
}

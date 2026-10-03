<?php
declare(strict_types=1);

namespace {
	$actions = [];
	if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', __DIR__ . '/' ); }

	class WP_Error {
		public function __construct( private string $code = '' ) {}
		public function get_error_code(): string { return $this->code; }
	}
	function add_action( string $hook, mixed $callback, int $priority = 10, int $accepted_args = 1 ): void {
		global $actions;
		$actions[ $hook ] = [ $callback, $priority, $accepted_args ];
	}
	function sanitize_email( string $value ): string { return strtolower( trim( $value ) ); }
	function is_wp_error( mixed $value ): bool { return $value instanceof WP_Error; }
	function get_current_user_id(): int { return 0; }
	function __( string $text, string $domain = '' ): string { unset( $domain ); return $text; }
}

namespace CB\CRM\Application {
	final class ContactSnapshotProvisioner {
		/** @var array<int,array<string,mixed>> */
		public static array $calls = [];
		public static array|\WP_Error $result = [ 'contact_id' => 0, 'created' => false ];
		/** @param array<string,mixed> $snapshot */
		public static function ensure( array $snapshot, int $post_author_id = 0 ): array|\WP_Error {
			unset( $post_author_id );
			self::$calls[] = $snapshot;
			return self::$result;
		}
	}
}

namespace CB\CRM\Content {
	final class Entity { public const CONTACT = 'contact'; }
}

namespace CB\CRM {
	final class Governance {
		public static array $activities = [];
		public static array $failures = [];
		public static function record_booking_activity( int $contact_id, int $booking_id, bool $created_contact ): void { self::$activities[] = [ $contact_id, $booking_id, $created_contact ]; }
		public static function record_booking_provision_failed( int $booking_id, string $reason ): void { self::$failures[] = [ $booking_id, $reason ]; }
	}
}

namespace CB\CRM\Repository {
	final class Activity {
		public static array $calls = [];
		public static function record( mixed ...$args ): bool { self::$calls[] = $args; return true; }
	}
}

namespace {
	require_once dirname( __DIR__ ) . '/src/Integration/Bookings.php';

	use CB\CRM\Application\ContactSnapshotProvisioner;
	use CB\CRM\Governance;
	use CB\CRM\Integration\Bookings;
	use CB\CRM\Repository\Activity;

	$failed = false;
	$assert = static function ( bool $condition, string $message ) use ( &$failed ): void {
		if ( ! $condition ) { fwrite( STDERR, $message . "\n" ); $failed = true; }
	};

	Bookings::init();
	$assert( isset( $actions['cb_bookings_booking_created'] ), 'CRM must listen to the public booking-created event.' );
	$assert( 20 === $actions['cb_bookings_booking_created'][1] && 1 === $actions['cb_bookings_booking_created'][2], 'Bookings event listener priority/arity must remain explicit.' );

	ContactSnapshotProvisioner::$calls = [];
	Bookings::booking_created( [ 'booking_id' => 10, 'customer_email' => '' ] );
	$assert( [] === ContactSnapshotProvisioner::$calls, 'Email-less bookings must remain valid and skip CRM auto-provisioning.' );

	ContactSnapshotProvisioner::$result = [ 'contact_id' => 44, 'created' => true ];
	ContactSnapshotProvisioner::$calls = [];
	Activity::$calls = [];
	Governance::$activities = [];
	Bookings::booking_created( [
		'booking_id'           => 11,
		'customer_first_name'  => 'Jan',
		'customer_name_prefix' => 'van der',
		'customer_last_name'   => 'Meer',
		'customer_email'       => 'JAN@example.test',
		'customer_phone'       => '+31 6 12345678',
	] );
	$snapshot = ContactSnapshotProvisioner::$calls[0] ?? [];
	$assert( 'Jan' === ( $snapshot['first_name'] ?? '' ) && 'van der' === ( $snapshot['name_prefix'] ?? '' ) && 'Meer' === ( $snapshot['last_name'] ?? '' ), 'Bookings adapter must forward structured identity without parsing a display name.' );
	$assert( 'jan@example.test' === ( $snapshot['email'] ?? '' ) && '+31 6 12345678' === ( $snapshot['phone'] ?? '' ), 'Bookings adapter must normalize email and forward optional phone.' );
	$activity = Activity::$calls[0] ?? [];
	$assert( 'booking_created' === ( $activity[2] ?? '' ) && 'bookings' === ( $activity[4] ?? '' ) && 'booking' === ( $activity[6] ?? '' ) && '11' === ( $activity[7] ?? '' ), 'Successful reconciliation must create CRM Activity with a Bookings reference.' );
	$assert( [ [ 44, 11, true ] ] === Governance::$activities, 'Successful new-contact reconciliation must be auditable.' );

	ContactSnapshotProvisioner::$result = [ 'contact_id' => 45, 'created' => false ];
	Governance::$activities = [];
	Bookings::booking_created( [
		'booking_id'          => 12,
		'customer_first_name' => 'Existing',
		'customer_last_name'  => 'Contact',
		'customer_email'      => 'existing@example.test',
	] );
	$assert( [ [ 45, 12, false ] ] === Governance::$activities, 'Existing unique email match must be reused without being reported as newly created.' );

	ContactSnapshotProvisioner::$result = new WP_Error( 'crm_snapshot_identity_conflict' );
	Governance::$failures = [];
	Bookings::booking_created( [
		'booking_id'          => 13,
		'customer_first_name' => 'Ambiguous',
		'customer_last_name'  => 'Contact',
		'customer_email'      => 'ambiguous@example.test',
	] );
	$assert( [ [ 13, 'crm_snapshot_identity_conflict' ] ] === Governance::$failures, 'Identity ambiguity must fail closed with governance evidence.' );

	ContactSnapshotProvisioner::$result = new WP_Error( 'crm_snapshot_provision_busy' );
	Governance::$failures = [];
	Bookings::booking_created( [
		'booking_id'          => 14,
		'customer_first_name' => 'Busy',
		'customer_last_name'  => 'Contact',
		'customer_email'      => 'busy@example.test',
	] );
	$assert( [] === Governance::$failures, 'Expected transient snapshot lock contention must not create warning noise.' );

	$source = (string) file_get_contents( dirname( __DIR__ ) . '/src/Integration/Bookings.php' );
	$assert( ! str_contains( $source, 'CB\\Bookings' ), 'CRM Bookings integration must not depend on Bookings classes.' );
	$assert( ! str_contains( $source, "'customer_name'") && str_contains( $source, 'customer_first_name' ) && str_contains( $source, 'customer_last_name' ), 'CRM Bookings integration must consume only the structured v1 identity contract.' );

	exit( $failed ? 1 : 0 );
}

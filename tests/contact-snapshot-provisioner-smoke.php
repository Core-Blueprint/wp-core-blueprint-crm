<?php
declare(strict_types=1);

namespace {
	$state = [
		'inserted' => 0,
		'deleted' => [],
		'published' => [],
		'options' => [],
		'post_args' => [],
	];

	if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', __DIR__ . '/' ); }

	class WP_Error {
		public function __construct( private string $code = '' ) {}
		public function get_error_code(): string { return $this->code; }
	}

	final class SnapshotWpdb {
		public string $options = 'wp_options';
		public function delete( string $table, array $where, array $format = [] ): int|false {
			global $state;
			unset( $table, $format );
			$key = (string) ( $where['option_name'] ?? '' );
			$value = (string) ( $where['option_value'] ?? '' );
			if ( ! array_key_exists( $key, $state['options'] ) || $state['options'][ $key ] !== $value ) {
				return 0;
			}
			unset( $state['options'][ $key ] );
			return 1;
		}
	}
	$GLOBALS['wpdb'] = new SnapshotWpdb();

	function is_wp_error( mixed $value ): bool { return $value instanceof WP_Error; }
	function sanitize_email( string $value ): string { return strtolower( trim( $value ) ); }
	function is_email( string $value ): bool { return false !== filter_var( $value, FILTER_VALIDATE_EMAIL ); }
	function sanitize_text_field( string $value ): string { return trim( preg_replace( '/\s+/', ' ', $value ) ?? '' ); }
	function wp_insert_post( array $args, bool $wp_error = false ): int|WP_Error {
		global $state;
		unset( $wp_error );
		$state['inserted']++;
		$id = 200 + $state['inserted'];
		$state['post_args'][ $id ] = $args;
		return $id;
	}
	function wp_update_post( array $args, bool $wp_error = false ): int|WP_Error {
		global $state;
		unset( $wp_error );
		$state['published'][] = (int) ( $args['ID'] ?? 0 );
		return (int) ( $args['ID'] ?? 0 );
	}
	function wp_delete_post( int $post_id, bool $force_delete = false ): bool {
		global $state;
		unset( $force_delete );
		$state['deleted'][] = $post_id;
		foreach ( \CB\CRM\Content\ContactIdentity::$matches as $email => $ids ) {
			\CB\CRM\Content\ContactIdentity::$matches[ $email ] = array_values( array_filter( $ids, static fn ( int $id ): bool => $id !== $post_id ) );
		}
		return true;
	}
	function add_option( string $option, mixed $value = '', string $deprecated = '', bool|null $autoload = null ): bool {
		global $state;
		unset( $deprecated, $autoload );
		if ( array_key_exists( $option, $state['options'] ) ) { return false; }
		$state['options'][ $option ] = (string) $value;
		return true;
	}
	function get_option( string $option, mixed $default = false ): mixed { global $state; return $state['options'][ $option ] ?? $default; }
	function wp_json_encode( mixed $value ): string|false { return json_encode( $value ); }
	function wp_generate_uuid4(): string { static $i = 0; $i++; return '00000000-0000-4000-8000-' . str_pad( (string) $i, 12, '0', STR_PAD_LEFT ); }
	function wp_cache_delete( string $key, string $group = '' ): bool { unset( $key, $group ); return true; }
}

namespace CB\CRM\Content {
	final class PostTypes { public const CONTACT = 'cb_crm_contact'; }
	final class ContactIdentity {
		/** @var array<string,int[]> */ public static array $matches = [];
		/** @return int[] */ public static function find_by_email( string $email ): array { return self::$matches[ strtolower( $email ) ] ?? []; }
	}
}

namespace CB\CRM\Application {
	final class RecordUpdater {
		public static bool $fail = false;
		public static int $extra_match = 0;
		/** @var array<string,mixed> */
		public static array $last_input = [];
		/** @param array<string,mixed> $input */
		public static function update_contact( int $contact_id, array $input ): array|\WP_Error {
			self::$last_input = $input;
			if ( self::$fail ) { return new \WP_Error( 'forced_update_failure' ); }
			$email = '';
			foreach ( (array) ( $input['contact_methods'] ?? [] ) as $method ) {
				if ( is_array( $method ) && 'email' === ( $method['method_type'] ?? '' ) ) {
					$email = strtolower( (string) ( $method['value'] ?? '' ) );
					break;
				}
			}
			if ( '' !== $email ) {
				\CB\CRM\Content\ContactIdentity::$matches[ $email ] = [ $contact_id ];
				if ( self::$extra_match > 0 ) {
					\CB\CRM\Content\ContactIdentity::$matches[ $email ][] = self::$extra_match;
				}
			}
			return [ 'record_id' => $contact_id, 'owner_type' => 'contact', 'updated_areas' => [ 'details', 'contact_methods' ] ];
		}
	}
}

namespace {
	require_once dirname( __DIR__ ) . '/src/Application/ContactSnapshotProvisioner.php';

	use CB\CRM\Application\ContactSnapshotProvisioner;
	use CB\CRM\Application\RecordUpdater;
	use CB\CRM\Content\ContactIdentity;

	$failed = false;
	$assert = static function ( bool $condition, string $message ) use ( &$failed ): void {
		if ( ! $condition ) { fwrite( STDERR, $message . "\n" ); $failed = true; }
	};

	$result = ContactSnapshotProvisioner::ensure( [
		'first_name' => 'No',
		'last_name'  => 'Email',
		'email'      => '',
	] );
	$assert( is_wp_error( $result ) && 'crm_snapshot_identity_invalid' === $result->get_error_code(), 'Snapshot provisioning must require a valid email.' );
	$assert( 0 === $state['inserted'], 'Invalid identity must not create a Contact.' );

	ContactIdentity::$matches['existing@example.test'] = [ 44 ];
	$result = ContactSnapshotProvisioner::ensure( [
		'first_name' => 'Existing',
		'last_name'  => 'Contact',
		'email'      => 'existing@example.test',
	] );
	$assert( is_array( $result ) && 44 === $result['contact_id'] && false === $result['created'], 'One email match must be reused without a write.' );
	$assert( 0 === $state['inserted'], 'Existing email match must not create a duplicate Contact.' );

	ContactIdentity::$matches['ambiguous@example.test'] = [ 50, 51 ];
	$result = ContactSnapshotProvisioner::ensure( [
		'first_name' => 'Ambiguous',
		'last_name'  => 'Contact',
		'email'      => 'ambiguous@example.test',
	] );
	$assert( is_wp_error( $result ) && 'crm_snapshot_identity_conflict' === $result->get_error_code(), 'Multiple email matches must fail closed.' );

	$busy_email = 'busy@example.test';
	$busy_key = 'cb_crm_snapshot_provision_' . substr( hash( 'sha256', $busy_email ), 0, 40 );
	$state['options'][ $busy_key ] = json_encode( [ 'token' => 'other', 'expires' => time() + 30 ] );
	$result = ContactSnapshotProvisioner::ensure( [
		'first_name' => 'Busy',
		'last_name'  => 'Contact',
		'email'      => $busy_email,
	] );
	$assert( is_wp_error( $result ) && 'crm_snapshot_provision_busy' === $result->get_error_code(), 'Live snapshot lease must fail closed.' );
	$assert( 0 === $state['inserted'], 'Lock contention must not create a Contact.' );

	$email = 'jan@example.test';
	$key = 'cb_crm_snapshot_provision_' . substr( hash( 'sha256', $email ), 0, 40 );
	$state['options'][ $key ] = json_encode( [ 'token' => 'stale', 'expires' => time() - 1 ] );
	ContactIdentity::$matches[ $email ] = [];
	RecordUpdater::$fail = false;
	RecordUpdater::$extra_match = 0;
	$result = ContactSnapshotProvisioner::ensure( [
		'first_name'  => 'Jan',
		'name_prefix' => 'van der',
		'last_name'   => 'Meer',
		'email'       => $email,
		'phone'       => '+31 6 12345678',
	] );
	$assert( is_array( $result ) && true === $result['created'], 'Stale lease must be reclaimed and permit provisioning.' );
	$created_id = (int) ( $result['contact_id'] ?? 0 );
	$assert( $created_id > 0 && 'Jan van der Meer' === ( $state['post_args'][ $created_id ]['post_title'] ?? '' ), 'New Contact title must use structured identity.' );
	$assert( 'Jan' === ( RecordUpdater::$last_input['first_name'] ?? '' ) && 'van der' === ( RecordUpdater::$last_input['name_prefix'] ?? '' ) && 'Meer' === ( RecordUpdater::$last_input['last_name'] ?? '' ), 'Structured name fields must be persisted without parsing.' );
	$methods = RecordUpdater::$last_input['contact_methods'] ?? [];
	$assert( 2 === count( $methods ) && 'email' === ( $methods[0]['method_type'] ?? '' ) && 'phone' === ( $methods[1]['method_type'] ?? '' ), 'New Contact must persist email and optional phone through canonical contact methods.' );
	$assert( [ $created_id ] === $state['published'], 'Contact must publish only after canonical update succeeds.' );
	$assert( ! array_key_exists( $key, $state['options'] ), 'Snapshot lease must be released after successful provisioning.' );

	$fail_email = 'writefail@example.test';
	ContactIdentity::$matches[ $fail_email ] = [];
	RecordUpdater::$fail = true;
	$result = ContactSnapshotProvisioner::ensure( [
		'first_name' => 'Write',
		'last_name'  => 'Failure',
		'email'      => $fail_email,
	] );
	$failed_id = 200 + $state['inserted'];
	$assert( is_wp_error( $result ) && 'forced_update_failure' === $result->get_error_code(), 'Canonical write failure must propagate.' );
	$assert( in_array( $failed_id, $state['deleted'], true ), 'Provisioning must roll back only its own Contact when canonical update fails.' );
	RecordUpdater::$fail = false;

	$race_email = 'race@example.test';
	ContactIdentity::$matches[ $race_email ] = [];
	RecordUpdater::$extra_match = 777;
	$result = ContactSnapshotProvisioner::ensure( [
		'first_name' => 'Race',
		'last_name'  => 'Contact',
		'email'      => $race_email,
	] );
	$race_id = 200 + $state['inserted'];
	$assert( is_wp_error( $result ) && 'crm_snapshot_identity_conflict' === $result->get_error_code(), 'Post-write email ambiguity must fail closed.' );
	$assert( in_array( $race_id, $state['deleted'], true ), 'Race reconciliation must delete only the Contact created by the current request.' );
	$assert( [ 777 ] === ContactIdentity::$matches[ $race_email ], 'Race rollback must preserve the competing Contact.' );

	exit( $failed ? 1 : 0 );
}

<?php
declare(strict_types=1);

namespace {
	$state = [
		'users' => [],
		'inserted' => 0,
		'deleted' => [],
		'published' => [],
		'options' => [],
	];

	if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', __DIR__ . '/' ); }
	class WP_Error {
		public function __construct( public string $code = '', public string $message = '' ) {}
		public function get_error_code(): string { return $this->code; }
	}
	class WP_User {
		public int $ID = 0;
		public string $user_email = '';
		public string $display_name = '';
		public string $user_login = '';
	}
	class FakeWpdb {
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
	$GLOBALS['wpdb'] = new FakeWpdb();

	function __( string $text, string $domain = '' ): string { unset( $domain ); return $text; }
	function is_wp_error( mixed $value ): bool { return $value instanceof WP_Error; }
	function sanitize_email( string $email ): string { return strtolower( trim( $email ) ); }
	function sanitize_text_field( string $value ): string { return trim( $value ); }
	function get_userdata( int $user_id ): WP_User|false { global $state; return $state['users'][ $user_id ] ?? false; }
	function get_user_meta( int $user_id, string $key, bool $single = false ): string { unset( $user_id, $single ); return 'first_name' === $key ? 'Chris' : ( 'last_name' === $key ? 'Tester' : '' ); }
	function wp_insert_post( array $args, bool $wp_error = false ): int|WP_Error { global $state; unset( $args, $wp_error ); $state['inserted']++; return 100 + $state['inserted']; }
	function wp_update_post( array $args, bool $wp_error = false ): int|WP_Error { global $state; unset( $wp_error ); $state['published'][] = (int) $args['ID']; return (int) $args['ID']; }
	function wp_delete_post( int $post_id, bool $force_delete = false ): bool { global $state; unset( $force_delete ); $state['deleted'][] = $post_id; foreach ( \CB\CRM\Content\ContactIdentity::$links as $user_id => $links ) { \CB\CRM\Content\ContactIdentity::$links[ $user_id ] = array_values( array_filter( $links, static fn( int $id ): bool => $id !== $post_id ) ); } return true; }
	function add_option( string $option, mixed $value = '', string $deprecated = '', bool|null $autoload = null ): bool { global $state; unset( $deprecated, $autoload ); if ( array_key_exists( $option, $state['options'] ) ) { return false; } $state['options'][ $option ] = (string) $value; return true; }
	function get_option( string $option, mixed $default = false ): mixed { global $state; return $state['options'][ $option ] ?? $default; }
	function wp_json_encode( mixed $value ): string|false { return json_encode( $value ); }
	function wp_generate_uuid4(): string { static $i = 0; $i++; return '00000000-0000-4000-8000-' . str_pad( (string) $i, 12, '0', STR_PAD_LEFT ); }
	function wp_cache_delete( string $key, string $group = '' ): bool { unset( $key, $group ); return true; }
}

namespace CB\CRM\Content {
	final class PostTypes { public const CONTACT = 'cb_crm_contact'; }
	final class ContactIdentity {
		public const EMAIL_WP = 'wp_user';
		/** @var array<int,int[]> */ public static array $links = [];
		/** @var int[] */ public static array $email_matches = [];
		/** @return int[] */ public static function contact_ids_for_user( int $user_id ): array { return self::$links[ $user_id ] ?? []; }
		/** @return int[] */ public static function find_by_email( string $email ): array { unset( $email ); return self::$email_matches; }
	}
}

namespace CB\CRM\Application {
	final class RecordUpdater {
		public static bool $fail = false;
		public static int $extra_link_after_update = 0;
		/** @param array<string,mixed> $input */
		public static function update_contact( int $contact_id, array $input ): array|\WP_Error {
			if ( self::$fail ) { return new \WP_Error( 'forced_failure' ); }
			$user_id = (int) ( $input['wp_user_id'] ?? 0 );
			if ( $user_id > 0 ) {
				\CB\CRM\Content\ContactIdentity::$links[ $user_id ] = [ $contact_id ];
				if ( self::$extra_link_after_update > 0 ) {
					\CB\CRM\Content\ContactIdentity::$links[ $user_id ][] = self::$extra_link_after_update;
				}
			}
			return [ 'record_id' => $contact_id, 'owner_type' => 'contact', 'updated_areas' => [ 'details' ] ];
		}
	}
}

namespace {
	require_once dirname( __DIR__ ) . '/src/Application/ContactProvisioner.php';

	use CB\CRM\Application\ContactProvisioner;
	use CB\CRM\Application\RecordUpdater;
	use CB\CRM\Content\ContactIdentity;

	$failed = false;
	$assert = static function ( bool $condition, string $message ) use ( &$failed ): void {
		if ( ! $condition ) { fwrite( STDERR, $message . "\n" ); $failed = true; }
	};

	$user = new WP_User();
	$user->ID = 7;
	$user->user_email = 'buyer@example.test';
	$user->display_name = 'Buyer Example';
	$user->user_login = 'buyer';
	$state['users'][7] = $user;

	ContactIdentity::$links = [ 7 => [ 44 ] ];
	$result = ContactProvisioner::ensure_for_user( 7 );
	$assert( is_array( $result ) && 44 === $result['contact_id'] && false === $result['created'], 'Existing canonical user link must be reused.' );
	$assert( 0 === $state['inserted'], 'Existing link must not create a duplicate Contact.' );

	ContactIdentity::$links = [ 7 => [ 44, 45 ] ];
	$result = ContactProvisioner::ensure_for_user( 7 );
	$assert( is_wp_error( $result ) && 'crm_identity_conflict' === $result->get_error_code(), 'Ambiguous canonical links must fail closed.' );

	ContactIdentity::$links = [ 7 => [] ];
	ContactIdentity::$email_matches = [ 88 ];
	$result = ContactProvisioner::ensure_for_user( 7 );
	$assert( is_wp_error( $result ) && 'crm_identity_match_requires_review' === $result->get_error_code(), 'Email candidates must require operator review instead of auto-linking.' );
	$assert( 0 === $state['inserted'], 'Email collision must not create a duplicate Contact.' );

	ContactIdentity::$email_matches = [];
	$state['options']['cb_crm_identity_provision_7'] = json_encode( [ 'token' => 'other-request', 'expires' => time() + 30 ] );
	$result = ContactProvisioner::ensure_for_user( 7 );
	$assert( is_wp_error( $result ) && 'crm_identity_provision_busy' === $result->get_error_code(), 'A live identity lease must fail closed instead of creating concurrently.' );
	$assert( 0 === $state['inserted'], 'Lock contention must not create a Contact.' );

	$state['options']['cb_crm_identity_provision_7'] = json_encode( [ 'token' => 'stale-request', 'expires' => time() - 1 ] );
	RecordUpdater::$fail = false;
	$result = ContactProvisioner::ensure_for_user( 7 );
	$assert( is_array( $result ) && true === $result['created'] && (int) $result['contact_id'] > 0, 'A stale lease must be reclaimed and allow provisioning.' );
	$assert( 1 === $state['inserted'], 'Provisioning must create exactly one Contact.' );
	$assert( [ (int) $result['contact_id'] ] === ContactIdentity::$links[7], 'Provisioning must establish the canonical WP User link.' );
	$assert( [ (int) $result['contact_id'] ] === $state['published'], 'Provisioning must publish only after the canonical update succeeds.' );
	$assert( ! array_key_exists( 'cb_crm_identity_provision_7', $state['options'] ), 'Provisioning must release its identity lease.' );

	$user2 = new WP_User();
	$user2->ID = 8;
	$user2->user_email = 'race@example.test';
	$user2->display_name = 'Race Example';
	$user2->user_login = 'race';
	$state['users'][8] = $user2;
	ContactIdentity::$links[8] = [];
	RecordUpdater::$extra_link_after_update = 777;
	$result = ContactProvisioner::ensure_for_user( 8 );
	$assert( is_wp_error( $result ) && 'crm_identity_conflict' === $result->get_error_code(), 'Post-write identity ambiguity must fail closed.' );
	$assert( [ 777 ] === ContactIdentity::$links[8], 'Race reconciliation must roll back only the Contact created by this request.' );
	$assert( ! array_key_exists( 'cb_crm_identity_provision_8', $state['options'] ), 'Conflict rollback must still release its identity lease.' );

	exit( $failed ? 1 : 0 );
}

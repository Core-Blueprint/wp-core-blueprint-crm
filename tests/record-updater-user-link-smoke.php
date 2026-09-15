<?php
declare(strict_types=1);

namespace {
	if ( ! defined( 'ABSPATH' ) ) {
		define( 'ABSPATH', __DIR__ . '/' );
	}

	final class WP_Error {
		/** @param array<string,mixed> $data */
		public function __construct( private string $code = '', private string $message = '', private array $data = [] ) {}
		public function get_error_code(): string { return $this->code; }
		/** @return array<string,mixed> */
		public function get_error_data(): array { return $this->data; }
	}

	final class WP_Post {
		public function __construct( public int $ID, public string $post_type, public string $post_status = 'publish' ) {}
	}

	$GLOBALS['crm_record_updater_meta'] = [
		'_cb_crm_wp_user_id' => 7,
		'_cb_crm_email_mode' => 'wp_user',
	];

	function __( string $text, string $domain = '' ): string { unset( $domain ); return $text; }
	function absint( mixed $value ): int { return abs( (int) $value ); }
	function sanitize_key( string $value ): string { return strtolower( preg_replace( '/[^a-z0-9_\-]/', '', $value ) ?? '' ); }
	function sanitize_text_field( string $value ): string { return trim( strip_tags( $value ) ); }
	function sanitize_title( string $value ): string { return sanitize_key( str_replace( ' ', '-', $value ) ); }
	function get_post( int $post_id ): ?WP_Post { return 42 === $post_id ? new WP_Post( 42, 'cb_crm_contact' ) : null; }
	function get_userdata( int $user_id ): object|false { return 7 === $user_id ? (object) [ 'ID' => 7 ] : false; }
	function get_post_meta( int $post_id, string $key, bool $single = false ): mixed { unset( $post_id, $single ); return $GLOBALS['crm_record_updater_meta'][ $key ] ?? ''; }
	function update_post_meta( int $post_id, string $key, mixed $value ): bool { unset( $post_id ); $GLOBALS['crm_record_updater_meta'][ $key ] = $value; return true; }
	function get_the_title( int $post_id ): string { unset( $post_id ); return 'Contact'; }
	function wp_update_post( array $args, bool $wp_error = false ): int|WP_Error { unset( $wp_error ); return (int) ( $args['ID'] ?? 0 ); }
	function wp_set_object_terms( int $object_id, array $terms, string $taxonomy, bool $append = false ): array { unset( $object_id, $taxonomy, $append ); return $terms; }
	function is_wp_error( mixed $value ): bool { return $value instanceof WP_Error; }
	function get_current_user_id(): int { return 1; }
}

namespace CB\CRM\Content {
	final class Entity {
		public const CONTACT = 'contact';
		public const ORGANIZATION = 'organization';
		public static function post_type_for_owner( string $owner_type ): string { return self::CONTACT === $owner_type ? 'cb_crm_contact' : 'cb_crm_org'; }
	}
	final class Meta {
		public const STATUS = '_cb_crm_status';
		public const FIRST_NAME = '_cb_crm_first_name';
		public const LAST_NAME = '_cb_crm_last_name';
		public const JOB_TITLE = '_cb_crm_job_title';
		public const WP_USER_ID = '_cb_crm_wp_user_id';
		public const EMAIL_MODE = '_cb_crm_email_mode';
		public const LEGAL_NAME = '_cb_crm_legal_name';
	}
	final class PostTypes { public const TAG = 'cb_crm_tag'; }
	final class RecordStatus { public const VALUES = [ 'active', 'inactive' ]; }
	final class ContactIdentity {
		public const EMAIL_CRM = 'crm';
		public const EMAIL_WP = 'wp_user';
		public static function linked_user_id( int $contact_id ): int { unset( $contact_id ); return (int) ( $GLOBALS['crm_record_updater_meta'][ Meta::WP_USER_ID ] ?? 0 ); }
		public static function can_link_user_to_contact( int $user_id, int $contact_id ): bool { unset( $contact_id ); return 7 === $user_id; }
	}
}

namespace CB\CRM\Integration {
	final class ContactDataSources { public static function name_candidates( int $contact_id, string $field ): array { unset( $contact_id, $field ); return []; } }
}

namespace CB\CRM\Repository {
	final class Activity { public static function record( mixed ...$args ): void { unset( $args ); } }
	final class Addresses { public static function replace( string $owner_type, int $record_id, array $rows ): bool { unset( $owner_type, $record_id, $rows ); return true; } }
	final class BusinessIdentifiers { public static function replace( int $record_id, array $rows ): bool { unset( $record_id, $rows ); return true; } }
	final class ContactMethods { public static function replace( string $owner_type, int $record_id, array $rows ): bool { unset( $owner_type, $record_id, $rows ); return true; } }
	final class Names { public static function replace( string $owner_type, int $record_id, array $rows ): bool { unset( $owner_type, $record_id, $rows ); return true; } }
	final class Organizations { public static function replace_for_contact( int $record_id, array $rows ): bool { unset( $record_id, $rows ); return true; } }
	final class ServiceAgreements { public static function replace( string $owner_type, int $record_id, array $rows ): bool { unset( $owner_type, $record_id, $rows ); return true; } }
}

namespace CB\CRM {
	final class Governance { public static function record_data_updated( mixed ...$args ): void { unset( $args ); } }
}

namespace {
	require dirname( __DIR__ ) . '/src/Application/RecordUpdater.php';

	$result = \CB\CRM\Application\RecordUpdater::update_contact( 42, [ 'wp_user_id' => 999 ] );
	if ( ! $result instanceof WP_Error ) {
		fwrite( STDERR, "FAIL: missing WordPress user must report crm_update_failed.\n" );
		exit( 1 );
	}
	$data = $result->get_error_data();
	if ( ! in_array( 'wordpress_user', $data['failed_areas'] ?? [], true ) ) {
		fwrite( STDERR, "FAIL: missing WordPress user must report wordpress_user failure.\n" );
		exit( 1 );
	}
	if ( 7 !== (int) $GLOBALS['crm_record_updater_meta']['_cb_crm_wp_user_id'] ) {
		fwrite( STDERR, "FAIL: invalid candidate user must not clear the existing CRM user link.\n" );
		exit( 1 );
	}
	if ( 'wp_user' !== $GLOBALS['crm_record_updater_meta']['_cb_crm_email_mode'] ) {
		fwrite( STDERR, "FAIL: invalid candidate user must not change email authority.\n" );
		exit( 1 );
	}

	echo "CRM RecordUpdater user-link fail-closed smoke passed.\n";
}

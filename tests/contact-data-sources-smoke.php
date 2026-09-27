<?php
declare(strict_types=1);

namespace {
	if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', __DIR__ . '/' ); }
	$meta = [ 44 => [ '_cb_crm_first_name' => '', '_cb_crm_name_prefix' => 'van', '_cb_crm_last_name' => '' ] ];
	$user_meta = [ 7 => [ 'first_name' => 'Chris', 'last_name' => 'Example' ] ];

	class WP_User {
		public function __construct( public int $ID, public string $user_email, public string $display_name = '' ) {}
	}
	class WC_Customer {
		public static array $store = [
			7 => [
				'billing_first_name' => 'Christopher',
				'billing_last_name' => 'Example',
				'billing_email' => 'billing@example.test',
				'billing_phone' => '+31 6 12345678',
				'shipping_phone' => '',
			],
		];
		public function __construct( private int $id ) {}
		public function get_id(): int { return isset( self::$store[ $this->id ] ) ? $this->id : 0; }
		public function __call( string $name, array $args ): string {
			unset( $args );
			if ( str_starts_with( $name, 'get_' ) ) { return (string) ( self::$store[ $this->id ][ substr( $name, 4 ) ] ?? '' ); }
			throw new \BadMethodCallException( $name );
		}
	}

	function get_userdata( int $user_id ): WP_User|false { return 7 === $user_id ? new WP_User( 7, 'account@example.test', 'Chris Example' ) : false; }
	function get_user_meta( int $user_id, string $key, bool $single = false ): string { global $user_meta; unset( $single ); return (string) ( $user_meta[ $user_id ][ $key ] ?? '' ); }
	function get_post_meta( int $post_id, string $key, bool $single = false ): string { global $meta; unset( $single ); return (string) ( $meta[ $post_id ][ $key ] ?? '' ); }
	function update_post_meta( int $post_id, string $key, mixed $value ): bool { global $meta; $meta[ $post_id ][ $key ] = (string) $value; return true; }
	function get_current_user_id(): int { return 123; }
	function __( string $text, string $domain = '' ): string { unset( $domain ); return $text; }
	function sanitize_text_field( string $value ): string { return trim( $value ); }
	function sanitize_email( string $value ): string { return strtolower( trim( $value ) ); }
	function sanitize_key( string $value ): string { return strtolower( preg_replace( '/[^a-z0-9_\-]/', '', $value ) ); }
	function wp_unslash( mixed $value ): mixed { return $value; }
	function absint( mixed $value ): int { return abs( (int) $value ); }
	function is_email( string $value ): bool { return false !== filter_var( $value, FILTER_VALIDATE_EMAIL ); }
}

namespace CB\CRM\Content {
	final class Meta {
		public const FIRST_NAME = '_cb_crm_first_name';
		public const NAME_PREFIX = '_cb_crm_name_prefix';
		public const LAST_NAME = '_cb_crm_last_name';
	}
	final class Entity { public const CONTACT = 'contact'; public const ORGANIZATION = 'organization'; }
	final class RecordStatus { public const ACTIVE = 'active'; }
	final class ContactIdentity {
		public static int $linked_user_id = 7;
		public static string $preferred = 'account@example.test';
		public static function linked_user_id( int $contact_id ): int { unset( $contact_id ); return self::$linked_user_id; }
		public static function preferred_email( int $contact_id ): string { unset( $contact_id ); return self::$preferred; }
	}
}

namespace CB\CRM\Repository {
	final class Activity {
		public static array $events = [];
		public static function record( mixed ...$args ): bool { self::$events[] = $args; return true; }
	}
	final class ContactMethods {
		public static ?array $phone = null;
		public static function primary( string $owner_type, int $owner_id, string $type ): ?array {
			unset( $owner_type, $owner_id );
			return in_array( $type, [ 'phone', 'mobile' ], true ) ? self::$phone : null;
		}
	}
}

namespace {
	require_once dirname( __DIR__ ) . '/src/Integration/ContactDataSources.php';
	require_once dirname( __DIR__ ) . '/src/Admin/Save.php';
	require_once dirname( __DIR__ ) . '/src/Application/RecordUpdater.php';

	use CB\CRM\Content\ContactIdentity;
	use CB\CRM\Integration\ContactDataSources;
	use CB\CRM\Repository\ContactMethods;

	$failed = false;
	$assert = static function ( bool $condition, string $message ) use ( &$failed ): void {
		if ( ! $condition ) { fwrite( STDERR, $message . "\n" ); $failed = true; }
	};

	$assert( 'van' === ContactDataSources::name_prefix( 44 ), 'CRM-owned name prefix must resolve independently from connected first/last-name sources.' );

	$first = ContactDataSources::name_field( 44, 'first_name' );
	$assert( 'Chris' === $first['value'] && 'wordpress' === $first['source'] && false === $first['override'], 'Empty CRM name must resolve live WordPress identity data first.' );
	$assert( 2 === count( $first['candidates'] ) && 'Christopher' === $first['candidates'][1]['value'], 'Woo billing identity must remain visible as connected provenance.' );

	$meta[44]['_cb_crm_first_name'] = 'Chris';
	$first = ContactDataSources::name_field( 44, 'first_name' );
	$assert( 'wordpress' === $first['source'] && false === $first['override'], 'A legacy CRM shadow identical to WordPress must not masquerade as an override.' );

	$meta[44]['_cb_crm_first_name'] = 'CRM Chris';
	$first = ContactDataSources::name_field( 44, 'first_name' );
	$assert( 'CRM Chris' === $first['value'] && 'crm' === $first['source'] && true === $first['override'], 'A divergent explicit CRM value must win while remaining marked as an override.' );
	$assert( 'Chris' === $first['candidates'][0]['value'] && 'Christopher' === $first['candidates'][1]['value'], 'An override must retain both WordPress and Woo live values for provenance.' );

	$meta[44]['_cb_crm_first_name'] = '';
	$user_meta[7]['first_name'] = '';
	$first = ContactDataSources::name_field( 44, 'first_name' );
	$assert( 'Christopher' === $first['value'] && 'woocommerce' === $first['source'], 'Woo billing identity must be the live fallback when WordPress has no first name.' );

	$methods = ContactDataSources::external_contact_methods( 44 );
	$assert( 3 === count( $methods ), 'Connected contact methods must expose WordPress email plus Woo billing email and phone without copying them to CRM.' );
	$assert( 'wordpress' === $methods[0]['source'] && 'user_email' === $methods[0]['path'], 'WordPress account email provenance must be explicit.' );

	ContactMethods::$phone = null;
	$assert( '+31 6 12345678' === ContactDataSources::effective_phone( 44 ), 'CRM projection must fall back to live Woo phone when CRM has no relationship phone.' );
	ContactMethods::$phone = [ 'value' => '+31 6 99999999' ];
	$assert( '+31 6 99999999' === ContactDataSources::effective_phone( 44 ), 'Explicit CRM relationship phone must override the Woo fallback.' );

	$assert( 'account@example.test' === ContactDataSources::effective_email( 44 ), 'Existing CRM/WordPress preferred email policy must stay authoritative.' );
	ContactIdentity::$preferred = '';
	$assert( 'billing@example.test' === ContactDataSources::effective_email( 44 ), 'Woo billing email must remain a fallback rather than duplicated CRM master data.' );

	$record_input = new \ReflectionMethod( \CB\CRM\Admin\Save::class, 'record_input' );
	$record_input->setAccessible( true );
	$_POST = [ 'cb_crm_details' => [ 'status' => 'active', 'wp_user_id' => '7', 'email_mode' => 'wp_user', 'job_title' => '', 'first_name' => 'Chris', 'first_name_override' => '0', 'name_prefix' => 'van', 'last_name' => 'Example', 'last_name_override' => '0' ] ];
	$input = $record_input->invoke( null, 'contact', 44 );
	$assert( '' === ( $input['first_name'] ?? null ) && '' === ( $input['last_name'] ?? null ), 'Displayed live names must not be persisted into CRM when no override is selected.' );
	$assert( 'van' === ( $input['name_prefix'] ?? null ), 'CRM-owned name prefix must persist independently from source-aware first/last-name overrides.' );
	$_POST['cb_crm_details']['first_name'] = 'CRM Chris';
	$_POST['cb_crm_details']['first_name_override'] = '1';
	$input = $record_input->invoke( null, 'contact', 44 );
	$assert( 'CRM Chris' === ( $input['first_name'] ?? null ), 'Checked CRM name override must be persisted explicitly.' );
	$_POST['cb_crm_details']['wp_user_id'] = '8';
	$input = $record_input->invoke( null, 'contact', 44 );
	$assert( ! array_key_exists( 'first_name', $input ) && ! array_key_exists( 'last_name', $input ), 'Source-aware name writes must fail closed when the linked WordPress identity changes in the same request.' );
	$assert( 'van' === ( $input['name_prefix'] ?? null ), 'CRM-owned name prefix must remain writable during a linked-identity change.' );

	$_POST['cb_crm_details']['wp_user_id'] = '7';
	$meta[44]['_cb_crm_first_name'] = '';
	$user_meta[7]['first_name'] = 'Chris';
	\CB\CRM\Repository\Activity::$events = [];
	$update_name = new \ReflectionMethod( \CB\CRM\Application\RecordUpdater::class, 'update_contact_name' );
	$update_name->setAccessible( true );
	$failures = [];
	$args = [ 44, 'first_name', '_cb_crm_first_name', [ 'first_name' => 'CRM Chris' ], &$failures ];
	$changed = $update_name->invokeArgs( null, $args );
	$event = \CB\CRM\Repository\Activity::$events[0] ?? [];
	$context = $event[8] ?? [];
	$assert( true === $changed && 'CRM Chris' === $meta[44]['_cb_crm_first_name'], 'Explicit CRM override must update only CRM-owned name data.' );
	$assert( 'contact_field_override' === ( $event[2] ?? '' ) && 'Chris' === ( $context['wordpress'] ?? '' ) && 'Christopher' === ( $context['woocommerce'] ?? '' ), 'Override Activity must snapshot both connected source values at write time.' );
	$failures = [];
	$args = [ 44, 'first_name', '_cb_crm_first_name', [ 'first_name' => '' ], &$failures ];
	$update_name->invokeArgs( null, $args );
	$event = \CB\CRM\Repository\Activity::$events[1] ?? [];
	$context = $event[8] ?? [];
	$assert( 'removed' === ( $context['state'] ?? '' ) && '' === $meta[44]['_cb_crm_first_name'], 'Removing an override must be auditable and restore live source resolution.' );

	$provisioner_source = (string) file_get_contents( dirname( __DIR__ ) . '/src/Application/ContactProvisioner.php' );
	$save_source = (string) file_get_contents( dirname( __DIR__ ) . '/src/Admin/Save.php' );
	$frontend_source = (string) file_get_contents( dirname( __DIR__ ) . '/src/Frontend/Data/Contact.php' );
	$updater_source = (string) file_get_contents( dirname( __DIR__ ) . '/src/Application/RecordUpdater.php' );
	$details_source = (string) file_get_contents( dirname( __DIR__ ) . '/src/Admin/Panels/DetailsPanel.php' );
	$activity_panel_source = (string) file_get_contents( dirname( __DIR__ ) . '/src/Admin/Panels/NotesActivityPanel.php' );
	$assert( ! str_contains( $provisioner_source, "'first_name' =>" ) && ! str_contains( $provisioner_source, "'last_name'  =>" ), 'Provisioning must link the WordPress identity without copying profile names into CRM master data.' );
	$assert( str_contains( $save_source, '$override_key = $field . \'_override\'' ) && str_contains( $save_source, '$identity_switch' ), 'CRM name overrides must be explicit and fail closed during identity switches.' );
	$assert( str_contains( $frontend_source, 'ContactDataSources::name_field' ) && str_contains( $frontend_source, 'ContactDataSources::name_prefix' ) && str_contains( $frontend_source, 'ContactDataSources::effective_phone' ), 'CRM public Contact projection must combine source-aware first/last names with the canonical CRM-owned name prefix.' );
	$assert( str_contains( $updater_source, "'contact_field_override'" ) && str_contains( $updater_source, '$context[ $candidate[\'source\'] ]' ), 'Explicit CRM overrides must snapshot their provenance into Activity.' );
	$assert( str_contains( $details_source, '$candidate[\'source\'] === $resolved[\'source\']' ) && str_contains( $details_source, '$candidate[\'path\'] === $resolved[\'path\']' ), 'Source-aware name fields must retain the actually resolved external value instead of silently switching to a higher-priority different source in JavaScript.' );
	$assert( str_contains( $details_source, "'Name prefix'" ) && str_contains( $details_source, 'Meta::NAME_PREFIX' ), 'CRM Details must expose name prefix as a distinct CRM-owned identity field.' );
	$assert( str_contains( $activity_panel_source, "'contact_field_override'" ) && str_contains( $activity_panel_source, "'wordpress' => 'WordPress'" ) && str_contains( $activity_panel_source, "'woocommerce' => 'WooCommerce'" ), 'Override provenance must remain visible to operators in CRM Activity.' );

	exit( $failed ? 1 : 0 );
}

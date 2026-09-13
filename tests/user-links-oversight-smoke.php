<?php
declare(strict_types=1);

$root = dirname( __DIR__ );
$failed = false;

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', $root . '/' );
}
if ( ! function_exists( 'absint' ) ) {
	function absint( mixed $value ): int {
		return abs( (int) $value );
	}
}

require_once $root . '/src/Admin/UserLinkDirectory.php';

$fail = static function ( string $message ) use ( &$failed ): void {
	fwrite( STDERR, $message . "\n" );
	$failed = true;
};

$class = \CB\CRM\Admin\UserLinkDirectory::class;
if ( 'unlinked' !== $class::classify( [] ) ) {
	$fail( 'Zero CRM contacts must classify as unlinked.' );
}
if ( 'linked' !== $class::classify( [ 12 ] ) ) {
	$fail( 'One CRM contact must classify as linked.' );
}
if ( 'linked' !== $class::classify( [ 12, 12, 0 ] ) ) {
	$fail( 'Duplicate/zero IDs must not create a false conflict.' );
}
if ( 'conflict' !== $class::classify( [ 12, 14 ] ) ) {
	$fail( 'Multiple CRM contacts must classify as conflict.' );
}

$user_links   = file_get_contents( $root . '/src/Admin/UserLinks.php' );
$actions      = file_get_contents( $root . '/src/Admin/UserLinkActions.php' );
$directory    = file_get_contents( $root . '/src/Admin/UserLinkDirectory.php' );
$contact_list = file_get_contents( $root . '/src/Admin/ContactList.php' );
$menu         = file_get_contents( $root . '/src/Admin/Menu.php' );
$admin        = file_get_contents( $root . '/src/Admin/Admin.php' );
$assets       = file_get_contents( $root . '/src/Admin/Assets.php' );
$styles       = file_get_contents( $root . '/assets/admin.css' );
$bootstrap    = file_get_contents( $root . '/core-blueprint-crm.php' );
$tools        = file_get_contents( $root . '/tools/check' );
$g4_source    = $user_links . "\n" . $actions . "\n" . $directory . "\n" . $contact_list;

$checks = [
	'G4 is wired through Admin init' => str_contains( $admin, 'UserLinks::init();' ),
	'G4 keeps page, mutation, directory and Contact-list responsibilities separate' => str_contains( $user_links, 'UserLinkActions::init();' ) && ! str_contains( $user_links, "'admin_post_'" ) && str_contains( $actions, "'admin_post_'" ) && ! str_contains( $user_links, "'pre_user_query'" ) && ! str_contains( $user_links, "manage_' . PostTypes::CONTACT" ),
	'CRM menu exposes the user oversight page' => str_contains( $menu, 'UserLinks::PAGE_SLUG' ) && str_contains( $menu, "'WordPress account'" ) && str_contains( $menu, "__( 'Users' )" ),
	'oversight is capability-gated' => str_contains( $user_links, 'current_user_can( Capabilities::MANAGE )' ) && str_contains( $actions, 'current_user_can( Capabilities::MANAGE )' ),
	'mutations are POST-only' => str_contains( $actions, "\$_SERVER['REQUEST_METHOD']" ) && str_contains( $actions, "'POST' !== \$method" ),
	'mutations use action-specific nonces' => str_contains( $actions, 'check_admin_referer( self::nonce_action(' ) && str_contains( $user_links, 'UserLinkActions::nonce_action(' ),
	'WordPress users are paginated through WP_User_Query' => str_contains( $user_links, 'new \\WP_User_Query' ) && str_contains( $user_links, "'number'  => self::PER_PAGE" ),
	'link-status filtering stays server-side and scalable' => str_contains( $directory, "'pre_user_query'" ) && str_contains( $directory, 'cb_crm_link_state' ) && str_contains( $directory, 'cb_crm_link_status' ),
	'pagination uses an encoding-safe numeric sentinel' => str_contains( $user_links, '$sentinel = 999999999;' ) && str_contains( $user_links, "'%#%'" ),
	'link state is projected from canonical CRM contact metadata' => str_contains( $directory, 'Meta::WP_USER_ID' ) && str_contains( $directory, 'ContactIdentity::contact_ids_for_user' ),
	'create mutation delegates to canonical ContactProvisioner' => str_contains( $actions, 'ContactProvisioner::ensure_for_user' ) && ! str_contains( $actions, "'first_name'" ) && ! str_contains( $actions, "'last_name'" ) && ! str_contains( $actions, 'wp_insert_post(' ),
	'existing-contact linking reuses RecordUpdater' => str_contains( $actions, 'RecordUpdater::update_contact' ),
	'existing-contact linking refuses to steal a contact from another user' => str_contains( $actions, '$existing_user_id > 0 && $existing_user_id !== $user_id' ),
	'contact list exposes reverse WordPress-account column and filter' => str_contains( $contact_list, "manage_' . PostTypes::CONTACT . '_posts_columns" ) && str_contains( $contact_list, "'restrict_manage_posts'" ) && str_contains( $contact_list, "'pre_get_posts'" ),
	'Users Screen Options use the native WordPress hidden-column contract' => str_contains( $menu, 'UserLinks::register_screen( $users_hook );' ) && str_contains( $user_links, "'manage_' . \$screen_id . '_columns'" ) && str_contains( $user_links, 'get_hidden_columns( $screen )' ),
	'Users table exposes stable WordPress column classes' => str_contains( $user_links, "'name'    => __( 'Name'" ) && str_contains( $user_links, "'email'   => __( 'Email'" ) && str_contains( $user_links, 'column_classes( $column_key, $hidden_columns, true )' ) && str_contains( $user_links, "column_classes( 'actions', \$hidden_columns )" ),
	'Users-specific CRM CSS is loaded on the Users screen' => str_contains( $assets, 'Menu::CONTEXT_USERS' ) && str_contains( $assets, "wp_enqueue_style( 'cb-crm-admin'" ),
	'long user identities prefer natural table sizing before fallback wrapping' => str_contains( $styles, '.cb-crm-user-links-page .wp-list-table' ) && str_contains( $styles, 'table-layout: auto;' ) && str_contains( $styles, '.cb-crm-user-login' ) && str_contains( $styles, 'overflow-wrap: anywhere;' ) && str_contains( $styles, 'word-break: normal;' ),
	'G4 creates no parallel identity storage' => ! str_contains( $g4_source, 'register_post_meta' ) && ! str_contains( $g4_source, 'CREATE TABLE' ),
	'G4 does not write the canonical link meta directly' => ! preg_match( '/update_post_meta\s*\([^;]*WP_USER_ID/s', $g4_source ),
	'G4 does not change the Golden schema or plugin candidate version' => str_contains( $bootstrap, "CB_CRM_SCHEMA_VERSION', '1.5'" ) && str_contains( $bootstrap, "CB_CRM_VERSION', '1.0.0-rc1'" ),
	'permanent G4 smoke is wired into tools/check' => str_contains( $tools, 'user-links-oversight-smoke.php' ),
];

foreach ( $checks as $label => $passed ) {
	if ( ! $passed ) {
		$fail( 'User-link oversight conformance failed: ' . $label );
	}
}

exit( $failed ? 1 : 0 );

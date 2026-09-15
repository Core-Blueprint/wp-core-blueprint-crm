<?php
declare(strict_types=1);

$root = dirname( __DIR__ );
$api = file_get_contents( $root . '/src/PublicApi/Contacts.php' );
$sources = file_get_contents( $root . '/src/Integration/ContactDataSources.php' );
$failed = false;

$assert = static function ( bool $condition, string $message ) use ( &$failed ): void {
	if ( ! $condition ) {
		fwrite( STDERR, $message . "\n" );
		$failed = true;
	}
};

$assert( is_string( $api ), 'Public Contact API source must exist.' );
$assert( is_string( $sources ), 'Contact source resolver must exist.' );

if ( is_string( $api ) ) {
	$assert(
		str_contains( $api, 'namespace CB\\CRM\\PublicApi;' )
			&& str_contains( $api, 'final class Contacts' )
			&& str_contains( $api, 'public static function get(' )
			&& str_contains( $api, 'public static function query(' ),
		'CRM must expose the canonical read-only server-side Contacts contract.'
	);
	$assert(
		! str_contains( $api, 'current_user_can(' )
			&& ! str_contains( $api, 'get_current_user_id(' )
			&& ! str_contains( $api, 'Frontend\\Access' ),
		'Background consumer reads must not depend on an authenticated WordPress user context.'
	);
	$assert(
		str_contains( $api, "function_exists( 'cb_crm_runtime_ready' )" )
			&& str_contains( $api, '! \\cb_crm_runtime_ready()' )
			&& str_contains( $api, "'items'       => []" )
			&& str_contains( $api, "'total'       => 0" ),
		'Background Contact reads must fail closed without current CRM runtime readiness.'
	);
	$assert(
		! str_contains( $api, 'global $wpdb' )
			&& ! str_contains( $api, 'Database\\Schema' )
			&& ! str_contains( $api, 'WC_Customer' )
			&& ! str_contains( $api, 'WC_Order' ),
		'Public Contact projections must not expose or couple to storage/provider internals.'
	);
	$assert(
		str_contains( $api, 'ContactDataSources::effective_email_field' )
			&& str_contains( $api, "'email_source'")
			&& str_contains( $api, "'email_path'"),
		'Public Contact projections must preserve effective email provenance.'
	);
	$assert(
		str_contains( $api, "'tag'")
			&& str_contains( $api, "'organization_id'")
			&& str_contains( $api, "'status'"),
		'Public Contact queries must retain the minimal generic audience filters.'
	);
	$assert(
		str_contains( $api, "[ 'publish', 'draft', 'pending', 'private', 'future' ]" )
			&& str_contains( $api, "[ 'auto-draft', 'trash' ]" ),
		'Public Contact reads must exclude trashed and auto-draft records.'
	);
	$assert(
		str_contains( $api, 'Marketing') && str_contains( $api, 'permission'),
		'CRM Public API must document that marketing permission remains consumer-owned.'
	);
}

if ( is_string( $sources ) ) {
	$assert(
		str_contains( $sources, 'public static function effective_email_field(' )
			&& str_contains( $sources, "'source' => 'wordpress'")
			&& str_contains( $sources, "'source' => 'woocommerce'")
			&& str_contains( $sources, "'source' => 'crm'"),
		'Effective email provenance must distinguish WordPress, WooCommerce and CRM sources.'
	);
	$assert(
		str_contains( $sources, 'self::effective_email( $contact_id )' ),
		'Email provenance must annotate the existing effective email resolver rather than replace its selection policy.'
	);
}

exit( $failed ? 1 : 0 );

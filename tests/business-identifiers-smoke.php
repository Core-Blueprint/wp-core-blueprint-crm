<?php
declare(strict_types=1);

namespace {
	define( 'ABSPATH', __DIR__ . '/' );
	$GLOBALS['cb_crm_business_identifier_meta'] = [];

	function sanitize_text_field( mixed $value ): string {
		return trim( strip_tags( (string) $value ) );
	}

	function get_post_type( int $post_id ): string {
		return 42 === $post_id ? 'cb_crm_org' : '';
	}

	function get_post_meta( int $post_id, string $key, bool $single = false ): mixed {
		unset( $single );
		return $GLOBALS['cb_crm_business_identifier_meta'][ $post_id ][ $key ] ?? '';
	}

	function update_post_meta( int $post_id, string $key, mixed $value ): int|bool {
		$GLOBALS['cb_crm_business_identifier_meta'][ $post_id ][ $key ] = $value;
		return 1;
	}

	function delete_post_meta( int $post_id, string $key ): bool {
		unset( $GLOBALS['cb_crm_business_identifier_meta'][ $post_id ][ $key ] );
		return true;
	}
}

namespace CB\CRM\Content {
	final class Meta {
		public const BUSINESS_IDENTIFIERS = '_cb_crm_business_identifiers';
	}
	final class PostTypes {
		public const ORGANIZATION = 'cb_crm_org';
	}
}

namespace {
	require_once dirname( __DIR__ ) . '/src/Repository/BusinessIdentifiers.php';

	use CB\CRM\Repository\BusinessIdentifiers;

	$assert = static function ( bool $condition, string $message ): void {
		if ( ! $condition ) {
			fwrite( STDERR, "FAIL: {$message}\n" );
			exit( 1 );
		}
	};

	$normalized = BusinessIdentifiers::normalize( [
		[ 'key' => 'VAT ID', 'value' => ' NL123456789B01 ' ],
		[ 'key' => 'registration_number', 'value' => '12345678' ],
	] );
	$assert( is_array( $normalized ), 'Valid identifier rows must normalize.' );
	$assert( [ 'registration_number', 'vat_id' ] === array_keys( $normalized ), 'Identifier keys must be canonical and deterministically sorted.' );
	$assert( 'NL123456789B01' === $normalized['vat_id'], 'Identifier values must be trimmed.' );

	$map = BusinessIdentifiers::normalize( [
		'eori' => 'NL123456789',
		'vat' => 'NL123456789B01',
	] );
	$assert( [ 'eori', 'vat' ] === array_keys( $map ?? [] ), 'Associative maps must be accepted and sorted.' );

	$assert( null === BusinessIdentifiers::normalize( [ [ 'key' => 'vat', 'value' => 'A' ], [ 'key' => 'VAT', 'value' => 'B' ] ] ), 'Canonical duplicate keys must fail closed.' );
	$assert( null === BusinessIdentifiers::normalize( [ [ 'key' => 'vat', 'value' => '' ] ] ), 'Partial rows must fail closed.' );
	$assert( null === BusinessIdentifiers::normalize( [ 'bad key!' => 'x' ] ), 'Invalid identifier keys must fail closed.' );

	$too_many = [];
	for ( $i = 0; $i < 33; ++$i ) {
		$too_many[ 'id_' . $i ] = 'value-' . $i;
	}
	$assert( null === BusinessIdentifiers::normalize( $too_many ), 'Identifier maps must be bounded.' );

	$assert( BusinessIdentifiers::replace( 42, [ [ 'key' => 'vat', 'value' => 'NL123456789B01' ] ] ), 'Organization identifiers must persist.' );
	$assert( [ 'vat' => 'NL123456789B01' ] === BusinessIdentifiers::for_organization( 42 ), 'Stored identifiers must round-trip canonically.' );
	$assert( BusinessIdentifiers::replace( 42, [] ), 'Identifier maps must support explicit clearing.' );
	$assert( [] === BusinessIdentifiers::for_organization( 42 ), 'Clearing identifiers must remove stored values.' );
	$assert( ! BusinessIdentifiers::replace( 99, [ 'vat' => 'NL123' ] ), 'Non-organization records must fail closed.' );

	$root = dirname( __DIR__ );
	$organization = file_get_contents( $root . '/src/Frontend/Data/Organization.php' );
	$updater = file_get_contents( $root . '/src/Application/RecordUpdater.php' );
	$panel = file_get_contents( $root . '/src/Admin/BusinessIdentifiersPanel.php' );
	$assert( is_string( $organization ) && str_contains( $organization, "'business_identifiers'" ), 'Public Organization projection must expose business_identifiers.' );
	$assert( is_string( $updater ) && str_contains( $updater, "replace_area( 'business_identifiers'" ), 'Organization update boundary must own business identifier writes.' );
	$assert( is_string( $panel ) && str_contains( $panel, 'data-cb-crm-repeater' ), 'Organization admin must expose the reusable identifier editor through the existing repeater contract.' );

	echo "PASS: CRM organization business identifiers smoke\n";
}

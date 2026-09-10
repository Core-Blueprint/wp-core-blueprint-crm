<?php
declare(strict_types=1);

define( 'ABSPATH', '/tmp/wp/' );
define( 'ARRAY_A', 'ARRAY_A' );

function sanitize_key( string $value ): string {
	return strtolower( preg_replace( '/[^a-z0-9_\-]/', '', $value ) ?? '' );
}

function sanitize_text_field( string $value ): string {
	return trim( strip_tags( $value ) );
}

function current_time( string $format, bool $gmt = false ): string {
	unset( $format, $gmt );
	return '2026-09-10 20:00:00';
}

function get_post_type( int $post_id ): string|false {
	return 42 === $post_id ? 'cb_crm_org' : false;
}

final class CRM_G1_WPDB {
	public string $prefix = 'wp_';
	/** @var array<int,array<string,mixed>> */
	public array $rows = [];
	public int $insert_calls = 0;
	public ?int $fail_insert_on = null;
	private int $next_id = 1;
	/** @var array{rows:array<int,array<string,mixed>>,next_id:int}|null */
	private ?array $snapshot = null;

	public function prepare( string $query, mixed ...$args ): string {
		return sprintf( $query, ...array_map( static fn( mixed $value ): int => (int) $value, $args ) );
	}

	/** @return array<int,array<string,mixed>> */
	public function get_results( string $query, mixed $output = null ): array {
		unset( $output );
		if ( 1 !== preg_match( '/organization_id = (\d+)/', $query, $match ) ) {
			return [];
		}
		$organization_id = (int) $match[1];
		$rows = array_values( array_filter( $this->rows, static fn( array $row ): bool => $organization_id === (int) ( $row['organization_id'] ?? 0 ) ) );
		usort( $rows, static fn( array $a, array $b ): int => [ (int) $a['sort_order'], (int) $a['id'] ] <=> [ (int) $b['sort_order'], (int) $b['id'] ] );
		return $rows;
	}

	public function query( string $sql ): int|false {
		if ( 'START TRANSACTION' === $sql ) {
			$this->snapshot = [ 'rows' => $this->rows, 'next_id' => $this->next_id ];
			return 1;
		}
		if ( 'ROLLBACK' === $sql ) {
			if ( null !== $this->snapshot ) {
				$this->rows = $this->snapshot['rows'];
				$this->next_id = $this->snapshot['next_id'];
			}
			$this->snapshot = null;
			return 1;
		}
		if ( 'COMMIT' === $sql ) {
			$this->snapshot = null;
			return 1;
		}
		return 0;
	}

	/** @param array<string,mixed> $where */
	public function delete( string $table, array $where, array $format = [] ): int|false {
		unset( $format );
		if ( $this->prefix . 'cb_crm_business_identifiers' !== $table ) {
			return false;
		}
		$organization_id = (int) ( $where['organization_id'] ?? 0 );
		$before = count( $this->rows );
		$this->rows = array_values( array_filter( $this->rows, static fn( array $row ): bool => $organization_id !== (int) ( $row['organization_id'] ?? 0 ) ) );
		return $before - count( $this->rows );
	}

	/** @param array<string,mixed> $data */
	public function insert( string $table, array $data, array $format = [] ): int|false {
		unset( $format );
		if ( $this->prefix . 'cb_crm_business_identifiers' !== $table ) {
			return false;
		}
		++$this->insert_calls;
		if ( null !== $this->fail_insert_on && $this->insert_calls === $this->fail_insert_on ) {
			return false;
		}
		$data['id'] = $this->next_id++;
		$this->rows[] = $data;
		return 1;
	}
}

$wpdb = new CRM_G1_WPDB();

require dirname( __DIR__ ) . '/src/Content/PostTypes.php';
require dirname( __DIR__ ) . '/src/Content/Entity.php';
require dirname( __DIR__ ) . '/src/Database/Schema.php';
require dirname( __DIR__ ) . '/src/Repository/BusinessIdentifiers.php';
require dirname( __DIR__ ) . '/src/Frontend/Data/Organization.php';

use CB\CRM\Frontend\Data\Organization;
use CB\CRM\Repository\BusinessIdentifiers;

$assert = static function ( bool $condition, string $message ): void {
	if ( ! $condition ) {
		fwrite( STDERR, "FAIL: {$message}\n" );
		exit( 1 );
	}
};

$rows = [
	[ 'identifier_type' => 'vat', 'value' => ' NL111 ', 'country' => 'nl' ],
	[ 'identifier_type' => 'vat', 'value' => 'DE999', 'country' => 'DE', 'is_primary' => 1 ],
	[ 'identifier_type' => 'vat', 'value' => 'de999', 'country' => 'de', 'is_primary' => 1 ],
	[ 'identifier_type' => 'registration_number', 'value' => '12345678', 'country' => 'NL', 'is_primary' => 1 ],
	[ 'identifier_type' => 'registration_number', 'value' => '87654321', 'country' => 'BE', 'is_primary' => 1 ],
	[ 'identifier_type' => 'eori', 'value' => 'NL-EORI-1', 'country' => 'NL' ],
	[ 'identifier_type' => 'other', 'value' => 'LEI-123', 'country' => 'nl', 'label' => 'LEI', 'is_primary' => 1 ],
	[ 'identifier_type' => 'other', 'value' => 'NO-LABEL', 'country' => 'NL' ],
	[ 'identifier_type' => 'unsupported', 'value' => 'DROP-ME', 'country' => 'NL' ],
	[ 'identifier_type' => 'vat', 'value' => 'GB123', 'country' => 'NLD' ],
];

$assert( BusinessIdentifiers::replace( 42, $rows ), 'Valid organization identifiers must persist.' );
$stored = BusinessIdentifiers::for_organization( 42 );
$assert( 7 === count( $stored ), 'Normalization must drop duplicates, unlabeled custom rows and unsupported types.' );
$assert( 'NL111' === $stored[0]['value'] && 'NL' === $stored[0]['country'], 'Values and country codes must normalize deterministically.' );
$assert( '' === $stored[6]['country'], 'Invalid three-letter country input must not be truncated into a false country code.' );

$vat_primary = array_values( array_filter( $stored, static fn( array $row ): bool => 'vat' === $row['identifier_type'] && ! empty( $row['is_primary'] ) ) );
$registration_primary = array_values( array_filter( $stored, static fn( array $row ): bool => 'registration_number' === $row['identifier_type'] && ! empty( $row['is_primary'] ) ) );
$assert( 1 === count( $vat_primary ) && 'DE999' === $vat_primary[0]['value'], 'Only the first explicit VAT primary may remain primary.' );
$assert( 1 === count( $registration_primary ) && '12345678' === $registration_primary[0]['value'], 'Only one primary may exist per identifier type.' );

$map = BusinessIdentifiers::primary_map( 42 );
$assert( [ 'vat' => 'DE999', 'registration_number' => '12345678', 'eori' => 'NL-EORI-1' ] === $map, 'Canonical map must prefer explicit primaries and fall back deterministically.' );
$assert( ! array_key_exists( 'other', $map ), 'Custom identifiers must not leak into the canonical IQ-compatible map.' );

$before_rollback = $wpdb->rows;
$wpdb->fail_insert_on = $wpdb->insert_calls + 2;
$assert( ! BusinessIdentifiers::replace( 42, [ [ 'identifier_type' => 'vat', 'value' => 'NEW-1' ], [ 'identifier_type' => 'eori', 'value' => 'NEW-2' ] ] ), 'Insert failure must fail the replace operation.' );
$assert( $before_rollback === $wpdb->rows, 'Insert failure must roll back the destructive replace.' );
$wpdb->fail_insert_on = null;

$assert( ! BusinessIdentifiers::replace( 99, [ [ 'identifier_type' => 'vat', 'value' => 'INVALID-OWNER' ] ] ), 'Identifiers must reject non-organization owners.' );
$assert( in_array( 'business_identifiers', Organization::fields(), true ), 'Organization public contract must expose the canonical identifier map.' );
$assert( in_array( 'business_identifier_records', Organization::fields(), true ), 'Organization public contract must expose full identifier records.' );

$root = dirname( __DIR__ );
$schema = file_get_contents( $root . '/src/Database/Schema.php' );
$lifecycle = file_get_contents( $root . '/src/Lifecycle.php' );
$updater = file_get_contents( $root . '/src/Application/RecordUpdater.php' );
$save = file_get_contents( $root . '/src/Admin/Save.php' );
$panel = file_get_contents( $root . '/src/Admin/BusinessIdentifiersPanel.php' );
$assert( is_string( $schema ) && str_contains( $schema, 'cb_crm_business_identifiers' ), 'Business identifier table must be declared by CRM schema.' );
$assert( is_string( $lifecycle ) && str_contains( $lifecycle, 'business_identifiers_table()' ), 'Organization deletion must own identifier cleanup.' );
$assert( is_string( $updater ) && str_contains( $updater, "replace_area( 'business_identifiers'" ), 'Organization updater must persist identifiers through the shared update pipeline.' );
$assert( is_string( $save ) && str_contains( $save, 'cb_crm_business_identifiers_present' ), 'Admin save boundary must only replace identifiers when their panel submitted.' );
$assert( is_string( $panel ) && str_contains( $panel, "'post_types' => [ Entity::ORGANIZATION ]" ), 'Business identifiers UI must be organization-only.' );

echo "CRM business identifiers smoke passed.\n";

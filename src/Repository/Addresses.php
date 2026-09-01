<?php
declare(strict_types=1);
namespace CB\CRM\Repository;
use CB\CRM\Content\Entity;
use CB\CRM\Database\Schema;
defined( 'ABSPATH' ) || exit;

final class Addresses {
	/** @return array<int,array<string,mixed>> */
	public static function for_owner( string $owner_type, int $owner_id ): array {
		global $wpdb; $rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . Schema::addresses_table() . ' WHERE owner_type = %s AND owner_id = %d ORDER BY is_primary DESC, sort_order ASC, id ASC', $owner_type, $owner_id ), ARRAY_A );
		return is_array( $rows ) ? $rows : [];
	}
	/** @param array<int,array<string,mixed>> $rows */
	public static function replace( string $owner_type, int $owner_id, array $rows ): bool {
		global $wpdb; if ( ! Entity::valid_owner( $owner_type, $owner_id, false ) ) { return false; }
		$normalized = []; $primary_seen = false;
		foreach ( array_slice( $rows, 0, 30 ) as $index => $row ) {
			if ( ! is_array( $row ) ) { continue; }
			$line1 = sanitize_text_field( (string) ( $row['address_line_1'] ?? '' ) ); $city = sanitize_text_field( (string) ( $row['city'] ?? '' ) ); $postal = sanitize_text_field( (string) ( $row['postal_code'] ?? '' ) );
			if ( '' === $line1 && '' === $city && '' === $postal ) { continue; }
			$is_primary = ! empty( $row['is_primary'] ) && ! $primary_seen; if ( $is_primary ) { $primary_seen = true; }
			$country = strtoupper( preg_replace( '/[^A-Za-z]/', '', (string) ( $row['country'] ?? '' ) ) );
			$normalized[] = [ 'label' => sanitize_text_field( (string) ( $row['label'] ?? '' ) ), 'address_line_1' => $line1, 'address_line_2' => sanitize_text_field( (string) ( $row['address_line_2'] ?? '' ) ), 'postal_code' => $postal, 'city' => $city, 'region' => sanitize_text_field( (string) ( $row['region'] ?? '' ) ), 'country' => substr( $country, 0, 2 ), 'is_primary' => $is_primary ? 1 : 0, 'sort_order' => (int) $index ];
		}
		$now = current_time( 'mysql', true ); $wpdb->query( 'START TRANSACTION' );
		if ( false === $wpdb->delete( Schema::addresses_table(), [ 'owner_type' => $owner_type, 'owner_id' => $owner_id ], [ '%s','%d' ] ) ) { $wpdb->query( 'ROLLBACK' ); return false; }
		foreach ( $normalized as $row ) {
			if ( false === $wpdb->insert( Schema::addresses_table(), [ 'owner_type' => $owner_type, 'owner_id' => $owner_id, 'label' => $row['label'], 'address_line_1' => $row['address_line_1'], 'address_line_2' => $row['address_line_2'], 'postal_code' => $row['postal_code'], 'city' => $row['city'], 'region' => $row['region'], 'country' => $row['country'], 'is_primary' => $row['is_primary'], 'sort_order' => $row['sort_order'], 'created_at' => $now, 'updated_at' => $now ], [ '%s','%d','%s','%s','%s','%s','%s','%s','%s','%d','%d','%s','%s' ] ) ) { $wpdb->query( 'ROLLBACK' ); return false; }
		}
		$wpdb->query( 'COMMIT' ); return true;
	}
}

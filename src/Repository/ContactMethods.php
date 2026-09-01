<?php
declare(strict_types=1);
namespace CB\CRM\Repository;
use CB\CRM\Content\Entity;
use CB\CRM\Database\Schema;
defined( 'ABSPATH' ) || exit;

final class ContactMethods {
	public const TYPES = [ 'email', 'phone', 'mobile', 'website', 'whatsapp', 'other' ];
	/** @return array<int,array<string,mixed>> */
	public static function for_owner( string $owner_type, int $owner_id ): array {
		global $wpdb; $rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . Schema::contact_methods_table() . ' WHERE owner_type = %s AND owner_id = %d ORDER BY sort_order ASC, id ASC', $owner_type, $owner_id ), ARRAY_A );
		return is_array( $rows ) ? $rows : [];
	}
	/** @return array<string,mixed>|null */
	public static function primary( string $owner_type, int $owner_id, string $method_type ): ?array {
		global $wpdb; $row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Schema::contact_methods_table() . ' WHERE owner_type = %s AND owner_id = %d AND method_type = %s AND is_primary = 1 ORDER BY id ASC LIMIT 1', $owner_type, $owner_id, sanitize_key( $method_type ) ), ARRAY_A );
		return is_array( $row ) ? $row : null;
	}
	/** @param array<int,array<string,mixed>> $rows */
	public static function replace( string $owner_type, int $owner_id, array $rows ): bool {
		global $wpdb; if ( ! Entity::valid_owner( $owner_type, $owner_id, false ) ) { return false; }
		$normalized = []; $primary_seen = [];
		foreach ( array_slice( $rows, 0, 50 ) as $index => $row ) {
			if ( ! is_array( $row ) ) { continue; }
			$type = sanitize_key( (string) ( $row['method_type'] ?? '' ) ); $label = sanitize_text_field( (string) ( $row['label'] ?? '' ) ); $value = self::sanitize_value( $type, (string) ( $row['value'] ?? '' ) );
			if ( ! in_array( $type, self::TYPES, true ) || '' === $value ) { continue; }
			$is_primary = ! empty( $row['is_primary'] ) && empty( $primary_seen[ $type ] ); if ( $is_primary ) { $primary_seen[ $type ] = true; }
			$normalized[] = [ 'method_type' => $type, 'label' => $label, 'value' => $value, 'is_primary' => $is_primary ? 1 : 0, 'sort_order' => (int) $index ];
		}
		$now = current_time( 'mysql', true ); $wpdb->query( 'START TRANSACTION' );
		if ( false === $wpdb->delete( Schema::contact_methods_table(), [ 'owner_type' => $owner_type, 'owner_id' => $owner_id ], [ '%s', '%d' ] ) ) { $wpdb->query( 'ROLLBACK' ); return false; }
		foreach ( $normalized as $row ) {
			if ( false === $wpdb->insert( Schema::contact_methods_table(), [ 'owner_type' => $owner_type, 'owner_id' => $owner_id, 'method_type' => $row['method_type'], 'label' => $row['label'], 'value' => $row['value'], 'is_primary' => $row['is_primary'], 'source' => 'crm', 'sort_order' => $row['sort_order'], 'created_at' => $now, 'updated_at' => $now ], [ '%s','%d','%s','%s','%s','%d','%s','%d','%s','%s' ] ) ) { $wpdb->query( 'ROLLBACK' ); return false; }
		}
		$wpdb->query( 'COMMIT' ); return true;
	}
	private static function sanitize_value( string $type, string $value ): string {
		$value = trim( $value ); return match ( $type ) { 'email' => is_email( $value ) ? sanitize_email( $value ) : '', 'website' => esc_url_raw( $value ), default => sanitize_text_field( $value ) };
	}
}

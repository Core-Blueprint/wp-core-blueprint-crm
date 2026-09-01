<?php
declare(strict_types=1);
namespace CB\CRM\Repository;
use CB\CRM\Content\Entity;
use CB\CRM\Database\Schema;
defined( 'ABSPATH' ) || exit;

final class Names {
	public const TYPES = [ 'legal', 'preferred', 'trading', 'alias', 'former' ];
	/** @return array<int,array<string,mixed>> */
	public static function for_owner( string $owner_type, int $owner_id ): array {
		global $wpdb; $rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . Schema::names_table() . ' WHERE owner_type = %s AND owner_id = %d ORDER BY sort_order ASC, id ASC', $owner_type, $owner_id ), ARRAY_A );
		return is_array( $rows ) ? $rows : [];
	}
	/** @param array<int,array<string,mixed>> $rows */
	public static function replace( string $owner_type, int $owner_id, array $rows ): bool {
		global $wpdb; if ( ! Entity::valid_owner( $owner_type, $owner_id ) ) { return false; }
		$normalized = [];
		foreach ( array_slice( $rows, 0, 30 ) as $index => $row ) {
			if ( ! is_array( $row ) ) { continue; }
			$name = sanitize_text_field( trim( (string) ( $row['name'] ?? '' ) ) ); $type = sanitize_key( (string) ( $row['name_type'] ?? 'alias' ) );
			if ( '' === $name ) { continue; } if ( ! in_array( $type, self::TYPES, true ) ) { $type = 'alias'; }
			$normalized[] = [ 'name' => $name, 'name_type' => $type, 'started_at' => self::date( (string) ( $row['started_at'] ?? '' ) ), 'ended_at' => self::date( (string) ( $row['ended_at'] ?? '' ) ), 'sort_order' => (int) $index ];
		}
		$now = current_time( 'mysql', true ); $wpdb->query( 'START TRANSACTION' );
		if ( false === $wpdb->delete( Schema::names_table(), [ 'owner_type' => $owner_type, 'owner_id' => $owner_id ], [ '%s','%d' ] ) ) { $wpdb->query( 'ROLLBACK' ); return false; }
		foreach ( $normalized as $row ) {
			if ( false === $wpdb->insert( Schema::names_table(), [ 'owner_type' => $owner_type, 'owner_id' => $owner_id, 'name' => $row['name'], 'name_type' => $row['name_type'], 'is_primary' => 0, 'started_at' => $row['started_at'], 'ended_at' => $row['ended_at'], 'sort_order' => $row['sort_order'], 'created_at' => $now, 'updated_at' => $now ], [ '%s','%d','%s','%s','%d','%s','%s','%d','%s','%s' ] ) ) { $wpdb->query( 'ROLLBACK' ); return false; }
		}
		$wpdb->query( 'COMMIT' ); return true;
	}
	private static function date( string $value ): ?string { $value = trim( $value ); if ( '' === $value ) { return null; } $d = \DateTimeImmutable::createFromFormat( '!Y-m-d', $value ); return $d && $d->format( 'Y-m-d' ) === $value ? $value : null; }
}

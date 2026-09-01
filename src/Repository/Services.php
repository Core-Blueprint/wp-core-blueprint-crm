<?php
declare(strict_types=1);
namespace CB\CRM\Repository;
use CB\CRM\Content\Entity;
use CB\CRM\Content\PostTypes;
use CB\CRM\Database\Schema;
defined( 'ABSPATH' ) || exit;

final class Services {
	public const STATUSES = [ 'active', 'paused', 'ended' ];
	/** @return array<int,array<string,mixed>> */
	public static function for_owner( string $owner_type, int $owner_id ): array { global $wpdb; $rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . Schema::service_assignments_table() . ' WHERE owner_type = %s AND owner_id = %d ORDER BY status = "active" DESC, started_at DESC, id DESC', $owner_type, $owner_id ), ARRAY_A ); return is_array( $rows ) ? $rows : []; }
	/** @param array<int,array<string,mixed>> $rows */
	public static function replace( string $owner_type, int $owner_id, array $rows ): bool {
		global $wpdb; if ( ! Entity::valid_owner( $owner_type, $owner_id, false ) ) { return false; }
		$normalized = [];
		foreach ( array_slice( $rows, 0, 50 ) as $row ) {
			if ( ! is_array( $row ) ) { continue; } $service_id = absint( $row['service_id'] ?? 0 ); if ( $service_id <= 0 || PostTypes::SERVICE !== get_post_type( $service_id ) ) { continue; }
			$status = sanitize_key( (string) ( $row['status'] ?? 'active' ) ); if ( ! in_array( $status, self::STATUSES, true ) ) { $status = 'active'; }
			$normalized[] = [ 'service_id' => $service_id, 'status' => $status, 'started_at' => self::date( (string) ( $row['started_at'] ?? '' ) ), 'ended_at' => self::date( (string) ( $row['ended_at'] ?? '' ) ), 'notes' => sanitize_textarea_field( (string) ( $row['notes'] ?? '' ) ) ];
		}
		$now = current_time( 'mysql', true ); $wpdb->query( 'START TRANSACTION' );
		if ( false === $wpdb->delete( Schema::service_assignments_table(), [ 'owner_type' => $owner_type, 'owner_id' => $owner_id ], [ '%s','%d' ] ) ) { $wpdb->query( 'ROLLBACK' ); return false; }
		foreach ( $normalized as $row ) { if ( false === $wpdb->insert( Schema::service_assignments_table(), [ 'service_id' => $row['service_id'], 'owner_type' => $owner_type, 'owner_id' => $owner_id, 'status' => $row['status'], 'started_at' => $row['started_at'], 'ended_at' => $row['ended_at'], 'notes' => $row['notes'], 'created_at' => $now, 'updated_at' => $now ], [ '%d','%s','%d','%s','%s','%s','%s','%s','%s' ] ) ) { $wpdb->query( 'ROLLBACK' ); return false; } }
		$wpdb->query( 'COMMIT' ); return true;
	}
	private static function date( string $value ): ?string { $value = trim( $value ); if ( '' === $value ) { return null; } $d = \DateTimeImmutable::createFromFormat( '!Y-m-d', $value ); return $d && $d->format( 'Y-m-d' ) === $value ? $value : null; }
}

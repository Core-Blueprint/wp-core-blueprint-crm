<?php
declare(strict_types=1);
namespace CB\CRM\Repository;
use CB\CRM\Content\PostTypes;
use CB\CRM\Database\Schema;
defined( 'ABSPATH' ) || exit;

final class Organizations {
	/** @return array<int,array<string,mixed>> */
	public static function for_contact( int $contact_id ): array { global $wpdb; $rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . Schema::organization_relations_table() . ' WHERE contact_id = %d ORDER BY is_primary DESC, ended_at IS NULL DESC, id ASC', $contact_id ), ARRAY_A ); return is_array( $rows ) ? $rows : []; }
	/** @return array<int,array<string,mixed>> */
	public static function contacts_for_organization( int $organization_id ): array { global $wpdb; $rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . Schema::organization_relations_table() . ' WHERE organization_id = %d ORDER BY is_primary DESC, ended_at IS NULL DESC, id ASC', $organization_id ), ARRAY_A ); return is_array( $rows ) ? $rows : []; }
	/** @param array<int,array<string,mixed>> $rows */
	public static function replace_for_contact( int $contact_id, array $rows ): bool {
		global $wpdb; if ( PostTypes::CONTACT !== get_post_type( $contact_id ) ) { return false; }
		$normalized = []; $primary_seen = false;
		foreach ( array_slice( $rows, 0, 30 ) as $row ) {
			if ( ! is_array( $row ) ) { continue; } $org_id = absint( $row['organization_id'] ?? 0 ); if ( $org_id <= 0 || PostTypes::ORGANIZATION !== get_post_type( $org_id ) ) { continue; }
			$is_primary = ! empty( $row['is_primary'] ) && ! $primary_seen; if ( $is_primary ) { $primary_seen = true; }
			$normalized[] = [ 'organization_id' => $org_id, 'role_title' => sanitize_text_field( (string) ( $row['role_title'] ?? '' ) ), 'is_primary' => $is_primary ? 1 : 0, 'started_at' => self::date( (string) ( $row['started_at'] ?? '' ) ), 'ended_at' => self::date( (string) ( $row['ended_at'] ?? '' ) ) ];
		}
		$now = current_time( 'mysql', true ); $wpdb->query( 'START TRANSACTION' );
		if ( false === $wpdb->delete( Schema::organization_relations_table(), [ 'contact_id' => $contact_id ], [ '%d' ] ) ) { $wpdb->query( 'ROLLBACK' ); return false; }
		foreach ( $normalized as $row ) { if ( false === $wpdb->insert( Schema::organization_relations_table(), [ 'contact_id' => $contact_id, 'organization_id' => $row['organization_id'], 'role_title' => $row['role_title'], 'is_primary' => $row['is_primary'], 'started_at' => $row['started_at'], 'ended_at' => $row['ended_at'], 'created_at' => $now, 'updated_at' => $now ], [ '%d','%d','%s','%d','%s','%s','%s','%s' ] ) ) { $wpdb->query( 'ROLLBACK' ); return false; } }
		$wpdb->query( 'COMMIT' ); return true;
	}
	/** @return int[] */
	public static function contact_ids_for_organization( int $organization_id ): array { global $wpdb; $ids = $wpdb->get_col( $wpdb->prepare( 'SELECT DISTINCT contact_id FROM ' . Schema::organization_relations_table() . ' WHERE organization_id = %d', $organization_id ) ); return array_map( 'intval', is_array( $ids ) ? $ids : [] ); }
	private static function date( string $value ): ?string { $value = trim( $value ); if ( '' === $value ) { return null; } $d = \DateTimeImmutable::createFromFormat( '!Y-m-d', $value ); return $d && $d->format( 'Y-m-d' ) === $value ? $value : null; }
}

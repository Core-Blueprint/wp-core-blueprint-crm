<?php
declare(strict_types=1);
namespace CB\CRM\Repository;
use CB\CRM\Content\Entity;
use CB\CRM\Database\Schema;
defined( 'ABSPATH' ) || exit;

final class Notes {
	/** @return array<int,array<string,mixed>> */
	public static function for_owner( string $owner_type, int $owner_id, int $limit = 50 ): array { global $wpdb; $limit = max( 1, min( 200, $limit ) ); $rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . Schema::notes_table() . ' WHERE owner_type = %s AND owner_id = %d ORDER BY created_at DESC, id DESC LIMIT %d', $owner_type, $owner_id, $limit ), ARRAY_A ); return is_array( $rows ) ? $rows : []; }
	public static function add( string $owner_type, int $owner_id, int $author_user_id, string $body ): int {
		global $wpdb; $body = sanitize_textarea_field( $body ); if ( '' === $body || ! Entity::valid_owner( $owner_type, $owner_id ) ) { return 0; }
		$now = current_time( 'mysql', true ); $ok = $wpdb->insert( Schema::notes_table(), [ 'owner_type' => $owner_type, 'owner_id' => $owner_id, 'author_user_id' => max( 0, $author_user_id ), 'body' => $body, 'created_at' => $now, 'updated_at' => $now ], [ '%s','%d','%d','%s','%s','%s' ] );
		return false === $ok ? 0 : (int) $wpdb->insert_id;
	}
}

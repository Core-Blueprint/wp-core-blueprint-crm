<?php
declare(strict_types=1);
namespace CB\CRM\Repository;

use CB\CRM\Content\Entity;
use CB\CRM\Database\Schema;
defined( 'ABSPATH' ) || exit;

final class Activity {
	/** @return array<int,array<string,mixed>> */
	public static function for_owner( string $owner_type, int $owner_id, int $limit = 100 ): array {
		global $wpdb;
		$limit = max( 1, min( 250, $limit ) );
		$rows = $wpdb->get_results( $wpdb->prepare(
			'SELECT * FROM ' . Schema::activities_table() . ' WHERE owner_type = %s AND owner_id = %d ORDER BY created_at DESC, id DESC LIMIT %d',
			$owner_type, $owner_id, $limit
		), ARRAY_A );
		return is_array( $rows ) ? $rows : [];
	}

	/** @param array<string,mixed> $context */
	public static function record(
		string $owner_type,
		int $owner_id,
		string $event_type,
		string $summary,
		string $source = 'crm',
		int $actor_user_id = 0,
		string $reference_type = '',
		string $reference_id = '',
		array $context = []
	): bool {
		global $wpdb;
		$event_type = sanitize_key( $event_type );
		$source = sanitize_key( $source );
		$reference_type = sanitize_key( $reference_type );
		$reference_id = sanitize_text_field( $reference_id );
		$summary = sanitize_text_field( $summary );
		if ( '' === $event_type || '' === $summary || ! Entity::valid_owner( $owner_type, $owner_id ) ) { return false; }
		$encoded = $context ? wp_json_encode( self::sanitize_context( $context ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) : null;
		$ok = $wpdb->insert( Schema::activities_table(), [
			'owner_type' => $owner_type, 'owner_id' => $owner_id, 'event_type' => $event_type, 'source' => $source ?: 'crm',
			'actor_user_id' => max( 0, $actor_user_id ), 'reference_type' => $reference_type, 'reference_id' => $reference_id,
			'summary' => $summary, 'context' => $encoded, 'created_at' => current_time( 'mysql', true ),
		], [ '%s','%d','%s','%s','%d','%s','%s','%s','%s','%s' ] );
		if ( false === $ok ) { return false; }
		do_action( 'cb_crm_activity_recorded', $owner_type, $owner_id, $event_type, $context );
		return true;
	}

	/** @param array<string,mixed> $context @return array<string,mixed> */
	private static function sanitize_context( array $context ): array {
		$out = [];
		foreach ( array_slice( $context, 0, 20, true ) as $key => $value ) {
			$key = sanitize_key( (string) $key );
			if ( '' === $key || preg_match( '/pass|secret|token|key/i', $key ) ) { continue; }
			if ( is_bool( $value ) || is_int( $value ) || is_float( $value ) || null === $value ) { $out[ $key ] = $value; continue; }
			if ( is_string( $value ) ) { $out[ $key ] = mb_substr( sanitize_text_field( $value ), 0, 500 ); }
		}
		return $out;
	}
}

<?php
declare(strict_types=1);
namespace CB\CRM\Content;

use CB\CRM\Repository\ContactMethods;
defined( 'ABSPATH' ) || exit;

final class ContactIdentity {
	public const EMAIL_WP = 'wp_user';
	public const EMAIL_CRM = 'crm';

	public static function linked_user_id( int $contact_id ): int { return absint( get_post_meta( $contact_id, Meta::WP_USER_ID, true ) ); }
	public static function account_email( int $contact_id ): string {
		$user_id = self::linked_user_id( $contact_id ); $user = $user_id > 0 ? get_userdata( $user_id ) : false;
		return $user instanceof \WP_User && is_email( $user->user_email ) ? sanitize_email( (string) $user->user_email ) : '';
	}
	public static function preferred_email( int $contact_id ): string {
		$mode = sanitize_key( (string) get_post_meta( $contact_id, Meta::EMAIL_MODE, true ) );
		if ( self::EMAIL_WP === $mode ) { $email = self::account_email( $contact_id ); if ( '' !== $email ) { return $email; } }
		$primary = ContactMethods::primary( Entity::CONTACT, $contact_id, 'email' );
		return $primary && is_email( (string) $primary['value'] ) ? sanitize_email( (string) $primary['value'] ) : self::account_email( $contact_id );
	}
	/** @return array<string,mixed>|null */
	public static function find_by_user_id( int $user_id ): ?array {
		if ( $user_id <= 0 ) { return null; }
		$ids = get_posts( [ 'post_type' => PostTypes::CONTACT, 'post_status' => 'any', 'fields' => 'ids', 'posts_per_page' => 2, 'meta_key' => Meta::WP_USER_ID, 'meta_value' => $user_id, 'no_found_rows' => true ] );
		return $ids ? [ 'contact_id' => (int) $ids[0], 'ambiguous' => count( $ids ) > 1 ] : null;
	}
	/** @return int[] */
	public static function find_by_email( string $email ): array {
		global $wpdb;
		$email = strtolower( sanitize_email( $email ) ); if ( ! is_email( $email ) ) { return []; }
		$ids = $wpdb->get_col( $wpdb->prepare( 'SELECT DISTINCT owner_id FROM ' . \CB\CRM\Database\Schema::contact_methods_table() . ' WHERE owner_type = %s AND method_type = %s AND LOWER(value) = %s', Entity::CONTACT, 'email', $email ) );
		$user = get_user_by( 'email', $email );
		if ( $user instanceof \WP_User ) { $linked = self::find_by_user_id( (int) $user->ID ); if ( $linked ) { $ids[] = (int) $linked['contact_id']; } }
		return array_values( array_unique( array_map( 'intval', is_array( $ids ) ? $ids : [] ) ) );
	}
}

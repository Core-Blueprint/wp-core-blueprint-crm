<?php
declare(strict_types=1);

namespace CB\CRM\Integration\Builders\Bricks;

use CB\CRM\Content\Entity;
use CB\CRM\Content\PostTypes;
use CB\CRM\Frontend\Data\Contact;
use CB\CRM\Frontend\Data\Organization;
defined( 'ABSPATH' ) || exit;

final class RecordContext {
	/** @return array{type:string,data:array<string,mixed>}|\WP_Error */
	public static function current(): array|\WP_Error {
		$from_loop = self::from_object( self::loop_object() ); if ( ! is_wp_error( $from_loop ) ) { return $from_loop; }
		$from_post = self::from_object( get_post() ); if ( ! is_wp_error( $from_post ) ) { return $from_post; }
		$contact = Contact::current(); return is_wp_error( $contact ) ? new \WP_Error( 'crm_record_context_not_found' ) : [ 'type' => Entity::CONTACT, 'data' => $contact ];
	}
	/** @return array<string,mixed>|\WP_Error */
	public static function data( string $owner_type ): array|\WP_Error { $owner_type = sanitize_key( $owner_type ); $current = self::current(); if ( ! is_wp_error( $current ) && $owner_type === $current['type'] ) { return $current['data']; } if ( Entity::CONTACT === $owner_type ) { return Contact::current(); } return new \WP_Error( 'crm_record_context_not_found' ); }
	public static function identifier( string $owner_type ): ?int { $data = self::data( $owner_type ); if ( is_wp_error( $data ) ) { return null; } $id = absint( $data['id'] ?? 0 ); return $id > 0 ? $id : null; }
	/** @return array{type:string,data:array<string,mixed>}|\WP_Error */
	private static function from_object( mixed $object ): array|\WP_Error {
		if ( is_array( $object ) ) { $type = self::projected_type( $object ); if ( '' !== $type ) { return [ 'type' => $type, 'data' => $object ]; } }
		if ( $object instanceof \WP_Post ) { $id = (int) $object->ID; return match ( (string) $object->post_type ) { PostTypes::CONTACT => self::wrap( Entity::CONTACT, Contact::get( $id ) ), PostTypes::ORGANIZATION => self::wrap( Entity::ORGANIZATION, Organization::get( $id ) ), default => new \WP_Error( 'crm_record_context_not_found' ) }; }
		return new \WP_Error( 'crm_record_context_not_found' );
	}
	/** @param array<string,mixed>|\WP_Error $data @return array{type:string,data:array<string,mixed>}|\WP_Error */
	private static function wrap( string $type, array|\WP_Error $data ): array|\WP_Error { return is_wp_error( $data ) ? $data : [ 'type' => $type, 'data' => $data ]; }
	/** @param array<string,mixed> $data */
	private static function projected_type( array $data ): string { if ( isset( $data['display_name'], $data['organizations'] ) ) { return Entity::CONTACT; } if ( isset( $data['name'], $data['contact_methods'] ) && array_key_exists( 'address', $data ) ) { return Entity::ORGANIZATION; } return ''; }
	private static function loop_object(): mixed { return class_exists( '\\Bricks\\Query' ) && method_exists( '\\Bricks\\Query', 'get_loop_object' ) ? \Bricks\Query::get_loop_object() : null; }
}

<?php
declare(strict_types=1);
namespace CB\CRM\Content;
defined( 'ABSPATH' ) || exit;

final class Entity {
	public const CONTACT = 'contact';
	public const ORGANIZATION = 'organization';
	public const SERVICE = 'service';

	public static function owner_type_for_post( int $post_id ): string {
		return match ( get_post_type( $post_id ) ) {
			PostTypes::CONTACT => self::CONTACT,
			PostTypes::ORGANIZATION => self::ORGANIZATION,
			PostTypes::SERVICE => self::SERVICE,
			default => '',
		};
	}

	public static function post_type_for_owner( string $owner_type ): string {
		return match ( sanitize_key( $owner_type ) ) {
			self::CONTACT => PostTypes::CONTACT,
			self::ORGANIZATION => PostTypes::ORGANIZATION,
			self::SERVICE => PostTypes::SERVICE,
			default => '',
		};
	}

	public static function valid_owner( string $owner_type, int $owner_id, bool $allow_service = true ): bool {
		$type = self::post_type_for_owner( $owner_type );
		if ( '' === $type || ( ! $allow_service && self::SERVICE === $owner_type ) ) { return false; }
		return $owner_id > 0 && $type === get_post_type( $owner_id );
	}
}

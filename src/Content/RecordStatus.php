<?php
declare(strict_types=1);

namespace CB\CRM\Content;

defined( 'ABSPATH' ) || exit;

final class RecordStatus {
	public const ACTIVE   = 'active';
	public const INACTIVE = 'inactive';
	public const ARCHIVED = 'archived';

	public const VALUES = [
		self::ACTIVE,
		self::INACTIVE,
		self::ARCHIVED,
	];

	public static function normalize( string $value ): string {
		$value = sanitize_key( $value );
		return in_array( $value, self::VALUES, true ) ? $value : self::ACTIVE;
	}

	/** @return array<string,string> */
	public static function labels(): array {
		return [
			self::ACTIVE   => __( 'Active', 'core-blueprint-crm' ),
			self::INACTIVE => __( 'Inactive', 'core-blueprint-crm' ),
			self::ARCHIVED => __( 'Archived', 'core-blueprint-crm' ),
		];
	}
}

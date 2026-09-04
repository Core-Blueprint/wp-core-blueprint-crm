<?php
declare(strict_types=1);

namespace CB\CRM\Integration\Builders\Bricks;

defined( 'ABSPATH' ) || exit;

final class GroupOrder {
	private const GROUP_PREFIX = 'Core Blueprint ';
	private const PRIORITY     = 9999;

	public static function init(): void {
		add_filter( 'bricks/dynamic_tags_list', [ self::class, 'normalize' ], self::PRIORITY );
	}

	/** @param array<int,mixed> $tags
	 *  @return array<int,mixed>
	 */
	public static function normalize( array $tags ): array {
		$core_tags     = [];
		$other_tags    = [];
		$insert_offset = null;

		foreach ( $tags as $tag ) {
			if ( self::is_core_blueprint_tag( $tag ) ) {
				if ( null === $insert_offset ) {
					$insert_offset = count( $other_tags );
				}
				$core_tags[] = $tag;
				continue;
			}
			$other_tags[] = $tag;
		}

		if ( null === $insert_offset || [] === $core_tags ) {
			return array_values( $tags );
		}

		array_splice( $other_tags, $insert_offset, 0, $core_tags );
		return array_values( $other_tags );
	}

	private static function is_core_blueprint_tag( mixed $tag ): bool {
		return is_array( $tag )
			&& isset( $tag['group'] )
			&& is_string( $tag['group'] )
			&& str_starts_with( $tag['group'], self::GROUP_PREFIX );
	}
}

<?php
declare(strict_types=1);

namespace CB\CRM\Frontend\Data;

use CB\CRM\Content\Entity;
use CB\CRM\Content\PostTypes;
use CB\CRM\Frontend\Access;
use CB\CRM\Repository\Services;

defined( 'ABSPATH' ) || exit;

/** @internal Projection helper; not a supported integration contract. */
final class Support {
	/** @return array<int,array{id:int,name:string,slug:string}> */
	public static function tags( int $post_id ): array {
		$terms = get_the_terms( $post_id, PostTypes::TAG );
		if ( is_wp_error( $terms ) || ! is_array( $terms ) ) {
			return [];
		}

		$items = [];
		foreach ( $terms as $term ) {
			if ( ! $term instanceof \WP_Term ) {
				continue;
			}
			$items[] = [
				'id'   => (int) $term->term_id,
				'name' => sanitize_text_field( (string) $term->name ),
				'slug' => sanitize_title( (string) $term->slug ),
			];
		}
		return $items;
	}

	/** @param array<string,mixed> $row
	 *  @return array<string,mixed>|null
	 */
	public static function service_assignment( array $row, bool $include_sensitive ): ?array {
		$service_id = absint( $row['service_id'] ?? 0 );
		$post       = $service_id > 0 ? get_post( $service_id ) : null;
		if ( ! $post instanceof \WP_Post || PostTypes::SERVICE !== $post->post_type ) {
			return null;
		}
		$item = [
			'id'         => $service_id,
			'name'       => sanitize_text_field( (string) get_the_title( $post ) ),
			'status'     => sanitize_key( (string) ( $row['status'] ?? 'active' ) ),
			'started_at' => self::date_or_empty( $row['started_at'] ?? '' ),
			'ended_at'   => self::date_or_empty( $row['ended_at'] ?? '' ),
		];
		if ( ! $include_sensitive ) {
			return $item;
		}

		$pricing = Services::effective_pricing( $row );
		$item['pricing_mode']    = sanitize_key( (string) ( $row['pricing_mode'] ?? 'inherit' ) );
		$item['pricing_summary'] = Services::pricing_summary( $row );
		$item['amount_minor']    = $pricing['amount_minor'];
		$item['currency']        = $pricing['currency'];
		$item['tax_mode']        = $pricing['tax_mode'];
		$item['tax_rate_id']     = $pricing['tax_rate_id'];
		return $item;
	}

	/** @return array<int,array<string,mixed>> */
	public static function service_assignments( string $owner_type, int $owner_id, bool $include_history ): array {
		if ( ! in_array( $owner_type, [ Entity::CONTACT, Entity::ORGANIZATION ], true ) ) {
			return [];
		}

		$items = [];
		foreach ( Services::for_owner( $owner_type, $owner_id ) as $row ) {
			if ( ! $include_history && ! Access::assignment_active( $row ) ) {
				continue;
			}
			$item = self::service_assignment( $row, $include_history );
			if ( null !== $item ) {
				$items[] = $item;
			}
		}
		return $items;
	}

	private static function date_or_empty( mixed $value ): string {
		$value = is_scalar( $value ) ? trim( (string) $value ) : '';
		$date  = \DateTimeImmutable::createFromFormat( '!Y-m-d', $value );
		return $date && $date->format( 'Y-m-d' ) === $value ? $value : '';
	}
}

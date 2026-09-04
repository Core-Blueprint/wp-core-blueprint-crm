<?php
declare(strict_types=1);

namespace CB\CRM\Frontend\Data;

use CB\CRM\Content\Entity;
use CB\CRM\Content\PostTypes;
use CB\CRM\Frontend\Access;
use CB\CRM\Repository\ServiceAgreements;
use CB\Work\PublicApi\Pricing as WorkPricing;
use CB\Work\PublicApi\Services as WorkServices;
defined( 'ABSPATH' ) || exit;

/** @internal Projection helper; not a supported integration contract. */
final class Support {
	/** @return array<int,array{id:int,name:string,slug:string}> */
	public static function tags( int $post_id ): array { $terms = get_the_terms( $post_id, PostTypes::TAG ); if ( is_wp_error( $terms ) || ! is_array( $terms ) ) { return []; } $items = []; foreach ( $terms as $term ) { if ( $term instanceof \WP_Term ) { $items[] = [ 'id' => (int) $term->term_id, 'name' => sanitize_text_field( (string) $term->name ), 'slug' => sanitize_title( (string) $term->slug ) ]; } } return $items; }

	/** @return array<int,array<string,mixed>> */
	public static function service_agreements( string $customer_type, int $customer_id, bool $include_sensitive ): array {
		if ( ! in_array( $customer_type, [ Entity::CONTACT, Entity::ORGANIZATION ], true ) || ! class_exists( WorkServices::class ) ) { return []; }
		$items = [];
		foreach ( ServiceAgreements::for_owner( $customer_type, $customer_id ) as $row ) {
			if ( ! $include_sensitive && ! Access::agreement_active( $row ) ) { continue; }
			$service_id = absint( $row['work_service_id'] ?? 0 ); $service = $service_id > 0 ? WorkServices::get( $service_id ) : null; if ( null === $service ) { continue; }
			$item = [ 'agreement_id' => absint( $row['id'] ?? 0 ), 'service_id' => $service_id, 'service_name' => sanitize_text_field( (string) ( $service['title'] ?? '' ) ), 'status' => sanitize_key( (string) ( $row['status'] ?? 'active' ) ), 'valid_from' => (string) ( $row['valid_from'] ?? '' ), 'valid_until' => (string) ( $row['valid_until'] ?? '' ) ];
			if ( $include_sensitive && class_exists( WorkPricing::class ) ) { $item['pricing_mode'] = sanitize_key( (string) ( $row['pricing_mode'] ?? 'inherit' ) ); $item['effective_pricing'] = WorkPricing::resolve( $service_id, [ 'customer_type' => $customer_type, 'customer_id' => $customer_id ] ); $item['notes'] = sanitize_textarea_field( (string) ( $row['notes'] ?? '' ) ); }
			$items[] = $item;
		}
		return $items;
	}
}

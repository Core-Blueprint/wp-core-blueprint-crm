<?php
declare(strict_types=1);

namespace CB\CRM\Frontend\Data;

use CB\CRM\Content\Meta;
use CB\CRM\Content\PostTypes;
use CB\CRM\Content\RecordStatus;
use CB\CRM\Content\ServicePricing;
use CB\CRM\Frontend\Access;
use CB\CRM\Repository\TaxRates;

defined( 'ABSPATH' ) || exit;

final class Service {
	private const FIELDS = [ 'id', 'name', 'pricing_summary', 'amount_minor', 'currency', 'tax_mode', 'tax_rate', 'status' ];

	/** @return array<string,mixed>|\WP_Error */
	public static function get( int $service_id ): array|\WP_Error {
		$post = $service_id > 0 ? get_post( $service_id ) : null;
		if ( ! $post instanceof \WP_Post || PostTypes::SERVICE !== $post->post_type || ! Access::can_read_service( $service_id ) ) {
			return new \WP_Error( 'crm_service_not_found' );
		}
		return self::project( $post );
	}

	public static function value( string $field, int $service_id ): mixed {
		$field = sanitize_key( $field );
		if ( ! in_array( $field, self::FIELDS, true ) ) {
			return null;
		}
		$service = self::get( $service_id );
		return is_wp_error( $service ) ? $service : ( $service[ $field ] ?? null );
	}

	/** @return string[] */
	public static function fields(): array {
		return self::FIELDS;
	}

	/** @return array<string,mixed> */
	private static function project( \WP_Post $post ): array {
		$service_id = (int) $post->ID;
		$pricing    = ServicePricing::get( $service_id );
		$rate       = $pricing['tax_rate_id'] > 0 ? TaxRates::get( $pricing['tax_rate_id'] ) : null;
		return [
			'id'              => $service_id,
			'name'            => sanitize_text_field( (string) get_the_title( $post ) ),
			'pricing_summary' => ServicePricing::summary( $pricing ),
			'amount_minor'    => $pricing['amount_minor'],
			'currency'        => $pricing['currency'],
			'tax_mode'        => $pricing['tax_mode'],
			'tax_rate'        => is_array( $rate ) ? [
				'id'           => absint( $rate['id'] ?? 0 ),
				'label'        => sanitize_text_field( (string) ( $rate['label'] ?? '' ) ),
				'country_code' => sanitize_text_field( (string) ( $rate['country_code'] ?? '' ) ),
				'rate'         => TaxRates::format_rate_bp( (int) ( $rate['rate_bp'] ?? 0 ) ),
			] : null,
			'status'          => RecordStatus::normalize( (string) get_post_meta( $service_id, Meta::STATUS, true ) ),
		];
	}
}

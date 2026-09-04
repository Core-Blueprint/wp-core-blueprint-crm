<?php
declare(strict_types=1);

namespace CB\CRM\Integration;

use CB\CRM\Content\Entity;
use CB\CRM\Repository\ServiceAgreements;
use CB\Work\PublicApi\PricingProviders;
defined( 'ABSPATH' ) || exit;

/** Optional CRM agreement provider for Work's canonical Pricing Resolver. */
final class WorkPricing {
	public static function init(): void { add_action( 'cb_work_register_pricing_providers', [ self::class, 'register_provider' ] ); }
	public static function register_provider(): void { if ( class_exists( PricingProviders::class ) ) { PricingProviders::register( 'crm', [ self::class, 'resolve' ], 100 ); } }
	/** @param array{service_id:int,customer_type:string,customer_id:int,effective_at:string} $context @return array<string,mixed>|null */
	public static function resolve( array $context ): ?array {
		$customer_type = sanitize_key( (string) ( $context['customer_type'] ?? '' ) ); $customer_id = absint( $context['customer_id'] ?? 0 ); $service_id = absint( $context['service_id'] ?? 0 ); $effective_at = (string) ( $context['effective_at'] ?? '' );
		if ( ! in_array( $customer_type, [ Entity::CONTACT, Entity::ORGANIZATION ], true ) || $customer_id <= 0 || $service_id <= 0 ) { return null; }
		$agreement = ServiceAgreements::effective_for( $customer_type, $customer_id, $service_id, $effective_at ); if ( null === $agreement ) { return null; }
		return [ 'pricing' => ServiceAgreements::pricing_projection( $agreement ), 'reference_type' => 'service_agreement', 'reference_id' => (string) absint( $agreement['id'] ?? 0 ) ];
	}
}

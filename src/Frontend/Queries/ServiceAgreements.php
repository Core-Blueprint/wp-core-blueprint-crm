<?php
declare(strict_types=1);

namespace CB\CRM\Frontend\Queries;

use CB\CRM\Content\Entity;
use CB\CRM\Frontend\Access;
use CB\CRM\Frontend\Data\Support;
defined( 'ABSPATH' ) || exit;

/** Builder-neutral CRM Service Agreement query contract. */
final class ServiceAgreements {
	/** @return array<int,array<string,mixed>>|\WP_Error */
	public static function for_customer( string $customer_type, int $customer_id ): array|\WP_Error {
		$customer_type = sanitize_key( $customer_type );
		$allowed = match ( $customer_type ) { Entity::CONTACT => Access::can_read_contact( $customer_id ), Entity::ORGANIZATION => Access::can_read_organization( $customer_id ), default => false };
		if ( ! $allowed ) { return new \WP_Error( 'crm_service_agreements_forbidden' ); }
		return Support::service_agreements( $customer_type, $customer_id, Access::is_staff() );
	}
}

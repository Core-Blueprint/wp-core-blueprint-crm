<?php
declare(strict_types=1);

namespace CB\CRM\Application\Actions;

use CB\CRM\Application\RecordUpdater;
use CB\CRM\Capabilities;
use CB\CRM\Content\PostTypes;

defined( 'ABSPATH' ) || exit;

final class UpdateOrganization {
	public const ID = 'crm.update_organization';

	/** @param array<string,mixed> $input
	 *  @return array{record_id:int,owner_type:string,updated_areas:string[]}|\WP_Error
	 */
	public static function execute( int $organization_id, array $input ): array|\WP_Error {
		if ( ! current_user_can( Capabilities::MANAGE ) || ! current_user_can( 'edit_post', $organization_id ) ) {
			return new \WP_Error( 'crm_forbidden' );
		}
		if ( $organization_id <= 0 || PostTypes::ORGANIZATION !== get_post_type( $organization_id ) ) {
			return new \WP_Error( 'crm_organization_not_found' );
		}
		return RecordUpdater::update_organization( $organization_id, $input );
	}
}

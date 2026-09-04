<?php
declare(strict_types=1);

namespace CB\CRM\Application\Actions;

use CB\CRM\Application\RecordUpdater;
use CB\CRM\Capabilities;
use CB\CRM\Content\PostTypes;

defined( 'ABSPATH' ) || exit;

final class UpdateContact {
	public const ID = 'crm.update_contact';

	/** @param array<string,mixed> $input
	 *  @return array{record_id:int,owner_type:string,updated_areas:string[]}|\WP_Error
	 */
	public static function execute( int $contact_id, array $input ): array|\WP_Error {
		if ( ! current_user_can( Capabilities::MANAGE ) || ! current_user_can( 'edit_post', $contact_id ) ) {
			return new \WP_Error( 'crm_forbidden' );
		}
		if ( $contact_id <= 0 || PostTypes::CONTACT !== get_post_type( $contact_id ) ) {
			return new \WP_Error( 'crm_contact_not_found' );
		}
		return RecordUpdater::update_contact( $contact_id, $input );
	}
}

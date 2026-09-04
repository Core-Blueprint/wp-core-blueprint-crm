<?php
declare(strict_types=1);

namespace CB\CRM\Application\Actions;

use CB\CRM\Capabilities;
use CB\CRM\Content\Entity;
use CB\CRM\Governance;
use CB\CRM\Repository\DocumentLinks;
use CB\Docs\Frontend\Data\Document;

defined( 'ABSPATH' ) || exit;

final class LinkDocument {
	public const ID = 'crm.link_document';

	/**
	 * @param array{relation_type?:mixed,notes?:mixed} $input
	 * @return array{owner_type:string,owner_id:int,document_id:int,relation_type:string,created:bool,changed:bool}|\WP_Error
	 */
	public static function execute( string $owner_type, int $owner_id, int $document_id, array $input = [] ): array|\WP_Error {
		$owner_type = sanitize_key( $owner_type );
		if (
			! current_user_can( Capabilities::MANAGE )
			|| ! Entity::valid_owner( $owner_type, $owner_id, false )
			|| ! current_user_can( 'edit_post', $owner_id )
		) {
			return new \WP_Error( 'crm_forbidden' );
		}

		if ( ! class_exists( Document::class ) ) {
			return new \WP_Error( 'crm_docs_unavailable' );
		}

		$document = Document::get( $document_id );
		if ( is_wp_error( $document ) || ! current_user_can( 'edit_post', $document_id ) ) {
			return new \WP_Error( 'crm_document_not_found' );
		}

		$relation_type = is_scalar( $input['relation_type'] ?? null )
			? (string) $input['relation_type']
			: '';
		$notes = is_scalar( $input['notes'] ?? null )
			? (string) $input['notes']
			: '';

		$result = DocumentLinks::link(
			$owner_type,
			$owner_id,
			$document_id,
			$relation_type,
			$notes,
			get_current_user_id()
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$relation = sanitize_text_field( (string) ( $result['row']['relation_type'] ?? '' ) );
		if ( $result['changed'] ) {
			Governance::record_document_link_changed(
				$owner_type,
				$owner_id,
				$document_id,
				$result['created'] ? 'linked' : 'updated',
				$relation
			);
		}

		return [
			'owner_type'    => $owner_type,
			'owner_id'      => $owner_id,
			'document_id'   => $document_id,
			'relation_type' => $relation,
			'created'       => (bool) $result['created'],
			'changed'       => (bool) $result['changed'],
		];
	}
}

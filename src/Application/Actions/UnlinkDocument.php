<?php
declare(strict_types=1);

namespace CB\CRM\Application\Actions;

use CB\CRM\Capabilities;
use CB\CRM\Content\Entity;
use CB\CRM\Governance;
use CB\CRM\Repository\DocumentLinks;
use CB\Docs\Frontend\Data\Document;

defined( 'ABSPATH' ) || exit;

final class UnlinkDocument {
	public const ID = 'crm.unlink_document';

	/** @return array{owner_type:string,owner_id:int,document_id:int,removed:bool}|\WP_Error */
	public static function execute( string $owner_type, int $owner_id, int $document_id ): array|\WP_Error {
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

		$existing = DocumentLinks::get( $owner_type, $owner_id, $document_id );
		if ( ! is_array( $existing ) ) {
			return [
				'owner_type'  => $owner_type,
				'owner_id'    => $owner_id,
				'document_id' => $document_id,
				'removed'     => false,
			];
		}

		if ( ! DocumentLinks::unlink( $owner_type, $owner_id, $document_id ) ) {
			return new \WP_Error( 'crm_document_unlink_failed' );
		}

		Governance::record_document_link_changed(
			$owner_type,
			$owner_id,
			$document_id,
			'unlinked',
			sanitize_text_field( (string) ( $existing['relation_type'] ?? '' ) )
		);

		return [
			'owner_type'  => $owner_type,
			'owner_id'    => $owner_id,
			'document_id' => $document_id,
			'removed'     => true,
		];
	}
}

<?php
declare(strict_types=1);

namespace CB\CRM\Integration;

use CB\CRM\Application\Actions\LinkDocument;
use CB\CRM\Application\Actions\UnlinkDocument;
use CB\CRM\Capabilities;
use CB\CRM\Content\Entity;
use CB\CRM\Frontend\Queries\Contacts;
use CB\CRM\Frontend\Queries\Organizations;
use CB\Docs\Frontend\Data\Document;
use CB\Docs\Frontend\Search;

defined( 'ABSPATH' ) || exit;

final class DocsAjax {
	private const NONCE         = 'cb_crm_docs_relations';
	private const VIEW_OWNER    = 'owner';
	private const VIEW_DOCUMENT = 'document';

	public static function ajax_search(): void {
		self::authorize_ajax();

		$view = isset( $_POST['view'] ) ? sanitize_key( (string) wp_unslash( $_POST['view'] ) ) : '';
		$term = isset( $_POST['term'] ) ? sanitize_text_field( (string) wp_unslash( $_POST['term'] ) ) : '';
		if ( strlen( $term ) < 2 ) {
			wp_send_json_success( [] );
		}

		if ( self::VIEW_OWNER === $view ) {
			$result = Search::documents( $term, 20 );
			$items  = [];
			foreach ( $result['items'] as $document ) {
				$id = absint( $document['id'] ?? 0 );
				if ( $id <= 0 || ! current_user_can( 'edit_post', $id ) ) {
					continue;
				}
				$items[] = [
					'id'    => $id,
					'type'  => 'document',
					'label' => sanitize_text_field( (string) ( $document['title'] ?? '' ) ),
					'meta'  => sanitize_text_field( (string) ( $document['documentation_status'] ?? '' ) ),
					'url'   => esc_url_raw( (string) ( $document['permalink'] ?? '' ) ),
				];
			}
			wp_send_json_success( $items );
		}

		if ( self::VIEW_DOCUMENT === $view ) {
			$owner_type = isset( $_POST['owner_type'] ) ? sanitize_key( (string) wp_unslash( $_POST['owner_type'] ) ) : Entity::CONTACT;
			$query = Entity::ORGANIZATION === $owner_type
				? Organizations::staff( [ 'search' => $term, 'per_page' => 20 ] )
				: Contacts::staff( [ 'search' => $term, 'per_page' => 20 ] );

			if ( is_wp_error( $query ) ) {
				self::send_error( $query );
			}

			$items = [];
			foreach ( $query['items'] as $record ) {
				$id = absint( $record['id'] ?? 0 );
				if ( $id <= 0 ) {
					continue;
				}
				$items[] = [
					'id'    => $id,
					'type'  => $owner_type,
					'label' => sanitize_text_field( (string) ( $record[ Entity::ORGANIZATION === $owner_type ? 'name' : 'display_name' ] ?? '' ) ),
					'meta'  => sanitize_text_field( (string) ( $record['status'] ?? '' ) ),
					'url'   => esc_url_raw( (string) get_edit_post_link( $id, '' ) ),
				];
			}
			wp_send_json_success( $items );
		}

		wp_send_json_success( [] );
	}

	public static function ajax_link(): void {
		self::authorize_ajax();

		$view          = self::request_view();
		$owner_type    = isset( $_POST['owner_type'] ) ? sanitize_key( (string) wp_unslash( $_POST['owner_type'] ) ) : '';
		$owner_id      = isset( $_POST['owner_id'] ) ? absint( $_POST['owner_id'] ) : 0;
		$document_id   = isset( $_POST['document_id'] ) ? absint( $_POST['document_id'] ) : 0;
		$relation_type = isset( $_POST['relation_type'] ) ? sanitize_text_field( (string) wp_unslash( $_POST['relation_type'] ) ) : '';

		$result = LinkDocument::execute(
			$owner_type,
			$owner_id,
			$document_id,
			[ 'relation_type' => $relation_type ]
		);
		if ( is_wp_error( $result ) ) {
			self::send_error( $result );
		}

		wp_send_json_success( [
			'html' => DocsAdmin::linked_html( $view, $owner_type, $owner_id, $document_id ),
		] );
	}

	public static function ajax_unlink(): void {
		self::authorize_ajax();

		$view        = self::request_view();
		$owner_type  = isset( $_POST['owner_type'] ) ? sanitize_key( (string) wp_unslash( $_POST['owner_type'] ) ) : '';
		$owner_id    = isset( $_POST['owner_id'] ) ? absint( $_POST['owner_id'] ) : 0;
		$document_id = isset( $_POST['document_id'] ) ? absint( $_POST['document_id'] ) : 0;

		$result = UnlinkDocument::execute( $owner_type, $owner_id, $document_id );
		if ( is_wp_error( $result ) ) {
			self::send_error( $result );
		}

		wp_send_json_success( [
			'html' => DocsAdmin::linked_html( $view, $owner_type, $owner_id, $document_id ),
		] );
	}

	private static function authorize_ajax(): void {
		self::require_post_request();
		check_ajax_referer( self::NONCE, 'nonce' );
		if ( ! self::available() || ! current_user_can( Capabilities::MANAGE ) ) {
			wp_send_json_error( [ 'code' => 'crm_forbidden' ], 403 );
		}
	}

	private static function require_post_request(): void {
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( (string) $_SERVER['REQUEST_METHOD'] ) : '';
		if ( 'POST' !== $method ) {
			wp_send_json_error( [ 'code' => 'crm_method_not_allowed' ], 405 );
		}
	}

	private static function request_view(): string {
		$view = isset( $_POST['view'] ) ? sanitize_key( (string) wp_unslash( $_POST['view'] ) ) : '';
		return in_array( $view, [ self::VIEW_OWNER, self::VIEW_DOCUMENT ], true ) ? $view : self::VIEW_OWNER;
	}

	private static function available(): bool {
		return class_exists( Search::class ) && class_exists( Document::class );
	}

	private static function send_error( \WP_Error $error ): never {
		$status = 'crm_forbidden' === $error->get_error_code() ? 403 : 400;
		wp_send_json_error( [ 'code' => $error->get_error_code() ], $status );
	}
}

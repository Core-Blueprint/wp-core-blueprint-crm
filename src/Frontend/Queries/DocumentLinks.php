<?php
declare(strict_types=1);

namespace CB\CRM\Frontend\Queries;

use CB\CRM\Capabilities;
use CB\CRM\Content\Entity;
use CB\CRM\Content\PostTypes;
use CB\CRM\Frontend\Access;
use CB\CRM\Repository\DocumentLinks as Repository;
use CB\Docs\Frontend\Data\Document;
use CB\Docs\Frontend\Queries\Documents;

defined( 'ABSPATH' ) || exit;

final class DocumentLinks {
	private const MAX_LIMIT = 100;

	/**
	 * Return readable Docs linked to one authorized CRM Contact/Organization.
	 *
	 * The stored CRM relation is never treated as document authorization.
	 * Documents are re-resolved through the public Docs query boundary.
	 *
	 * @return array<int,array{
	 *     owner_type:string,
	 *     owner_id:int,
	 *     document_id:int,
	 *     relation_type:string,
	 *     document:array<string,mixed>
	 * }>|\WP_Error
	 */
	public static function for_owner( string $owner_type, int $owner_id, int $limit = self::MAX_LIMIT ): array|\WP_Error {
		$owner_type = sanitize_key( $owner_type );
		if ( ! self::can_read_owner( $owner_type, $owner_id ) ) {
			return new \WP_Error( 'crm_record_not_found' );
		}
		if ( ! class_exists( Documents::class ) ) {
			return [];
		}

		$limit = max( 1, min( self::MAX_LIMIT, $limit ) );
		$rows  = Repository::for_owner( $owner_type, $owner_id, $limit );
		if ( [] === $rows ) {
			return [];
		}

		$ids = [];
		foreach ( $rows as $row ) {
			$id = absint( $row['document_id'] ?? 0 );
			if ( $id > 0 ) {
				$ids[] = $id;
			}
		}
		if ( [] === $ids ) {
			return [];
		}

		$result = Documents::query( [
			'include_ids' => array_values( array_unique( $ids ) ),
			'per_page'    => min( self::MAX_LIMIT, count( $ids ) ),
		] );
		$documents = [];
		foreach ( $result['items'] as $document ) {
			$id = absint( $document['id'] ?? 0 );
			if ( $id > 0 ) {
				$documents[ $id ] = $document;
			}
		}

		$staff = Access::is_staff();
		$items = [];
		foreach ( $rows as $row ) {
			$document_id = absint( $row['document_id'] ?? 0 );
			if ( $document_id <= 0 || ! isset( $documents[ $document_id ] ) ) {
				continue;
			}
			$items[] = [
				'owner_type'    => $owner_type,
				'owner_id'      => $owner_id,
				'document_id'   => $document_id,
				'relation_type' => $staff ? sanitize_text_field( (string) ( $row['relation_type'] ?? '' ) ) : '',
				'document'      => $documents[ $document_id ],
			];
		}

		return $items;
	}

	/** @return array<int,array<string,mixed>>|\WP_Error */
	public static function for_document( int $document_id, int $limit = self::MAX_LIMIT ): array|\WP_Error {
		if ( ! current_user_can( Capabilities::MANAGE ) ) {
			return new \WP_Error( 'crm_forbidden' );
		}
		if ( ! class_exists( Document::class ) ) {
			return new \WP_Error( 'crm_docs_unavailable' );
		}

		$document = Document::get( $document_id );
		if ( is_wp_error( $document ) || ! current_user_can( 'edit_post', $document_id ) ) {
			return new \WP_Error( 'crm_document_not_found' );
		}

		$limit = max( 1, min( self::MAX_LIMIT, $limit ) );
		$rows  = Repository::for_document( $document_id, $limit );
		if ( [] === $rows ) {
			return [];
		}

		$ids = [];
		foreach ( $rows as $row ) {
			$id = absint( $row['owner_id'] ?? 0 );
			if ( $id > 0 ) {
				$ids[] = $id;
			}
		}

		$ids = array_values( array_unique( $ids ) );
		if ( [] === $ids ) {
			return [];
		}

		$posts = get_posts( [
			'post_type'              => [ PostTypes::CONTACT, PostTypes::ORGANIZATION ],
			'post_status'            => 'any',
			'post__in'               => $ids,
			'posts_per_page'         => $limit,
			'orderby'                => 'post__in',
			'suppress_filters'       => false,
			'ignore_sticky_posts'    => true,
			'no_found_rows'          => true,
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
		] );
		$records = [];
		foreach ( $posts as $post ) {
			if ( ! $post instanceof \WP_Post ) {
				continue;
			}
			$records[ (int) $post->ID ] = $post;
		}

		$items = [];
		foreach ( $rows as $row ) {
			$owner_type = sanitize_key( (string) ( $row['owner_type'] ?? '' ) );
			$owner_id   = absint( $row['owner_id'] ?? 0 );
			$post       = $records[ $owner_id ] ?? null;
			$expected   = Entity::post_type_for_owner( $owner_type );
			if (
				! $post instanceof \WP_Post
				|| ! in_array( $owner_type, [ Entity::CONTACT, Entity::ORGANIZATION ], true )
				|| $expected !== $post->post_type
			) {
				continue;
			}

			$items[] = [
				'owner_type'    => $owner_type,
				'owner_id'      => $owner_id,
				'name'          => sanitize_text_field( (string) get_the_title( $post ) ),
				'document_id'   => $document_id,
				'relation_type' => sanitize_text_field( (string) ( $row['relation_type'] ?? '' ) ),
				'notes'         => sanitize_textarea_field( (string) ( $row['notes'] ?? '' ) ),
				'created_at'    => sanitize_text_field( (string) ( $row['created_at'] ?? '' ) ),
				'created_by'    => absint( $row['created_by'] ?? 0 ),
			];
		}

		return $items;
	}

	private static function can_read_owner( string $owner_type, int $owner_id ): bool {
		return match ( $owner_type ) {
			Entity::CONTACT      => Access::can_read_contact( $owner_id ),
			Entity::ORGANIZATION => Access::can_read_organization( $owner_id ),
			default              => false,
		};
	}
}

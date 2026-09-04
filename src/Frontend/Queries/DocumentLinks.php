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
	private const MAX_LIMIT           = 100;
	private const MAX_BATCH_OWNERS    = 25;
	private const MAX_BATCH_RELATIONS = 2500;

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

		$documents = self::documents_for_rows( $rows, $limit );
		$staff     = Access::is_staff();
		$items     = [];

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

	/**
	 * Return readable Docs plus CRM relation contexts for multiple CRM owners.
	 *
	 * This is the supported batch boundary for combined integrations. Owner
	 * authorization is resolved once, CRM relation storage is read once and all
	 * referenced documents are resolved through one public Docs query.
	 *
	 * `$limit` caps distinct document results. Up to 25 owners are accepted.
	 * A stored relation never grants document access.
	 *
	 * @param array<int,array{type?:mixed,id?:mixed}> $owners
	 * @return array<int,array{
	 *     document_id:int,
	 *     document:array<string,mixed>,
	 *     contexts:array<int,array{owner_type:string,owner_id:int,relation_type:string}>
	 * }>|\WP_Error
	 */
	public static function documents_for_owners( array $owners, int $limit = self::MAX_LIMIT ): array|\WP_Error {
		if ( ! class_exists( Documents::class ) ) {
			return [];
		}

		$owners = self::authorized_owners( $owners );
		if ( is_wp_error( $owners ) ) {
			return $owners;
		}
		if ( [] === $owners ) {
			return [];
		}

		$limit = max( 1, min( self::MAX_LIMIT, $limit ) );
		$rows  = Repository::for_owners( $owners, self::MAX_BATCH_RELATIONS );
		if ( [] === $rows ) {
			return [];
		}

		$owner_order = [];
		foreach ( $owners as $index => $owner ) {
			$owner_order[ $owner['type'] . ':' . $owner['id'] ] = $index;
		}
		usort(
			$rows,
			static function ( array $left, array $right ) use ( $owner_order ): int {
				$left_key  = sanitize_key( (string) ( $left['owner_type'] ?? '' ) ) . ':' . absint( $left['owner_id'] ?? 0 );
				$right_key = sanitize_key( (string) ( $right['owner_type'] ?? '' ) ) . ':' . absint( $right['owner_id'] ?? 0 );
				$owner_cmp = ( $owner_order[ $left_key ] ?? PHP_INT_MAX ) <=> ( $owner_order[ $right_key ] ?? PHP_INT_MAX );
				return 0 !== $owner_cmp
					? $owner_cmp
					: absint( $left['id'] ?? 0 ) <=> absint( $right['id'] ?? 0 );
			}
		);

		$selected_ids = [];
		foreach ( $rows as $row ) {
			$document_id = absint( $row['document_id'] ?? 0 );
			if ( $document_id <= 0 || isset( $selected_ids[ $document_id ] ) ) {
				continue;
			}

			$selected_ids[ $document_id ] = true;
			if ( count( $selected_ids ) >= $limit ) {
				break;
			}
		}
		if ( [] === $selected_ids ) {
			return [];
		}

		$documents = self::documents_by_ids( array_keys( $selected_ids ) );
		if ( [] === $documents ) {
			return [];
		}

		$staff = Access::is_staff();
		$items = [];
		foreach ( array_keys( $selected_ids ) as $document_id ) {
			if ( isset( $documents[ $document_id ] ) ) {
				$items[ $document_id ] = [
					'document_id' => $document_id,
					'document'    => $documents[ $document_id ],
					'contexts'    => [],
				];
			}
		}

		$seen_contexts = [];
		foreach ( $rows as $row ) {
			$document_id = absint( $row['document_id'] ?? 0 );
			if ( ! isset( $items[ $document_id ] ) ) {
				continue;
			}

			$owner_type = sanitize_key( (string) ( $row['owner_type'] ?? '' ) );
			$owner_id   = absint( $row['owner_id'] ?? 0 );
			$owner_key  = $owner_type . ':' . $owner_id;
			if ( ! isset( $owner_order[ $owner_key ] ) || $owner_id <= 0 ) {
				continue;
			}

			$context_key = $document_id . '|' . $owner_key;
			if ( isset( $seen_contexts[ $context_key ] ) ) {
				continue;
			}
			$seen_contexts[ $context_key ] = true;

			$items[ $document_id ]['contexts'][] = [
				'owner_type'    => $owner_type,
				'owner_id'      => $owner_id,
				'relation_type' => $staff ? sanitize_text_field( (string) ( $row['relation_type'] ?? '' ) ) : '',
			];
		}

		return array_values( $items );
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

	/**
	 * @param array<int,array<string,mixed>> $rows
	 * @return array<int,array<string,mixed>>
	 */
	private static function documents_for_rows( array $rows, int $limit ): array {
		$ids = [];
		foreach ( $rows as $row ) {
			$id = absint( $row['document_id'] ?? 0 );
			if ( $id > 0 ) {
				$ids[] = $id;
			}
		}

		$ids = array_slice( array_values( array_unique( $ids ) ), 0, $limit );
		return self::documents_by_ids( $ids );
	}

	/**
	 * @param int[] $ids
	 * @return array<int,array<string,mixed>>
	 */
	private static function documents_by_ids( array $ids ): array {
		if ( [] === $ids ) {
			return [];
		}

		$result = Documents::query( [
			'include_ids' => $ids,
			'per_page'    => min( self::MAX_LIMIT, count( $ids ) ),
		] );

		$documents = [];
		foreach ( $result['items'] as $document ) {
			$id = absint( $document['id'] ?? 0 );
			if ( $id > 0 ) {
				$documents[ $id ] = $document;
			}
		}

		return $documents;
	}

	/**
	 * Resolve and authorize a bounded owner set without repeated relation reads.
	 *
	 * @param array<int,array{type?:mixed,id?:mixed}> $owners
	 * @return array<int,array{type:string,id:int}>|\WP_Error
	 */
	private static function authorized_owners( array $owners ): array|\WP_Error {
		$normalized = [];
		foreach ( $owners as $owner ) {
			if ( ! is_array( $owner ) ) {
				continue;
			}

			$type = sanitize_key( (string) ( $owner['type'] ?? '' ) );
			$id   = absint( $owner['id'] ?? 0 );
			if ( ! in_array( $type, [ Entity::CONTACT, Entity::ORGANIZATION ], true ) || $id <= 0 ) {
				continue;
			}

			$normalized[ $type . ':' . $id ] = [ 'type' => $type, 'id' => $id ];
			if ( count( $normalized ) >= self::MAX_BATCH_OWNERS ) {
				break;
			}
		}
		if ( [] === $normalized ) {
			return [];
		}

		$ids = array_values(
			array_unique(
				array_map(
					static fn( array $owner ): int => $owner['id'],
					array_values( $normalized )
				)
			)
		);
		$posts = get_posts( [
			'post_type'              => [ PostTypes::CONTACT, PostTypes::ORGANIZATION ],
			'post_status'            => 'any',
			'post__in'               => $ids,
			'posts_per_page'         => count( $ids ),
			'orderby'                => 'post__in',
			'suppress_filters'       => false,
			'ignore_sticky_posts'    => true,
			'no_found_rows'          => true,
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
		] );

		$valid = [];
		foreach ( $posts as $post ) {
			if (
				! $post instanceof \WP_Post
				|| in_array( $post->post_status, [ 'trash', 'auto-draft' ], true )
			) {
				continue;
			}

			$type = PostTypes::CONTACT === $post->post_type
				? Entity::CONTACT
				: ( PostTypes::ORGANIZATION === $post->post_type ? Entity::ORGANIZATION : '' );
			if ( '' !== $type ) {
				$valid[ $type . ':' . (int) $post->ID ] = true;
			}
		}

		if ( Access::is_staff() ) {
			return array_values(
				array_filter(
					$normalized,
					static fn( array $owner ): bool => isset( $valid[ $owner['type'] . ':' . $owner['id'] ] )
				)
			);
		}

		$current_contact = Access::current_contact_id();
		if ( is_wp_error( $current_contact ) ) {
			return new \WP_Error( 'crm_record_not_found' );
		}
		$organization_ids = array_fill_keys( Access::current_organization_ids(), true );

		$authorized = [];
		foreach ( $normalized as $owner ) {
			$key = $owner['type'] . ':' . $owner['id'];
			if ( ! isset( $valid[ $key ] ) ) {
				continue;
			}
			if ( Entity::CONTACT === $owner['type'] && $owner['id'] === $current_contact ) {
				$authorized[] = $owner;
				continue;
			}
			if ( Entity::ORGANIZATION === $owner['type'] && isset( $organization_ids[ $owner['id'] ] ) ) {
				$authorized[] = $owner;
			}
		}

		return $authorized;
	}

	private static function can_read_owner( string $owner_type, int $owner_id ): bool {
		return match ( $owner_type ) {
			Entity::CONTACT      => Access::can_read_contact( $owner_id ),
			Entity::ORGANIZATION => Access::can_read_organization( $owner_id ),
			default              => false,
		};
	}
}

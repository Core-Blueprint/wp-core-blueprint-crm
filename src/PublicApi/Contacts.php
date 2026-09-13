<?php
declare(strict_types=1);

namespace CB\CRM\PublicApi;

use CB\CRM\Content\ContactIdentity;
use CB\CRM\Content\Meta;
use CB\CRM\Content\PostTypes;
use CB\CRM\Content\RecordStatus;
use CB\CRM\Integration\ContactDataSources;
use CB\CRM\Repository\Organizations;

defined( 'ABSPATH' ) || exit;

/**
 * Read-only server-side Contact contract for first-party extension consumers.
 *
 * This API is deliberately independent from the authenticated Frontend query
 * boundary so background workers and scheduled jobs can resolve CRM contacts
 * without impersonating a WordPress user. Consumers remain responsible for
 * authorizing the operation that caused a read.
 *
 * No CRM repository rows, WP_Post objects or WooCommerce objects cross this
 * boundary. Returned data is a stable, minimal projection of CRM-owned identity
 * plus resolved source provenance.
 */
final class Contacts {
	private const DEFAULT_PER_PAGE = 100;
	private const MAX_PER_PAGE = 500;

	/**
	 * @return array{
	 *   contact_id:int,
	 *   display_name:string,
	 *   first_name:string,
	 *   last_name:string,
	 *   email:string,
	 *   email_source:string,
	 *   email_path:string,
	 *   linked_user_id:int,
	 *   status:string,
	 *   tags:string[],
	 *   organization_ids:int[]
	 * }|null
	 */
	public static function get( int $contact_id ): ?array {
		$post = $contact_id > 0 ? get_post( $contact_id ) : null;
		if (
			! $post instanceof \WP_Post
			|| PostTypes::CONTACT !== $post->post_type
			|| in_array( $post->post_status, [ 'auto-draft', 'trash' ], true )
		) {
			return null;
		}

		$first_name = ContactDataSources::name_field( $contact_id, 'first_name' );
		$last_name = ContactDataSources::name_field( $contact_id, 'last_name' );
		$email = ContactDataSources::effective_email_field( $contact_id );

		return [
			'contact_id'       => $contact_id,
			'display_name'     => sanitize_text_field( (string) get_the_title( $post ) ),
			'first_name'       => sanitize_text_field( (string) ( $first_name['value'] ?? '' ) ),
			'last_name'        => sanitize_text_field( (string) ( $last_name['value'] ?? '' ) ),
			'email'            => sanitize_email( (string) ( $email['value'] ?? '' ) ),
			'email_source'     => sanitize_key( (string) ( $email['source'] ?? '' ) ),
			'email_path'       => sanitize_text_field( (string) ( $email['path'] ?? '' ) ),
			'linked_user_id'   => ContactIdentity::linked_user_id( $contact_id ),
			'status'           => RecordStatus::normalize( (string) get_post_meta( $contact_id, Meta::STATUS, true ) ),
			'tags'             => self::tags( $contact_id ),
			'organization_ids' => self::organization_ids( $contact_id ),
		];
	}

	/**
	 * Query live CRM Contacts for a trusted server-side consumer.
	 *
	 * Supported filters are intentionally small and domain-neutral. Marketing
	 * permission, suppression and campaign eligibility do not belong to CRM and
	 * must be applied by the consuming product.
	 *
	 * @param array{
	 *   search?:mixed,
	 *   status?:mixed,
	 *   tag?:mixed,
	 *   organization_id?:mixed,
	 *   include_ids?:mixed,
	 *   page?:mixed,
	 *   per_page?:mixed
	 * } $args
	 * @return array{
	 *   items:array<int,array<string,mixed>>,
	 *   page:int,
	 *   per_page:int,
	 *   total:int,
	 *   total_pages:int
	 * }
	 */
	public static function query( array $args = [] ): array {
		$page = self::positive_int( $args['page'] ?? 1, 1 );
		$per_page = min(
			self::MAX_PER_PAGE,
			self::positive_int( $args['per_page'] ?? self::DEFAULT_PER_PAGE, self::DEFAULT_PER_PAGE )
		);

		$query_args = [
			'post_type'           => PostTypes::CONTACT,
			'post_status'         => [ 'publish', 'draft', 'pending', 'private', 'future' ],
			'posts_per_page'      => $per_page,
			'paged'               => $page,
			'orderby'             => [ 'title' => 'ASC', 'ID' => 'ASC' ],
			'order'               => 'ASC',
			'ignore_sticky_posts' => true,
		];

		$search = sanitize_text_field( trim( self::scalar_string( $args['search'] ?? '' ) ) );
		if ( '' !== $search ) {
			$query_args['s'] = $search;
		}

		$status = sanitize_key( self::scalar_string( $args['status'] ?? '' ) );
		if ( in_array( $status, RecordStatus::VALUES, true ) ) {
			$query_args['meta_query'] = [
				[
					'key'   => Meta::STATUS,
					'value' => $status,
				],
			];
		}

		$tag = sanitize_title( self::scalar_string( $args['tag'] ?? '' ) );
		if ( '' !== $tag ) {
			$query_args['tax_query'] = [
				[
					'taxonomy' => PostTypes::TAG,
					'field'    => 'slug',
					'terms'    => [ $tag ],
				],
			];
		}

		$include_ids = self::include_ids( $args['include_ids'] ?? [] );
		$organization_id = self::positive_int( $args['organization_id'] ?? 0, 0 );
		if ( $organization_id > 0 ) {
			$organization_ids = Organizations::contact_ids_for_organization( $organization_id );
			$include_ids = [] === $include_ids
				? $organization_ids
				: array_values( array_intersect( $include_ids, $organization_ids ) );
			if ( [] === $include_ids ) {
				$include_ids = [ 0 ];
			}
		}
		if ( [] !== $include_ids ) {
			$query_args['post__in'] = $include_ids;
		}

		$query = new \WP_Query( $query_args );
		$items = [];
		foreach ( $query->posts as $post ) {
			if ( ! $post instanceof \WP_Post ) {
				continue;
			}
			$item = self::get( (int) $post->ID );
			if ( null !== $item ) {
				$items[] = $item;
			}
		}

		return [
			'items'       => $items,
			'page'        => $page,
			'per_page'    => $per_page,
			'total'       => max( 0, (int) $query->found_posts ),
			'total_pages' => max( 0, (int) $query->max_num_pages ),
		];
	}

	/** @return string[] */
	private static function tags( int $contact_id ): array {
		$terms = wp_get_object_terms( $contact_id, PostTypes::TAG, [ 'fields' => 'slugs' ] );
		if ( is_wp_error( $terms ) || ! is_array( $terms ) ) {
			return [];
		}
		$out = [];
		foreach ( $terms as $term ) {
			if ( is_scalar( $term ) ) {
				$slug = sanitize_title( (string) $term );
				if ( '' !== $slug ) {
					$out[] = $slug;
				}
			}
		}
		return array_values( array_unique( $out ) );
	}

	/** @return int[] */
	private static function organization_ids( int $contact_id ): array {
		$ids = [];
		foreach ( Organizations::for_contact( $contact_id ) as $relation ) {
			$organization_id = absint( $relation['organization_id'] ?? 0 );
			if ( $organization_id > 0 && PostTypes::ORGANIZATION === get_post_type( $organization_id ) ) {
				$ids[] = $organization_id;
			}
		}
		return array_values( array_unique( $ids ) );
	}

	private static function positive_int( mixed $value, int $default ): int {
		if ( ! is_scalar( $value ) || ! is_numeric( $value ) ) {
			return $default;
		}
		$value = absint( $value );
		return $value > 0 ? $value : $default;
	}

	private static function scalar_string( mixed $value ): string {
		return is_scalar( $value ) ? (string) $value : '';
	}

	/** @return int[] */
	private static function include_ids( mixed $raw ): array {
		$values = is_array( $raw ) ? $raw : ( is_scalar( $raw ) && '' !== (string) $raw ? [ $raw ] : [] );
		$ids = [];
		foreach ( $values as $value ) {
			if ( is_scalar( $value ) && absint( $value ) > 0 ) {
				$ids[] = absint( $value );
			}
		}
		return array_values( array_unique( $ids ) );
	}

	private function __construct() {}
}

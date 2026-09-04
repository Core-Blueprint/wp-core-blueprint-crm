<?php
declare(strict_types=1);

namespace CB\CRM\Frontend\Queries;

use CB\CRM\Capabilities;
use CB\CRM\Content\Entity;
use CB\CRM\Content\Meta;
use CB\CRM\Content\PostTypes;
use CB\CRM\Content\RecordStatus;
use CB\CRM\Frontend\Data\Contact;
use CB\CRM\Repository\Organizations;

defined( 'ABSPATH' ) || exit;

final class Contacts {
	private const DEFAULT_PER_PAGE = 30;
	private const MAX_PER_PAGE     = 100;

	/** @return array<string,mixed>|\WP_Error */
	public static function current_user(): array|\WP_Error {
		return Contact::current();
	}

	/**
	 * @param array{search?:mixed,status?:mixed,tag?:mixed,include_ids?:mixed,page?:mixed,per_page?:mixed} $args
	 * @return array{items:array<int,array<string,mixed>>,page:int,per_page:int,total:int,total_pages:int}|\WP_Error
	 */
	public static function staff( array $args = [] ): array|\WP_Error {
		if ( ! current_user_can( Capabilities::MANAGE ) ) {
			return new \WP_Error( 'crm_forbidden' );
		}
		return self::query( $args );
	}

	/** @return array<int,array<string,mixed>>|\WP_Error */
	public static function for_organization( int $organization_id, int $limit = 30 ): array|\WP_Error {
		if ( ! current_user_can( Capabilities::MANAGE ) ) {
			return new \WP_Error( 'crm_forbidden' );
		}
		if ( $organization_id <= 0 || PostTypes::ORGANIZATION !== get_post_type( $organization_id ) ) {
			return [];
		}
		$ids = Organizations::contact_ids_for_organization( $organization_id );
		if ( [] === $ids ) {
			return [];
		}
		$result = self::query( [ 'include_ids' => $ids, 'per_page' => max( 1, min( self::MAX_PER_PAGE, $limit ) ) ] );
		return $result['items'];
	}

	/** @param array<string,mixed> $args
	 *  @return array{items:array<int,array<string,mixed>>,page:int,per_page:int,total:int,total_pages:int}
	 */
	private static function query( array $args ): array {
		$page     = self::positive_int( $args['page'] ?? 1, 1 );
		$per_page = min( self::MAX_PER_PAGE, self::positive_int( $args['per_page'] ?? self::DEFAULT_PER_PAGE, self::DEFAULT_PER_PAGE ) );
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
			$query_args['meta_query'] = [ [ 'key' => Meta::STATUS, 'value' => $status ] ];
		}
		$tag = sanitize_title( self::scalar_string( $args['tag'] ?? '' ) );
		if ( '' !== $tag ) {
			$query_args['tax_query'] = [ [ 'taxonomy' => PostTypes::TAG, 'field' => 'slug', 'terms' => [ $tag ] ] ];
		}
		$ids = self::include_ids( $args['include_ids'] ?? [] );
		if ( [] !== $ids ) {
			$query_args['post__in'] = $ids;
		}

		$query = new \WP_Query( $query_args );
		$items = [];
		foreach ( $query->posts as $post ) {
			if ( ! $post instanceof \WP_Post ) { continue; }
			$item = Contact::get( (int) $post->ID );
			if ( ! is_wp_error( $item ) ) { $items[] = $item; }
		}
		return [ 'items' => $items, 'page' => $page, 'per_page' => $per_page, 'total' => max( 0, (int) $query->found_posts ), 'total_pages' => max( 0, (int) $query->max_num_pages ) ];
	}

	private static function positive_int( mixed $value, int $default ): int {
		if ( ! is_scalar( $value ) || ! is_numeric( $value ) ) { return $default; }
		$value = absint( $value ); return $value > 0 ? $value : $default;
	}
	private static function scalar_string( mixed $value ): string { return is_scalar( $value ) ? (string) $value : ''; }
	/** @return int[] */
	private static function include_ids( mixed $raw ): array {
		$values = is_array( $raw ) ? $raw : ( is_scalar( $raw ) && '' !== (string) $raw ? [ $raw ] : [] );
		$ids = [];
		foreach ( $values as $value ) { if ( is_scalar( $value ) && absint( $value ) > 0 ) { $ids[] = absint( $value ); } }
		return array_values( array_unique( $ids ) );
	}
}

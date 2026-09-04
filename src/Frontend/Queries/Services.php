<?php
declare(strict_types=1);

namespace CB\CRM\Frontend\Queries;

use CB\CRM\Content\Meta;
use CB\CRM\Content\PostTypes;
use CB\CRM\Content\RecordStatus;
use CB\CRM\Frontend\Access;
use CB\CRM\Frontend\Data\Service;

defined( 'ABSPATH' ) || exit;

final class Services {
	private const DEFAULT_PER_PAGE = 30;
	private const MAX_PER_PAGE     = 100;

	/**
	 * Staff receive the service catalog. Non-staff authenticated users receive
	 * only services actively assigned to their uniquely linked Contact or an
	 * active related Organization.
	 *
	 * @param array{search?:mixed,status?:mixed,include_ids?:mixed,page?:mixed,per_page?:mixed} $args
	 * @return array{items:array<int,array<string,mixed>>,page:int,per_page:int,total:int,total_pages:int}|\WP_Error
	 */
	public static function query( array $args = [] ): array|\WP_Error {
		if ( ! Access::is_staff() && get_current_user_id() <= 0 ) {
			return new \WP_Error( 'crm_login_required' );
		}

		$page     = self::positive_int( $args['page'] ?? 1, 1 );
		$per_page = min( self::MAX_PER_PAGE, self::positive_int( $args['per_page'] ?? self::DEFAULT_PER_PAGE, self::DEFAULT_PER_PAGE ) );
		$query_args = [
			'post_type' => PostTypes::SERVICE,
			'post_status' => [ 'publish', 'draft', 'pending', 'private', 'future' ],
			'posts_per_page' => $per_page,
			'paged' => $page,
			'orderby' => [ 'title' => 'ASC', 'ID' => 'ASC' ],
			'order' => 'ASC',
			'ignore_sticky_posts' => true,
		];

		$allowed_ids = Access::is_staff() ? [] : Access::current_service_ids();
		if ( ! Access::is_staff() && [] === $allowed_ids ) {
			return [ 'items' => [], 'page' => $page, 'per_page' => $per_page, 'total' => 0, 'total_pages' => 0 ];
		}
		$requested_ids = self::include_ids( $args['include_ids'] ?? [] );
		if ( ! Access::is_staff() ) {
			$requested_ids = [] === $requested_ids ? $allowed_ids : array_values( array_intersect( $requested_ids, $allowed_ids ) );
			if ( [] === $requested_ids ) {
				return [ 'items' => [], 'page' => $page, 'per_page' => $per_page, 'total' => 0, 'total_pages' => 0 ];
			}
			$query_args['post__in'] = $requested_ids;
		} elseif ( [] !== $requested_ids ) {
			$query_args['post__in'] = $requested_ids;
		}

		$search = sanitize_text_field( trim( self::scalar_string( $args['search'] ?? '' ) ) );
		if ( '' !== $search ) { $query_args['s'] = $search; }
		$status = sanitize_key( self::scalar_string( $args['status'] ?? '' ) );
		if ( in_array( $status, RecordStatus::VALUES, true ) ) { $query_args['meta_query'] = [ [ 'key' => Meta::STATUS, 'value' => $status ] ]; }

		$query = new \WP_Query( $query_args );
		$items = [];
		foreach ( $query->posts as $post ) {
			if ( ! $post instanceof \WP_Post ) { continue; }
			if ( ! Access::is_staff() && ! in_array( (int) $post->ID, $allowed_ids, true ) ) { continue; }
			$item = Service::get( (int) $post->ID );
			if ( ! is_wp_error( $item ) ) { $items[] = $item; }
		}
		return [ 'items' => $items, 'page' => $page, 'per_page' => $per_page, 'total' => max( 0, (int) $query->found_posts ), 'total_pages' => max( 0, (int) $query->max_num_pages ) ];
	}

	private static function positive_int( mixed $value, int $default ): int { if ( ! is_scalar( $value ) || ! is_numeric( $value ) ) { return $default; } $value = absint( $value ); return $value > 0 ? $value : $default; }
	private static function scalar_string( mixed $value ): string { return is_scalar( $value ) ? (string) $value : ''; }
	/** @return int[] */
	private static function include_ids( mixed $raw ): array { $values = is_array( $raw ) ? $raw : ( is_scalar( $raw ) && '' !== (string) $raw ? [ $raw ] : [] ); $ids = []; foreach ( $values as $value ) { if ( is_scalar( $value ) && absint( $value ) > 0 ) { $ids[] = absint( $value ); } } return array_values( array_unique( $ids ) ); }
}

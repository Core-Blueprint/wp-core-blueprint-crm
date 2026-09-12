<?php
declare(strict_types=1);

namespace CB\CRM\Admin;

use CB\CRM\Content\ContactIdentity;
use CB\CRM\Content\Meta;
use CB\CRM\Content\PostTypes;

defined( 'ABSPATH' ) || exit;

final class UserLinkDirectory {
	public static function init(): void {
		add_action( 'pre_user_query', [ __CLASS__, 'scope_user_query' ] );
	}

	/** @param int[] $contact_ids */
	public static function classify( array $contact_ids ): string {
		$count = count( array_values( array_unique( array_filter( array_map( 'absint', $contact_ids ) ) ) ) );
		if ( 0 === $count ) {
			return 'unlinked';
		}
		return 1 === $count ? 'linked' : 'conflict';
	}

	/** @param array<string,mixed> $args */
	public static function apply_status_scope( array &$args, string $status ): void {
		if ( in_array( $status, [ 'linked', 'unlinked', 'conflict' ], true ) ) {
			$args['cb_crm_link_status'] = $status;
		}
	}

	public static function scope_user_query( \WP_User_Query $query ): void {
		$status = isset( $query->query_vars['cb_crm_link_status'] ) ? sanitize_key( (string) $query->query_vars['cb_crm_link_status'] ) : '';
		if ( ! in_array( $status, [ 'linked', 'unlinked', 'conflict' ], true ) || str_contains( $query->query_from, 'cb_crm_link_state' ) ) {
			return;
		}

		global $wpdb;
		$subquery = $wpdb->prepare(
			"SELECT CAST(pm.meta_value AS UNSIGNED) AS user_id, COUNT(DISTINCT p.ID) AS link_count
			FROM {$wpdb->posts} p
			INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID
			WHERE p.post_type = %s
			AND p.post_status <> 'auto-draft'
			AND pm.meta_key = %s
			AND CAST(pm.meta_value AS UNSIGNED) > 0
			GROUP BY CAST(pm.meta_value AS UNSIGNED)",
			PostTypes::CONTACT,
			Meta::WP_USER_ID
		);
		$query->query_from .= " LEFT JOIN ({$subquery}) cb_crm_link_state ON cb_crm_link_state.user_id = {$wpdb->users}.ID";
		$query->query_where .= match ( $status ) {
			'linked'   => ' AND cb_crm_link_state.link_count = 1',
			'conflict' => ' AND cb_crm_link_state.link_count > 1',
			default    => ' AND cb_crm_link_state.user_id IS NULL',
		};
	}

	/** @param int[] $user_ids @return array<int,int[]> */
	public static function links_for_users( array $user_ids ): array {
		global $wpdb;
		$user_ids = array_values( array_unique( array_filter( array_map( 'absint', $user_ids ) ) ) );
		if ( [] === $user_ids ) {
			return [];
		}
		$placeholders = implode( ',', array_fill( 0, count( $user_ids ), '%d' ) );
		$sql = "SELECT CAST(pm.meta_value AS UNSIGNED) AS user_id, p.ID AS contact_id
			FROM {$wpdb->posts} p
			INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID
			WHERE p.post_type = %s
			AND p.post_status <> 'auto-draft'
			AND pm.meta_key = %s
			AND CAST(pm.meta_value AS UNSIGNED) IN ({$placeholders})
			ORDER BY p.ID ASC";
		$prepared = $wpdb->prepare( $sql, PostTypes::CONTACT, Meta::WP_USER_ID, ...$user_ids );
		$rows = $wpdb->get_results( $prepared, ARRAY_A );
		$map = [];
		foreach ( is_array( $rows ) ? $rows : [] as $row ) {
			$user_id = absint( $row['user_id'] ?? 0 );
			$contact_id = absint( $row['contact_id'] ?? 0 );
			if ( $user_id > 0 && $contact_id > 0 ) {
				$map[ $user_id ][] = $contact_id;
			}
		}
		foreach ( $map as $user_id => $contact_ids ) {
			$map[ $user_id ] = array_values( array_unique( array_map( 'absint', $contact_ids ) ) );
		}
		return $map;
	}

	/** @return int[] */
	public static function candidate_contacts( \WP_User $user, string $search ): array {
		$ids = [];
		$email = is_email( $search ) ? sanitize_email( $search ) : sanitize_email( (string) $user->user_email );
		if ( '' !== $email ) {
			$ids = ContactIdentity::find_by_email( $email );
		}

		$term = '' !== trim( $search ) ? trim( $search ) : trim( (string) $user->display_name );
		if ( '' !== $term ) {
			$query = new \WP_Query( [
				'post_type'      => PostTypes::CONTACT,
				'post_status'    => [ 'publish', 'draft', 'pending', 'private' ],
				'posts_per_page' => 20,
				'orderby'        => 'title',
				'order'          => 'ASC',
				'fields'         => 'ids',
				's'              => $term,
				'no_found_rows'  => true,
			] );
			$ids = array_merge( $ids, array_map( 'absint', $query->posts ) );
		}

		$candidates = [];
		foreach ( array_values( array_unique( array_filter( array_map( 'absint', $ids ) ) ) ) as $contact_id ) {
			$post = get_post( $contact_id );
			if ( ! $post instanceof \WP_Post || PostTypes::CONTACT !== $post->post_type || in_array( $post->post_status, [ 'auto-draft', 'trash' ], true ) ) {
				continue;
			}
			$linked_user_id = ContactIdentity::linked_user_id( $contact_id );
			if ( $linked_user_id > 0 && $linked_user_id !== (int) $user->ID ) {
				continue;
			}
			if ( ! ContactIdentity::can_link_user_to_contact( (int) $user->ID, $contact_id ) ) {
				continue;
			}
			$candidates[] = $contact_id;
		}
		return array_slice( $candidates, 0, 20 );
	}
}

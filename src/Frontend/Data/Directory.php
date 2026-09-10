<?php
declare(strict_types=1);

namespace CB\CRM\Frontend\Data;

use CB\CRM\Content\PostTypes;
use CB\CRM\Frontend\Access;

defined( 'ABSPATH' ) || exit;

/** Public, staff-governed lookup contract for consumer selectors. */
final class Directory {
	/**
	 * @return array<int,array{type:string,id:int,label:string,secondary:string}>|\WP_Error
	 */
	public static function search( string $term = '', int $limit = 20 ): array|\WP_Error {
		if ( ! Access::is_staff() ) {
			return new \WP_Error( 'crm_directory_forbidden', __( 'CRM directory lookup requires staff access.', 'core-blueprint-crm' ) );
		}

		$term  = trim( sanitize_text_field( $term ) );
		$limit = max( 1, min( 50, $limit ) );
		$args  = [
			'post_type'      => [ PostTypes::CONTACT, PostTypes::ORGANIZATION ],
			'post_status'    => 'any',
			'posts_per_page' => $limit,
			'orderby'        => 'title',
			'order'          => 'ASC',
			'fields'         => 'ids',
			'no_found_rows'  => true,
		];
		if ( '' !== $term ) {
			$args['s'] = $term;
		}

		$query = new \WP_Query( $args );
		$items = [];
		foreach ( $query->posts as $rawId ) {
			$id   = absint( $rawId );
			$post = $id > 0 ? get_post( $id ) : null;
			if ( ! $post instanceof \WP_Post ) {
				continue;
			}

			if ( PostTypes::ORGANIZATION === $post->post_type ) {
				$data = Organization::get( $id );
				if ( is_wp_error( $data ) ) {
					continue;
				}
				$label     = trim( (string) ( $data['name'] ?? '' ) );
				$secondary = trim( (string) ( $data['legal_name'] ?? '' ) );
				$type      = 'organization';
			} elseif ( PostTypes::CONTACT === $post->post_type ) {
				$data = Contact::get( $id );
				if ( is_wp_error( $data ) ) {
					continue;
				}
				$label     = trim( (string) ( $data['display_name'] ?? '' ) );
				$secondary = trim( (string) ( $data['primary_email'] ?? '' ) );
				$type      = 'contact';
			} else {
				continue;
			}

			if ( '' === $label ) {
				continue;
			}
			$items[] = [
				'type'      => $type,
				'id'        => $id,
				'label'     => sanitize_text_field( $label ),
				'secondary' => sanitize_text_field( $secondary ),
			];
		}
		return $items;
	}
}

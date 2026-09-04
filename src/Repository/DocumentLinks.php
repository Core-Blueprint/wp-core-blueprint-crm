<?php
declare(strict_types=1);

namespace CB\CRM\Repository;

use CB\CRM\Content\Entity;
use CB\CRM\Database\Schema;

defined( 'ABSPATH' ) || exit;

final class DocumentLinks {
	private const MAX_LIMIT = 100;

	/** @return array<int,array<string,mixed>> */
	public static function for_owner( string $owner_type, int $owner_id, int $limit = self::MAX_LIMIT ): array {
		global $wpdb;

		if ( ! Entity::valid_owner( $owner_type, $owner_id, false ) ) {
			return [];
		}

		$limit = max( 1, min( self::MAX_LIMIT, $limit ) );
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM ' . Schema::document_links_table() . ' WHERE owner_type = %s AND owner_id = %d ORDER BY id ASC LIMIT %d',
				$owner_type,
				$owner_id,
				$limit
			),
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : [];
	}

	/** @return array<int,array<string,mixed>> */
	public static function for_document( int $document_id, int $limit = self::MAX_LIMIT ): array {
		global $wpdb;

		if ( $document_id <= 0 ) {
			return [];
		}

		$limit = max( 1, min( self::MAX_LIMIT, $limit ) );
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM ' . Schema::document_links_table() . ' WHERE document_id = %d ORDER BY owner_type ASC, owner_id ASC, id ASC LIMIT %d',
				$document_id,
				$limit
			),
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : [];
	}

	/** @return array<string,mixed>|null */
	public static function get( string $owner_type, int $owner_id, int $document_id ): ?array {
		global $wpdb;

		if ( ! Entity::valid_owner( $owner_type, $owner_id, false ) || $document_id <= 0 ) {
			return null;
		}

		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM ' . Schema::document_links_table() . ' WHERE owner_type = %s AND owner_id = %d AND document_id = %d LIMIT 1',
				$owner_type,
				$owner_id,
				$document_id
			),
			ARRAY_A
		);

		return is_array( $row ) ? $row : null;
	}

	/**
	 * @return array{row:array<string,mixed>,created:bool,changed:bool}|\WP_Error
	 */
	public static function link(
		string $owner_type,
		int $owner_id,
		int $document_id,
		string $relation_type = '',
		string $notes = '',
		int $created_by = 0
	): array|\WP_Error {
		global $wpdb;

		if ( ! Entity::valid_owner( $owner_type, $owner_id, false ) || $document_id <= 0 ) {
			return new \WP_Error( 'crm_document_link_invalid_target' );
		}

		$relation_type = substr( sanitize_text_field( $relation_type ), 0, 64 );
		$notes         = substr( sanitize_textarea_field( $notes ), 0, 2000 );
		$existing      = self::get( $owner_type, $owner_id, $document_id );

		if ( is_array( $existing ) ) {
			$stored_relation = sanitize_text_field( (string) ( $existing['relation_type'] ?? '' ) );
			$stored_notes    = sanitize_textarea_field( (string) ( $existing['notes'] ?? '' ) );
			if ( $stored_relation === $relation_type && $stored_notes === $notes ) {
				return [ 'row' => $existing, 'created' => false, 'changed' => false ];
			}

			$updated = $wpdb->update(
				Schema::document_links_table(),
				[
					'relation_type' => $relation_type,
					'notes'         => '' !== $notes ? $notes : null,
				],
				[ 'id' => (int) $existing['id'] ],
				[ '%s', '%s' ],
				[ '%d' ]
			);
			if ( false === $updated ) {
				return new \WP_Error( 'crm_document_link_write_failed' );
			}

			$row = self::get( $owner_type, $owner_id, $document_id );
			return is_array( $row )
				? [ 'row' => $row, 'created' => false, 'changed' => true ]
				: new \WP_Error( 'crm_document_link_write_failed' );
		}

		$inserted = $wpdb->insert(
			Schema::document_links_table(),
			[
				'owner_type'    => $owner_type,
				'owner_id'      => $owner_id,
				'document_id'   => $document_id,
				'relation_type' => $relation_type,
				'notes'         => '' !== $notes ? $notes : null,
				'created_at'    => current_time( 'mysql', true ),
				'created_by'    => max( 0, $created_by ),
			],
			[ '%s', '%d', '%d', '%s', '%s', '%s', '%d' ]
		);

		if ( false === $inserted ) {
			// A concurrent idempotent insert may have won the unique key.
			$row = self::get( $owner_type, $owner_id, $document_id );
			return is_array( $row )
				? [ 'row' => $row, 'created' => false, 'changed' => false ]
				: new \WP_Error( 'crm_document_link_write_failed' );
		}

		$row = self::get( $owner_type, $owner_id, $document_id );
		return is_array( $row )
			? [ 'row' => $row, 'created' => true, 'changed' => true ]
			: new \WP_Error( 'crm_document_link_write_failed' );
	}

	public static function unlink( string $owner_type, int $owner_id, int $document_id ): bool {
		global $wpdb;

		if ( ! Entity::valid_owner( $owner_type, $owner_id, false ) || $document_id <= 0 ) {
			return false;
		}

		return false !== $wpdb->delete(
			Schema::document_links_table(),
			[
				'owner_type'  => $owner_type,
				'owner_id'    => $owner_id,
				'document_id' => $document_id,
			],
			[ '%s', '%d', '%d' ]
		);
	}

	public static function delete_for_owner( string $owner_type, int $owner_id ): bool {
		global $wpdb;

		if ( ! in_array( $owner_type, [ Entity::CONTACT, Entity::ORGANIZATION ], true ) || $owner_id <= 0 ) {
			return false;
		}

		return false !== $wpdb->delete(
			Schema::document_links_table(),
			[ 'owner_type' => $owner_type, 'owner_id' => $owner_id ],
			[ '%s', '%d' ]
		);
	}

	public static function delete_for_document( int $document_id ): bool {
		global $wpdb;

		if ( $document_id <= 0 ) {
			return false;
		}

		return false !== $wpdb->delete(
			Schema::document_links_table(),
			[ 'document_id' => $document_id ],
			[ '%d' ]
		);
	}
}

<?php
declare(strict_types=1);

namespace CB\CRM\Repository;

use CB\CRM\Content\Entity;
use CB\CRM\Database\Schema;

defined( 'ABSPATH' ) || exit;

final class BusinessIdentifiers {
	public const TYPES = [ 'vat', 'registration_number', 'eori', 'other' ];
	public const CANONICAL_TYPES = [ 'vat', 'registration_number', 'eori' ];
	private const MAX_ROWS = 50;

	/** @return array<int,array<string,mixed>> */
	public static function for_organization( int $organization_id ): array {
		global $wpdb;
		if ( $organization_id <= 0 ) {
			return [];
		}
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM ' . Schema::business_identifiers_table() . ' WHERE organization_id = %d ORDER BY sort_order ASC, id ASC',
				$organization_id
			),
			ARRAY_A
		);
		return is_array( $rows ) ? $rows : [];
	}

	/** @param array<int,array<string,mixed>> $rows */
	public static function replace( int $organization_id, array $rows ): bool {
		global $wpdb;
		if ( ! Entity::valid_owner( Entity::ORGANIZATION, $organization_id ) ) {
			return false;
		}

		$normalized = self::normalize_rows( $rows );
		$now = current_time( 'mysql', true );
		$wpdb->query( 'START TRANSACTION' );

		if ( false === $wpdb->delete( Schema::business_identifiers_table(), [ 'organization_id' => $organization_id ], [ '%d' ] ) ) {
			$wpdb->query( 'ROLLBACK' );
			return false;
		}

		foreach ( $normalized as $row ) {
			$inserted = $wpdb->insert(
				Schema::business_identifiers_table(),
				[
					'organization_id' => $organization_id,
					'identifier_type' => $row['identifier_type'],
					'value' => $row['value'],
					'country' => $row['country'],
					'label' => $row['label'],
					'is_primary' => $row['is_primary'],
					'sort_order' => $row['sort_order'],
					'created_at' => $now,
					'updated_at' => $now,
				],
				[ '%d', '%s', '%s', '%s', '%s', '%d', '%d', '%s', '%s' ]
			);
			if ( false === $inserted ) {
				$wpdb->query( 'ROLLBACK' );
				return false;
			}
		}

		$wpdb->query( 'COMMIT' );
		return true;
	}

	/** @return array<string,string> */
	public static function primary_map( int $organization_id ): array {
		return self::canonical_map( self::for_organization( $organization_id ) );
	}

	/** @param array<int,array<string,mixed>> $rows
	 *  @return array<string,string>
	 */
	public static function canonical_map( array $rows ): array {
		$fallback = [];
		$primary = [];
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$type = sanitize_key( (string) ( $row['identifier_type'] ?? '' ) );
			$value = trim( sanitize_text_field( (string) ( $row['value'] ?? '' ) ) );
			if ( ! in_array( $type, self::CANONICAL_TYPES, true ) || '' === $value ) {
				continue;
			}
			if ( ! isset( $fallback[ $type ] ) ) {
				$fallback[ $type ] = $value;
			}
			if ( ! empty( $row['is_primary'] ) && ! isset( $primary[ $type ] ) ) {
				$primary[ $type ] = $value;
			}
		}

		$result = [];
		foreach ( self::CANONICAL_TYPES as $type ) {
			$value = $primary[ $type ] ?? $fallback[ $type ] ?? '';
			if ( '' !== $value ) {
				$result[ $type ] = $value;
			}
		}
		return $result;
	}

	/** @param array<int,array<string,mixed>> $rows
	 *  @return array<int,array<string,mixed>>
	 */
	private static function normalize_rows( array $rows ): array {
		$normalized = [];
		$primary_seen = [];
		$dedupe = [];

		foreach ( array_slice( $rows, 0, self::MAX_ROWS ) as $index => $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$type = sanitize_key( (string) ( $row['identifier_type'] ?? '' ) );
			if ( ! in_array( $type, self::TYPES, true ) ) {
				continue;
			}
			$value = substr( trim( sanitize_text_field( (string) ( $row['value'] ?? '' ) ) ), 0, 190 );
			$label = substr( trim( sanitize_text_field( (string) ( $row['label'] ?? '' ) ) ), 0, 100 );
			$country = self::country( (string) ( $row['country'] ?? '' ) );
			if ( '' === $value || ( 'other' === $type && '' === $label ) ) {
				continue;
			}

			$dedupe_key = $type . '|' . $country . '|' . strtolower( $value );
			if ( isset( $dedupe[ $dedupe_key ] ) ) {
				continue;
			}
			$dedupe[ $dedupe_key ] = true;

			$is_primary = ! empty( $row['is_primary'] ) && empty( $primary_seen[ $type ] );
			if ( $is_primary ) {
				$primary_seen[ $type ] = true;
			}

			$normalized[] = [
				'identifier_type' => $type,
				'value' => $value,
				'country' => $country,
				'label' => $label,
				'is_primary' => $is_primary ? 1 : 0,
				'sort_order' => (int) $index,
			];
		}
		return $normalized;
	}

	private static function country( string $country ): string {
		$country = strtoupper( preg_replace( '/[^A-Za-z]/', '', $country ) ?? '' );
		return 2 === strlen( $country ) ? $country : '';
	}
}

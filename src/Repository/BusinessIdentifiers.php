<?php
declare(strict_types=1);

namespace CB\CRM\Repository;

use CB\CRM\Content\Meta;
use CB\CRM\Content\PostTypes;

defined( 'ABSPATH' ) || exit;

/** Canonical organization business identifiers stored as one bounded map. */
final class BusinessIdentifiers {
	private const MAX_IDENTIFIERS = 32;

	/** @return array<string,string> */
	public static function for_organization( int $organization_id ): array {
		if ( $organization_id < 1 || PostTypes::ORGANIZATION !== get_post_type( $organization_id ) ) {
			return [];
		}

		$raw = get_post_meta( $organization_id, Meta::BUSINESS_IDENTIFIERS, true );
		if ( ! is_array( $raw ) ) {
			return [];
		}

		return self::normalize( $raw ) ?? [];
	}

	/** @param array<mixed> $values */
	public static function replace( int $organization_id, array $values ): bool {
		if ( $organization_id < 1 || PostTypes::ORGANIZATION !== get_post_type( $organization_id ) ) {
			return false;
		}

		$normalized = self::normalize( $values );
		if ( null === $normalized ) {
			return false;
		}

		$before = self::for_organization( $organization_id );
		if ( $before === $normalized ) {
			return true;
		}

		if ( [] === $normalized ) {
			delete_post_meta( $organization_id, Meta::BUSINESS_IDENTIFIERS );
			return true;
		}

		return false !== update_post_meta( $organization_id, Meta::BUSINESS_IDENTIFIERS, $normalized );
	}

	/**
	 * Sanitizer for registered post meta. Invalid values fail closed to an empty map.
	 *
	 * @return array<string,string>
	 */
	public static function sanitize_meta( mixed $value ): array {
		if ( ! is_array( $value ) ) {
			return [];
		}
		return self::normalize( $value ) ?? [];
	}

	/**
	 * Accepts either an associative map or admin rows shaped as {key,value}.
	 *
	 * @param array<mixed> $values
	 * @return array<string,string>|null
	 */
	public static function normalize( array $values ): ?array {
		$map = [];
		$is_list = array_is_list( $values );

		foreach ( $values as $key => $value ) {
			if ( $is_list ) {
				if ( ! is_array( $value ) ) {
					return null;
				}
				$raw_key   = $value['key'] ?? '';
				$raw_value = $value['value'] ?? '';
			} else {
				$raw_key   = $key;
				$raw_value = $value;
			}

			if ( ! is_scalar( $raw_key ) || ! is_scalar( $raw_value ) ) {
				return null;
			}

			$key_string   = trim( (string) $raw_key );
			$value_string = trim( (string) $raw_value );
			if ( '' === $key_string && '' === $value_string ) {
				continue;
			}
			if ( '' === $key_string || '' === $value_string ) {
				return null;
			}

			$canonical_key = strtolower( $key_string );
			$canonical_key = (string) preg_replace( '/\s+/', '_', $canonical_key );
			if ( 1 !== preg_match( '/^[a-z0-9][a-z0-9._-]{0,63}$/D', $canonical_key ) ) {
				return null;
			}

			$clean_value = sanitize_text_field( $value_string );
			if ( '' === $clean_value || strlen( $clean_value ) > 191 || isset( $map[ $canonical_key ] ) ) {
				return null;
			}

			$map[ $canonical_key ] = $clean_value;
			if ( count( $map ) > self::MAX_IDENTIFIERS ) {
				return null;
			}
		}

		ksort( $map, SORT_STRING );
		return $map;
	}
}

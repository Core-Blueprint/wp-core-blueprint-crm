<?php
declare(strict_types=1);

namespace CB\CRM\Repository;

use CB\CRM\Content\Entity;
use CB\CRM\Database\Schema;
use CB\Work\PublicApi\Services as WorkServices;
use CB\Work\PublicApi\TaxRates as WorkTaxRates;

defined( 'ABSPATH' ) || exit;

/** CRM-owned customer agreements that reference Work-owned services and VAT. */
final class ServiceAgreements {
	public const STATUSES = [ 'active', 'paused', 'ended' ];
	public const PRICING_INHERIT = 'inherit';
	public const PRICING_CUSTOM  = 'custom';

	/** @return array<string,string> */
	public static function status_labels(): array {
		return [
			'active' => __( 'Active', 'core-blueprint-crm' ),
			'paused' => __( 'Paused', 'core-blueprint-crm' ),
			'ended'  => __( 'Ended', 'core-blueprint-crm' ),
		];
	}

	/** @return array<int,array<string,mixed>> */
	public static function for_owner( string $customer_type, int $customer_id ): array {
		global $wpdb;
		if ( ! Entity::valid_owner( $customer_type, $customer_id ) ) {
			return [];
		}

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM ' . Schema::service_agreements_table() . ' WHERE customer_type = %s AND customer_id = %d ORDER BY status = "active" DESC, valid_from DESC, id DESC',
				$customer_type,
				$customer_id
			),
			ARRAY_A
		);
		return is_array( $rows ) ? $rows : [];
	}

	/** @return array<string,mixed>|null */
	public static function effective_for( string $customer_type, int $customer_id, int $service_id, string $effective_at ): ?array {
		global $wpdb;
		if ( ! Entity::valid_owner( $customer_type, $customer_id ) || $service_id <= 0 || null === self::work_service( $service_id ) ) {
			return null;
		}

		$date = self::date( $effective_at );
		if ( null === $date ) {
			return null;
		}

		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM ' . Schema::service_agreements_table() . ' WHERE customer_type = %s AND customer_id = %d AND work_service_id = %d AND status = %s AND (valid_from IS NULL OR valid_from <= %s) AND (valid_until IS NULL OR valid_until >= %s) ORDER BY valid_from DESC, id DESC LIMIT 1',
				$customer_type,
				$customer_id,
				$service_id,
				'active',
				$date,
				$date
			),
			ARRAY_A
		);
		return is_array( $row ) ? $row : null;
	}

	/**
	 * Replace the customer's submitted agreement set while preserving stable IDs
	 * for existing rows. Stable agreement identity is part of pricing provenance.
	 *
	 * @param array<int,array<string,mixed>> $rows
	 */
	public static function replace( string $customer_type, int $customer_id, array $rows ): bool {
		global $wpdb;
		if ( ! Entity::valid_owner( $customer_type, $customer_id ) || ! self::work_available() ) {
			return false;
		}

		$existing = [];
		foreach ( self::for_owner( $customer_type, $customer_id ) as $row ) {
			$id = absint( $row['id'] ?? 0 );
			if ( $id > 0 ) {
				$existing[ $id ] = $row;
			}
		}

		$normalized = [];
		$seen_ids   = [];
		foreach ( array_slice( $rows, 0, 50 ) as $row ) {
			if ( ! is_array( $row ) ) {
				return false;
			}

			$id = absint( $row['id'] ?? 0 );
			if ( $id > 0 ) {
				if ( ! isset( $existing[ $id ] ) || isset( $seen_ids[ $id ] ) ) {
					return false;
				}
				$seen_ids[ $id ] = true;
			}

			$service_id = absint( $row['work_service_id'] ?? 0 );
			if ( $service_id <= 0 ) {
				return false;
			}

			$stored = $id > 0 ? $existing[ $id ] : null;
			$stored_service_id = is_array( $stored ) ? absint( $stored['work_service_id'] ?? 0 ) : 0;
			if ( null === self::work_service( $service_id ) && $service_id !== $stored_service_id ) {
				return false;
			}

			$status = sanitize_key( (string) ( $row['status'] ?? 'active' ) );
			if ( ! in_array( $status, self::STATUSES, true ) ) {
				return false;
			}

			$valid_from  = self::date_or_empty( $row['valid_from'] ?? '' );
			$valid_until = self::date_or_empty( $row['valid_until'] ?? '' );
			if ( false === $valid_from || false === $valid_until ) {
				return false;
			}
			if ( null !== $valid_from && null !== $valid_until && $valid_from > $valid_until ) {
				return false;
			}

			$pricing_mode = sanitize_key( (string) ( $row['pricing_mode'] ?? self::PRICING_INHERIT ) );
			if ( ! in_array( $pricing_mode, [ self::PRICING_INHERIT, self::PRICING_CUSTOM ], true ) ) {
				return false;
			}

			$custom_amount_minor = null;
			$custom_currency     = null;
			$custom_tax_mode     = null;
			$custom_tax_rate_id  = null;

			if ( self::PRICING_CUSTOM === $pricing_mode ) {
				$amount_raw = trim( (string) ( $row['custom_amount'] ?? '' ) );
				if ( '' !== $amount_raw ) {
					$custom_amount_minor = self::parse_amount_minor( $amount_raw );
					if ( null === $custom_amount_minor ) {
						return false;
					}
				}

				$currency_raw = trim( (string) ( $row['custom_currency'] ?? '' ) );
				if ( '' !== $currency_raw ) {
					$custom_currency = self::currency( $currency_raw );
					if ( null === $custom_currency ) {
						return false;
					}
				}

				$tax_mode_raw = sanitize_key( (string) ( $row['custom_tax_mode'] ?? '' ) );
				if ( '' !== $tax_mode_raw ) {
					if ( ! in_array( $tax_mode_raw, [ 'exclusive', 'inclusive', 'exempt' ], true ) ) {
						return false;
					}
					$custom_tax_mode = $tax_mode_raw;
				}

				$rate_id = absint( $row['custom_tax_rate_id'] ?? 0 );
				if ( 'exempt' === $custom_tax_mode ) {
					$custom_tax_rate_id = 0;
				} elseif ( $rate_id > 0 ) {
					$stored_rate_id = is_array( $stored ) ? absint( $stored['custom_tax_rate_id'] ?? 0 ) : 0;
					if ( ! WorkTaxRates::is_available( $rate_id ) && $rate_id !== $stored_rate_id ) {
						return false;
					}
					$custom_tax_rate_id = $rate_id;
				}
			}

			$normalized[] = [
				'id'                    => $id,
				'work_service_id'       => $service_id,
				'status'                => $status,
				'valid_from'            => $valid_from,
				'valid_until'           => $valid_until,
				'pricing_mode'          => $pricing_mode,
				'custom_amount_minor'   => $custom_amount_minor,
				'custom_currency'       => $custom_currency,
				'custom_tax_mode'       => $custom_tax_mode,
				'custom_tax_rate_id'    => $custom_tax_rate_id,
				'notes'                 => sanitize_textarea_field( (string) ( $row['notes'] ?? '' ) ),
			];
		}

		$now = current_time( 'mysql', true );
		$wpdb->query( 'START TRANSACTION' );
		try {
			$retained_ids = [];
			foreach ( $normalized as $row ) {
				$data = [
					'work_service_id'     => $row['work_service_id'],
					'customer_type'       => $customer_type,
					'customer_id'         => $customer_id,
					'status'              => $row['status'],
					'valid_from'          => $row['valid_from'],
					'valid_until'         => $row['valid_until'],
					'pricing_mode'        => $row['pricing_mode'],
					'custom_amount_minor' => $row['custom_amount_minor'],
					'custom_currency'     => $row['custom_currency'],
					'custom_tax_mode'     => $row['custom_tax_mode'],
					'custom_tax_rate_id'  => $row['custom_tax_rate_id'],
					'notes'               => $row['notes'],
					'updated_at'          => $now,
				];

				if ( $row['id'] > 0 ) {
					$updated = $wpdb->update(
						Schema::service_agreements_table(),
						$data,
						[ 'id' => $row['id'], 'customer_type' => $customer_type, 'customer_id' => $customer_id ],
						[ '%d', '%s', '%d', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%d', '%s', '%s' ],
						[ '%d', '%s', '%d' ]
					);
					if ( false === $updated ) {
						throw new \RuntimeException( 'agreement_update_failed' );
					}
					$retained_ids[ $row['id'] ] = true;
					continue;
				}

				$data['created_at'] = $now;
				$inserted = $wpdb->insert(
					Schema::service_agreements_table(),
					$data,
					[ '%d', '%s', '%d', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%d', '%s', '%s', '%s' ]
				);
				if ( false === $inserted ) {
					throw new \RuntimeException( 'agreement_insert_failed' );
				}
			}

			foreach ( array_keys( $existing ) as $existing_id ) {
				if ( isset( $retained_ids[ $existing_id ] ) ) {
					continue;
				}
				$deleted = $wpdb->delete(
					Schema::service_agreements_table(),
					[ 'id' => $existing_id, 'customer_type' => $customer_type, 'customer_id' => $customer_id ],
					[ '%d', '%s', '%d' ]
				);
				if ( false === $deleted ) {
					throw new \RuntimeException( 'agreement_delete_failed' );
				}
			}

			$wpdb->query( 'COMMIT' );
			return true;
		} catch ( \Throwable $e ) {
			unset( $e );
			$wpdb->query( 'ROLLBACK' );
			return false;
		}
	}

	/** @param array<string,mixed> $agreement @return array<string,mixed> */
	public static function pricing_projection( array $agreement ): array {
		if ( self::PRICING_CUSTOM !== sanitize_key( (string) ( $agreement['pricing_mode'] ?? '' ) ) ) {
			return [];
		}

		$pricing = [];
		if ( null !== ( $agreement['custom_amount_minor'] ?? null ) ) {
			$pricing['amount_minor'] = max( 0, (int) $agreement['custom_amount_minor'] );
		}
		if ( is_scalar( $agreement['custom_currency'] ?? null ) && '' !== trim( (string) $agreement['custom_currency'] ) ) {
			$pricing['currency'] = (string) $agreement['custom_currency'];
		}
		$tax_mode = sanitize_key( (string) ( $agreement['custom_tax_mode'] ?? '' ) );
		if ( in_array( $tax_mode, [ 'exclusive', 'inclusive', 'exempt' ], true ) ) {
			$pricing['tax_mode'] = $tax_mode;
		}
		if ( null !== ( $agreement['custom_tax_rate_id'] ?? null ) ) {
			$pricing['tax_rate_id'] = absint( $agreement['custom_tax_rate_id'] );
		}
		return $pricing;
	}

	private static function work_available(): bool {
		return class_exists( WorkServices::class ) && class_exists( WorkTaxRates::class );
	}

	/** @return array<string,mixed>|null */
	private static function work_service( int $service_id ): ?array {
		return self::work_available() ? WorkServices::get( $service_id ) : null;
	}

	private static function currency( string $currency ): ?string {
		$value = strtoupper( preg_replace( '/[^A-Za-z]/', '', $currency ) ?? '' );
		return 3 === strlen( $value ) ? $value : null;
	}

	private static function parse_amount_minor( string $value ): ?int {
		$value = str_replace( [ "\xc2\xa0", ' ' ], '', trim( $value ) );
		if ( '' === $value ) {
			return null;
		}
		$comma = strrpos( $value, ',' );
		$dot   = strrpos( $value, '.' );
		if ( false !== $comma && false !== $dot ) {
			if ( $comma > $dot ) {
				$value = str_replace( '.', '', $value );
				$value = str_replace( ',', '.', $value );
			} else {
				$value = str_replace( ',', '', $value );
			}
		} elseif ( false !== $comma ) {
			$value = str_replace( ',', '.', $value );
		}
		if ( 1 !== preg_match( '/^(\d+)(?:\.(\d{1,2}))?$/D', $value, $matches ) ) {
			return null;
		}
		$whole = (int) $matches[1];
		if ( $whole > intdiv( PHP_INT_MAX, 100 ) ) {
			return null;
		}
		$fraction = isset( $matches[2] ) ? str_pad( $matches[2], 2, '0' ) : '00';
		return ( $whole * 100 ) + (int) $fraction;
	}

	/** @return string|null|false */
	private static function date_or_empty( mixed $value ): string|null|false {
		$value = is_scalar( $value ) ? trim( (string) $value ) : '';
		if ( '' === $value ) {
			return null;
		}
		$date = self::date( $value );
		return null === $date ? false : $date;
	}

	private static function date( string $value ): ?string {
		$date = \DateTimeImmutable::createFromFormat( '!Y-m-d', $value );
		return $date && $date->format( 'Y-m-d' ) === $value ? $value : null;
	}
}

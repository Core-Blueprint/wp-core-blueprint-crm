<?php
declare(strict_types=1);
namespace CB\CRM\Repository;

use CB\CRM\Content\Entity;
use CB\CRM\Content\PostTypes;
use CB\CRM\Content\ServicePricing;
use CB\CRM\Database\Schema;

defined( 'ABSPATH' ) || exit;

final class Services {
	public const STATUSES = [ 'active', 'paused', 'ended' ];

	/** @return array<string,string> */
	public static function status_labels(): array {
		return [
			'active' => __( 'Active', 'core-blueprint-crm' ),
			'paused' => __( 'Paused', 'core-blueprint-crm' ),
			'ended'  => __( 'Ended', 'core-blueprint-crm' ),
		];
	}

	/** @return array<int,array<string,mixed>> */
	public static function for_owner( string $owner_type, int $owner_id ): array {
		global $wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM ' . Schema::service_assignments_table() . ' WHERE owner_type = %s AND owner_id = %d ORDER BY status = "active" DESC, started_at DESC, id DESC',
				$owner_type,
				$owner_id
			),
			ARRAY_A
		);
		return is_array( $rows ) ? $rows : [];
	}

	/** @param array<int,array<string,mixed>> $rows */
	public static function replace( string $owner_type, int $owner_id, array $rows ): bool {
		global $wpdb;
		if ( ! Entity::valid_owner( $owner_type, $owner_id, false ) ) {
			return false;
		}

		$normalized = [];
		foreach ( array_slice( $rows, 0, 50 ) as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$service_id = absint( $row['service_id'] ?? 0 );
			if ( $service_id <= 0 || PostTypes::SERVICE !== get_post_type( $service_id ) ) {
				continue;
			}

			$status = sanitize_key( (string) ( $row['status'] ?? 'active' ) );
			if ( ! in_array( $status, self::STATUSES, true ) ) {
				$status = 'active';
			}

			$pricing_mode = sanitize_key( (string) ( $row['pricing_mode'] ?? 'inherit' ) );
			$custom_amount = null;
			$custom_currency = null;
			$custom_tax_mode = null;
			$custom_tax_rate_id = null;
			if ( 'custom' === $pricing_mode ) {
				$amount_raw = trim( (string) ( $row['custom_amount'] ?? '' ) );
				$parsed = ServicePricing::parse_amount_minor( $amount_raw );
				if ( '' !== $amount_raw && null !== $parsed ) {
					$custom_amount = $parsed;
					$custom_currency = ServicePricing::normalize_currency( (string) ( $row['custom_currency'] ?? ServicePricing::DEFAULT_CURRENCY ) );
					$custom_tax_mode = ServicePricing::normalize_tax_mode( (string) ( $row['custom_tax_mode'] ?? ServicePricing::TAX_EXCLUSIVE ) );
					$rate_id = absint( $row['custom_tax_rate_id'] ?? 0 );
					if ( ServicePricing::TAX_EXEMPT !== $custom_tax_mode && TaxRates::get( $rate_id ) ) {
						$custom_tax_rate_id = $rate_id;
					}
				} else {
					$pricing_mode = 'inherit';
				}
			}
			if ( 'custom' !== $pricing_mode ) {
				$pricing_mode = 'inherit';
			}

			$normalized[] = [
				'service_id'          => $service_id,
				'status'              => $status,
				'started_at'          => self::date( (string) ( $row['started_at'] ?? '' ) ),
				'ended_at'            => self::date( (string) ( $row['ended_at'] ?? '' ) ),
				'pricing_mode'        => $pricing_mode,
				'custom_amount_minor' => $custom_amount,
				'custom_currency'     => $custom_currency,
				'custom_tax_mode'     => $custom_tax_mode,
				'custom_tax_rate_id'  => $custom_tax_rate_id,
				'notes'               => sanitize_textarea_field( (string) ( $row['notes'] ?? '' ) ),
			];
		}

		$now = current_time( 'mysql', true );
		$wpdb->query( 'START TRANSACTION' );
		if ( false === $wpdb->delete( Schema::service_assignments_table(), [ 'owner_type' => $owner_type, 'owner_id' => $owner_id ], [ '%s', '%d' ] ) ) {
			$wpdb->query( 'ROLLBACK' );
			return false;
		}

		foreach ( $normalized as $row ) {
			$inserted = $wpdb->insert(
				Schema::service_assignments_table(),
				[
					'service_id'          => $row['service_id'],
					'owner_type'          => $owner_type,
					'owner_id'            => $owner_id,
					'status'              => $row['status'],
					'started_at'          => $row['started_at'],
					'ended_at'            => $row['ended_at'],
					'pricing_mode'        => $row['pricing_mode'],
					'custom_amount_minor' => $row['custom_amount_minor'],
					'custom_currency'     => $row['custom_currency'],
					'custom_tax_mode'     => $row['custom_tax_mode'],
					'custom_tax_rate_id'  => $row['custom_tax_rate_id'],
					'notes'               => $row['notes'],
					'created_at'          => $now,
					'updated_at'          => $now,
				],
				[ '%d', '%s', '%d', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%d', '%s', '%s', '%s' ]
			);
			if ( false === $inserted ) {
				$wpdb->query( 'ROLLBACK' );
				return false;
			}
		}

		$wpdb->query( 'COMMIT' );
		return true;
	}

	/** @param array<string,mixed> $row
	 *  @return array{amount_minor:?int,currency:string,tax_mode:string,tax_rate_id:int}
	 */
	public static function effective_pricing( array $row ): array {
		if ( 'custom' === (string) ( $row['pricing_mode'] ?? '' ) && null !== ( $row['custom_amount_minor'] ?? null ) ) {
			return [
				'amount_minor' => (int) $row['custom_amount_minor'],
				'currency'     => ServicePricing::normalize_currency( (string) ( $row['custom_currency'] ?? ServicePricing::DEFAULT_CURRENCY ) ),
				'tax_mode'     => ServicePricing::normalize_tax_mode( (string) ( $row['custom_tax_mode'] ?? ServicePricing::TAX_EXCLUSIVE ) ),
				'tax_rate_id'  => absint( $row['custom_tax_rate_id'] ?? 0 ),
			];
		}
		return ServicePricing::get( absint( $row['service_id'] ?? 0 ) );
	}

	/** @param array<string,mixed> $row */
	public static function pricing_summary( array $row ): string {
		return ServicePricing::summary( self::effective_pricing( $row ) );
	}

	private static function date( string $value ): ?string {
		$value = trim( $value );
		if ( '' === $value ) {
			return null;
		}
		$date = \DateTimeImmutable::createFromFormat( '!Y-m-d', $value );
		return $date && $date->format( 'Y-m-d' ) === $value ? $value : null;
	}
}

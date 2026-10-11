<?php
declare(strict_types=1);

namespace CB\CRM\Repository\Sales;

use CB\CRM\Database\Schema;
use CB\CRM\Domain\Sales\Access;
use CB\CRM\Domain\Sales\Rules;

defined( 'ABSPATH' ) || exit;

/**
 * Internal, staff-only commercial Outcome mutations for Opportunities.
 *
 * Closing and reopening are explicit separate operations; no Lead status,
 * WordPress identity, Work status, quote or payment is changed. Every successful
 * mutation writes its outcome transition event in the same InnoDB transaction.
 */
final class Outcomes {
	/**
	 * Close an open Opportunity as Won or Lost.
	 * @return int|\WP_Error Next revision or a typed failure.
	 */
	public static function close( int $opportunity_id, string $outcome, int $expected_revision ): int|\WP_Error {
		if ( ! in_array( $outcome, [ Rules::DEAL_WON, Rules::DEAL_LOST ], true ) ) {
			return self::error( 'crm_sales_invalid_input', 'Invalid Opportunity closing outcome.' );
		}
		return self::mutate( $opportunity_id, $expected_revision, $outcome, false );
	}

	/**
	 * Explicitly reopen a Won or Lost Opportunity. Stage is not changed.
	 * @return int|\WP_Error Next revision or a typed failure.
	 */
	public static function reopen( int $opportunity_id, int $expected_revision ): int|\WP_Error {
		return self::mutate( $opportunity_id, $expected_revision, Rules::DEAL_OPEN, true );
	}

	private static function mutate( int $id, int $expected_revision, string $target_outcome, bool $reopen ): int|\WP_Error {
		global $wpdb;

		if ( ! Access::can_manage() || ! self::schema_ready() || get_current_user_id() <= 0 ) {
			return self::error( 'crm_sales_forbidden', 'CRM Sales outcome writes are unavailable.' );
		}
		if ( $id <= 0 || $expected_revision <= 0 ) {
			return self::error( 'crm_sales_invalid_input', 'Invalid Opportunity ID or revision.' );
		}
		if ( false === $wpdb->query( 'START TRANSACTION' ) ) {
			return self::error( 'crm_sales_storage_failed', 'Could not begin Opportunity transaction.' );
		}

		$table = Schema::sales_opportunities_table();
		$actor = get_current_user_id();
		try {
			$wpdb->last_error = '';
			$row = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT id, outcome, won_at, lost_at, revision FROM {$table} WHERE id = %d LIMIT 1 FOR UPDATE",
					$id
				),
				ARRAY_A
			);
			if ( '' !== (string) $wpdb->last_error ) {
				throw new \RuntimeException( 'sales_outcome_lock_failed' );
			}
			if ( ! is_array( $row ) ) {
				throw new \DomainException( 'crm_sales_not_found' );
			}
			if ( (int) $row['revision'] !== $expected_revision ) {
				throw new \DomainException( 'crm_sales_conflict' );
			}

			$before = (string) ( $row['outcome'] ?? '' );
			if ( $reopen
				? ! Rules::can_reopen_deal( $before, $target_outcome )
				: ! Rules::can_close_deal( $before, $target_outcome ) ) {
				throw new \DomainException( 'crm_sales_invalid_transition' );
			}

			// Effective close timestamps belong to the CURRENT outcome only.
			// Prior close dates remain immutable in the append-only event log.
			$won_at = $row['won_at'] ?? null;
			$lost_at = $row['lost_at'] ?? null;
			if ( Rules::DEAL_OPEN === $before && ( null !== $won_at || null !== $lost_at ) ) {
				throw new \DomainException( 'crm_sales_outcome_inconsistent' );
			}
			if ( Rules::DEAL_WON === $before && ( null === $won_at || null !== $lost_at ) ) {
				throw new \DomainException( 'crm_sales_outcome_inconsistent' );
			}
			if ( Rules::DEAL_LOST === $before && ( null === $lost_at || null !== $won_at ) ) {
				throw new \DomainException( 'crm_sales_outcome_inconsistent' );
			}

			$next_revision = $expected_revision + 1;
			$now = current_time( 'mysql', true );
			$new_won_at = Rules::DEAL_WON === $target_outcome ? $now : null;
			$new_lost_at = Rules::DEAL_LOST === $target_outcome ? $now : null;

			$updated = $wpdb->update(
				$table,
				[
					'outcome' => $target_outcome,
					'won_at' => $new_won_at,
					'lost_at' => $new_lost_at,
					'revision' => $next_revision,
					'updated_at' => $now,
				],
				[ 'id' => $id, 'revision' => $expected_revision, 'outcome' => $before ],
				[ '%s', '%s', '%s', '%d', '%s' ],
				[ '%d', '%d', '%s' ]
			);
			if ( false === $updated ) {
				throw new \RuntimeException( 'sales_outcome_update_failed' );
			}
			if ( 1 !== $updated ) {
				throw new \DomainException( 'crm_sales_conflict' );
			}

			if ( false === $wpdb->insert(
				Schema::sales_events_table(),
				[
					'entity_type' => Rules::OPPORTUNITY,
					'entity_id' => $id,
					'event_type' => $reopen ? 'reopened' : $target_outcome,
					'from_outcome' => $before,
					'to_outcome' => $target_outcome,
					'actor_user_id' => $actor,
					'revision' => $next_revision,
					'created_at' => $now,
				],
				[ '%s', '%d', '%s', '%s', '%s', '%d', '%d', '%s' ]
			) ) {
				throw new \RuntimeException( 'sales_outcome_event_failed' );
			}
			if ( false === $wpdb->query( 'COMMIT' ) ) {
				throw new \RuntimeException( 'sales_outcome_commit_failed' );
			}
			return $next_revision;
		} catch ( \Throwable $exception ) {
			$wpdb->query( 'ROLLBACK' );
			return self::error(
				$exception instanceof \DomainException ? $exception->getMessage() : 'crm_sales_storage_failed',
				'Opportunity outcome was not changed.'
			);
		}
	}

	private static function schema_ready(): bool {
		return defined( 'CB_CRM_SCHEMA_VERSION' )
			&& CB_CRM_SCHEMA_VERSION === (string) get_option( Schema::OPTION, '' );
	}

	private static function error( string $code, string $message ): \WP_Error {
		return new \WP_Error( $code, $message );
	}
}

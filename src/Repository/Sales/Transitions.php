<?php
declare(strict_types=1);

namespace CB\CRM\Repository\Sales;

use CB\CRM\Database\Schema;
use CB\CRM\Domain\Sales\Access;
use CB\CRM\Domain\Sales\Rules;

defined( 'ABSPATH' ) || exit;

/**
 * Internal, staff-only Wave 1B stage and Lead-state mutations.
 *
 * Not a public API or transport endpoint. No participant changes, conversion,
 * Won/Lost/Reopen, cross-extension actions or policy-driven erasure occur here.
 * The expected revision must come from the caller's previously read record.
 */
final class Transitions {
	/**
	 * Move an open Lead or Opportunity to an active stage within its own
	 * existing active pipeline. Result is the committed revision.
	 */
	public static function move_stage( string $type, int $id, int $target_stage_id, int $expected_revision ): int|\WP_Error {
		if ( ! Rules::valid_entity_type( $type ) || $target_stage_id <= 0 ) {
			return self::error( 'crm_sales_invalid_input', 'Invalid Sales stage request.' );
		}
		return self::mutate( $type, $id, $expected_revision, 'stage', $target_stage_id );
	}

	/**
	 * Qualification/disqualification only. Converted belongs to the explicit
	 * transactional Wave 1B/B2 operation; this action must never set it.
	 */
	public static function set_lead_status( int $id, string $target_status, int $expected_revision ): int|\WP_Error {
		if ( ! in_array( $target_status, [ Rules::LEAD_QUALIFIED, Rules::LEAD_DISQUALIFIED ], true ) ) {
			return self::error( 'crm_sales_invalid_input', 'Unsupported Lead status action.' );
		}
		return self::mutate( Rules::LEAD, $id, $expected_revision, 'lead_status', $target_status );
	}

	private static function mutate( string $type, int $id, int $expected_revision, string $action, int|string $target ): int|\WP_Error {
		global $wpdb;

		if ( ! Access::can_manage() || ! self::schema_ready() || get_current_user_id() <= 0 ) {
			return self::error( 'crm_sales_forbidden', 'CRM Sales writes are unavailable.' );
		}
		if ( $id <= 0 || $expected_revision <= 0 ) {
			return self::error( 'crm_sales_invalid_input', 'Invalid Sales revision or record.' );
		}

		$table = Rules::LEAD === $type ? Schema::sales_leads_table() : Schema::sales_opportunities_table();
		$actor_id = get_current_user_id();
		if ( false === $wpdb->query( 'START TRANSACTION' ) ) {
			return self::error( 'crm_sales_storage_failed', 'Could not start Sales transaction.' );
		}

		try {
			$wpdb->last_error = '';
			$row = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT id, pipeline_id, stage_id, revision, " .
					( Rules::LEAD === $type ? 'status' : 'outcome' ) .
					" FROM {$table} WHERE id = %d LIMIT 1 FOR UPDATE",
					$id
				),
				ARRAY_A
			);
			if ( '' !== (string) $wpdb->last_error ) {
				throw new \RuntimeException( 'sales_record_lock_failed' );
			}
			if ( ! is_array( $row ) ) {
				throw new \DomainException( 'crm_sales_not_found' );
			}
			if ( (int) $row['revision'] !== $expected_revision ) {
				throw new \DomainException( 'crm_sales_conflict' );
			}

			$old_stage = (int) $row['stage_id'];
			$now = current_time( 'mysql', true );
			$next_revision = $expected_revision + 1;
			$values = [ 'revision' => $next_revision, 'updated_at' => $now ];
			$formats = [ '%d', '%s' ];
			$event_type = '';
			$event = [
				'entity_type' => $type,
				'entity_id' => $id,
				'actor_user_id' => $actor_id,
				'revision' => $next_revision,
				'created_at' => $now,
			];

			if ( 'stage' === $action ) {
				$state = Rules::LEAD === $type ? (string) $row['status'] : (string) $row['outcome'];
				if ( ! in_array( $state, Rules::LEAD === $type
					? [ Rules::LEAD_OPEN, Rules::LEAD_QUALIFIED ]
					: [ Rules::DEAL_OPEN ], true ) ) {
					throw new \DomainException( 'crm_sales_invalid_transition' );
				}
				if ( $old_stage === $target ) {
					throw new \DomainException( 'crm_sales_no_change' );
				}

				// Version 1B changes stages, never pipelines. Locks prevent a
				// concurrent pipeline/stage disable during this write.
				$wpdb->last_error = '';
				$pipeline = $wpdb->get_row(
					$wpdb->prepare(
						'SELECT id, entity_type, is_active FROM ' . Schema::sales_pipelines_table() .
						' WHERE id = %d LIMIT 1 FOR UPDATE',
						(int) $row['pipeline_id']
					),
					ARRAY_A
				);
				if ( '' !== (string) $wpdb->last_error ) {
					throw new \RuntimeException( 'sales_pipeline_read_failed' );
				}
				if ( ! is_array( $pipeline ) || $pipeline['entity_type'] !== $type
					|| 1 !== (int) $pipeline['is_active'] ) {
					throw new \DomainException( 'crm_sales_invalid_pipeline' );
				}

				$wpdb->last_error = '';
				$stage = $wpdb->get_row(
					$wpdb->prepare(
						'SELECT id, pipeline_id, is_active FROM ' . Schema::sales_stages_table() .
						' WHERE id = %d LIMIT 1 FOR UPDATE',
						(int) $target
					),
					ARRAY_A
				);
				if ( '' !== (string) $wpdb->last_error ) {
					throw new \RuntimeException( 'sales_stage_read_failed' );
				}
				if ( ! is_array( $stage )
					|| (int) $stage['pipeline_id'] !== (int) $row['pipeline_id']
					|| 1 !== (int) $stage['is_active'] ) {
					throw new \DomainException( 'crm_sales_invalid_stage' );
				}
				$values['stage_id'] = (int) $target;
				$formats[] = '%d';
				$event_type = 'stage_changed';
				$event['from_stage_id'] = $old_stage;
				$event['to_stage_id'] = (int) $target;
			} else {
				$from_status = (string) $row['status'];
				if ( ! Rules::can_transition_lead( $from_status, (string) $target ) ) {
					throw new \DomainException( 'crm_sales_invalid_transition' );
				}
				$values['status'] = (string) $target;
				$formats[] = '%s';
				$timestamp_column = Rules::LEAD_QUALIFIED === $target ? 'qualified_at' : 'disqualified_at';
				$values[ $timestamp_column ] = $now;
				$formats[] = '%s';
				// Preserve source state without repurposing Opportunity outcome.
				$event_type = 'lead_status_' . $from_status . '_to_' . $target;
			}

			$changed = $wpdb->update(
				$table,
				$values,
				[ 'id' => $id, 'revision' => $expected_revision ],
				$formats,
				[ '%d', '%d' ]
			);
			if ( false === $changed ) {
				throw new \RuntimeException( 'sales_record_update_failed' );
			}
			if ( 1 !== $changed ) {
				throw new \DomainException( 'crm_sales_conflict' );
			}

			$event['event_type'] = $event_type;
			if ( false === $wpdb->insert(
				Schema::sales_events_table(),
				$event,
				array_map(
					static fn ( string $field ): string => in_array(
						$field, [ 'entity_id', 'actor_user_id', 'revision', 'from_stage_id', 'to_stage_id' ], true
					) ? '%d' : '%s',
					array_keys( $event )
				)
			) ) {
				throw new \RuntimeException( 'sales_event_insert_failed' );
			}
			if ( false === $wpdb->query( 'COMMIT' ) ) {
				throw new \RuntimeException( 'sales_commit_failed' );
			}
			return $next_revision;
		} catch ( \Throwable $exception ) {
			$wpdb->query( 'ROLLBACK' );
			$code = $exception instanceof \DomainException
				? $exception->getMessage()
				: 'crm_sales_storage_failed';
			return self::error( $code, 'CRM Sales transition was not applied.' );
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

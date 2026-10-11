<?php
declare(strict_types=1);

namespace CB\CRM\Repository\Sales;

use CB\CRM\Content\Entity;
use CB\CRM\Database\Schema;
use CB\CRM\Domain\Sales\Access;
use CB\CRM\Domain\Sales\Rules;

defined( 'ABSPATH' ) || exit;

/**
 * Internal, transactionally replay-safe qualified Lead conversion.
 *
 * No customer identity, Work item, invoice, communications or consent is
 * created. All participants are references to canonical CRM identities.
 */
final class Conversion {
	private const MAX_PARTICIPANTS = 50;

	/**
	 * Return the created Opportunity ID or the existing linked ID on replay.
	 * An already-converted Lead is only considered a replay if the reciprocal
	 * Opportunity source link can be verified inside the same transaction.
	 */
	public static function convert(
		int $lead_id,
		int $expected_revision,
		int $opportunity_pipeline_id,
		int $opportunity_stage_id
	): int|\WP_Error {
		global $wpdb;

		if ( ! Access::can_manage() || ! self::schema_ready() || get_current_user_id() <= 0 ) {
			return self::error( 'crm_sales_forbidden', 'CRM Sales conversion is unavailable.' );
		}
		if ( $lead_id <= 0 || $expected_revision <= 0 || $opportunity_pipeline_id <= 0 || $opportunity_stage_id <= 0 ) {
			return self::error( 'crm_sales_invalid_input', 'Invalid Sales conversion reference.' );
		}
		$actor = get_current_user_id();
		$now = current_time( 'mysql', true );
		if ( false === $wpdb->query( 'START TRANSACTION' ) ) {
			return self::error( 'crm_sales_storage_failed', 'Could not start conversion transaction.' );
		}

		try {
			$wpdb->last_error = '';
			$lead = $wpdb->get_row(
				$wpdb->prepare(
					'SELECT id, title, owner_user_id, status, converted_opportunity_id, revision FROM ' .
					Schema::sales_leads_table() . ' WHERE id = %d LIMIT 1 FOR UPDATE',
					$lead_id
				),
				ARRAY_A
			);
			if ( '' !== (string) $wpdb->last_error ) {
				throw new \RuntimeException( 'sales_lead_read_failed' );
			}
			if ( ! is_array( $lead ) ) {
				throw new \DomainException( 'crm_sales_not_found' );
			}

			// The lead lock serializes concurrent converters. Replays must not
			// rewrite history or manufacture a second Opportunity/event.
			if ( Rules::LEAD_CONVERTED === (string) $lead['status'] ) {
				$existing_id = (int) ( $lead['converted_opportunity_id'] ?? 0 );
				if ( $existing_id <= 0 ) {
					throw new \DomainException( 'crm_sales_conversion_inconsistent' );
				}
				$wpdb->last_error = '';
				$existing = $wpdb->get_row(
					$wpdb->prepare(
						'SELECT id, source_lead_id FROM ' . Schema::sales_opportunities_table() .
						' WHERE id = %d LIMIT 1 FOR UPDATE',
						$existing_id
					),
					ARRAY_A
				);
				if ( '' !== (string) $wpdb->last_error ) {
					throw new \RuntimeException( 'sales_conversion_link_read_failed' );
				}
				if ( ! is_array( $existing ) || $lead_id !== (int) ( $existing['source_lead_id'] ?? 0 ) ) {
					throw new \DomainException( 'crm_sales_conversion_inconsistent' );
				}
				if ( false === $wpdb->query( 'COMMIT' ) ) {
					throw new \RuntimeException( 'sales_replay_commit_failed' );
				}
				return $existing_id;
			}

			if ( (int) $lead['revision'] !== $expected_revision ) {
				throw new \DomainException( 'crm_sales_conflict' );
			}
			if ( ! Rules::can_transition_lead( (string) $lead['status'], Rules::LEAD_CONVERTED ) ) {
				throw new \DomainException( 'crm_sales_invalid_transition' );
			}
			if ( null !== $lead['converted_opportunity_id'] ) {
				throw new \DomainException( 'crm_sales_conversion_inconsistent' );
			}

			// A reciprocal source link is the second integrity boundary.
			// A stray existing source row must never be duplicated or adopted.
			$wpdb->last_error = '';
			$source_row = $wpdb->get_row(
				$wpdb->prepare(
					'SELECT id FROM ' . Schema::sales_opportunities_table() .
					' WHERE source_lead_id = %d LIMIT 1 FOR UPDATE',
					$lead_id
				),
				ARRAY_A
			);
			if ( '' !== (string) $wpdb->last_error ) {
				throw new \RuntimeException( 'sales_source_lookup_failed' );
			}
			if ( is_array( $source_row ) ) {
				throw new \DomainException( 'crm_sales_conversion_inconsistent' );
			}

			$wpdb->last_error = '';
			$participants = $wpdb->get_results(
				$wpdb->prepare(
					'SELECT party_type, party_id, role FROM ' . Schema::sales_participants_table() .
					' WHERE entity_type = %s AND entity_id = %d ORDER BY party_id, party_type, role FOR UPDATE',
					Rules::LEAD,
					$lead_id
				),
				ARRAY_A
			);
			if ( ! is_array( $participants ) || '' !== (string) $wpdb->last_error ) {
				throw new \RuntimeException( 'sales_participants_read_failed' );
			}
			if ( count( $participants ) > self::MAX_PARTICIPANTS ) {
				throw new \DomainException( 'crm_sales_invalid_participants' );
			}
			$parties = [];
			foreach ( $participants as $participant ) {
				$parties[] = [
					'party_type' => $participant['party_type'] ?? null,
					'party_id' => isset( $participant['party_id'] ) ? (int) $participant['party_id'] : 0,
					'role' => $participant['role'] ?? null,
				];
			}
			if ( ! Rules::valid_participants( $parties ) ) {
				throw new \DomainException( 'crm_sales_invalid_participants' );
			}

			// Protect the identity/delete race just as Records::create() does:
			// lock real wp_posts, not a potentially stale cached WP_Post.
			foreach ( $parties as $party ) {
				$expected_type = Entity::post_type_for_owner( $party['party_type'] );
				$wpdb->last_error = '';
				$identity = $wpdb->get_row(
					$wpdb->prepare(
						"SELECT post_type, post_status FROM {$wpdb->posts} WHERE ID = %d LIMIT 1 FOR UPDATE",
						$party['party_id']
					),
					ARRAY_A
				);
				if ( '' !== (string) $wpdb->last_error ) {
					throw new \RuntimeException( 'sales_identity_lock_failed' );
				}
				if ( '' === $expected_type || ! is_array( $identity )
					|| $expected_type !== ( $identity['post_type'] ?? null )
					|| ! is_string( $identity['post_status'] ?? null )
					|| in_array( $identity['post_status'], [ 'trash', 'auto-draft' ], true ) ) {
					throw new \DomainException( 'crm_sales_invalid_identity' );
				}
			}

			$wpdb->last_error = '';
			$pipeline = $wpdb->get_row(
				$wpdb->prepare(
					'SELECT id, entity_type, is_active FROM ' . Schema::sales_pipelines_table() .
					' WHERE id = %d LIMIT 1 FOR UPDATE',
					$opportunity_pipeline_id
				),
				ARRAY_A
			);
			if ( '' !== (string) $wpdb->last_error ) {
				throw new \RuntimeException( 'sales_pipeline_read_failed' );
			}
			if ( ! is_array( $pipeline ) || Rules::OPPORTUNITY !== ( $pipeline['entity_type'] ?? '' )
				|| 1 !== (int) $pipeline['is_active'] ) {
				throw new \DomainException( 'crm_sales_invalid_pipeline' );
			}

			$wpdb->last_error = '';
			$stage = $wpdb->get_row(
				$wpdb->prepare(
					'SELECT id, pipeline_id, is_active FROM ' . Schema::sales_stages_table() .
					' WHERE id = %d LIMIT 1 FOR UPDATE',
					$opportunity_stage_id
				),
				ARRAY_A
			);
			if ( '' !== (string) $wpdb->last_error ) {
				throw new \RuntimeException( 'sales_stage_read_failed' );
			}
			if ( ! is_array( $stage )
				|| $opportunity_pipeline_id !== (int) $stage['pipeline_id']
				|| 1 !== (int) $stage['is_active'] ) {
				throw new \DomainException( 'crm_sales_invalid_stage' );
			}

			$inserted = $wpdb->insert(
				Schema::sales_opportunities_table(),
				[
					'title' => (string) $lead['title'],
					'owner_user_id' => (int) $lead['owner_user_id'],
					'pipeline_id' => $opportunity_pipeline_id,
					'stage_id' => $opportunity_stage_id,
					'outcome' => Rules::DEAL_OPEN,
					'source_lead_id' => $lead_id,
					'revision' => 1,
					'created_at' => $now,
					'updated_at' => $now,
				],
				[ '%s', '%d', '%d', '%d', '%s', '%d', '%d', '%s', '%s' ]
			);
			if ( false === $inserted || (int) $wpdb->insert_id <= 0 ) {
				throw new \RuntimeException( 'sales_opportunity_insert_failed' );
			}
			$opportunity_id = (int) $wpdb->insert_id;

			foreach ( $parties as $party ) {
				if ( false === $wpdb->insert(
					Schema::sales_participants_table(),
					[
						'entity_type' => Rules::OPPORTUNITY,
						'entity_id' => $opportunity_id,
						'party_type' => $party['party_type'],
						'party_id' => $party['party_id'],
						'role' => $party['role'],
						'created_at' => $now,
					],
					[ '%s', '%d', '%s', '%d', '%s', '%s' ]
				) ) {
					throw new \RuntimeException( 'sales_conversion_participant_failed' );
				}
			}

			$next_revision = $expected_revision + 1;
			$updated = $wpdb->update(
				Schema::sales_leads_table(),
				[
					'status' => Rules::LEAD_CONVERTED,
					'converted_opportunity_id' => $opportunity_id,
					'revision' => $next_revision,
					'updated_at' => $now,
				],
				[
					'id' => $lead_id,
					'revision' => $expected_revision,
					'status' => Rules::LEAD_QUALIFIED,
					'converted_opportunity_id' => null,
				],
				[ '%s', '%d', '%d', '%s' ],
				[ '%d', '%d', '%s', '%d' ]
			);
			if ( false === $updated ) {
				throw new \RuntimeException( 'sales_lead_conversion_update_failed' );
			}
			if ( 1 !== $updated ) {
				throw new \DomainException( 'crm_sales_conflict' );
			}

			if ( false === $wpdb->insert(
				Schema::sales_events_table(),
				[
					'entity_type' => Rules::OPPORTUNITY,
					'entity_id' => $opportunity_id,
					'event_type' => 'created',
					'to_stage_id' => $opportunity_stage_id,
					'to_outcome' => Rules::DEAL_OPEN,
					'actor_user_id' => $actor,
					'revision' => 1,
					'created_at' => $now,
				],
				[ '%s', '%d', '%s', '%d', '%s', '%d', '%d', '%s' ]
			) ) {
				throw new \RuntimeException( 'sales_opportunity_event_failed' );
			}
			if ( false === $wpdb->insert(
				Schema::sales_events_table(),
				[
					'entity_type' => Rules::LEAD,
					'entity_id' => $lead_id,
					'event_type' => 'converted',
					'actor_user_id' => $actor,
					'revision' => $next_revision,
					'created_at' => $now,
				],
				[ '%s', '%d', '%s', '%d', '%d', '%s' ]
			) ) {
				throw new \RuntimeException( 'sales_lead_event_failed' );
			}
			if ( false === $wpdb->query( 'COMMIT' ) ) {
				throw new \RuntimeException( 'sales_conversion_commit_failed' );
			}
			return $opportunity_id;
		} catch ( \Throwable $exception ) {
			$wpdb->query( 'ROLLBACK' );
			$code = $exception instanceof \DomainException ? $exception->getMessage() : 'crm_sales_storage_failed';
			return self::error( $code, 'CRM Sales conversion was not applied.' );
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

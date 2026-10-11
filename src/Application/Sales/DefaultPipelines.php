<?php
declare(strict_types=1);

namespace CB\CRM\Application\Sales;

use CB\CRM\Database\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * First-party Sales defaults, independently versioned from database DDL.
 *
 * A completed marker is never automatically reset. An interrupted first
 * installation can retry; existing default stage labels/settings are never
 * overwritten. All inserts happen in one transaction.
 */
final class DefaultPipelines {
	public const OPTION = 'cb_crm_sales_defaults_version';
	private const VERSION = '1';

	/**
	 * English seed labels are stored as user-editable data, not interface
	 * translations. Future display adapters should localize default keys,
	 * leaving any user-edited label untouched.
	 *
	 * @return array<string,array{type:string,name:string,stages:array<string,string>}>
	 */
	public static function definitions(): array {
		return [
			'default_leads' => [
				'type' => 'lead',
				'name' => 'Lead Qualification',
				'stages' => [
					'new' => 'New',
					'attempting_contact' => 'Attempting Contact',
					'connected' => 'Connected',
					'qualified' => 'Qualified',
				],
			],
			'default_opportunities' => [
				'type' => 'opportunity',
				'name' => 'Sales Opportunities',
				'stages' => [
					'discovery' => 'Discovery',
					'proposal' => 'Proposal',
					'decision' => 'Decision',
				],
			],
		];
	}

	public static function maybe_install(): bool {
		if ( self::VERSION === (string) get_option( self::OPTION, '' ) ) {
			return true;
		}

		// Never run Sales DML while Base's schema migration is incomplete.
		if ( ! defined( 'CB_CRM_SCHEMA_VERSION' )
			|| version_compare( (string) CB_CRM_SCHEMA_VERSION, '1.6', '<' )
			|| CB_CRM_SCHEMA_VERSION !== (string) get_option( Schema::OPTION, '' ) ) {
			return false;
		}

		return self::install();
	}

	private static function install(): bool {
		global $wpdb;

		if ( false === $wpdb->query( 'START TRANSACTION' ) ) {
			return false;
		}

		try {
			$now = current_time( 'mysql', true );
			foreach ( self::definitions() as $key => $definition ) {
				$pipelines = Schema::sales_pipelines_table();
				$existing = $wpdb->get_row(
					$wpdb->prepare(
						"SELECT id, entity_type FROM {$pipelines} WHERE pipeline_key = %s LIMIT 1 FOR UPDATE",
						$key
					),
					ARRAY_A
				);
				if ( ! is_array( $existing ) && '' !== (string) $wpdb->last_error ) {
					throw new \RuntimeException( 'sales_pipeline_lookup_failed' );
				}

				if ( is_array( $existing ) ) {
					if ( $definition['type'] !== (string) ( $existing['entity_type'] ?? '' )
						|| (int) ( $existing['id'] ?? 0 ) <= 0 ) {
						throw new \RuntimeException( 'sales_pipeline_key_collision' );
					}
					$pipeline_id = (int) $existing['id'];
				} else {
					if ( false === $wpdb->insert(
						$pipelines,
						[
							'pipeline_key' => $key,
							'entity_type' => $definition['type'],
							'name' => $definition['name'],
							'is_active' => 1,
							'sort_order' => 'lead' === $definition['type'] ? 10 : 20,
							'created_at' => $now,
							'updated_at' => $now,
						],
						[ '%s', '%s', '%s', '%d', '%d', '%s', '%s' ]
					) || (int) $wpdb->insert_id <= 0 ) {
						throw new \RuntimeException( 'sales_pipeline_insert_failed' );
					}
					$pipeline_id = (int) $wpdb->insert_id;
				}

				foreach ( $definition['stages'] as $stage_key => $stage_name ) {
					$stages = Schema::sales_stages_table();
					$found = $wpdb->get_row(
						$wpdb->prepare(
							"SELECT id FROM {$stages} WHERE pipeline_id = %d AND stage_key = %s LIMIT 1 FOR UPDATE",
							$pipeline_id,
							$stage_key
						),
						ARRAY_A
					);
					if ( ! is_array( $found ) && '' !== (string) $wpdb->last_error ) {
						throw new \RuntimeException( 'sales_stage_lookup_failed' );
					}
					if ( is_array( $found ) ) {
						if ( (int) ( $found['id'] ?? 0 ) <= 0 ) {
							throw new \RuntimeException( 'sales_stage_lookup_invalid' );
						}
						continue; // Never rewrite customized stage labels, order or active state.
					}

					$position = array_search( $stage_key, array_keys( $definition['stages'] ), true );
					if ( false === $wpdb->insert(
						$stages,
						[
							'pipeline_id' => $pipeline_id,
							'stage_key' => $stage_key,
							'name' => $stage_name,
							'is_active' => 1,
							'sort_order' => ( (int) $position + 1 ) * 10,
							'created_at' => $now,
							'updated_at' => $now,
						],
						[ '%d', '%s', '%s', '%d', '%d', '%s', '%s' ]
					) ) {
						throw new \RuntimeException( 'sales_stage_insert_failed' );
					}
				}
			}

			if ( false === $wpdb->query( 'COMMIT' ) ) {
				throw new \RuntimeException( 'sales_defaults_commit_failed' );
			}
		} catch ( \Throwable $error ) {
			$wpdb->query( 'ROLLBACK' );
			return false;
		}

		// Mark only after commit, so incomplete database writes remain retryable.
		return (bool) update_option( self::OPTION, self::VERSION, false );
	}
}

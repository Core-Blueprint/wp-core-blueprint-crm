<?php
declare(strict_types=1);

namespace CB\CRM\Repository\Sales;

use CB\CRM\Content\Entity;
use CB\CRM\Database\Schema;
use CB\CRM\Domain\Sales\Access;
use CB\CRM\Domain\Sales\Rules;

defined( 'ABSPATH' ) || exit;

/** W2B2-A: bounded, manager-only selections; never a public identity search. */
final class CreationOptions {
	private const MAX_STAGES = 200;
	private const MAX_IDENTITIES = 20;

	/** @return list<array{pipeline_id:int,pipeline_name:string,stage_id:int,stage_name:string}>|\WP_Error */
	public static function stages( string $type ): array|\WP_Error {
		global $wpdb;
		if ( ! self::allowed() || ! Rules::valid_entity_type( $type ) ) {
			return new \WP_Error( 'crm_sales_forbidden' );
		}
		$pipelines = Schema::sales_pipelines_table();
		$stages = Schema::sales_stages_table();
		$wpdb->last_error = '';
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT p.id AS pipeline_id, p.name AS pipeline_name, s.id AS stage_id, s.name AS stage_name
			 FROM {$pipelines} p INNER JOIN {$stages} s ON s.pipeline_id = p.id
			 WHERE p.entity_type = %s AND p.is_active = 1 AND s.is_active = 1
			 ORDER BY p.sort_order ASC, p.id ASC, s.sort_order ASC, s.id ASC LIMIT %d",
			$type,
			self::MAX_STAGES + 1
		), ARRAY_A );
		if ( ! is_array( $rows ) || '' !== (string) $wpdb->last_error || count( $rows ) > self::MAX_STAGES ) {
			return new \WP_Error( 'crm_sales_choices_unavailable' );
		}
		$options = [];
		foreach ( $rows as $row ) {
			$pipeline_id = (int) ( $row['pipeline_id'] ?? 0 );
			$stage_id = (int) ( $row['stage_id'] ?? 0 );
			if ( $pipeline_id <= 0 || $stage_id <= 0 ) {
				return new \WP_Error( 'crm_sales_choices_unavailable' );
			}
			$options[] = [
				'pipeline_id' => $pipeline_id,
				'pipeline_name' => (string) $row['pipeline_name'],
				'stage_id' => $stage_id,
				'stage_name' => (string) $row['stage_name'],
			];
		}
		return $options;
	}

	/** @return list<array{id:int,title:string}>|\WP_Error */
	public static function identities( string $party_type, string $search ): array|\WP_Error {
		global $wpdb;
		if ( ! self::allowed() || ! Rules::valid_party_type( $party_type ) ) {
			return new \WP_Error( 'crm_sales_forbidden' );
		}
		$search = trim( $search );
		if ( '' === $search || mb_strlen( $search ) > 80 ) {
			return new \WP_Error( 'crm_sales_invalid_query' );
		}
		$post_type = Entity::post_type_for_owner( $party_type );
		if ( '' === $post_type ) {
			return new \WP_Error( 'crm_sales_invalid_query' );
		}
		$wpdb->last_error = '';
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT ID, post_title FROM {$wpdb->posts} WHERE post_type = %s
			 AND post_status NOT IN ('trash', 'auto-draft') AND post_title LIKE %s
			 ORDER BY post_title ASC, ID ASC LIMIT %d",
			$post_type,
			'%' . $wpdb->esc_like( $search ) . '%',
			self::MAX_IDENTITIES
		), ARRAY_A );
		if ( ! is_array( $rows ) || '' !== (string) $wpdb->last_error ) {
			return new \WP_Error( 'crm_sales_choices_unavailable' );
		}
		$options = [];
		foreach ( $rows as $row ) {
			$id = (int) ( $row['ID'] ?? 0 );
			if ( $id <= 0 ) {
				return new \WP_Error( 'crm_sales_choices_unavailable' );
			}
			$options[] = [ 'id' => $id, 'title' => (string) ( $row['post_title'] ?? '' ) ];
		}
		return $options;
	}

	private static function allowed(): bool {
		return Access::can_manage() && function_exists( 'get_current_user_id' ) && get_current_user_id() > 0;
	}
}

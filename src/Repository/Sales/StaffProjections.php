<?php
declare(strict_types=1);

namespace CB\CRM\Repository\Sales;

use CB\CRM\Database\Schema;
use CB\CRM\Domain\Sales\Access;
use CB\CRM\Domain\Sales\Rules;

defined( 'ABSPATH' ) || exit;

/**
 * Internal, staff-wide and intentionally narrow Sales read projections.
 *
 * No REST/shortcode or public per-owner access contract. The canonical CRM
 * and Sales view capabilities are required on EVERY entry point. No identity
 * emails, addresses, notes, source references or WP_Post metadata are fetched.
 */
final class StaffProjections {
	public const MAX_PAGE_SIZE = 50;
	private const MAX_PARTICIPANTS = 50;
	private const FILTERS = [ 'pipeline_id', 'stage_id', 'owner_user_id', 'state' ];

	/**
	 * Deterministic descending-ID keyset page (no OFFSET/full COUNT).
	 *
	 * Supported exact filters: pipeline_id, stage_id, owner_user_id (including
	 * 0 for anonymized owner) and state (Lead status / Opportunity outcome).
	 * This is staff-wide visibility, NOT a per-owner confidentiality policy.
	 *
	 * @param array<string,mixed> $filters
	 * @return array{rows:list<array<string,mixed>>,cursor:int,has_more:bool}|\WP_Error
	 */
	public static function page( string $type, array $filters = [], int $after = 0, int $limit = 20 ): array|\WP_Error {
		global $wpdb;

		if ( ! self::authorized() ) {
			return self::error( 'crm_sales_forbidden', 'CRM Sales staff read is unavailable.' );
		}
		if ( ! Rules::valid_entity_type( $type ) || $after < 0 || $limit < 1 || $limit > self::MAX_PAGE_SIZE
			|| ! self::valid_filters( $type, $filters ) ) {
			return self::error( 'crm_sales_invalid_query', 'Invalid CRM Sales page or filter.' );
		}

		$table = self::table_for( $type );
		$state_column = self::state_column( $type );
		$clauses = [];
		$args = [];
		if ( $after > 0 ) {
			$clauses[] = 'id < %d';
			$args[] = $after;
		}
		foreach ( [ 'pipeline_id', 'stage_id', 'owner_user_id' ] as $field ) {
			if ( array_key_exists( $field, $filters ) ) {
				$clauses[] = "{$field} = %d";
				$args[] = $filters[$field];
			}
		}
		if ( array_key_exists( 'state', $filters ) ) {
			$clauses[] = "{$state_column} = %s";
			$args[] = $filters['state'];
		}
		$where = [] === $clauses ? '' : ' WHERE ' . implode( ' AND ', $clauses );
		$args[] = $limit + 1;
		$sql = "SELECT id, title, owner_user_id, pipeline_id, stage_id, {$state_column} AS state," .
			" next_action_on, revision, created_at, updated_at FROM {$table}{$where} ORDER BY id DESC LIMIT %d";

		$wpdb->last_error = '';
		$found = $wpdb->get_results( $wpdb->prepare( $sql, ...$args ), ARRAY_A );
		if ( ! is_array( $found ) || '' !== (string) $wpdb->last_error ) {
			return self::error( 'crm_sales_read_failed', 'Cannot read CRM Sales page.' );
		}
		$has_more = count( $found ) > $limit;
		$found = array_slice( $found, 0, $limit );
		$rows = [];
		$cursor = $after;
		foreach ( $found as $row ) {
			$mapped = self::summary_row( $row );
			if ( $mapped instanceof \WP_Error || ( $cursor > 0 && $mapped['id'] >= $cursor ) ) {
				return self::error( 'crm_sales_invalid_row', 'CRM Sales page returned an invalid row.' );
			}
			$cursor = $mapped['id'];
			$rows[] = $mapped;
		}
		return [ 'rows' => $rows, 'cursor' => $cursor, 'has_more' => $has_more ];
	}

	/**
	 * Minimal record detail plus numeric references to canonical identities.
	 * Participants are not joined to wp_posts and never contain PII fields.
	 *
	 * @return array{record:array<string,mixed>,participants:list<array<string,int|string>>}|\WP_Error|null
	 */
	public static function detail( string $type, int $id ): array|\WP_Error|null {
		global $wpdb;
		if ( ! self::authorized() ) {
			return self::error( 'crm_sales_forbidden', 'CRM Sales staff read is unavailable.' );
		}
		if ( ! Rules::valid_entity_type( $type ) || $id <= 0 ) {
			return self::error( 'crm_sales_invalid_query', 'Invalid CRM Sales detail request.' );
		}
		$table = self::table_for( $type );
		$state_column = self::state_column( $type );
		$extra = Rules::LEAD === $type
			? 'source, converted_opportunity_id, qualified_at, disqualified_at'
			: 'source_lead_id, amount_minor, currency, expected_close_on, won_at, lost_at';
		$wpdb->last_error = '';
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id, title, owner_user_id, pipeline_id, stage_id, {$state_column} AS state," .
				" next_action_on, revision, created_at, updated_at, {$extra}" .
				" FROM {$table} WHERE id = %d LIMIT 1",
				$id
			),
			ARRAY_A
		);
		if ( '' !== (string) $wpdb->last_error ) {
			return self::error( 'crm_sales_read_failed', 'Cannot read CRM Sales detail.' );
		}
		if ( ! is_array( $row ) ) {
			return null;
		}
		$record = self::summary_row( $row );
		if ( $record instanceof \WP_Error || $record['id'] !== $id ) {
			return self::error( 'crm_sales_invalid_row', 'CRM Sales detail returned an invalid row.' );
		}
		if ( Rules::LEAD === $type ) {
			$record['source'] = (string) ( $row['source'] ?? '' );
			$record['converted_opportunity_id'] = self::nullable_int( $row['converted_opportunity_id'] ?? null );
			$record['qualified_at'] = self::nullable_string( $row['qualified_at'] ?? null );
			$record['disqualified_at'] = self::nullable_string( $row['disqualified_at'] ?? null );
		} else {
			$record['source_lead_id'] = self::nullable_int( $row['source_lead_id'] ?? null );
			$record['amount_minor'] = self::nullable_int( $row['amount_minor'] ?? null );
			$record['currency'] = (string) ( $row['currency'] ?? '' );
			$record['expected_close_on'] = self::nullable_string( $row['expected_close_on'] ?? null );
			$record['won_at'] = self::nullable_string( $row['won_at'] ?? null );
			$record['lost_at'] = self::nullable_string( $row['lost_at'] ?? null );
		}

		$wpdb->last_error = '';
		$participants = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT party_type, party_id, role FROM ' . Schema::sales_participants_table() .
				' WHERE entity_type = %s AND entity_id = %d ORDER BY id ASC LIMIT %d',
				$type,
				$id,
				self::MAX_PARTICIPANTS + 1
			),
			ARRAY_A
		);
		if ( ! is_array( $participants ) || '' !== (string) $wpdb->last_error ) {
			return self::error( 'crm_sales_read_failed', 'Cannot read CRM Sales participants.' );
		}
		if ( count( $participants ) > self::MAX_PARTICIPANTS ) {
			return self::error( 'crm_sales_invalid_row', 'CRM Sales participant projection exceeds the safe limit.' );
		}
		$parties = [];
		foreach ( $participants as $participant ) {
			$party_type = $participant['party_type'] ?? null;
			$party_id = $participant['party_id'] ?? null;
			$role = $participant['role'] ?? null;
			if ( ! is_string( $party_type ) || ! Rules::valid_party_type( $party_type )
				|| ! is_numeric( $party_id ) || (int) $party_id <= 0
				|| ! is_string( $role ) || ! Rules::valid_participant_role( $role ) ) {
				return self::error( 'crm_sales_invalid_row', 'CRM Sales participant projection is malformed.' );
			}
			$parties[] = [ 'party_type' => $party_type, 'party_id' => (int) $party_id, 'role' => $role ];
		}

		return [ 'record' => $record, 'participants' => $parties ];
	}

	/**
	 * Ascending event-ID keyset page. Returns only audited scalar metadata,
	 * not joined user/contact records or arbitrary free-text audit payloads.
	 *
	 * @return array{rows:list<array<string,int|string|null>>,cursor:int,has_more:bool}|\WP_Error
	 */
	public static function history( string $type, int $id, int $after = 0, int $limit = 20 ): array|\WP_Error {
		global $wpdb;
		if ( ! self::authorized() ) {
			return self::error( 'crm_sales_forbidden', 'CRM Sales staff read is unavailable.' );
		}
		if ( ! Rules::valid_entity_type( $type ) || $id <= 0 || $after < 0
			|| $limit < 1 || $limit > self::MAX_PAGE_SIZE ) {
			return self::error( 'crm_sales_invalid_query', 'Invalid CRM Sales history request.' );
		}
		$table = self::table_for( $type );
		$wpdb->last_error = '';
		$exists = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE id = %d LIMIT 1", $id ) );
		if ( '' !== (string) $wpdb->last_error ) {
			return self::error( 'crm_sales_read_failed', 'Cannot verify CRM Sales history subject.' );
		}
		if ( null === $exists ) {
			return self::error( 'crm_sales_not_found', 'CRM Sales record does not exist.' );
		}

		$filter = $after > 0 ? ' AND id > %d' : '';
		$args = [ $type, $id ];
		if ( $after > 0 ) {
			$args[] = $after;
		}
		$args[] = $limit + 1;
		$wpdb->last_error = '';
		$events = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT id, event_type, from_stage_id, to_stage_id, from_outcome, to_outcome,' .
				' actor_user_id, revision, created_at FROM ' . Schema::sales_events_table() .
				" WHERE entity_type = %s AND entity_id = %d{$filter} ORDER BY id ASC LIMIT %d",
				...$args
			),
			ARRAY_A
		);
		if ( ! is_array( $events ) || '' !== (string) $wpdb->last_error ) {
			return self::error( 'crm_sales_read_failed', 'Cannot read CRM Sales audit history.' );
		}
		$has_more = count( $events ) > $limit;
		$events = array_slice( $events, 0, $limit );
		$rows = [];
		$cursor = $after;
		foreach ( $events as $event ) {
			$event_id = (int) ( $event['id'] ?? 0 );
			$revision = (int) ( $event['revision'] ?? 0 );
			if ( $event_id <= $cursor || $revision <= 0 || ! is_string( $event['event_type'] ?? null ) ) {
				return self::error( 'crm_sales_invalid_row', 'CRM Sales audit history contains an invalid event.' );
			}
			$rows[] = [
				'id' => $event_id,
				'event_type' => $event['event_type'],
				'from_stage_id' => self::nullable_int( $event['from_stage_id'] ?? null ),
				'to_stage_id' => self::nullable_int( $event['to_stage_id'] ?? null ),
				'from_outcome' => (string) ( $event['from_outcome'] ?? '' ),
				'to_outcome' => (string) ( $event['to_outcome'] ?? '' ),
				'actor_user_id' => (int) ( $event['actor_user_id'] ?? 0 ),
				'revision' => $revision,
				'created_at' => (string) ( $event['created_at'] ?? '' ),
			];
			$cursor = $event_id;
		}
		return [ 'rows' => $rows, 'cursor' => $cursor, 'has_more' => $has_more ];
	}

	/** @param array<string,mixed> $filters */
	private static function valid_filters( string $type, array $filters ): bool {
		foreach ( $filters as $key => $value ) {
			if ( ! is_string( $key ) || ! in_array( $key, self::FILTERS, true ) ) {
				return false;
			}
			if ( 'state' === $key ) {
				if ( ! is_string( $value ) || ! ( Rules::LEAD === $type
					? Rules::valid_lead_status( $value )
					: Rules::valid_opportunity_outcome( $value ) ) ) {
					return false;
				}
			} elseif ( ! is_int( $value ) || $value < ( 'owner_user_id' === $key ? 0 : 1 ) ) {
				return false;
			}
		}
		return true;
	}

	/** @param array<string,mixed> $row
	 *  @return array<string,mixed>|\WP_Error
	 */
	private static function summary_row( array $row ): array|\WP_Error {
		$id = (int) ( $row['id'] ?? 0 );
		if ( $id <= 0 || ! is_string( $row['title'] ?? null )
			|| ! is_string( $row['state'] ?? null )
			|| (int) ( $row['revision'] ?? 0 ) <= 0 ) {
			return self::error( 'crm_sales_invalid_row', 'CRM Sales projection contains invalid data.' );
		}
		return [
			'id' => $id,
			'title' => $row['title'],
			'owner_user_id' => (int) ( $row['owner_user_id'] ?? 0 ),
			'pipeline_id' => (int) ( $row['pipeline_id'] ?? 0 ),
			'stage_id' => (int) ( $row['stage_id'] ?? 0 ),
			'state' => $row['state'],
			'next_action_on' => self::nullable_string( $row['next_action_on'] ?? null ),
			'revision' => (int) $row['revision'],
			'created_at' => (string) ( $row['created_at'] ?? '' ),
			'updated_at' => (string) ( $row['updated_at'] ?? '' ),
		];
	}

	private static function nullable_string( mixed $value ): ?string {
		return null === $value ? null : (string) $value;
	}

	private static function nullable_int( mixed $value ): ?int {
		return null === $value ? null : (int) $value;
	}

	private static function authorized(): bool {
		return Access::can_view() && function_exists( 'get_current_user_id' ) && get_current_user_id() > 0
			&& defined( 'CB_CRM_SCHEMA_VERSION' )
			&& CB_CRM_SCHEMA_VERSION === (string) get_option( Schema::OPTION, '' );
	}

	private static function table_for( string $type ): string {
		return Rules::LEAD === $type ? Schema::sales_leads_table() : Schema::sales_opportunities_table();
	}

	private static function state_column( string $type ): string {
		return Rules::LEAD === $type ? 'status' : 'outcome';
	}

	private static function error( string $code, string $message ): \WP_Error {
		return new \WP_Error( $code, $message );
	}
}

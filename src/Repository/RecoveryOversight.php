<?php
declare(strict_types=1);

namespace CB\CRM\Repository;

use CB\CRM\Content\Entity;

defined( 'ABSPATH' ) || exit;

/**
 * Read-only bounded inventory of durable CRM deletion intents.
 *
 * No identities, email, names, notes, SQL option payloads or customer data
 * are projected. The operator must opt in to any subsequent single-ID retry.
 */
final class RecoveryOversight {
	public const MAX_PAGE_SIZE = 100;
	private const USER_PREFIX = 'cb_crm_user_anonymize_intent_';
	private const POST_PREFIX = 'cb_crm_delete_intent_';

	/**
	 * @return array{rows:list<array<string,int|string>>,cursor:int,has_more:bool}|\WP_Error
	 */
	public static function page( int $after = 0, int $limit = 20 ): array|\WP_Error {
		global $wpdb;
		if ( $after < 0 || $limit < 1 || $limit > self::MAX_PAGE_SIZE ) {
			return new \WP_Error( 'crm_recovery_invalid_page', 'Invalid recovery page parameters.' );
		}
		$wpdb->last_error = '';
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT option_id, option_name FROM {$wpdb->options}
				WHERE option_id > %d AND (option_name LIKE %s OR option_name LIKE %s)
				ORDER BY option_id ASC LIMIT %d",
				$after,
				$wpdb->esc_like( self::POST_PREFIX ) . '%',
				$wpdb->esc_like( self::USER_PREFIX ) . '%',
				$limit + 1
			),
			ARRAY_A
		);
		if ( ! is_array( $rows ) || '' !== (string) $wpdb->last_error ) {
			return new \WP_Error( 'crm_recovery_inventory_failed', 'Cannot read CRM recovery inventory.' );
		}
		$has_more = count( $rows ) > $limit;
		$rows = array_slice( $rows, 0, $limit );
		$result = [];
		$cursor = $after;
		foreach ( $rows as $row ) {
			$option_id = (int) ( $row['option_id'] ?? 0 );
			$name = $row['option_name'] ?? null;
			if ( $option_id <= $cursor || ! is_string( $name ) ) {
				return new \WP_Error( 'crm_recovery_invalid_row', 'Invalid CRM recovery inventory row.' );
			}
			$cursor = $option_id;
			$result[] = self::inspect_name( $name, $option_id );
		}
		return [ 'rows' => $result, 'cursor' => $cursor, 'has_more' => $has_more ];
	}

	/**
	 * Get one explicit intent before a privileged operator retry.
	 *
	 * @return array<string,int|string>|\WP_Error
	 */
	public static function inspect( string $kind, int $id ): array|\WP_Error {
		if ( $id <= 0 || ! in_array( $kind, [ Entity::CONTACT, Entity::ORGANIZATION, 'user' ], true ) ) {
			return new \WP_Error( 'crm_recovery_invalid_target', 'Invalid CRM recovery target.' );
		}
		if ( 'user' === $kind && is_multisite() ) {
			return new \WP_Error( 'crm_recovery_multisite_unsupported', 'WordPress user recovery is not supported on Multisite.' );
		}
		$key = ( 'user' === $kind ? self::USER_PREFIX : self::POST_PREFIX ) . $id;
		$view = self::inspect_name( $key, 0 );
		if ( 'missing' === $view['state'] ) {
			return new \WP_Error( 'crm_recovery_not_found', 'No durable CRM recovery intent exists for this ID.' );
		}
		if ( $kind !== $view['kind'] ) {
			return new \WP_Error( 'crm_recovery_identity_mismatch', 'Recovery intent type does not match the requested type.' );
		}
		return $view;
	}

	/**
	 * Returns only numeric identity references and neutral state labels.
	 *
	 * @return array{kind:string,id:int,state:string,scheduled:string,option_id:int}
	 */
	private static function inspect_name( string $name, int $option_id ): array {
		global $wpdb;
		$kind = 'invalid';
		$id = 0;
		$is_user = false;
		if ( preg_match( '/^cb_crm_user_anonymize_intent_([1-9][0-9]*)$/D', $name, $matches ) ) {
			$kind = 'user';
			$id = (int) $matches[1];
			$is_user = true;
		} elseif ( preg_match( '/^cb_crm_delete_intent_([1-9][0-9]*)$/D', $name, $matches ) ) {
			$id = (int) $matches[1];
			// Do not trust the payload to authorize a retry; this is only display.
		}
		$view = [ 'kind' => $kind, 'id' => $id, 'state' => 'invalid', 'scheduled' => 'no', 'option_id' => $option_id ];
		if ( $id <= 0 ) {
			return $view;
		}
		$intent = get_option( $name, null );
		if ( null === $intent ) {
			$view['state'] = 'missing';
			return $view;
		}
		if ( $is_user ) {
			if ( ! is_array( $intent ) || 1 !== ( $intent['version'] ?? null )
				|| $id !== ( $intent['user_id'] ?? null ) ) {
				return $view;
			}
		} else {
			if ( ! is_array( $intent ) || 1 !== ( $intent['version'] ?? null )
				|| $id !== ( $intent['id'] ?? null )
				|| ! in_array( $intent['type'] ?? null, [ Entity::CONTACT, Entity::ORGANIZATION ], true ) ) {
				return $view;
			}
			$kind = $intent['type'];
			$view['kind'] = $kind;
		}
		$hook = $is_user ? 'cb_crm_user_anonymize_reconcile' : 'cb_crm_lifecycle_reconcile';
		$view['scheduled'] = wp_next_scheduled( $hook, [ $id ] ) ? 'yes' : 'no';
		$wpdb->last_error = '';
		$table = $is_user ? $wpdb->users : $wpdb->posts;
		$found = $wpdb->get_var(
			$wpdb->prepare( "SELECT ID FROM {$table} WHERE ID = %d LIMIT 1", $id )
		);
		$view['state'] = '' !== (string) $wpdb->last_error
			? 'lookup_error'
			: ( null === $found ? 'ready' : 'identity_exists' );
		return $view;
	}
}

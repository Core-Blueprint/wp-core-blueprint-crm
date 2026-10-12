<?php
declare(strict_types=1);

namespace CB\CRM\Domain\Sales;

defined( 'ABSPATH' ) || exit;

/**
 * Storage-neutral rules for the CRM Sales domain.
 *
 * A CRM identity is never a Lead. Neither relationship record status nor Work
 * Item execution status belongs in these commercial lifecycles.
 */
final class Rules {
	public const LEAD = 'lead';
	public const OPPORTUNITY = 'opportunity';

	public const LEAD_OPEN = 'open';
	public const LEAD_QUALIFIED = 'qualified';
	public const LEAD_DISQUALIFIED = 'disqualified';
	public const LEAD_CONVERTED = 'converted';

	public const DEAL_OPEN = 'open';
	public const DEAL_WON = 'won';
	public const DEAL_LOST = 'lost';

	public const PARTICIPANT_ROLES = [ 'primary_contact', 'decision_maker', 'influencer', 'advisor', 'other' ];

	public static function valid_entity_type( string $type ): bool {
		return in_array( $type, [ self::LEAD, self::OPPORTUNITY ], true );
	}

	public static function valid_party_type( string $type ): bool {
		return in_array( $type, [ 'contact', 'organization' ], true );
	}

	public static function valid_participant_role( string $role ): bool {
		return in_array( $role, self::PARTICIPANT_ROLES, true );
	}

	public static function valid_lead_status( string $status ): bool {
		return in_array( $status, [ self::LEAD_OPEN, self::LEAD_QUALIFIED, self::LEAD_DISQUALIFIED, self::LEAD_CONVERTED ], true );
	}

	public static function valid_opportunity_outcome( string $outcome ): bool {
		return in_array( $outcome, [ self::DEAL_OPEN, self::DEAL_WON, self::DEAL_LOST ], true );
	}

	/** Only explicit qualification can lead to conversion. */
	public static function can_transition_lead( string $from, string $to ): bool {
		return match ( $from ) {
			self::LEAD_OPEN => in_array( $to, [ self::LEAD_QUALIFIED, self::LEAD_DISQUALIFIED ], true ),
			self::LEAD_QUALIFIED => in_array( $to, [ self::LEAD_DISQUALIFIED, self::LEAD_CONVERTED ], true ),
			default => false,
		};
	}

	/** Won/Lost are terminal outcomes; reopening must be an explicit action. */
	public static function can_close_deal( string $from, string $to ): bool {
		return self::DEAL_OPEN === $from && in_array( $to, [ self::DEAL_WON, self::DEAL_LOST ], true );
	}

	public static function can_reopen_deal( string $from, string $to ): bool {
		return in_array( $from, [ self::DEAL_WON, self::DEAL_LOST ], true ) && self::DEAL_OPEN === $to;
	}

	/** No invented currency conversion; an estimate has an explicit ISO-shaped code. */
	public static function valid_estimate( ?int $minor, ?string $currency ): bool {
		if ( null === $minor ) {
			return null === $currency || '' === $currency;
		}
		return $minor >= 0 && is_string( $currency ) && 1 === preg_match( '/^[A-Z]{3}$/D', $currency );
	}

	/**
	 * Structural validation only. Actual IDs must later be resolved against
	 * canonical CRM Contacts/Organizations by a governed repository action.
	 *
	 * @param array<int,array<string,mixed>> $participants
	 */
	public static function valid_participants( array $participants, bool $allow_empty = false ): bool {
		if ( [] === $participants ) {
			return $allow_empty;
		}
		$seen = [];
		foreach ( $participants as $participant ) {
			if ( ! is_array( $participant ) ) {
				return false;
			}
			$type = $participant['party_type'] ?? null;
			$id = $participant['party_id'] ?? null;
			$role = $participant['role'] ?? 'other';
			if ( ! is_string( $type ) || ! self::valid_party_type( $type )
				|| ! is_int( $id ) || $id <= 0
				|| ! is_string( $role ) || ! self::valid_participant_role( $role ) ) {
				return false;
			}
			$key = $type . ':' . $id . ':' . $role;
			if ( isset( $seen[ $key ] ) ) {
				return false;
			}
			$seen[ $key ] = true;
		}
		return true;
	}
}

<?php
declare(strict_types=1);

namespace CB\CRM\Frontend\Queries;

use CB\CRM\Frontend\Data\Contact;
use CB\Helpdesk\Frontend\Queries\Tickets as HelpdeskProvider;

defined( 'ABSPATH' ) || exit;

/**
 * Optional builder-neutral CRM → Helpdesk customer-context bridge.
 *
 * CRM owns Contact → WordPress-user identity. Helpdesk remains responsible
 * for ticket authorization and public ticket projection.
 */
final class HelpdeskTickets {
	private const MAX_LIMIT = 100;

	/** @return array<int,array<string,mixed>>|\WP_Error */
	public static function for_contact( int $contact_id, int $limit = 50 ): array|\WP_Error {
		$contact = Contact::get( $contact_id );
		if ( is_wp_error( $contact ) ) {
			return $contact;
		}
		if ( ! class_exists( HelpdeskProvider::class ) ) {
			return [];
		}

		$user_id = absint( $contact['linked_user_id'] ?? 0 );
		if ( $user_id <= 0 ) {
			return [];
		}

		$limit = max( 1, min( self::MAX_LIMIT, $limit ) );
		return HelpdeskProvider::for_user( $user_id, $limit );
	}

	public static function count_for_contact( int $contact_id, int $limit = self::MAX_LIMIT ): int|\WP_Error {
		$tickets = self::for_contact( $contact_id, $limit );
		return is_wp_error( $tickets ) ? $tickets : count( $tickets );
	}

	/**
	 * Whether the Contact has at least one Helpdesk ticket whose public status
	 * is the canonical `open` state.
	 */
	public static function has_open_for_contact( int $contact_id, int $limit = self::MAX_LIMIT ): bool {
		$tickets = self::for_contact( $contact_id, $limit );
		if ( is_wp_error( $tickets ) ) {
			return false;
		}

		foreach ( $tickets as $ticket ) {
			if ( 'open' === sanitize_key( (string) ( $ticket['status'] ?? '' ) ) ) {
				return true;
			}
		}
		return false;
	}
}

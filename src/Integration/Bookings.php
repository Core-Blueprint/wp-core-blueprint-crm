<?php
declare(strict_types=1);

namespace CB\CRM\Integration;

use CB\CRM\Application\ContactSnapshotProvisioner;
use CB\CRM\Content\Entity;
use CB\CRM\Governance;
use CB\CRM\Repository\Activity;

defined( 'ABSPATH' ) || exit;

/**
 * Optional Core Blueprint Bookings -> CRM integration.
 *
 * Bookings remains authoritative for appointment state. CRM reacts only to
 * the post-commit public booking-created event.
 */
final class Bookings {
	public static function init(): void {
		add_action( 'cb_bookings_booking_created', [ __CLASS__, 'booking_created' ], 20, 1 );
	}

	/** @param array<string,mixed> $booking */
	public static function booking_created( array $booking ): void {
		$booking_id = max( 0, (int) ( $booking['booking_id'] ?? 0 ) );
		if ( $booking_id <= 0 ) {
			return;
		}

		$email = is_scalar( $booking['customer_email'] ?? null )
			? strtolower( sanitize_email( (string) $booking['customer_email'] ) )
			: '';
		if ( '' === $email ) {
			return;
		}

		// This adapter consumes only the canonical structured Bookings v1
		// customer snapshot. It deliberately does not parse legacy display names.
		$result = ContactSnapshotProvisioner::ensure(
			[
				'first_name'  => $booking['customer_first_name'] ?? '',
				'name_prefix' => $booking['customer_name_prefix'] ?? '',
				'last_name'   => $booking['customer_last_name'] ?? '',
				'email'       => $email,
				'phone'       => $booking['customer_phone'] ?? '',
			]
		);
		if ( is_wp_error( $result ) ) {
			$reason = method_exists( $result, 'get_error_code' )
				? (string) $result->get_error_code()
				: 'crm_snapshot_provision_failed';
			if ( 'crm_snapshot_provision_busy' !== $reason ) {
				Governance::record_booking_provision_failed( $booking_id, $reason );
			}
			return;
		}

		$contact_id = (int) ( $result['contact_id'] ?? 0 );
		if ( $contact_id <= 0 ) {
			return;
		}

		Activity::record(
			Entity::CONTACT,
			$contact_id,
			'booking_created',
			sprintf( __( 'Booking #%d created', 'core-blueprint-crm' ), $booking_id ),
			'bookings',
			get_current_user_id(),
			'booking',
			(string) $booking_id,
			[]
		);
		Governance::record_booking_activity(
			$contact_id,
			$booking_id,
			! empty( $result['created'] )
		);
	}

	private function __construct() {}
}

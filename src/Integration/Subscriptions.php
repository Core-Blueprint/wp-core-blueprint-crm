<?php
declare(strict_types=1);

namespace CB\CRM\Integration;

use CB\CRM\Content\ContactIdentity;
use CB\CRM\Content\Entity;
use CB\CRM\PanelRegistry;
use CB\Subscriptions\Frontend\Queries;

defined( 'ABSPATH' ) || exit;

/** Optional read-only subscription context for a linked CRM Contact. */
final class Subscriptions {
	public static function init(): void {
		add_action( 'cb_crm_register_panels', [ __CLASS__, 'register_panel' ], 20 );
	}

	public static function register_panel(): void {
		if ( ! class_exists( Queries::class ) || ! self::can_view() ) {
			return;
		}

		PanelRegistry::register( [
			'id'         => 'subscriptions',
			'label'      => __( 'Subscriptions', 'cb-subscriptions' ),
			'post_types' => [ Entity::CONTACT ],
			'render'     => [ __CLASS__, 'render' ],
			'context'    => 'side',
			'priority'   => 'default',
		] );
	}

	public static function render( string $owner_type, int $contact_id ): void {
		if ( Entity::CONTACT !== $owner_type || ! class_exists( Queries::class ) || ! self::can_view() ) {
			return;
		}

		$user_id = ContactIdentity::linked_user_id( $contact_id );
		if ( $user_id <= 0 ) {
			echo '<p class="description">' . esc_html__( 'Optional. Link this CRM contact to an existing WordPress account.', 'core-blueprint-crm' ) . '</p>';
			return;
		}

		$subscriptions = Queries::for_user( $user_id, [ 'limit' => 10 ] );
		if ( [] === $subscriptions ) {
			echo '<p class="description">' . esc_html__( 'No current subscription is available.', 'cb-subscriptions' ) . '</p>';
			return;
		}

		echo '<ul style="margin:0;">';
		foreach ( $subscriptions as $subscription ) {
			if ( ! is_array( $subscription ) ) {
				continue;
			}

			$id       = absint( $subscription['subscription_id'] ?? 0 );
			$plan     = sanitize_text_field( (string) ( $subscription['plan_name'] ?? '' ) );
			$status   = sanitize_text_field( (string) ( $subscription['status_label'] ?? $subscription['status'] ?? '' ) );
			$price    = sanitize_text_field( (string) ( $subscription['plan_price_formatted'] ?? '' ) );
			$schedule = sanitize_text_field( (string) ( $subscription['billing_schedule'] ?? '' ) );
			$next     = sanitize_text_field( (string) ( $subscription['next_payment_formatted'] ?? '' ) );

			$title = '' !== $plan ? $plan : ( $id > 0 ? '#' . $id : __( 'Subscriptions', 'cb-subscriptions' ) );
			$meta  = array_values( array_filter( [ $status, $price, $schedule ], static fn( string $value ): bool => '' !== $value ) );

			echo '<li style="margin-bottom:8px;"><strong>' . esc_html( $title ) . '</strong>';
			if ( [] !== $meta ) {
				echo '<br><small>' . esc_html( implode( ' · ', $meta ) ) . '</small>';
			}
			if ( '' !== $next ) {
				echo '<br><small>' . esc_html__( 'Next payment', 'woocommerce' ) . ': ' . esc_html( $next ) . '</small>';
			}
			echo '</li>';
		}
		echo '</ul>';
	}

	private static function can_view(): bool {
		return current_user_can( 'manage_woocommerce' ) || current_user_can( 'manage_options' );
	}
}

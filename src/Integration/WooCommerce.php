<?php
declare(strict_types=1);

namespace CB\CRM\Integration;

use CB\CRM\Application\ContactProvisioner;
use CB\CRM\Content\ContactIdentity;
use CB\CRM\Content\Entity;
use CB\CRM\Governance;
use CB\CRM\PanelRegistry;
use CB\CRM\Repository\Activity;

defined( 'ABSPATH' ) || exit;

final class WooCommerce {
	private const PROVISION_STATUSES = [ 'processing', 'completed' ];

	public static function init(): void {
		add_action( 'cb_crm_register_panels', [ __CLASS__, 'register_panel' ], 20 );
		add_action( 'woocommerce_order_status_changed', [ __CLASS__, 'order_status_changed' ], 20, 4 );
		add_action( 'woocommerce_payment_complete', [ __CLASS__, 'payment_complete' ], 20, 1 );
		add_action( 'woocommerce_update_order', [ __CLASS__, 'order_updated' ], 20, 1 );
	}

	public static function register_panel(): void {
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return;
		}
		PanelRegistry::register( [
			'id'         => 'woocommerce-orders',
			'label'      => __( 'WooCommerce Orders', 'core-blueprint-crm' ),
			'post_types' => [ Entity::CONTACT ],
			'render'     => [ __CLASS__, 'render' ],
			'context'    => 'side',
			'priority'   => 'default',
		] );
	}

	public static function render( string $owner_type, int $contact_id ): void {
		unset( $owner_type );
		$user_id = ContactIdentity::linked_user_id( $contact_id );
		if ( $user_id <= 0 ) {
			echo '<p class="description">' . esc_html__( 'Link this contact to a WordPress user to show their WooCommerce orders.', 'core-blueprint-crm' ) . '</p>';
			return;
		}

		$orders = wc_get_orders( [
			'customer_id' => $user_id,
			'limit'       => 10,
			'orderby'     => 'date',
			'order'       => 'DESC',
			'return'      => 'objects',
		] );
		if ( ! $orders ) {
			echo '<p class="description">' . esc_html__( 'No WooCommerce orders for this user.', 'core-blueprint-crm' ) . '</p>';
			return;
		}

		echo '<ul style="margin:0;">';
		foreach ( $orders as $order ) {
			if ( ! $order instanceof \WC_Order ) {
				continue;
			}
			$date = $order->get_date_created();
			echo '<li style="margin-bottom:8px;"><a href="' . esc_url( $order->get_edit_order_url() ) . '"><strong>#' . esc_html( (string) $order->get_order_number() ) . '</strong></a> · ' . wp_kses_post( $order->get_formatted_order_total() ) . '<br><small>' . esc_html( wc_get_order_status_name( $order->get_status() ) ) . ( $date ? ' · ' . esc_html( $date->date_i18n( get_option( 'date_format' ) ) ) : '' ) . '</small></li>';
		}
		echo '</ul>';
	}

	public static function order_status_changed( int $order_id, string $from, string $to, $order ): void {
		unset( $order );
		$contact_id = self::reconcile_order( $order_id, 'order_status' );
		if ( $contact_id <= 0 ) {
			return;
		}

		Activity::record(
			Entity::CONTACT,
			$contact_id,
			'order_status_changed',
			sprintf( __( 'WooCommerce order #%1$d changed from %2$s to %3$s', 'core-blueprint-crm' ), $order_id, $from, $to ),
			'woocommerce',
			get_current_user_id(),
			'order',
			(string) $order_id,
			[ 'from' => $from, 'to' => $to ]
		);
		Governance::record_order_activity( $contact_id, $order_id, $from, $to );
	}

	public static function payment_complete( int $order_id ): void {
		self::reconcile_order( $order_id, 'payment_complete' );
	}

	public static function order_updated( int $order_id ): void {
		self::reconcile_order( $order_id, 'order_update' );
	}

	private static function reconcile_order( int $order_id, string $source ): int {
		if ( $order_id <= 0 || ! function_exists( 'wc_get_order' ) ) {
			return 0;
		}
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof \WC_Order ) {
			return 0;
		}

		$status = sanitize_key( str_replace( 'wc-', '', (string) $order->get_status() ) );
		if ( ! in_array( $status, self::PROVISION_STATUSES, true ) ) {
			return 0;
		}

		$user_id = (int) $order->get_customer_id();
		if ( $user_id <= 0 ) {
			return 0;
		}

		$linked = ContactIdentity::find_by_user_id( $user_id );
		if ( $linked && empty( $linked['ambiguous'] ) ) {
			return (int) $linked['contact_id'];
		}
		if ( $linked && ! empty( $linked['ambiguous'] ) ) {
			Governance::record_order_provision_failed( $order_id, $user_id, 'crm_identity_conflict', $source );
			return 0;
		}

		return self::provision_for_user( $user_id, $order_id, $source );
	}

	private static function provision_for_user( int $user_id, int $order_id, string $source ): int {
		$result = ContactProvisioner::ensure_for_user( $user_id, 0 );
		if ( is_wp_error( $result ) ) {
			$reason = method_exists( $result, 'get_error_code' ) ? (string) $result->get_error_code() : 'crm_identity_provision_failed';
			if ( 'crm_identity_provision_busy' !== $reason ) {
				Governance::record_order_provision_failed( $order_id, $user_id, $reason, $source );
			}
			return 0;
		}
		return (int) ( $result['contact_id'] ?? 0 );
	}
}

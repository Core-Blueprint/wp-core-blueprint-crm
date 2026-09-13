<?php
declare(strict_types=1);

namespace CB\CRM\Integration;

use CB\CRM\Capabilities;
use CB\CRM\Content\ContactIdentity;
use CB\CRM\Content\Entity;
use CB\CRM\Content\PostTypes;
use CB\CRM\Repository\Activity;

defined( 'ABSPATH' ) || exit;

/**
 * Safe WooCommerce persistence for fields presented inside the unified CRM
 * Contact workspace. WooCommerce remains the authority for customer data.
 */
final class WooCustomerWorkspace {
	/** @var array<int,bool> */
	private static array $save_failed = [];

	public static function init(): void {
		// WordPress fires save_post_{post_type} before the generic save_post hook
		// used by CRM identity persistence. The payload therefore remains bound
		// to the user for which the unified workspace was actually rendered.
		add_action( 'save_post_' . PostTypes::CONTACT, [ __CLASS__, 'save_customer' ], 15, 3 );
		add_filter( 'redirect_post_location', [ __CLASS__, 'redirect_post_location' ], 100, 2 );
		add_action( 'admin_notices', [ __CLASS__, 'admin_notice' ] );
	}

	public static function render_binding( int $contact_id ): void {
		$customer = ContactDataSources::woo_customer( $contact_id );
		$user_id = ContactIdentity::linked_user_id( $contact_id );
		if ( ! is_object( $customer ) || $user_id <= 0 ) {
			return;
		}
		echo '<input type="hidden" name="cb_crm_woo_customer_present" value="1">';
		echo '<input type="hidden" name="cb_crm_woo_customer_user_id" value="' . esc_attr( (string) $user_id ) . '">';
	}

	public static function save_customer( int $post_id, \WP_Post $post, bool $update ): void {
		unset( $update );
		if ( 'trash' === $post->post_status || wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) ) {
			return;
		}
		if ( ! isset( $_POST['cb_crm_woo_customer_present'], $_POST['cb_crm_nonce'] ) ) {
			return;
		}
		if ( ! wp_verify_nonce( sanitize_text_field( (string) wp_unslash( $_POST['cb_crm_nonce'] ) ), 'cb_crm_save_record' ) ) {
			return;
		}
		if ( ! current_user_can( Capabilities::MANAGE ) || ! current_user_can( 'edit_post', $post_id ) || ! self::can_edit_customer() ) {
			return;
		}
		if ( ! class_exists( '\\WC_Customer' ) ) {
			return;
		}

		$user_id = ContactIdentity::linked_user_id( $post_id );
		$expected_user_id = isset( $_POST['cb_crm_woo_customer_user_id'] ) ? absint( $_POST['cb_crm_woo_customer_user_id'] ) : 0;
		if ( $user_id <= 0 || $expected_user_id <= 0 || $user_id !== $expected_user_id ) {
			return;
		}

		// If this same form submission is changing the CRM ↔ WP identity link,
		// fail closed for Woo data. After the Contact reloads, the workspace is
		// rendered for the newly persisted identity and can be edited safely.
		$requested_user_id = $expected_user_id;
		if ( isset( $_POST['cb_crm_details'] ) && is_array( $_POST['cb_crm_details'] ) ) {
			$details = wp_unslash( $_POST['cb_crm_details'] );
			if ( array_key_exists( 'wp_user_id', $details ) ) {
				if ( ! is_scalar( $details['wp_user_id'] ) ) {
					return;
				}
				$requested_user_id = absint( $details['wp_user_id'] );
			}
		}
		if ( $requested_user_id !== $expected_user_id ) {
			return;
		}

		$posted = isset( $_POST['cb_crm_woo_customer'] ) && is_array( $_POST['cb_crm_woo_customer'] ) ? wp_unslash( $_POST['cb_crm_woo_customer'] ) : [];
		if ( [] === $posted ) {
			return;
		}

		try {
			$customer = new \WC_Customer( $user_id );
			if ( (int) $customer->get_id() !== $user_id ) {
				return;
			}
			$changed = [];
			foreach ( self::field_keys( $customer ) as $key ) {
				if ( ! array_key_exists( $key, $posted ) || ! is_scalar( $posted[ $key ] ) ) {
					continue;
				}
				$getter = 'get_' . $key;
				$setter = 'set_' . $key;
				if ( ! is_callable( [ $customer, $getter ] ) || ! is_callable( [ $customer, $setter ] ) ) {
					continue;
				}
				$value = self::clean_value( $key, (string) $posted[ $key ] );
				if ( (string) $customer->{$getter}( 'edit' ) === $value ) {
					continue;
				}
				$customer->{$setter}( $value );
				$changed[] = $key;
			}
			if ( [] === $changed ) {
				return;
			}
			if ( (int) $customer->save() <= 0 ) {
				self::$save_failed[ $post_id ] = true;
				return;
			}

			Activity::record(
				Entity::CONTACT,
				$post_id,
				'woocommerce_customer_updated',
				__( 'Customer updated', 'woocommerce' ),
				'woocommerce',
				get_current_user_id(),
				'user',
				(string) $user_id,
				[ 'fields' => implode( ',', $changed ) ]
			);
		} catch ( \Throwable ) {
			self::$save_failed[ $post_id ] = true;
		}
	}

	public static function redirect_post_location( string $location, int $post_id ): string {
		return empty( self::$save_failed[ $post_id ] ) ? $location : add_query_arg( 'cb_crm_woo_customer_error', '1', $location );
	}

	public static function admin_notice(): void {
		if ( ! isset( $_GET['cb_crm_woo_customer_error'] ) || ! current_user_can( Capabilities::MANAGE ) ) {
			return;
		}
		$screen = get_current_screen();
		if ( ! $screen || PostTypes::CONTACT !== (string) $screen->post_type ) {
			return;
		}
		printf(
			'<div class="notice notice-error is-dismissible"><p>%s</p></div>',
			esc_html( 'WooCommerce: ' . __( 'Error', 'woocommerce' ) . '. ' . __( 'Please try again.', 'woocommerce' ) )
		);
	}

	public static function can_edit_customer(): bool {
		return current_user_can( 'manage_woocommerce' ) || current_user_can( 'manage_options' );
	}

	/** @return array<string,string> */
	public static function countries(): array {
		if ( ! function_exists( 'WC' ) ) {
			return [];
		}
		$woocommerce = WC();
		if ( ! is_object( $woocommerce ) || ! isset( $woocommerce->countries ) || ! is_object( $woocommerce->countries ) || ! is_callable( [ $woocommerce->countries, 'get_countries' ] ) ) {
			return [];
		}
		$countries = $woocommerce->countries->get_countries();
		return is_array( $countries ) ? $countries : [];
	}

	/** @return string[] */
	private static function field_keys( object $customer ): array {
		$keys = [
			'billing_first_name', 'billing_last_name', 'billing_company', 'billing_email', 'billing_phone',
			'billing_address_1', 'billing_address_2', 'billing_city', 'billing_state', 'billing_postcode', 'billing_country',
			'shipping_first_name', 'shipping_last_name', 'shipping_company',
			'shipping_address_1', 'shipping_address_2', 'shipping_city', 'shipping_state', 'shipping_postcode', 'shipping_country',
		];
		if ( is_callable( [ $customer, 'get_shipping_phone' ] ) && is_callable( [ $customer, 'set_shipping_phone' ] ) ) {
			$keys[] = 'shipping_phone';
		}
		return $keys;
	}

	private static function clean_value( string $key, string $value ): string {
		$value = trim( $value );
		if ( str_ends_with( $key, '_country' ) ) {
			return strtoupper( substr( preg_replace( '/[^A-Za-z]/', '', $value ), 0, 2 ) );
		}
		if ( 'billing_email' === $key ) {
			return $value;
		}
		return function_exists( 'wc_clean' ) ? (string) wc_clean( $value ) : sanitize_text_field( $value );
	}
}

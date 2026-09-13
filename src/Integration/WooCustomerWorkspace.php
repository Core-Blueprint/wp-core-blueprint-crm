<?php
declare(strict_types=1);

namespace CB\CRM\Integration;

use CB\CRM\Capabilities;
use CB\CRM\Content\ContactIdentity;
use CB\CRM\Content\Entity;
use CB\CRM\Content\PostTypes;
use CB\CRM\PanelRegistry;
use CB\CRM\Repository\Activity;

defined( 'ABSPATH' ) || exit;

/**
 * Operator workspace for WooCommerce-owned customer profile data.
 *
 * CRM provides the workspace and canonical WP-user relation. WooCommerce
 * remains authority for billing/shipping data: reads and writes both travel
 * through WC_Customer. Historical WC_Order snapshots are never modified.
 */
final class WooCustomerWorkspace {
	/** @var array<int,bool> */
	private static array $save_failed = [];

	public static function init(): void {
		add_action( 'cb_crm_register_panels', [ __CLASS__, 'register_panel' ], 20 );
		// WordPress fires save_post_{post_type} before the generic save_post hook
		// used by CRM identity persistence. The payload therefore remains bound
		// to the user for which this workspace was actually rendered.
		add_action( 'save_post_' . PostTypes::CONTACT, [ __CLASS__, 'save_customer' ], 15, 3 );
		add_filter( 'redirect_post_location', [ __CLASS__, 'redirect_post_location' ], 100, 2 );
		add_action( 'admin_notices', [ __CLASS__, 'admin_notice' ] );
	}

	public static function register_panel(): void {
		if ( ! class_exists( '\\WC_Customer' ) ) {
			return;
		}
		PanelRegistry::register( [
			'id'         => 'woocommerce-customer',
			'label'      => 'WooCommerce ' . __( 'Customer', 'woocommerce' ),
			'post_types' => [ Entity::CONTACT ],
			'render'     => [ __CLASS__, 'render' ],
			'context'    => 'normal',
			'priority'   => 'default',
		] );
	}

	public static function render( string $owner_type, int $contact_id ): void {
		unset( $owner_type );
		$user_id = ContactIdentity::linked_user_id( $contact_id );
		if ( $user_id <= 0 ) {
			echo '<p class="description">' . esc_html__( 'Optional. Link this CRM contact to an existing WordPress account.', 'core-blueprint-crm' ) . '</p>';
			return;
		}
		if ( ! class_exists( '\\WC_Customer' ) ) {
			return;
		}

		try {
			$customer = new \WC_Customer( $user_id );
		} catch ( \Throwable ) {
			return;
		}
		if ( (int) $customer->get_id() !== $user_id ) {
			return;
		}

		$can_edit = self::can_edit_customer();
		$countries = self::countries();
		?>
		<input type="hidden" name="cb_crm_woo_customer_present" value="1">
		<input type="hidden" name="cb_crm_woo_customer_user_id" value="<?php echo esc_attr( (string) $user_id ); ?>">
		<table class="form-table" role="presentation"><tbody>
		<?php foreach ( self::field_groups( $customer ) as $group_label => $fields ) : ?>
			<tr><th colspan="2"><strong><?php echo esc_html( $group_label ); ?></strong></th></tr>
			<?php foreach ( $fields as $key => $field ) : ?>
				<?php self::render_field( $customer, $key, $field, $countries, $can_edit ); ?>
			<?php endforeach; ?>
		<?php endforeach; ?>
		</tbody></table>
		<?php
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

	private static function can_edit_customer(): bool {
		return current_user_can( 'manage_woocommerce' ) || current_user_can( 'manage_options' );
	}

	/** @return array<string,array<string,array{label:string,type:string}>> */
	private static function field_groups( \WC_Customer $customer ): array {
		$billing = [
			'billing_first_name' => [ 'label' => __( 'First name', 'woocommerce' ), 'type' => 'text' ],
			'billing_last_name'  => [ 'label' => __( 'Last name', 'woocommerce' ), 'type' => 'text' ],
			'billing_company'    => [ 'label' => __( 'Company', 'woocommerce' ), 'type' => 'text' ],
			'billing_email'      => [ 'label' => __( 'Email address', 'woocommerce' ), 'type' => 'email' ],
			'billing_phone'      => [ 'label' => __( 'Phone', 'woocommerce' ), 'type' => 'tel' ],
			'billing_address_1'  => [ 'label' => __( 'Address line 1', 'woocommerce' ), 'type' => 'text' ],
			'billing_address_2'  => [ 'label' => __( 'Address line 2', 'woocommerce' ), 'type' => 'text' ],
			'billing_city'       => [ 'label' => __( 'Town / City', 'woocommerce' ), 'type' => 'text' ],
			'billing_state'      => [ 'label' => __( 'State / County', 'woocommerce' ), 'type' => 'text' ],
			'billing_postcode'   => [ 'label' => __( 'Postcode / ZIP', 'woocommerce' ), 'type' => 'text' ],
			'billing_country'    => [ 'label' => __( 'Country / Region', 'woocommerce' ), 'type' => 'country' ],
		];
		$shipping = [
			'shipping_first_name' => [ 'label' => __( 'First name', 'woocommerce' ), 'type' => 'text' ],
			'shipping_last_name'  => [ 'label' => __( 'Last name', 'woocommerce' ), 'type' => 'text' ],
			'shipping_company'    => [ 'label' => __( 'Company', 'woocommerce' ), 'type' => 'text' ],
			'shipping_address_1'  => [ 'label' => __( 'Address line 1', 'woocommerce' ), 'type' => 'text' ],
			'shipping_address_2'  => [ 'label' => __( 'Address line 2', 'woocommerce' ), 'type' => 'text' ],
			'shipping_city'       => [ 'label' => __( 'Town / City', 'woocommerce' ), 'type' => 'text' ],
			'shipping_state'      => [ 'label' => __( 'State / County', 'woocommerce' ), 'type' => 'text' ],
			'shipping_postcode'   => [ 'label' => __( 'Postcode / ZIP', 'woocommerce' ), 'type' => 'text' ],
			'shipping_country'    => [ 'label' => __( 'Country / Region', 'woocommerce' ), 'type' => 'country' ],
		];
		if ( is_callable( [ $customer, 'get_shipping_phone' ] ) && is_callable( [ $customer, 'set_shipping_phone' ] ) ) {
			$shipping = array_merge(
				array_slice( $shipping, 0, 3, true ),
				[ 'shipping_phone' => [ 'label' => __( 'Phone', 'woocommerce' ), 'type' => 'tel' ] ],
				array_slice( $shipping, 3, null, true )
			);
		}
		return [
			__( 'Billing address', 'woocommerce' )  => $billing,
			__( 'Shipping address', 'woocommerce' ) => $shipping,
		];
	}

	/** @return string[] */
	private static function field_keys( \WC_Customer $customer ): array {
		$keys = [];
		foreach ( self::field_groups( $customer ) as $fields ) {
			$keys = array_merge( $keys, array_keys( $fields ) );
		}
		return array_values( array_unique( $keys ) );
	}

	/** @param array{label:string,type:string} $field @param array<string,string> $countries */
	private static function render_field( \WC_Customer $customer, string $key, array $field, array $countries, bool $can_edit ): void {
		$getter = 'get_' . $key;
		if ( ! is_callable( [ $customer, $getter ] ) ) {
			return;
		}
		$value = (string) $customer->{$getter}( 'edit' );
		$name = 'cb_crm_woo_customer[' . $key . ']';
		$id = 'cb-crm-woo-' . str_replace( '_', '-', $key );
		?>
		<tr>
			<th><label for="<?php echo esc_attr( $id ); ?>"><?php echo esc_html( $field['label'] ); ?></label></th>
			<td>
			<?php if ( 'country' === $field['type'] && [] !== $countries ) : ?>
				<select id="<?php echo esc_attr( $id ); ?>" name="<?php echo esc_attr( $name ); ?>" <?php disabled( ! $can_edit ); ?>>
					<option value=""></option>
					<?php foreach ( $countries as $code => $label ) : ?><option value="<?php echo esc_attr( $code ); ?>" <?php selected( $value, $code ); ?>><?php echo esc_html( $label ); ?></option><?php endforeach; ?>
				</select>
			<?php else : ?>
				<input type="<?php echo esc_attr( $field['type'] ); ?>" class="regular-text" id="<?php echo esc_attr( $id ); ?>" name="<?php echo esc_attr( $name ); ?>" value="<?php echo esc_attr( $value ); ?>" <?php disabled( ! $can_edit ); ?>>
			<?php endif; ?>
			</td>
		</tr>
		<?php
	}

	/** @return array<string,string> */
	private static function countries(): array {
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

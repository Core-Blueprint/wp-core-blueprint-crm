<?php
declare(strict_types=1);

namespace CB\CRM\Integration;

use CB\CRM\Content\ContactIdentity;
use CB\CRM\Content\Entity;
use CB\CRM\Content\Meta;
use CB\CRM\Repository\ContactMethods;

defined( 'ABSPATH' ) || exit;

/**
 * Contact-scoped source resolution for the launch workspace.
 *
 * This is deliberately not a generic provider framework. WordPress and
 * WooCommerce remain the authorities for their own data; CRM only stores an
 * explicit relationship override when an operator chooses one.
 */
final class ContactDataSources {
	/** @var array<int,\WP_User|null> */
	private static array $users = [];

	/** @var array<int,object|null> */
	private static array $customers = [];

	public static function wordpress_user( int $contact_id ): ?\WP_User {
		if ( array_key_exists( $contact_id, self::$users ) ) {
			return self::$users[ $contact_id ];
		}
		$user_id = ContactIdentity::linked_user_id( $contact_id );
		$user = $user_id > 0 ? get_userdata( $user_id ) : false;
		self::$users[ $contact_id ] = $user instanceof \WP_User ? $user : null;
		return self::$users[ $contact_id ];
	}

	public static function woo_customer( int $contact_id ): ?object {
		if ( array_key_exists( $contact_id, self::$customers ) ) {
			return self::$customers[ $contact_id ];
		}
		self::$customers[ $contact_id ] = null;
		if ( ! class_exists( '\\WC_Customer' ) ) {
			return null;
		}
		$user_id = ContactIdentity::linked_user_id( $contact_id );
		if ( $user_id <= 0 ) {
			return null;
		}
		try {
			$customer = new \WC_Customer( $user_id );
		} catch ( \Throwable ) {
			return null;
		}
		if ( ! is_callable( [ $customer, 'get_id' ] ) || (int) $customer->get_id() !== $user_id ) {
			return null;
		}
		self::$customers[ $contact_id ] = $customer;
		return $customer;
	}

	public static function woo_value( int $contact_id, string $key ): string {
		$customer = self::woo_customer( $contact_id );
		$getter = 'get_' . sanitize_key( $key );
		if ( ! is_object( $customer ) || ! is_callable( [ $customer, $getter ] ) ) {
			return '';
		}
		try {
			return sanitize_text_field( (string) $customer->{$getter}( 'edit' ) );
		} catch ( \Throwable ) {
			return '';
		}
	}

	public static function name_prefix( int $contact_id ): string {
		return sanitize_text_field( (string) get_post_meta( $contact_id, Meta::NAME_PREFIX, true ) );
	}

	/**
	 * @return array<int,array{source:string,path:string,value:string}>
	 */
	public static function name_candidates( int $contact_id, string $field ): array {
		if ( ! in_array( $field, [ 'first_name', 'last_name' ], true ) ) {
			return [];
		}
		$candidates = [];
		$user = self::wordpress_user( $contact_id );
		if ( $user instanceof \WP_User ) {
			$value = sanitize_text_field( (string) get_user_meta( (int) $user->ID, $field, true ) );
			if ( '' !== $value ) {
				$candidates[] = [ 'source' => 'wordpress', 'path' => $field, 'value' => $value ];
			}
		}
		$woo_key = 'billing_' . $field;
		$woo_value = self::woo_value( $contact_id, $woo_key );
		if ( '' !== $woo_value ) {
			$candidates[] = [ 'source' => 'woocommerce', 'path' => $woo_key, 'value' => $woo_value ];
		}
		return $candidates;
	}

	/**
	 * @return array{value:string,source:string,path:string,override:bool,candidates:array<int,array{source:string,path:string,value:string}>}
	 */
	public static function name_field( int $contact_id, string $field ): array {
		$meta_key = 'first_name' === $field ? Meta::FIRST_NAME : Meta::LAST_NAME;
		$crm_value = sanitize_text_field( (string) get_post_meta( $contact_id, $meta_key, true ) );
		$candidates = self::name_candidates( $contact_id, $field );

		if ( '' !== $crm_value ) {
			foreach ( $candidates as $candidate ) {
				if ( $crm_value === $candidate['value'] ) {
					return [
						'value' => $candidate['value'],
						'source' => $candidate['source'],
						'path' => $candidate['path'],
						'override' => false,
						'candidates' => $candidates,
					];
				}
			}
			return [
				'value' => $crm_value,
				'source' => 'crm',
				'path' => $meta_key,
				'override' => [] !== $candidates,
				'candidates' => $candidates,
			];
		}

		if ( [] !== $candidates ) {
			$candidate = $candidates[0];
			return [
				'value' => $candidate['value'],
				'source' => $candidate['source'],
				'path' => $candidate['path'],
				'override' => false,
				'candidates' => $candidates,
			];
		}

		return [
			'value' => '',
			'source' => 'crm',
			'path' => $meta_key,
			'override' => false,
			'candidates' => [],
		];
	}

	/**
	 * @return array<int,array{type:string,label:string,value:string,source:string,path:string,woo_key:string}>
	 */
	public static function external_contact_methods( int $contact_id ): array {
		$rows = [];
		$user = self::wordpress_user( $contact_id );
		if ( $user instanceof \WP_User ) {
			$email = sanitize_email( (string) $user->user_email );
			if ( is_email( $email ) ) {
				$rows[] = [
					'type' => 'email',
					'label' => 'WordPress',
					'value' => $email,
					'source' => 'wordpress',
					'path' => 'user_email',
					'woo_key' => '',
				];
			}
		}

		foreach ( [
			'billing_email' => [ 'email', 'WooCommerce billing' ],
			'billing_phone' => [ 'phone', 'WooCommerce billing' ],
			'shipping_phone' => [ 'phone', 'WooCommerce shipping' ],
		] as $key => $definition ) {
			$value = self::woo_value( $contact_id, $key );
			if ( '' === $value ) {
				continue;
			}
			if ( 'email' === $definition[0] ) {
				$value = sanitize_email( $value );
				if ( ! is_email( $value ) ) {
					continue;
				}
			}
			$rows[] = [
				'type' => $definition[0],
				'label' => $definition[1],
				'value' => $value,
				'source' => 'woocommerce',
				'path' => $key,
				'woo_key' => $key,
			];
		}
		return $rows;
	}

	public static function effective_email( int $contact_id ): string {
		$email = ContactIdentity::preferred_email( $contact_id );
		if ( '' !== $email ) {
			return $email;
		}
		$woo = sanitize_email( self::woo_value( $contact_id, 'billing_email' ) );
		return is_email( $woo ) ? $woo : '';
	}

	/** @return array{value:string,source:string,path:string} */
	public static function effective_email_field( int $contact_id ): array {
		$value = sanitize_email( self::effective_email( $contact_id ) );
		if ( ! is_email( $value ) ) {
			return [ 'value' => '', 'source' => '', 'path' => '' ];
		}

		$mode = sanitize_key( (string) get_post_meta( $contact_id, Meta::EMAIL_MODE, true ) );
		$account_email = sanitize_email( ContactIdentity::account_email( $contact_id ) );
		if (
			ContactIdentity::EMAIL_WP === $mode
			&& is_email( $account_email )
			&& 0 === strcasecmp( $value, $account_email )
		) {
			return [ 'value' => $value, 'source' => 'wordpress', 'path' => 'user_email' ];
		}

		$primary = ContactMethods::primary( Entity::CONTACT, $contact_id, 'email' );
		$crm_email = is_array( $primary ) ? sanitize_email( (string) ( $primary['value'] ?? '' ) ) : '';
		if ( is_email( $crm_email ) && 0 === strcasecmp( $value, $crm_email ) ) {
			return [ 'value' => $value, 'source' => 'crm', 'path' => 'contact_methods.primary_email' ];
		}

		if ( is_email( $account_email ) && 0 === strcasecmp( $value, $account_email ) ) {
			return [ 'value' => $value, 'source' => 'wordpress', 'path' => 'user_email' ];
		}

		$woo_email = sanitize_email( self::woo_value( $contact_id, 'billing_email' ) );
		if ( is_email( $woo_email ) && 0 === strcasecmp( $value, $woo_email ) ) {
			return [ 'value' => $value, 'source' => 'woocommerce', 'path' => 'billing_email' ];
		}

		return [ 'value' => $value, 'source' => 'crm', 'path' => 'effective_email' ];
	}

	public static function effective_phone( int $contact_id ): string {
		$phone = ContactMethods::primary( Entity::CONTACT, $contact_id, 'phone' );
		if ( ! is_array( $phone ) ) {
			$phone = ContactMethods::primary( Entity::CONTACT, $contact_id, 'mobile' );
		}
		if ( is_array( $phone ) ) {
			$value = sanitize_text_field( (string) ( $phone['value'] ?? '' ) );
			if ( '' !== $value ) {
				return $value;
			}
		}
		$billing = self::woo_value( $contact_id, 'billing_phone' );
		return '' !== $billing ? $billing : self::woo_value( $contact_id, 'shipping_phone' );
	}
}

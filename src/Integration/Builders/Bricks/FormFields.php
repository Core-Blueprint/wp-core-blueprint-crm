<?php
declare(strict_types=1);

namespace CB\CRM\Integration\Builders\Bricks;

defined( 'ABSPATH' ) || exit;

final class FormFields {
	public static function has_mapping( object $form, string $setting_key ): bool {
		if ( ! method_exists( $form, 'get_settings' ) ) {
			return false;
		}
		$settings = $form->get_settings();
		return is_array( $settings ) && '' !== self::mapped_field_id( $settings[ $setting_key ] ?? '' );
	}

	public static function value( object $form, string $setting_key ): mixed {
		if ( ! method_exists( $form, 'get_settings' ) || ! method_exists( $form, 'get_fields' ) ) {
			return '';
		}

		$settings = $form->get_settings();
		$fields   = $form->get_fields();
		if ( ! is_array( $settings ) || ! is_array( $fields ) ) {
			return '';
		}

		$field_id = self::mapped_field_id( $settings[ $setting_key ] ?? '' );
		if ( '' === $field_id ) {
			return '';
		}

		foreach ( [ $field_id, 'form-field-' . $field_id ] as $key ) {
			if ( array_key_exists( $key, $fields ) ) {
				return $fields[ $key ];
			}
		}
		return '';
	}

	private static function mapped_field_id( mixed $value ): string {
		if ( is_array( $value ) ) {
			$value = reset( $value );
		}
		if ( ! is_scalar( $value ) ) {
			return '';
		}

		$field_id = trim( (string) $value );
		if ( str_starts_with( $field_id, 'form-field-' ) ) {
			$field_id = substr( $field_id, strlen( 'form-field-' ) );
		}
		return preg_match( '/^[A-Za-z0-9_-]+$/', $field_id ) ? $field_id : '';
	}
}

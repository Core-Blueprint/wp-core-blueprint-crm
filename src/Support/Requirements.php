<?php
declare(strict_types=1);

namespace CB\CRM\Support;

defined( 'ABSPATH' ) || exit;

final class Requirements {
	public static function api_compatible( string $available, string $required ): bool {
		if ( 1 !== preg_match( '/^(\d+)\.(\d+)$/', $available, $available_match ) ) {
			return false;
		}
		if ( 1 !== preg_match( '/^(\d+)\.(\d+)$/', $required, $required_match ) ) {
			return false;
		}

		return (int) $available_match[1] === (int) $required_match[1]
			&& (int) $available_match[2] >= (int) $required_match[2];
	}

	/** @return string[] Bootstrap v1 blockers only. */
	public static function issues(): array {
		$issues = [];

		if ( version_compare( PHP_VERSION, CB_CRM_MIN_PHP, '<' ) ) {
			$issues[] = 'php-version';
		}
		if ( ! defined( 'CB_CORE_API_VERSION' ) ) {
			$issues[] = 'base-missing';
			return $issues;
		}
		if ( ! self::api_compatible( (string) CB_CORE_API_VERSION, CB_CRM_REQUIRED_API ) ) {
			$issues[] = 'base-api-incompatible';
		}

		return array_values( array_unique( $issues ) );
	}

	public static function runtime_ready(): bool {
		return [] === self::issues();
	}

	public static function operator_message(): string {
		$issues = self::issues();
		$issue  = (string) ( $issues[0] ?? '' );

		switch ( $issue ) {
			case 'php-version':
				// The main plugin file handles this before product localization is safe.
				return sprintf( 'PHP %s or newer is required. This server runs PHP %s.', CB_CRM_MIN_PHP, PHP_VERSION );
			case 'base-missing':
				return __( 'Core Blueprint CRM requires an active Core Blueprint Base plugin.', 'core-blueprint-crm' );
			case 'base-api-incompatible':
				return sprintf(
					/* translators: 1: required Core API version, 2: available Core API version. */
					__( 'Core Blueprint CRM requires Core API %1$s or a newer compatible minor version. This site provides %2$s.', 'core-blueprint-crm' ),
					CB_CRM_REQUIRED_API,
					(string) CB_CORE_API_VERSION
				);
			default:
				return 'Ready';
		}
	}
}

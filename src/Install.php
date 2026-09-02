<?php
declare(strict_types=1);

namespace CB\CRM;

use CB\CRM\Database\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * CRM install/upgrade lifecycle.
 *
 * Functional CRM access is granted by default to both Core Blueprint
 * Operators and WordPress Administrators. Other roles may receive the
 * capability explicitly through Core Blueprint Base's User Roles tooling.
 */
final class Install {
	private const OPTION_VERSION = 'cb_crm_install_version';
	private const VERSION        = '1';

	public static function activate(): void {
		Capabilities::install();
		Schema::register();
		update_option( self::OPTION_VERSION, self::VERSION, false );
	}

	/**
	 * Apply one-time install policy upgrades for already-active builds.
	 *
	 * This is deliberately marker-driven rather than a per-request auto-heal:
	 * role state is mutated only when the CRM install policy version advances
	 * or when upgrading from a build that predates the marker.
	 */
	public static function maybe_upgrade(): void {
		if ( self::VERSION === (string) get_option( self::OPTION_VERSION, '' ) ) {
			return;
		}

		Capabilities::install();
		update_option( self::OPTION_VERSION, self::VERSION, false );
	}
}

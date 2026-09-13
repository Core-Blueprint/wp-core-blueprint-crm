<?php
declare(strict_types=1);

namespace {
	$options = [ 'cb_crm_install_version' => '1' ];
	$capability_installs = 0;
	$schema_registers = 0;

	if ( ! defined( 'ABSPATH' ) ) {
		define( 'ABSPATH', __DIR__ . '/' );
	}

	function get_option( string $name, mixed $default = false ): mixed {
		global $options;
		return array_key_exists( $name, $options ) ? $options[ $name ] : $default;
	}

	function update_option( string $name, mixed $value, bool $autoload = true ): bool {
		global $options;
		unset( $autoload );
		$options[ $name ] = $value;
		return true;
	}
}

namespace CB\CRM {
	final class Capabilities {
		public static function install(): void {
			global $capability_installs;
			$capability_installs++;
		}
	}
}

namespace CB\CRM\Database {
	final class Schema {
		public static function register(): void {
			global $schema_registers;
			$schema_registers++;
		}
	}
}

namespace {
	require_once dirname( __DIR__ ) . '/src/Install.php';

	use CB\CRM\Install;

	$failed = false;
	$assert = static function ( bool $condition, string $message ) use ( &$failed ): void {
		if ( ! $condition ) {
			fwrite( STDERR, "CRM install policy smoke failed: {$message}\n" );
			$failed = true;
		}
	};

	Install::maybe_upgrade();
	$assert( 1 === $capability_installs, 'policy version 1 must re-apply capabilities exactly once when upgrading to version 2' );
	$assert( '2' === (string) ( $options['cb_crm_install_version'] ?? '' ), 'upgrade must persist install policy marker version 2' );
	$assert( 0 === $schema_registers, 'policy-only upgrade must not rerun schema registration' );

	Install::maybe_upgrade();
	$assert( 1 === $capability_installs, 'current policy marker must prevent per-request capability auto-heal' );

	$options['cb_crm_install_version'] = '';
	Install::maybe_upgrade();
	$assert( 2 === $capability_installs, 'pre-marker installs must receive the current capability policy once' );
	$assert( '2' === (string) ( $options['cb_crm_install_version'] ?? '' ), 'pre-marker upgrade must persist current install policy marker' );

	if ( ! $failed ) {
		echo "CRM install policy upgrade smoke passed.\n";
	}

	exit( $failed ? 1 : 0 );
}

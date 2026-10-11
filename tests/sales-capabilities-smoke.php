<?php
declare(strict_types=1);

namespace CB\CRM\Database {
	final class Schema {
		public static function register(): void {
			++$GLOBALS['crm_sales_schema_registers'];
		}
	}
}

namespace {
	$root = dirname( __DIR__ );
	define( 'ABSPATH', $root . '/' );

	final class CRM_Sales_Test_Role {
		/** @var array<string,bool> */
		public array $caps = [];
		public int $grant_calls = 0;
		public function add_cap( string $capability ): void {
			$this->caps[ $capability ] = true;
			++$this->grant_calls;
		}
	}

	$GLOBALS['crm_sales_roles'] = [
		'administrator' => new CRM_Sales_Test_Role(),
		'cb_operator' => new CRM_Sales_Test_Role(),
		'crm_staff' => new CRM_Sales_Test_Role(),
		'subscriber' => new CRM_Sales_Test_Role(),
	];
	$GLOBALS['crm_sales_roles']['crm_staff']->caps['cb_manage_crm'] = true;
	$GLOBALS['crm_sales_options'] = [ 'cb_crm_install_version' => '1' ];
	$GLOBALS['crm_sales_schema_registers'] = 0;

	function get_role( string $name ): ?CRM_Sales_Test_Role { return $GLOBALS['crm_sales_roles'][ $name ] ?? null; }
	function get_option( string $name, mixed $default = '' ): mixed { return $GLOBALS['crm_sales_options'][ $name ] ?? $default; }
	function update_option( string $name, mixed $value, bool $autoload = false ): bool {
		$GLOBALS['crm_sales_options'][ $name ] = $value;
		return true;
	}
	function add_filter( string $name, callable $callback ): void { unset( $name, $callback ); }
	function did_action( string $name ): int { unset( $name ); return 0; }
	function doing_action( string $name ): bool { unset( $name ); return false; }
	function __( string $message, string $domain = '' ): string { unset( $domain ); return $message; }

	require $root . '/src/Capabilities.php';
	require $root . '/src/Install.php';

	use CB\CRM\Capabilities;
	use CB\CRM\Install;

	$assert = static function ( bool $condition, string $message ): void {
		if ( ! $condition ) {
			fwrite( STDERR, "CRM Sales capability smoke failed: {$message}\n" );
			exit( 1 );
		}
	};

	Install::maybe_upgrade();
	$assert( '2' === get_option( 'cb_crm_install_version' ), 'versioned migration advances to policy 2' );
	foreach ( [ 'administrator', 'cb_operator' ] as $name ) {
		$role = get_role( $name );
		$assert( true === ( $role->caps[ Capabilities::MANAGE ] ?? false ), "canonical CRM capability retained for {$name}" );
		$assert( true === ( $role->caps[ Capabilities::VIEW_SALES ] ?? false ), "Sales view granted to {$name}" );
		$assert( true === ( $role->caps[ Capabilities::MANAGE_SALES ] ?? false ), "Sales management granted to {$name}" );
	}
	$assert( [ Capabilities::MANAGE => true ] === get_role( 'crm_staff' )->caps, 'custom CRM staff not escalated to Sales' );
	$assert( [] === get_role( 'subscriber' )->caps, 'subscriber not granted CRM or Sales' );
	$grants = get_role( 'administrator' )->grant_calls;
	Install::maybe_upgrade();
	$assert( $grants === get_role( 'administrator' )->grant_calls, 'repeat upgrade does not re-grant caps' );

	$catalog = Capabilities::catalog( [] );
	foreach ( [ Capabilities::MANAGE, Capabilities::VIEW_SALES, Capabilities::MANAGE_SALES ] as $cap ) {
		$assert( isset( $catalog[ $cap ] ), "capability {$cap} is advertised to Base" );
		$assert( false === ( $catalog[ $cap ]['policy_grant'] ?? null ), "capability {$cap} cannot be granted by broad policy" );
	}
	$assert( 'View CRM Sales' === $catalog[ Capabilities::VIEW_SALES ]['label'], 'read capability is identifiable' );
	$assert( 'Manage CRM Sales' === $catalog[ Capabilities::MANAGE_SALES ]['label'], 'write capability is identifiable' );

	// Activate with a fresh set of roles, leaving unrelated staff untouched.
	$GLOBALS['crm_sales_options'] = [];
	get_role( 'administrator' )->caps = [];
	Install::activate();
	$assert( '2' === get_option( 'cb_crm_install_version' ), 'fresh install writes version 2' );
	$assert( true === ( get_role( 'administrator' )->caps[ Capabilities::MANAGE_SALES ] ?? false ), 'fresh install grants Sales manage' );
	$assert( 1 === $GLOBALS['crm_sales_schema_registers'], 'activation preserves SchemaRegistry registration' );

	echo "CRM Sales capability migration smoke passed.\n";
}

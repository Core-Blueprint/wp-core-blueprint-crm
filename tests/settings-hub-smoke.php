<?php
declare(strict_types=1);

$root = dirname( __DIR__ );

function cb_crm_settings_hub_assert( bool $condition, string $message ): void {
	if ( $condition ) {
		return;
	}
	fwrite( STDERR, "CRM Settings Hub smoke failed: {$message}\n" );
	exit( 1 );
}

$admin    = (string) file_get_contents( $root . '/src/Admin/Admin.php' );
$settings = (string) file_get_contents( $root . '/src/Admin/SettingsPage.php' );

cb_crm_settings_hub_assert(
	str_contains( $admin, 'SettingsPage::init();' ),
	'Admin bootstrap must initialize the CRM Settings Hub provider'
);
cb_crm_settings_hub_assert(
	str_contains( $settings, "'core_blueprint_register_settings'" ),
	'CRM Settings Hub provider must use the canonical Base v1 settings hook'
);
cb_crm_settings_hub_assert(
	str_contains( $settings, 'SettingsRegistry::register(' )
		&& str_contains( $settings, 'Suite::ID' ),
	'CRM must register its Settings Hub provider against the canonical extension id'
);
cb_crm_settings_hub_assert(
	str_contains( $settings, 'SettingsRegistry::GROUP_BUSINESS' ),
	'CRM Settings Hub provider must use the Business group'
);
cb_crm_settings_hub_assert(
	str_contains( $settings, "'capability'   => Capabilities::MANAGE" ),
	'CRM Settings Hub provider must preserve the CRM management capability boundary'
);
cb_crm_settings_hub_assert(
	str_contains( $settings, "admin.php?page=' . Menu::TOP_LEVEL_SLUG" ),
	'CRM Settings Hub provider must link back to the dedicated CRM workspace'
);

fwrite( STDOUT, "CRM Settings Hub smoke: PASS\n" );

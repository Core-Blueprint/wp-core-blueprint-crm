<?php
declare(strict_types=1);
namespace CB\CRM;

use CB\CRM\Admin\Admin;
use CB\CRM\Content\PostTypes;
use CB\CRM\Integration\Helpdesk;
use CB\CRM\Integration\Suite;
use CB\CRM\Integration\WooCommerce;

defined( 'ABSPATH' ) || exit;

final class Plugin {
	private static bool $booted = false;

	public static function boot(): void {
		if ( self::$booted ) { return; }
		self::$booted = true;

		Install::maybe_upgrade();
		Capabilities::init();
		PostTypes::init();
		PanelRegistry::init();
		Suite::init();
		Governance::init();
		Admin::init();
		Helpdesk::init();
		WooCommerce::init();
	}
}

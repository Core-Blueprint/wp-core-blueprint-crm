<?php
declare(strict_types=1);
namespace CB\CRM;

use CB\CRM\Admin\Admin;
use CB\CRM\Content\PostTypes;
use CB\CRM\Integration\Builders\Bootstrap as BuildersBootstrap;
use CB\CRM\Integration\Docs;
use CB\CRM\Integration\Helpdesk;
use CB\CRM\Integration\Subscriptions;
use CB\CRM\Integration\WooCommerce;
use CB\CRM\Integration\WooCustomerWorkspace;
use CB\CRM\Integration\WorkPricing;

defined( 'ABSPATH' ) || exit;

final class Plugin {
	private static bool $booted = false;

	public static function boot(): void {
		if ( self::$booted ) {
			return;
		}
		self::$booted = true;

		Install::maybe_upgrade();
		Capabilities::init();
		PostTypes::init();
		PanelRegistry::init();
		Governance::init();
		Lifecycle::init();
		WorkPricing::init();
		Admin::init();
		BuildersBootstrap::init();
		Docs::init();
		Helpdesk::init();
		Subscriptions::init();
		WooCommerce::init();
		WooCustomerWorkspace::init();
	}
}

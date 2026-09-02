<?php
declare(strict_types=1);

namespace CB\CRM\Admin;

defined( 'ABSPATH' ) || exit;

final class Admin {
	public static function init(): void {
		Menu::init();
		Panels::init();
		Save::init();
		add_filter( 'plugin_action_links_' . CB_CRM_BASENAME, [ __CLASS__, 'action_links' ] );
	}

	/** @param string[] $links
	 *  @return string[]
	 */
	public static function action_links( array $links ): array {
		$links[] = sprintf(
			'<a href="%s">%s</a>',
			esc_url( admin_url( 'admin.php?page=' . Menu::TOP_LEVEL_SLUG ) ),
			esc_html__( 'CRM', 'core-blueprint-crm' )
		);
		return $links;
	}
}

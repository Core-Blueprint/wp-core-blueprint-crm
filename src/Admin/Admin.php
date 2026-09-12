<?php
declare(strict_types=1);

namespace CB\CRM\Admin;

use CB\CRM\Content\PostTypes;
defined( 'ABSPATH' ) || exit;

final class Admin {
	public static function init(): void {
		Menu::init();
		Panels::init();
		BusinessIdentifiersPanel::init();
		ServiceAgreements::init();
		Save::init();
		UserSearch::init();
		UserLinks::init();
		Assets::init();
		add_filter( 'plugin_action_links_' . CB_CRM_BASENAME, [ __CLASS__, 'action_links' ] );
		add_filter( 'enter_title_here', [ __CLASS__, 'title_placeholder' ], 10, 2 );
	}

	/** @param string[] $links @return string[] */
	public static function action_links( array $links ): array {
		$links[] = sprintf( '<a href="%s">%s</a>', esc_url( admin_url( 'admin.php?page=' . Menu::TOP_LEVEL_SLUG ) ), esc_html__( 'CRM', 'core-blueprint-crm' ) );
		return $links;
	}

	public static function title_placeholder( string $title, \WP_Post $post ): string {
		return match ( $post->post_type ) {
			PostTypes::CONTACT => __( 'Display name', 'core-blueprint-crm' ),
			PostTypes::ORGANIZATION => __( 'Organization name', 'core-blueprint-crm' ),
			default => $title,
		};
	}
}

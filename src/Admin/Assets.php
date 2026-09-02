<?php
declare(strict_types=1);

namespace CB\CRM\Admin;

use CB\Core\UI\FormComposition;
use CB\CRM\Content\PostTypes;
defined( 'ABSPATH' ) || exit;

final class Assets {
	public static function init(): void {
		add_action( 'admin_enqueue_scripts', [ __CLASS__, 'enqueue' ] );
	}

	public static function enqueue(): void {
		$screen = get_current_screen();
		if ( ! $screen || ! in_array( (string) $screen->post_type, [ PostTypes::CONTACT, PostTypes::ORGANIZATION, PostTypes::SERVICE ], true ) ) {
			return;
		}

		FormComposition::enqueue( FormComposition::PRESENTATION_WP_NATIVE );

		wp_enqueue_style(
			'cb-crm-admin',
			CB_CRM_URL . 'assets/admin.css',
			[],
			CB_CRM_VERSION
		);

		wp_enqueue_script(
			'cb-crm-admin',
			CB_CRM_URL . 'assets/admin.js',
			[],
			CB_CRM_VERSION,
			true
		);

		wp_localize_script( 'cb-crm-admin', 'cbCrmAdmin', [
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			'nonce'   => wp_create_nonce( UserSearch::nonce_action() ),
			'i18n'    => [
				'noUsers'   => __( 'No matching WordPress users found.', 'core-blueprint-crm' ),
				'searching' => __( 'Searching…', 'core-blueprint-crm' ),
				'error'     => __( 'WordPress user search failed. Try again.', 'core-blueprint-crm' ),
			],
		] );
	}
}

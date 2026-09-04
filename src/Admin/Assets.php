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
		if ( ! $screen ) {
			return;
		}

		$context          = Menu::screen_context( $screen );
		$is_record_editor = Menu::is_record_editor_screen( $screen );
		$is_layout_page   = in_array( $context, [ Menu::CONTEXT_OVERVIEW, Menu::CONTEXT_TAX_RATES ], true );

		if ( ! $is_record_editor && ! $is_layout_page ) {
			return;
		}

		wp_enqueue_style(
			'cb-crm-admin',
			CB_CRM_URL . 'assets/admin.css',
			[],
			CB_CRM_VERSION
		);

		if ( ! $is_record_editor ) {
			return;
		}

		FormComposition::enqueue( FormComposition::PRESENTATION_WP_NATIVE );

		wp_enqueue_script(
			'cb-crm-admin',
			CB_CRM_URL . 'assets/admin.js',
			[],
			CB_CRM_VERSION,
			true
		);

		$current_contact_id = 0;
		if ( PostTypes::CONTACT === (string) $screen->post_type ) {
			global $post;
			if ( $post instanceof \WP_Post && PostTypes::CONTACT === $post->post_type ) {
				$current_contact_id = (int) $post->ID;
			} elseif ( isset( $_GET['post'] ) ) {
				$candidate = absint( $_GET['post'] );
				if ( PostTypes::CONTACT === get_post_type( $candidate ) ) {
					$current_contact_id = $candidate;
				}
			}
		}

		wp_localize_script( 'cb-crm-admin', 'cbCrmAdmin', [
			'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
			'nonce'     => wp_create_nonce( UserSearch::nonce_action() ),
			'contactId' => $current_contact_id,
			'i18n'      => [
				'noUsers'   => __( 'No matching WordPress users found.', 'core-blueprint-crm' ),
				'searching' => __( 'Searching…', 'core-blueprint-crm' ),
				'error'     => __( 'WordPress user search failed. Try again.', 'core-blueprint-crm' ),
			],
		] );
	}
}

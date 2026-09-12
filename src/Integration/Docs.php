<?php
declare(strict_types=1);

namespace CB\CRM\Integration;

defined( 'ABSPATH' ) || exit;

/** Thin coordinator preserving the existing CRM ↔ Docs integration surface. */
final class Docs {
	private const NONCE         = 'cb_crm_docs_relations';
	private const AJAX_SEARCH   = 'cb_crm_docs_relation_search';
	private const AJAX_LINK     = 'cb_crm_docs_relation_link';
	private const AJAX_UNLINK   = 'cb_crm_docs_relation_unlink';
	private const VIEW_OWNER    = 'owner';
	private const VIEW_DOCUMENT = 'document';

	public static function init(): void {
		add_action( 'cb_crm_register_panels', [ __CLASS__, 'register_crm_panel' ], 30 );
		add_action( 'add_meta_boxes', [ __CLASS__, 'register_docs_panel' ], 30, 2 );
		add_action( 'admin_enqueue_scripts', [ __CLASS__, 'enqueue' ] );
		add_action( 'wp_ajax_' . self::AJAX_SEARCH, [ __CLASS__, 'ajax_search' ] );
		add_action( 'wp_ajax_' . self::AJAX_LINK, [ __CLASS__, 'ajax_link' ] );
		add_action( 'wp_ajax_' . self::AJAX_UNLINK, [ __CLASS__, 'ajax_unlink' ] );
	}

	public static function register_crm_panel(): void {
		DocsAdmin::register_crm_panel();
	}

	public static function register_docs_panel( string $post_type, \WP_Post $post ): void {
		DocsAdmin::register_docs_panel( $post_type, $post );
	}

	public static function render_crm_panel( string $owner_type, int $owner_id ): void {
		DocsAdmin::render_crm_panel( $owner_type, $owner_id );
	}

	public static function render_docs_panel( \WP_Post $post ): void {
		DocsAdmin::render_docs_panel( $post );
	}

	public static function enqueue(): void {
		DocsAdmin::enqueue();
	}

	public static function ajax_search(): void {
		DocsAjax::ajax_search();
	}

	public static function ajax_link(): void {
		DocsAjax::ajax_link();
	}

	public static function ajax_unlink(): void {
		DocsAjax::ajax_unlink();
	}
}

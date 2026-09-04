<?php
declare(strict_types=1);

namespace CB\CRM\Integration;

use CB\CRM\Application\Actions\LinkDocument;
use CB\CRM\Application\Actions\UnlinkDocument;
use CB\CRM\Capabilities;
use CB\CRM\Content\Entity;
use CB\CRM\Frontend\Queries\Contacts;
use CB\CRM\Frontend\Queries\DocumentLinks;
use CB\CRM\Frontend\Queries\Organizations;
use CB\CRM\PanelRegistry;
use CB\Docs\Frontend\Data\Document;
use CB\Docs\Frontend\Search;

defined( 'ABSPATH' ) || exit;

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
		if ( ! self::available() || ! current_user_can( Capabilities::MANAGE ) ) {
			return;
		}

		PanelRegistry::register( [
			'id'         => 'documentation',
			'label'      => 'Docs',
			'post_types' => [ Entity::CONTACT, Entity::ORGANIZATION ],
			'render'     => [ __CLASS__, 'render_crm_panel' ],
			'context'    => 'side',
			'priority'   => 'default',
		] );
	}

	public static function register_docs_panel( string $post_type, \WP_Post $post ): void {
		if ( ! self::available() || ! current_user_can( Capabilities::MANAGE ) || ! current_user_can( 'edit_post', (int) $post->ID ) ) {
			return;
		}

		$document = Document::get( (int) $post->ID );
		if ( is_wp_error( $document ) ) {
			return;
		}

		add_meta_box(
			'cb-crm-linked-records',
			__( 'CRM', 'core-blueprint-crm' ),
			[ __CLASS__, 'render_docs_panel' ],
			$post_type,
			'side',
			'default'
		);
	}

	public static function render_crm_panel( string $owner_type, int $owner_id ): void {
		if (
			! self::available()
			|| ! current_user_can( Capabilities::MANAGE )
			|| ! in_array( $owner_type, [ Entity::CONTACT, Entity::ORGANIZATION ], true )
		) {
			return;
		}

		echo '<div data-cb-crm-docs-panel data-view="' . esc_attr( self::VIEW_OWNER ) . '" data-owner-type="' . esc_attr( $owner_type ) . '" data-owner-id="' . esc_attr( (string) $owner_id ) . '" data-document-id="0">';
		echo '<div data-cb-crm-docs-linked>';
		self::render_owner_links( $owner_type, $owner_id );
		echo '</div>';
		self::render_search_controls( self::VIEW_OWNER );
		echo '</div>';
	}

	public static function render_docs_panel( \WP_Post $post ): void {
		if ( ! self::available() || ! current_user_can( Capabilities::MANAGE ) || ! current_user_can( 'edit_post', (int) $post->ID ) ) {
			return;
		}

		$document = Document::get( (int) $post->ID );
		if ( is_wp_error( $document ) ) {
			return;
		}

		echo '<div data-cb-crm-docs-panel data-view="' . esc_attr( self::VIEW_DOCUMENT ) . '" data-owner-type="" data-owner-id="0" data-document-id="' . esc_attr( (string) $post->ID ) . '">';
		echo '<div data-cb-crm-docs-linked>';
		self::render_document_links( (int) $post->ID );
		echo '</div>';
		self::render_search_controls( self::VIEW_DOCUMENT );
		echo '</div>';
	}

	public static function enqueue(): void {
		if ( ! is_admin() || ! self::available() || ! current_user_can( Capabilities::MANAGE ) || ! self::relevant_screen() ) {
			return;
		}

		wp_enqueue_script(
			'cb-crm-docs-integration',
			CB_CRM_URL . 'assets/admin-docs-integration.js',
			[],
			CB_CRM_VERSION,
			true
		);

		wp_localize_script( 'cb-crm-docs-integration', 'cbCrmDocs', [
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			'nonce'   => wp_create_nonce( self::NONCE ),
			'actions' => [
				'search' => self::AJAX_SEARCH,
				'link'   => self::AJAX_LINK,
				'unlink' => self::AJAX_UNLINK,
			],
			'i18n' => [
				'addDocs'         => sprintf( __( 'Add %s', 'core-blueprint-crm' ), 'Docs' ),
				'addContact'      => sprintf( __( 'Add %s', 'core-blueprint-crm' ), __( 'Contact', 'core-blueprint-crm' ) ),
				'addOrganization' => sprintf( __( 'Add %s', 'core-blueprint-crm' ), __( 'Organization', 'core-blueprint-crm' ) ),
				'remove'          => __( 'Remove', 'core-blueprint-crm' ),
				'searching'       => __( 'Searching…', 'core-blueprint-crm' ),
				'emptyDocs'       => sprintf( __( 'No %s found.', 'core-blueprint-crm' ), 'Docs' ),
				'emptyCrm'        => sprintf( __( 'No %s found.', 'core-blueprint-crm' ), 'CRM' ),
			],
		] );
	}

	public static function ajax_search(): void {
		self::authorize_ajax();

		$view = isset( $_POST['view'] ) ? sanitize_key( (string) wp_unslash( $_POST['view'] ) ) : '';
		$term = isset( $_POST['term'] ) ? sanitize_text_field( (string) wp_unslash( $_POST['term'] ) ) : '';
		if ( strlen( $term ) < 2 ) {
			wp_send_json_success( [] );
		}

		if ( self::VIEW_OWNER === $view ) {
			$result = Search::documents( $term, 20 );
			$items  = [];
			foreach ( $result['items'] as $document ) {
				$id = absint( $document['id'] ?? 0 );
				if ( $id <= 0 || ! current_user_can( 'edit_post', $id ) ) {
					continue;
				}
				$items[] = [
					'id'    => $id,
					'type'  => 'document',
					'label' => sanitize_text_field( (string) ( $document['title'] ?? '' ) ),
					'meta'  => sanitize_text_field( (string) ( $document['documentation_status'] ?? '' ) ),
					'url'   => esc_url_raw( (string) ( $document['permalink'] ?? '' ) ),
				];
			}
			wp_send_json_success( $items );
		}

		if ( self::VIEW_DOCUMENT === $view ) {
			$owner_type = isset( $_POST['owner_type'] ) ? sanitize_key( (string) wp_unslash( $_POST['owner_type'] ) ) : Entity::CONTACT;
			$query = Entity::ORGANIZATION === $owner_type
				? Organizations::staff( [ 'search' => $term, 'per_page' => 20 ] )
				: Contacts::staff( [ 'search' => $term, 'per_page' => 20 ] );

			if ( is_wp_error( $query ) ) {
				self::send_error( $query );
			}

			$items = [];
			foreach ( $query['items'] as $record ) {
				$id = absint( $record['id'] ?? 0 );
				if ( $id <= 0 ) {
					continue;
				}
				$items[] = [
					'id'    => $id,
					'type'  => $owner_type,
					'label' => sanitize_text_field( (string) ( $record[ Entity::ORGANIZATION === $owner_type ? 'name' : 'display_name' ] ?? '' ) ),
					'meta'  => sanitize_text_field( (string) ( $record['status'] ?? '' ) ),
					'url'   => esc_url_raw( (string) get_edit_post_link( $id, '' ) ),
				];
			}
			wp_send_json_success( $items );
		}

		wp_send_json_success( [] );
	}

	public static function ajax_link(): void {
		self::authorize_ajax();

		$view          = self::request_view();
		$owner_type    = isset( $_POST['owner_type'] ) ? sanitize_key( (string) wp_unslash( $_POST['owner_type'] ) ) : '';
		$owner_id      = isset( $_POST['owner_id'] ) ? absint( $_POST['owner_id'] ) : 0;
		$document_id   = isset( $_POST['document_id'] ) ? absint( $_POST['document_id'] ) : 0;
		$relation_type = isset( $_POST['relation_type'] ) ? sanitize_text_field( (string) wp_unslash( $_POST['relation_type'] ) ) : '';

		$result = LinkDocument::execute(
			$owner_type,
			$owner_id,
			$document_id,
			[ 'relation_type' => $relation_type ]
		);
		if ( is_wp_error( $result ) ) {
			self::send_error( $result );
		}

		wp_send_json_success( [
			'html' => self::linked_html( $view, $owner_type, $owner_id, $document_id ),
		] );
	}

	public static function ajax_unlink(): void {
		self::authorize_ajax();

		$view        = self::request_view();
		$owner_type  = isset( $_POST['owner_type'] ) ? sanitize_key( (string) wp_unslash( $_POST['owner_type'] ) ) : '';
		$owner_id    = isset( $_POST['owner_id'] ) ? absint( $_POST['owner_id'] ) : 0;
		$document_id = isset( $_POST['document_id'] ) ? absint( $_POST['document_id'] ) : 0;

		$result = UnlinkDocument::execute( $owner_type, $owner_id, $document_id );
		if ( is_wp_error( $result ) ) {
			self::send_error( $result );
		}

		wp_send_json_success( [
			'html' => self::linked_html( $view, $owner_type, $owner_id, $document_id ),
		] );
	}

	private static function render_search_controls( string $view ): void {
		echo '<hr>';

		if ( self::VIEW_DOCUMENT === $view ) {
			echo '<p><label><span class="screen-reader-text">' . esc_html__( 'CRM', 'core-blueprint-crm' ) . '</span>';
			echo '<select class="widefat" data-cb-crm-docs-owner-type>';
			echo '<option value="' . esc_attr( Entity::CONTACT ) . '">' . esc_html__( 'Contacts', 'core-blueprint-crm' ) . '</option>';
			echo '<option value="' . esc_attr( Entity::ORGANIZATION ) . '">' . esc_html__( 'Organizations', 'core-blueprint-crm' ) . '</option>';
			echo '</select></label></p>';
		}

		echo '<p><label>' . esc_html__( 'Context', 'core-blueprint-crm' );
		echo '<input type="text" class="widefat" maxlength="64" data-cb-crm-docs-context></label></p>';

		$search_label = sprintf(
			__( 'Search %s', 'core-blueprint-crm' ),
			self::VIEW_OWNER === $view ? 'Docs' : __( 'CRM', 'core-blueprint-crm' )
		);
		echo '<p><label><span class="screen-reader-text">' . esc_html( $search_label ) . '</span>';
		echo '<input type="search" class="widefat" data-cb-crm-docs-search autocomplete="off" placeholder="' . esc_attr( $search_label ) . '"></label></p>';
		echo '<p><button type="button" class="button" data-cb-crm-docs-search-button>' . esc_html( $search_label ) . '</button></p>';
		echo '<div data-cb-crm-docs-results></div>';
		echo '<p class="description" role="status" aria-live="polite" data-cb-crm-docs-status></p>';
	}

	private static function render_owner_links( string $owner_type, int $owner_id ): void {
		$links = DocumentLinks::for_owner( $owner_type, $owner_id, 100 );
		if ( is_wp_error( $links ) || [] === $links ) {
			echo '<p class="description">' . esc_html( sprintf( __( 'No %s found.', 'core-blueprint-crm' ), 'Docs' ) ) . '</p>';
			return;
		}

		echo '<ul>';
		foreach ( $links as $link ) {
			$document = is_array( $link['document'] ?? null ) ? $link['document'] : [];
			$title    = sanitize_text_field( (string) ( $document['title'] ?? '' ) );
			$url      = esc_url( (string) ( $document['permalink'] ?? '' ) );
			$status   = sanitize_text_field( (string) ( $document['documentation_status'] ?? '' ) );
			$context  = sanitize_text_field( (string) ( $link['relation_type'] ?? '' ) );
			$doc_id   = absint( $link['document_id'] ?? 0 );

			echo '<li>';
			if ( '' !== $url ) {
				echo '<a href="' . $url . '" target="_blank" rel="noopener"><strong>' . esc_html( $title ) . '</strong></a>';
			} else {
				echo '<strong>' . esc_html( $title ) . '</strong>';
			}
			$meta = array_values( array_filter( [ $status, $context ], static fn( string $value ): bool => '' !== $value ) );
			if ( [] !== $meta ) {
				echo '<br><small>' . esc_html( implode( ' · ', $meta ) ) . '</small>';
			}
			if ( current_user_can( 'edit_post', $doc_id ) ) {
				echo '<br><button type="button" class="button-link-delete" data-cb-crm-docs-unlink data-owner-type="' . esc_attr( $owner_type ) . '" data-owner-id="' . esc_attr( (string) $owner_id ) . '" data-document-id="' . esc_attr( (string) $doc_id ) . '">' . esc_html__( 'Remove', 'core-blueprint-crm' ) . '</button>';
			}
			echo '</li>';
		}
		echo '</ul>';
	}

	private static function render_document_links( int $document_id ): void {
		$links = DocumentLinks::for_document( $document_id, 100 );
		if ( is_wp_error( $links ) || [] === $links ) {
			echo '<p class="description">' . esc_html( sprintf( __( 'No %s found.', 'core-blueprint-crm' ), 'CRM' ) ) . '</p>';
			return;
		}

		echo '<ul>';
		foreach ( $links as $link ) {
			$owner_type = sanitize_key( (string) ( $link['owner_type'] ?? '' ) );
			$owner_id   = absint( $link['owner_id'] ?? 0 );
			$name       = sanitize_text_field( (string) ( $link['name'] ?? '' ) );
			$context    = sanitize_text_field( (string) ( $link['relation_type'] ?? '' ) );
			$url        = get_edit_post_link( $owner_id, '' );

			echo '<li>';
			if ( is_string( $url ) && '' !== $url ) {
				echo '<a href="' . esc_url( $url ) . '"><strong>' . esc_html( $name ) . '</strong></a>';
			} else {
				echo '<strong>' . esc_html( $name ) . '</strong>';
			}
			echo '<br><small>' . esc_html( Entity::ORGANIZATION === $owner_type ? __( 'Organization', 'core-blueprint-crm' ) : __( 'Contact', 'core-blueprint-crm' ) );
			if ( '' !== $context ) {
				echo ' · ' . esc_html( $context );
			}
			echo '</small>';
			echo '<br><button type="button" class="button-link-delete" data-cb-crm-docs-unlink data-owner-type="' . esc_attr( $owner_type ) . '" data-owner-id="' . esc_attr( (string) $owner_id ) . '" data-document-id="' . esc_attr( (string) $document_id ) . '">' . esc_html__( 'Remove', 'core-blueprint-crm' ) . '</button>';
			echo '</li>';
		}
		echo '</ul>';
	}

	private static function linked_html( string $view, string $owner_type, int $owner_id, int $document_id ): string {
		ob_start();
		if ( self::VIEW_DOCUMENT === $view ) {
			self::render_document_links( $document_id );
		} else {
			self::render_owner_links( $owner_type, $owner_id );
		}
		return (string) ob_get_clean();
	}

	private static function relevant_screen(): bool {
		$screen = get_current_screen();
		if ( ! $screen || 'post' !== (string) $screen->base ) {
			return false;
		}

		if ( in_array( (string) $screen->post_type, [ \CB\CRM\Content\PostTypes::CONTACT, \CB\CRM\Content\PostTypes::ORGANIZATION ], true ) ) {
			return true;
		}

		global $post;
		$post_id = $post instanceof \WP_Post ? (int) $post->ID : ( isset( $_GET['post'] ) ? absint( $_GET['post'] ) : 0 );
		if ( $post_id <= 0 || ! current_user_can( 'edit_post', $post_id ) ) {
			return false;
		}

		return ! is_wp_error( Document::get( $post_id ) );
	}

	private static function available(): bool {
		return class_exists( Search::class ) && class_exists( Document::class );
	}

	private static function authorize_ajax(): void {
		check_ajax_referer( self::NONCE, 'nonce' );
		if ( ! self::available() || ! current_user_can( Capabilities::MANAGE ) ) {
			wp_send_json_error( [ 'code' => 'crm_forbidden' ], 403 );
		}
	}

	private static function request_view(): string {
		$view = isset( $_POST['view'] ) ? sanitize_key( (string) wp_unslash( $_POST['view'] ) ) : '';
		return in_array( $view, [ self::VIEW_OWNER, self::VIEW_DOCUMENT ], true ) ? $view : self::VIEW_OWNER;
	}

	private static function send_error( \WP_Error $error ): never {
		$status = 'crm_forbidden' === $error->get_error_code() ? 403 : 400;
		wp_send_json_error( [ 'code' => $error->get_error_code() ], $status );
	}
}

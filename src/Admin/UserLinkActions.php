<?php
declare(strict_types=1);

namespace CB\CRM\Admin;

use CB\CRM\Application\ContactProvisioner;
use CB\CRM\Application\RecordUpdater;
use CB\CRM\Capabilities;
use CB\CRM\Content\ContactIdentity;
use CB\CRM\Content\PostTypes;

defined( 'ABSPATH' ) || exit;

/**
 * Mutation boundary for WordPress User <-> CRM Contact administration.
 *
 * UserLinks owns presentation. This class owns the POST-only mutation paths so
 * every new Contact flows through the canonical ContactProvisioner authority.
 */
final class UserLinkActions {
	public const ACTION_CREATE = 'cb_crm_create_contact_for_user';
	public const ACTION_LINK   = 'cb_crm_link_contact_to_user';

	public static function init(): void {
		add_action( 'admin_post_' . self::ACTION_CREATE, [ __CLASS__, 'create_contact_for_user' ] );
		add_action( 'admin_post_' . self::ACTION_LINK, [ __CLASS__, 'link_contact_to_user' ] );
	}

	public static function create_contact_for_user(): void {
		self::require_post();
		$user_id = isset( $_POST['user_id'] ) ? absint( $_POST['user_id'] ) : 0;
		check_admin_referer( self::nonce_action( 'create', $user_id ) );

		$result = ContactProvisioner::ensure_for_user( $user_id, get_current_user_id() );
		if ( is_wp_error( $result ) ) {
			self::redirect( 'error' );
		}

		self::redirect( ! empty( $result['created'] ) ? 'created' : 'linked' );
	}

	public static function link_contact_to_user(): void {
		self::require_post();
		$user_id    = isset( $_POST['user_id'] ) ? absint( $_POST['user_id'] ) : 0;
		$contact_id = isset( $_POST['contact_id'] ) ? absint( $_POST['contact_id'] ) : 0;
		check_admin_referer( self::nonce_action( 'link', $user_id, $contact_id ) );

		$user    = $user_id > 0 ? get_userdata( $user_id ) : false;
		$contact = $contact_id > 0 ? get_post( $contact_id ) : null;
		if (
			! $user instanceof \WP_User
			|| ! $contact instanceof \WP_Post
			|| PostTypes::CONTACT !== $contact->post_type
			|| in_array( $contact->post_status, [ 'auto-draft', 'trash' ], true )
		) {
			self::redirect( 'error' );
		}

		$existing_user_id = ContactIdentity::linked_user_id( $contact_id );
		if ( $existing_user_id > 0 && $existing_user_id !== $user_id ) {
			self::redirect( 'error' );
		}
		if ( ! ContactIdentity::can_link_user_to_contact( $user_id, $contact_id ) ) {
			self::redirect( 'error' );
		}

		$result = RecordUpdater::update_contact( $contact_id, [ 'wp_user_id' => $user_id ] );
		if ( is_wp_error( $result ) ) {
			self::redirect( 'error' );
		}
		self::redirect( 'linked' );
	}

	public static function nonce_action( string $action, int $user_id, int $contact_id = 0 ): string {
		return 'cb_crm_user_link_' . sanitize_key( $action ) . '_' . $user_id . '_' . $contact_id;
	}

	private static function require_post(): void {
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( (string) $_SERVER['REQUEST_METHOD'] ) : '';
		if ( 'POST' !== $method ) {
			status_header( 405 );
			wp_die( esc_html__( 'Invalid request.' ) );
		}
		if ( ! current_user_can( Capabilities::MANAGE ) ) {
			wp_die( esc_html__( 'You do not have permission to manage CRM data.', 'core-blueprint-crm' ) );
		}
	}

	private static function redirect( string $result ): never {
		$url = add_query_arg(
			[
				'page'                    => UserLinks::PAGE_SLUG,
				'cb_crm_user_link_result' => sanitize_key( $result ),
			],
			admin_url( 'admin.php' )
		);
		wp_safe_redirect( $url );
		exit;
	}
}

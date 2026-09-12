<?php
declare(strict_types=1);

namespace CB\CRM\Admin;

use CB\CRM\Capabilities;
use CB\CRM\Content\ContactIdentity;
use CB\CRM\Content\PostTypes;
defined( 'ABSPATH' ) || exit;

final class UserSearch {
	private const ACTION = 'cb_crm_search_users';
	private const NONCE  = 'cb_crm_user_search';

	public static function init(): void {
		add_action( 'wp_ajax_' . self::ACTION, [ __CLASS__, 'search' ] );
	}

	public static function nonce_action(): string {
		return self::NONCE;
	}

	public static function search(): void {
		self::require_post_request();
		check_ajax_referer( self::NONCE, 'nonce' );

		if ( ! current_user_can( Capabilities::MANAGE ) ) {
			wp_send_json_error( [ 'message' => __( 'You are not allowed to search WordPress users.', 'core-blueprint-crm' ) ], 403 );
		}

		$term = isset( $_POST['term'] ) ? sanitize_text_field( (string) wp_unslash( $_POST['term'] ) ) : '';
		if ( strlen( $term ) < 2 ) {
			wp_send_json_success( [] );
		}

		$current_contact_id = isset( $_POST['contact_id'] ) ? absint( $_POST['contact_id'] ) : 0;
		if ( $current_contact_id > 0 && PostTypes::CONTACT !== get_post_type( $current_contact_id ) ) {
			$current_contact_id = 0;
		}

		$query = new \WP_User_Query( [
			'number'         => 20,
			'search'         => '*' . $term . '*',
			'search_columns' => [ 'user_login', 'user_email', 'display_name' ],
			'orderby'        => 'display_name',
			'order'          => 'ASC',
			'fields'         => [ 'ID', 'display_name', 'user_email', 'user_login' ],
		] );

		$results = [];
		foreach ( $query->get_results() as $user ) {
			if ( ! ContactIdentity::can_link_user_to_contact( (int) $user->ID, $current_contact_id ) ) {
				continue;
			}
			$results[] = [
				'id'    => (int) $user->ID,
				'name'  => (string) $user->display_name,
				'email' => (string) $user->user_email,
				'login' => (string) $user->user_login,
			];
		}

		wp_send_json_success( $results );
	}

	private static function require_post_request(): void {
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( (string) $_SERVER['REQUEST_METHOD'] ) : '';
		if ( 'POST' !== $method ) {
			wp_send_json_error( [ 'code' => 'crm_method_not_allowed' ], 405 );
		}
	}
}

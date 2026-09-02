<?php
declare(strict_types=1);

namespace CB\CRM\Admin;

use CB\CRM\Capabilities;

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
		check_ajax_referer( self::NONCE, 'nonce' );

		if ( ! current_user_can( Capabilities::MANAGE ) ) {
			wp_send_json_error( [ 'message' => __( 'You are not allowed to search WordPress users.', 'core-blueprint-crm' ) ], 403 );
		}

		$term = isset( $_POST['term'] ) ? sanitize_text_field( (string) wp_unslash( $_POST['term'] ) ) : '';
		if ( mb_strlen( $term ) < 2 ) {
			wp_send_json_success( [] );
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
			$results[] = [
				'id'    => (int) $user->ID,
				'name'  => (string) $user->display_name,
				'email' => (string) $user->user_email,
				'login' => (string) $user->user_login,
			];
		}

		wp_send_json_success( $results );
	}
}

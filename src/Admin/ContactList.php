<?php
declare(strict_types=1);

namespace CB\CRM\Admin;

use CB\CRM\Content\ContactIdentity;
use CB\CRM\Content\Meta;
use CB\CRM\Content\PostTypes;

defined( 'ABSPATH' ) || exit;

final class ContactList {
	public static function init(): void {
		add_filter( 'manage_' . PostTypes::CONTACT . '_posts_columns', [ __CLASS__, 'columns' ] );
		add_action( 'manage_' . PostTypes::CONTACT . '_posts_custom_column', [ __CLASS__, 'column' ], 10, 2 );
		add_action( 'restrict_manage_posts', [ __CLASS__, 'filter' ] );
		add_action( 'pre_get_posts', [ __CLASS__, 'apply_filter' ] );
	}

	/** @param string[] $columns @return string[] */
	public static function columns( array $columns ): array {
		$updated = [];
		foreach ( $columns as $key => $label ) {
			if ( 'date' === $key ) {
				$updated['cb_crm_wp_account'] = __( 'WordPress account', 'core-blueprint-crm' );
			}
			$updated[ $key ] = $label;
		}
		if ( ! isset( $updated['cb_crm_wp_account'] ) ) {
			$updated['cb_crm_wp_account'] = __( 'WordPress account', 'core-blueprint-crm' );
		}
		return $updated;
	}

	public static function column( string $column, int $post_id ): void {
		if ( 'cb_crm_wp_account' !== $column ) {
			return;
		}
		$user_id = ContactIdentity::linked_user_id( $post_id );
		$user = $user_id > 0 ? get_userdata( $user_id ) : false;
		if ( ! $user instanceof \WP_User ) {
			echo esc_html__( 'None' );
			return;
		}
		$name = '' !== (string) $user->display_name ? (string) $user->display_name : (string) $user->user_login;
		if ( current_user_can( 'edit_user', $user_id ) ) {
			echo '<a href="' . esc_url( admin_url( 'user-edit.php?user_id=' . $user_id ) ) . '"><strong>' . esc_html( $name ) . '</strong></a>';
		} else {
			echo '<strong>' . esc_html( $name ) . '</strong>';
		}
		echo '<br><span class="description">' . esc_html( (string) $user->user_email ) . '</span>';
	}

	public static function filter(): void {
		$post_type = isset( $_GET['post_type'] ) ? sanitize_key( (string) wp_unslash( $_GET['post_type'] ) ) : '';
		if ( PostTypes::CONTACT !== $post_type ) {
			return;
		}
		$value = isset( $_GET['cb_crm_wp_account'] ) ? sanitize_key( (string) wp_unslash( $_GET['cb_crm_wp_account'] ) ) : '';
		?>
		<label class="screen-reader-text" for="cb-crm-wp-account-filter"><?php esc_html_e( 'WordPress account', 'core-blueprint-crm' ); ?></label>
		<select id="cb-crm-wp-account-filter" name="cb_crm_wp_account">
			<option value=""><?php esc_html_e( 'WordPress account', 'core-blueprint-crm' ); ?></option>
			<option value="linked" <?php selected( $value, 'linked' ); ?>><?php esc_html_e( 'Linked WordPress account', 'core-blueprint-crm' ); ?></option>
			<option value="unlinked" <?php selected( $value, 'unlinked' ); ?>><?php echo esc_html__( 'None' ); ?></option>
		</select>
		<?php
	}

	public static function apply_filter( \WP_Query $query ): void {
		if ( ! is_admin() || ! $query->is_main_query() || PostTypes::CONTACT !== $query->get( 'post_type' ) ) {
			return;
		}
		$value = isset( $_GET['cb_crm_wp_account'] ) ? sanitize_key( (string) wp_unslash( $_GET['cb_crm_wp_account'] ) ) : '';
		if ( ! in_array( $value, [ 'linked', 'unlinked' ], true ) ) {
			return;
		}
		$meta_query = (array) $query->get( 'meta_query' );
		if ( 'linked' === $value ) {
			$meta_query[] = [ 'key' => Meta::WP_USER_ID, 'value' => 0, 'compare' => '>', 'type' => 'NUMERIC' ];
		} else {
			$meta_query[] = [
				'relation' => 'OR',
				[ 'key' => Meta::WP_USER_ID, 'compare' => 'NOT EXISTS' ],
				[ 'key' => Meta::WP_USER_ID, 'value' => 0, 'compare' => '<=', 'type' => 'NUMERIC' ],
			];
		}
		$query->set( 'meta_query', $meta_query );
	}
}

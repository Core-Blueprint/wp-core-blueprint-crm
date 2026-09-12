<?php
declare(strict_types=1);

namespace CB\CRM\Admin;

use CB\CRM\Application\RecordUpdater;
use CB\CRM\Capabilities;
use CB\CRM\Content\ContactIdentity;
use CB\CRM\Content\PostTypes;

defined( 'ABSPATH' ) || exit;

final class UserLinks {
	public const PAGE_SLUG = 'core-blueprint-crm-users';

	private const ACTION_CREATE = 'cb_crm_create_contact_for_user';
	private const ACTION_LINK   = 'cb_crm_link_contact_to_user';
	private const PER_PAGE      = 50;

	public static function init(): void {
		UserLinkDirectory::init();
		ContactList::init();
		add_action( 'admin_post_' . self::ACTION_CREATE, [ __CLASS__, 'create_contact_for_user' ] );
		add_action( 'admin_post_' . self::ACTION_LINK, [ __CLASS__, 'link_contact_to_user' ] );
	}

	public static function render(): void {
		if ( ! current_user_can( Capabilities::MANAGE ) ) {
			wp_die( esc_html__( 'You do not have permission to manage CRM data.', 'core-blueprint-crm' ) );
		}

		$mode = isset( $_GET['mode'] ) ? sanitize_key( (string) wp_unslash( $_GET['mode'] ) ) : '';
		if ( 'link' === $mode ) {
			self::render_link_mode();
			return;
		}

		self::render_notice();

		$status = isset( $_GET['link_status'] ) ? sanitize_key( (string) wp_unslash( $_GET['link_status'] ) ) : '';
		if ( ! in_array( $status, [ '', 'linked', 'unlinked', 'conflict' ], true ) ) {
			$status = '';
		}
		$search = isset( $_GET['s'] ) ? sanitize_text_field( (string) wp_unslash( $_GET['s'] ) ) : '';
		$role   = isset( $_GET['role'] ) ? sanitize_key( (string) wp_unslash( $_GET['role'] ) ) : '';
		$roles  = wp_roles()->get_names();
		if ( '' !== $role && ! isset( $roles[ $role ] ) ) {
			$role = '';
		}
		$paged = max( 1, isset( $_GET['paged'] ) ? absint( $_GET['paged'] ) : 1 );

		$args = [
			'number'  => self::PER_PAGE,
			'paged'   => $paged,
			'orderby' => 'display_name',
			'order'   => 'ASC',
			'fields'  => 'all_with_meta',
		];
		if ( '' !== $search ) {
			$args['search'] = '*' . $search . '*';
			$args['search_columns'] = [ 'user_login', 'user_email', 'display_name' ];
		}
		if ( '' !== $role ) {
			$args['role'] = $role;
		}
		UserLinkDirectory::apply_status_scope( $args, $status );

		$query = new \WP_User_Query( $args );
		$users = array_values( array_filter( $query->get_results(), static fn( mixed $user ): bool => $user instanceof \WP_User ) );
		$user_ids = array_map( static fn( \WP_User $user ): int => (int) $user->ID, $users );
		$link_map = UserLinkDirectory::links_for_users( $user_ids );

		?>
		<div class="wrap cb-crm-user-links-page">
			<h1><?php echo esc_html__( 'CRM', 'core-blueprint-crm' ) . ' — ' . esc_html__( 'Users' ); ?></h1>
			<form method="get" class="search-form">
				<input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE_SLUG ); ?>">
				<p class="search-box">
					<label class="screen-reader-text" for="cb-crm-user-link-search"><?php esc_html_e( 'Search by name or email…', 'core-blueprint-crm' ); ?></label>
					<input type="search" id="cb-crm-user-link-search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php echo esc_attr__( 'Search by name or email…', 'core-blueprint-crm' ); ?>">
					<?php submit_button( __( 'Search' ), 'primary', '', false ); ?>
				</p>
				<div class="tablenav top"><div class="alignleft actions">
					<label class="screen-reader-text" for="cb-crm-link-status"><?php esc_html_e( 'Status', 'core-blueprint-crm' ); ?></label>
					<select id="cb-crm-link-status" name="link_status">
						<option value="" <?php selected( $status, '' ); ?>><?php echo esc_html__( 'All' ); ?></option>
						<option value="linked" <?php selected( $status, 'linked' ); ?>><?php esc_html_e( 'Linked CRM record', 'core-blueprint-crm' ); ?></option>
						<option value="unlinked" <?php selected( $status, 'unlinked' ); ?>><?php esc_html_e( 'No CRM records found.', 'core-blueprint-crm' ); ?></option>
						<option value="conflict" <?php selected( $status, 'conflict' ); ?>><?php echo esc_html__( 'Conflict' ); ?></option>
					</select>
					<label class="screen-reader-text" for="cb-crm-user-role"><?php esc_html_e( 'Role', 'core-blueprint-crm' ); ?></label>
					<select id="cb-crm-user-role" name="role">
						<option value=""><?php esc_html_e( 'Role', 'core-blueprint-crm' ); ?></option>
						<?php foreach ( $roles as $role_key => $role_name ) : ?>
							<option value="<?php echo esc_attr( (string) $role_key ); ?>" <?php selected( $role, (string) $role_key ); ?>><?php echo esc_html( translate_user_role( (string) $role_name ) ); ?></option>
						<?php endforeach; ?>
					</select>
					<?php submit_button( __( 'Filter' ), 'secondary', '', false ); ?>
				</div></div>
			</form>

			<table class="wp-list-table widefat fixed striped table-view-list users">
				<thead><tr>
					<th scope="col"><?php esc_html_e( 'Name', 'core-blueprint-crm' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Email', 'core-blueprint-crm' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Role', 'core-blueprint-crm' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Status', 'core-blueprint-crm' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Contact', 'core-blueprint-crm' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Actions', 'core-blueprint-crm' ); ?></th>
				</tr></thead>
				<tbody>
				<?php if ( [] === $users ) : ?>
					<tr class="no-items"><td colspan="6"><?php esc_html_e( 'No matching WordPress users found.', 'core-blueprint-crm' ); ?></td></tr>
				<?php else : foreach ( $users as $user ) :
					$contact_ids = $link_map[ (int) $user->ID ] ?? [];
					$link_status = UserLinkDirectory::classify( $contact_ids );
					?>
					<tr>
						<td><strong><?php echo esc_html( (string) $user->display_name ); ?></strong><br><code><?php echo esc_html( (string) $user->user_login ); ?></code></td>
						<td><?php echo esc_html( (string) $user->user_email ); ?></td>
						<td><?php echo esc_html( implode( ', ', array_map( static fn( string $item ): string => translate_user_role( wp_roles()->roles[ $item ]['name'] ?? $item ), (array) $user->roles ) ) ); ?></td>
						<td><?php self::render_status( $link_status, $contact_ids ); ?></td>
						<td><?php self::render_contacts( $contact_ids ); ?></td>
						<td><?php self::render_actions( $user, $link_status, $contact_ids ); ?></td>
					</tr>
				<?php endforeach; endif; ?>
				</tbody>
			</table>
			<?php self::render_pagination( (int) $query->get_total(), $paged, [ 's' => $search, 'link_status' => $status, 'role' => $role ] ); ?>
		</div>
		<?php
	}

	public static function create_contact_for_user(): void {
		self::require_post();
		$user_id = isset( $_POST['user_id'] ) ? absint( $_POST['user_id'] ) : 0;
		check_admin_referer( self::nonce_action( 'create', $user_id ) );

		$user = $user_id > 0 ? get_userdata( $user_id ) : false;
		if ( ! $user instanceof \WP_User ) {
			self::redirect( 'error' );
		}
		if ( [] !== ContactIdentity::contact_ids_for_user( $user_id ) ) {
			self::redirect( 'linked' );
		}

		$title = trim( (string) $user->display_name );
		if ( '' === $title ) {
			$title = '' !== (string) $user->user_login ? (string) $user->user_login : (string) $user->user_email;
		}
		$contact_id = wp_insert_post( [
			'post_type'   => PostTypes::CONTACT,
			'post_status' => 'draft',
			'post_title'  => sanitize_text_field( $title ),
			'post_author' => get_current_user_id(),
		], true );
		if ( is_wp_error( $contact_id ) || (int) $contact_id <= 0 ) {
			self::redirect( 'error' );
		}

		$result = RecordUpdater::update_contact( (int) $contact_id, [
			'wp_user_id' => $user_id,
			'first_name' => (string) get_user_meta( $user_id, 'first_name', true ),
			'last_name'  => (string) get_user_meta( $user_id, 'last_name', true ),
			'email_mode' => ContactIdentity::EMAIL_WP,
		] );
		if ( is_wp_error( $result ) ) {
			wp_delete_post( (int) $contact_id, true );
			self::redirect( 'error' );
		}

		$published = wp_update_post( [ 'ID' => (int) $contact_id, 'post_status' => 'publish' ], true );
		if ( is_wp_error( $published ) ) {
			wp_delete_post( (int) $contact_id, true );
			self::redirect( 'error' );
		}

		self::redirect( 'created' );
	}

	public static function link_contact_to_user(): void {
		self::require_post();
		$user_id    = isset( $_POST['user_id'] ) ? absint( $_POST['user_id'] ) : 0;
		$contact_id = isset( $_POST['contact_id'] ) ? absint( $_POST['contact_id'] ) : 0;
		check_admin_referer( self::nonce_action( 'link', $user_id, $contact_id ) );

		$user    = $user_id > 0 ? get_userdata( $user_id ) : false;
		$contact = $contact_id > 0 ? get_post( $contact_id ) : null;
		if ( ! $user instanceof \WP_User || ! $contact instanceof \WP_Post || PostTypes::CONTACT !== $contact->post_type || in_array( $contact->post_status, [ 'auto-draft', 'trash' ], true ) ) {
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

	private static function render_link_mode(): void {
		$user_id = isset( $_GET['user_id'] ) ? absint( $_GET['user_id'] ) : 0;
		$user = $user_id > 0 ? get_userdata( $user_id ) : false;
		if ( ! $user instanceof \WP_User ) {
			self::redirect( 'error' );
		}

		$search = isset( $_GET['contact_search'] ) ? sanitize_text_field( (string) wp_unslash( $_GET['contact_search'] ) ) : '';
		$candidates = UserLinkDirectory::candidate_contacts( $user, $search );
		?>
		<div class="wrap cb-crm-user-links-page">
			<h1><?php esc_html_e( 'Search Contacts', 'core-blueprint-crm' ); ?></h1>
			<p><strong><?php echo esc_html( (string) $user->display_name ); ?></strong> — <?php echo esc_html( (string) $user->user_email ); ?></p>
			<form method="get">
				<input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE_SLUG ); ?>">
				<input type="hidden" name="mode" value="link">
				<input type="hidden" name="user_id" value="<?php echo esc_attr( (string) $user_id ); ?>">
				<label class="screen-reader-text" for="cb-crm-contact-search"><?php esc_html_e( 'Search by name or email…', 'core-blueprint-crm' ); ?></label>
				<input type="search" class="regular-text" id="cb-crm-contact-search" name="contact_search" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php echo esc_attr__( 'Search by name or email…', 'core-blueprint-crm' ); ?>">
				<?php submit_button( __( 'Search' ), 'secondary', '', false ); ?>
			</form>
			<table class="wp-list-table widefat fixed striped">
				<thead><tr><th><?php esc_html_e( 'Name', 'core-blueprint-crm' ); ?></th><th><?php esc_html_e( 'Email', 'core-blueprint-crm' ); ?></th><th><?php esc_html_e( 'Actions', 'core-blueprint-crm' ); ?></th></tr></thead>
				<tbody>
				<?php if ( [] === $candidates ) : ?>
					<tr class="no-items"><td colspan="3"><?php esc_html_e( 'No contacts found.', 'core-blueprint-crm' ); ?></td></tr>
				<?php else : foreach ( $candidates as $contact_id ) : ?>
					<tr>
						<td><a href="<?php echo esc_url( get_edit_post_link( $contact_id, '' ) ?: '#' ); ?>"><strong><?php echo esc_html( get_the_title( $contact_id ) ); ?></strong></a></td>
						<td><?php echo esc_html( ContactIdentity::preferred_email( $contact_id ) ); ?></td>
						<td>
							<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
								<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION_LINK ); ?>">
								<input type="hidden" name="user_id" value="<?php echo esc_attr( (string) $user_id ); ?>">
								<input type="hidden" name="contact_id" value="<?php echo esc_attr( (string) $contact_id ); ?>">
								<?php wp_nonce_field( self::nonce_action( 'link', $user_id, $contact_id ) ); ?>
								<button type="submit" class="button"><?php echo esc_html__( 'Link' ); ?></button>
							</form>
						</td>
					</tr>
				<?php endforeach; endif; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	/** @param int[] $contact_ids */
	private static function render_status( string $status, array $contact_ids ): void {
		if ( 'linked' === $status ) {
			echo '<span class="dashicons dashicons-yes-alt" aria-hidden="true"></span> ' . esc_html__( 'Linked CRM record', 'core-blueprint-crm' );
			return;
		}
		if ( 'unlinked' === $status ) {
			echo '<span class="dashicons dashicons-minus" aria-hidden="true"></span> ' . esc_html__( 'No CRM records found.', 'core-blueprint-crm' );
			return;
		}
		$count = count( $contact_ids );
		echo '<span class="dashicons dashicons-warning" aria-hidden="true"></span> ' . esc_html( sprintf( _n( '%d contact', '%d contacts', $count, 'core-blueprint-crm' ), $count ) );
	}

	/** @param int[] $contact_ids */
	private static function render_contacts( array $contact_ids ): void {
		if ( [] === $contact_ids ) {
			echo '&mdash;';
			return;
		}
		$links = [];
		foreach ( $contact_ids as $contact_id ) {
			$title = get_the_title( $contact_id );
			$links[] = '<a href="' . esc_url( get_edit_post_link( $contact_id, '' ) ?: '#' ) . '">' . esc_html( '' !== $title ? $title : '#' . $contact_id ) . '</a>';
		}
		echo wp_kses_post( implode( '<br>', $links ) );
	}

	/** @param int[] $contact_ids */
	private static function render_actions( \WP_User $user, string $status, array $contact_ids ): void {
		if ( 'unlinked' === $status ) {
			?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block;margin-right:6px">
				<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION_CREATE ); ?>">
				<input type="hidden" name="user_id" value="<?php echo esc_attr( (string) $user->ID ); ?>">
				<?php wp_nonce_field( self::nonce_action( 'create', (int) $user->ID ) ); ?>
				<button type="submit" class="button button-small"><?php esc_html_e( 'Add Contact', 'core-blueprint-crm' ); ?></button>
			</form>
			<a class="button button-small" href="<?php echo esc_url( self::page_url( [ 'mode' => 'link', 'user_id' => (int) $user->ID ] ) ); ?>"><?php esc_html_e( 'Search Contacts', 'core-blueprint-crm' ); ?></a>
			<?php
			return;
		}
		foreach ( $contact_ids as $contact_id ) {
			echo '<a class="button button-small" href="' . esc_url( get_edit_post_link( $contact_id, '' ) ?: '#' ) . '">' . esc_html__( 'Edit Contact', 'core-blueprint-crm' ) . '</a> ';
		}
	}

	/** @param array<string,string> $filters */
	private static function render_pagination( int $total, int $paged, array $filters ): void {
		$total_pages = (int) ceil( $total / self::PER_PAGE );
		if ( $total_pages <= 1 ) {
			return;
		}
		$sentinel = 999999999;
		$pagination_base = str_replace( (string) $sentinel, '%#%', self::page_url( array_merge( array_filter( $filters, static fn( string $value ): bool => '' !== $value ), [ 'paged' => $sentinel ] ) ) );
		$links = paginate_links( [
			'base'      => $pagination_base,
			'format'    => '',
			'current'   => $paged,
			'total'     => $total_pages,
			'type'      => 'array',
			'prev_text' => '&lsaquo;',
			'next_text' => '&rsaquo;',
		] );
		if ( ! is_array( $links ) ) {
			return;
		}
		echo '<div class="tablenav bottom"><div class="tablenav-pages"><span class="pagination-links">' . wp_kses_post( implode( ' ', $links ) ) . '</span></div></div>';
	}

	private static function render_notice(): void {
		$result = isset( $_GET['cb_crm_user_link_result'] ) ? sanitize_key( (string) wp_unslash( $_GET['cb_crm_user_link_result'] ) ) : '';
		if ( '' === $result ) {
			return;
		}
		$message = match ( $result ) {
			'created' => __( 'CRM record created', 'core-blueprint-crm' ),
			'linked'  => __( 'Linked CRM record', 'core-blueprint-crm' ),
			default   => __( 'Error' ),
		};
		$class = in_array( $result, [ 'created', 'linked' ], true ) ? 'notice notice-success is-dismissible' : 'notice notice-error';
		echo '<div class="' . esc_attr( $class ) . '"><p>' . esc_html( $message ) . '</p></div>';
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

	private static function nonce_action( string $action, int $user_id, int $contact_id = 0 ): string {
		return 'cb_crm_user_link_' . sanitize_key( $action ) . '_' . $user_id . '_' . $contact_id;
	}

	/** @param array<string,int|string> $args */
	private static function page_url( array $args = [] ): string {
		return add_query_arg( array_merge( [ 'page' => self::PAGE_SLUG ], $args ), admin_url( 'admin.php' ) );
	}

	private static function redirect( string $result ): never {
		wp_safe_redirect( self::page_url( [ 'cb_crm_user_link_result' => sanitize_key( $result ) ] ) );
		exit;
	}
}

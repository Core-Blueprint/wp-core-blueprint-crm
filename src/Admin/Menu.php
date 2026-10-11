<?php
declare(strict_types=1);

namespace CB\CRM\Admin;

use CoreBlueprint\Core\UI\AdminTheme;
use CB\CRM\Capabilities;
use CB\CRM\Content\PostTypes;
defined( 'ABSPATH' ) || exit;

final class Menu {
	public const TOP_LEVEL_SLUG = 'core-blueprint-crm';
	public const CONTEXT_OVERVIEW = 'overview';
	public const CONTEXT_CONTACTS = 'contacts';
	public const CONTEXT_USERS = 'users';
	public const CONTEXT_ORGANIZATIONS = 'organizations';
	public const CONTEXT_TAGS = 'tags';
	public const CONTEXT_LEADS = 'leads';
	public const CONTEXT_OPPORTUNITIES = 'opportunities';
	public const SALES_LEADS_SLUG = 'core-blueprint-crm-leads';
	public const SALES_OPPORTUNITIES_SLUG = 'core-blueprint-crm-opportunities';

	public static function init(): void {
		add_action( 'admin_menu', [ __CLASS__, 'register' ], 5 );
		add_filter( 'parent_file', [ __CLASS__, 'parent_file' ] );
		add_filter( 'submenu_file', [ __CLASS__, 'submenu_file' ], 10, 2 );
	}

	public static function register(): void {
		$overview_hook = add_menu_page( __( 'CRM', 'core-blueprint-crm' ), __( 'CRM', 'core-blueprint-crm' ), Capabilities::MANAGE, self::TOP_LEVEL_SLUG, [ __CLASS__, 'render_dashboard' ], 'dashicons-groups', 26.4 );
		if ( is_string( $overview_hook ) && class_exists( AdminTheme::class ) ) {
			AdminTheme::register_screen( $overview_hook );
		}
		add_submenu_page( self::TOP_LEVEL_SLUG, __( 'Overview', 'core-blueprint-crm' ), __( 'Overview', 'core-blueprint-crm' ), Capabilities::MANAGE, self::TOP_LEVEL_SLUG, [ __CLASS__, 'render_dashboard' ] );
		add_submenu_page( self::TOP_LEVEL_SLUG, __( 'Contacts', 'core-blueprint-crm' ), __( 'Contacts', 'core-blueprint-crm' ), Capabilities::MANAGE, 'edit.php?post_type=' . PostTypes::CONTACT );
		$users_hook = add_submenu_page( self::TOP_LEVEL_SLUG, __( 'WordPress account', 'core-blueprint-crm' ), __( 'Users' ), Capabilities::MANAGE, UserLinks::PAGE_SLUG, [ UserLinks::class, 'render' ] );
		if ( is_string( $users_hook ) ) {
			UserLinks::register_screen( $users_hook );
			if ( class_exists( AdminTheme::class ) ) {
				AdminTheme::register_screen( $users_hook );
			}
		}
		add_submenu_page( self::TOP_LEVEL_SLUG, __( 'Organizations', 'core-blueprint-crm' ), __( 'Organizations', 'core-blueprint-crm' ), Capabilities::MANAGE, 'edit.php?post_type=' . PostTypes::ORGANIZATION );
		add_submenu_page( self::TOP_LEVEL_SLUG, __( 'Tags', 'core-blueprint-crm' ), __( 'Tags', 'core-blueprint-crm' ), Capabilities::MANAGE, 'edit-tags.php?taxonomy=' . PostTypes::TAG . '&post_type=' . PostTypes::CONTACT );
		// Match Access::can_view(): a Sales manager may legitimately lack the separate viewer grant.
		// WordPress menu API accepts only one capability, so choose a granted Sales capability
		// for this request; SalesPage still enforces canonical CRM + Sales access itself.
		$sales_menu_capability = current_user_can( Capabilities::VIEW_SALES ) ? Capabilities::VIEW_SALES : Capabilities::MANAGE_SALES;
		// Read-only Sales pages are native CRM submenus, not separate top-level menus.
		foreach ( [
			[ self::SALES_LEADS_SLUG, __( 'Leads', 'core-blueprint-crm' ), [ SalesPage::class, 'render_leads' ] ],
			[ self::SALES_OPPORTUNITIES_SLUG, __( 'Opportunities', 'core-blueprint-crm' ), [ SalesPage::class, 'render_opportunities' ] ],
		] as [ $slug, $label, $render ] ) {
			$hook = add_submenu_page( self::TOP_LEVEL_SLUG, $label, $label, $sales_menu_capability, $slug, $render );
			if ( is_string( $hook ) && class_exists( AdminTheme::class ) ) {
				AdminTheme::register_screen( $hook );
			}
		}
	}

	public static function screen_context( ?\WP_Screen $screen = null ): string {
		$screen = $screen ?? get_current_screen();
		if ( ! $screen ) { return ''; }
		$page = isset( $_GET['page'] ) && is_string( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		if ( self::TOP_LEVEL_SLUG === $page ) { return self::CONTEXT_OVERVIEW; }
		if ( UserLinks::PAGE_SLUG === $page ) { return self::CONTEXT_USERS; }
		if ( self::SALES_LEADS_SLUG === $page ) { return self::CONTEXT_LEADS; }
		if ( self::SALES_OPPORTUNITIES_SLUG === $page ) { return self::CONTEXT_OPPORTUNITIES; }
		if ( '' !== (string) $screen->taxonomy ) { return PostTypes::TAG === (string) $screen->taxonomy ? self::CONTEXT_TAGS : ''; }
		return match ( (string) $screen->post_type ) {
			PostTypes::CONTACT => self::CONTEXT_CONTACTS,
			PostTypes::ORGANIZATION => self::CONTEXT_ORGANIZATIONS,
			default => '',
		};
	}

	public static function is_record_editor_screen( ?\WP_Screen $screen = null ): bool {
		$screen = $screen ?? get_current_screen();
		return $screen && 'post' === (string) $screen->base && in_array( self::screen_context( $screen ), [ self::CONTEXT_CONTACTS, self::CONTEXT_ORGANIZATIONS ], true );
	}
	public static function parent_file( string $parent_file ): string { return '' !== self::screen_context() ? self::TOP_LEVEL_SLUG : $parent_file; }
	public static function submenu_file( mixed $submenu_file, mixed $parent_file = '' ): mixed { unset( $parent_file ); $slug = self::submenu_slug( self::screen_context() ); return '' !== $slug ? $slug : $submenu_file; }

	public static function render_dashboard(): void {
		if ( ! current_user_can( Capabilities::MANAGE ) ) { wp_die( esc_html__( 'You do not have permission to manage CRM data.', 'core-blueprint-crm' ) ); }
		$items = [
			[ __( 'Contacts', 'core-blueprint-crm' ), self::count( PostTypes::CONTACT ), admin_url( 'edit.php?post_type=' . PostTypes::CONTACT ), __( 'People, identity and customer context.', 'core-blueprint-crm' ) ],
			[ __( 'Organizations', 'core-blueprint-crm' ), self::count( PostTypes::ORGANIZATION ), admin_url( 'edit.php?post_type=' . PostTypes::ORGANIZATION ), __( 'Companies, institutions and customer relationships.', 'core-blueprint-crm' ) ],
		];
		?>
		<div class="wrap cb-crm-overview-page"><h1><?php esc_html_e( 'Core Blueprint CRM', 'core-blueprint-crm' ); ?></h1>
		<p class="description"><?php esc_html_e( 'Customer identity, organizations, context and customer-specific service agreements. The service catalog and VAT are managed by Core Blueprint Work.', 'core-blueprint-crm' ); ?></p>
		<div class="cb-crm-overview-grid"><?php foreach ( $items as [ $label, $count, $url, $description ] ) : ?><div class="postbox cb-crm-overview-card"><div class="inside"><h2><?php echo esc_html( $label ); ?></h2><p class="cb-crm-overview-count"><strong><?php echo esc_html( (string) $count ); ?></strong></p><p><?php echo esc_html( $description ); ?></p><p><a class="button" href="<?php echo esc_url( $url ); ?>"><?php esc_html_e( 'Manage', 'core-blueprint-crm' ); ?></a></p></div></div><?php endforeach; ?></div></div>
		<?php
	}

	private static function submenu_slug( string $context ): string {
		return match ( $context ) { self::CONTEXT_OVERVIEW => self::TOP_LEVEL_SLUG, self::CONTEXT_CONTACTS => 'edit.php?post_type=' . PostTypes::CONTACT, self::CONTEXT_USERS => UserLinks::PAGE_SLUG, self::CONTEXT_ORGANIZATIONS => 'edit.php?post_type=' . PostTypes::ORGANIZATION, self::CONTEXT_TAGS => 'edit-tags.php?taxonomy=' . PostTypes::TAG . '&post_type=' . PostTypes::CONTACT, self::CONTEXT_LEADS => self::SALES_LEADS_SLUG, self::CONTEXT_OPPORTUNITIES => self::SALES_OPPORTUNITIES_SLUG, default => '' };
	}
	private static function count( string $post_type ): int { $counts = wp_count_posts( $post_type ); $total = 0; foreach ( get_object_vars( $counts ) as $status => $count ) { if ( ! in_array( $status, [ 'trash', 'auto-draft' ], true ) ) { $total += (int) $count; } } return $total; }
}

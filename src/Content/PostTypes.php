<?php
declare(strict_types=1);
namespace CB\CRM\Content;

use CB\CRM\Capabilities;
defined( 'ABSPATH' ) || exit;

final class PostTypes {
	public const CONTACT = 'cb_crm_contact';
	public const ORGANIZATION = 'cb_crm_org';
	public const SERVICE = 'cb_crm_service';
	public const TAG = 'cb_crm_tag';

	public static function init(): void {
		add_action( 'init', [ __CLASS__, 'register' ], 6 );
		add_action( 'init', [ Meta::class, 'register' ], 7 );
	}

	public static function register(): void {
		self::register_type( self::CONTACT, __( 'Contacts', 'core-blueprint-crm' ), __( 'Contact', 'core-blueprint-crm' ), 'dashicons-businessperson' );
		self::register_type( self::ORGANIZATION, __( 'Organizations', 'core-blueprint-crm' ), __( 'Organization', 'core-blueprint-crm' ), 'dashicons-building' );
		self::register_type( self::SERVICE, __( 'Services', 'core-blueprint-crm' ), __( 'Service', 'core-blueprint-crm' ), 'dashicons-hammer' );

		register_taxonomy( self::TAG, [ self::CONTACT, self::ORGANIZATION ], [
			'labels' => [ 'name' => __( 'CRM Tags', 'core-blueprint-crm' ), 'singular_name' => __( 'CRM Tag', 'core-blueprint-crm' ) ],
			'public' => false,
			'show_ui' => true,
			'show_in_menu' => false,
			'show_admin_column' => true,
			'show_in_rest' => false,
			'hierarchical' => false,
			'capabilities' => [ 'manage_terms' => Capabilities::MANAGE, 'edit_terms' => Capabilities::MANAGE, 'delete_terms' => Capabilities::MANAGE, 'assign_terms' => Capabilities::MANAGE ],
		] );
	}

	private static function register_type( string $type, string $plural, string $singular, string $icon ): void {
		register_post_type( $type, [
			'labels' => [
				'name' => $plural, 'singular_name' => $singular,
				'add_new' => sprintf( __( 'Add %s', 'core-blueprint-crm' ), $singular ),
				'add_new_item' => sprintf( __( 'Add %s', 'core-blueprint-crm' ), $singular ),
				'edit_item' => sprintf( __( 'Edit %s', 'core-blueprint-crm' ), $singular ),
				'new_item' => sprintf( __( 'New %s', 'core-blueprint-crm' ), $singular ),
				'view_item' => sprintf( __( 'View %s', 'core-blueprint-crm' ), $singular ),
				'search_items' => sprintf( __( 'Search %s', 'core-blueprint-crm' ), $plural ),
				'not_found' => sprintf( __( 'No %s found.', 'core-blueprint-crm' ), strtolower( $plural ) ),
			],
			'public' => false, 'publicly_queryable' => false, 'show_ui' => true, 'show_in_menu' => false, 'show_in_rest' => false,
			'exclude_from_search' => true, 'has_archive' => false, 'rewrite' => false,
			'supports' => self::SERVICE === $type ? [ 'title', 'editor' ] : [ 'title' ],
			'menu_icon' => $icon,
			'capability_type' => [ 'cb_crm_record', 'cb_crm_records' ], 'map_meta_cap' => true,
			'capabilities' => [
				'edit_post' => Capabilities::MANAGE, 'read_post' => Capabilities::MANAGE, 'delete_post' => Capabilities::MANAGE,
				'edit_posts' => Capabilities::MANAGE, 'edit_others_posts' => Capabilities::MANAGE, 'publish_posts' => Capabilities::MANAGE,
				'read_private_posts' => Capabilities::MANAGE, 'delete_posts' => Capabilities::MANAGE, 'delete_private_posts' => Capabilities::MANAGE,
				'delete_published_posts' => Capabilities::MANAGE, 'delete_others_posts' => Capabilities::MANAGE, 'edit_private_posts' => Capabilities::MANAGE,
				'edit_published_posts' => Capabilities::MANAGE, 'create_posts' => Capabilities::MANAGE,
			],
		] );
	}
}

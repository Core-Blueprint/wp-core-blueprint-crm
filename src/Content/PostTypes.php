<?php
declare(strict_types=1);
namespace CB\CRM\Content;

use CB\CRM\Capabilities;
defined( 'ABSPATH' ) || exit;

final class PostTypes {
	public const CONTACT = 'cb_crm_contact';
	public const ORGANIZATION = 'cb_crm_org';
	public const TAG = 'cb_crm_tag';

	public static function init(): void {
		add_action( 'init', [ __CLASS__, 'register' ], 6 );
		add_action( 'init', [ Meta::class, 'register' ], 7 );
	}

	public static function register(): void {
		self::register_type(
			self::CONTACT,
			[
				'name'               => __( 'Contacts', 'core-blueprint-crm' ),
				'singular_name'      => __( 'Contact', 'core-blueprint-crm' ),
				'all_items'          => __( 'All Contacts', 'core-blueprint-crm' ),
				'add_new'            => __( 'Add Contact', 'core-blueprint-crm' ),
				'add_new_item'       => __( 'Add Contact', 'core-blueprint-crm' ),
				'edit_item'          => __( 'Edit Contact', 'core-blueprint-crm' ),
				'new_item'           => __( 'New Contact', 'core-blueprint-crm' ),
				'view_item'          => __( 'View Contact', 'core-blueprint-crm' ),
				'search_items'       => __( 'Search Contacts', 'core-blueprint-crm' ),
				'not_found'          => __( 'No contacts found.', 'core-blueprint-crm' ),
				'not_found_in_trash' => __( 'No contacts found in Trash.', 'core-blueprint-crm' ),
			],
			'dashicons-businessperson'
		);

		self::register_type(
			self::ORGANIZATION,
			[
				'name'               => __( 'Organizations', 'core-blueprint-crm' ),
				'singular_name'      => __( 'Organization', 'core-blueprint-crm' ),
				'all_items'          => __( 'All Organizations', 'core-blueprint-crm' ),
				'add_new'            => __( 'Add Organization', 'core-blueprint-crm' ),
				'add_new_item'       => __( 'Add Organization', 'core-blueprint-crm' ),
				'edit_item'          => __( 'Edit Organization', 'core-blueprint-crm' ),
				'new_item'           => __( 'New Organization', 'core-blueprint-crm' ),
				'view_item'          => __( 'View Organization', 'core-blueprint-crm' ),
				'search_items'       => __( 'Search Organizations', 'core-blueprint-crm' ),
				'not_found'          => __( 'No organizations found.', 'core-blueprint-crm' ),
				'not_found_in_trash' => __( 'No organizations found in Trash.', 'core-blueprint-crm' ),
			],
			'dashicons-building'
		);

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

	/** @param array<string,string> $labels */
	private static function register_type( string $type, array $labels, string $icon ): void {
		register_post_type( $type, [
			'labels' => $labels,
			'public' => false, 'publicly_queryable' => false, 'show_ui' => true, 'show_in_menu' => false, 'show_in_rest' => false,
			'exclude_from_search' => true, 'has_archive' => false, 'rewrite' => false,
			'supports' => [ 'title' ],
			'menu_icon' => $icon,
			'capability_type' => [ 'cb_crm_record', 'cb_crm_records' ],
			'map_meta_cap' => false,
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

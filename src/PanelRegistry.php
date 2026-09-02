<?php
declare(strict_types=1);
namespace CB\CRM;

use CB\CRM\Content\Entity;
defined( 'ABSPATH' ) || exit;

final class PanelRegistry {
	/** @var array<string,array{id:string,label:string,post_types:string[],render:callable,context:string,priority:string}> */
	private static array $panels = [];

	public static function init(): void {
		add_action( 'init', [ __CLASS__, 'registration' ], 10 );
		add_action( 'add_meta_boxes', [ __CLASS__, 'metaboxes' ], 20 );
	}

	public static function registration(): void { do_action( 'cb_crm_register_panels' ); }

	/** @param array<string,mixed> $definition */
	public static function register( array $definition ): bool {
		$id = sanitize_key( (string) ( $definition['id'] ?? '' ) );
		$label = sanitize_text_field( (string) ( $definition['label'] ?? '' ) );
		$post_types = array_values( array_intersect( array_map( 'sanitize_key', (array) ( $definition['post_types'] ?? [] ) ), [ Entity::CONTACT, Entity::ORGANIZATION, Entity::SERVICE ] ) );
		$render = $definition['render'] ?? null;

		$requested_context = (string) ( $definition['context'] ?? 'normal' );
		$context = in_array( $requested_context, [ 'normal', 'side', 'advanced' ], true ) ? $requested_context : 'normal';

		$requested_priority = (string) ( $definition['priority'] ?? 'default' );
		$priority = in_array( $requested_priority, [ 'high', 'core', 'default', 'low' ], true ) ? $requested_priority : 'default';

		if ( '' === $id || '' === $label || ! $post_types || ! is_callable( $render ) || isset( self::$panels[ $id ] ) ) { return false; }
		self::$panels[ $id ] = compact( 'id', 'label', 'post_types', 'render', 'context', 'priority' );
		return true;
	}

	public static function metaboxes(): void {
		foreach ( self::$panels as $panel ) {
			foreach ( $panel['post_types'] as $owner_type ) {
				$post_type = Entity::post_type_for_owner( $owner_type );
				if ( '' === $post_type ) { continue; }
				add_meta_box( 'cb-crm-panel-' . $panel['id'], $panel['label'], static function ( \WP_Post $post ) use ( $panel, $owner_type ): void {
					call_user_func( $panel['render'], $owner_type, (int) $post->ID, $post );
				}, $post_type, $panel['context'], $panel['priority'] );
			}
		}
	}
}

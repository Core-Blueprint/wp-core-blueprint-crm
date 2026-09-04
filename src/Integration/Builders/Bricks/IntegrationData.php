<?php
declare(strict_types=1);

namespace CB\CRM\Integration\Builders\Bricks;

use CB\CRM\Content\Entity;
use CB\CRM\Frontend\Queries\DocumentLinks;
use CB\CRM\Frontend\Queries\HelpdeskTickets;
use CB\Docs\Frontend\Data\Document;
use CB\Helpdesk\Frontend\Queries\Tickets as HelpdeskProvider;

defined( 'ABSPATH' ) || exit;

/**
 * Dynamic data for optional cross-extension projections only.
 * Relationship/customer matching remains in builder-neutral query providers.
 */
final class IntegrationData {
	private const GROUP = 'Core Blueprint CRM';

	private const DOCUMENT_COUNT     = 'cb_crm_record_document_count';
	private const TICKET_COUNT       = 'cb_crm_contact_ticket_count';
	private const LINKED_RECORD_ID   = 'cb_crm_linked_record_id';
	private const LINKED_RECORD_NAME = 'cb_crm_linked_record_name';
	private const LINKED_RECORD_TYPE = 'cb_crm_linked_record_type';
	private const LINKED_RELATION    = 'cb_crm_linked_record_relation';

	public static function init(): void {
		add_filter( 'bricks/dynamic_tags_list', [ self::class, 'register_tags' ] );
		add_filter( 'bricks/dynamic_data/render_tag', [ self::class, 'render_tag' ], 20, 3 );
		add_filter( 'bricks/dynamic_data/render_content', [ self::class, 'render_content' ], 20, 3 );
		add_filter( 'bricks/frontend/render_data', [ self::class, 'render_content' ], 20, 2 );
	}

	/** @param array<int,array<string,mixed>> $tags
	 *  @return array<int,array<string,mixed>>
	 */
	public static function register_tags( array $tags ): array {
		foreach ( self::labels() as $name => $label ) {
			$tags[] = [ 'name' => '{' . $name . '}', 'label' => $label, 'group' => self::GROUP ];
		}
		return $tags;
	}

	public static function render_tag( mixed $tag, mixed $post = null, string $context = 'text' ): mixed {
		unset( $post, $context );
		if ( ! is_string( $tag ) ) {
			return $tag;
		}
		$name = trim( $tag, '{}' );
		return array_key_exists( $name, self::labels() ) ? self::value( $name ) : $tag;
	}

	public static function render_content( mixed $content, mixed $post = null, string $context = 'text' ): mixed {
		unset( $post, $context );
		if ( ! is_string( $content ) || false === strpos( $content, '{cb_crm_' ) ) {
			return $content;
		}
		foreach ( self::labels() as $name => $label ) {
			unset( $label );
			$needle = '{' . $name . '}';
			if ( false !== strpos( $content, $needle ) ) {
				$content = str_replace( $needle, self::value( $name ), $content );
			}
		}
		return $content;
	}

	/** @return array<string,string> */
	private static function labels(): array {
		$labels = [];
		if ( class_exists( Document::class ) ) {
			$labels[ self::DOCUMENT_COUNT ]     = __( 'Documentation', 'core-blueprint-crm' ) . ' · ' . __( 'Count', 'core-blueprint-crm' );
			$labels[ self::LINKED_RECORD_ID ]   = __( 'Linked CRM record', 'core-blueprint-crm' ) . ' · ID';
			$labels[ self::LINKED_RECORD_NAME ] = __( 'Linked CRM record', 'core-blueprint-crm' ) . ' · ' . __( 'Name', 'core-blueprint-crm' );
			$labels[ self::LINKED_RECORD_TYPE ] = __( 'Linked CRM record', 'core-blueprint-crm' ) . ' · ' . __( 'Type', 'core-blueprint-crm' );
			$labels[ self::LINKED_RELATION ]    = __( 'Linked CRM record', 'core-blueprint-crm' ) . ' · ' . __( 'Relation', 'core-blueprint-crm' );
		}
		if ( class_exists( HelpdeskProvider::class ) ) {
			$labels[ self::TICKET_COUNT ] = __( 'Contact', 'core-blueprint-crm' ) . ' · ' . __( 'Ticket count', 'core-blueprint-crm' );
		}
		return $labels;
	}

	private static function value( string $name ): string {
		if ( self::DOCUMENT_COUNT === $name ) {
			$current = RecordContext::current();
			if ( is_wp_error( $current ) || ! in_array( $current['type'], [ Entity::CONTACT, Entity::ORGANIZATION ], true ) ) {
				return '';
			}
			$id    = absint( $current['data']['id'] ?? 0 );
			$links = DocumentLinks::for_owner( $current['type'], $id, 100 );
			return is_wp_error( $links ) ? '' : (string) count( $links );
		}
		if ( self::TICKET_COUNT === $name ) {
			$contact_id = RecordContext::identifier( Entity::CONTACT );
			if ( null === $contact_id ) {
				return '';
			}
			$count = HelpdeskTickets::count_for_contact( $contact_id );
			return is_wp_error( $count ) ? '' : (string) $count;
		}

		$relation = self::relation_loop();
		if ( null === $relation ) {
			return '';
		}
		return match ( $name ) {
			self::LINKED_RECORD_ID   => (string) absint( $relation['owner_id'] ?? 0 ),
			self::LINKED_RECORD_NAME => sanitize_text_field( (string) ( $relation['name'] ?? '' ) ),
			self::LINKED_RECORD_TYPE => sanitize_key( (string) ( $relation['owner_type'] ?? '' ) ),
			self::LINKED_RELATION    => sanitize_text_field( (string) ( $relation['relation_type'] ?? '' ) ),
			default                  => '',
		};
	}

	/** @return array<string,mixed>|null */
	private static function relation_loop(): ?array {
		if ( ! class_exists( '\\Bricks\\Query' ) || ! method_exists( '\\Bricks\\Query', 'get_loop_object' ) ) {
			return null;
		}
		$object = \Bricks\Query::get_loop_object();
		if ( ! is_array( $object ) ) {
			return null;
		}
		$type = sanitize_key( (string) ( $object['owner_type'] ?? '' ) );
		$id   = absint( $object['owner_id'] ?? 0 );
		return $id > 0 && in_array( $type, [ Entity::CONTACT, Entity::ORGANIZATION ], true ) ? $object : null;
	}
}

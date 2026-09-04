<?php
declare(strict_types=1);

namespace CB\CRM\Integration\Builders\Bricks;

use CB\CRM\Content\Entity;
use CB\CRM\Frontend\Queries\Contacts;
use CB\CRM\Frontend\Queries\DocumentLinks;
use CB\CRM\Frontend\Queries\HelpdeskTickets;
use CB\CRM\Frontend\Queries\Organizations;
use CB\CRM\Frontend\Queries\ServiceAgreements;
use CB\Docs\Frontend\Data\Document;
use CB\Helpdesk\Frontend\Queries\Tickets as HelpdeskProvider;
defined( 'ABSPATH' ) || exit;

final class Queries {
	public const CURRENT_CONTACT = 'cb_crm_current_contact';
	public const CONTACTS = 'cb_crm_contacts';
	public const ORGANIZATIONS = 'cb_crm_organizations';
	public const SERVICE_AGREEMENTS = 'cb_crm_service_agreements';
	public const LINKED_DOCUMENTS = 'cb_crm_linked_documents';
	public const DOCUMENT_RECORDS = 'cb_crm_document_records';
	public const CONTACT_TICKETS = 'cb_crm_contact_helpdesk_tickets';

	public static function init(): void { add_filter( 'bricks/setup/control_options', [ self::class, 'register_query_types' ] ); add_filter( 'bricks/query/run', [ self::class, 'run' ], 10, 2 ); }
	/** @param array<string,mixed> $options @return array<string,mixed> */
	public static function register_query_types( array $options ): array {
		if ( ! isset( $options['queryTypes'] ) || ! is_array( $options['queryTypes'] ) ) { $options['queryTypes'] = []; }
		$crm = __( 'CRM', 'core-blueprint-crm' );
		$options['queryTypes'][ self::CURRENT_CONTACT ] = $crm . ': ' . __( 'Contact', 'core-blueprint-crm' );
		$options['queryTypes'][ self::CONTACTS ] = $crm . ': ' . __( 'Contacts', 'core-blueprint-crm' );
		$options['queryTypes'][ self::ORGANIZATIONS ] = $crm . ': ' . __( 'Organizations', 'core-blueprint-crm' );
		$options['queryTypes'][ self::SERVICE_AGREEMENTS ] = $crm . ': ' . __( 'Service Agreements', 'core-blueprint-crm' );
		if ( class_exists( Document::class ) ) { $options['queryTypes'][ self::LINKED_DOCUMENTS ] = $crm . ': ' . __( 'Linked documentation', 'core-blueprint-crm' ); $options['queryTypes'][ self::DOCUMENT_RECORDS ] = $crm . ': ' . __( 'Records linked to document', 'core-blueprint-crm' ); }
		if ( class_exists( HelpdeskProvider::class ) ) { $options['queryTypes'][ self::CONTACT_TICKETS ] = $crm . ': ' . __( 'Contact tickets', 'core-blueprint-crm' ); }
		return $options;
	}
	/** @param array<int,mixed> $results @return array<int,mixed> */
	public static function run( array $results, mixed $query_obj ): array {
		$type = is_object( $query_obj ) && isset( $query_obj->object_type ) ? (string) $query_obj->object_type : ''; $page = self::page( $query_obj );
		if ( self::CURRENT_CONTACT === $type ) { $contact = Contacts::current_user(); return is_wp_error( $contact ) ? [] : [ $contact ]; }
		if ( self::CONTACTS === $type ) { $query = Contacts::staff( [ 'page' => $page, 'per_page' => self::limit( $query_obj, 30 ) ] ); return is_wp_error( $query ) ? [] : $query['items']; }
		if ( self::ORGANIZATIONS === $type ) { $query = Organizations::staff( [ 'page' => $page, 'per_page' => self::limit( $query_obj, 30 ) ] ); return is_wp_error( $query ) ? [] : $query['items']; }
		if ( self::SERVICE_AGREEMENTS === $type ) { $current = RecordContext::current(); if ( is_wp_error( $current ) || ! in_array( $current['type'], [ Entity::CONTACT, Entity::ORGANIZATION ], true ) ) { return []; } $items = ServiceAgreements::for_customer( $current['type'], absint( $current['data']['id'] ?? 0 ) ); return is_wp_error( $items ) ? [] : self::slice_window( $items, self::window( $query_obj, 30 ) ); }
		if ( self::LINKED_DOCUMENTS === $type ) { $current = RecordContext::current(); if ( is_wp_error( $current ) || ! in_array( $current['type'], [ Entity::CONTACT, Entity::ORGANIZATION ], true ) ) { return []; } $window = self::window( $query_obj, 30 ); $links = DocumentLinks::for_owner( $current['type'], absint( $current['data']['id'] ?? 0 ), $window['fetch'] ); if ( is_wp_error( $links ) ) { return []; } $documents = []; foreach ( $links as $link ) { if ( isset( $link['document'] ) && is_array( $link['document'] ) ) { $documents[] = $link['document']; } } return self::slice_window( $documents, $window ); }
		if ( self::DOCUMENT_RECORDS === $type ) { $document_id = self::current_document_id(); if ( null === $document_id ) { return []; } $window = self::window( $query_obj, 30 ); $links = DocumentLinks::for_document( $document_id, $window['fetch'] ); return is_wp_error( $links ) ? [] : self::slice_window( $links, $window ); }
		if ( self::CONTACT_TICKETS === $type ) { $contact_id = RecordContext::identifier( Entity::CONTACT ); if ( null === $contact_id ) { return []; } $window = self::window( $query_obj, 30 ); $tickets = HelpdeskTickets::for_contact( $contact_id, $window['fetch'] ); return is_wp_error( $tickets ) ? [] : self::slice_window( $tickets, $window ); }
		return $results;
	}
	private static function current_document_id(): ?int { if ( ! class_exists( Document::class ) ) { return null; } $loop = class_exists( '\\Bricks\\Query' ) && method_exists( '\\Bricks\\Query', 'get_loop_object' ) ? \Bricks\Query::get_loop_object() : null; $document = is_array( $loop ) && isset( $loop['id'] ) ? Document::get( absint( $loop['id'] ) ) : ( $loop instanceof \WP_Post ? Document::get( (int) $loop->ID ) : Document::current() ); if ( is_wp_error( $document ) ) { return null; } $id = absint( $document['id'] ?? 0 ); return $id > 0 ? $id : null; }
	private static function limit( mixed $query_obj, int $default ): int { if ( ! is_object( $query_obj ) || ! isset( $query_obj->settings ) || ! is_array( $query_obj->settings ) ) { return $default; } $raw = $query_obj->settings['posts_per_page'] ?? $query_obj->settings['count'] ?? $default; return is_scalar( $raw ) && is_numeric( $raw ) ? max( 1, min( 100, (int) $raw ) ) : $default; }
	private static function page( mixed $query_obj ): int { if ( ! is_object( $query_obj ) || ! isset( $query_obj->settings ) || ! is_array( $query_obj->settings ) ) { return 1; } $raw = $query_obj->settings['paged'] ?? $query_obj->settings['page'] ?? 1; return is_scalar( $raw ) && is_numeric( $raw ) ? max( 1, (int) $raw ) : 1; }
	/** @return array{limit:int,offset:int,fetch:int} */ private static function window( mixed $query_obj, int $default ): array { $limit = self::limit( $query_obj, $default ); $offset = ( self::page( $query_obj ) - 1 ) * $limit; return [ 'limit' => $limit, 'offset' => $offset, 'fetch' => min( 100, $offset + $limit ) ]; }
	/** @param array<int,mixed> $items @param array{limit:int,offset:int,fetch:int} $window @return array<int,mixed> */ private static function slice_window( array $items, array $window ): array { return array_values( array_slice( $items, $window['offset'], $window['limit'] ) ); }
}

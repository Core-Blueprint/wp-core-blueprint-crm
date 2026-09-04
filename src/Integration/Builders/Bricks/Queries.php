<?php
declare(strict_types=1);

namespace CB\CRM\Integration\Builders\Bricks;

use CB\CRM\Content\Entity;
use CB\CRM\Frontend\Queries\Contacts;
use CB\CRM\Frontend\Queries\DocumentLinks;
use CB\CRM\Frontend\Queries\HelpdeskTickets;
use CB\CRM\Frontend\Queries\Organizations;
use CB\CRM\Frontend\Queries\Services;
use CB\Docs\Frontend\Data\Document;
use CB\Helpdesk\Frontend\Queries\Tickets as HelpdeskProvider;

defined( 'ABSPATH' ) || exit;

final class Queries {
	public const CURRENT_CONTACT  = 'cb_crm_current_contact';
	public const CONTACTS         = 'cb_crm_contacts';
	public const ORGANIZATIONS    = 'cb_crm_organizations';
	public const SERVICES         = 'cb_crm_services';
	public const LINKED_DOCUMENTS = 'cb_crm_linked_documents';
	public const DOCUMENT_RECORDS = 'cb_crm_document_records';
	public const CONTACT_TICKETS  = 'cb_crm_contact_helpdesk_tickets';

	public static function init(): void {
		add_filter( 'bricks/setup/control_options', [ self::class, 'register_query_types' ] );
		add_filter( 'bricks/query/run', [ self::class, 'run' ], 10, 2 );
	}

	/** @param array<string,mixed> $options
	 *  @return array<string,mixed>
	 */
	public static function register_query_types( array $options ): array {
		if ( ! isset( $options['queryTypes'] ) || ! is_array( $options['queryTypes'] ) ) {
			$options['queryTypes'] = [];
		}

		$crm = __( 'CRM', 'core-blueprint-crm' );
		$options['queryTypes'][ self::CURRENT_CONTACT ] = $crm . ': ' . __( 'Contact', 'core-blueprint-crm' );
		$options['queryTypes'][ self::CONTACTS ]        = $crm . ': ' . __( 'Contacts', 'core-blueprint-crm' );
		$options['queryTypes'][ self::ORGANIZATIONS ]   = $crm . ': ' . __( 'Organizations', 'core-blueprint-crm' );
		$options['queryTypes'][ self::SERVICES ]        = $crm . ': ' . __( 'Services', 'core-blueprint-crm' );

		if ( class_exists( Document::class ) ) {
			$options['queryTypes'][ self::LINKED_DOCUMENTS ] = $crm . ': ' . __( 'Linked documentation', 'core-blueprint-crm' );
			$options['queryTypes'][ self::DOCUMENT_RECORDS ] = $crm . ': ' . __( 'Records linked to document', 'core-blueprint-crm' );
		}
		if ( class_exists( HelpdeskProvider::class ) ) {
			$options['queryTypes'][ self::CONTACT_TICKETS ] = $crm . ': ' . __( 'Contact tickets', 'core-blueprint-crm' );
		}
		return $options;
	}

	/** @param array<int,mixed> $results
	 *  @return array<int,mixed>
	 */
	public static function run( array $results, mixed $query_obj ): array {
		$object_type = is_object( $query_obj ) && isset( $query_obj->object_type )
			? (string) $query_obj->object_type
			: '';

		if ( self::CURRENT_CONTACT === $object_type ) {
			$contact = Contacts::current_user();
			return is_wp_error( $contact ) ? [] : [ $contact ];
		}
		if ( self::CONTACTS === $object_type ) {
			$query = Contacts::staff( [ 'per_page' => self::limit( $query_obj, 30 ) ] );
			return is_wp_error( $query ) ? [] : $query['items'];
		}
		if ( self::ORGANIZATIONS === $object_type ) {
			$query = Organizations::staff( [ 'per_page' => self::limit( $query_obj, 30 ) ] );
			return is_wp_error( $query ) ? [] : $query['items'];
		}
		if ( self::SERVICES === $object_type ) {
			$query = Services::query( [ 'per_page' => self::limit( $query_obj, 30 ) ] );
			return is_wp_error( $query ) ? [] : $query['items'];
		}
		if ( self::LINKED_DOCUMENTS === $object_type ) {
			$current = RecordContext::current();
			if ( is_wp_error( $current ) || ! in_array( $current['type'], [ Entity::CONTACT, Entity::ORGANIZATION ], true ) ) {
				return [];
			}
			$owner_id = absint( $current['data']['id'] ?? 0 );
			$links    = DocumentLinks::for_owner( $current['type'], $owner_id, self::limit( $query_obj, 30 ) );
			if ( is_wp_error( $links ) ) {
				return [];
			}
			$documents = [];
			foreach ( $links as $link ) {
				if ( isset( $link['document'] ) && is_array( $link['document'] ) ) {
					$documents[] = $link['document'];
				}
			}
			return $documents;
		}
		if ( self::DOCUMENT_RECORDS === $object_type ) {
			$document_id = self::current_document_id();
			if ( null === $document_id ) {
				return [];
			}
			$links = DocumentLinks::for_document( $document_id, self::limit( $query_obj, 30 ) );
			return is_wp_error( $links ) ? [] : $links;
		}
		if ( self::CONTACT_TICKETS === $object_type ) {
			$contact_id = RecordContext::identifier( Entity::CONTACT );
			if ( null === $contact_id ) {
				return [];
			}
			$tickets = HelpdeskTickets::for_contact( $contact_id, self::limit( $query_obj, 30 ) );
			return is_wp_error( $tickets ) ? [] : $tickets;
		}
		return $results;
	}

	private static function current_document_id(): ?int {
		if ( ! class_exists( Document::class ) ) {
			return null;
		}

		$loop = null;
		if ( class_exists( '\\Bricks\\Query' ) && method_exists( '\\Bricks\\Query', 'get_loop_object' ) ) {
			$loop = \Bricks\Query::get_loop_object();
		}

		$document = null;
		if ( is_array( $loop ) && isset( $loop['id'] ) ) {
			$document = Document::get( absint( $loop['id'] ) );
		} elseif ( $loop instanceof \WP_Post ) {
			$document = Document::get( (int) $loop->ID );
		}
		if ( is_wp_error( $document ) || null === $document ) {
			$document = Document::current();
		}
		if ( is_wp_error( $document ) ) {
			return null;
		}
		$id = absint( $document['id'] ?? 0 );
		return $id > 0 ? $id : null;
	}

	private static function limit( mixed $query_obj, int $default ): int {
		if ( ! is_object( $query_obj ) || ! isset( $query_obj->settings ) || ! is_array( $query_obj->settings ) ) {
			return $default;
		}
		$raw = $query_obj->settings['posts_per_page'] ?? $query_obj->settings['count'] ?? $default;
		if ( ! is_scalar( $raw ) || ! is_numeric( $raw ) ) {
			return $default;
		}
		return max( 1, min( 100, (int) $raw ) );
	}
}

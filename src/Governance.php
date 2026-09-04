<?php
declare(strict_types=1);

namespace CB\CRM;

use CB\Core\Governance\Audit;
use CB\Core\Governance\EventRegistry;
use CB\CRM\Content\Entity;
use CB\CRM\Content\PostTypes;

defined( 'ABSPATH' ) || exit;

final class Governance {
	public const RECORD_CREATED  = 'crm.record.created';
	public const RECORD_UPDATED  = 'crm.record.updated';
	public const RECORD_DELETED  = 'crm.record.deleted';
	public const DATA_UPDATED    = 'crm.data.updated';
	public const NOTE_CREATED    = 'crm.note.created';
	public const ORDER_ACTIVITY  = 'crm.order.activity';
	public const TICKET_ACTIVITY = 'crm.ticket.activity';
	public const CLEANUP_FAILED  = 'crm.cleanup.failed';

	public static function init(): void {
		add_action( 'init', [ __CLASS__, 'register_events' ], 10 );
		add_action( 'wp_after_insert_post', [ __CLASS__, 'record_post_change' ], 20, 4 );
	}

	public static function register_events(): void {
		EventRegistry::register( [ 'id' => self::RECORD_CREATED, 'label' => __( 'CRM record created', 'core-blueprint-crm' ), 'retention_category' => 'general' ] );
		EventRegistry::register( [ 'id' => self::RECORD_UPDATED, 'label' => __( 'CRM record updated', 'core-blueprint-crm' ), 'retention_category' => 'general' ] );
		EventRegistry::register( [ 'id' => self::RECORD_DELETED, 'label' => __( 'CRM record deleted', 'core-blueprint-crm' ), 'retention_category' => 'general' ] );
		EventRegistry::register( [ 'id' => self::DATA_UPDATED, 'label' => __( 'CRM record data updated', 'core-blueprint-crm' ), 'retention_category' => 'general' ] );
		EventRegistry::register( [ 'id' => self::NOTE_CREATED, 'label' => __( 'CRM note created', 'core-blueprint-crm' ), 'retention_category' => 'general' ] );
		EventRegistry::register( [ 'id' => self::ORDER_ACTIVITY, 'label' => __( 'CRM WooCommerce activity recorded', 'core-blueprint-crm' ), 'retention_category' => 'general' ] );
		EventRegistry::register( [ 'id' => self::TICKET_ACTIVITY, 'label' => __( 'CRM Helpdesk activity recorded', 'core-blueprint-crm' ), 'retention_category' => 'general' ] );
		EventRegistry::register( [ 'id' => self::CLEANUP_FAILED, 'label' => __( 'CRM record cleanup failed', 'core-blueprint-crm' ), 'retention_category' => 'maintenance' ] );
	}

	public static function record_post_change( int $post_id, \WP_Post $post, bool $update, ?\WP_Post $post_before ): void {
		$owner_type = Entity::owner_type_for_post( $post_id );
		if ( '' === $owner_type || in_array( $post->post_status, [ 'auto-draft', 'trash' ], true ) ) {
			return;
		}
		$created = ! $update || ( $post_before instanceof \WP_Post && 'auto-draft' === $post_before->post_status );
		Audit::record( $created ? self::RECORD_CREATED : self::RECORD_UPDATED, 'notice', [ 'record_id' => $post_id, 'record_type' => $owner_type, 'actor_user_id' => get_current_user_id() ] );
	}

	public static function record_record_deleted( string $owner_type, int $owner_id ): void {
		Audit::record( self::RECORD_DELETED, 'notice', [ 'record_id' => $owner_id, 'record_type' => sanitize_key( $owner_type ), 'actor_user_id' => get_current_user_id() ] );
	}

	public static function record_cleanup_failed( string $owner_type, int $owner_id ): void {
		Audit::record( self::CLEANUP_FAILED, 'warning', [ 'record_id' => $owner_id, 'record_type' => sanitize_key( $owner_type ), 'actor_user_id' => get_current_user_id() ] );
	}

	public static function record_data_updated( string $owner_type, int $owner_id, string $area ): void {
		if ( ! Entity::valid_owner( $owner_type, $owner_id ) ) {
			return;
		}
		Audit::record( self::DATA_UPDATED, 'notice', [ 'record_id' => $owner_id, 'record_type' => $owner_type, 'area' => sanitize_key( $area ), 'actor_user_id' => get_current_user_id() ] );
	}

	public static function record_document_link_changed( string $owner_type, int $owner_id, int $document_id, string $action, string $relation_type = '' ): void {
		$owner_type = sanitize_key( $owner_type );
		$action = sanitize_key( $action );
		if ( ! Entity::valid_owner( $owner_type, $owner_id ) || $document_id <= 0 || ! in_array( $action, [ 'linked', 'updated', 'unlinked' ], true ) ) {
			return;
		}
		Audit::record( self::DATA_UPDATED, 'notice', [ 'record_id' => $owner_id, 'record_type' => $owner_type, 'area' => 'documentation_' . $action, 'document_id' => $document_id, 'relation_type' => substr( sanitize_text_field( $relation_type ), 0, 64 ), 'actor_user_id' => get_current_user_id() ] );
	}

	public static function record_note_created( string $owner_type, int $owner_id, int $note_id ): void {
		Audit::record( self::NOTE_CREATED, 'notice', [ 'record_id' => $owner_id, 'record_type' => sanitize_key( $owner_type ), 'note_id' => $note_id, 'actor_user_id' => get_current_user_id() ] );
	}

	public static function record_order_activity( int $contact_id, int $order_id, string $from, string $to ): void {
		if ( PostTypes::CONTACT !== get_post_type( $contact_id ) ) {
			return;
		}
		Audit::record( self::ORDER_ACTIVITY, 'info', [ 'contact_id' => $contact_id, 'order_id' => $order_id, 'status_from' => sanitize_key( $from ), 'status_to' => sanitize_key( $to ), 'actor_user_id' => get_current_user_id() ] );
	}

	public static function record_ticket_activity( int $contact_id, int $ticket_id, string $action ): void {
		if ( PostTypes::CONTACT !== get_post_type( $contact_id ) ) {
			return;
		}
		Audit::record( self::TICKET_ACTIVITY, 'info', [ 'contact_id' => $contact_id, 'ticket_id' => $ticket_id, 'action' => sanitize_key( $action ), 'actor_user_id' => get_current_user_id() ] );
	}
}

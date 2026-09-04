<?php
declare(strict_types=1);

namespace CB\CRM\Integration\Builders\Bricks;

use CB\CRM\Content\Entity;
use CB\CRM\Content\RecordStatus;
use CB\CRM\Frontend\Conditions\Records;
use CB\CRM\Frontend\Data\Organization;
use CB\CRM\Frontend\Queries\DocumentLinks;
use CB\CRM\Frontend\Queries\HelpdeskTickets;
use CB\CRM\Frontend\Queries\Organizations;
use CB\Docs\Frontend\Data\Document;
use CB\Helpdesk\Frontend\Queries\Tickets as HelpdeskProvider;
use CB\Work\PublicApi\Services as WorkServices;
defined( 'ABSPATH' ) || exit;

final class Conditions {
	private const GROUP = 'cb_crm';
	private const CURRENT_CONTACT = 'cb_crm_current_user_has_contact';
	private const CONTACT_SERVICE_AGREEMENT = 'cb_crm_contact_has_service_agreement';
	private const CONTACT_ORG = 'cb_crm_contact_organization';
	private const ORG_SERVICE_AGREEMENT = 'cb_crm_organization_has_service_agreement';
	private const RECORD_STATUS = 'cb_crm_record_status';
	private const RECORD_DOCS = 'cb_crm_record_has_linked_docs';
	private const CONTACT_OPEN_TICKETS = 'cb_crm_contact_has_open_helpdesk_tickets';
	public static function init(): void { add_filter( 'bricks/conditions/groups', [ self::class, 'register_group' ] ); add_filter( 'bricks/conditions/options', [ self::class, 'register_options' ] ); add_filter( 'bricks/conditions/result', [ self::class, 'result' ], 10, 3 ); }
	/** @param array<int,array<string,mixed>> $groups @return array<int,array<string,mixed>> */ public static function register_group( array $groups ): array { $groups[] = [ 'name' => self::GROUP, 'label' => __( 'Core Blueprint CRM', 'core-blueprint-crm' ) ]; return $groups; }
	/** @param array<int,array<string,mixed>> $options @return array<int,array<string,mixed>> */
	public static function register_options( array $options ): array { $contact = __( 'Contact', 'core-blueprint-crm' ); $organization = __( 'Organization', 'core-blueprint-crm' ); $agreement = __( 'Service Agreement', 'core-blueprint-crm' ); $options[] = [ 'key' => self::CURRENT_CONTACT, 'label' => $contact, 'group' => self::GROUP ]; $options[] = self::select_condition( self::CONTACT_SERVICE_AGREEMENT, $contact . ' · ' . $agreement, self::service_options() ); $options[] = self::select_condition( self::CONTACT_ORG, $contact . ' · ' . $organization, self::organization_options() ); $options[] = self::select_condition( self::ORG_SERVICE_AGREEMENT, $organization . ' · ' . $agreement, self::service_options() ); $options[] = self::select_condition( self::RECORD_STATUS, __( 'Status', 'core-blueprint-crm' ), RecordStatus::labels() ); if ( class_exists( Document::class ) ) { $options[] = [ 'key' => self::RECORD_DOCS, 'label' => __( 'Documentation', 'core-blueprint-crm' ), 'group' => self::GROUP ]; } if ( class_exists( HelpdeskProvider::class ) ) { $options[] = [ 'key' => self::CONTACT_OPEN_TICKETS, 'label' => $contact . ' · ' . __( 'Open Helpdesk tickets', 'core-blueprint-crm' ), 'group' => self::GROUP ]; } return $options; }
	public static function result( bool $result, string $key, array $condition ): bool {
		$supported = [ self::CURRENT_CONTACT, self::CONTACT_SERVICE_AGREEMENT, self::CONTACT_ORG, self::ORG_SERVICE_AGREEMENT, self::RECORD_STATUS, self::RECORD_DOCS, self::CONTACT_OPEN_TICKETS ]; if ( ! in_array( $key, $supported, true ) ) { return $result; }
		if ( self::CURRENT_CONTACT === $key ) { return Records::current_user_has_contact(); }
		if ( self::RECORD_DOCS === $key ) { if ( ! class_exists( Document::class ) ) { return false; } $current = RecordContext::current(); if ( is_wp_error( $current ) || ! in_array( $current['type'], [ Entity::CONTACT, Entity::ORGANIZATION ], true ) ) { return false; } $links = DocumentLinks::for_owner( $current['type'], absint( $current['data']['id'] ?? 0 ), 1 ); return ! is_wp_error( $links ) && [] !== $links; }
		if ( self::CONTACT_OPEN_TICKETS === $key ) { if ( ! class_exists( HelpdeskProvider::class ) ) { return false; } $id = RecordContext::identifier( Entity::CONTACT ); return null !== $id && HelpdeskTickets::has_open_for_contact( $id ); }
		$value = isset( $condition['value'] ) && is_scalar( $condition['value'] ) ? (string) $condition['value'] : ''; $compare = isset( $condition['compare'] ) && is_scalar( $condition['compare'] ) ? (string) $condition['compare'] : '=='; if ( ! in_array( $compare, [ '==', '!=' ], true ) ) { return false; }
		if ( self::RECORD_STATUS === $key ) { $status = sanitize_key( $value ); if ( ! in_array( $status, RecordStatus::VALUES, true ) ) { return false; } $current = RecordContext::current(); if ( is_wp_error( $current ) ) { return false; } $matches = Records::record_status_is( $current['type'], absint( $current['data']['id'] ?? 0 ), $status ); return '!=' === $compare ? ! $matches : $matches; }
		$target_id = ctype_digit( $value ) ? absint( $value ) : 0; if ( $target_id <= 0 ) { return false; }
		if ( self::CONTACT_SERVICE_AGREEMENT === $key ) { $id = RecordContext::identifier( Entity::CONTACT ); $matches = null !== $id && Records::contact_has_service_agreement( $target_id, $id ); return '!=' === $compare ? ! $matches : $matches; }
		if ( self::CONTACT_ORG === $key ) { if ( is_wp_error( Organization::get( $target_id ) ) ) { return false; } $id = RecordContext::identifier( Entity::CONTACT ); $matches = null !== $id && Records::contact_belongs_to_organization( $target_id, $id ); return '!=' === $compare ? ! $matches : $matches; }
		$id = RecordContext::identifier( Entity::ORGANIZATION ); $matches = null !== $id && Records::organization_has_service_agreement( $target_id, $id ); return '!=' === $compare ? ! $matches : $matches;
	}
	/** @param array<int|string,string> $values @return array<string,mixed> */ private static function select_condition( string $key, string $label, array $values ): array { return [ 'key' => $key, 'label' => $label, 'group' => self::GROUP, 'compare' => [ 'type' => 'select', 'options' => [ '==' => '=', '!=' => '≠' ] ], 'value' => [ 'type' => 'select', 'options' => $values ] ]; }
	/** @return array<int|string,string> */ private static function service_options(): array { if ( ! class_exists( WorkServices::class ) ) { return []; } $options = []; foreach ( WorkServices::all( 100, [ 'publish', 'draft', 'private' ] ) as $item ) { $id = absint( $item['id'] ?? 0 ); $name = is_scalar( $item['title'] ?? null ) ? sanitize_text_field( (string) $item['title'] ) : ''; if ( $id > 0 && '' !== $name ) { $options[ $id ] = $name; } } return $options; }
	/** @return array<int|string,string> */ private static function organization_options(): array { $query = Organizations::staff( [ 'per_page' => 100 ] ); if ( is_wp_error( $query ) ) { return []; } $options = []; foreach ( $query['items'] as $item ) { $id = absint( $item['id'] ?? 0 ); $name = is_scalar( $item['name'] ?? null ) ? sanitize_text_field( (string) $item['name'] ) : ''; if ( $id > 0 && '' !== $name ) { $options[ $id ] = $name; } } return $options; }
}

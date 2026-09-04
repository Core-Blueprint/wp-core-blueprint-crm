<?php
declare(strict_types=1);

namespace CB\CRM\Frontend\Conditions;

use CB\CRM\Content\Entity;
use CB\CRM\Content\Meta;
use CB\CRM\Content\RecordStatus;
use CB\CRM\Frontend\Access;
use CB\CRM\Repository\Organizations;
use CB\CRM\Repository\ServiceAgreements;
defined( 'ABSPATH' ) || exit;

final class Records {
	public static function current_user_has_contact(): bool { return ! is_wp_error( Access::current_contact_id() ); }
	public static function contact_has_service_agreement( int $work_service_id, ?int $contact_id = null ): bool { $contact_id ??= self::current_contact_id(); return self::has_agreement( Entity::CONTACT, $contact_id, $work_service_id ) && Access::can_read_contact( $contact_id ); }
	public static function organization_has_service_agreement( int $work_service_id, int $organization_id ): bool { return Access::can_read_organization( $organization_id ) && self::has_agreement( Entity::ORGANIZATION, $organization_id, $work_service_id ); }
	public static function contact_belongs_to_organization( int $organization_id, ?int $contact_id = null ): bool { $contact_id ??= self::current_contact_id(); if ( $contact_id <= 0 || $organization_id <= 0 || ! Access::can_read_contact( $contact_id ) ) { return false; } foreach ( Organizations::for_contact( $contact_id ) as $row ) { if ( absint( $row['organization_id'] ?? 0 ) === $organization_id && ( Access::is_staff() || Access::relation_active( $row ) ) ) { return true; } } return false; }
	public static function record_status_is( string $owner_type, int $record_id, string $status ): bool { $status = sanitize_key( $status ); if ( ! in_array( $status, RecordStatus::VALUES, true ) ) { return false; } $allowed = match ( sanitize_key( $owner_type ) ) { Entity::CONTACT => Access::can_read_contact( $record_id ), Entity::ORGANIZATION => Access::can_read_organization( $record_id ), default => false }; return $allowed && RecordStatus::normalize( (string) get_post_meta( $record_id, Meta::STATUS, true ) ) === $status; }
	private static function current_contact_id(): int { $current = Access::current_contact_id(); return is_wp_error( $current ) ? 0 : $current; }
	private static function has_agreement( string $type, int $id, int $service_id ): bool { if ( $id <= 0 || $service_id <= 0 ) { return false; } foreach ( ServiceAgreements::for_owner( $type, $id ) as $row ) { if ( absint( $row['work_service_id'] ?? 0 ) === $service_id && ( Access::is_staff() || Access::agreement_active( $row ) ) ) { return true; } } return false; }
}

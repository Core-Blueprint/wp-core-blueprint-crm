<?php
declare(strict_types=1);

namespace CB\CRM\Frontend;

use CB\CRM\Capabilities;
use CB\CRM\Content\ContactIdentity;
use CB\CRM\Content\PostTypes;
use CB\CRM\Repository\Organizations;
defined( 'ABSPATH' ) || exit;

final class Access {
	public static function is_staff(): bool { return current_user_can( Capabilities::MANAGE ); }
	public static function current_contact_id(): int|\WP_Error {
		$user_id = get_current_user_id(); if ( $user_id <= 0 ) { return new \WP_Error( 'crm_login_required' ); }
		$match = ContactIdentity::find_by_user_id( $user_id ); if ( ! is_array( $match ) || empty( $match['contact_id'] ) ) { return new \WP_Error( 'crm_contact_not_found' ); } if ( ! empty( $match['ambiguous'] ) ) { return new \WP_Error( 'crm_contact_ambiguous' ); }
		$contact_id = absint( $match['contact_id'] ); return self::valid_record( $contact_id, PostTypes::CONTACT ) ? $contact_id : new \WP_Error( 'crm_contact_not_found' );
	}
	public static function can_read_contact( int $contact_id ): bool { if ( ! self::valid_record( $contact_id, PostTypes::CONTACT ) ) { return false; } if ( self::is_staff() ) { return true; } $current = self::current_contact_id(); return ! is_wp_error( $current ) && $current === $contact_id; }
	public static function can_read_organization( int $organization_id ): bool { if ( ! self::valid_record( $organization_id, PostTypes::ORGANIZATION ) ) { return false; } if ( self::is_staff() ) { return true; } $current = self::current_contact_id(); if ( is_wp_error( $current ) ) { return false; } foreach ( Organizations::for_contact( $current ) as $relation ) { if ( absint( $relation['organization_id'] ?? 0 ) === $organization_id && self::relation_active( $relation ) ) { return true; } } return false; }
	/** @return int[] */
	public static function current_organization_ids(): array { $current = self::current_contact_id(); if ( is_wp_error( $current ) ) { return []; } $ids = []; foreach ( Organizations::for_contact( $current ) as $relation ) { $id = absint( $relation['organization_id'] ?? 0 ); if ( $id > 0 && self::relation_active( $relation ) ) { $ids[] = $id; } } return array_values( array_unique( $ids ) ); }
	/** @param array<string,mixed> $relation */
	public static function relation_active( array $relation ): bool { return self::date_window_active( $relation, 'started_at', 'ended_at' ); }
	/** @param array<string,mixed> $agreement */
	public static function agreement_active( array $agreement ): bool { return 'active' === sanitize_key( (string) ( $agreement['status'] ?? '' ) ) && self::date_window_active( $agreement, 'valid_from', 'valid_until' ); }
	private static function valid_record( int $record_id, string $post_type ): bool { $post = $record_id > 0 ? get_post( $record_id ) : null; return $post instanceof \WP_Post && $post_type === $post->post_type && ! in_array( $post->post_status, [ 'trash', 'auto-draft' ], true ); }
	/** @param array<string,mixed> $row */
	private static function date_window_active( array $row, string $from_key, string $until_key ): bool { $today = current_time( 'Y-m-d' ); $from = is_scalar( $row[ $from_key ] ?? null ) ? trim( (string) $row[ $from_key ] ) : ''; if ( '' !== $from ) { $date = self::date( $from ); if ( null === $date || $today < $date ) { return false; } } $until = is_scalar( $row[ $until_key ] ?? null ) ? trim( (string) $row[ $until_key ] ) : ''; if ( '' !== $until ) { $date = self::date( $until ); if ( null === $date || $today > $date ) { return false; } } return true; }
	private static function date( string $value ): ?string { $date = \DateTimeImmutable::createFromFormat( '!Y-m-d', $value ); return $date && $date->format( 'Y-m-d' ) === $value ? $value : null; }
}

<?php
declare(strict_types=1);

namespace CB\CRM\Frontend\Data;

use CB\CRM\Content\Entity;
use CB\CRM\Content\Meta;
use CB\CRM\Content\PostTypes;
use CB\CRM\Content\RecordStatus;
use CB\CRM\Frontend\Access;
use CB\CRM\Repository\Addresses;
use CB\CRM\Repository\BusinessIdentifiers;
use CB\CRM\Repository\ContactMethods;
defined( 'ABSPATH' ) || exit;

final class Organization {
	private const FIELDS = [ 'id', 'name', 'legal_name', 'contact_methods', 'address', 'business_identifiers', 'business_identifier_records', 'service_agreements', 'tags', 'status' ];
	/** @return array<string,mixed>|\WP_Error */ public static function get( int $id ): array|\WP_Error { $post = $id > 0 ? get_post( $id ) : null; return ! $post instanceof \WP_Post || PostTypes::ORGANIZATION !== $post->post_type || ! Access::can_read_organization( $id ) ? new \WP_Error( 'crm_organization_not_found' ) : self::project( $post ); }
	public static function value( string $field, int $id ): mixed { $field = sanitize_key( $field ); if ( ! in_array( $field, self::FIELDS, true ) ) { return null; } $data = self::get( $id ); return is_wp_error( $data ) ? $data : ( $data[ $field ] ?? null ); }
	/** @return string[] */ public static function fields(): array { return self::FIELDS; }
	/** @return array<string,mixed> */ private static function project( \WP_Post $post ): array { $id = (int) $post->ID; $staff = Access::is_staff(); $identifier_rows = $staff ? BusinessIdentifiers::for_organization( $id ) : []; return [ 'id' => $id, 'name' => sanitize_text_field( (string) get_the_title( $post ) ), 'legal_name' => sanitize_text_field( (string) get_post_meta( $id, Meta::LEGAL_NAME, true ) ), 'contact_methods' => self::contact_methods( $id ), 'address' => self::primary_address( $id ), 'business_identifiers' => $staff ? BusinessIdentifiers::canonical_map( $identifier_rows ) : [], 'business_identifier_records' => $staff ? self::business_identifier_records( $identifier_rows ) : [], 'service_agreements' => Support::service_agreements( Entity::ORGANIZATION, $id, $staff ), 'tags' => $staff ? Support::tags( $id ) : [], 'status' => RecordStatus::normalize( (string) get_post_meta( $id, Meta::STATUS, true ) ) ]; }
	/** @return array<int,array<string,mixed>> */ private static function contact_methods( int $id ): array { $items = []; foreach ( ContactMethods::for_owner( Entity::ORGANIZATION, $id ) as $row ) { $items[] = [ 'type' => sanitize_key( (string) ( $row['method_type'] ?? '' ) ), 'label' => sanitize_text_field( (string) ( $row['label'] ?? '' ) ), 'value' => sanitize_text_field( (string) ( $row['value'] ?? '' ) ), 'is_primary' => ! empty( $row['is_primary'] ) ]; } return $items; }
	/** @param array<int,array<string,mixed>> $rows @return array<int,array<string,mixed>> */ private static function business_identifier_records( array $rows ): array { $items = []; foreach ( $rows as $row ) { if ( ! is_array( $row ) ) { continue; } $type = sanitize_key( (string) ( $row['identifier_type'] ?? '' ) ); $value = sanitize_text_field( (string) ( $row['value'] ?? '' ) ); if ( ! in_array( $type, BusinessIdentifiers::TYPES, true ) || '' === $value ) { continue; } $items[] = [ 'type' => $type, 'value' => $value, 'country' => sanitize_text_field( (string) ( $row['country'] ?? '' ) ), 'label' => sanitize_text_field( (string) ( $row['label'] ?? '' ) ), 'is_primary' => ! empty( $row['is_primary'] ) ]; } return $items; }
	/** @return array<string,mixed>|null */ private static function primary_address( int $id ): ?array { $rows = Addresses::for_owner( Entity::ORGANIZATION, $id ); if ( [] === $rows ) { return null; } $row = $rows[0]; foreach ( $rows as $candidate ) { if ( ! empty( $candidate['is_primary'] ) ) { $row = $candidate; break; } } return [ 'label' => sanitize_text_field( (string) ( $row['label'] ?? '' ) ), 'address_line_1' => sanitize_text_field( (string) ( $row['address_line_1'] ?? '' ) ), 'address_line_2' => sanitize_text_field( (string) ( $row['address_line_2'] ?? '' ) ), 'postal_code' => sanitize_text_field( (string) ( $row['postal_code'] ?? '' ) ), 'city' => sanitize_text_field( (string) ( $row['city'] ?? '' ) ), 'region' => sanitize_text_field( (string) ( $row['region'] ?? '' ) ), 'country' => sanitize_text_field( (string) ( $row['country'] ?? '' ) ) ]; }
}

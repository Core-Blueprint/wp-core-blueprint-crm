<?php
declare(strict_types=1);

namespace CB\CRM\Frontend\Data;

use CB\CRM\Content\Entity;
use CB\CRM\Content\Meta;
use CB\CRM\Content\PostTypes;
use CB\CRM\Content\RecordStatus;
use CB\CRM\Frontend\Access;
use CB\CRM\Repository\Addresses;
use CB\CRM\Repository\ContactMethods;

defined( 'ABSPATH' ) || exit;

final class Organization {
	private const FIELDS = [ 'id', 'name', 'legal_name', 'contact_methods', 'address', 'services', 'tags', 'status' ];

	/** @return array<string,mixed>|\WP_Error */
	public static function get( int $organization_id ): array|\WP_Error {
		$post = $organization_id > 0 ? get_post( $organization_id ) : null;
		if ( ! $post instanceof \WP_Post || PostTypes::ORGANIZATION !== $post->post_type || ! Access::can_read_organization( $organization_id ) ) {
			return new \WP_Error( 'crm_organization_not_found' );
		}
		return self::project( $post );
	}

	public static function value( string $field, int $organization_id ): mixed {
		$field = sanitize_key( $field );
		if ( ! in_array( $field, self::FIELDS, true ) ) {
			return null;
		}
		$organization = self::get( $organization_id );
		return is_wp_error( $organization ) ? $organization : ( $organization[ $field ] ?? null );
	}

	/** @return string[] */
	public static function fields(): array {
		return self::FIELDS;
	}

	/** @return array<string,mixed> */
	private static function project( \WP_Post $post ): array {
		$organization_id = (int) $post->ID;
		return [
			'id'              => $organization_id,
			'name'            => sanitize_text_field( (string) get_the_title( $post ) ),
			'legal_name'      => sanitize_text_field( (string) get_post_meta( $organization_id, Meta::LEGAL_NAME, true ) ),
			'contact_methods' => self::contact_methods( $organization_id ),
			'address'         => self::primary_address( $organization_id ),
			'services'        => Support::service_assignments( Entity::ORGANIZATION, $organization_id, Access::is_staff() ),
			'tags'            => Access::is_staff() ? Support::tags( $organization_id ) : [],
			'status'          => RecordStatus::normalize( (string) get_post_meta( $organization_id, Meta::STATUS, true ) ),
		];
	}

	/** @return array<int,array{type:string,label:string,value:string,is_primary:bool}> */
	private static function contact_methods( int $organization_id ): array {
		$items = [];
		foreach ( ContactMethods::for_owner( Entity::ORGANIZATION, $organization_id ) as $row ) {
			$items[] = [
				'type'       => sanitize_key( (string) ( $row['method_type'] ?? '' ) ),
				'label'      => sanitize_text_field( (string) ( $row['label'] ?? '' ) ),
				'value'      => sanitize_text_field( (string) ( $row['value'] ?? '' ) ),
				'is_primary' => ! empty( $row['is_primary'] ),
			];
		}
		return $items;
	}

	/** @return array<string,mixed>|null */
	private static function primary_address( int $organization_id ): ?array {
		$rows = Addresses::for_owner( Entity::ORGANIZATION, $organization_id );
		if ( [] === $rows ) {
			return null;
		}
		$row = $rows[0];
		foreach ( $rows as $candidate ) {
			if ( ! empty( $candidate['is_primary'] ) ) {
				$row = $candidate;
				break;
			}
		}
		return [
			'label'          => sanitize_text_field( (string) ( $row['label'] ?? '' ) ),
			'address_line_1' => sanitize_text_field( (string) ( $row['address_line_1'] ?? '' ) ),
			'address_line_2' => sanitize_text_field( (string) ( $row['address_line_2'] ?? '' ) ),
			'postal_code'    => sanitize_text_field( (string) ( $row['postal_code'] ?? '' ) ),
			'city'           => sanitize_text_field( (string) ( $row['city'] ?? '' ) ),
			'region'         => sanitize_text_field( (string) ( $row['region'] ?? '' ) ),
			'country'        => sanitize_text_field( (string) ( $row['country'] ?? '' ) ),
		];
	}
}

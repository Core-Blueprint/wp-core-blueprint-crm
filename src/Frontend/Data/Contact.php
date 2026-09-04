<?php
declare(strict_types=1);

namespace CB\CRM\Frontend\Data;

use CB\CRM\Content\ContactIdentity;
use CB\CRM\Content\Entity;
use CB\CRM\Content\Meta;
use CB\CRM\Content\PostTypes;
use CB\CRM\Content\RecordStatus;
use CB\CRM\Frontend\Access;
use CB\CRM\Repository\ContactMethods;
use CB\CRM\Repository\Organizations;

defined( 'ABSPATH' ) || exit;

final class Contact {
	private const FIELDS = [
		'id', 'display_name', 'first_name', 'last_name', 'job_title', 'primary_email', 'primary_phone',
		'linked_user_id', 'primary_organization', 'organizations', 'services', 'tags', 'status',
	];

	/** @return array<string,mixed>|\WP_Error */
	public static function current(): array|\WP_Error {
		$contact_id = Access::current_contact_id();
		return is_wp_error( $contact_id ) ? $contact_id : self::get( $contact_id );
	}

	/** @return array<string,mixed>|\WP_Error */
	public static function get( int $contact_id ): array|\WP_Error {
		$post = $contact_id > 0 ? get_post( $contact_id ) : null;
		if ( ! $post instanceof \WP_Post || PostTypes::CONTACT !== $post->post_type || ! Access::can_read_contact( $contact_id ) ) {
			return new \WP_Error( 'crm_contact_not_found' );
		}
		return self::project( $post );
	}

	public static function value( string $field, ?int $contact_id = null ): mixed {
		$field = sanitize_key( $field );
		if ( ! in_array( $field, self::FIELDS, true ) ) {
			return null;
		}
		$contact = null === $contact_id ? self::current() : self::get( $contact_id );
		return is_wp_error( $contact ) ? $contact : ( $contact[ $field ] ?? null );
	}

	/** @return string[] */
	public static function fields(): array {
		return self::FIELDS;
	}

	/** @return array<string,mixed> */
	private static function project( \WP_Post $post ): array {
		$contact_id    = (int) $post->ID;
		$relations     = self::organizations( $contact_id );
		$primary_org   = null;
		foreach ( $relations as $relation ) {
			if ( ! empty( $relation['is_primary'] ) ) {
				$primary_org = $relation;
				break;
			}
		}
		if ( null === $primary_org && [] !== $relations ) {
			$primary_org = $relations[0];
		}

		$phone = ContactMethods::primary( Entity::CONTACT, $contact_id, 'phone' );
		if ( ! is_array( $phone ) ) {
			$phone = ContactMethods::primary( Entity::CONTACT, $contact_id, 'mobile' );
		}

		$status = RecordStatus::normalize( (string) get_post_meta( $contact_id, Meta::STATUS, true ) );
		return [
			'id'                   => $contact_id,
			'display_name'         => sanitize_text_field( (string) get_the_title( $post ) ),
			'first_name'           => sanitize_text_field( (string) get_post_meta( $contact_id, Meta::FIRST_NAME, true ) ),
			'last_name'            => sanitize_text_field( (string) get_post_meta( $contact_id, Meta::LAST_NAME, true ) ),
			'job_title'            => sanitize_text_field( (string) get_post_meta( $contact_id, Meta::JOB_TITLE, true ) ),
			'primary_email'        => ContactIdentity::preferred_email( $contact_id ),
			'primary_phone'        => is_array( $phone ) ? sanitize_text_field( (string) ( $phone['value'] ?? '' ) ) : '',
			'linked_user_id'       => ContactIdentity::linked_user_id( $contact_id ),
			'primary_organization' => $primary_org,
			'organizations'        => $relations,
			'services'             => Support::service_assignments( Entity::CONTACT, $contact_id, Access::is_staff() ),
			'tags'                 => Access::is_staff() ? Support::tags( $contact_id ) : [],
			'status'               => $status,
		];
	}

	/** @return array<int,array<string,mixed>> */
	private static function organizations( int $contact_id ): array {
		$items = [];
		foreach ( Organizations::for_contact( $contact_id ) as $row ) {
			if ( ! Access::is_staff() && ! Access::relation_active( $row ) ) {
				continue;
			}
			$organization_id = absint( $row['organization_id'] ?? 0 );
			$post = $organization_id > 0 ? get_post( $organization_id ) : null;
			if ( ! $post instanceof \WP_Post || PostTypes::ORGANIZATION !== $post->post_type ) {
				continue;
			}
			$items[] = [
				'id'         => $organization_id,
				'name'       => sanitize_text_field( (string) get_the_title( $post ) ),
				'role_title' => sanitize_text_field( (string) ( $row['role_title'] ?? '' ) ),
				'is_primary' => ! empty( $row['is_primary'] ),
				'started_at' => is_scalar( $row['started_at'] ?? null ) ? sanitize_text_field( (string) $row['started_at'] ) : '',
				'ended_at'   => is_scalar( $row['ended_at'] ?? null ) ? sanitize_text_field( (string) $row['ended_at'] ) : '',
			];
		}
		return $items;
	}
}

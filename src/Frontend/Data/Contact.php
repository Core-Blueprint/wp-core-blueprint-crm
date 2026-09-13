<?php
declare(strict_types=1);

namespace CB\CRM\Frontend\Data;

use CB\CRM\Content\ContactIdentity;
use CB\CRM\Content\Meta;
use CB\CRM\Content\PostTypes;
use CB\CRM\Content\RecordStatus;
use CB\CRM\Frontend\Access;
use CB\CRM\Integration\ContactDataSources;
use CB\CRM\Repository\Organizations;
defined( 'ABSPATH' ) || exit;

final class Contact {
	private const FIELDS = [ 'id', 'display_name', 'first_name', 'last_name', 'job_title', 'primary_email', 'primary_phone', 'linked_user_id', 'primary_organization', 'organizations', 'service_agreements', 'tags', 'status' ];
	/** @return array<string,mixed>|\WP_Error */ public static function current(): array|\WP_Error { $id = Access::current_contact_id(); return is_wp_error( $id ) ? $id : self::get( $id ); }
	/** @return array<string,mixed>|\WP_Error */ public static function get( int $id ): array|\WP_Error { $post = $id > 0 ? get_post( $id ) : null; return ! $post instanceof \WP_Post || PostTypes::CONTACT !== $post->post_type || ! Access::can_read_contact( $id ) ? new \WP_Error( 'crm_contact_not_found' ) : self::project( $post ); }
	public static function value( string $field, ?int $id = null ): mixed { $field = sanitize_key( $field ); if ( ! in_array( $field, self::FIELDS, true ) ) { return null; } $data = null === $id ? self::current() : self::get( $id ); return is_wp_error( $data ) ? $data : ( $data[ $field ] ?? null ); }
	/** @return string[] */ public static function fields(): array { return self::FIELDS; }
	/** @return array<string,mixed> */ private static function project( \WP_Post $post ): array { $id = (int) $post->ID; $relations = self::organizations( $id ); $primary = null; foreach ( $relations as $relation ) { if ( ! empty( $relation['is_primary'] ) ) { $primary = $relation; break; } } $primary ??= $relations[0] ?? null; $first_name = ContactDataSources::name_field( $id, 'first_name' ); $last_name = ContactDataSources::name_field( $id, 'last_name' ); return [ 'id' => $id, 'display_name' => sanitize_text_field( (string) get_the_title( $post ) ), 'first_name' => (string) $first_name['value'], 'last_name' => (string) $last_name['value'], 'job_title' => sanitize_text_field( (string) get_post_meta( $id, Meta::JOB_TITLE, true ) ), 'primary_email' => ContactDataSources::effective_email( $id ), 'primary_phone' => ContactDataSources::effective_phone( $id ), 'linked_user_id' => ContactIdentity::linked_user_id( $id ), 'primary_organization' => $primary, 'organizations' => $relations, 'service_agreements' => Support::service_agreements( \CB\CRM\Content\Entity::CONTACT, $id, Access::is_staff() ), 'tags' => Access::is_staff() ? Support::tags( $id ) : [], 'status' => RecordStatus::normalize( (string) get_post_meta( $id, Meta::STATUS, true ) ) ]; }
	/** @return array<int,array<string,mixed>> */ private static function organizations( int $id ): array { $items = []; foreach ( Organizations::for_contact( $id ) as $row ) { if ( ! Access::is_staff() && ! Access::relation_active( $row ) ) { continue; } $org_id = absint( $row['organization_id'] ?? 0 ); $post = $org_id > 0 ? get_post( $org_id ) : null; if ( ! $post instanceof \WP_Post || PostTypes::ORGANIZATION !== $post->post_type ) { continue; } $items[] = [ 'id' => $org_id, 'name' => sanitize_text_field( (string) get_the_title( $post ) ), 'role_title' => sanitize_text_field( (string) ( $row['role_title'] ?? '' ) ), 'is_primary' => ! empty( $row['is_primary'] ), 'started_at' => (string) ( $row['started_at'] ?? '' ), 'ended_at' => (string) ( $row['ended_at'] ?? '' ) ]; } return $items; }
}

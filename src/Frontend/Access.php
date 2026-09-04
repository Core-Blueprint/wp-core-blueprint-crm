<?php
declare(strict_types=1);

namespace CB\CRM\Frontend;

use CB\CRM\Capabilities;
use CB\CRM\Content\ContactIdentity;
use CB\CRM\Content\Entity;
use CB\CRM\Content\PostTypes;
use CB\CRM\Repository\Organizations;
use CB\CRM\Repository\Services;

defined( 'ABSPATH' ) || exit;

final class Access {
	public static function is_staff(): bool {
		return current_user_can( Capabilities::MANAGE );
	}

	public static function current_contact_id(): int|\WP_Error {
		$user_id = get_current_user_id();
		if ( $user_id <= 0 ) {
			return new \WP_Error( 'crm_login_required' );
		}

		$match = ContactIdentity::find_by_user_id( $user_id );
		if ( ! is_array( $match ) || empty( $match['contact_id'] ) ) {
			return new \WP_Error( 'crm_contact_not_found' );
		}
		if ( ! empty( $match['ambiguous'] ) ) {
			return new \WP_Error( 'crm_contact_ambiguous' );
		}

		$contact_id = absint( $match['contact_id'] );
		return self::valid_record( $contact_id, PostTypes::CONTACT )
			? $contact_id
			: new \WP_Error( 'crm_contact_not_found' );
	}

	public static function can_read_contact( int $contact_id ): bool {
		if ( ! self::valid_record( $contact_id, PostTypes::CONTACT ) ) {
			return false;
		}
		if ( self::is_staff() ) {
			return true;
		}

		$current = self::current_contact_id();
		return ! is_wp_error( $current ) && $current === $contact_id;
	}

	public static function can_read_organization( int $organization_id ): bool {
		if ( ! self::valid_record( $organization_id, PostTypes::ORGANIZATION ) ) {
			return false;
		}
		if ( self::is_staff() ) {
			return true;
		}

		$current = self::current_contact_id();
		if ( is_wp_error( $current ) ) {
			return false;
		}

		foreach ( Organizations::for_contact( $current ) as $relation ) {
			if ( absint( $relation['organization_id'] ?? 0 ) !== $organization_id ) {
				continue;
			}
			if ( self::relation_active( $relation ) ) {
				return true;
			}
		}
		return false;
	}

	public static function can_read_service( int $service_id ): bool {
		if ( ! self::valid_record( $service_id, PostTypes::SERVICE ) ) {
			return false;
		}
		if ( self::is_staff() ) {
			return true;
		}

		return in_array( $service_id, self::current_service_ids(), true );
	}

	/** @return int[] */
	public static function current_organization_ids(): array {
		$current = self::current_contact_id();
		if ( is_wp_error( $current ) ) {
			return [];
		}

		$ids = [];
		foreach ( Organizations::for_contact( $current ) as $relation ) {
			$organization_id = absint( $relation['organization_id'] ?? 0 );
			if ( $organization_id > 0 && self::relation_active( $relation ) ) {
				$ids[] = $organization_id;
			}
		}
		return array_values( array_unique( $ids ) );
	}

	/** @return int[] */
	public static function current_service_ids(): array {
		$current = self::current_contact_id();
		if ( is_wp_error( $current ) ) {
			return [];
		}

		$ids = self::active_service_ids( Entity::CONTACT, $current );
		foreach ( self::current_organization_ids() as $organization_id ) {
			$ids = array_merge( $ids, self::active_service_ids( Entity::ORGANIZATION, $organization_id ) );
		}
		return array_values( array_unique( array_map( 'intval', $ids ) ) );
	}

	/** @param array<string,mixed> $relation */
	public static function relation_active( array $relation ): bool {
		return self::date_window_active( $relation );
	}

	/** @param array<string,mixed> $assignment */
	public static function assignment_active( array $assignment ): bool {
		return 'active' === sanitize_key( (string) ( $assignment['status'] ?? '' ) )
			&& self::date_window_active( $assignment );
	}

	private static function valid_record( int $record_id, string $post_type ): bool {
		$post = $record_id > 0 ? get_post( $record_id ) : null;
		return $post instanceof \WP_Post
			&& $post_type === $post->post_type
			&& ! in_array( $post->post_status, [ 'trash', 'auto-draft' ], true );
	}

	/** @param array<string,mixed> $row */
	private static function date_window_active( array $row ): bool {
		$today = current_time( 'Y-m-d' );
		$started_raw = is_scalar( $row['started_at'] ?? null ) ? trim( (string) $row['started_at'] ) : '';
		if ( '' !== $started_raw ) {
			$started = self::date( $started_raw );
			if ( null === $started || $today < $started ) {
				return false;
			}
		}

		$ended_raw = is_scalar( $row['ended_at'] ?? null ) ? trim( (string) $row['ended_at'] ) : '';
		if ( '' !== $ended_raw ) {
			$ended = self::date( $ended_raw );
			if ( null === $ended || $today > $ended ) {
				return false;
			}
		}

		return true;
	}

	private static function date( string $value ): ?string {
		$date = \DateTimeImmutable::createFromFormat( '!Y-m-d', $value );
		return $date && $date->format( 'Y-m-d' ) === $value ? $value : null;
	}

	/** @return int[] */
	private static function active_service_ids( string $owner_type, int $owner_id ): array {
		$ids = [];
		foreach ( Services::for_owner( $owner_type, $owner_id ) as $assignment ) {
			if ( ! self::assignment_active( $assignment ) ) {
				continue;
			}
			$service_id = absint( $assignment['service_id'] ?? 0 );
			if ( $service_id > 0 ) {
				$ids[] = $service_id;
			}
		}
		return $ids;
	}
}

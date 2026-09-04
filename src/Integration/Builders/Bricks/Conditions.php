<?php
declare(strict_types=1);

namespace CB\CRM\Integration\Builders\Bricks;

use CB\CRM\Content\Entity;
use CB\CRM\Content\PostTypes;
use CB\CRM\Content\RecordStatus;
use CB\CRM\Frontend\Conditions\Records;
use CB\CRM\Frontend\Data\Organization;
use CB\CRM\Frontend\Data\Service;
use CB\CRM\Frontend\Queries\Organizations;
use CB\CRM\Frontend\Queries\Services;

defined( 'ABSPATH' ) || exit;

final class Conditions {
	private const GROUP            = 'cb_crm';
	private const CURRENT_CONTACT  = 'cb_crm_current_user_has_contact';
	private const CONTACT_SERVICE  = 'cb_crm_contact_has_service';
	private const CONTACT_ORG      = 'cb_crm_contact_organization';
	private const ORG_SERVICE      = 'cb_crm_organization_has_service';
	private const RECORD_STATUS    = 'cb_crm_record_status';

	public static function init(): void {
		add_filter( 'bricks/conditions/groups', [ self::class, 'register_group' ] );
		add_filter( 'bricks/conditions/options', [ self::class, 'register_options' ] );
		add_filter( 'bricks/conditions/result', [ self::class, 'result' ], 10, 3 );
	}

	/** @param array<int,array<string,mixed>> $groups
	 *  @return array<int,array<string,mixed>>
	 */
	public static function register_group( array $groups ): array {
		$groups[] = [ 'name' => self::GROUP, 'label' => __( 'Core Blueprint CRM', 'core-blueprint-crm' ) ];
		return $groups;
	}

	/** @param array<int,array<string,mixed>> $options
	 *  @return array<int,array<string,mixed>>
	 */
	public static function register_options( array $options ): array {
		$contact      = __( 'Contact', 'core-blueprint-crm' );
		$organization = __( 'Organization', 'core-blueprint-crm' );
		$service      = __( 'Service', 'core-blueprint-crm' );

		$options[] = [ 'key' => self::CURRENT_CONTACT, 'label' => $contact, 'group' => self::GROUP ];
		$options[] = self::select_condition( self::CONTACT_SERVICE, $contact . ' · ' . $service, self::service_options() );
		$options[] = self::select_condition( self::CONTACT_ORG, $contact . ' · ' . $organization, self::organization_options() );
		$options[] = self::select_condition( self::ORG_SERVICE, $organization . ' · ' . $service, self::service_options() );
		$options[] = self::select_condition( self::RECORD_STATUS, __( 'Status', 'core-blueprint-crm' ), RecordStatus::labels() );
		return $options;
	}

	public static function result( bool $result, string $condition_key, array $condition ): bool {
		if ( ! in_array( $condition_key, [ self::CURRENT_CONTACT, self::CONTACT_SERVICE, self::CONTACT_ORG, self::ORG_SERVICE, self::RECORD_STATUS ], true ) ) {
			return $result;
		}
		if ( self::CURRENT_CONTACT === $condition_key ) {
			return Records::current_user_has_contact();
		}

		$value = isset( $condition['value'] ) && is_scalar( $condition['value'] ) ? (string) $condition['value'] : '';
		$compare = isset( $condition['compare'] ) && is_scalar( $condition['compare'] ) ? (string) $condition['compare'] : '==';
		if ( ! in_array( $compare, [ '==', '!=' ], true ) ) {
			return false;
		}

		if ( self::RECORD_STATUS === $condition_key ) {
			$status = sanitize_key( $value );
			if ( ! in_array( $status, RecordStatus::VALUES, true ) ) {
				return false;
			}
			$current = RecordContext::current();
			if ( is_wp_error( $current ) ) {
				return false;
			}
			$id = absint( $current['data']['id'] ?? 0 );
			$matches = $id > 0 && Records::record_status_is( $current['type'], $id, $status );
			return '!=' === $compare ? ! $matches : $matches;
		}

		$target_id = ctype_digit( $value ) ? absint( $value ) : 0;
		if ( $target_id <= 0 ) {
			return false;
		}

		if ( self::CONTACT_SERVICE === $condition_key ) {
			if ( is_wp_error( Service::get( $target_id ) ) ) {
				return false;
			}
			$contact_id = RecordContext::identifier( Entity::CONTACT );
			if ( null === $contact_id ) {
				return false;
			}
			$matches = Records::contact_has_service( $target_id, $contact_id );
			return '!=' === $compare ? ! $matches : $matches;
		}

		if ( self::CONTACT_ORG === $condition_key ) {
			if ( is_wp_error( Organization::get( $target_id ) ) ) {
				return false;
			}
			$contact_id = RecordContext::identifier( Entity::CONTACT );
			if ( null === $contact_id ) {
				return false;
			}
			$matches = Records::contact_belongs_to_organization( $target_id, $contact_id );
			return '!=' === $compare ? ! $matches : $matches;
		}

		if ( is_wp_error( Service::get( $target_id ) ) ) {
			return false;
		}
		$organization_id = RecordContext::identifier( Entity::ORGANIZATION );
		if ( null === $organization_id ) {
			return false;
		}
		$matches = Records::organization_has_service( $target_id, $organization_id );
		return '!=' === $compare ? ! $matches : $matches;
	}

	/** @param array<int|string,string> $values
	 *  @return array<string,mixed>
	 */
	private static function select_condition( string $key, string $label, array $values ): array {
		return [
			'key'     => $key,
			'label'   => $label,
			'group'   => self::GROUP,
			'compare' => [ 'type' => 'select', 'options' => [ '==' => '=', '!=' => '≠' ] ],
			'value'   => [ 'type' => 'select', 'options' => $values ],
		];
	}

	/** @return array<int|string,string> */
	private static function service_options(): array {
		$query = Services::query( [ 'per_page' => 100 ] );
		if ( is_wp_error( $query ) ) {
			return [];
		}
		$options = [];
		foreach ( $query['items'] as $item ) {
			$id = absint( $item['id'] ?? 0 );
			$name = isset( $item['name'] ) && is_scalar( $item['name'] ) ? sanitize_text_field( (string) $item['name'] ) : '';
			if ( $id > 0 && '' !== $name ) {
				$options[ $id ] = $name;
			}
		}
		return $options;
	}

	/** @return array<int|string,string> */
	private static function organization_options(): array {
		$query = Organizations::staff( [ 'per_page' => 100 ] );
		if ( is_wp_error( $query ) ) {
			return [];
		}
		$options = [];
		foreach ( $query['items'] as $item ) {
			$id = absint( $item['id'] ?? 0 );
			$name = isset( $item['name'] ) && is_scalar( $item['name'] ) ? sanitize_text_field( (string) $item['name'] ) : '';
			if ( $id > 0 && '' !== $name ) {
				$options[ $id ] = $name;
			}
		}
		return $options;
	}
}

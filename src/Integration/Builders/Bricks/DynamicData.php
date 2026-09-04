<?php
declare(strict_types=1);

namespace CB\CRM\Integration\Builders\Bricks;

use CB\CRM\Content\Entity;

defined( 'ABSPATH' ) || exit;

final class DynamicData {
	private const GROUP = 'Core Blueprint CRM';

	/** @var array<string,array{type:string,field:string}> */
	private const FIELDS = [
		'cb_crm_contact_id'                   => [ 'type' => Entity::CONTACT, 'field' => 'id' ],
		'cb_crm_contact_display_name'         => [ 'type' => Entity::CONTACT, 'field' => 'display_name' ],
		'cb_crm_contact_first_name'           => [ 'type' => Entity::CONTACT, 'field' => 'first_name' ],
		'cb_crm_contact_last_name'            => [ 'type' => Entity::CONTACT, 'field' => 'last_name' ],
		'cb_crm_contact_job_title'            => [ 'type' => Entity::CONTACT, 'field' => 'job_title' ],
		'cb_crm_contact_email'                => [ 'type' => Entity::CONTACT, 'field' => 'primary_email' ],
		'cb_crm_contact_phone'                => [ 'type' => Entity::CONTACT, 'field' => 'primary_phone' ],
		'cb_crm_contact_linked_user_id'       => [ 'type' => Entity::CONTACT, 'field' => 'linked_user_id' ],
		'cb_crm_contact_primary_organization' => [ 'type' => Entity::CONTACT, 'field' => 'primary_organization' ],
		'cb_crm_contact_organizations'        => [ 'type' => Entity::CONTACT, 'field' => 'organizations' ],
		'cb_crm_contact_services'             => [ 'type' => Entity::CONTACT, 'field' => 'services' ],
		'cb_crm_contact_tags'                 => [ 'type' => Entity::CONTACT, 'field' => 'tags' ],
		'cb_crm_contact_status'               => [ 'type' => Entity::CONTACT, 'field' => 'status' ],

		'cb_crm_organization_id'              => [ 'type' => Entity::ORGANIZATION, 'field' => 'id' ],
		'cb_crm_organization_name'            => [ 'type' => Entity::ORGANIZATION, 'field' => 'name' ],
		'cb_crm_organization_legal_name'      => [ 'type' => Entity::ORGANIZATION, 'field' => 'legal_name' ],
		'cb_crm_organization_contact_methods' => [ 'type' => Entity::ORGANIZATION, 'field' => 'contact_methods' ],
		'cb_crm_organization_address'         => [ 'type' => Entity::ORGANIZATION, 'field' => 'address' ],
		'cb_crm_organization_services'        => [ 'type' => Entity::ORGANIZATION, 'field' => 'services' ],
		'cb_crm_organization_tags'            => [ 'type' => Entity::ORGANIZATION, 'field' => 'tags' ],
		'cb_crm_organization_status'          => [ 'type' => Entity::ORGANIZATION, 'field' => 'status' ],

		'cb_crm_service_id'                   => [ 'type' => Entity::SERVICE, 'field' => 'id' ],
		'cb_crm_service_name'                 => [ 'type' => Entity::SERVICE, 'field' => 'name' ],
		'cb_crm_service_pricing'              => [ 'type' => Entity::SERVICE, 'field' => 'pricing_summary' ],
		'cb_crm_service_amount'               => [ 'type' => Entity::SERVICE, 'field' => 'amount_minor' ],
		'cb_crm_service_currency'             => [ 'type' => Entity::SERVICE, 'field' => 'currency' ],
		'cb_crm_service_tax_mode'             => [ 'type' => Entity::SERVICE, 'field' => 'tax_mode' ],
		'cb_crm_service_tax_rate'             => [ 'type' => Entity::SERVICE, 'field' => 'tax_rate' ],
		'cb_crm_service_status'               => [ 'type' => Entity::SERVICE, 'field' => 'status' ],
	];

	public static function init(): void {
		add_filter( 'bricks/dynamic_tags_list', [ self::class, 'register_tags' ] );
		add_filter( 'bricks/dynamic_data/render_tag', [ self::class, 'render_tag' ], 20, 3 );
		add_filter( 'bricks/dynamic_data/render_content', [ self::class, 'render_content' ], 20, 3 );
		add_filter( 'bricks/frontend/render_data', [ self::class, 'render_content' ], 20, 2 );
	}

	/** @param array<int,array<string,mixed>> $tags
	 *  @return array<int,array<string,mixed>>
	 */
	public static function register_tags( array $tags ): array {
		foreach ( self::labels() as $name => $label ) {
			$tags[] = [ 'name' => '{' . $name . '}', 'label' => $label, 'group' => self::GROUP ];
		}
		return $tags;
	}

	public static function render_tag( mixed $tag, mixed $post = null, string $context = 'text' ): mixed {
		unset( $post, $context );
		if ( ! is_string( $tag ) ) {
			return $tag;
		}
		$name = trim( $tag, '{}' );
		return isset( self::FIELDS[ $name ] ) ? self::value_for( $name ) : $tag;
	}

	public static function render_content( mixed $content, mixed $post = null, string $context = 'text' ): mixed {
		unset( $post, $context );
		if ( ! is_string( $content ) || false === strpos( $content, '{cb_crm_' ) ) {
			return $content;
		}
		foreach ( self::FIELDS as $name => $definition ) {
			unset( $definition );
			$needle = '{' . $name . '}';
			if ( false !== strpos( $content, $needle ) ) {
				$content = str_replace( $needle, self::value_for( $name ), $content );
			}
		}
		return $content;
	}

	private static function value_for( string $name ): string {
		$definition = self::FIELDS[ $name ];
		$data       = RecordContext::data( $definition['type'] );
		if ( is_wp_error( $data ) ) {
			return '';
		}
		$value = $data[ $definition['field'] ] ?? null;
		return self::string_value( $definition['field'], $value );
	}

	private static function string_value( string $field, mixed $value ): string {
		if ( 'amount_minor' === $field ) {
			if ( null === $value || ! is_numeric( $value ) ) {
				return '';
			}
			$minor = max( 0, (int) $value );
			return intdiv( $minor, 100 ) . '.' . str_pad( (string) ( $minor % 100 ), 2, '0', STR_PAD_LEFT );
		}
		if ( 'primary_organization' === $field && is_array( $value ) ) {
			return sanitize_text_field( (string) ( $value['name'] ?? '' ) );
		}
		if ( 'address' === $field && is_array( $value ) ) {
			$parts = [];
			foreach ( [ 'address_line_1', 'address_line_2', 'postal_code', 'city', 'region', 'country' ] as $key ) {
				$part = sanitize_text_field( (string) ( $value[ $key ] ?? '' ) );
				if ( '' !== $part ) {
					$parts[] = $part;
				}
			}
			return implode( ', ', $parts );
		}
		if ( 'tax_rate' === $field && is_array( $value ) ) {
			$label = sanitize_text_field( (string) ( $value['label'] ?? '' ) );
			$rate  = sanitize_text_field( (string) ( $value['rate'] ?? '' ) );
			return trim( $label . ( '' !== $rate ? ' ' . $rate . '%' : '' ) );
		}
		if ( 'contact_methods' === $field && is_array( $value ) ) {
			$items = [];
			foreach ( $value as $row ) {
				if ( is_array( $row ) && isset( $row['value'] ) && is_scalar( $row['value'] ) ) {
					$item = sanitize_text_field( (string) $row['value'] );
					if ( '' !== $item ) {
						$items[] = $item;
					}
				}
			}
			return implode( ', ', array_values( array_unique( $items ) ) );
		}
		if ( in_array( $field, [ 'organizations', 'services', 'tags' ], true ) && is_array( $value ) ) {
			$items = [];
			foreach ( $value as $row ) {
				if ( is_array( $row ) && isset( $row['name'] ) && is_scalar( $row['name'] ) ) {
					$item = sanitize_text_field( (string) $row['name'] );
					if ( '' !== $item ) {
						$items[] = $item;
					}
				}
			}
			return implode( ', ', array_values( array_unique( $items ) ) );
		}
		if ( is_bool( $value ) ) {
			return $value ? '1' : '';
		}
		return is_scalar( $value ) ? (string) $value : '';
	}

	/** @return array<string,string> */
	private static function labels(): array {
		$contact      = __( 'Contact', 'core-blueprint-crm' );
		$organization = __( 'Organization', 'core-blueprint-crm' );
		$service      = __( 'Service', 'core-blueprint-crm' );
		return [
			'cb_crm_contact_id'                   => $contact . ' · ID',
			'cb_crm_contact_display_name'         => $contact . ' · ' . __( 'Display name', 'core-blueprint-crm' ),
			'cb_crm_contact_first_name'           => $contact . ' · ' . __( 'First name', 'core-blueprint-crm' ),
			'cb_crm_contact_last_name'            => $contact . ' · ' . __( 'Last name', 'core-blueprint-crm' ),
			'cb_crm_contact_job_title'            => $contact . ' · ' . __( 'Job title', 'core-blueprint-crm' ),
			'cb_crm_contact_email'                => $contact . ' · ' . __( 'Email', 'core-blueprint-crm' ),
			'cb_crm_contact_phone'                => $contact . ' · ' . __( 'Phone', 'core-blueprint-crm' ),
			'cb_crm_contact_linked_user_id'       => $contact . ' · ' . __( 'Linked WordPress account', 'core-blueprint-crm' ),
			'cb_crm_contact_primary_organization' => $contact . ' · ' . __( 'Primary organization', 'core-blueprint-crm' ),
			'cb_crm_contact_organizations'        => $contact . ' · ' . __( 'Organizations', 'core-blueprint-crm' ),
			'cb_crm_contact_services'             => $contact . ' · ' . __( 'Services', 'core-blueprint-crm' ),
			'cb_crm_contact_tags'                 => $contact . ' · ' . __( 'CRM Tags', 'core-blueprint-crm' ),
			'cb_crm_contact_status'               => $contact . ' · ' . __( 'Status', 'core-blueprint-crm' ),
			'cb_crm_organization_id'              => $organization . ' · ID',
			'cb_crm_organization_name'            => $organization . ' · ' . __( 'Organization name', 'core-blueprint-crm' ),
			'cb_crm_organization_legal_name'      => $organization . ' · ' . __( 'Legal name', 'core-blueprint-crm' ),
			'cb_crm_organization_contact_methods' => $organization . ' · ' . __( 'Contact Methods', 'core-blueprint-crm' ),
			'cb_crm_organization_address'         => $organization . ' · ' . __( 'Primary address', 'core-blueprint-crm' ),
			'cb_crm_organization_services'        => $organization . ' · ' . __( 'Services', 'core-blueprint-crm' ),
			'cb_crm_organization_tags'            => $organization . ' · ' . __( 'CRM Tags', 'core-blueprint-crm' ),
			'cb_crm_organization_status'          => $organization . ' · ' . __( 'Status', 'core-blueprint-crm' ),
			'cb_crm_service_id'                   => $service . ' · ID',
			'cb_crm_service_name'                 => $service . ' · ' . __( 'Service name', 'core-blueprint-crm' ),
			'cb_crm_service_pricing'              => $service . ' · ' . __( 'Pricing', 'core-blueprint-crm' ),
			'cb_crm_service_amount'               => $service . ' · ' . __( 'Custom price', 'core-blueprint-crm' ),
			'cb_crm_service_currency'             => $service . ' · ' . __( 'Currency', 'core-blueprint-crm' ),
			'cb_crm_service_tax_mode'             => $service . ' · ' . __( 'Price is', 'core-blueprint-crm' ),
			'cb_crm_service_tax_rate'             => $service . ' · ' . __( 'Rate', 'core-blueprint-crm' ),
			'cb_crm_service_status'               => $service . ' · ' . __( 'Status', 'core-blueprint-crm' ),
		];
	}
}

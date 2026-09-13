<?php
declare(strict_types=1);

namespace CB\CRM\Admin\Panels;

use CB\CRM\Content\Entity;
use CB\CRM\Integration\ContactDataSources;
use CB\CRM\Integration\WooCustomerWorkspace;
use CB\CRM\PanelRegistry;
use CB\CRM\Repository\ContactMethods;

defined( 'ABSPATH' ) || exit;

final class ContactMethodsPanel {
	public static function register(): void {
		PanelRegistry::register( [
			'id'         => 'contact-methods',
			'label'      => __( 'Contact Methods', 'core-blueprint-crm' ),
			'post_types' => [ Entity::CONTACT, Entity::ORGANIZATION ],
			'render'     => [ \CB\CRM\Admin\Panels::class, 'contact_methods' ],
		] );
	}

	public static function render( string $owner_type, int $post_id ): void {
		$rows = ContactMethods::for_owner( $owner_type, $post_id );
		$external = Entity::CONTACT === $owner_type ? self::external_groups( ContactDataSources::external_contact_methods( $post_id ) ) : [];
		$consumed = [];
		echo '<input type="hidden" name="cb_crm_contact_methods_present" value="1">';
		RepeatableTable::start( 'contact-methods', count( $rows ), [ __( 'Type', 'core-blueprint-crm' ), __( 'Label', 'core-blueprint-crm' ), __( 'Value', 'core-blueprint-crm' ), __( 'Primary', 'core-blueprint-crm' ), __( 'Actions', 'core-blueprint-crm' ) ] );
		foreach ( $rows as $i => $row ) {
			$fingerprint = self::fingerprint( (string) ( $row['method_type'] ?? '' ), (string) ( $row['value'] ?? '' ) );
			$connected = isset( $external[ $fingerprint ] ) ? $external[ $fingerprint ]['sources'] : [];
			if ( [] !== $connected ) {
				$consumed[ $fingerprint ] = true;
			}
			self::row( $i, $row, $connected );
		}
		foreach ( $external as $fingerprint => $group ) {
			if ( ! empty( $consumed[ $fingerprint ] ) ) {
				continue;
			}
			self::external_row( $group );
		}
		RepeatableTable::middle();
		?>
		<template><?php self::row( '__INDEX__', [] ); ?></template>
		<?php RepeatableTable::end( __( 'Add contact method', 'core-blueprint-crm' ) );
	}

	/** @param array<string,mixed> $row @param array<int,array<string,string>> $connected */
	private static function row( int|string $index, array $row, array $connected = [] ): void {
		$prefix = 'cb_crm_contact_methods[' . (string) $index . ']';
		$value = (string) ( $row['value'] ?? '' );
		?>
		<tr data-cb-crm-row>
			<td><select name="<?php echo esc_attr( $prefix . '[method_type]' ); ?>"><?php foreach ( ContactMethods::TYPES as $type ) : ?><option value="<?php echo esc_attr( $type ); ?>" <?php selected( (string) ( $row['method_type'] ?? 'email' ), $type ); ?>><?php echo esc_html( self::label( $type ) ); ?></option><?php endforeach; ?></select></td>
			<td><input type="text" name="<?php echo esc_attr( $prefix . '[label]' ); ?>" value="<?php echo esc_attr( (string) ( $row['label'] ?? '' ) ); ?>"></td>
			<td><div class="cb-crm-source-field"><input type="text" class="regular-text" name="<?php echo esc_attr( $prefix . '[value]' ); ?>" value="<?php echo esc_attr( $value ); ?>"><span class="cb-crm-source-badges"><?php SourceBadge::render( 'crm', 'contact_methods', $value ); ?><?php foreach ( $connected as $source ) { SourceBadge::render( $source['source'], $source['path'], $source['value'], false ); } ?></span></div></td>
			<td><label><input type="checkbox" name="<?php echo esc_attr( $prefix . '[is_primary]' ); ?>" value="1" <?php checked( ! empty( $row['is_primary'] ) ); ?>> <span class="screen-reader-text"><?php esc_html_e( 'Primary contact method', 'core-blueprint-crm' ); ?></span></label></td>
			<td><button type="button" class="button-link-delete" data-cb-crm-remove-row><?php esc_html_e( 'Remove', 'core-blueprint-crm' ); ?></button></td>
		</tr>
		<?php
	}

	/** @param array{type:string,value:string,labels:array<int,string>,sources:array<int,array<string,string>>} $group */
	private static function external_row( array $group ): void {
		$sources = $group['sources'];
		$single = 1 === count( $sources ) ? $sources[0] : null;
		$can_edit = is_array( $single ) && 'woocommerce' === $single['source'] && '' !== $single['woo_key'] && WooCustomerWorkspace::can_edit_customer();
		$type = (string) $group['type'];
		$value = (string) $group['value'];
		$input_type = 'email' === $type ? 'email' : ( 'phone' === $type || 'mobile' === $type ? 'tel' : 'text' );
		?>
		<tr class="cb-crm-source-row">
			<td><?php echo esc_html( self::label( $type ) ); ?></td>
			<td><?php echo esc_html( implode( ' / ', array_unique( $group['labels'] ) ) ); ?></td>
			<td><div class="cb-crm-source-field"><input type="<?php echo esc_attr( $input_type ); ?>" class="regular-text" <?php if ( $can_edit ) : ?>name="cb_crm_woo_customer[<?php echo esc_attr( (string) $single['woo_key'] ); ?>]"<?php else : ?>readonly<?php endif; ?> value="<?php echo esc_attr( $value ); ?>"><span class="cb-crm-source-badges"><?php foreach ( $sources as $source ) { SourceBadge::render( $source['source'], $source['path'], $source['value'] ); } ?></span></div></td>
			<td aria-hidden="true">—</td>
			<td aria-hidden="true">—</td>
		</tr>
		<?php
	}

	/**
	 * @param array<int,array{type:string,label:string,value:string,source:string,path:string,woo_key:string}> $rows
	 * @return array<string,array{type:string,value:string,labels:array<int,string>,sources:array<int,array<string,string>>}>
	 */
	private static function external_groups( array $rows ): array {
		$groups = [];
		foreach ( $rows as $row ) {
			$fingerprint = self::fingerprint( $row['type'], $row['value'] );
			if ( '' === $fingerprint ) {
				continue;
			}
			if ( ! isset( $groups[ $fingerprint ] ) ) {
				$groups[ $fingerprint ] = [ 'type' => $row['type'], 'value' => $row['value'], 'labels' => [], 'sources' => [] ];
			}
			$groups[ $fingerprint ]['labels'][] = $row['label'];
			$groups[ $fingerprint ]['sources'][] = [
				'source' => $row['source'],
				'path' => $row['path'],
				'value' => $row['value'],
				'woo_key' => $row['woo_key'],
			];
		}
		return $groups;
	}

	private static function fingerprint( string $type, string $value ): string {
		$type = sanitize_key( $type );
		$value = strtolower( trim( $value ) );
		return '' !== $type && '' !== $value ? $type . '|' . $value : '';
	}

	private static function label( string $type ): string {
		return match ( $type ) {
			'email'    => __( 'Email', 'core-blueprint-crm' ),
			'phone'    => __( 'Phone', 'core-blueprint-crm' ),
			'mobile'   => __( 'Mobile', 'core-blueprint-crm' ),
			'website'  => __( 'Website', 'core-blueprint-crm' ),
			'whatsapp' => __( 'WhatsApp', 'core-blueprint-crm' ),
			default    => __( 'Other', 'core-blueprint-crm' ),
		};
	}
}

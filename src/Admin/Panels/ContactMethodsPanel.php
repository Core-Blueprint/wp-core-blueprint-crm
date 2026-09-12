<?php
declare(strict_types=1);

namespace CB\CRM\Admin\Panels;

use CB\CRM\Content\Entity;
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
		echo '<input type="hidden" name="cb_crm_contact_methods_present" value="1">';
		RepeatableTable::start( 'contact-methods', count( $rows ), [ __( 'Type', 'core-blueprint-crm' ), __( 'Label', 'core-blueprint-crm' ), __( 'Value', 'core-blueprint-crm' ), __( 'Primary', 'core-blueprint-crm' ), __( 'Actions', 'core-blueprint-crm' ) ] );
		foreach ( $rows as $i => $row ) {
			self::row( $i, $row );
		}
		RepeatableTable::middle();
		?>
		<template><?php self::row( '__INDEX__', [] ); ?></template>
		<?php RepeatableTable::end( __( 'Add contact method', 'core-blueprint-crm' ) );
	}

	/** @param array<string,mixed> $row */
	private static function row( int|string $index, array $row ): void {
		$prefix = 'cb_crm_contact_methods[' . (string) $index . ']';
		?>
		<tr data-cb-crm-row>
			<td><select name="<?php echo esc_attr( $prefix . '[method_type]' ); ?>"><?php foreach ( ContactMethods::TYPES as $type ) : ?><option value="<?php echo esc_attr( $type ); ?>" <?php selected( (string) ( $row['method_type'] ?? 'email' ), $type ); ?>><?php echo esc_html( self::label( $type ) ); ?></option><?php endforeach; ?></select></td>
			<td><input type="text" name="<?php echo esc_attr( $prefix . '[label]' ); ?>" value="<?php echo esc_attr( (string) ( $row['label'] ?? '' ) ); ?>"></td>
			<td><input type="text" class="regular-text" name="<?php echo esc_attr( $prefix . '[value]' ); ?>" value="<?php echo esc_attr( (string) ( $row['value'] ?? '' ) ); ?>"></td>
			<td><label><input type="checkbox" name="<?php echo esc_attr( $prefix . '[is_primary]' ); ?>" value="1" <?php checked( ! empty( $row['is_primary'] ) ); ?>> <span class="screen-reader-text"><?php esc_html_e( 'Primary contact method', 'core-blueprint-crm' ); ?></span></label></td>
			<td><button type="button" class="button-link-delete" data-cb-crm-remove-row><?php esc_html_e( 'Remove', 'core-blueprint-crm' ); ?></button></td>
		</tr>
		<?php
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

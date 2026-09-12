<?php
declare(strict_types=1);

namespace CB\CRM\Admin\Panels;

use CB\CRM\Content\Entity;
use CB\CRM\Content\PostTypes;
use CB\CRM\PanelRegistry;
use CB\CRM\Repository\Organizations;

defined( 'ABSPATH' ) || exit;

final class OrganizationsPanel {
	public static function register(): void {
		PanelRegistry::register( [
			'id'         => 'organizations',
			'label'      => __( 'Organizations', 'core-blueprint-crm' ),
			'post_types' => [ Entity::CONTACT ],
			'render'     => [ \CB\CRM\Admin\Panels::class, 'organizations' ],
		] );
	}

	public static function render( string $owner_type, int $post_id ): void {
		unset( $owner_type );
		$rows    = Organizations::for_contact( $post_id );
		$options = get_posts( [ 'post_type' => PostTypes::ORGANIZATION, 'post_status' => [ 'publish', 'draft', 'private' ], 'numberposts' => -1, 'orderby' => 'title', 'order' => 'ASC' ] );
		echo '<input type="hidden" name="cb_crm_organizations_present" value="1">';
		RepeatableTable::start( 'organizations', count( $rows ), [ __( 'Organization', 'core-blueprint-crm' ), __( 'Role', 'core-blueprint-crm' ), __( 'From', 'core-blueprint-crm' ), __( 'Until', 'core-blueprint-crm' ), __( 'Primary', 'core-blueprint-crm' ), __( 'Actions', 'core-blueprint-crm' ) ] );
		foreach ( $rows as $i => $row ) {
			self::row( $i, $row, $options );
		}
		RepeatableTable::middle();
		?>
		<template><?php self::row( '__INDEX__', [], $options ); ?></template>
		<?php RepeatableTable::end( __( 'Add organization', 'core-blueprint-crm' ) );
	}

	/**
	 * @param array<string,mixed> $row
	 * @param \WP_Post[] $options
	 */
	private static function row( int|string $index, array $row, array $options ): void {
		$prefix = 'cb_crm_organizations[' . (string) $index . ']';
		?>
		<tr data-cb-crm-row>
			<td><select name="<?php echo esc_attr( $prefix . '[organization_id]' ); ?>"><option value="0">—</option><?php foreach ( $options as $org ) : ?><option value="<?php echo esc_attr( (string) $org->ID ); ?>" <?php selected( (int) ( $row['organization_id'] ?? 0 ), $org->ID ); ?>><?php echo esc_html( $org->post_title ); ?></option><?php endforeach; ?></select></td>
			<td><input type="text" name="<?php echo esc_attr( $prefix . '[role_title]' ); ?>" value="<?php echo esc_attr( (string) ( $row['role_title'] ?? '' ) ); ?>"></td>
			<td><input type="date" name="<?php echo esc_attr( $prefix . '[started_at]' ); ?>" value="<?php echo esc_attr( (string) ( $row['started_at'] ?? '' ) ); ?>"></td>
			<td><input type="date" name="<?php echo esc_attr( $prefix . '[ended_at]' ); ?>" value="<?php echo esc_attr( (string) ( $row['ended_at'] ?? '' ) ); ?>"></td>
			<td><label><input type="checkbox" name="<?php echo esc_attr( $prefix . '[is_primary]' ); ?>" value="1" <?php checked( ! empty( $row['is_primary'] ) ); ?>> <span class="screen-reader-text"><?php esc_html_e( 'Primary organization', 'core-blueprint-crm' ); ?></span></label></td>
			<td><button type="button" class="button-link-delete" data-cb-crm-remove-row><?php esc_html_e( 'Remove', 'core-blueprint-crm' ); ?></button></td>
		</tr>
		<?php
	}
}

<?php
declare(strict_types=1);

namespace CB\CRM\Admin\Panels;

use CB\CRM\Content\Entity;
use CB\CRM\PanelRegistry;
use CB\CRM\Repository\Names;

defined( 'ABSPATH' ) || exit;

final class NamesPanel {
	public static function register(): void {
		PanelRegistry::register( [
			'id'         => 'names',
			'label'      => __( 'Names & Aliases', 'core-blueprint-crm' ),
			'post_types' => [ Entity::CONTACT, Entity::ORGANIZATION ],
			'render'     => [ \CB\CRM\Admin\Panels::class, 'names' ],
		] );
	}

	public static function render( string $owner_type, int $post_id ): void {
		$rows = Names::for_owner( $owner_type, $post_id );
		echo '<input type="hidden" name="cb_crm_names_present" value="1">';
		RepeatableTable::start( 'names', count( $rows ), [ __( 'Name', 'core-blueprint-crm' ), __( 'Type', 'core-blueprint-crm' ), __( 'From', 'core-blueprint-crm' ), __( 'Until', 'core-blueprint-crm' ), __( 'Actions', 'core-blueprint-crm' ) ] );
		foreach ( $rows as $i => $row ) {
			self::row( $i, $row );
		}
		RepeatableTable::middle();
		?>
		<template><?php self::row( '__INDEX__', [] ); ?></template>
		<?php RepeatableTable::end( __( 'Add name or alias', 'core-blueprint-crm' ) );
	}

	/** @param array<string,mixed> $row */
	private static function row( int|string $index, array $row ): void {
		$prefix = 'cb_crm_names[' . (string) $index . ']';
		?>
		<tr data-cb-crm-row>
			<td><input type="text" class="regular-text" name="<?php echo esc_attr( $prefix . '[name]' ); ?>" value="<?php echo esc_attr( (string) ( $row['name'] ?? '' ) ); ?>"></td>
			<td><select name="<?php echo esc_attr( $prefix . '[name_type]' ); ?>"><?php foreach ( Names::TYPES as $type ) : ?><option value="<?php echo esc_attr( $type ); ?>" <?php selected( (string) ( $row['name_type'] ?? 'alias' ), $type ); ?>><?php echo esc_html( self::label( $type ) ); ?></option><?php endforeach; ?></select></td>
			<td><input type="date" name="<?php echo esc_attr( $prefix . '[started_at]' ); ?>" value="<?php echo esc_attr( (string) ( $row['started_at'] ?? '' ) ); ?>"></td>
			<td><input type="date" name="<?php echo esc_attr( $prefix . '[ended_at]' ); ?>" value="<?php echo esc_attr( (string) ( $row['ended_at'] ?? '' ) ); ?>"></td>
			<td><button type="button" class="button-link-delete" data-cb-crm-remove-row><?php esc_html_e( 'Remove', 'core-blueprint-crm' ); ?></button></td>
		</tr>
		<?php
	}

	private static function label( string $type ): string {
		return match ( $type ) {
			'legal'     => __( 'Legal', 'core-blueprint-crm' ),
			'preferred' => __( 'Preferred', 'core-blueprint-crm' ),
			'trading'   => __( 'Trading', 'core-blueprint-crm' ),
			'former'    => __( 'Former', 'core-blueprint-crm' ),
			default     => __( 'Alias', 'core-blueprint-crm' ),
		};
	}
}

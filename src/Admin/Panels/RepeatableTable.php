<?php
declare(strict_types=1);

namespace CB\CRM\Admin\Panels;

defined( 'ABSPATH' ) || exit;

final class RepeatableTable {
	/** @param string[] $labels */
	public static function start( string $id, int $next_index, array $labels ): void {
		echo '<div class="cb-crm-repeater" data-cb-crm-repeater data-next-index="' . esc_attr( (string) $next_index ) . '">';
		echo '<table class="widefat striped cb-crm-repeatable-table"><thead><tr>';
		foreach ( $labels as $label ) {
			echo '<th>' . esc_html( $label ) . '</th>';
		}
		echo '</tr></thead><tbody data-cb-crm-rows id="cb-crm-' . esc_attr( $id ) . '-rows">';
	}

	public static function middle(): void {
		echo '</tbody></table>';
	}

	public static function end( string $button_label ): void {
		echo '<div class="cb-crm-repeater-toolbar"><button type="button" class="button" data-cb-crm-add-row>' . esc_html( $button_label ) . '</button></div></div>';
	}
}

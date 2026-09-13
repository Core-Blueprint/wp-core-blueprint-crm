<?php
declare(strict_types=1);

namespace CB\CRM\Admin\Panels;

defined( 'ABSPATH' ) || exit;

final class SourceBadge {
	public static function render( string $source, string $path = '', string $value = '', bool $active = true ): void {
		$name = self::name( $source );
		$title = $name;
		if ( '' !== $path ) {
			$title .= ' · ' . $path;
		}
		if ( '' !== $value ) {
			$title .= ': ' . $value;
		}
		$classes = 'cb-crm-source-badge is-' . sanitize_html_class( $source ) . ( $active ? ' is-active' : ' is-reference' );
		echo '<span class="' . esc_attr( $classes ) . '" title="' . esc_attr( $title ) . '" aria-label="' . esc_attr( $title ) . '">';
		if ( 'wordpress' === $source ) {
			echo '<span class="dashicons dashicons-wordpress" aria-hidden="true"></span><span class="screen-reader-text">WordPress</span>';
		} elseif ( 'woocommerce' === $source ) {
			echo 'Woo';
		} else {
			echo 'CRM';
		}
		echo '</span>';
	}

	private static function name( string $source ): string {
		return match ( $source ) {
			'wordpress' => 'WordPress',
			'woocommerce' => 'WooCommerce',
			default => 'CRM',
		};
	}
}

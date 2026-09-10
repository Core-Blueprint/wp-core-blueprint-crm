<?php
declare(strict_types=1);

namespace CB\CRM\Admin;

use CB\CRM\Content\Entity;
use CB\CRM\PanelRegistry;
use CB\CRM\Repository\BusinessIdentifiers;

defined( 'ABSPATH' ) || exit;

final class BusinessIdentifiersPanel {
	public static function init(): void {
		add_action( 'cb_crm_register_panels', [ __CLASS__, 'register' ] );
	}

	public static function register(): void {
		PanelRegistry::register( [
			'id' => 'business-identifiers',
			'label' => __( 'Business Identifiers', 'core-blueprint-crm' ),
			'post_types' => [ Entity::ORGANIZATION ],
			'render' => [ __CLASS__, 'render' ],
			'context' => 'normal',
			'priority' => 'default',
		] );
	}

	public static function render( string $owner_type, int $organization_id ): void {
		if ( Entity::ORGANIZATION !== $owner_type ) {
			return;
		}
		$rows = BusinessIdentifiers::for_organization( $organization_id );
		echo '<input type="hidden" name="cb_crm_business_identifiers_present" value="1">';
		echo '<div class="cb-crm-repeater" data-cb-crm-repeater data-next-index="' . esc_attr( (string) count( $rows ) ) . '">';
		echo '<table class="widefat striped"><thead><tr>';
		foreach ( [ __( 'Type', 'core-blueprint-crm' ), __( 'Value', 'core-blueprint-crm' ), __( 'Country', 'core-blueprint-crm' ), __( 'Label', 'core-blueprint-crm' ), __( 'Primary', 'core-blueprint-crm' ), __( 'Actions', 'core-blueprint-crm' ) ] as $label ) {
			echo '<th>' . esc_html( $label ) . '</th>';
		}
		echo '</tr></thead><tbody data-cb-crm-rows>';
		foreach ( $rows as $index => $row ) {
			self::row( $index, $row );
		}
		echo '</tbody></table>';
		echo '<template>';
		self::row( '__INDEX__', [] );
		echo '</template>';
		echo '<div class="cb-crm-repeater-toolbar"><button type="button" class="button" data-cb-crm-add-row>' . esc_html__( 'Add business identifier', 'core-blueprint-crm' ) . '</button></div>';
		echo '<p class="description">' . esc_html__( 'Store legal and commercial identifiers for this organization. Country uses a two-letter country code; Other identifiers require a descriptive label.', 'core-blueprint-crm' ) . '</p>';
		echo '</div>';
	}

	/** @param array<string,mixed> $row */
	private static function row( int|string $index, array $row ): void {
		$prefix = 'cb_crm_business_identifiers[' . (string) $index . ']';
		$type = sanitize_key( (string) ( $row['identifier_type'] ?? 'vat' ) );
		?>
		<tr data-cb-crm-row>
			<td><select name="<?php echo esc_attr( $prefix . '[identifier_type]' ); ?>"><?php foreach ( BusinessIdentifiers::TYPES as $candidate ) : ?><option value="<?php echo esc_attr( $candidate ); ?>" <?php selected( $type, $candidate ); ?>><?php echo esc_html( self::type_label( $candidate ) ); ?></option><?php endforeach; ?></select></td>
			<td><input type="text" class="regular-text" maxlength="190" name="<?php echo esc_attr( $prefix . '[value]' ); ?>" value="<?php echo esc_attr( (string) ( $row['value'] ?? '' ) ); ?>"></td>
			<td><input type="text" size="4" maxlength="2" name="<?php echo esc_attr( $prefix . '[country]' ); ?>" value="<?php echo esc_attr( (string) ( $row['country'] ?? '' ) ); ?>" placeholder="NL"></td>
			<td><input type="text" maxlength="100" name="<?php echo esc_attr( $prefix . '[label]' ); ?>" value="<?php echo esc_attr( (string) ( $row['label'] ?? '' ) ); ?>"></td>
			<td><label><input type="checkbox" name="<?php echo esc_attr( $prefix . '[is_primary]' ); ?>" value="1" <?php checked( ! empty( $row['is_primary'] ) ); ?>> <span class="screen-reader-text"><?php esc_html_e( 'Primary identifier of this type', 'core-blueprint-crm' ); ?></span></label></td>
			<td><button type="button" class="button-link-delete" data-cb-crm-remove-row><?php esc_html_e( 'Remove', 'core-blueprint-crm' ); ?></button></td>
		</tr>
		<?php
	}

	private static function type_label( string $type ): string {
		return match ( $type ) {
			'vat' => __( 'VAT / tax registration', 'core-blueprint-crm' ),
			'registration_number' => __( 'Company registration', 'core-blueprint-crm' ),
			'eori' => __( 'EORI', 'core-blueprint-crm' ),
			default => __( 'Other', 'core-blueprint-crm' ),
		};
	}
}

<?php
declare(strict_types=1);

namespace CB\CRM\Admin;

use CB\CRM\Content\ContactIdentity;
use CB\CRM\Content\Entity;
use CB\CRM\Content\Meta;
use CB\CRM\Content\PostTypes;
use CB\CRM\PanelRegistry;
use CB\CRM\Repository\Activity;
use CB\CRM\Repository\Addresses;
use CB\CRM\Repository\ContactMethods;
use CB\CRM\Repository\Names;
use CB\CRM\Repository\Notes;
use CB\CRM\Repository\Organizations;
use CB\CRM\Repository\Services;

defined( 'ABSPATH' ) || exit;

final class Panels {
	public static function init(): void {
		add_action( 'cb_crm_register_panels', [ __CLASS__, 'register' ] );
	}

	public static function register(): void {
		PanelRegistry::register( [ 'id' => 'details', 'label' => __( 'CRM Details', 'core-blueprint-crm' ), 'post_types' => [ Entity::CONTACT, Entity::ORGANIZATION, Entity::SERVICE ], 'render' => [ __CLASS__, 'details' ], 'context' => 'normal', 'priority' => 'high' ] );
		PanelRegistry::register( [ 'id' => 'contact-methods', 'label' => __( 'Contact Methods', 'core-blueprint-crm' ), 'post_types' => [ Entity::CONTACT, Entity::ORGANIZATION ], 'render' => [ __CLASS__, 'contact_methods' ] ] );
		PanelRegistry::register( [ 'id' => 'addresses', 'label' => __( 'Addresses', 'core-blueprint-crm' ), 'post_types' => [ Entity::CONTACT, Entity::ORGANIZATION ], 'render' => [ __CLASS__, 'addresses' ] ] );
		PanelRegistry::register( [ 'id' => 'names', 'label' => __( 'Names & Aliases', 'core-blueprint-crm' ), 'post_types' => [ Entity::CONTACT, Entity::ORGANIZATION, Entity::SERVICE ], 'render' => [ __CLASS__, 'names' ] ] );
		PanelRegistry::register( [ 'id' => 'organizations', 'label' => __( 'Organizations', 'core-blueprint-crm' ), 'post_types' => [ Entity::CONTACT ], 'render' => [ __CLASS__, 'organizations' ] ] );
		PanelRegistry::register( [ 'id' => 'services', 'label' => __( 'Services', 'core-blueprint-crm' ), 'post_types' => [ Entity::CONTACT, Entity::ORGANIZATION ], 'render' => [ __CLASS__, 'services' ] ] );
		PanelRegistry::register( [ 'id' => 'notes-activity', 'label' => __( 'Notes & Activity', 'core-blueprint-crm' ), 'post_types' => [ Entity::CONTACT, Entity::ORGANIZATION, Entity::SERVICE ], 'render' => [ __CLASS__, 'notes_activity' ] ] );
	}

	public static function details( string $owner_type, int $post_id ): void {
		wp_nonce_field( 'cb_crm_save_record', 'cb_crm_nonce' );
		$status = (string) get_post_meta( $post_id, Meta::STATUS, true );
		?>
		<table class="form-table" role="presentation"><tbody>
		<tr><th><label for="cb-crm-status"><?php esc_html_e( 'Status', 'core-blueprint-crm' ); ?></label></th><td><input class="regular-text" id="cb-crm-status" name="cb_crm_details[status]" value="<?php echo esc_attr( $status ); ?>" placeholder="active"></td></tr>
		<?php if ( Entity::CONTACT === $owner_type ) :
			$linked = ContactIdentity::linked_user_id( $post_id ); ?>
		<tr><th><label for="cb-crm-first-name"><?php esc_html_e( 'First name', 'core-blueprint-crm' ); ?></label></th><td><input class="regular-text" id="cb-crm-first-name" name="cb_crm_details[first_name]" value="<?php echo esc_attr( (string) get_post_meta( $post_id, Meta::FIRST_NAME, true ) ); ?>"></td></tr>
		<tr><th><label for="cb-crm-last-name"><?php esc_html_e( 'Last name', 'core-blueprint-crm' ); ?></label></th><td><input class="regular-text" id="cb-crm-last-name" name="cb_crm_details[last_name]" value="<?php echo esc_attr( (string) get_post_meta( $post_id, Meta::LAST_NAME, true ) ); ?>"></td></tr>
		<tr><th><label for="cb-crm-job-title"><?php esc_html_e( 'Job title', 'core-blueprint-crm' ); ?></label></th><td><input class="regular-text" id="cb-crm-job-title" name="cb_crm_details[job_title]" value="<?php echo esc_attr( (string) get_post_meta( $post_id, Meta::JOB_TITLE, true ) ); ?>"></td></tr>
		<tr><th><label for="cb-crm-user"><?php esc_html_e( 'WordPress user', 'core-blueprint-crm' ); ?></label></th><td><input type="number" min="0" id="cb-crm-user" name="cb_crm_details[wp_user_id]" value="<?php echo esc_attr( (string) $linked ); ?>"> <span class="description"><?php echo $linked > 0 ? esc_html( (string) ( get_userdata( $linked )->display_name ?? '' ) ) : esc_html__( 'Optional. Enter a WordPress user ID.', 'core-blueprint-crm' ); ?></span></td></tr>
		<tr><th><?php esc_html_e( 'Preferred email source', 'core-blueprint-crm' ); ?></th><td><select name="cb_crm_details[email_mode]"><option value="crm" <?php selected( (string) get_post_meta( $post_id, Meta::EMAIL_MODE, true ), 'crm' ); ?>><?php esc_html_e( 'CRM contact methods', 'core-blueprint-crm' ); ?></option><option value="wp_user" <?php selected( (string) get_post_meta( $post_id, Meta::EMAIL_MODE, true ), 'wp_user' ); ?>><?php esc_html_e( 'Linked WordPress account', 'core-blueprint-crm' ); ?></option></select></td></tr>
		<?php elseif ( Entity::ORGANIZATION === $owner_type ) : ?>
		<tr><th><label for="cb-crm-legal-name"><?php esc_html_e( 'Legal name', 'core-blueprint-crm' ); ?></label></th><td><input class="regular-text" id="cb-crm-legal-name" name="cb_crm_details[legal_name]" value="<?php echo esc_attr( (string) get_post_meta( $post_id, Meta::LEGAL_NAME, true ) ); ?>"></td></tr>
		<?php endif; ?>
		</tbody></table>
		<?php
	}

	public static function contact_methods( string $owner_type, int $post_id ): void {
		$rows = ContactMethods::for_owner( $owner_type, $post_id );
		self::repeatable_head( [ __( 'Type', 'core-blueprint-crm' ), __( 'Label', 'core-blueprint-crm' ), __( 'Value', 'core-blueprint-crm' ), __( 'Primary', 'core-blueprint-crm' ) ] );
		foreach ( array_merge( $rows, [ [], [] ] ) as $i => $row ) {
			echo '<tr><td><select name="cb_crm_contact_methods[' . esc_attr( (string) $i ) . '][method_type]">';
			foreach ( ContactMethods::TYPES as $type ) { echo '<option value="' . esc_attr( $type ) . '" ' . selected( (string) ( $row['method_type'] ?? '' ), $type, false ) . '>' . esc_html( ucfirst( $type ) ) . '</option>'; }
			echo '</select></td><td><input name="cb_crm_contact_methods[' . esc_attr( (string) $i ) . '][label]" value="' . esc_attr( (string) ( $row['label'] ?? '' ) ) . '"></td><td><input class="regular-text" name="cb_crm_contact_methods[' . esc_attr( (string) $i ) . '][value]" value="' . esc_attr( (string) ( $row['value'] ?? '' ) ) . '"></td><td><input type="checkbox" name="cb_crm_contact_methods[' . esc_attr( (string) $i ) . '][is_primary]" value="1" ' . checked( ! empty( $row['is_primary'] ), true, false ) . '></td></tr>';
		}
		self::repeatable_foot();
	}

	public static function addresses( string $owner_type, int $post_id ): void {
		$rows = Addresses::for_owner( $owner_type, $post_id );
		self::repeatable_head( [ __( 'Label', 'core-blueprint-crm' ), __( 'Address', 'core-blueprint-crm' ), __( 'Postal / City', 'core-blueprint-crm' ), __( 'Country', 'core-blueprint-crm' ), __( 'Primary', 'core-blueprint-crm' ) ] );
		foreach ( array_merge( $rows, [ [] ] ) as $i => $row ) {
			echo '<tr><td><input name="cb_crm_addresses[' . esc_attr( (string) $i ) . '][label]" value="' . esc_attr( (string) ( $row['label'] ?? '' ) ) . '"></td><td><input name="cb_crm_addresses[' . esc_attr( (string) $i ) . '][address_line_1]" value="' . esc_attr( (string) ( $row['address_line_1'] ?? '' ) ) . '" placeholder="' . esc_attr__( 'Address line 1', 'core-blueprint-crm' ) . '"><br><input name="cb_crm_addresses[' . esc_attr( (string) $i ) . '][address_line_2]" value="' . esc_attr( (string) ( $row['address_line_2'] ?? '' ) ) . '" placeholder="' . esc_attr__( 'Address line 2', 'core-blueprint-crm' ) . '"></td><td><input size="10" name="cb_crm_addresses[' . esc_attr( (string) $i ) . '][postal_code]" value="' . esc_attr( (string) ( $row['postal_code'] ?? '' ) ) . '"> <input name="cb_crm_addresses[' . esc_attr( (string) $i ) . '][city]" value="' . esc_attr( (string) ( $row['city'] ?? '' ) ) . '"><br><input name="cb_crm_addresses[' . esc_attr( (string) $i ) . '][region]" value="' . esc_attr( (string) ( $row['region'] ?? '' ) ) . '" placeholder="' . esc_attr__( 'Region', 'core-blueprint-crm' ) . '"></td><td><input size="4" maxlength="2" name="cb_crm_addresses[' . esc_attr( (string) $i ) . '][country]" value="' . esc_attr( (string) ( $row['country'] ?? '' ) ) . '"></td><td><input type="checkbox" name="cb_crm_addresses[' . esc_attr( (string) $i ) . '][is_primary]" value="1" ' . checked( ! empty( $row['is_primary'] ), true, false ) . '></td></tr>';
		}
		self::repeatable_foot();
	}

	public static function names( string $owner_type, int $post_id ): void {
		$rows = Names::for_owner( $owner_type, $post_id );
		self::repeatable_head( [ __( 'Name', 'core-blueprint-crm' ), __( 'Type', 'core-blueprint-crm' ), __( 'From', 'core-blueprint-crm' ), __( 'Until', 'core-blueprint-crm' ) ] );
		foreach ( array_merge( $rows, [ [] ] ) as $i => $row ) {
			echo '<tr><td><input class="regular-text" name="cb_crm_names[' . esc_attr( (string) $i ) . '][name]" value="' . esc_attr( (string) ( $row['name'] ?? '' ) ) . '"></td><td><select name="cb_crm_names[' . esc_attr( (string) $i ) . '][name_type]">';
			foreach ( Names::TYPES as $type ) { echo '<option value="' . esc_attr( $type ) . '" ' . selected( (string) ( $row['name_type'] ?? 'alias' ), $type, false ) . '>' . esc_html( ucfirst( $type ) ) . '</option>'; }
			echo '</select></td><td><input type="date" name="cb_crm_names[' . esc_attr( (string) $i ) . '][started_at]" value="' . esc_attr( (string) ( $row['started_at'] ?? '' ) ) . '"></td><td><input type="date" name="cb_crm_names[' . esc_attr( (string) $i ) . '][ended_at]" value="' . esc_attr( (string) ( $row['ended_at'] ?? '' ) ) . '"></td></tr>';
		}
		self::repeatable_foot();
	}

	public static function organizations( string $owner_type, int $post_id ): void {
		unset( $owner_type );
		$rows = Organizations::for_contact( $post_id );
		$options = get_posts( [ 'post_type' => PostTypes::ORGANIZATION, 'post_status' => [ 'publish', 'draft', 'private' ], 'numberposts' => -1, 'orderby' => 'title', 'order' => 'ASC' ] );
		self::repeatable_head( [ __( 'Organization', 'core-blueprint-crm' ), __( 'Role', 'core-blueprint-crm' ), __( 'From', 'core-blueprint-crm' ), __( 'Until', 'core-blueprint-crm' ), __( 'Primary', 'core-blueprint-crm' ) ] );
		foreach ( array_merge( $rows, [ [] ] ) as $i => $row ) {
			echo '<tr><td><select name="cb_crm_organizations[' . esc_attr( (string) $i ) . '][organization_id]"><option value="0">—</option>';
			foreach ( $options as $org ) { echo '<option value="' . esc_attr( (string) $org->ID ) . '" ' . selected( (int) ( $row['organization_id'] ?? 0 ), $org->ID, false ) . '>' . esc_html( $org->post_title ) . '</option>'; }
			echo '</select></td><td><input name="cb_crm_organizations[' . esc_attr( (string) $i ) . '][role_title]" value="' . esc_attr( (string) ( $row['role_title'] ?? '' ) ) . '"></td><td><input type="date" name="cb_crm_organizations[' . esc_attr( (string) $i ) . '][started_at]" value="' . esc_attr( (string) ( $row['started_at'] ?? '' ) ) . '"></td><td><input type="date" name="cb_crm_organizations[' . esc_attr( (string) $i ) . '][ended_at]" value="' . esc_attr( (string) ( $row['ended_at'] ?? '' ) ) . '"></td><td><input type="checkbox" name="cb_crm_organizations[' . esc_attr( (string) $i ) . '][is_primary]" value="1" ' . checked( ! empty( $row['is_primary'] ), true, false ) . '></td></tr>';
		}
		self::repeatable_foot();
	}

	public static function services( string $owner_type, int $post_id ): void {
		$rows = Services::for_owner( $owner_type, $post_id );
		$options = get_posts( [ 'post_type' => PostTypes::SERVICE, 'post_status' => [ 'publish', 'draft', 'private' ], 'numberposts' => -1, 'orderby' => 'title', 'order' => 'ASC' ] );
		self::repeatable_head( [ __( 'Service', 'core-blueprint-crm' ), __( 'Status', 'core-blueprint-crm' ), __( 'From', 'core-blueprint-crm' ), __( 'Until', 'core-blueprint-crm' ), __( 'Context', 'core-blueprint-crm' ) ] );
		foreach ( array_merge( $rows, [ [] ] ) as $i => $row ) {
			echo '<tr><td><select name="cb_crm_services[' . esc_attr( (string) $i ) . '][service_id]"><option value="0">—</option>';
			foreach ( $options as $service ) { echo '<option value="' . esc_attr( (string) $service->ID ) . '" ' . selected( (int) ( $row['service_id'] ?? 0 ), $service->ID, false ) . '>' . esc_html( $service->post_title ) . '</option>'; }
			echo '</select></td><td><select name="cb_crm_services[' . esc_attr( (string) $i ) . '][status]">';
			foreach ( Services::STATUSES as $status ) { echo '<option value="' . esc_attr( $status ) . '" ' . selected( (string) ( $row['status'] ?? 'active' ), $status, false ) . '>' . esc_html( ucfirst( $status ) ) . '</option>'; }
			echo '</select></td><td><input type="date" name="cb_crm_services[' . esc_attr( (string) $i ) . '][started_at]" value="' . esc_attr( (string) ( $row['started_at'] ?? '' ) ) . '"></td><td><input type="date" name="cb_crm_services[' . esc_attr( (string) $i ) . '][ended_at]" value="' . esc_attr( (string) ( $row['ended_at'] ?? '' ) ) . '"></td><td><input name="cb_crm_services[' . esc_attr( (string) $i ) . '][notes]" value="' . esc_attr( (string) ( $row['notes'] ?? '' ) ) . '"></td></tr>';
		}
		self::repeatable_foot();
	}

	public static function notes_activity( string $owner_type, int $post_id ): void {
		$notes = Notes::for_owner( $owner_type, $post_id, 20 );
		$activity = Activity::for_owner( $owner_type, $post_id, 30 );
		echo '<p><label for="cb-crm-new-note"><strong>' . esc_html__( 'Add note', 'core-blueprint-crm' ) . '</strong></label></p><textarea class="widefat" rows="3" id="cb-crm-new-note" name="cb_crm_new_note"></textarea>';
		echo '<h3>' . esc_html__( 'Recent notes', 'core-blueprint-crm' ) . '</h3>';
		if ( ! $notes ) { echo '<p class="description">' . esc_html__( 'No notes yet.', 'core-blueprint-crm' ) . '</p>'; }
		foreach ( $notes as $note ) { $author = get_userdata( (int) $note['author_user_id'] ); echo '<div style="border-top:1px solid #dcdcde;padding:10px 0;"><p style="margin:0 0 6px;">' . nl2br( esc_html( (string) $note['body'] ) ) . '</p><small>' . esc_html( $author ? $author->display_name : __( 'System', 'core-blueprint-crm' ) ) . ' · ' . esc_html( (string) $note['created_at'] ) . '</small></div>'; }
		echo '<h3>' . esc_html__( 'Activity', 'core-blueprint-crm' ) . '</h3>';
		if ( ! $activity ) { echo '<p class="description">' . esc_html__( 'No activity yet.', 'core-blueprint-crm' ) . '</p>'; }
		foreach ( $activity as $event ) { echo '<div style="border-top:1px solid #dcdcde;padding:8px 0;"><strong>' . esc_html( (string) $event['summary'] ) . '</strong><br><small>' . esc_html( (string) $event['source'] ) . ' · ' . esc_html( (string) $event['created_at'] ) . '</small></div>'; }
	}

	/** @param string[] $labels */
	private static function repeatable_head( array $labels ): void {
		echo '<p class="description">' . esc_html__( 'Existing rows are saved together. Use the empty row to add another item.', 'core-blueprint-crm' ) . '</p><table class="widefat striped"><thead><tr>';
		foreach ( $labels as $label ) { echo '<th>' . esc_html( $label ) . '</th>'; }
		echo '</tr></thead><tbody>';
	}

	private static function repeatable_foot(): void {
		echo '</tbody></table>';
	}
}

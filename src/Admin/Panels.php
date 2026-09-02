<?php
declare(strict_types=1);

namespace CB\CRM\Admin;

use CB\CRM\Content\ContactIdentity;
use CB\CRM\Content\Entity;
use CB\CRM\Content\Meta;
use CB\CRM\Content\PostTypes;
use CB\CRM\Content\RecordStatus;
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
		$status = RecordStatus::normalize( (string) get_post_meta( $post_id, Meta::STATUS, true ) );
		?>
		<table class="form-table" role="presentation"><tbody>
		<tr>
			<th><label for="cb-crm-status"><?php esc_html_e( 'Status', 'core-blueprint-crm' ); ?></label></th>
			<td>
				<select id="cb-crm-status" name="cb_crm_details[status]">
					<?php foreach ( RecordStatus::labels() as $value => $label ) : ?>
						<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $status, $value ); ?>><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
				</select>
			</td>
		</tr>
		<?php if ( Entity::CONTACT === $owner_type ) :
			$linked      = ContactIdentity::linked_user_id( $post_id );
			$linked_user = $linked > 0 ? get_userdata( $linked ) : false;
			if ( ! ( $linked_user instanceof \WP_User ) ) {
				$linked = 0;
			}
			$email_mode = sanitize_key( (string) get_post_meta( $post_id, Meta::EMAIL_MODE, true ) );
			if ( ! in_array( $email_mode, [ ContactIdentity::EMAIL_CRM, ContactIdentity::EMAIL_WP ], true ) || ( ContactIdentity::EMAIL_WP === $email_mode && 0 === $linked ) ) {
				$email_mode = ContactIdentity::EMAIL_CRM;
			}
			?>
		<tr><th><label for="cb-crm-first-name"><?php esc_html_e( 'First name', 'core-blueprint-crm' ); ?></label></th><td><input type="text" class="regular-text" id="cb-crm-first-name" name="cb_crm_details[first_name]" value="<?php echo esc_attr( (string) get_post_meta( $post_id, Meta::FIRST_NAME, true ) ); ?>"></td></tr>
		<tr><th><label for="cb-crm-last-name"><?php esc_html_e( 'Last name', 'core-blueprint-crm' ); ?></label></th><td><input type="text" class="regular-text" id="cb-crm-last-name" name="cb_crm_details[last_name]" value="<?php echo esc_attr( (string) get_post_meta( $post_id, Meta::LAST_NAME, true ) ); ?>"></td></tr>
		<tr><th><label for="cb-crm-job-title"><?php esc_html_e( 'Job title', 'core-blueprint-crm' ); ?></label></th><td><input type="text" class="regular-text" id="cb-crm-job-title" name="cb_crm_details[job_title]" value="<?php echo esc_attr( (string) get_post_meta( $post_id, Meta::JOB_TITLE, true ) ); ?>"></td></tr>
		<tr>
			<th><label for="cb-crm-user-search"><?php esc_html_e( 'WordPress account', 'core-blueprint-crm' ); ?></label></th>
			<td>
				<div class="cb-crm-user-picker" data-cb-crm-user-picker>
					<input type="hidden" name="cb_crm_details[wp_user_id]" value="<?php echo esc_attr( (string) $linked ); ?>" data-cb-crm-user-id>
					<div class="cb-crm-user-selected" data-cb-crm-user-selected <?php echo 0 === $linked ? 'hidden' : ''; ?>>
						<div class="cb-crm-user-selected-copy">
							<strong data-cb-crm-user-selected-name><?php echo esc_html( $linked_user instanceof \WP_User ? $linked_user->display_name : '' ); ?></strong>
							<span data-cb-crm-user-selected-email><?php echo esc_html( $linked_user instanceof \WP_User ? $linked_user->user_email : '' ); ?></span>
						</div>
						<button type="button" class="button-link-delete" data-cb-crm-user-remove><?php esc_html_e( 'Remove', 'core-blueprint-crm' ); ?></button>
					</div>
					<input type="search" class="regular-text" id="cb-crm-user-search" autocomplete="off" placeholder="<?php echo esc_attr__( 'Search by name or email…', 'core-blueprint-crm' ); ?>" data-cb-crm-user-search>
					<div class="cb-crm-user-results" role="listbox" hidden data-cb-crm-user-results></div>
				</div>
				<p class="description"><?php esc_html_e( 'Optional. Link this CRM contact to an existing WordPress account.', 'core-blueprint-crm' ); ?></p>
			</td>
		</tr>
		<tr>
			<th><label for="cb-crm-email-mode"><?php esc_html_e( 'Preferred email source', 'core-blueprint-crm' ); ?></label></th>
			<td>
				<select id="cb-crm-email-mode" name="cb_crm_details[email_mode]" data-cb-crm-email-mode>
					<option value="crm" <?php selected( $email_mode, ContactIdentity::EMAIL_CRM ); ?>><?php esc_html_e( 'CRM contact methods', 'core-blueprint-crm' ); ?></option>
					<option value="wp_user" <?php selected( $email_mode, ContactIdentity::EMAIL_WP ); ?> <?php disabled( 0 === $linked ); ?>><?php esc_html_e( 'Linked WordPress account', 'core-blueprint-crm' ); ?></option>
				</select>
				<p class="description"><?php esc_html_e( 'Choose which email address CRM should prefer. WordPress account linking itself remains optional.', 'core-blueprint-crm' ); ?></p>
			</td>
		</tr>
		<?php elseif ( Entity::ORGANIZATION === $owner_type ) : ?>
		<tr><th><label for="cb-crm-legal-name"><?php esc_html_e( 'Legal name', 'core-blueprint-crm' ); ?></label></th><td><input type="text" class="regular-text" id="cb-crm-legal-name" name="cb_crm_details[legal_name]" value="<?php echo esc_attr( (string) get_post_meta( $post_id, Meta::LEGAL_NAME, true ) ); ?>"></td></tr>
		<?php endif; ?>
		</tbody></table>
		<?php
	}

	public static function contact_methods( string $owner_type, int $post_id ): void {
		$rows = ContactMethods::for_owner( $owner_type, $post_id );
		echo '<input type="hidden" name="cb_crm_contact_methods_present" value="1">';
		self::repeatable_table_start( 'contact-methods', count( $rows ), [ __( 'Type', 'core-blueprint-crm' ), __( 'Label', 'core-blueprint-crm' ), __( 'Value', 'core-blueprint-crm' ), __( 'Primary', 'core-blueprint-crm' ), __( 'Actions', 'core-blueprint-crm' ) ] );
		foreach ( $rows as $i => $row ) {
			self::contact_method_row( $i, $row );
		}
		self::repeatable_table_middle();
		?>
		<template><?php self::contact_method_row( '__INDEX__', [] ); ?></template>
		<?php self::repeatable_table_end( __( 'Add contact method', 'core-blueprint-crm' ) );
	}

	public static function addresses( string $owner_type, int $post_id ): void {
		$rows = Addresses::for_owner( $owner_type, $post_id );
		?>
		<input type="hidden" name="cb_crm_addresses_present" value="1">
		<div class="cb-crm-repeater" data-cb-crm-repeater data-next-index="<?php echo esc_attr( (string) count( $rows ) ); ?>">
			<div class="cb-crm-address-list" data-cb-crm-rows>
				<?php foreach ( $rows as $i => $row ) { self::address_row( $i, $row ); } ?>
			</div>
			<template><?php self::address_row( '__INDEX__', [] ); ?></template>
			<div class="cb-crm-repeater-toolbar"><button type="button" class="button" data-cb-crm-add-row><?php esc_html_e( 'Add address', 'core-blueprint-crm' ); ?></button></div>
		</div>
		<?php
	}

	public static function names( string $owner_type, int $post_id ): void {
		$rows = Names::for_owner( $owner_type, $post_id );
		echo '<input type="hidden" name="cb_crm_names_present" value="1">';
		self::repeatable_table_start( 'names', count( $rows ), [ __( 'Name', 'core-blueprint-crm' ), __( 'Type', 'core-blueprint-crm' ), __( 'From', 'core-blueprint-crm' ), __( 'Until', 'core-blueprint-crm' ), __( 'Actions', 'core-blueprint-crm' ) ] );
		foreach ( $rows as $i => $row ) {
			self::name_row( $i, $row );
		}
		self::repeatable_table_middle();
		?>
		<template><?php self::name_row( '__INDEX__', [] ); ?></template>
		<?php self::repeatable_table_end( __( 'Add name or alias', 'core-blueprint-crm' ) );
	}

	public static function organizations( string $owner_type, int $post_id ): void {
		unset( $owner_type );
		$rows    = Organizations::for_contact( $post_id );
		$options = get_posts( [ 'post_type' => PostTypes::ORGANIZATION, 'post_status' => [ 'publish', 'draft', 'private' ], 'numberposts' => -1, 'orderby' => 'title', 'order' => 'ASC' ] );
		echo '<input type="hidden" name="cb_crm_organizations_present" value="1">';
		self::repeatable_table_start( 'organizations', count( $rows ), [ __( 'Organization', 'core-blueprint-crm' ), __( 'Role', 'core-blueprint-crm' ), __( 'From', 'core-blueprint-crm' ), __( 'Until', 'core-blueprint-crm' ), __( 'Primary', 'core-blueprint-crm' ), __( 'Actions', 'core-blueprint-crm' ) ] );
		foreach ( $rows as $i => $row ) {
			self::organization_row( $i, $row, $options );
		}
		self::repeatable_table_middle();
		?>
		<template><?php self::organization_row( '__INDEX__', [], $options ); ?></template>
		<?php self::repeatable_table_end( __( 'Add organization', 'core-blueprint-crm' ) );
	}

	public static function services( string $owner_type, int $post_id ): void {
		$rows    = Services::for_owner( $owner_type, $post_id );
		$options = get_posts( [ 'post_type' => PostTypes::SERVICE, 'post_status' => [ 'publish', 'draft', 'private' ], 'numberposts' => -1, 'orderby' => 'title', 'order' => 'ASC' ] );
		echo '<input type="hidden" name="cb_crm_services_present" value="1">';
		self::repeatable_table_start( 'services', count( $rows ), [ __( 'Service', 'core-blueprint-crm' ), __( 'Status', 'core-blueprint-crm' ), __( 'From', 'core-blueprint-crm' ), __( 'Until', 'core-blueprint-crm' ), __( 'Context', 'core-blueprint-crm' ), __( 'Actions', 'core-blueprint-crm' ) ] );
		foreach ( $rows as $i => $row ) {
			self::service_row( $i, $row, $options );
		}
		self::repeatable_table_middle();
		?>
		<template><?php self::service_row( '__INDEX__', [], $options ); ?></template>
		<?php self::repeatable_table_end( __( 'Add service', 'core-blueprint-crm' ) );
	}

	public static function notes_activity( string $owner_type, int $post_id ): void {
		$notes    = Notes::for_owner( $owner_type, $post_id, 20 );
		$activity = Activity::for_owner( $owner_type, $post_id, 30 );
		echo '<p><label for="cb-crm-new-note"><strong>' . esc_html__( 'Add note', 'core-blueprint-crm' ) . '</strong></label></p><textarea class="widefat" rows="3" id="cb-crm-new-note" name="cb_crm_new_note"></textarea>';
		echo '<h3>' . esc_html__( 'Recent notes', 'core-blueprint-crm' ) . '</h3>';
		if ( ! $notes ) {
			echo '<p class="description">' . esc_html__( 'No notes yet.', 'core-blueprint-crm' ) . '</p>';
		}
		foreach ( $notes as $note ) {
			$author = get_userdata( (int) $note['author_user_id'] );
			echo '<div style="border-top:1px solid #dcdcde;padding:10px 0;"><p style="margin:0 0 6px;">' . nl2br( esc_html( (string) $note['body'] ) ) . '</p><small>' . esc_html( $author ? $author->display_name : __( 'System', 'core-blueprint-crm' ) ) . ' · ' . esc_html( (string) $note['created_at'] ) . '</small></div>';
		}
		echo '<h3>' . esc_html__( 'Activity', 'core-blueprint-crm' ) . '</h3>';
		if ( ! $activity ) {
			echo '<p class="description">' . esc_html__( 'No activity yet.', 'core-blueprint-crm' ) . '</p>';
		}
		foreach ( $activity as $event ) {
			echo '<div style="border-top:1px solid #dcdcde;padding:8px 0;"><strong>' . esc_html( (string) $event['summary'] ) . '</strong><br><small>' . esc_html( (string) $event['source'] ) . ' · ' . esc_html( (string) $event['created_at'] ) . '</small></div>';
		}
	}

	/** @param string[] $labels */
	private static function repeatable_table_start( string $id, int $next_index, array $labels ): void {
		echo '<div class="cb-crm-repeater" data-cb-crm-repeater data-next-index="' . esc_attr( (string) $next_index ) . '">';
		echo '<table class="widefat striped cb-crm-repeatable-table"><thead><tr>';
		foreach ( $labels as $label ) {
			echo '<th>' . esc_html( $label ) . '</th>';
		}
		echo '</tr></thead><tbody data-cb-crm-rows id="cb-crm-' . esc_attr( $id ) . '-rows">';
	}

	private static function repeatable_table_middle(): void {
		echo '</tbody></table>';
	}

	private static function repeatable_table_end( string $button_label ): void {
		echo '<div class="cb-crm-repeater-toolbar"><button type="button" class="button" data-cb-crm-add-row>' . esc_html( $button_label ) . '</button></div></div>';
	}

	/** @param array<string,mixed> $row */
	private static function contact_method_row( int|string $index, array $row ): void {
		$prefix = 'cb_crm_contact_methods[' . (string) $index . ']';
		?>
		<tr data-cb-crm-row>
			<td><select name="<?php echo esc_attr( $prefix . '[method_type]' ); ?>"><?php foreach ( ContactMethods::TYPES as $type ) : ?><option value="<?php echo esc_attr( $type ); ?>" <?php selected( (string) ( $row['method_type'] ?? 'email' ), $type ); ?>><?php echo esc_html( self::contact_method_label( $type ) ); ?></option><?php endforeach; ?></select></td>
			<td><input type="text" name="<?php echo esc_attr( $prefix . '[label]' ); ?>" value="<?php echo esc_attr( (string) ( $row['label'] ?? '' ) ); ?>"></td>
			<td><input type="text" class="regular-text" name="<?php echo esc_attr( $prefix . '[value]' ); ?>" value="<?php echo esc_attr( (string) ( $row['value'] ?? '' ) ); ?>"></td>
			<td><label><input type="checkbox" name="<?php echo esc_attr( $prefix . '[is_primary]' ); ?>" value="1" <?php checked( ! empty( $row['is_primary'] ) ); ?>> <span class="screen-reader-text"><?php esc_html_e( 'Primary contact method', 'core-blueprint-crm' ); ?></span></label></td>
			<td><button type="button" class="button-link-delete" data-cb-crm-remove-row><?php esc_html_e( 'Remove', 'core-blueprint-crm' ); ?></button></td>
		</tr>
		<?php
	}

	/** @param array<string,mixed> $row */
	private static function address_row( int|string $index, array $row ): void {
		$prefix = 'cb_crm_addresses[' . (string) $index . ']';
		?>
		<div class="cb-crm-address-card" data-cb-crm-row>
			<div class="cb-crm-address-grid">
				<div class="cb-crm-address-field"><label><?php esc_html_e( 'Label', 'core-blueprint-crm' ); ?><input type="text" name="<?php echo esc_attr( $prefix . '[label]' ); ?>" value="<?php echo esc_attr( (string) ( $row['label'] ?? '' ) ); ?>" placeholder="<?php echo esc_attr__( 'e.g. Work or Home', 'core-blueprint-crm' ); ?>"></label></div>
				<div class="cb-crm-address-field is-wide"><label><?php esc_html_e( 'Address line 1', 'core-blueprint-crm' ); ?><input type="text" name="<?php echo esc_attr( $prefix . '[address_line_1]' ); ?>" value="<?php echo esc_attr( (string) ( $row['address_line_1'] ?? '' ) ); ?>"></label></div>
				<div class="cb-crm-address-field is-wide"><label><?php esc_html_e( 'Address line 2', 'core-blueprint-crm' ); ?><input type="text" name="<?php echo esc_attr( $prefix . '[address_line_2]' ); ?>" value="<?php echo esc_attr( (string) ( $row['address_line_2'] ?? '' ) ); ?>"></label></div>
				<div class="cb-crm-address-field is-small"><label><?php esc_html_e( 'Postal code', 'core-blueprint-crm' ); ?><input type="text" name="<?php echo esc_attr( $prefix . '[postal_code]' ); ?>" value="<?php echo esc_attr( (string) ( $row['postal_code'] ?? '' ) ); ?>"></label></div>
				<div class="cb-crm-address-field"><label><?php esc_html_e( 'City', 'core-blueprint-crm' ); ?><input type="text" name="<?php echo esc_attr( $prefix . '[city]' ); ?>" value="<?php echo esc_attr( (string) ( $row['city'] ?? '' ) ); ?>"></label></div>
				<div class="cb-crm-address-field"><label><?php esc_html_e( 'Region', 'core-blueprint-crm' ); ?><input type="text" name="<?php echo esc_attr( $prefix . '[region]' ); ?>" value="<?php echo esc_attr( (string) ( $row['region'] ?? '' ) ); ?>"></label></div>
				<div class="cb-crm-address-field is-small"><label><?php esc_html_e( 'Country code', 'core-blueprint-crm' ); ?><input type="text" maxlength="2" name="<?php echo esc_attr( $prefix . '[country]' ); ?>" value="<?php echo esc_attr( (string) ( $row['country'] ?? '' ) ); ?>" placeholder="NL"></label></div>
			</div>
			<div class="cb-crm-address-actions">
				<label><input type="checkbox" name="<?php echo esc_attr( $prefix . '[is_primary]' ); ?>" value="1" <?php checked( ! empty( $row['is_primary'] ) ); ?>> <?php esc_html_e( 'Primary address', 'core-blueprint-crm' ); ?></label>
				<button type="button" class="button-link-delete" data-cb-crm-remove-row><?php esc_html_e( 'Remove address', 'core-blueprint-crm' ); ?></button>
			</div>
		</div>
		<?php
	}

	/** @param array<string,mixed> $row */
	private static function name_row( int|string $index, array $row ): void {
		$prefix = 'cb_crm_names[' . (string) $index . ']';
		?>
		<tr data-cb-crm-row>
			<td><input type="text" class="regular-text" name="<?php echo esc_attr( $prefix . '[name]' ); ?>" value="<?php echo esc_attr( (string) ( $row['name'] ?? '' ) ); ?>"></td>
			<td><select name="<?php echo esc_attr( $prefix . '[name_type]' ); ?>"><?php foreach ( Names::TYPES as $type ) : ?><option value="<?php echo esc_attr( $type ); ?>" <?php selected( (string) ( $row['name_type'] ?? 'alias' ), $type ); ?>><?php echo esc_html( self::name_type_label( $type ) ); ?></option><?php endforeach; ?></select></td>
			<td><input type="date" name="<?php echo esc_attr( $prefix . '[started_at]' ); ?>" value="<?php echo esc_attr( (string) ( $row['started_at'] ?? '' ) ); ?>"></td>
			<td><input type="date" name="<?php echo esc_attr( $prefix . '[ended_at]' ); ?>" value="<?php echo esc_attr( (string) ( $row['ended_at'] ?? '' ) ); ?>"></td>
			<td><button type="button" class="button-link-delete" data-cb-crm-remove-row><?php esc_html_e( 'Remove', 'core-blueprint-crm' ); ?></button></td>
		</tr>
		<?php
	}

	/** @param array<string,mixed> $row
	 *  @param \WP_Post[] $options
	 */
	private static function organization_row( int|string $index, array $row, array $options ): void {
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

	/** @param array<string,mixed> $row
	 *  @param \WP_Post[] $options
	 */
	private static function service_row( int|string $index, array $row, array $options ): void {
		$prefix = 'cb_crm_services[' . (string) $index . ']';
		?>
		<tr data-cb-crm-row>
			<td><select name="<?php echo esc_attr( $prefix . '[service_id]' ); ?>"><option value="0">—</option><?php foreach ( $options as $service ) : ?><option value="<?php echo esc_attr( (string) $service->ID ); ?>" <?php selected( (int) ( $row['service_id'] ?? 0 ), $service->ID ); ?>><?php echo esc_html( $service->post_title ); ?></option><?php endforeach; ?></select></td>
			<td><select name="<?php echo esc_attr( $prefix . '[status]' ); ?>"><?php foreach ( Services::STATUSES as $status ) : ?><option value="<?php echo esc_attr( $status ); ?>" <?php selected( (string) ( $row['status'] ?? 'active' ), $status ); ?>><?php echo esc_html( self::service_status_label( $status ) ); ?></option><?php endforeach; ?></select></td>
			<td><input type="date" name="<?php echo esc_attr( $prefix . '[started_at]' ); ?>" value="<?php echo esc_attr( (string) ( $row['started_at'] ?? '' ) ); ?>"></td>
			<td><input type="date" name="<?php echo esc_attr( $prefix . '[ended_at]' ); ?>" value="<?php echo esc_attr( (string) ( $row['ended_at'] ?? '' ) ); ?>"></td>
			<td><input type="text" name="<?php echo esc_attr( $prefix . '[notes]' ); ?>" value="<?php echo esc_attr( (string) ( $row['notes'] ?? '' ) ); ?>"></td>
			<td><button type="button" class="button-link-delete" data-cb-crm-remove-row><?php esc_html_e( 'Remove', 'core-blueprint-crm' ); ?></button></td>
		</tr>
		<?php
	}

	private static function contact_method_label( string $type ): string {
		return match ( $type ) {
			'email'    => __( 'Email', 'core-blueprint-crm' ),
			'phone'    => __( 'Phone', 'core-blueprint-crm' ),
			'mobile'   => __( 'Mobile', 'core-blueprint-crm' ),
			'website'  => __( 'Website', 'core-blueprint-crm' ),
			'whatsapp' => __( 'WhatsApp', 'core-blueprint-crm' ),
			default    => __( 'Other', 'core-blueprint-crm' ),
		};
	}

	private static function name_type_label( string $type ): string {
		return match ( $type ) {
			'legal'     => __( 'Legal', 'core-blueprint-crm' ),
			'preferred' => __( 'Preferred', 'core-blueprint-crm' ),
			'trading'   => __( 'Trading', 'core-blueprint-crm' ),
			'former'    => __( 'Former', 'core-blueprint-crm' ),
			default     => __( 'Alias', 'core-blueprint-crm' ),
		};
	}

	private static function service_status_label( string $status ): string {
		return match ( $status ) {
			'paused' => __( 'Paused', 'core-blueprint-crm' ),
			'ended'  => __( 'Ended', 'core-blueprint-crm' ),
			default  => __( 'Active', 'core-blueprint-crm' ),
		};
	}
}

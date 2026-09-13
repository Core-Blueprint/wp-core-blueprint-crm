<?php
declare(strict_types=1);

namespace CB\CRM\Admin\Panels;

use CB\CRM\Content\ContactIdentity;
use CB\CRM\Content\Entity;
use CB\CRM\Content\Meta;
use CB\CRM\Content\RecordStatus;
use CB\CRM\PanelRegistry;

defined( 'ABSPATH' ) || exit;

final class DetailsPanel {
	public static function register(): void {
		PanelRegistry::register( [
			'id'         => 'details',
			'label'      => __( 'CRM Details', 'core-blueprint-crm' ),
			'post_types' => [ Entity::CONTACT, Entity::ORGANIZATION ],
			'render'     => [ \CB\CRM\Admin\Panels::class, 'details' ],
			'context'    => 'normal',
			'priority'   => 'high',
		] );
	}

	public static function render( string $owner_type, int $post_id ): void {
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
			$profile_url = $linked > 0 ? get_edit_user_link( $linked ) : '';
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
							<strong data-cb-crm-user-selected-name><?php if ( $linked_user instanceof \WP_User && is_string( $profile_url ) && '' !== $profile_url ) : ?><a href="<?php echo esc_url( $profile_url ); ?>"><?php echo esc_html( $linked_user->display_name ); ?></a><?php else : ?><?php echo esc_html( $linked_user instanceof \WP_User ? $linked_user->display_name : '' ); ?><?php endif; ?></strong>
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
}

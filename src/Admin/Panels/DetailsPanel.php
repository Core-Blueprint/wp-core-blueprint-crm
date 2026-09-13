<?php
declare(strict_types=1);

namespace CB\CRM\Admin\Panels;

use CB\CRM\Content\ContactIdentity;
use CB\CRM\Content\Entity;
use CB\CRM\Content\Meta;
use CB\CRM\Content\RecordStatus;
use CB\CRM\Integration\ContactDataSources;
use CB\CRM\Integration\WooCustomerWorkspace;
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
		if ( Entity::CONTACT === $owner_type ) {
			WooCustomerWorkspace::render_binding( $post_id );
		}
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
			self::source_name_row( $post_id, 'first_name', __( 'First name', 'core-blueprint-crm' ), 'cb-crm-first-name' );
			self::source_name_row( $post_id, 'last_name', __( 'Last name', 'core-blueprint-crm' ), 'cb-crm-last-name' );
			?>
		<tr><th><label for="cb-crm-job-title"><?php esc_html_e( 'Job title', 'core-blueprint-crm' ); ?></label></th><td><div class="cb-crm-source-field"><input type="text" class="regular-text" id="cb-crm-job-title" name="cb_crm_details[job_title]" value="<?php echo esc_attr( (string) get_post_meta( $post_id, Meta::JOB_TITLE, true ) ); ?>"><span class="cb-crm-source-badges"><?php SourceBadge::render( 'crm', Meta::JOB_TITLE, (string) get_post_meta( $post_id, Meta::JOB_TITLE, true ) ); ?></span></div></td></tr>
		<?php self::woo_company_row( $post_id ); ?>
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
						<span class="cb-crm-source-badges"><?php if ( $linked > 0 ) { SourceBadge::render( 'wordpress', 'user' ); } ?></span>
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

	private static function source_name_row( int $post_id, string $field, string $label, string $id ): void {
		$resolved = ContactDataSources::name_field( $post_id, $field );
		$name = 'first_name' === $field ? 'cb_crm_details[first_name]' : 'cb_crm_details[last_name]';
		$override_name = 'first_name' === $field ? 'cb_crm_details[first_name_override]' : 'cb_crm_details[last_name_override]';
		$candidates = $resolved['candidates'];
		$has_external = [] !== $candidates;
		$override = $has_external && ! empty( $resolved['override'] );
		$external_value = '';
		if ( $has_external ) {
			$external_value = (string) $candidates[0]['value'];
			if ( ! $override && 'crm' !== $resolved['source'] ) {
				foreach ( $candidates as $candidate ) {
					if ( $candidate['source'] === $resolved['source'] && $candidate['path'] === $resolved['path'] ) {
						$external_value = (string) $candidate['value'];
						break;
					}
				}
			}
		}
		?>
		<tr>
			<th><label for="<?php echo esc_attr( $id ); ?>"><?php echo esc_html( $label ); ?></label></th>
			<td>
				<div class="cb-crm-source-field">
					<input type="text" class="regular-text" id="<?php echo esc_attr( $id ); ?>" name="<?php echo esc_attr( $name ); ?>" value="<?php echo esc_attr( (string) $resolved['value'] ); ?>" data-external-value="<?php echo esc_attr( $external_value ); ?>" data-override-value="<?php echo esc_attr( $override ? (string) $resolved['value'] : '' ); ?>" <?php echo $has_external && ! $override ? 'readonly' : ''; ?>>
					<span class="cb-crm-source-badges">
						<?php SourceBadge::render( (string) $resolved['source'], (string) $resolved['path'], (string) $resolved['value'] ); ?>
						<?php foreach ( $candidates as $candidate ) : ?>
							<?php if ( ! $override && $candidate['source'] === $resolved['source'] && $candidate['path'] === $resolved['path'] ) { continue; } ?>
							<?php SourceBadge::render( $candidate['source'], $candidate['path'], $candidate['value'], false ); ?>
						<?php endforeach; ?>
					</span>
				</div>
				<?php if ( $has_external ) : ?>
					<label class="cb-crm-source-override-control"><input type="hidden" name="<?php echo esc_attr( $override_name ); ?>" value="0"><input type="checkbox" name="<?php echo esc_attr( $override_name ); ?>" value="1" data-cb-crm-source-override data-target="<?php echo esc_attr( $id ); ?>" <?php checked( $override ); ?>> <?php esc_html_e( 'Customer-specific override', 'core-blueprint-crm' ); ?></label>
				<?php endif; ?>
			</td>
		</tr>
		<?php
	}

	private static function woo_company_row( int $post_id ): void {
		$customer = ContactDataSources::woo_customer( $post_id );
		if ( ! is_object( $customer ) ) {
			return;
		}
		$value = ContactDataSources::woo_value( $post_id, 'billing_company' );
		$can_edit = WooCustomerWorkspace::can_edit_customer();
		if ( '' === $value && ! $can_edit ) {
			return;
		}
		?>
		<tr>
			<th><label for="cb-crm-woo-billing-company"><?php echo esc_html( __( 'Company', 'woocommerce' ) ); ?></label></th>
			<td><div class="cb-crm-source-field"><input type="text" class="regular-text" id="cb-crm-woo-billing-company" name="cb_crm_woo_customer[billing_company]" value="<?php echo esc_attr( $value ); ?>" <?php disabled( ! $can_edit ); ?>><span class="cb-crm-source-badges"><?php SourceBadge::render( 'woocommerce', 'billing_company', $value ); ?></span></div></td>
		</tr>
		<?php
	}
}

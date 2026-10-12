<?php
declare(strict_types=1);

namespace CB\CRM\Admin;

use CB\CRM\Application\Sales\StaffActions;
use CB\CRM\Domain\Sales\Access;
use CB\CRM\Domain\Sales\Rules;
use CB\CRM\Repository\Sales\CreationOptions;

defined( 'ABSPATH' ) || exit;

/** W2B2-A: authenticated WordPress Admin create transport only. */
final class SalesCreateActions {
	public const HOOK = 'cb_crm_sales_create';

	public static function init(): void {
		add_action( 'admin_post_' . self::HOOK, [ __CLASS__, 'handle' ] );
	}

	public static function render_for_type( string $type, string $slug ): void {
		if ( ! self::allowed() || ! Rules::valid_entity_type( $type ) ) {
			wp_die( esc_html__( 'You do not have permission to create Sales records.', 'core-blueprint-crm' ) );
			return;
		}
		$stages = CreationOptions::stages( $type );
		if ( $stages instanceof \WP_Error || [] === $stages ) {
			self::render_error();
			return;
		}
		$party_type = isset( $_GET['identity_type'] ) ? $_GET['identity_type'] : 'contact';
		$search = isset( $_GET['identity_search'] ) ? $_GET['identity_search'] : '';
		if ( ! is_string( $party_type ) || ! is_string( $search ) ) {
			self::render_error();
			return;
		}
		$party_type = wp_unslash( $party_type );
		$search = trim( wp_unslash( $search ) );
		if ( ! Rules::valid_party_type( $party_type ) || mb_strlen( $search ) > 80 ) {
			self::render_error();
			return;
		}
		$identities = [];
		if ( '' !== $search ) {
			$identities = CreationOptions::identities( $party_type, $search );
			if ( $identities instanceof \WP_Error ) {
				self::render_error();
				return;
			}
		}
		$label = Rules::LEAD === $type ? __( 'Create Lead', 'core-blueprint-crm' ) : __( 'Create Opportunity', 'core-blueprint-crm' );
		$back = add_query_arg( [ 'page' => $slug ], admin_url( 'admin.php' ) );
		echo '<p><a href="' . esc_url( $back ) . '">' . esc_html__( 'Back to overview', 'core-blueprint-crm' ) . '</a></p>';
		echo '<h2>' . esc_html( $label ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'Enter a title to save this record now. You can link a Contact or Organization later.', 'core-blueprint-crm' ) . '</p>';
		echo '<form method="get" action="' . esc_url( admin_url( 'admin.php' ) ) . '" class="cb-crm-sales-filters">';
		echo '<input type="hidden" name="page" value="' . esc_attr( $slug ) . '"><input type="hidden" name="create" value="1">';
		echo '<label for="cb-crm-identity-type">' . esc_html__( 'Relationship type', 'core-blueprint-crm' ) . '</label>';
		echo '<select id="cb-crm-identity-type" name="identity_type">';
		foreach ( [ 'contact' => __( 'Contact', 'core-blueprint-crm' ), 'organization' => __( 'Organization', 'core-blueprint-crm' ) ] as $value => $text ) {
			echo '<option value="' . esc_attr( $value ) . '"' . selected( $party_type, $value, false ) . '>' . esc_html( $text ) . '</option>';
		}
		echo '</select><label for="cb-crm-identity-search">' . esc_html__( 'Search name', 'core-blueprint-crm' ) . '</label>';
		echo '<input id="cb-crm-identity-search" name="identity_search" type="search" maxlength="80" value="' . esc_attr( $search ) . '" required>';
		echo '<button type="submit" class="button">' . esc_html__( 'Find relationship', 'core-blueprint-crm' ) . '</button></form>';
		if ( '' !== $search && [] === $identities ) {
			echo '<p role="status">' . esc_html__( 'No matching Contacts or Organizations. Try another name.', 'core-blueprint-crm' ) . '</p>';
		}

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="cb-crm-sales-create">';
		echo '<input type="hidden" name="action" value="' . esc_attr( self::HOOK ) . '">';
		echo '<input type="hidden" name="entity_type" value="' . esc_attr( $type ) . '">';
		wp_nonce_field( self::nonce_action( $type ), '_wpnonce', false, true );
		if ( [] === $identities ) {
			echo '<input type="hidden" name="party" value="">';
		} else {
			echo '<fieldset><legend>' . esc_html__( 'Select a relationship', 'core-blueprint-crm' ) . '</legend>';
			echo '<label class="cb-crm-sales-identity"><input type="radio" name="party" value="" checked> ' . esc_html__( 'No relationship yet', 'core-blueprint-crm' ) . '</label>';
		foreach ( $identities as $index => $identity ) {
			$id = 'cb-crm-party-' . $index;
			echo '<label class="cb-crm-sales-identity" for="' . esc_attr( $id ) . '">';
			echo '<input type="radio" id="' . esc_attr( $id ) . '" name="party" value="' . esc_attr( $party_type . ':' . $identity['id'] ) . '">';
			echo esc_html( $identity['title'] ) . ' <span class="description">#' . esc_html( (string) $identity['id'] ) . '</span></label>';
		}
		echo '</fieldset>';
		}
		echo '<label for="cb-crm-sales-title">' . esc_html__( 'Title', 'core-blueprint-crm' ) . '</label>';
		echo '<input id="cb-crm-sales-title" type="text" name="title" maxlength="190" required>';
		echo '<label for="cb-crm-sales-stage">' . esc_html__( 'Pipeline and stage', 'core-blueprint-crm' ) . '</label>';
		echo '<select id="cb-crm-sales-stage" name="stage_id" required>';
		$initial_stage = $stages[0]['stage_id'];
		$group = null;
		foreach ( $stages as $stage ) {
			if ( $group !== $stage['pipeline_id'] ) {
				if ( null !== $group ) {
					echo '</optgroup>';
				}
				$group = $stage['pipeline_id'];
				echo '<optgroup label="' . esc_attr( $stage['pipeline_name'] ) . '">';
			}
			echo '<option value="' . esc_attr( (string) $stage['stage_id'] ) . '"' . selected( $initial_stage, $stage['stage_id'], false ) . '>' . esc_html( $stage['stage_name'] ) . '</option>';
		}
		if ( null !== $group ) {
			echo '</optgroup>';
		}
		echo '</select>';
		echo '<label for="cb-crm-sales-role">' . esc_html__( 'Relationship role', 'core-blueprint-crm' ) . '</label>';
		echo '<select id="cb-crm-sales-role" name="role">';
		$role_labels = [
			'primary_contact' => __( 'Primary contact', 'core-blueprint-crm' ),
			'decision_maker' => __( 'Decision maker', 'core-blueprint-crm' ),
			'influencer' => __( 'Influencer', 'core-blueprint-crm' ),
			'advisor' => __( 'Advisor', 'core-blueprint-crm' ),
			'other' => __( 'Other', 'core-blueprint-crm' ),
		];
		foreach ( Rules::PARTICIPANT_ROLES as $role ) {
			echo '<option value="' . esc_attr( $role ) . '"' . selected( 'other', $role, false ) . '>' . esc_html( $role_labels[ $role ] ) . '</option>';
		}
		echo '</select>';
		if ( Rules::OPPORTUNITY === $type ) {
			echo '<label for="cb-crm-sales-minor">' . esc_html__( 'Estimated amount (minor units)', 'core-blueprint-crm' ) . '</label>';
			echo '<input id="cb-crm-sales-minor" type="number" name="amount_minor" min="0" step="1" placeholder="" inputmode="numeric">';
			echo '<label for="cb-crm-sales-currency">' . esc_html__( 'Currency (ISO code)', 'core-blueprint-crm' ) . '</label>';
			echo '<input id="cb-crm-sales-currency" type="text" name="currency" maxlength="3" pattern="[A-Z]{3}" placeholder="EUR">';
		}
		echo '<button type="submit" class="button button-primary">' . esc_html( $label ) . '</button></form>';
	}

	/** @param array<string,mixed> $post WordPress-slashed POST data. */
	public static function process( array $post ): int|\WP_Error {
		if ( ! self::allowed() ) {
			return new \WP_Error( 'crm_sales_forbidden' );
		}
		if ( ! is_string( $post['entity_type'] ?? null ) ) {
			return new \WP_Error( 'crm_sales_invalid_request' );
		}
		$type = wp_unslash( $post['entity_type'] );
		if ( ! Rules::valid_entity_type( $type ) ) {
			return new \WP_Error( 'crm_sales_invalid_request' );
		}
		$allowed = [ 'action', 'entity_type', 'title', 'stage_id', 'party', 'role', '_wpnonce' ];
		if ( Rules::OPPORTUNITY === $type ) {
			array_push( $allowed, 'amount_minor', 'currency' );
		}
		if ( count( $post ) !== count( $allowed ) || array_diff( array_keys( $post ), $allowed ) ) {
			return new \WP_Error( 'crm_sales_invalid_request' );
		}
		$values = [];
		foreach ( $allowed as $key ) {
			if ( ! isset( $post[ $key ] ) || ! is_string( $post[ $key ] ) ) {
				return new \WP_Error( 'crm_sales_invalid_request' );
			}
			$values[ $key ] = wp_unslash( $post[ $key ] );
		}
		if ( self::HOOK !== $values['action'] ) {
			return new \WP_Error( 'crm_sales_invalid_request' );
		}
		$verified = wp_verify_nonce( $values['_wpnonce'], self::nonce_action( $type ) );
		if ( 1 !== $verified && 2 !== $verified ) {
			return new \WP_Error( 'crm_sales_invalid_nonce' );
		}
		$title = trim( sanitize_text_field( $values['title'] ) );
		$stage_id = self::positive_integer( $values['stage_id'] );
		if ( '' === $title || mb_strlen( $title ) > 190 || null === $stage_id ) {
			return new \WP_Error( 'crm_sales_invalid_request' );
		}
		$participants = [];
		if ( ! Rules::valid_participant_role( $values['role'] ) ) {
			return new \WP_Error( 'crm_sales_invalid_request' );
		}
		if ( '' !== $values['party'] ) {
			if ( ! preg_match( '/^(contact|organization):([1-9][0-9]{0,17})$/D', $values['party'], $matches ) ) {
				return new \WP_Error( 'crm_sales_invalid_request' );
			}
			$party_id = self::positive_integer( $matches[2] );
			if ( null === $party_id ) {
				return new \WP_Error( 'crm_sales_invalid_request' );
			}
			$participants[] = [ 'party_type' => $matches[1], 'party_id' => $party_id, 'role' => $values['role'] ];
		}
		$stages = CreationOptions::stages( $type );
		if ( $stages instanceof \WP_Error ) {
			return $stages;
		}
		$pipeline_id = null;
		foreach ( $stages as $stage ) {
			if ( $stage['stage_id'] === $stage_id ) {
				$pipeline_id = $stage['pipeline_id'];
				break;
			}
		}
		if ( null === $pipeline_id ) {
			return new \WP_Error( 'crm_sales_invalid_stage' );
		}
		$input = [ 'title' => $title, 'pipeline_id' => $pipeline_id, 'stage_id' => $stage_id ];
		if ( Rules::OPPORTUNITY === $type ) {
			$raw_minor = $values['amount_minor'];
			$minor = '' === $raw_minor ? null : self::nonnegative_integer( $raw_minor );
			$currency = $values['currency'];
			if ( ( '' !== $raw_minor && null === $minor )
				|| ! Rules::valid_estimate( $minor, $currency ) ) {
				return new \WP_Error( 'crm_sales_invalid_estimate' );
			}
			$input['amount_minor'] = $minor;
			$input['currency'] = $currency;
		}
		return Rules::LEAD === $type
			? StaffActions::create_lead( $input, $participants )
			: StaffActions::create_opportunity( $input, $participants );
	}

	public static function handle(): void {
		if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
			wp_die( esc_html__( 'Invalid Sales request.', 'core-blueprint-crm' ), '', [ 'response' => 405 ] );
			return;
		}
		$result = self::process( $_POST );
		if ( $result instanceof \WP_Error ) {
			$denied = in_array( $result->get_error_code(), [ 'crm_sales_forbidden', 'crm_sales_invalid_nonce' ], true );
			wp_die( esc_html__( 'The Sales record could not be created. Check the information and try again.', 'core-blueprint-crm' ), '', [ 'response' => $denied ? 403 : 400 ] );
			return;
		}
		$type = wp_unslash( $_POST['entity_type'] );
		$slug = Rules::LEAD === $type ? Menu::SALES_LEADS_SLUG : Menu::SALES_OPPORTUNITIES_SLUG;
		$url = add_query_arg( [ 'page' => $slug, 'record' => $result, 'sales_notice' => 'created' ], admin_url( 'admin.php' ) );
		wp_safe_redirect( $url, 303 );
		exit;
	}

	private static function allowed(): bool {
		return Access::can_manage() && function_exists( 'get_current_user_id' ) && get_current_user_id() > 0;
	}

	private static function nonce_action( string $type ): string {
		return 'cb_crm_sales_create_' . $type;
	}

	private static function positive_integer( string $value ): ?int {
		$value = self::nonnegative_integer( $value );
		return null !== $value && $value > 0 ? $value : null;
	}

	private static function nonnegative_integer( string $value ): ?int {
		if ( ! preg_match( '/^(0|[1-9][0-9]{0,17})$/D', $value ) ) {
			return null;
		}
		$result = filter_var( $value, FILTER_VALIDATE_INT, [ 'options' => [ 'min_range' => 0 ] ] );
		return false === $result ? null : $result;
	}

	private static function render_error(): void {
		echo '<div class="notice notice-error inline" role="alert"><p>' .
			esc_html__( 'Unable to prepare the Sales creation form.', 'core-blueprint-crm' ) . '</p></div>';
	}
}

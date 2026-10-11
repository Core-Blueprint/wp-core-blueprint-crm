<?php
declare(strict_types=1);

namespace CB\CRM\Admin;

use CB\CRM\Application\Sales\StaffActions;
use CB\CRM\Domain\Sales\Access;
use CB\CRM\Domain\Sales\Rules;

defined( 'ABSPATH' ) || exit;

/**
 * W2B1: narrowly-scoped WordPress Admin POST boundary for Sales status changes.
 *
 * This is not a public API. Every request requires an authenticated CRM/Sales
 * manager, a per-action/per-record/per-revision WordPress nonce, exact input
 * names and types, and the original C2 repository domain/revision checks.
 * Never bypass the C2 facade, call SQL, or trust a caller redirect URL.
 */
final class SalesStatusActions {
	public const HOOK = 'cb_crm_sales_transition';

	/** @return array<string,array{type:string,label:string}> */
	private static function definitions(): array {
		return [
			'qualify'    => [ 'type' => Rules::LEAD, 'label' => __( 'Qualify lead', 'core-blueprint-crm' ) ],
			'disqualify' => [ 'type' => Rules::LEAD, 'label' => __( 'Disqualify lead', 'core-blueprint-crm' ) ],
			'won'        => [ 'type' => Rules::OPPORTUNITY, 'label' => __( 'Mark won', 'core-blueprint-crm' ) ],
			'lost'       => [ 'type' => Rules::OPPORTUNITY, 'label' => __( 'Mark lost', 'core-blueprint-crm' ) ],
			'reopen'     => [ 'type' => Rules::OPPORTUNITY, 'label' => __( 'Reopen opportunity', 'core-blueprint-crm' ) ],
		];
	}

	public static function init(): void {
		add_action( 'admin_post_' . self::HOOK, [ __CLASS__, 'handle' ] );
		// No admin_post_nopriv handler.
	}

	/**
	 * @param array<string,mixed> $record Projected C1 detail record.
	 */
	public static function render_for_record( string $type, array $record ): void {
		if ( ! Access::can_manage() || get_current_user_id() <= 0 || ! Rules::valid_entity_type( $type ) ) {
			return;
		}
		$id = $record['id'] ?? null;
		$revision = $record['revision'] ?? null;
		$state = $record['state'] ?? null;
		if ( ! is_int( $id ) || $id <= 0 || ! is_int( $revision ) || $revision <= 0 || ! is_string( $state ) ) {
			return;
		}
		$actions = match ( $type ) {
			Rules::LEAD => match ( $state ) {
				Rules::LEAD_OPEN => [ 'qualify', 'disqualify' ],
				Rules::LEAD_QUALIFIED => [ 'disqualify' ],
				default => [],
			},
			Rules::OPPORTUNITY => match ( $state ) {
				Rules::DEAL_OPEN => [ 'won', 'lost' ],
				Rules::DEAL_WON, Rules::DEAL_LOST => [ 'reopen' ],
				default => [],
			},
			default => [],
		};
		if ( [] === $actions ) {
			return;
		}

		$definitions = self::definitions();
		echo '<section class="cb-crm-sales-actions" aria-label="' . esc_attr__( 'Sales actions', 'core-blueprint-crm' ) . '">';
		echo '<h3>' . esc_html__( 'Sales actions', 'core-blueprint-crm' ) . '</h3>';
		foreach ( $actions as $operation ) {
			$nonce = self::nonce_action( $type, $id, $revision, $operation );
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
			foreach ( [
				'action' => self::HOOK,
				'sales_op' => $operation,
				'entity_type' => $type,
				'record_id' => (string) $id,
				'revision' => (string) $revision,
			] as $name => $value ) {
				echo '<input type="hidden" name="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '">';
			}
			wp_nonce_field( $nonce, '_wpnonce', false, true );
			echo '<button type="submit" class="button ' .
				esc_attr( in_array( $operation, [ 'qualify', 'won' ], true ) ? 'button-primary' : '' ) . '">' .
				esc_html( $definitions[ $operation ]['label'] ) . '</button>';
			echo '</form>';
		}
		echo '</section>';
	}

	/**
	 * Fully validates a single POST before any C2 repository delegation.
	 *
	 * @param array<string,mixed> $post POST-style WordPress slashed data.
	 * @return int|\WP_Error Committed new revision or preserved original error.
	 */
	public static function process( array $post ): int|\WP_Error {
		if ( ! Access::can_manage() || ! function_exists( 'get_current_user_id' ) || get_current_user_id() <= 0 ) {
			return new \WP_Error( 'crm_sales_forbidden' );
		}
		$allowed = [ 'action', 'sales_op', 'entity_type', 'record_id', 'revision', '_wpnonce' ];
		if ( array_diff( array_keys( $post ), $allowed ) || count( $post ) !== count( $allowed ) ) {
			return new \WP_Error( 'crm_sales_invalid_request' );
		}
		$clean = [];
		foreach ( $allowed as $key ) {
			if ( ! isset( $post[ $key ] ) || ! is_string( $post[ $key ] ) ) {
				return new \WP_Error( 'crm_sales_invalid_request' );
			}
			$clean[ $key ] = wp_unslash( $post[ $key ] );
		}
		if ( self::HOOK !== $clean['action'] ) {
			return new \WP_Error( 'crm_sales_invalid_request' );
		}
		$definitions = self::definitions();
		$operation = $clean['sales_op'];
		$type = $clean['entity_type'];
		if ( ! isset( $definitions[ $operation ] ) || $definitions[ $operation ]['type'] !== $type ) {
			return new \WP_Error( 'crm_sales_invalid_request' );
		}
		$id = self::positive_integer( $clean['record_id'] );
		$revision = self::positive_integer( $clean['revision'] );
		if ( null === $id || null === $revision ) {
			return new \WP_Error( 'crm_sales_invalid_request' );
		}
		$nonce_result = wp_verify_nonce(
			$clean['_wpnonce'],
			self::nonce_action( $type, $id, $revision, $operation )
		);
		if ( 1 !== $nonce_result && 2 !== $nonce_result ) {
			return new \WP_Error( 'crm_sales_invalid_nonce' );
		}

		return match ( $operation ) {
			'qualify' => StaffActions::qualify_lead( $id, $revision ),
			'disqualify' => StaffActions::disqualify_lead( $id, $revision ),
			'won', 'lost' => StaffActions::close_opportunity( $id, $operation, $revision ),
			'reopen' => StaffActions::reopen_opportunity( $id, $revision ),
		};
	}

	/** WordPress admin-post transport; accepts POST only, redirects only on success. */
	public static function handle(): void {
		if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
			wp_die( esc_html__( 'Invalid Sales request.', 'core-blueprint-crm' ), '', [ 'response' => 405 ] );
			return;
		}
		$result = self::process( $_POST );
		if ( $result instanceof \WP_Error ) {
			$denied = in_array( $result->get_error_code(), [ 'crm_sales_forbidden', 'crm_sales_invalid_nonce' ], true );
			wp_die(
				esc_html__( 'The Sales action could not be completed. Reload the record and try again.', 'core-blueprint-crm' ),
				'',
				[ 'response' => $denied ? 403 : 400 ]
			);
			return;
		}
		$type = wp_unslash( $_POST['entity_type'] );
		$id = self::positive_integer( wp_unslash( $_POST['record_id'] ) );
		if ( null === $id ) {
			wp_die( esc_html__( 'Invalid Sales request.', 'core-blueprint-crm' ), '', [ 'response' => 400 ] );
			return;
		}
		$slug = Rules::LEAD === $type ? Menu::SALES_LEADS_SLUG : Menu::SALES_OPPORTUNITIES_SLUG;
		$url = add_query_arg(
			[ 'page' => $slug, 'record' => $id, 'sales_notice' => 'updated' ],
			admin_url( 'admin.php' )
		);
		wp_safe_redirect( $url, 303 );
		exit;
	}

	private static function nonce_action( string $type, int $id, int $revision, string $operation ): string {
		return 'cb_crm_sales_' . $type . '_' . $id . '_' . $revision . '_' . $operation;
	}

	private static function positive_integer( string $value ): ?int {
		if ( 1 !== preg_match( '/^[1-9][0-9]{0,17}$/D', $value ) ) {
			return null;
		}
		$result = filter_var( $value, FILTER_VALIDATE_INT, [ 'options' => [ 'min_range' => 1 ] ] );
		return false === $result ? null : $result;
	}
}

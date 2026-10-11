<?php
declare(strict_types=1);

namespace CB\CRM\Application\Sales;

use CB\CRM\Database\Schema;
use CB\CRM\Domain\Sales\Access;
use CB\CRM\Domain\Sales\Rules;
use CB\CRM\Repository\Sales\Conversion;
use CB\CRM\Repository\Sales\Outcomes;
use CB\CRM\Repository\Sales\Records;
use CB\CRM\Repository\Sales\Transitions;

defined( 'ABSPATH' ) || exit;

/**
 * Internal, staff-only Wave 1C/C2 mutation facade.
 *
 * Provides a single typed PHP entry point for future governed Admin handlers.
 * This class is NOT an HTTP/REST endpoint or a nonce/CSRF boundary. Any future
 * transport must enforce its own capability, nonce and user-intent checks.
 *
 * The repositories retain ownership of transactional state, revision CAS,
 * canonical identity locks, audit events and domain validation. The facade
 * intentionally has no SQL, generic action dispatcher or actor/owner argument.
 */
final class StaffActions {
	/** @param array<string,mixed> $input
	 *  @param array<int,array<string,mixed>> $participants
	 *  @return int|\WP_Error Created Lead ID.
	 */
	public static function create_lead( array $input, array $participants ): int|\WP_Error {
		$denied = self::deny_if_unavailable();
		return $denied ?? Records::create_lead( $input, $participants );
	}

	/** @param array<string,mixed> $input
	 *  @param array<int,array<string,mixed>> $participants
	 *  @return int|\WP_Error Created Opportunity ID.
	 */
	public static function create_opportunity( array $input, array $participants ): int|\WP_Error {
		$denied = self::deny_if_unavailable();
		return $denied ?? Records::create_opportunity( $input, $participants );
	}

	/** @return int|\WP_Error New Lead revision. */
	public static function qualify_lead( int $lead_id, int $expected_revision ): int|\WP_Error {
		$denied = self::deny_if_unavailable();
		return $denied ?? Transitions::set_lead_status( $lead_id, Rules::LEAD_QUALIFIED, $expected_revision );
	}

	/** @return int|\WP_Error New Lead revision. */
	public static function disqualify_lead( int $lead_id, int $expected_revision ): int|\WP_Error {
		$denied = self::deny_if_unavailable();
		return $denied ?? Transitions::set_lead_status( $lead_id, Rules::LEAD_DISQUALIFIED, $expected_revision );
	}

	/** @return int|\WP_Error New revision of the existing Sales record. */
	public static function move_stage( string $type, int $id, int $stage_id, int $expected_revision ): int|\WP_Error {
		$denied = self::deny_if_unavailable();
		return $denied ?? Transitions::move_stage( $type, $id, $stage_id, $expected_revision );
	}

	/**
	 * Explicit qualified Lead conversion, never an implicit qualify action.
	 * Replay returns the verified existing Opportunity ID and does not write.
	 *
	 * @return int|\WP_Error New or previously linked Opportunity ID.
	 */
	public static function convert_lead(
		int $lead_id,
		int $expected_revision,
		int $opportunity_pipeline_id,
		int $opportunity_stage_id
	): int|\WP_Error {
		$denied = self::deny_if_unavailable();
		return $denied ?? Conversion::convert(
			$lead_id,
			$expected_revision,
			$opportunity_pipeline_id,
			$opportunity_stage_id
		);
	}

	/** @return int|\WP_Error New Opportunity revision. */
	public static function close_opportunity( int $opportunity_id, string $outcome, int $expected_revision ): int|\WP_Error {
		$denied = self::deny_if_unavailable();
		return $denied ?? Outcomes::close( $opportunity_id, $outcome, $expected_revision );
	}

	/** @return int|\WP_Error New Opportunity revision. */
	public static function reopen_opportunity( int $opportunity_id, int $expected_revision ): int|\WP_Error {
		$denied = self::deny_if_unavailable();
		return $denied ?? Outcomes::reopen( $opportunity_id, $expected_revision );
	}

	/**
	 * Fail closed before delegation and before any SQL in all eight methods.
	 * Repositories repeat domain/capability checks during the actual mutation.
	 */
	private static function deny_if_unavailable(): ?\WP_Error {
		if ( ! Access::can_manage()
			|| ! function_exists( 'get_current_user_id' )
			|| get_current_user_id() <= 0
			|| ! defined( 'CB_CRM_SCHEMA_VERSION' )
			|| CB_CRM_SCHEMA_VERSION !== (string) get_option( Schema::OPTION, '' ) ) {
			return new \WP_Error( 'crm_sales_forbidden', 'CRM Sales staff action is unavailable.' );
		}
		return null;
	}
}

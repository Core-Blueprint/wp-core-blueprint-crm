<?php
declare(strict_types=1);

namespace CB\CRM\Domain\Sales;

use CB\CRM\Capabilities;

defined( 'ABSPATH' ) || exit;

/**
 * Internal Wave 1A staff-wide gate, not a row-level confidentiality policy.
 * Owner/assignee metadata must never be treated as authorisation.
 *
 * Retain the canonical CRM capability as a prerequisite: a Sales-only role
 * cannot bypass CRM identity access just by receiving a Sales capability.
 */
final class Access {
	private static function can_access_crm(): bool {
		return function_exists( 'cb_crm_runtime_ready' )
			&& \cb_crm_runtime_ready()
			&& function_exists( 'current_user_can' )
			&& current_user_can( Capabilities::MANAGE );
	}

	public static function can_manage(): bool {
		return self::can_access_crm() && current_user_can( Capabilities::MANAGE_SALES );
	}

	public static function can_view(): bool {
		return self::can_access_crm()
			&& ( current_user_can( Capabilities::VIEW_SALES ) || current_user_can( Capabilities::MANAGE_SALES ) );
	}
}

<?php
declare(strict_types=1);

namespace CB\CRM\Domain\Sales;

use CB\CRM\Capabilities;

defined( 'ABSPATH' ) || exit;

/**
 * Internal Wave 1A gate only; no public Sales read or mutation API exists yet.
 *
 * Owner/assignee metadata must never be treated as row-level authorisation.
 * Separate Sales-specific capabilities and record scoping remain a pre-UI gate.
 */
final class Access {
	public static function can_manage(): bool {
		return function_exists( 'cb_crm_runtime_ready' )
			&& \cb_crm_runtime_ready()
			&& function_exists( 'current_user_can' )
			&& current_user_can( Capabilities::MANAGE );
	}

	public static function can_view(): bool {
		return self::can_manage();
	}
}

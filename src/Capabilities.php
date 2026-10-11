<?php
declare(strict_types=1);
namespace CB\CRM;
defined( 'ABSPATH' ) || exit;

final class Capabilities {
	public const MANAGE = 'cb_manage_crm';
	public const VIEW_SALES = 'cb_view_crm_sales';
	public const MANAGE_SALES = 'cb_manage_crm_sales';

	public static function init(): void {
		add_filter( 'core_blueprint_capability_catalog', [ __CLASS__, 'catalog' ] );
	}

	public static function install(): void {
		foreach ( [ 'administrator', 'cb_operator' ] as $role_name ) {
			$role = get_role( $role_name );
			if ( $role ) {
				$role->add_cap( self::MANAGE );
				$role->add_cap( self::VIEW_SALES );
				$role->add_cap( self::MANAGE_SALES );
			}
		}
	}

	/** @param array<string,array<string,mixed>> $catalog @return array<string,array<string,mixed>> */
	public static function catalog( array $catalog ): array {
		$ready = did_action( 'init' ) > 0 || doing_action( 'init' );
		$catalog[ self::MANAGE ] = [
			'label' => $ready ? __( 'Manage CRM', 'core-blueprint-crm' ) : 'Manage CRM',
			'group' => $ready ? __( 'Core Blueprint CRM', 'core-blueprint-crm' ) : 'Core Blueprint CRM',
			'source' => 'Core Blueprint CRM',
			'description' => $ready ? __( 'View and manage CRM contacts, organizations, relationships, service agreements, notes and activity.', 'core-blueprint-crm' ) : 'View and manage CRM contacts, organizations, relationships, service agreements, notes and activity.',
			'policy_grant' => false,
		];
		// Custom CRM roles receive no implicit Sales grant. Delegation is explicit.
		foreach ( [
			self::VIEW_SALES => [ 'View CRM Sales', 'Read internal CRM Sales leads and opportunities.' ],
			self::MANAGE_SALES => [ 'Manage CRM Sales', 'Create and manage internal CRM Sales leads and opportunities.' ],
		] as $capability => [ $label, $description ] ) {
			$catalog[ $capability ] = [
				'label' => $label,
				'group' => $ready ? __( 'Core Blueprint CRM', 'core-blueprint-crm' ) : 'Core Blueprint CRM',
				'source' => 'Core Blueprint CRM',
				'description' => $description,
				'policy_grant' => false,
			];
		}
		return $catalog;
	}
}

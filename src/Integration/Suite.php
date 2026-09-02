<?php
declare(strict_types=1);

namespace CB\CRM\Integration;

use CB\Core\ExtensionRegistry;
use CB\CRM\Admin\Menu;
use CB\CRM\Content\PostTypes;

defined( 'ABSPATH' ) || exit;

final class Suite {
	public const ID = 'core-blueprint-crm';

	public static function init(): void {
		add_action( 'cb_core_register_extensions', [ __CLASS__, 'register_extension' ] );
		add_filter( 'cb_core_module_status_definitions', [ __CLASS__, 'register_status_definition' ] );
	}

	public static function register_extension(): void {
		ExtensionRegistry::register( [
			'id'            => self::ID,
			'plugin_file'   => CB_CRM_BASENAME,
			'requires_api'  => CB_CRM_REQUIRED_API,
			'requires_base' => CB_CRM_REQUIRED_BASE,
			'menu_url'      => admin_url( 'admin.php?page=' . Menu::TOP_LEVEL_SLUG ),
			'status_id'     => 'crm',
		] );
	}

	/** @param array<string,array<string,mixed>> $definitions
	 *  @return array<string,array<string,mixed>>
	 */
	public static function register_status_definition( array $definitions ): array {
		$definitions['crm'] = [
			'provider' => [ __CLASS__, 'status' ],
			'label'    => did_action( 'init' ) ? __( 'CRM', 'core-blueprint-crm' ) : 'CRM',
			'url'      => admin_url( 'admin.php?page=' . Menu::TOP_LEVEL_SLUG ),
		];
		return $definitions;
	}

	/** @return array{state:string,detail:string,url:string} */
	public static function status(): array {
		$contacts      = wp_count_posts( PostTypes::CONTACT );
		$organizations = wp_count_posts( PostTypes::ORGANIZATION );
		$contact_count = isset( $contacts->publish ) ? (int) $contacts->publish : 0;
		$org_count     = isset( $organizations->publish ) ? (int) $organizations->publish : 0;

		return [
			'state'  => 'ok',
			'detail' => sprintf(
				/* translators: 1: contacts, 2: organizations. */
				__( '%1$d contacts · %2$d organizations', 'core-blueprint-crm' ),
				$contact_count,
				$org_count
			),
			'url' => admin_url( 'admin.php?page=' . Menu::TOP_LEVEL_SLUG ),
		];
	}
}

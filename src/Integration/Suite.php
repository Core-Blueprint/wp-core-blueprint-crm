<?php
declare(strict_types=1);

namespace CB\CRM\Integration;

use CB\Core\ExtensionRegistry;
use CB\CRM\Admin\Menu;
use CB\CRM\Content\PostTypes;
use CB\CRM\Database\Schema;

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
		$installed_schema = (string) get_option( Schema::OPTION, '0' );
		if ( version_compare( $installed_schema, CB_CRM_SCHEMA_VERSION, '<' ) ) {
			return [ 'state' => 'warn', 'detail' => __( 'CRM database upgrade pending.', 'core-blueprint-crm' ), 'url' => admin_url( 'admin.php?page=' . Menu::TOP_LEVEL_SLUG ) ];
		}
		if ( version_compare( $installed_schema, CB_CRM_SCHEMA_VERSION, '>' ) ) {
			return [ 'state' => 'warn', 'detail' => __( 'CRM database schema is newer than this plugin build.', 'core-blueprint-crm' ), 'url' => admin_url( 'admin.php?page=' . Menu::TOP_LEVEL_SLUG ) ];
		}

		$contact_count      = self::record_count( PostTypes::CONTACT );
		$organization_count = self::record_count( PostTypes::ORGANIZATION );
		$contacts = sprintf(
			/* translators: %d: number of contacts. */
			_n( '%d contact', '%d contacts', $contact_count, 'core-blueprint-crm' ),
			$contact_count
		);
		$organizations = sprintf(
			/* translators: %d: number of organizations. */
			_n( '%d organization', '%d organizations', $organization_count, 'core-blueprint-crm' ),
			$organization_count
		);

		return [
			'state'  => 'ok',
			'detail' => $contacts . ' · ' . $organizations,
			'url'    => admin_url( 'admin.php?page=' . Menu::TOP_LEVEL_SLUG ),
		];
	}

	private static function record_count( string $post_type ): int {
		$counts = wp_count_posts( $post_type );
		$total  = 0;
		foreach ( get_object_vars( $counts ) as $status => $count ) {
			if ( ! in_array( $status, [ 'trash', 'auto-draft' ], true ) ) {
				$total += (int) $count;
			}
		}
		return $total;
	}
}

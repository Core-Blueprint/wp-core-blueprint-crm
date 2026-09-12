<?php
declare(strict_types=1);

namespace CB\CRM\Admin;

use CB\CRM\Admin\Panels\AddressesPanel;
use CB\CRM\Admin\Panels\ContactMethodsPanel;
use CB\CRM\Admin\Panels\DetailsPanel;
use CB\CRM\Admin\Panels\NamesPanel;
use CB\CRM\Admin\Panels\NotesActivityPanel;
use CB\CRM\Admin\Panels\OrganizationsPanel;

defined( 'ABSPATH' ) || exit;

/** Thin coordinator kept for registration order and backwards-compatible render callbacks. */
final class Panels {
	public static function init(): void {
		add_action( 'cb_crm_register_panels', [ __CLASS__, 'register' ] );
	}

	public static function register(): void {
		DetailsPanel::register();
		ContactMethodsPanel::register();
		AddressesPanel::register();
		NamesPanel::register();
		OrganizationsPanel::register();
		NotesActivityPanel::register();
	}

	public static function details( string $owner_type, int $post_id ): void {
		DetailsPanel::render( $owner_type, $post_id );
	}

	public static function contact_methods( string $owner_type, int $post_id ): void {
		ContactMethodsPanel::render( $owner_type, $post_id );
	}

	public static function addresses( string $owner_type, int $post_id ): void {
		AddressesPanel::render( $owner_type, $post_id );
	}

	public static function names( string $owner_type, int $post_id ): void {
		NamesPanel::render( $owner_type, $post_id );
	}

	public static function organizations( string $owner_type, int $post_id ): void {
		OrganizationsPanel::render( $owner_type, $post_id );
	}

	public static function notes_activity( string $owner_type, int $post_id ): void {
		NotesActivityPanel::render( $owner_type, $post_id );
	}
}

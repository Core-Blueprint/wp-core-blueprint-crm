<?php
declare(strict_types=1);

namespace CB\CRM\Integration;

use CB\Core\ExtensionRegistry;
use CB\CRM\Content\ContactIdentity;
use CB\CRM\Content\Entity;
use CB\CRM\PanelRegistry;
use CB\Helpdesk\Frontend\Queries\Tickets;

defined( 'ABSPATH' ) || exit;

final class Helpdesk {
	public static function init(): void {
		add_action( 'cb_crm_register_panels', [ __CLASS__, 'register_panel' ], 20 );
	}

	public static function register_panel(): void {
		if ( ! class_exists( Tickets::class ) || ! current_user_can( 'cb_helpdesk_manage_tickets' ) ) {
			return;
		}

		PanelRegistry::register( [
			'id'         => 'helpdesk',
			'label'      => __( 'Helpdesk', 'core-blueprint-crm' ),
			'post_types' => [ Entity::CONTACT ],
			'render'     => [ __CLASS__, 'render' ],
			'context'    => 'side',
			'priority'   => 'default',
		] );
	}

	public static function render( string $owner_type, int $contact_id ): void {
		if ( Entity::CONTACT !== $owner_type ) {
			return;
		}

		$user_id = ContactIdentity::linked_user_id( $contact_id );
		if ( $user_id <= 0 ) {
			echo '<p class="description">' . esc_html__( 'Link this contact to a WordPress user to show their support tickets.', 'core-blueprint-crm' ) . '</p>';
			return;
		}

		$tickets = Tickets::for_user( $user_id, 10 );
		if ( is_wp_error( $tickets ) ) {
			return;
		}

		if ( [] === $tickets ) {
			echo '<p class="description">' . esc_html__( 'No Helpdesk tickets for this user.', 'core-blueprint-crm' ) . '</p>';
			self::render_workspace_link();
			return;
		}

		echo '<ul style="margin:0;">';
		foreach ( $tickets as $ticket ) {
			echo '<li style="margin-bottom:8px;"><strong>' . esc_html( (string) ( $ticket['subject'] ?? '' ) ) . '</strong><br><small>'
				. esc_html( (string) ( $ticket['ticket_number'] ?? '' ) )
				. ' · '
				. esc_html( (string) ( $ticket['status'] ?? '' ) )
				. '</small></li>';
		}
		echo '</ul>';

		self::render_workspace_link();
	}

	private static function render_workspace_link(): void {
		$url = self::workspace_url();
		if ( '' === $url ) {
			return;
		}

		echo '<p><a class="button" href="' . esc_url( $url ) . '">' . esc_html__( 'Helpdesk', 'core-blueprint-crm' ) . '</a></p>';
	}

	private static function workspace_url(): string {
		$definition = ExtensionRegistry::definition( 'core-blueprint-helpdesk' );
		if ( ! is_array( $definition ) ) {
			return '';
		}

		$url = isset( $definition['menu_url'] ) && is_scalar( $definition['menu_url'] )
			? trim( (string) $definition['menu_url'] )
			: '';

		return '' !== $url ? esc_url_raw( $url ) : '';
	}
}

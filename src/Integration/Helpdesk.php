<?php
declare(strict_types=1);

namespace CB\CRM\Integration;

use CB\CRM\Content\ContactIdentity;
use CB\CRM\Content\Entity;
use CB\CRM\PanelRegistry;

defined( 'ABSPATH' ) || exit;

final class Helpdesk {
	public static function init(): void {
		add_action( 'cb_crm_register_panels', [ __CLASS__, 'register_panel' ], 20 );
	}

	public static function register_panel(): void {
		if ( ! class_exists( '\\CB\\Helpdesk\\Ticket\\Repository' ) ) {
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
		unset( $owner_type );
		$user_id = ContactIdentity::linked_user_id( $contact_id );
		if ( $user_id <= 0 ) {
			echo '<p class="description">' . esc_html__( 'Link this contact to a WordPress user to show their support tickets.', 'core-blueprint-crm' ) . '</p>';
			return;
		}

		$tickets = \CB\Helpdesk\Ticket\Repository::for_user( $user_id, 10 );
		if ( ! $tickets ) {
			echo '<p class="description">' . esc_html__( 'No Helpdesk tickets for this user.', 'core-blueprint-crm' ) . '</p>';
			return;
		}

		$base_url = admin_url( 'admin.php?page=core-blueprint-helpdesk' );
		echo '<ul style="margin:0;">';
		foreach ( $tickets as $ticket ) {
			$url = add_query_arg( 'ticket', (int) $ticket['id'], $base_url );
			echo '<li style="margin-bottom:8px;"><a href="' . esc_url( $url ) . '"><strong>' . esc_html( (string) $ticket['subject'] ) . '</strong></a><br><small>' . esc_html( (string) $ticket['ticket_number'] ) . ' · ' . esc_html( (string) $ticket['status'] ) . '</small></li>';
		}
		echo '</ul>';
	}
}

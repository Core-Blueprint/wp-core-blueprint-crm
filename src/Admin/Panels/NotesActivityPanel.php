<?php
declare(strict_types=1);

namespace CB\CRM\Admin\Panels;

use CB\CRM\Content\Entity;
use CB\CRM\PanelRegistry;
use CB\CRM\Repository\Activity;
use CB\CRM\Repository\Notes;

defined( 'ABSPATH' ) || exit;

final class NotesActivityPanel {
	public static function register(): void {
		PanelRegistry::register( [
			'id'         => 'notes-activity',
			'label'      => __( 'Notes & Activity', 'core-blueprint-crm' ),
			'post_types' => [ Entity::CONTACT, Entity::ORGANIZATION ],
			'render'     => [ \CB\CRM\Admin\Panels::class, 'notes_activity' ],
		] );
	}

	public static function render( string $owner_type, int $post_id ): void {
		$notes    = Notes::for_owner( $owner_type, $post_id, 20 );
		$activity = Activity::for_owner( $owner_type, $post_id, 30 );
		echo '<p><label for="cb-crm-new-note"><strong>' . esc_html__( 'Add note', 'core-blueprint-crm' ) . '</strong></label></p><textarea class="widefat" rows="3" id="cb-crm-new-note" name="cb_crm_new_note"></textarea>';
		echo '<h3>' . esc_html__( 'Recent notes', 'core-blueprint-crm' ) . '</h3>';
		if ( ! $notes ) {
			echo '<p class="description">' . esc_html__( 'No notes yet.', 'core-blueprint-crm' ) . '</p>';
		}
		foreach ( $notes as $note ) {
			$author = get_userdata( (int) $note['author_user_id'] );
			echo '<div class="cb-crm-timeline-entry"><p class="cb-crm-timeline-body">' . nl2br( esc_html( (string) $note['body'] ) ) . '</p><small>' . esc_html( $author ? $author->display_name : __( 'System', 'core-blueprint-crm' ) ) . ' · ' . esc_html( (string) $note['created_at'] ) . '</small></div>';
		}
		echo '<h3>' . esc_html__( 'Activity', 'core-blueprint-crm' ) . '</h3>';
		if ( ! $activity ) {
			echo '<p class="description">' . esc_html__( 'No activity yet.', 'core-blueprint-crm' ) . '</p>';
		}
		foreach ( $activity as $event ) {
			echo '<div class="cb-crm-timeline-entry"><strong>' . esc_html( (string) $event['summary'] ) . '</strong><br><small>' . esc_html( (string) $event['source'] ) . ' · ' . esc_html( (string) $event['created_at'] ) . '</small></div>';
		}
	}
}

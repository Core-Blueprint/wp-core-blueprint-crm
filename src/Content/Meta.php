<?php
declare(strict_types=1);
namespace CB\CRM\Content;
defined( 'ABSPATH' ) || exit;

final class Meta {
	public const STATUS = '_cb_crm_status';
	public const WP_USER_ID = '_cb_crm_wp_user_id';
	public const EMAIL_MODE = '_cb_crm_email_mode';
	public const FIRST_NAME = '_cb_crm_first_name';
	public const NAME_PREFIX = '_cb_crm_name_prefix';
	public const LAST_NAME = '_cb_crm_last_name';
	public const JOB_TITLE = '_cb_crm_job_title';
	public const LEGAL_NAME = '_cb_crm_legal_name';

	public static function register(): void {
		foreach ( [ PostTypes::CONTACT, PostTypes::ORGANIZATION ] as $post_type ) {
			register_post_meta( $post_type, self::STATUS, self::args( 'string', 'sanitize_key' ) );
		}
		register_post_meta( PostTypes::CONTACT, self::WP_USER_ID, self::args( 'integer', 'absint' ) );
		register_post_meta( PostTypes::CONTACT, self::EMAIL_MODE, self::args( 'string', 'sanitize_key' ) );
		register_post_meta( PostTypes::CONTACT, self::FIRST_NAME, self::args( 'string', 'sanitize_text_field' ) );
		register_post_meta( PostTypes::CONTACT, self::NAME_PREFIX, self::args( 'string', 'sanitize_text_field' ) );
		register_post_meta( PostTypes::CONTACT, self::LAST_NAME, self::args( 'string', 'sanitize_text_field' ) );
		register_post_meta( PostTypes::CONTACT, self::JOB_TITLE, self::args( 'string', 'sanitize_text_field' ) );
		register_post_meta( PostTypes::ORGANIZATION, self::LEGAL_NAME, self::args( 'string', 'sanitize_text_field' ) );
	}

	/** @return array<string,mixed> */
	private static function args( string $type, callable|string $sanitize ): array {
		return [
			'type'              => $type,
			'single'            => true,
			'show_in_rest'      => false,
			'sanitize_callback' => $sanitize,
			'auth_callback'     => static fn(): bool => current_user_can( \CB\CRM\Capabilities::MANAGE ),
		];
	}
}

<?php
declare(strict_types=1);
namespace CB\CRM\Database;

use CB\Core\Database\SchemaRegistry;
defined( 'ABSPATH' ) || exit;

final class Schema {
	public const OPTION = 'cb_crm_db_version';
	public static function register(): void {
		SchemaRegistry::register( [
			'id' => 'core-blueprint-crm', 'version' => CB_CRM_SCHEMA_VERSION, 'option_key' => self::OPTION,
			'tables' => [ [ __CLASS__, 'contact_methods_table' ], [ __CLASS__, 'addresses_table' ], [ __CLASS__, 'names_table' ], [ __CLASS__, 'organization_relations_table' ], [ __CLASS__, 'service_assignments_table' ], [ __CLASS__, 'notes_table' ], [ __CLASS__, 'activities_table' ] ],
			'install' => [ __CLASS__, 'install' ],
		] );
	}
	public static function contact_methods_table(): string { global $wpdb; return $wpdb->prefix . 'cb_crm_contact_methods'; }
	public static function addresses_table(): string { global $wpdb; return $wpdb->prefix . 'cb_crm_addresses'; }
	public static function names_table(): string { global $wpdb; return $wpdb->prefix . 'cb_crm_names'; }
	public static function organization_relations_table(): string { global $wpdb; return $wpdb->prefix . 'cb_crm_org_relations'; }
	public static function service_assignments_table(): string { global $wpdb; return $wpdb->prefix . 'cb_crm_service_assignments'; }
	public static function notes_table(): string { global $wpdb; return $wpdb->prefix . 'cb_crm_notes'; }
	public static function activities_table(): string { global $wpdb; return $wpdb->prefix . 'cb_crm_activities'; }

	public static function install(): bool {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset = $wpdb->get_charset_collate();
		dbDelta( 'CREATE TABLE ' . self::contact_methods_table() . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT, owner_type varchar(20) NOT NULL, owner_id bigint(20) unsigned NOT NULL,
			method_type varchar(32) NOT NULL, label varchar(100) NOT NULL DEFAULT '', value varchar(255) NOT NULL,
			is_primary tinyint(1) unsigned NOT NULL DEFAULT 0, source varchar(32) NOT NULL DEFAULT 'crm', sort_order int unsigned NOT NULL DEFAULT 0,
			created_at datetime NOT NULL, updated_at datetime NOT NULL, PRIMARY KEY  (id), KEY owner (owner_type,owner_id),
			KEY owner_method (owner_type,owner_id,method_type), KEY value (value(191))
		) {$charset};" );
		dbDelta( 'CREATE TABLE ' . self::addresses_table() . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT, owner_type varchar(20) NOT NULL, owner_id bigint(20) unsigned NOT NULL,
			label varchar(100) NOT NULL DEFAULT '', address_line_1 varchar(255) NOT NULL DEFAULT '', address_line_2 varchar(255) NOT NULL DEFAULT '',
			postal_code varchar(40) NOT NULL DEFAULT '', city varchar(120) NOT NULL DEFAULT '', region varchar(120) NOT NULL DEFAULT '', country varchar(2) NOT NULL DEFAULT '',
			is_primary tinyint(1) unsigned NOT NULL DEFAULT 0, sort_order int unsigned NOT NULL DEFAULT 0, created_at datetime NOT NULL, updated_at datetime NOT NULL,
			PRIMARY KEY  (id), KEY owner (owner_type,owner_id), KEY postal_code (postal_code), KEY city (city)
		) {$charset};" );
		dbDelta( 'CREATE TABLE ' . self::names_table() . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT, owner_type varchar(20) NOT NULL, owner_id bigint(20) unsigned NOT NULL,
			name varchar(255) NOT NULL, name_type varchar(32) NOT NULL DEFAULT 'alias', is_primary tinyint(1) unsigned NOT NULL DEFAULT 0,
			started_at date NULL, ended_at date NULL, sort_order int unsigned NOT NULL DEFAULT 0, created_at datetime NOT NULL, updated_at datetime NOT NULL,
			PRIMARY KEY  (id), KEY owner (owner_type,owner_id), KEY name_lookup (name(191))
		) {$charset};" );
		dbDelta( 'CREATE TABLE ' . self::organization_relations_table() . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT, contact_id bigint(20) unsigned NOT NULL, organization_id bigint(20) unsigned NOT NULL,
			role_title varchar(190) NOT NULL DEFAULT '', is_primary tinyint(1) unsigned NOT NULL DEFAULT 0, started_at date NULL, ended_at date NULL,
			created_at datetime NOT NULL, updated_at datetime NOT NULL, PRIMARY KEY  (id), KEY contact (contact_id), KEY organization (organization_id), KEY active_contact (contact_id,ended_at)
		) {$charset};" );
		dbDelta( 'CREATE TABLE ' . self::service_assignments_table() . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT, service_id bigint(20) unsigned NOT NULL, owner_type varchar(20) NOT NULL, owner_id bigint(20) unsigned NOT NULL,
			status varchar(32) NOT NULL DEFAULT 'active', started_at date NULL, ended_at date NULL, notes text NULL, created_at datetime NOT NULL, updated_at datetime NOT NULL,
			PRIMARY KEY  (id), KEY owner (owner_type,owner_id), KEY service (service_id), KEY status (status)
		) {$charset};" );
		dbDelta( 'CREATE TABLE ' . self::notes_table() . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT, owner_type varchar(20) NOT NULL, owner_id bigint(20) unsigned NOT NULL,
			author_user_id bigint(20) unsigned NOT NULL DEFAULT 0, body longtext NOT NULL, created_at datetime NOT NULL, updated_at datetime NOT NULL,
			PRIMARY KEY  (id), KEY owner_created (owner_type,owner_id,created_at)
		) {$charset};" );
		dbDelta( 'CREATE TABLE ' . self::activities_table() . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT, owner_type varchar(20) NOT NULL, owner_id bigint(20) unsigned NOT NULL,
			event_type varchar(64) NOT NULL, source varchar(64) NOT NULL DEFAULT 'crm', actor_user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			reference_type varchar(64) NOT NULL DEFAULT '', reference_id varchar(190) NOT NULL DEFAULT '', summary varchar(255) NOT NULL DEFAULT '', context longtext NULL,
			created_at datetime NOT NULL, PRIMARY KEY  (id), KEY owner_created (owner_type,owner_id,created_at), KEY event_type (event_type), KEY reference (reference_type,reference_id)
		) {$charset};" );
		return true;
	}
}

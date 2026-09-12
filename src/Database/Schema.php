<?php
declare(strict_types=1);

namespace CB\CRM\Database;

use CB\Core\Database\SchemaRegistry;

defined( 'ABSPATH' ) || exit;

final class Schema {
	public const OPTION = 'cb_crm_db_version';

	public static function register(): void {
		SchemaRegistry::register( [
			'id' => 'core-blueprint-crm',
			'version' => CB_CRM_SCHEMA_VERSION,
			'option_key' => self::OPTION,
			'tables' => [
				[ __CLASS__, 'contact_methods_table' ],
				[ __CLASS__, 'addresses_table' ],
				[ __CLASS__, 'names_table' ],
				[ __CLASS__, 'business_identifiers_table' ],
				[ __CLASS__, 'organization_relations_table' ],
				[ __CLASS__, 'service_agreements_table' ],
				[ __CLASS__, 'document_links_table' ],
				[ __CLASS__, 'notes_table' ],
				[ __CLASS__, 'activities_table' ],
			],
			'install' => [ __CLASS__, 'install' ],
		] );
	}

	public static function contact_methods_table(): string { global $wpdb; return $wpdb->prefix . 'cb_crm_contact_methods'; }
	public static function addresses_table(): string { global $wpdb; return $wpdb->prefix . 'cb_crm_addresses'; }
	public static function names_table(): string { global $wpdb; return $wpdb->prefix . 'cb_crm_names'; }
	public static function business_identifiers_table(): string { global $wpdb; return $wpdb->prefix . 'cb_crm_business_identifiers'; }
	public static function organization_relations_table(): string { global $wpdb; return $wpdb->prefix . 'cb_crm_org_relations'; }
	public static function service_agreements_table(): string { global $wpdb; return $wpdb->prefix . 'cb_crm_service_agreements'; }
	public static function document_links_table(): string { global $wpdb; return $wpdb->prefix . 'cb_crm_document_links'; }
	public static function notes_table(): string { global $wpdb; return $wpdb->prefix . 'cb_crm_notes'; }
	public static function activities_table(): string { global $wpdb; return $wpdb->prefix . 'cb_crm_activities'; }

	public static function install(): bool {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset = $wpdb->get_charset_collate();

		foreach ( self::ddl_definitions() as $table => $definitions ) {
			$sql = "CREATE TABLE {$table} (\n  " . implode( ",\n  ", $definitions ) . "\n) {$charset};";
			dbDelta( $sql );
		}

		// Legacy 1.4 builds could let dbDelta infer a default from a later
		// definition on the same source line. Canonical DDL prevents new drift;
		// this repair removes any already-persisted default drift in place.
		self::repair_default_drift();

		// Never report installer success unless the canonical columns/defaults
		// and indexes can actually be observed in the database.
		return self::schema_is_healthy();
	}

	/**
	 * Canonical dbDelta input.
	 *
	 * Every array entry is exactly one field or index definition. install()
	 * joins entries with newlines because dbDelta parses CREATE TABLE bodies
	 * line-by-line.
	 *
	 * @return array<string,array<int,string>>
	 */
	private static function ddl_definitions(): array {
		return [
			self::contact_methods_table() => [
				'id bigint(20) unsigned NOT NULL AUTO_INCREMENT',
				'owner_type varchar(20) NOT NULL',
				'owner_id bigint(20) unsigned NOT NULL',
				'method_type varchar(32) NOT NULL',
				"label varchar(100) NOT NULL DEFAULT ''",
				'value varchar(255) NOT NULL',
				'is_primary tinyint(1) unsigned NOT NULL DEFAULT 0',
				"source varchar(32) NOT NULL DEFAULT 'crm'",
				'sort_order int(11) unsigned NOT NULL DEFAULT 0',
				'created_at datetime NOT NULL',
				'updated_at datetime NOT NULL',
				'PRIMARY KEY  (id)',
				'KEY owner (owner_type,owner_id)',
				'KEY owner_method (owner_type,owner_id,method_type)',
				'KEY value (value(191))',
			],
			self::addresses_table() => [
				'id bigint(20) unsigned NOT NULL AUTO_INCREMENT',
				'owner_type varchar(20) NOT NULL',
				'owner_id bigint(20) unsigned NOT NULL',
				"label varchar(100) NOT NULL DEFAULT ''",
				"address_line_1 varchar(255) NOT NULL DEFAULT ''",
				"address_line_2 varchar(255) NOT NULL DEFAULT ''",
				"postal_code varchar(40) NOT NULL DEFAULT ''",
				"city varchar(120) NOT NULL DEFAULT ''",
				"region varchar(120) NOT NULL DEFAULT ''",
				"country varchar(2) NOT NULL DEFAULT ''",
				'is_primary tinyint(1) unsigned NOT NULL DEFAULT 0',
				'sort_order int(11) unsigned NOT NULL DEFAULT 0',
				'created_at datetime NOT NULL',
				'updated_at datetime NOT NULL',
				'PRIMARY KEY  (id)',
				'KEY owner (owner_type,owner_id)',
				'KEY postal_code (postal_code)',
				'KEY city (city)',
			],
			self::names_table() => [
				'id bigint(20) unsigned NOT NULL AUTO_INCREMENT',
				'owner_type varchar(20) NOT NULL',
				'owner_id bigint(20) unsigned NOT NULL',
				'name varchar(255) NOT NULL',
				"name_type varchar(32) NOT NULL DEFAULT 'alias'",
				'is_primary tinyint(1) unsigned NOT NULL DEFAULT 0',
				'started_at date NULL',
				'ended_at date NULL',
				'sort_order int(11) unsigned NOT NULL DEFAULT 0',
				'created_at datetime NOT NULL',
				'updated_at datetime NOT NULL',
				'PRIMARY KEY  (id)',
				'KEY owner (owner_type,owner_id)',
				'KEY name_lookup (name(191))',
			],
			self::business_identifiers_table() => [
				'id bigint(20) unsigned NOT NULL AUTO_INCREMENT',
				'organization_id bigint(20) unsigned NOT NULL',
				'identifier_type varchar(32) NOT NULL',
				'value varchar(190) NOT NULL',
				"country varchar(2) NOT NULL DEFAULT ''",
				"label varchar(100) NOT NULL DEFAULT ''",
				'is_primary tinyint(1) unsigned NOT NULL DEFAULT 0',
				'sort_order int(11) unsigned NOT NULL DEFAULT 0',
				'created_at datetime NOT NULL',
				'updated_at datetime NOT NULL',
				'PRIMARY KEY  (id)',
				'KEY organization (organization_id)',
				'KEY organization_type (organization_id,identifier_type)',
				'KEY value (value(100))',
			],
			self::organization_relations_table() => [
				'id bigint(20) unsigned NOT NULL AUTO_INCREMENT',
				'contact_id bigint(20) unsigned NOT NULL',
				'organization_id bigint(20) unsigned NOT NULL',
				"role_title varchar(190) NOT NULL DEFAULT ''",
				'is_primary tinyint(1) unsigned NOT NULL DEFAULT 0',
				'started_at date NULL',
				'ended_at date NULL',
				'created_at datetime NOT NULL',
				'updated_at datetime NOT NULL',
				'PRIMARY KEY  (id)',
				'KEY contact (contact_id)',
				'KEY organization (organization_id)',
				'KEY active_contact (contact_id,ended_at)',
			],
			self::service_agreements_table() => [
				'id bigint(20) unsigned NOT NULL AUTO_INCREMENT',
				'work_service_id bigint(20) unsigned NOT NULL',
				'customer_type varchar(20) NOT NULL',
				'customer_id bigint(20) unsigned NOT NULL',
				"status varchar(32) NOT NULL DEFAULT 'active'",
				'valid_from date NULL',
				'valid_until date NULL',
				"pricing_mode varchar(16) NOT NULL DEFAULT 'inherit'",
				'custom_amount_minor bigint(20) unsigned NULL',
				'custom_currency varchar(3) NULL',
				'custom_tax_mode varchar(16) NULL',
				'custom_tax_rate_id bigint(20) unsigned NULL',
				'notes text NULL',
				'created_at datetime NOT NULL',
				'updated_at datetime NOT NULL',
				'PRIMARY KEY  (id)',
				'KEY customer (customer_type,customer_id)',
				'KEY work_service (work_service_id)',
				'KEY status (status)',
				'KEY custom_tax_rate (custom_tax_rate_id)',
			],
			self::document_links_table() => [
				'id bigint(20) unsigned NOT NULL AUTO_INCREMENT',
				'owner_type varchar(20) NOT NULL',
				'owner_id bigint(20) unsigned NOT NULL',
				'document_id bigint(20) unsigned NOT NULL',
				"relation_type varchar(64) NOT NULL DEFAULT ''",
				'notes text NULL',
				'created_at datetime NOT NULL',
				'created_by bigint(20) unsigned NOT NULL DEFAULT 0',
				'PRIMARY KEY  (id)',
				'UNIQUE KEY owner_document (owner_type,owner_id,document_id)',
				'KEY owner (owner_type,owner_id)',
				'KEY document (document_id)',
				'KEY relation_type (relation_type)',
			],
			self::notes_table() => [
				'id bigint(20) unsigned NOT NULL AUTO_INCREMENT',
				'owner_type varchar(20) NOT NULL',
				'owner_id bigint(20) unsigned NOT NULL',
				'author_user_id bigint(20) unsigned NOT NULL DEFAULT 0',
				'body longtext NOT NULL',
				'created_at datetime NOT NULL',
				'updated_at datetime NOT NULL',
				'PRIMARY KEY  (id)',
				'KEY owner_created (owner_type,owner_id,created_at)',
			],
			self::activities_table() => [
				'id bigint(20) unsigned NOT NULL AUTO_INCREMENT',
				'owner_type varchar(20) NOT NULL',
				'owner_id bigint(20) unsigned NOT NULL',
				'event_type varchar(64) NOT NULL',
				"source varchar(64) NOT NULL DEFAULT 'crm'",
				'actor_user_id bigint(20) unsigned NOT NULL DEFAULT 0',
				"reference_type varchar(64) NOT NULL DEFAULT ''",
				"reference_id varchar(190) NOT NULL DEFAULT ''",
				"summary varchar(255) NOT NULL DEFAULT ''",
				'context longtext NULL',
				'created_at datetime NOT NULL',
				'PRIMARY KEY  (id)',
				'KEY owner_created (owner_type,owner_id,created_at)',
				'KEY event_type (event_type)',
				'KEY reference (reference_type,reference_id)',
			],
		];
	}

	private static function repair_default_drift(): void {
		global $wpdb;

		foreach ( self::expected_schema() as $table => $expected ) {
			$actual_columns = self::column_rows( $table );
			if ( [] === $actual_columns ) {
				continue;
			}

			foreach ( $expected['columns'] as $column => $spec ) {
				if ( ! isset( $actual_columns[ $column ] ) ) {
					continue;
				}

				$actual_default = $actual_columns[ $column ]['Default'] ?? null;
				$expected_default = $spec['default'];

				if ( self::defaults_match( $actual_default, $expected_default ) ) {
					continue;
				}

				$table_sql = self::quote_identifier( $table );
				$column_sql = self::quote_identifier( $column );

				if ( null === $expected_default ) {
					$wpdb->query( "ALTER TABLE {$table_sql} ALTER COLUMN {$column_sql} DROP DEFAULT" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					continue;
				}

				$literal = $wpdb->prepare( '%s', $expected_default );
				$wpdb->query( "ALTER TABLE {$table_sql} ALTER COLUMN {$column_sql} SET DEFAULT {$literal}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			}
		}
	}

	private static function schema_is_healthy(): bool {
		foreach ( self::expected_schema() as $table => $expected ) {
			$actual_columns = self::column_rows( $table );
			if ( count( $actual_columns ) < count( $expected['columns'] ) ) {
				return false;
			}

			foreach ( $expected['columns'] as $column => $spec ) {
				if ( ! isset( $actual_columns[ $column ] ) ) {
					return false;
				}

				$actual = $actual_columns[ $column ];
				if ( self::normalize_type( (string) ( $actual['Type'] ?? '' ) ) !== self::normalize_type( $spec['type'] ) ) {
					return false;
				}
				if ( ( 'YES' === strtoupper( (string) ( $actual['Null'] ?? '' ) ) ) !== $spec['nullable'] ) {
					return false;
				}
				if ( ! self::defaults_match( $actual['Default'] ?? null, $spec['default'] ) ) {
					return false;
				}

				$actual_auto_increment = str_contains( strtolower( (string) ( $actual['Extra'] ?? '' ) ), 'auto_increment' );
				if ( $actual_auto_increment !== $spec['auto_increment'] ) {
					return false;
				}
			}

			$actual_indexes = self::index_rows( $table );
			foreach ( $expected['indexes'] as $index_name => $spec ) {
				if ( ! isset( $actual_indexes[ $index_name ] ) ) {
					return false;
				}
				if ( $actual_indexes[ $index_name ]['unique'] !== $spec['unique'] ) {
					return false;
				}
				if ( $actual_indexes[ $index_name ]['columns'] !== $spec['columns'] ) {
					return false;
				}
			}
		}

		return true;
	}

	/**
	 * Build health expectations from the same canonical definitions given to
	 * dbDelta, keeping install and verification on one source of truth.
	 *
	 * @return array<string,array{columns:array<string,array{type:string,nullable:bool,default:?string,auto_increment:bool}>,indexes:array<string,array{unique:bool,columns:array<int,array{name:string,sub_part:?int}>}>}>
	 */
	private static function expected_schema(): array {
		$expected = [];

		foreach ( self::ddl_definitions() as $table => $definitions ) {
			$columns = [];
			$indexes = [];

			foreach ( $definitions as $definition ) {
				if ( preg_match( '/^PRIMARY KEY\s+\((.+)\)$/i', $definition, $match ) ) {
					$indexes['PRIMARY'] = [
						'unique' => true,
						'columns' => self::parse_index_columns( $match[1] ),
					];
					continue;
				}
				if ( preg_match( '/^UNIQUE KEY\s+([a-z0-9_]+)\s+\((.+)\)$/i', $definition, $match ) ) {
					$indexes[ $match[1] ] = [
						'unique' => true,
						'columns' => self::parse_index_columns( $match[2] ),
					];
					continue;
				}
				if ( preg_match( '/^KEY\s+([a-z0-9_]+)\s+\((.+)\)$/i', $definition, $match ) ) {
					$indexes[ $match[1] ] = [
						'unique' => false,
						'columns' => self::parse_index_columns( $match[2] ),
					];
					continue;
				}

				if ( ! preg_match( '/^([a-z0-9_]+)\s+(.+)$/i', $definition, $match ) ) {
					continue;
				}

				$column = $match[1];
				$rest = $match[2];
				$type_parts = preg_split( '/\s+(?=NOT NULL|NULL|DEFAULT|AUTO_INCREMENT)/i', $rest, 2 );
				$type = trim( (string) ( $type_parts[0] ?? '' ) );

				$default = null;
				if ( preg_match( "/\sDEFAULT\s+'([^']*)'/i", $rest, $default_match ) ) {
					$default = $default_match[1];
				} elseif ( preg_match( '/\sDEFAULT\s+([0-9]+)/i', $rest, $default_match ) ) {
					$default = $default_match[1];
				}

				$columns[ $column ] = [
					'type' => $type,
					'nullable' => ! str_contains( strtoupper( $rest ), 'NOT NULL' ),
					'default' => $default,
					'auto_increment' => str_contains( strtoupper( $rest ), 'AUTO_INCREMENT' ),
				];
			}

			$expected[ $table ] = [
				'columns' => $columns,
				'indexes' => $indexes,
			];
		}

		return $expected;
	}

	/**
	 * @return array<int,array{name:string,sub_part:?int}>
	 */
	private static function parse_index_columns( string $columns ): array {
		$parsed = [];

		foreach ( array_map( 'trim', explode( ',', $columns ) ) as $column ) {
			if ( ! preg_match( '/^([a-z0-9_]+)(?:\(([0-9]+)\))?$/i', $column, $match ) ) {
				return [];
			}
			$parsed[] = [
				'name' => $match[1],
				'sub_part' => isset( $match[2] ) ? (int) $match[2] : null,
			];
		}

		return $parsed;
	}

	/**
	 * @return array<string,array<string,mixed>>
	 */
	private static function column_rows( string $table ): array {
		global $wpdb;

		$table_sql = self::quote_identifier( $table );
		$rows = $wpdb->get_results( "SHOW COLUMNS FROM {$table_sql}", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( ! is_array( $rows ) ) {
			return [];
		}

		$indexed = [];
		foreach ( $rows as $row ) {
			$field = (string) ( $row['Field'] ?? '' );
			if ( '' !== $field ) {
				$indexed[ $field ] = $row;
			}
		}

		return $indexed;
	}

	/**
	 * @return array<string,array{unique:bool,columns:array<int,array{name:string,sub_part:?int}>}>
	 */
	private static function index_rows( string $table ): array {
		global $wpdb;

		$table_sql = self::quote_identifier( $table );
		$rows = $wpdb->get_results( "SHOW INDEX FROM {$table_sql}", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( ! is_array( $rows ) ) {
			return [];
		}

		$grouped = [];
		foreach ( $rows as $row ) {
			$key_name = (string) ( $row['Key_name'] ?? '' );
			$column = (string) ( $row['Column_name'] ?? '' );
			$sequence = (int) ( $row['Seq_in_index'] ?? 0 );

			if ( '' === $key_name || '' === $column || $sequence < 1 ) {
				continue;
			}

			if ( ! isset( $grouped[ $key_name ] ) ) {
				$grouped[ $key_name ] = [
					'unique' => 0 === (int) ( $row['Non_unique'] ?? 1 ),
					'columns' => [],
				];
			}

			$sub_part = $row['Sub_part'] ?? null;
			$grouped[ $key_name ]['columns'][ $sequence ] = [
				'name' => $column,
				'sub_part' => null === $sub_part ? null : (int) $sub_part,
			];
		}

		foreach ( $grouped as &$index ) {
			ksort( $index['columns'] );
			$index['columns'] = array_values( $index['columns'] );
		}
		unset( $index );

		return $grouped;
	}

	private static function defaults_match( mixed $actual, ?string $expected ): bool {
		if ( null === $expected ) {
			return null === $actual || 'NULL' === strtoupper( (string) $actual );
		}

		return (string) $actual === $expected;
	}

	private static function normalize_type( string $type ): string {
		$type = strtolower( trim( preg_replace( '/\s+/', ' ', $type ) ?? $type ) );
		return (string) preg_replace( '/\b(bigint|int|tinyint)\([0-9]+\)/', '$1', $type );
	}

	private static function quote_identifier( string $identifier ): string {
		return '`' . str_replace( '`', '``', $identifier ) . '`';
	}
}

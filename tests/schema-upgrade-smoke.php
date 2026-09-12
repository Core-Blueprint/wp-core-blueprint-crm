<?php
declare(strict_types=1);

$root = dirname( __DIR__ );
$failed = false;

$fixture_root = sys_get_temp_dir() . '/cb-crm-schema-' . bin2hex( random_bytes( 6 ) ) . '/';
$upgrade_dir = $fixture_root . 'wp-admin/includes';
if ( ! mkdir( $upgrade_dir, 0777, true ) && ! is_dir( $upgrade_dir ) ) {
	fwrite( STDERR, "Could not create dbDelta fixture directory.\n" );
	exit( 1 );
}

$upgrade_php = <<<'PHP'
<?php
function dbDelta( string $sql ): array {
	global $wpdb;
	$wpdb->apply_dbdelta( $sql );
	return [];
}
PHP;
file_put_contents( $upgrade_dir . '/upgrade.php', $upgrade_php );

define( 'ABSPATH', $fixture_root );
define( 'ARRAY_A', 'ARRAY_A' );

final class CRM_Schema_Fake_Wpdb {
	public string $prefix = 'wp_';

	/** @var array<string,array{columns:array<string,array<string,mixed>>,indexes:array<string,array{unique:bool,columns:array<int,array{name:string,sub_part:?int}>}>}> */
	public array $tables = [];

	/** @var array<int,string> */
	public array $repair_queries = [];

	public string $data_marker = 'preserve-me';

	/** @var array<string,bool> */
	public array $blocked_type_repairs = [];

	public function get_charset_collate(): string {
		return 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci';
	}

	public function prepare( string $query, mixed ...$args ): string {
		if ( '%s' !== $query || 1 !== count( $args ) ) {
			throw new RuntimeException( 'Unexpected prepare() call in schema smoke.' );
		}
		return "'" . str_replace( "'", "''", (string) $args[0] ) . "'";
	}

	/**
	 * Minimal dbDelta behavior model for this regression:
	 * - canonical field/index definitions are applied;
	 * - an existing default is preserved when the desired field has no DEFAULT,
	 *   matching the legacy drift case that requires explicit repair.
	 */
	public function apply_dbdelta( string $sql ): void {
		if ( ! preg_match( '/^CREATE TABLE ([^\s(]+) \(\n  (.+)\n\) /s', $sql, $match ) ) {
			throw new RuntimeException( 'Malformed CREATE TABLE statement passed to dbDelta.' );
		}

		$table = $match[1];
		$definitions = explode( ",\n  ", $match[2] );
		$existing = $this->tables[ $table ] ?? [ 'columns' => [], 'indexes' => [] ];
		$columns = $existing['columns'];
		$indexes = $existing['indexes'];

		foreach ( $definitions as $definition ) {
			if ( preg_match( '/^PRIMARY KEY\s+\((.+)\)$/i', $definition, $index_match ) ) {
				$indexes['PRIMARY'] = [
					'unique' => true,
					'columns' => $this->parse_index_columns( $index_match[1] ),
				];
				continue;
			}
			if ( preg_match( '/^UNIQUE KEY\s+([a-z0-9_]+)\s+\((.+)\)$/i', $definition, $index_match ) ) {
				$indexes[ $index_match[1] ] = [
					'unique' => true,
					'columns' => $this->parse_index_columns( $index_match[2] ),
				];
				continue;
			}
			if ( preg_match( '/^KEY\s+([a-z0-9_]+)\s+\((.+)\)$/i', $definition, $index_match ) ) {
				$indexes[ $index_match[1] ] = [
					'unique' => false,
					'columns' => $this->parse_index_columns( $index_match[2] ),
				];
				continue;
			}

			if ( ! preg_match( '/^([a-z0-9_]+)\s+(.+)$/i', $definition, $field_match ) ) {
				throw new RuntimeException( "Unparseable field definition: {$definition}" );
			}

			$name = $field_match[1];
			$rest = $field_match[2];
			$type_parts = preg_split( '/\s+(?=NOT NULL|NULL|DEFAULT|AUTO_INCREMENT)/i', $rest, 2 );
			$type = trim( (string) ( $type_parts[0] ?? '' ) );
			$default_is_explicit = false;
			$default = $columns[ $name ]['Default'] ?? null;

			if ( preg_match( "/\sDEFAULT\s+'([^']*)'/i", $rest, $default_match ) ) {
				$default = $default_match[1];
				$default_is_explicit = true;
			} elseif ( preg_match( '/\sDEFAULT\s+([0-9]+)/i', $rest, $default_match ) ) {
				$default = $default_match[1];
				$default_is_explicit = true;
			}

			if ( ! isset( $columns[ $name ] ) && ! $default_is_explicit ) {
				$default = null;
			}

			$type_key = $table . '.' . $name;
			$actual_type = isset( $this->blocked_type_repairs[ $type_key ], $columns[ $name ] )
				? (string) $columns[ $name ]['Type']
				: $type;

			$columns[ $name ] = [
				'Field' => $name,
				'Type' => $actual_type,
				'Null' => str_contains( strtoupper( $rest ), 'NOT NULL' ) ? 'NO' : 'YES',
				'Default' => $default,
				'Extra' => str_contains( strtoupper( $rest ), 'AUTO_INCREMENT' ) ? 'auto_increment' : '',
			];
		}

		$this->tables[ $table ] = [
			'columns' => $columns,
			'indexes' => $indexes,
		];
	}

	public function get_results( string $sql, string $output ): array {
		unset( $output );

		if ( preg_match( '/^SHOW COLUMNS FROM `([^`]+)`$/', $sql, $match ) ) {
			return array_values( $this->tables[ $match[1] ]['columns'] ?? [] );
		}

		if ( preg_match( '/^SHOW INDEX FROM `([^`]+)`$/', $sql, $match ) ) {
			$rows = [];
			foreach ( $this->tables[ $match[1] ]['indexes'] ?? [] as $name => $index ) {
				foreach ( $index['columns'] as $offset => $column ) {
					$rows[] = [
						'Key_name' => $name,
						'Seq_in_index' => $offset + 1,
						'Column_name' => $column['name'],
						'Sub_part' => $column['sub_part'],
						'Non_unique' => $index['unique'] ? 0 : 1,
					];
				}
			}
			return $rows;
		}

		throw new RuntimeException( "Unexpected get_results() query: {$sql}" );
	}

	public function query( string $sql ): int|false {
		if ( ! preg_match( '/^ALTER TABLE `([^`]+)` ALTER COLUMN `([^`]+)` (DROP DEFAULT|SET DEFAULT (.+))$/', $sql, $match ) ) {
			throw new RuntimeException( "Unexpected repair query: {$sql}" );
		}

		$table = $match[1];
		$column = $match[2];
		if ( ! isset( $this->tables[ $table ]['columns'][ $column ] ) ) {
			return false;
		}

		$this->repair_queries[] = $sql;

		if ( 'DROP DEFAULT' === $match[3] ) {
			$this->tables[ $table ]['columns'][ $column ]['Default'] = null;
			return 1;
		}

		$literal = (string) $match[4];
		if ( strlen( $literal ) < 2 || "'" !== $literal[0] || "'" !== $literal[ strlen( $literal ) - 1 ] ) {
			return false;
		}
		$value = substr( $literal, 1, -1 );
		$this->tables[ $table ]['columns'][ $column ]['Default'] = str_replace( "''", "'", $value );
		return 1;
	}

	/**
	 * @return array<int,array{name:string,sub_part:?int}>
	 */
	private function parse_index_columns( string $columns ): array {
		$parsed = [];
		foreach ( array_map( 'trim', explode( ',', $columns ) ) as $column ) {
			if ( ! preg_match( '/^([a-z0-9_]+)(?:\(([0-9]+)\))?$/i', $column, $match ) ) {
				throw new RuntimeException( "Unparseable index column: {$column}" );
			}
			$parsed[] = [
				'name' => $match[1],
				'sub_part' => isset( $match[2] ) ? (int) $match[2] : null,
			];
		}
		return $parsed;
	}
}

require_once $root . '/src/Database/Schema.php';

$fail = static function ( string $message ) use ( &$failed ): void {
	fwrite( STDERR, $message . "\n" );
	$failed = true;
};

$wpdb = new CRM_Schema_Fake_Wpdb();
$GLOBALS['wpdb'] = $wpdb;

// Fresh install must build a healthy canonical schema.
if ( true !== \CB\CRM\Database\Schema::install() ) {
	$fail( 'Fresh schema install did not satisfy postconditions.' );
}
if ( 9 !== count( $wpdb->tables ) ) {
	$fail( 'Fresh schema install did not create all nine CRM tables.' );
}
if ( [] !== $wpdb->repair_queries ) {
	$fail( 'Fresh install unexpectedly required legacy default repair.' );
}

// Reproduce the silent defaults that the old multi-definition dbDelta input
// could infer from a later field on the same source line.
$wpdb->tables['wp_cb_crm_contact_methods']['columns']['method_type']['Default'] = '';
$wpdb->tables['wp_cb_crm_names']['columns']['name']['Default'] = 'alias';
$wpdb->tables['wp_cb_crm_document_links']['columns']['document_id']['Default'] = '';
$wpdb->tables['wp_cb_crm_activities']['columns']['event_type']['Default'] = 'crm';

// Also remove an expected index so the canonical dbDelta pass must restore it.
unset( $wpdb->tables['wp_cb_crm_document_links']['indexes']['document'] );

$wpdb->repair_queries = [];
$before_marker = $wpdb->data_marker;

if ( true !== \CB\CRM\Database\Schema::install() ) {
	$fail( 'Legacy 1.4-style drift did not reconcile to a healthy schema.' );
}

$repaired_defaults = [
	$wpdb->tables['wp_cb_crm_contact_methods']['columns']['method_type']['Default'],
	$wpdb->tables['wp_cb_crm_names']['columns']['name']['Default'],
	$wpdb->tables['wp_cb_crm_document_links']['columns']['document_id']['Default'],
	$wpdb->tables['wp_cb_crm_activities']['columns']['event_type']['Default'],
];
if ( [ null, null, null, null ] !== $repaired_defaults ) {
	$fail( 'Legacy default drift was not repaired in place.' );
}
if ( count( $wpdb->repair_queries ) < 4 ) {
	$fail( 'Expected explicit default repairs were not issued.' );
}
if ( ! isset( $wpdb->tables['wp_cb_crm_document_links']['indexes']['document'] ) ) {
	$fail( 'Missing document index was not restored by canonical reconciliation.' );
}
if ( $before_marker !== $wpdb->data_marker ) {
	$fail( 'Schema reconciliation mutated the data preservation marker.' );
}

// A second installer pass must be idempotent: same schema, no repair ALTERs.
$schema_after_upgrade = serialize( $wpdb->tables );
$wpdb->repair_queries = [];

if ( true !== \CB\CRM\Database\Schema::install() ) {
	$fail( 'Second schema reconciliation did not remain healthy.' );
}
if ( [] !== $wpdb->repair_queries ) {
	$fail( 'Second schema reconciliation issued unexpected repair ALTERs.' );
}
if ( $schema_after_upgrade !== serialize( $wpdb->tables ) ) {
	$fail( 'Second schema reconciliation changed an already-canonical schema.' );
}

// Postconditions must fail closed if canonical schema cannot be reached.
$blocked_key = 'wp_cb_crm_contact_methods.method_type';
$wpdb->tables['wp_cb_crm_contact_methods']['columns']['method_type']['Type'] = 'varchar(7)';
$wpdb->blocked_type_repairs[ $blocked_key ] = true;
if ( false !== \CB\CRM\Database\Schema::install() ) {
	$fail( 'Installer reported success while a canonical column type remained unrepaired.' );
}
unset( $wpdb->blocked_type_repairs[ $blocked_key ] );

@unlink( $upgrade_dir . '/upgrade.php' );
@rmdir( $upgrade_dir );
@rmdir( dirname( $upgrade_dir ) );
@rmdir( dirname( dirname( $upgrade_dir ) ) );
@rmdir( $fixture_root );

exit( $failed ? 1 : 0 );

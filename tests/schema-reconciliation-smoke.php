<?php
declare(strict_types=1);

$root = dirname( __DIR__ );
$failed = false;

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', $root . '/' );
}

$GLOBALS['wpdb'] = (object) [ 'prefix' => 'wp_' ];

require_once $root . '/src/Database/Schema.php';

$schema_class = \CB\CRM\Database\Schema::class;

$ddl_method = new ReflectionMethod( $schema_class, 'ddl_definitions' );
$definitions = $ddl_method->invoke( null );

$expect_method = new ReflectionMethod( $schema_class, 'expected_schema' );
$expectations = $expect_method->invoke( null );

$fail = static function ( string $message ) use ( &$failed ): void {
	fwrite( STDERR, $message . "\n" );
	$failed = true;
};

if ( 9 !== count( $definitions ) ) {
	$fail( 'Expected exactly nine CRM-owned table definitions.' );
}

$expected_tables = [
	'wp_cb_crm_contact_methods',
	'wp_cb_crm_addresses',
	'wp_cb_crm_names',
	'wp_cb_crm_business_identifiers',
	'wp_cb_crm_org_relations',
	'wp_cb_crm_service_agreements',
	'wp_cb_crm_document_links',
	'wp_cb_crm_notes',
	'wp_cb_crm_activities',
];

if ( array_keys( $definitions ) !== $expected_tables ) {
	$fail( 'Canonical CRM table set/order changed unexpectedly.' );
}

$has_top_level_comma = static function ( string $definition ): bool {
	$depth = 0;
	$length = strlen( $definition );

	for ( $i = 0; $i < $length; $i++ ) {
		$char = $definition[ $i ];
		if ( '(' === $char ) {
			$depth++;
			continue;
		}
		if ( ')' === $char ) {
			$depth = max( 0, $depth - 1 );
			continue;
		}
		if ( ',' === $char && 0 === $depth ) {
			return true;
		}
	}

	return false;
};

foreach ( $definitions as $table => $table_definitions ) {
	if ( [] === $table_definitions ) {
		$fail( "No definitions declared for {$table}." );
		continue;
	}

	foreach ( $table_definitions as $definition ) {
		if ( str_contains( $definition, "\n" ) || str_contains( $definition, "\r" ) ) {
			$fail( "Definition for {$table} contains an embedded newline: {$definition}" );
		}
		if ( $has_top_level_comma( $definition ) ) {
			$fail( "Definition for {$table} contains multiple top-level dbDelta definitions: {$definition}" );
		}
	}

	if ( ! isset( $expectations[ $table ] ) ) {
		$fail( "No health expectations derived for {$table}." );
		continue;
	}

	if ( [] === $expectations[ $table ]['columns'] ) {
		$fail( "No column expectations derived for {$table}." );
	}
	if ( ! isset( $expectations[ $table ]['indexes']['PRIMARY'] ) ) {
		$fail( "Primary key expectation missing for {$table}." );
	}
}

$drift_sensitive_defaults = [
	'wp_cb_crm_contact_methods' => [ 'method_type' => null ],
	'wp_cb_crm_names' => [ 'name' => null ],
	'wp_cb_crm_document_links' => [ 'document_id' => null ],
	'wp_cb_crm_activities' => [ 'event_type' => null ],
];

foreach ( $drift_sensitive_defaults as $table => $columns ) {
	foreach ( $columns as $column => $expected_default ) {
		if ( ! isset( $expectations[ $table ]['columns'][ $column ] ) ) {
			$fail( "Drift-sensitive column {$table}.{$column} is missing." );
			continue;
		}
		$actual = $expectations[ $table ]['columns'][ $column ]['default'];
		if ( $actual !== $expected_default ) {
			$fail( "Drift-sensitive default for {$table}.{$column} is not canonical." );
		}
	}
}

$document_indexes = $expectations['wp_cb_crm_document_links']['indexes'] ?? [];
if (
	! isset( $document_indexes['owner_document'] )
	|| true !== $document_indexes['owner_document']['unique']
	|| [
		[ 'name' => 'owner_type', 'sub_part' => null ],
		[ 'name' => 'owner_id', 'sub_part' => null ],
		[ 'name' => 'document_id', 'sub_part' => null ],
	] !== $document_indexes['owner_document']['columns']
) {
	$fail( 'Document-link unique index expectation is not canonical.' );
}

$schema_source = file_get_contents( $root . '/src/Database/Schema.php' );
$bootstrap = file_get_contents( $root . '/core-blueprint-crm.php' );
$tools_check = file_get_contents( $root . '/tools/check' );

$source_checks = [
	'schema version is 1.5' => str_contains( $bootstrap, "CB_CRM_SCHEMA_VERSION', '1.5'" ),
	'installer repairs legacy default drift' => str_contains( $schema_source, 'self::repair_default_drift();' ),
	'installer fails closed on postcondition drift' => str_contains( $schema_source, 'return self::schema_is_healthy();' ),
	'health verification checks columns' => str_contains( $schema_source, 'SHOW COLUMNS FROM' ),
	'health verification checks indexes' => str_contains( $schema_source, 'SHOW INDEX FROM' ),
	'canonical DDL is newline joined for dbDelta' => str_contains( $schema_source, 'implode( ",\n  ", $definitions )' ),
	'no destructive table repair' => ! preg_match( '/\b(?:DROP\s+TABLE|TRUNCATE\s+TABLE|DELETE\s+FROM)\b/i', $schema_source ),
	'permanent schema smoke is wired into tools/check' => str_contains( $tools_check, 'schema-reconciliation-smoke.php' ),
];

foreach ( $source_checks as $label => $passed ) {
	if ( ! $passed ) {
		$fail( "Schema reconciliation conformance failed: {$label}" );
	}
}

exit( $failed ? 1 : 0 );

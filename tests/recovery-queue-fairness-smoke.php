<?php
declare(strict_types=1);

define( 'ABSPATH', dirname( __DIR__ ) . '/' );
define( 'ARRAY_A', 'ARRAY_A' );

$GLOBALS['scanner_options'] = [];
$GLOBALS['scanner_next_id'] = 1;
$GLOBALS['scanner_read_error'] = false;
$GLOBALS['scanner_write_error'] = false;

function get_option( string $key, mixed $default = false ): mixed {
	return isset( $GLOBALS['scanner_options'][ $key ] ) ? $GLOBALS['scanner_options'][ $key ]['value'] : $default;
}
function update_option( string $key, mixed $value, bool $autoload = false ): bool {
	if ( $GLOBALS['scanner_write_error'] ) { return false; }
	if ( isset( $GLOBALS['scanner_options'][ $key ] ) ) {
		$GLOBALS['scanner_options'][ $key ]['value'] = $value;
		return true;
	}
	$GLOBALS['scanner_options'][ $key ] = [ 'id' => $GLOBALS['scanner_next_id']++, 'value' => $value ];
	return true;
}
final class CRM_Fair_Recovery_Db {
	public string $options = 'wp_options';
	public string $last_error = '';
	public function esc_like( string $value ): string { return str_replace( [ '_', '%' ], [ '\\_', '\\%' ], $value ); }
	public function prepare( string $sql, mixed ...$params ): string {
		$i = 0;
		$value = preg_replace_callback( '/%[ds]/', static function ( array $match ) use ( $params, &$i ): string {
			$v = $params[ $i++ ] ?? null;
			return '%d' === $match[0] ? (string) (int) $v : "'" . str_replace( "'", "''", (string) $v ) . "'";
		}, $sql );
		if ( ! is_string( $value ) || $i !== count( $params ) ) {
			throw new \RuntimeException( 'Incorrect SQL binding' );
		}
		return $value;
	}
	public function get_results( string $sql, string $format ): ?array {
		$this->last_error = '';
		if ( $GLOBALS['scanner_read_error'] ) {
			$this->last_error = 'injected_sql_error';
			return null;
		}
		if ( ARRAY_A !== $format || ! preg_match( "/option_id > (\\d+) AND option_name LIKE '([^']+)'\\s+ORDER BY option_id ASC LIMIT (\\d+)/s", $sql, $m ) ) {
			throw new \RuntimeException( 'Unbounded or non-keyset recovery scan' );
		}
		$prefix = str_replace( '\\_', '_', rtrim( $m[2], '%' ) );
		$rows = [];
		foreach ( $GLOBALS['scanner_options'] as $name => $entry ) {
			if ( str_starts_with( $name, $prefix ) && $entry['id'] > (int) $m[1] ) {
				$rows[] = [ 'option_id' => $entry['id'], 'option_name' => $name ];
			}
		}
		usort( $rows, static fn ( array $a, array $b ): int => $a['option_id'] <=> $b['option_id'] );
		return array_slice( $rows, 0, (int) $m[3] );
	}
}
$GLOBALS['wpdb'] = new CRM_Fair_Recovery_Db();

require dirname( __DIR__ ) . '/src/Repository/RecoveryQueueScanner.php';

use CB\CRM\Repository\RecoveryQueueScanner;
$assert = static function ( bool $ok, string $msg ): void {
	if ( ! $ok ) {
		fwrite( STDERR, 'CRM recovery queue fairness smoke FAIL: ' . $msg . "\n" );
		exit( 1 );
	}
};

// Seed malformed first-slot entries plus enough valid intents for > 2 pages.
// These options are NEVER deleted or changed by the scanner itself.
foreach ( [ 'identity' => 'cb_crm_delete_intent_', 'user' => 'cb_crm_user_anonymize_intent_' ] as $queue => $prefix ) {
	for ( $n = 1; $n <= 45; ++$n ) {
		$GLOBALS['scanner_options'][ $prefix . $n ] = [
			'id' => $GLOBALS['scanner_next_id']++,
			'value' => ( 1 === $n ? [ 'unexpected' => 'malformed' ] : [ 'version' => 1, 'id' => $n ] ),
		];
	}
}
foreach ( [ 'identity', 'user' ] as $queue ) {
	$prefix = 'identity' === $queue ? 'cb_crm_delete_intent_' : 'cb_crm_user_anonymize_intent_';
	$first = RecoveryQueueScanner::next_names( $queue );
	$assert( 20 === count( $first ) && $first[0] === $prefix . '1', 'first page includes malformed oldest item but remains bounded' );
	$second = RecoveryQueueScanner::next_names( $queue );
	$assert( 20 === count( $second ) && $second[0] === $prefix . '21', 'cursor advances beyond oldest twenty' );
	$third = RecoveryQueueScanner::next_names( $queue );
	$assert( 5 === count( $third ) && $third[4] === $prefix . '45', 'final five options are eventually scheduled' );
	$wrapped = RecoveryQueueScanner::next_names( $queue );
	$assert( 20 === count( $wrapped ) && $wrapped[0] === $prefix . '1', 'exhausted queue wraps safely to oldest item' );
	$assert( isset( $GLOBALS['scanner_options'][ $prefix . '1' ] ), 'malformed intent not discarded by scanning' );
}
$GLOBALS['scanner_read_error'] = true;
$assert( [] === RecoveryQueueScanner::next_names( 'identity' ), 'DB error fails closed without selecting intents' );
$GLOBALS['scanner_read_error'] = false;
$GLOBALS['scanner_write_error'] = true;
$previous = get_option( 'cb_crm_recovery_cursor_identity', 0 );
$assert( [] === RecoveryQueueScanner::next_names( 'identity' ), 'cursor write failure refuses to schedule partial page' );
$assert( $previous === get_option( 'cb_crm_recovery_cursor_identity', 0 ), 'failed cursor write must not claim progress' );
$GLOBALS['scanner_write_error'] = false;
$GLOBALS['scanner_options']['cb_crm_recovery_cursor_user']['value'] = [ 'corrupt' => true ];
$assert( [] === RecoveryQueueScanner::next_names( 'user' ), 'corrupt queue cursor fails closed' );
$assert( [] === RecoveryQueueScanner::next_names( 'unknown' ), 'unregistered queue refused' );

echo "CRM bounded recovery queue fair-cursor smoke passed.\n";

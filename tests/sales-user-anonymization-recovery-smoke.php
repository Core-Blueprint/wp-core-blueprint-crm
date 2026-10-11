<?php
declare(strict_types=1);

namespace CB\CRM\Database {
	final class Schema { public const OPTION = 'cb_crm_db_version'; }
}
namespace CB\CRM {
	final class Governance {
		public static array $failures = [];
		public static function record_cleanup_failed( string $kind, int $id ): void {
			self::$failures[] = [ $kind, $id ];
		}
	}
}
namespace CB\CRM\Repository\Sales {
	final class LifecycleCleanup {
		public static bool $fail = false;
		public static array $calls = [];
		public static function for_deleted_user( int $id ): bool {
			self::$calls[] = $id;
			return ! self::$fail;
		}
	}
}
namespace {
	define( 'ABSPATH', dirname( __DIR__ ) . '/' );
	define( 'ARRAY_A', 'ARRAY_A' );
	define( 'CB_CRM_SCHEMA_VERSION', '1.6' );
	$GLOBALS['recovery_user_opts'] = [];
	$GLOBALS['recovery_user_cron'] = [];
	$GLOBALS['recovery_user_hooks'] = [];
	$GLOBALS['recovery_user_fail_add'] = false;
	$GLOBALS['recovery_user_fail_schedule'] = false;
	$GLOBALS['recovery_user_fail_delete'] = false;
	$GLOBALS['recovery_user_multisite'] = false;
	$GLOBALS['recovery_user_schema'] = '1.6';
	$GLOBALS['recovery_user_admin'] = false;

	function is_multisite(): bool { return $GLOBALS['recovery_user_multisite']; }
	function get_option( string $key, mixed $default = false ): mixed {
		if ( 'cb_crm_db_version' === $key ) { return $GLOBALS['recovery_user_schema']; }
		return $GLOBALS['recovery_user_opts'][ $key ] ?? $default;
	}
	function update_option( string $key, mixed $value, bool $autoload = false ): bool {
		$GLOBALS['recovery_user_opts'][ $key ] = $value;
		return true;
	}
	function add_option( string $key, mixed $value, string $deprecated = '', bool $autoload = false ): bool {
		if ( $autoload || $GLOBALS['recovery_user_fail_add'] || isset( $GLOBALS['recovery_user_opts'][ $key ] ) ) { return false; }
		$GLOBALS['recovery_user_opts'][ $key ] = $value;
		return true;
	}
	function delete_option( string $key ): bool {
		if ( $GLOBALS['recovery_user_fail_delete'] || ! isset( $GLOBALS['recovery_user_opts'][ $key ] ) ) { return false; }
		unset( $GLOBALS['recovery_user_opts'][ $key ] );
		return true;
	}
	function wp_next_scheduled( string $hook, array $args = [] ): int|false {
		return $GLOBALS['recovery_user_cron'][ $hook . ':' . ( $args[0] ?? 0 ) ] ?? false;
	}
	function wp_schedule_single_event( int $when, string $hook, array $args = [] ): bool {
		if ( $GLOBALS['recovery_user_fail_schedule'] ) { return false; }
		$GLOBALS['recovery_user_cron'][ $hook . ':' . ( $args[0] ?? 0 ) ] = $when;
		return true;
	}
	function add_action( string $name, callable $callback, int $priority = 10, int $argc = 1 ): void {
		$GLOBALS['recovery_user_hooks'][ $name ] = [ $callback, $priority, $argc ];
	}
	function current_user_can( string $cap ): bool {
		return 'cb_manage_crm' === $cap && $GLOBALS['recovery_user_admin'];
	}
	final class CRM_Deletion_Blocked extends \RuntimeException {}
	function wp_die( string $message, string $title = '', array $args = [] ): never {
		throw new CRM_Deletion_Blocked( $message );
	}
	final class CRM_User_Recovery_Db {
		public string $users = 'wp_users';
		public string $options = 'wp_options';
		public string $last_error = '';
		public array $existing = [ 17 => true, 22 => true, 44 => true ];
		public bool $fail_lookup = false;
		public function prepare( string $sql, mixed ...$args ): string {
			$i = 0;
			return preg_replace_callback( '/%[ds]/', static function ( array $m ) use ( $args, &$i ): string {
				$v = $args[ $i++ ] ?? null;
				return '%d' === $m[0] ? (string) (int) $v : "'" . (string) $v . "'";
			}, $sql ) ?? '';
		}
		public function get_var( string $sql ): ?int {
			if ( ! preg_match( '/SELECT ID FROM wp_users WHERE ID = ([0-9]+) LIMIT 1/', $sql, $m ) ) {
				throw new \RuntimeException( 'Unexpected user read SQL: ' . $sql );
			}
			if ( $this->fail_lookup ) { $this->last_error = 'injected_user_db_failure'; return null; }
			return isset( $this->existing[ (int) $m[1] ] ) ? (int) $m[1] : null;
		}
		public function esc_like( string $value ): string { return $value; }
		public function get_results( string $sql, string $format ): ?array {
			if ( 'ARRAY_A' !== $format
				|| ! preg_match( '/option_id > ([0-9]+).*LIMIT 20/s', $sql, $match )
				|| ! str_contains( $sql, 'ORDER BY option_id ASC' )
				|| ! str_contains( $sql, 'cb_crm_' ) ) {
				throw new \RuntimeException( 'Unexpected cursor-based recovery SQL' );
			}
			$rows = [];
			$next_id = 1;
			foreach ( $GLOBALS['recovery_user_opts'] as $name => $payload ) {
				if ( $next_id > (int) $match[1] && str_starts_with( $name, 'cb_crm_user_anonymize_intent_' ) ) {
					$rows[] = [ 'option_id' => $next_id, 'option_name' => $name ];
				}
				++$next_id;
			}
			return array_slice( $rows, 0, 20 );
		}

		public function get_col( string $sql ): array {
			if ( ! str_contains( $sql, 'LIMIT 20' ) ) { throw new \RuntimeException( 'Unbounded admin recovery lookup' ); }
			return array_slice(
				array_keys( array_filter(
					$GLOBALS['recovery_user_opts'],
					static fn ( string $name ): bool => str_starts_with( $name, 'cb_crm_user_anonymize_intent_' ),
					ARRAY_FILTER_USE_KEY
				) ), 0, 20
			);
		}
	}
	$GLOBALS['wpdb'] = new CRM_User_Recovery_Db();
	require dirname( __DIR__ ) . '/src/Repository/RecoveryQueueScanner.php';
	require dirname( __DIR__ ) . '/src/Repository/UserAnonymizationRecovery.php';

	use CB\CRM\Repository\UserAnonymizationRecovery as Recovery;
	use CB\CRM\Repository\Sales\LifecycleCleanup as Cleanup;
	use CB\CRM\Governance;
	$assert = static function ( bool $true, string $message ): void {
		if ( ! $true ) { fwrite( STDERR, 'User anonymization recovery smoke FAIL: ' . $message . "\n" ); exit( 1 ); }
	};
	$key = static fn ( int $id ): string => 'cb_crm_user_anonymize_intent_' . $id;

	Recovery::init();
	foreach ( [ 'delete_user', 'wpmu_delete_user', 'cb_crm_user_anonymize_reconcile', 'admin_init' ] as $hook ) {
		$assert( isset( $GLOBALS['recovery_user_hooks'][ $hook ] ), 'missing required hook ' . $hook );
	}
	$assert( ! isset( $GLOBALS['recovery_user_hooks']['deleted_user'] ), 'only canonical Lifecycle must own deleted_user hook' );

	$GLOBALS['recovery_user_fail_add'] = true;
	try {
		Recovery::before_delete( 17 );
		$assert( false, 'user deletion not blocked without durable option' );
	} catch ( CRM_Deletion_Blocked ) {}
	$assert( ! isset( $GLOBALS['recovery_user_opts'][ $key( 17 ) ] ), 'failed option did not create intent' );
	$GLOBALS['recovery_user_fail_add'] = false;
	$GLOBALS['recovery_user_fail_schedule'] = true;
	try {
		Recovery::before_delete( 22 );
		$assert( false, 'user deletion not blocked without scheduled retry' );
	} catch ( CRM_Deletion_Blocked ) {}
	$assert( isset( $GLOBALS['recovery_user_opts'][ $key( 22 ) ] ), 'schedule failure retains intent' );
	$GLOBALS['recovery_user_fail_schedule'] = false;
	Recovery::before_delete( 17 );
	$assert( isset( $GLOBALS['recovery_user_opts'][ $key( 17 ) ] ), 'pre-delete intent not saved' );
	$assert( Recovery::retry( 17 ), 'living WordPress user is not rewritten' );
	$assert( [] === Cleanup::$calls, 'living user is never anonymized' );

	unset( $GLOBALS['wpdb']->existing[17] );
	$GLOBALS['wpdb']->fail_lookup = true;
	$assert( false === Recovery::retry( 17 ), 'SQL user read failure fails closed' );
	$assert( [] === Cleanup::$calls, 'read failure skips mutations' );
	$GLOBALS['wpdb']->fail_lookup = false;

	Cleanup::$fail = true;
	$assert( false === Recovery::retry( 17 ), 'failed anonymization returns false' );
	$assert( isset( $GLOBALS['recovery_user_opts'][ $key( 17 ) ] ), 'failed SQL retains durable intent' );
	Cleanup::$fail = false;

	$GLOBALS['recovery_user_fail_delete'] = true;
	$assert( false === Recovery::retry( 17 ), 'failed acknowledgement remains pending' );
	$assert( isset( $GLOBALS['recovery_user_opts'][ $key( 17 ) ] ), 'acknowledgement failure keeps intent' );
	$GLOBALS['recovery_user_fail_delete'] = false;
	$assert( Recovery::retry( 17 ), 'SQL recovery completes after transient error' );
	$assert( ! isset( $GLOBALS['recovery_user_opts'][ $key( 17 ) ] ), 'successful replay clears durable intent' );
	$calls = count( Cleanup::$calls );
	$assert( Recovery::retry( 17 ), 'duplicate callback is idempotent' );
	$assert( $calls === count( Cleanup::$calls ), 'duplicate retry avoids additional updates' );
	$assert( ! in_array( 44, Cleanup::$calls, true ), 'unrelated user untouched' );

	$GLOBALS['recovery_user_opts'][ $key( 99 ) ] = [ 'version' => 1, 'user_id' => 22 ];
	$assert( false === Recovery::retry( 99 ), 'corrupt intent cannot overwrite another user' );

	$GLOBALS['recovery_user_admin'] = true;
	unset( $GLOBALS['recovery_user_cron']['cb_crm_user_anonymize_reconcile:22'] );
	Recovery::reschedule_pending();
	$assert( false !== wp_next_scheduled( 'cb_crm_user_anonymize_reconcile', [ 22 ] ), 'admin can restore lost schedule' );

	$GLOBALS['recovery_user_multisite'] = true;
	Recovery::before_delete( 44 );
	$assert( ! isset( $GLOBALS['recovery_user_opts'][ $key( 44 ) ] ), 'unsupported multisite must not silently rewrite network IDs' );
	$GLOBALS['recovery_user_multisite'] = false;
	$GLOBALS['recovery_user_schema'] = '1.5';
	try {
		Recovery::before_delete( 44 );
		$assert( false, 'schema 1.5 marker must block user deletion on 1.6 source' );
	} catch ( CRM_Deletion_Blocked ) {}
	$assert( ! isset( $GLOBALS['recovery_user_opts'][ $key( 44 ) ] ), 'incomplete schema did not invent intent' );
	$assert( Governance::$failures !== [], 'failure evidence is recorded' );

	echo "CRM Sales user anonymization recovery smoke passed.\n";
}

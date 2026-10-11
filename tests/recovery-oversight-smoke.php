<?php
declare(strict_types=1);

namespace {
	define( 'ABSPATH', dirname( __DIR__ ) . '/' );
	define( 'ARRAY_A', 'ARRAY_A' );

	final class WP_Error {
		public function __construct( private string $code, private string $message ) {}
		public function get_error_message(): string { return $this->message; }
	}
	function is_wp_error( mixed $value ): bool { return $value instanceof WP_Error; }
	final class WP_CLI {
		public static array $output = [];
		public static function line( string $text ): void { self::$output[] = $text; }
		public static function success( string $text ): void { self::$output[] = $text; }
		public static function error( string $message ): never { throw new \RuntimeException( $message ); }
	}
	$GLOBALS['oversight_options'] = [];
	$GLOBALS['oversight_live_users'] = [ 111 => true ];
	$GLOBALS['oversight_live_posts'] = [ 900 => true ];
	$GLOBALS['oversight_error'] = false;
	$GLOBALS['oversight_admin'] = true;
	$GLOBALS['oversight_current_user'] = 1;
	$GLOBALS['oversight_multisite'] = false;
	function get_option( string $name, mixed $default = null ): mixed {
		return $GLOBALS['oversight_options'][ $name ]['value'] ?? $default;
	}
	function wp_next_scheduled( string $name, array $args ): int|false {
		return 'cb_crm_user_anonymize_reconcile' === $name && ( $args[0] ?? 0 ) === 110 ? 12345 : false;
	}
	function get_current_user_id(): int { return $GLOBALS['oversight_current_user']; }
	function current_user_can( string $cap ): bool {
		return $GLOBALS['oversight_admin'] && 'cb_manage_crm' === $cap;
	}
	function cb_crm_runtime_ready(): bool { return true; }
	function is_multisite(): bool { return $GLOBALS['oversight_multisite']; }
	final class CRM_Recovery_Oversight_Db {
		public string $options = 'wp_options';
		public string $users = 'wp_users';
		public string $posts = 'wp_posts';
		public string $last_error = '';
		public function esc_like( string $text ): string { return $text; }
		public function prepare( string $sql, mixed ...$arguments ): string {
			$index = 0;
			$prepared = preg_replace_callback( '/%[ds]/', static function ( array $match ) use ( $arguments, &$index ): string {
				$value = $arguments[ $index++ ];
				return '%d' === $match[0] ? (string) (int) $value : "'" . str_replace( "'", "''", (string) $value ) . "'";
			}, $sql );
			if ( ! is_string( $prepared ) || $index !== count( $arguments ) ) {
				throw new \RuntimeException( 'invalid SQL arguments' );
			}
			return $prepared;
		}
		public function get_results( string $sql, string $mode ): ?array {
			if ( ARRAY_A !== $mode || ! preg_match( '/option_id > ([0-9]+).*LIMIT ([0-9]+)/s', $sql, $m )
				|| ! str_contains( $sql, 'ORDER BY option_id ASC' )
				|| ! str_contains( $sql, 'cb_crm_user_anonymize_intent_' )
				|| ! str_contains( $sql, 'cb_crm_delete_intent_' ) ) {
				throw new \RuntimeException( 'invalid recovery inventory SQL' );
			}
			if ( $GLOBALS['oversight_error'] ) { $this->last_error = 'injected_db_error'; return null; }
			$result = [];
			foreach ( $GLOBALS['oversight_options'] as $name => $option ) {
				if ( $option['id'] <= (int) $m[1] || ! (
					str_starts_with( $name, 'cb_crm_user_anonymize_intent_' )
					|| str_starts_with( $name, 'cb_crm_delete_intent_' )
				) ) { continue; }
				$result[] = [ 'option_id' => $option['id'], 'option_name' => $name ];
			}
			usort( $result, static fn ( array $a, array $b ): int => $a['option_id'] <=> $b['option_id'] );
			return array_slice( $result, 0, (int) $m[2] );
		}
		public function get_var( string $sql ): ?int {
			if ( $GLOBALS['oversight_error'] ) { $this->last_error = 'injected_lookup_error'; return null; }
			if ( ! preg_match( '/SELECT ID FROM wp_(users|posts) WHERE ID = ([0-9]+) LIMIT 1/', $sql, $m ) ) {
				throw new \RuntimeException( 'unexpected identity database read' );
			}
			$exists = 'users' === $m[1] ? $GLOBALS['oversight_live_users'] : $GLOBALS['oversight_live_posts'];
			return isset( $exists[ (int) $m[2] ] ) ? (int) $m[2] : null;
		}
	}
	$GLOBALS['wpdb'] = new CRM_Recovery_Oversight_Db();
}
namespace CB\CRM\Content {
	final class Entity {
		public const CONTACT = 'contact';
		public const ORGANIZATION = 'organization';
	}
}
namespace CB\CRM\Repository {
	final class LifecycleRecovery {
		public static array $calls = [];
		public static function retry( int $id ): bool { self::$calls[] = $id; return true; }
	}
	final class UserAnonymizationRecovery {
		public static array $calls = [];
		public static function retry( int $id ): bool { self::$calls[] = $id; return true; }
	}
}
namespace {
	require dirname( __DIR__ ) . '/src/Repository/RecoveryOversight.php';
	require dirname( __DIR__ ) . '/src/CLI/RecoveryCommand.php';
	use CB\CRM\Repository\RecoveryOversight;
	use CB\CRM\CLI\RecoveryCommand;
	use CB\CRM\Repository\UserAnonymizationRecovery;
	use CB\CRM\Repository\LifecycleRecovery;

	$assert = static function ( bool $ok, string $msg ): void {
		if ( ! $ok ) { fwrite( STDERR, 'CRM recovery oversight smoke FAIL: ' . $msg . "\n" ); exit( 1 ); }
	};
	$refused = static function ( callable $f, string $msg ) use ( $assert ): void {
		$failed = false;
		try { $f(); } catch ( \RuntimeException ) { $failed = true; }
		$assert( $failed, $msg );
	};
	for ( $n = 100; $n < 125; ++$n ) {
		$GLOBALS['oversight_options']['cb_crm_user_anonymize_intent_' . $n] = [
			'id' => $n - 99,
			'value' => [ 'version' => 1, 'user_id' => $n ],
		];
	}
	$GLOBALS['oversight_options']['cb_crm_delete_intent_900'] = [
		'id' => 26,
		'value' => [ 'version' => 1, 'type' => 'contact', 'id' => 900 ],
	];
	$GLOBALS['oversight_options']['cb_crm_delete_intent_901'] = [
		'id' => 27,
		'value' => [ 'version' => 1, 'type' => 'organization', 'id' => 999 ],
	];
	$page = RecoveryOversight::page( 0, 20 );
	$assert( is_array( $page ) && 20 === count( $page['rows'] ), 'first page must cap to 20' );
	$assert( 20 === $page['cursor'] && $page['has_more'], 'first page emits actionable cursor' );
	$assert( 'identity_exists' === $page['rows'][11]['state'], 'existing user is marked as such' );
	$assert( 'yes' === $page['rows'][10]['scheduled'], 'scheduled user intent is visible' );
	$next = RecoveryOversight::page( $page['cursor'], 20 );
	$assert( is_array( $next ) && 7 === count( $next['rows'] ) && ! $next['has_more'], 'keyset page reaches intents beyond first 20' );
	$assert( 'invalid' === $next['rows'][6]['state'], 'corrupt post intent remains visible but not retryable' );
	$assert( 'contact' === $next['rows'][5]['kind'] && 'identity_exists' === $next['rows'][5]['state'], 'live Contact is never replayed' );

	$GLOBALS['oversight_error'] = true;
	$assert( RecoveryOversight::page() instanceof WP_Error, 'inventory SQL errors fail closed' );
	$GLOBALS['oversight_error'] = false;
	$cmd = new RecoveryCommand();
	WP_CLI::$output = [];
	$cmd->status( [], [ 'after' => '20', 'limit' => '20' ] );
	$assert( in_array( 'HAS_MORE=no', WP_CLI::$output, true ), 'CLI keyset page exposes last page' );
	$refused( static fn () => $cmd->retry( [], [ 'kind' => 'user', 'id' => '100' ] ), 'retry requires explicit confirmation' );
	$refused( static fn () => $cmd->retry( [], [ 'kind' => 'user', 'id' => '111', 'confirm' => '1' ] ), 'retry refuses a living WordPress user' );
	$refused( static fn () => $cmd->retry( [], [ 'kind' => 'organization', 'id' => '901', 'confirm' => '1' ] ), 'mismatched corrupt identity intent rejected' );
	$refused( static fn () => $cmd->retry( [], [ 'kind' => 'contact', 'id' => '900', 'confirm' => '1' ] ), 'post still present must not be reconciled' );
	$refused( static fn () => $cmd->retry( [], [ 'kind' => 'user', 'id' => '-5', 'confirm' => '1' ] ), 'malformed ID rejected' );
	$GLOBALS['oversight_admin'] = false;
	$refused( static fn () => $cmd->status( [], [] ), 'non-operator cannot enumerate recovery IDs' );
	$GLOBALS['oversight_admin'] = true;
	$GLOBALS['oversight_current_user'] = 0;
	$refused( static fn () => $cmd->status( [], [] ), 'anonymous admin cannot enumerate recovery IDs' );
	$GLOBALS['oversight_current_user'] = 1;
	$cmd->retry( [], [ 'kind' => 'user', 'id' => '124', 'confirm' => '1' ] );
	$assert( [ 124 ] === UserAnonymizationRecovery::$calls, 'authorized single-ID retry dispatches once' );
	$assert( [] === LifecycleRecovery::$calls, 'user action does not dispatch Contact cleanup' );
	$refused( static fn () => $cmd->retry( [], [ 'kind' => 'user', 'id' => '900', 'confirm' => '1' ] ), 'must not switch unrelated intent kinds' );
	$GLOBALS['oversight_multisite'] = true;
	$refused( static fn () => $cmd->retry( [], [ 'kind' => 'user', 'id' => '123', 'confirm' => '1' ] ), 'multisite user recovery explicitly unsupported' );

	echo "CRM recovery oversight and privileged single-ID CLI smoke passed.\n";
}

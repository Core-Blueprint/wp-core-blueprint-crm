<?php
declare(strict_types=1);

namespace CB\CRM\Content {
	final class ContactIdentity {}
	final class Meta {}
	final class PostTypes {
		public const CONTACT = 'cb_crm_contact';
		public const ORGANIZATION = 'cb_crm_org';
	}
	final class Entity {
		public const CONTACT = 'contact';
		public const ORGANIZATION = 'organization';
	}
}
namespace CB\CRM\Database {
	final class Schema {
		public static function __callStatic( string $name, array $args ): string {
			if ( ! str_ends_with( $name, '_table' ) || [] !== $args ) {
				throw new \RuntimeException( 'Unrecognized CRM table request' );
			}
			return 'wp_cb_crm_' . substr( $name, 0, -6 );
		}
	}
}
namespace CB\CRM\Repository\Sales {
	final class LifecycleCleanup {
		public static bool $fail = false;
		public static function for_deleted_identity( string $type, int $id ): bool {
			global $wpdb;
			if ( self::$fail ) {
				return false;
			}
			$wpdb->rows['wp_cb_crm_sales_participants'] = array_values( array_filter(
				$wpdb->rows['wp_cb_crm_sales_participants'] ?? [],
				static fn ( array $row ): bool => $row['party_type'] !== $type || $row['party_id'] !== $id
			) );
			return true;
		}
	}
}
namespace CB\CRM\Repository {
	final class Activity {}
	final class DocumentLinks {}
	final class UserAnonymizationRecovery {
		public static function init(): void {}
		public static function deleted_user( int $user_id ): void {}
	}
}
namespace CB\CRM {
	final class Governance {
		public static array $deleted = [];
		public static array $failed = [];
		public static function record_cleanup_failed( string $type, int $id ): void { self::$failed[] = [ $type, $id ]; }
		public static function record_record_deleted( string $type, int $id ): void { self::$deleted[] = [ $type, $id ]; }
	}
}
namespace {
	$root = dirname( __DIR__ );
	define( 'ABSPATH', $root . '/' );
	final class WP_Post {
		public function __construct(
			public int $ID,
			public string $post_type,
			public string $post_status = 'publish'
		) {}
	}

	$GLOBALS['recovery_options'] = [];
	$GLOBALS['recovery_cron'] = [];
	$GLOBALS['recovery_hooks'] = [];
	$GLOBALS['recovery_fail_option_add'] = false;
	$GLOBALS['recovery_fail_option_delete'] = false;
	$GLOBALS['recovery_fail_schedule'] = false;
	$GLOBALS['recovery_admin'] = false;
	function get_option( string $key, mixed $default = false ): mixed {
		return $GLOBALS['recovery_options'][ $key ] ?? $default;
	}
	function add_option( string $key, mixed $value, string $deprecated = '', bool $autoload = false ): bool {
		if ( $GLOBALS['recovery_fail_option_add'] || array_key_exists( $key, $GLOBALS['recovery_options'] ) || $autoload ) {
			return false;
		}
		$GLOBALS['recovery_options'][ $key ] = $value;
		return true;
	}
	function delete_option( string $key ): bool {
		if ( $GLOBALS['recovery_fail_option_delete'] || ! array_key_exists( $key, $GLOBALS['recovery_options'] ) ) {
			return false;
		}
		unset( $GLOBALS['recovery_options'][ $key ] );
		return true;
	}
	function wp_next_scheduled( string $hook, array $args = [] ): int|false {
		return $GLOBALS['recovery_cron'][ $hook . ':' . ( $args[0] ?? 0 ) ] ?? false;
	}
	function wp_schedule_single_event( int $time, string $hook, array $args = [] ): bool {
		if ( $GLOBALS['recovery_fail_schedule'] ) {
			return false;
		}
		$GLOBALS['recovery_cron'][ $hook . ':' . ( $args[0] ?? 0 ) ] = $time;
		return true;
	}
	function add_filter( string $name, callable $callback, int $priority = 10, int $accepted_args = 1 ): void {
		$GLOBALS['recovery_hooks'][ $name ] = [ $callback, $priority, $accepted_args ];
	}
	function add_action( string $name, callable $callback, int $priority = 10, int $accepted_args = 1 ): void {
		$GLOBALS['recovery_hooks'][ $name ] = [ $callback, $priority, $accepted_args ];
	}
	function current_user_can( string $capability ): bool {
		return 'cb_manage_crm' === $capability && $GLOBALS['recovery_admin'];
	}

	final class CRM_Recovery_Db {
		public string $prefix = 'wp_';
		public string $posts = 'wp_posts';
		public string $options = 'wp_options';
		public string $last_error = '';
		public array $existing_posts = [ 42 => true, 44 => true, 45 => true ];
		public array $rows = [
			'wp_cb_crm_notes' => [
				[ 'owner_type' => 'contact', 'owner_id' => 42, 'body' => 'Existing note' ],
			],
			'wp_cb_crm_sales_participants' => [
				[ 'party_type' => 'contact', 'party_id' => 42 ],
				[ 'party_type' => 'organization', 'party_id' => 9 ],
			],
		];
		public ?string $fail_delete_table = null;
		public bool $fail_lookup = false;
		public bool $fail_commit = false;
		public int $transactions = 0;
		public int $deletes = 0;
		private ?array $snapshot = null;

		public function prepare( string $sql, mixed ...$args ): string {
			$index = 0;
			$prepared = preg_replace_callback(
				'/%[ds]/',
				static function ( array $match ) use ( $args, &$index ): string {
					$value = $args[ $index++ ];
					return '%d' === $match[0] ? (string) (int) $value : "'" . str_replace( "'", "''", (string) $value ) . "'";
				},
				$sql
			);
			if ( $index !== count( $args ) || ! is_string( $prepared ) ) {
				throw new \RuntimeException( 'Incorrect SQL parameters' );
			}
			return $prepared;
		}
		public function get_var( string $sql ): ?int {
			if ( ! preg_match( '/SELECT ID FROM wp_posts WHERE ID = ([0-9]+) LIMIT 1/', $sql, $m ) ) {
				throw new \RuntimeException( 'Unrecognized direct post lookup' );
			}
			if ( $this->fail_lookup ) {
				$this->last_error = 'simulated_post_lookup_failure';
				return null;
			}
			return isset( $this->existing_posts[ (int) $m[1] ] ) ? (int) $m[1] : null;
		}
		public function esc_like( string $value ): string { return $value; }
		public function get_col( string $sql ): array {
			if ( ! str_contains( $sql, 'FROM wp_options' ) || ! str_contains( $sql, 'LIMIT 20' ) ) {
				throw new \RuntimeException( 'Unbounded recovery listing' );
			}
			return array_slice(
				array_keys( array_filter(
					$GLOBALS['recovery_options'],
					static fn ( string $name ): bool => str_starts_with( $name, 'cb_crm_delete_intent_' ),
					ARRAY_FILTER_USE_KEY
				) ),
				0,
				20
			);
		}
		public function delete( string $table, array $where, array $formats = [] ): int|false {
			++$this->deletes;
			if ( $this->fail_delete_table === $table || count( $where ) !== count( $formats ) ) {
				return false;
			}
			$existing = $this->rows[ $table ] ?? [];
			$this->rows[ $table ] = array_values( array_filter(
				$existing,
				static function ( array $row ) use ( $where ): bool {
					foreach ( $where as $key => $value ) {
						if ( (string) ( $row[ $key ] ?? '' ) !== (string) $value ) {
							return true;
						}
					}
					return false;
				}
			) );
			return count( $existing ) - count( $this->rows[ $table ] );
		}
		public function query( string $sql ): int|false {
			if ( 'START TRANSACTION' === $sql ) {
				++$this->transactions;
				$this->snapshot = $this->rows;
				return 1;
			}
			if ( 'COMMIT' === $sql ) {
				if ( $this->fail_commit ) {
					return false;
				}
				$this->snapshot = null;
				return 1;
			}
			if ( 'ROLLBACK' === $sql ) {
				if ( null !== $this->snapshot ) {
					$this->rows = $this->snapshot;
				}
				$this->snapshot = null;
				return 1;
			}
			throw new \RuntimeException( 'Unrecognized transaction SQL' );
		}
	}
	$GLOBALS['wpdb'] = new CRM_Recovery_Db();

	require $root . '/src/Repository/LifecycleRecovery.php';
	require $root . '/src/Lifecycle.php';

	use CB\CRM\Governance;
	use CB\CRM\Lifecycle;
	use CB\CRM\Repository\LifecycleRecovery;
	use CB\CRM\Repository\Sales\LifecycleCleanup;

	$assert = static function ( bool $condition, string $message ): void {
		if ( ! $condition ) {
			fwrite( STDERR, "CRM lifecycle recovery smoke failed: {$message}\n" );
			exit( 1 );
		}
	};
	$cron_key = static fn ( int $id ): string => 'cb_crm_lifecycle_reconcile:' . $id;
	$intent_key = static fn ( int $id ): string => 'cb_crm_delete_intent_' . $id;

	Lifecycle::init();
	$assert( isset( $GLOBALS['recovery_hooks']['pre_delete_post'] ), 'filter is registered before deletion' );
	$assert( 3 === $GLOBALS['recovery_hooks']['pre_delete_post'][2], 'pre-delete filter receives force flag' );
	$assert( isset( $GLOBALS['recovery_hooks']['cb_crm_lifecycle_reconcile'] ), 'cron retry callback is registered' );
	$assert( ! isset( $GLOBALS['recovery_hooks']['wp_trash_post'] ), 'trashing does not register relational cleanup' );
	$post = new WP_Post( 42, 'cb_crm_contact' );
	$assert( false === LifecycleRecovery::pre_delete_post( false, $post, true ), 'another plugin veto is retained' );
	$assert( [] === $GLOBALS['recovery_options'], 'veto creates no intent' );
	$assert( null === LifecycleRecovery::pre_delete_post( null, new WP_Post( 100, 'post' ), true ), 'unrelated WP posts unaffected' );

	$GLOBALS['recovery_fail_option_add'] = true;
	$assert( false === LifecycleRecovery::pre_delete_post( null, $post, true ), 'failed durable write vetoes permanent deletion' );
	$assert( [] === $GLOBALS['recovery_options'], 'failed intent write does not imply durability' );
	$GLOBALS['recovery_fail_option_add'] = false;
	$GLOBALS['recovery_fail_schedule'] = true;
	$assert( false === LifecycleRecovery::pre_delete_post( null, $post, false ), 'failed cron scheduling vetoes even non-force custom post deletion' );
	$assert( isset( $GLOBALS['recovery_options'][ $intent_key( 42 ) ] ), 'blocked post still has durable intent' );
	$GLOBALS['recovery_fail_schedule'] = false;
	$assert( null === LifecycleRecovery::pre_delete_post( null, $post, false ), 'existing intent reused for a later deletion' );
	$assert( isset( $GLOBALS['recovery_cron'][ $cron_key( 42 ) ] ), 'durable retry is scheduled' );
	$assert( false === LifecycleRecovery::pre_delete_post( null, new WP_Post( 42, 'cb_crm_org' ), true ), 'mismatching pending identity cannot be overwritten' );

	// WordPress has physically deleted the Contact outside our SQL transaction.
	unset( $GLOBALS['wpdb']->existing_posts[42] );
	$before = serialize( $GLOBALS['wpdb']->rows );
	LifecycleCleanup::$fail = true;
	Lifecycle::deleted_post( 42, $post );
	$assert( $before === serialize( $GLOBALS['wpdb']->rows ), 'relational transaction rolls back on Sales failure' );
	$assert( isset( $GLOBALS['recovery_options'][ $intent_key( 42 ) ] ), 'post-delete failure retains durable intent' );
	$assert( [] === Governance::$deleted, 'failed cleanup is not a deletion success' );
	LifecycleCleanup::$fail = false;
	unset( $GLOBALS['recovery_cron'][ $cron_key( 42 ) ] );
	$assert( true === LifecycleRecovery::retry( 42 ), 'retry completes cleanup after the post is actually gone' );
	$assert( ! isset( $GLOBALS['recovery_options'][ $intent_key( 42 ) ] ), 'successful retry acknowledges durable intent' );
	$assert( [] === $GLOBALS['wpdb']->rows['wp_cb_crm_notes'], 'successful recovery cleans canonical CRM notes' );
	$assert( 1 === count( $GLOBALS['wpdb']->rows['wp_cb_crm_sales_participants'] ), 'unrelated Sales participant survives' );
	$assert( [ [ 'contact', 42 ] ] === Governance::$deleted, 'successful deletion recorded once' );
	$transactions = $GLOBALS['wpdb']->transactions;
	$assert( true === LifecycleRecovery::retry( 42 ), 'duplicate retry is an idempotent no-op' );
	$assert( $transactions === $GLOBALS['wpdb']->transactions, 'duplicate retry performs no new relational writes' );
	$assert( false === Lifecycle::reconcile_deleted_identity( 'contact', 42 ), 'internal recovery cannot delete without a valid intent' );

	// An aborted deletion or a trashed post must never lose its CRM relations.
	$trashed = new WP_Post( 44, 'cb_crm_contact', 'trash' );
	$assert( null === LifecycleRecovery::pre_delete_post( null, $trashed, true ), 'Trash permanent-delete path prepares intent' );
	$before_count = $GLOBALS['wpdb']->transactions;
	$assert( false === Lifecycle::reconcile_deleted_identity( 'contact', 44 ), 'internal recovery cannot bypass a still-existing post' );
	unset( $GLOBALS['recovery_cron'][ $cron_key( 44 ) ] );
	$assert( true === LifecycleRecovery::retry( 44 ), 'recovery defers while WordPress post still exists' );
	$assert( isset( $GLOBALS['recovery_options'][ $intent_key( 44 ) ] ), 'intent survives an aborted delete' );
	$assert( $before_count === $GLOBALS['wpdb']->transactions, 'existing/trashed posts never enter relational transaction' );
	$GLOBALS['recovery_admin'] = true;
	LifecycleRecovery::reschedule_pending();
	$assert( isset( $GLOBALS['recovery_cron'][ $cron_key( 44 ) ] ), 'authorized admin recovers missing cron schedule' );
	$GLOBALS['recovery_admin'] = false;

	// A transient DB failure cannot masquerade as post absence.
	$GLOBALS['wpdb']->fail_lookup = true;
	unset( $GLOBALS['recovery_cron'][ $cron_key( 44 ) ] );
	$assert( false === LifecycleRecovery::retry( 44 ), 'database read failure fails closed' );
	$assert( isset( $GLOBALS['recovery_cron'][ $cron_key( 44 ) ] ), 'read failure schedules next retry' );
	$GLOBALS['wpdb']->fail_lookup = false;
	unset( $GLOBALS['wpdb']->existing_posts[44] );
	$assert( true === LifecycleRecovery::retry( 44 ), 'recovery proceeds only after real removal' );
	$assert( ! isset( $GLOBALS['recovery_options'][ $intent_key( 44 ) ] ), 'reconciled recovery intent removed' );

	// A committed cleanup with a failed acknowledgement must remain retryable.
	$post45 = new WP_Post( 45, 'cb_crm_org' );
	$assert( null === LifecycleRecovery::pre_delete_post( null, $post45, true ), 'organization deletion guarded' );
	unset( $GLOBALS['wpdb']->existing_posts[45] );
	$GLOBALS['recovery_fail_option_delete'] = true;
	$assert( false === LifecycleRecovery::retry( 45 ), 'failed acknowledgement is reported' );
	$assert( isset( $GLOBALS['recovery_options'][ $intent_key( 45 ) ] ), 'failed acknowledgement preserves retry intent' );
	$GLOBALS['recovery_fail_option_delete'] = false;
	$assert( true === LifecycleRecovery::retry( 45 ), 'retry after committed cleanup succeeds without data loss' );
	$assert( ! isset( $GLOBALS['recovery_options'][ $intent_key( 45 ) ] ), 'eventually acknowledged intent cleared' );

	// COMMIT failure must preserve the intent for another clean transaction.
	$post46 = new WP_Post( 46, 'cb_crm_org' );
	$assert( null === LifecycleRecovery::pre_delete_post( null, $post46, true ), 'commit-failure fixture prepared' );
	$GLOBALS['wpdb']->fail_commit = true;
	$assert( false === LifecycleRecovery::retry( 46 ), 'failed transaction commit does not acknowledge intent' );
	$assert( isset( $GLOBALS['recovery_options'][ $intent_key( 46 ) ] ), 'commit failure retains recovery intent' );
	$GLOBALS['wpdb']->fail_commit = false;
	$assert( true === LifecycleRecovery::retry( 46 ), 'commit retry succeeds after storage returns' );
	$assert( ! isset( $GLOBALS['recovery_options'][ $intent_key( 46 ) ] ), 'commit retry clears intent' );

	// Unknown/corrupted versions are fail-closed and never deleted automatically.
	$GLOBALS['recovery_options'][ $intent_key( 47 ) ] = [ 'version' => 99, 'type' => 'contact', 'id' => 47 ];
	$assert( false === LifecycleRecovery::retry( 47 ), 'unknown recovery intent version fails closed' );
	$assert( isset( $GLOBALS['recovery_options'][ $intent_key( 47 ) ] ), 'unknown intent retained for operator review' );

	echo "CRM lifecycle recovery smoke passed.\n";
}

<?php
declare(strict_types=1);

namespace {
	$root = dirname( __DIR__ );
	define( 'ABSPATH', $root . '/' );
	define( 'ARRAY_A', 'ARRAY_A' );
	define( 'CB_CRM_SCHEMA_VERSION', '1.6' );
	$GLOBALS['crm_sales_schema_version'] = '1.6';

	final class WP_Post {
		public function __construct( public int $ID, public string $post_type ) {}
	}
	function get_option( string $key, mixed $default = '' ): mixed {
		return 'cb_crm_db_version' === $key ? $GLOBALS['crm_sales_schema_version'] : $default;
	}
	final class CRM_Sales_Lifecycle_Db {
		public string $prefix = 'wp_';
		public string $last_error = '';
		/** @var array<string,list<array<string,mixed>>> */
		public array $rows = [];
		public ?string $fail_delete_table = null;
		public ?string $fail_update_table = null;
		public bool $fail_commit = false;
		public bool $fail_read = false;
		private ?array $snapshot = null;
		public int $queries = 0;

		public function prepare( string $sql, mixed ...$args ): string {
			$index = 0;
			$out = preg_replace_callback( '/%[ds]/', static function ( array $match ) use ( $args, &$index ): string {
				$value = $args[ $index++ ] ?? null;
				return '%d' === $match[0] ? (string) (int) $value : "'" . str_replace( "'", "''", (string) $value ) . "'";
			}, $sql ) ?? '';
			if ( $index !== count( $args ) ) {
				throw new \RuntimeException( 'Incorrect Sales SQL bindings' );
			}
			return $out;
		}
		public function get_results( string $sql, string $type ): ?array {
			++$this->queries;
			if ( ARRAY_A !== $type || ! preg_match( "/WHERE party_type = '([^']+)' AND party_id = (\\d+) FOR UPDATE/", $sql, $m ) ) {
				throw new \RuntimeException( 'Unexpected Sales identity lookup' );
			}
			if ( $this->fail_read ) {
				return null;
			}
			return array_values( array_map(
				static fn ( array $row ): array => [ 'entity_type' => $row['entity_type'], 'entity_id' => $row['entity_id'] ],
				array_filter(
					$this->rows['wp_cb_crm_sales_participants'] ?? [],
					static fn ( array $row ): bool => $m[1] === $row['party_type'] && (int) $m[2] === (int) $row['party_id']
				)
			) );
		}
		public function get_row( string $sql, string $type ): ?array {
			++$this->queries;
			if ( ARRAY_A !== $type || ! preg_match( "/WHERE entity_type = '([^']+)' AND entity_id = (\\d+) LIMIT 1 FOR UPDATE/", $sql, $m ) ) {
				throw new \RuntimeException( 'Unexpected Sales participant count' );
			}
			if ( $this->fail_read ) {
				$this->last_error = 'read_failed';
				return null;
			}
			$this->last_error = '';
			foreach ( $this->rows['wp_cb_crm_sales_participants'] ?? [] as $row ) {
				if ( $row['entity_type'] === $m[1] && (int) $row['entity_id'] === (int) $m[2] ) {
					return [ 'id' => $row['id'] ];
				}
			}
			return null;
		}
		public function delete( string $table, array $where, array $formats = [] ): int|false {
			++$this->queries;
			if ( $table === $this->fail_delete_table || count( $where ) !== count( $formats ) ) {
				return false;
			}
			$existing = $this->rows[ $table ] ?? [];
			$this->rows[ $table ] = array_values( array_filter( $existing, static function ( array $row ) use ( $where ): bool {
				foreach ( $where as $field => $value ) {
					if ( (string) ( $row[ $field ] ?? '' ) !== (string) $value ) {
						return true;
					}
				}
				return false;
			} ) );
			return count( $existing ) - count( $this->rows[ $table ] );
		}
		public function update( string $table, array $data, array $where, array $formats, array $where_formats ): int|false {
			++$this->queries;
			if ( $table === $this->fail_update_table || count( $data ) !== count( $formats ) || count( $where ) !== count( $where_formats ) ) {
				return false;
			}
			$updated = 0;
			if ( ! isset( $this->rows[ $table ] ) ) {
				$this->rows[ $table ] = [];
			}
			foreach ( $this->rows[ $table ] as &$row ) {
				$matched = true;
				foreach ( $where as $key => $value ) {
					if ( (string) ( $row[ $key ] ?? '' ) !== (string) $value ) {
						$matched = false;
					}
				}
				if ( $matched ) {
					$row = array_replace( $row, $data );
					++$updated;
				}
			}
			unset( $row );
			return $updated;
		}
		public function query( string $sql ): int|false {
			++$this->queries;
			if ( 'START TRANSACTION' === $sql ) {
				$this->snapshot = $this->rows;
				return 1;
			}
			if ( 'ROLLBACK' === $sql ) {
				if ( null !== $this->snapshot ) {
					$this->rows = $this->snapshot;
				}
				$this->snapshot = null;
				return 1;
			}
			if ( 'COMMIT' === $sql ) {
				if ( $this->fail_commit ) {
					return false;
				}
				$this->snapshot = null;
				return 1;
			}
			throw new \RuntimeException( 'Unexpected transaction SQL' );
		}
	}
}
namespace CB\CRM\Content {
	final class ContactIdentity {
		public static function contact_ids_for_user( int $user_id ): array { return []; }
	}
	final class PostTypes {
		public const CONTACT = 'cb_crm_contact';
		public const ORGANIZATION = 'cb_crm_org';
	}
}
namespace CB\CRM\Repository {
	final class Activity {}
	final class DocumentLinks {}
	final class UserAnonymizationRecovery {
		public static function init(): void {}
		public static function deleted_user( int $user_id ): void {
			\CB\CRM\Repository\Sales\LifecycleCleanup::for_deleted_user( $user_id );
		}
	}
}
namespace CB\CRM {
	final class Governance {
		public static array $deleted = [];
		public static array $failed = [];
		public static function record_record_deleted( string $type, int $id ): void { self::$deleted[] = [ $type, $id ]; }
		public static function record_cleanup_failed( string $type, int $id ): void { self::$failed[] = [ $type, $id ]; }
	}
}
namespace {
	require $root . '/src/Content/Entity.php';
	require $root . '/src/Database/Schema.php';
	require $root . '/src/Domain/Sales/Rules.php';
	require $root . '/src/Repository/Sales/LifecycleCleanup.php';
	require $root . '/src/Repository/LifecycleRecovery.php';
	require $root . '/src/Lifecycle.php';

	use CB\CRM\Lifecycle;
	use CB\CRM\Governance;
	use CB\CRM\Repository\Sales\LifecycleCleanup;
	$assert = static function ( bool $condition, string $message ): void {
		if ( ! $condition ) {
			fwrite( STDERR, "CRM Sales lifecycle smoke failed: {$message}\n" );
			exit( 1 );
		}
	};
	$make_db = static function (): CRM_Sales_Lifecycle_Db {
		$db = new CRM_Sales_Lifecycle_Db();
		$db->rows = [
			'wp_cb_crm_sales_leads' => [
				[ 'id' => 10, 'owner_user_id' => 17, 'title' => 'Existing sales prospect' ],
			],
			'wp_cb_crm_sales_opportunities' => [
				[ 'id' => 20, 'owner_user_id' => 17, 'title' => 'Existing opportunity' ],
			],
			'wp_cb_crm_sales_participants' => [
				[ 'id' => 1, 'entity_type' => 'lead', 'entity_id' => 10, 'party_type' => 'contact', 'party_id' => 42 ],
				[ 'id' => 2, 'entity_type' => 'lead', 'entity_id' => 10, 'party_type' => 'organization', 'party_id' => 7 ],
				[ 'id' => 3, 'entity_type' => 'opportunity', 'entity_id' => 20, 'party_type' => 'contact', 'party_id' => 42 ],
			],
			'wp_cb_crm_sales_events' => [
				[ 'id' => 100, 'entity_type' => 'lead', 'entity_id' => 10, 'actor_user_id' => 17 ],
				[ 'id' => 101, 'entity_type' => 'opportunity', 'entity_id' => 20, 'actor_user_id' => 17 ],
			],
			'wp_cb_crm_notes' => [
				[ 'id' => 5, 'owner_type' => 'contact', 'owner_id' => 42, 'body' => 'Existing contact note' ],
			],
		];
		return $db;
	};

	$GLOBALS['wpdb'] = $make_db();
	$db = $GLOBALS['wpdb'];
	Lifecycle::deleted_post( 42, new WP_Post( 42, 'cb_crm_contact' ) );
	$assert( [ [ 'contact', 42 ] ] === Governance::$deleted, 'successful CRM Contact cleanup recorded' );
	$assert( [] === Governance::$failed, 'no false cleanup failure' );
	$assert( 1 === count( $db->rows['wp_cb_crm_sales_participants'] ), 'deleted contact associations pruned' );
	$assert( 'organization' === $db->rows['wp_cb_crm_sales_participants'][0]['party_type'], 'Lead with organization remains' );
	$assert( 1 === count( $db->rows['wp_cb_crm_sales_leads'] ), 'shared Lead retained' );
	$assert( [] === $db->rows['wp_cb_crm_sales_opportunities'], 'orphan opportunity removed' );
	$assert( 1 === count( $db->rows['wp_cb_crm_sales_events'] ), 'orphan event history removed' );
	$assert( [] === $db->rows['wp_cb_crm_notes'], 'existing canonical Contact notes cleanup retained' );

	// Deleting the last CRM organization must erase the remaining Lead and event.
	Lifecycle::deleted_post( 7, new WP_Post( 7, 'cb_crm_org' ) );
	$assert( [] === $db->rows['wp_cb_crm_sales_leads'], 'last participant deletion cleans Lead' );
	$assert( [] === $db->rows['wp_cb_crm_sales_participants'], 'last participant removed' );
	$assert( [] === $db->rows['wp_cb_crm_sales_events'], 'last participant events deleted' );

	// All changes roll back if Sales cleanup fails in the existing CRM transaction.
	Governance::$deleted = [];
	Governance::$failed = [];
	$GLOBALS['wpdb'] = $make_db();
	$db = $GLOBALS['wpdb'];
	$before = serialize( $db->rows );
	$db->fail_delete_table = 'wp_cb_crm_sales_events';
	Lifecycle::deleted_post( 42, new WP_Post( 42, 'cb_crm_contact' ) );
	$assert( $before === serialize( $db->rows ), 'failure to delete Sales events rolled back all CRM deletions' );
	$assert( [ [ 'contact', 42 ] ] === Governance::$failed, 'cleanup failure surfaced to Governance' );
	$assert( [] === Governance::$deleted, 'failed cleanup not marked successful' );

	// Deleting a WordPress user anonymises Sales ownership and actor provenance.
	Governance::$failed = [];
	$GLOBALS['wpdb'] = $make_db();
	$db = $GLOBALS['wpdb'];
	Lifecycle::deleted_user( 17 );
	$assert( 0 === $db->rows['wp_cb_crm_sales_leads'][0]['owner_user_id'], 'removed Sales owner ID' );
	$assert( 0 === $db->rows['wp_cb_crm_sales_opportunities'][0]['owner_user_id'], 'removed Opportunity owner ID' );
	$assert( 0 === $db->rows['wp_cb_crm_sales_events'][0]['actor_user_id'], 'removed Sales actor ID' );
	$assert( 3 === count( $db->rows['wp_cb_crm_sales_participants'] ), 'deleting WordPress account preserves canonical CRM identity relationships' );
	$assert( [] === Governance::$failed, 'anonymisation completed without errors' );

	// A partially failing user anonymisation must roll back all Sales changes.
	$GLOBALS['wpdb'] = $make_db();
	$db = $GLOBALS['wpdb'];
	$before = serialize( $db->rows );
	$db->fail_update_table = 'wp_cb_crm_sales_events';
	$assert( false === LifecycleCleanup::for_deleted_user( 17 ), 'anonymisation write failure is surfaced' );
	$assert( $before === serialize( $db->rows ), 'anonymisation failure rolled back actor and owner mutations' );
	$db->fail_update_table = null;

	// Prior schema version must not attempt to query non-existent Sales tables.
	$GLOBALS['crm_sales_schema_version'] = '1.5';
	$GLOBALS['wpdb'] = $make_db();
	$db = $GLOBALS['wpdb'];
	$assert( true === LifecycleCleanup::for_deleted_identity( 'contact', 42 ), 'legacy schema cleanup no-op' );
	$assert( 0 === $db->queries, 'legacy schema makes no Sales SQL queries' );

	echo "CRM Sales lifecycle cleanup smoke passed.\n";
}

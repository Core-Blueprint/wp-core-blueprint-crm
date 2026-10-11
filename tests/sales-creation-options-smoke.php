<?php
declare(strict_types=1);
/** W2B2-A strict bounded identity and stage selection source smoke. */
namespace CB\CRM\Domain\Sales {
	final class Access {
		public static bool $manager = false;
		public static function can_manage(): bool { return self::$manager; }
	}
}
namespace CB\CRM\Database {
	final class Schema {
		public static function sales_pipelines_table(): string { return 'wp_cb_crm_sales_pipelines'; }
		public static function sales_stages_table(): string { return 'wp_cb_crm_sales_stages'; }
	}
}
namespace CB\CRM\Content {
	final class Entity {
		public static function post_type_for_owner( string $type ): string {
			return [ 'contact' => 'cb_crm_contact', 'organization' => 'cb_crm_org' ][$type] ?? '';
		}
	}
}
namespace {
	use CB\CRM\Domain\Sales\Access;
	use CB\CRM\Repository\Sales\CreationOptions;
	define( 'ABSPATH', dirname( __DIR__ ) . '/' );
	if ( ! function_exists( 'mb_strlen' ) ) { function mb_strlen( string $s ): int { return strlen( $s ); } }
	final class WP_Error {
		public function __construct( private string $code ) {}
		public function get_error_code(): string { return $this->code; }
	}
	final class Test_Wpdb {
		public string $last_error = '';
		public string $posts = 'wp_posts';
		public array $rows = [];
		public array $args = [];
		public string $query = '';
		public int $queries = 0;
		public function prepare( string $sql, mixed ...$args ): string { $this->args = $args; return $sql; }
		public function get_results( string $sql, string $output_type ): array { ++$this->queries; $this->query = $sql; return $this->rows; }
		public function esc_like( string $needle ): string { return addcslashes( $needle, '_%\\' ); }
	}
	define( 'ARRAY_A', 'ARRAY_A' );
	$GLOBALS['wpdb'] = new Test_Wpdb();
	$GLOBALS['w2b2a_actor'] = 4;
	function get_current_user_id(): int { return $GLOBALS['w2b2a_actor']; }
	require dirname( __DIR__ ) . '/src/Domain/Sales/Rules.php';
	require dirname( __DIR__ ) . '/src/Repository/Sales/CreationOptions.php';
	$check = static function ( bool $ok, string $why ): void {
		if ( ! $ok ) { fwrite( STDERR, "CRM W2B2-A options FAIL: {$why}\n" ); exit( 1 ); }
	};
	$code = static fn ( mixed $r ): string => $r instanceof WP_Error ? $r->get_error_code() : '';
	$db = $GLOBALS['wpdb'];
	$check( 'crm_sales_forbidden' === $code( CreationOptions::stages( 'lead' ) ) && $db->queries === 0,
		'viewer could query private stages' );
	Access::$manager = true;
	$check( 'crm_sales_forbidden' === $code( CreationOptions::stages( 'unknown' ) ) && $db->queries === 0,
		'invalid stage type executed SQL' );
	$db->rows = [ [ 'pipeline_id' => 5, 'pipeline_name' => 'P', 'stage_id' => 20, 'stage_name' => 'S' ] ];
	$stages = CreationOptions::stages( 'lead' );
	$check( is_array( $stages ) && 5 === $stages[0]['pipeline_id'] && 20 === $stages[0]['stage_id']
		&& [ 'lead', 201 ] === $db->args && str_contains( $db->query, 'p.is_active = 1')
		&& str_contains( $db->query, 's.is_active = 1' ), 'stage query not active or bounded' );
	$db->rows = array_fill( 0, 201, [ 'pipeline_id' => 5, 'stage_id' => 20, 'pipeline_name' => 'P', 'stage_name' => 'S' ] );
	$check( 'crm_sales_choices_unavailable' === $code( CreationOptions::stages( 'lead' ) ),
		'overlarge stage set accepted' );
	$db->rows = [ [ 'ID' => 66, 'post_title' => 'Test Organization' ] ];
	$hits = CreationOptions::identities( 'organization', 'A%_');
	$check( is_array( $hits ) && $hits[0]['id'] === 66
		&& [ 'cb_crm_org', '%A\\%\\_%', 20 ] === $db->args
		&& str_contains( $db->query, "post_status NOT IN ('trash', 'auto-draft')" ),
		'identity search lacks bound/LIKE escaping or type filtering' );
	$before = $db->queries;
	$check( 'crm_sales_invalid_query' === $code( CreationOptions::identities( 'contact', str_repeat( 'a', 81 ) ) )
		&& $db->queries === $before, 'unbounded search reached SQL' );
	$GLOBALS['w2b2a_actor'] = 0;
	$check( 'crm_sales_forbidden' === $code( CreationOptions::identities( 'contact', 'Alice' ) ),
		'anonymous identity search allowed' );
	echo "CRM Sales W2B2-A bounded creation options smoke passed.\n";
}

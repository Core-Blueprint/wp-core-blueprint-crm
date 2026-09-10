<?php
declare(strict_types=1);

namespace {
	define( 'ABSPATH', '/tmp/wp/' );

	final class WP_Post {
		public function __construct( public int $ID, public string $post_type ) {}
	}

	final class WP_Error {
		public function __construct( private string $code = 'error' ) {}
		public function get_error_code(): string { return $this->code; }
	}

	final class CRM_G2_Response extends \RuntimeException {
		/** @param array<string,mixed> $data */
		public function __construct( public array $data, public int $status ) {
			parent::__construct( (string) ( $data['code'] ?? 'response' ) );
		}
	}

	final class CRM_G2_NonceReached extends \RuntimeException {}

	$GLOBALS['crm_g2_nonce_checks'] = 0;

	function sanitize_key( string $value ): string {
		return strtolower( preg_replace( '/[^a-z0-9_\-]/', '', $value ) ?? '' );
	}
	function sanitize_text_field( string $value ): string { return trim( strip_tags( $value ) ); }
	function get_post( int $id ): ?WP_Post { return 42 === $id ? new WP_Post( 42, 'cb_crm_org' ) : null; }
	function get_the_title( WP_Post $post ): string { return 42 === $post->ID ? 'Example BV' : ''; }
	function get_post_meta( int $id, string $key, bool $single = false ): string { unset( $id, $key, $single ); return ''; }
	function is_wp_error( mixed $value ): bool { return $value instanceof WP_Error; }
	function check_ajax_referer( string $action, string $query_arg = false ): bool {
		unset( $action, $query_arg );
		++$GLOBALS['crm_g2_nonce_checks'];
		throw new CRM_G2_NonceReached();
	}
	/** @param array<string,mixed> $data */
	function wp_send_json_error( array $data = [], ?int $status_code = null ): never {
		throw new CRM_G2_Response( $data, $status_code ?? 200 );
	}
}

namespace CB\CRM {
	final class Capabilities { public const MANAGE = 'cb_manage_crm'; }
	final class PanelRegistry {}
}

namespace CB\CRM\Content {
	final class Entity { public const ORGANIZATION = 'organization'; }
	final class Meta { public const LEGAL_NAME = '_cb_crm_legal_name'; public const STATUS = '_cb_crm_status'; }
	final class PostTypes { public const CONTACT = 'cb_crm_contact'; public const ORGANIZATION = 'cb_crm_org'; }
	final class RecordStatus { public static function normalize( string $value ): string { return '' !== $value ? $value : 'active'; } }
	final class ContactIdentity {}
}

namespace CB\CRM\Frontend {
	final class Access {
		public static bool $staff = false;
		public static function is_staff(): bool { return self::$staff; }
		public static function can_read_organization( int $id ): bool { return 42 === $id; }
	}
}

namespace CB\CRM\Repository {
	final class Addresses { public static function for_owner( string $type, int $id ): array { unset( $type, $id ); return []; } }
	final class ContactMethods { public static function for_owner( string $type, int $id ): array { unset( $type, $id ); return []; } }
	final class BusinessIdentifiers {
		public const TYPES = [ 'vat', 'registration_number', 'eori', 'other' ];
		public static int $reads = 0;
		/** @return array<int,array<string,mixed>> */
		public static function for_organization( int $id ): array {
			++self::$reads;
			return 42 === $id ? [ [ 'identifier_type' => 'vat', 'value' => 'NL123', 'country' => 'NL', 'label' => '', 'is_primary' => 1 ] ] : [];
		}
		/** @param array<int,array<string,mixed>> $rows @return array<string,string> */
		public static function canonical_map( array $rows ): array { return [] !== $rows ? [ 'vat' => 'NL123' ] : []; }
	}
}

namespace CB\CRM\Frontend\Data {
	final class Support {
		public static function service_agreements( string $type, int $id, bool $sensitive ): array { unset( $type, $id, $sensitive ); return []; }
		public static function tags( int $id ): array { unset( $id ); return []; }
	}
}

namespace {
	require dirname( __DIR__ ) . '/src/Frontend/Data/Organization.php';
	require dirname( __DIR__ ) . '/src/Admin/UserSearch.php';
	require dirname( __DIR__ ) . '/src/Integration/Docs.php';

	$assert = static function ( bool $condition, string $message ): void {
		if ( ! $condition ) {
			fwrite( STDERR, "FAIL: {$message}\n" );
			exit( 1 );
		}
	};

	\CB\CRM\Frontend\Access::$staff = false;
	\CB\CRM\Repository\BusinessIdentifiers::$reads = 0;
	$portal = \CB\CRM\Frontend\Data\Organization::get( 42 );
	$assert( is_array( $portal ), 'Authorized non-staff Organization read must remain available.' );
	$assert( [] === $portal['business_identifiers'], 'Canonical Business Identifiers must be staff-only.' );
	$assert( [] === $portal['business_identifier_records'], 'Full Business Identifier records must be staff-only.' );
	$assert( 0 === \CB\CRM\Repository\BusinessIdentifiers::$reads, 'Non-staff projections must not read Business Identifier storage.' );

	\CB\CRM\Frontend\Access::$staff = true;
	$staff = \CB\CRM\Frontend\Data\Organization::get( 42 );
	$assert( is_array( $staff ), 'Staff Organization read must remain available.' );
	$assert( [ 'vat' => 'NL123' ] === $staff['business_identifiers'], 'Staff projection must expose canonical Business Identifiers.' );
	$assert( 1 === count( $staff['business_identifier_records'] ), 'Staff projection must expose structured Business Identifier records.' );
	$assert( 1 === \CB\CRM\Repository\BusinessIdentifiers::$reads, 'Staff projection must read Business Identifier storage exactly once.' );

	$handlers = [
		[ \CB\CRM\Admin\UserSearch::class, 'search' ],
		[ \CB\CRM\Integration\Docs::class, 'ajax_search' ],
		[ \CB\CRM\Integration\Docs::class, 'ajax_link' ],
		[ \CB\CRM\Integration\Docs::class, 'ajax_unlink' ],
	];

	foreach ( $handlers as $handler ) {
		$_SERVER['REQUEST_METHOD'] = 'GET';
		$GLOBALS['crm_g2_nonce_checks'] = 0;
		try {
			$handler();
			$assert( false, 'GET request unexpectedly reached handler body.' );
		} catch ( CRM_G2_Response $response ) {
			$assert( 405 === $response->status, 'Non-POST AJAX requests must return HTTP 405.' );
			$assert( 'crm_method_not_allowed' === ( $response->data['code'] ?? '' ), 'Non-POST AJAX requests must return the canonical method error.' );
			$assert( 0 === $GLOBALS['crm_g2_nonce_checks'], 'Method rejection must happen before nonce processing.' );
		}

		$_SERVER['REQUEST_METHOD'] = 'POST';
		$GLOBALS['crm_g2_nonce_checks'] = 0;
		try {
			$handler();
			$assert( false, 'POST request did not reach nonce processing.' );
		} catch ( CRM_G2_NonceReached ) {
			$assert( 1 === $GLOBALS['crm_g2_nonce_checks'], 'POST AJAX request must proceed to nonce processing exactly once.' );
		}
	}

	echo "CRM security boundary smoke passed.\n";
}

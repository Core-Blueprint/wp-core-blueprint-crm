<?php
declare(strict_types=1);

$root = dirname( __DIR__ );

$fail = static function ( string $message ): never {
	fwrite( STDERR, "FAIL: {$message}\n" );
	exit( 1 );
};

$read = static function ( string $relative ) use ( $root, $fail ): string {
	$contents = file_get_contents( $root . '/' . $relative );
	if ( false === $contents ) {
		$fail( 'Could not read ' . $relative );
	}
	return $contents;
};

$coordinator = $read( 'src/Integration/Docs.php' );
$admin       = $read( 'src/Integration/DocsAdmin.php' );
$ajax        = $read( 'src/Integration/DocsAjax.php' );
$js          = $read( 'assets/admin-docs-integration.js' );

$coordinator_needles = [
	"private const AJAX_SEARCH   = 'cb_crm_docs_relation_search';",
	"private const AJAX_LINK     = 'cb_crm_docs_relation_link';",
	"private const AJAX_UNLINK   = 'cb_crm_docs_relation_unlink';",
	"add_action( 'cb_crm_register_panels', [ __CLASS__, 'register_crm_panel' ], 30 );",
	"add_action( 'add_meta_boxes', [ __CLASS__, 'register_docs_panel' ], 30, 2 );",
	"add_action( 'admin_enqueue_scripts', [ __CLASS__, 'enqueue' ] );",
	"add_action( 'wp_ajax_' . self::AJAX_SEARCH, [ __CLASS__, 'ajax_search' ] );",
	"add_action( 'wp_ajax_' . self::AJAX_LINK, [ __CLASS__, 'ajax_link' ] );",
	"add_action( 'wp_ajax_' . self::AJAX_UNLINK, [ __CLASS__, 'ajax_unlink' ] );",
	'DocsAdmin::register_crm_panel();',
	'DocsAdmin::render_crm_panel( $owner_type, $owner_id );',
	'DocsAjax::ajax_search();',
	'DocsAjax::ajax_link();',
	'DocsAjax::ajax_unlink();',
];
foreach ( $coordinator_needles as $needle ) {
	if ( ! str_contains( $coordinator, $needle ) ) {
		$fail( 'Docs coordinator lost contract: ' . $needle );
	}
}
if ( str_contains( $coordinator, '$_POST' ) || str_contains( $coordinator, 'LinkDocument::execute' ) || str_contains( $coordinator, 'DocumentLinks::for_owner' ) ) {
	$fail( 'Docs coordinator must remain transport/UI implementation-free.' );
}

$admin_needles = [
	"'id'         => 'documentation'",
	"[ Docs::class, 'render_crm_panel' ]",
	"'cb-crm-linked-records'",
	"[ Docs::class, 'render_docs_panel' ]",
	"'cb-crm-docs-integration'",
	"'cbCrmDocs'",
	"'search' => self::AJAX_SEARCH",
	"'link'   => self::AJAX_LINK",
	"'unlink' => self::AJAX_UNLINK",
	'data-cb-crm-docs-panel',
	'data-cb-crm-docs-linked',
	'data-cb-crm-docs-owner-type',
	'data-cb-crm-docs-context',
	'data-cb-crm-docs-search',
	'data-cb-crm-docs-search-button',
	'data-cb-crm-docs-results',
	'data-cb-crm-docs-status',
	'data-cb-crm-docs-unlink',
];
foreach ( $admin_needles as $needle ) {
	if ( ! str_contains( $admin, $needle ) ) {
		$fail( 'DocsAdmin lost UI contract: ' . $needle );
	}
}

$ajax_needles = [
	"private const NONCE         = 'cb_crm_docs_relations';",
	'LinkDocument::execute(',
	'UnlinkDocument::execute(',
	'DocsAdmin::linked_html(',
	"\$_POST['view']",
	"\$_POST['term']",
	"\$_POST['owner_type']",
	"\$_POST['owner_id']",
	"\$_POST['document_id']",
	"\$_POST['relation_type']",
	"'crm_method_not_allowed'",
	"'crm_forbidden'",
];
foreach ( $ajax_needles as $needle ) {
	if ( ! str_contains( $ajax, $needle ) ) {
		$fail( 'DocsAjax lost transport contract: ' . $needle );
	}
}

$method_pos = strpos( $ajax, 'self::require_post_request();' );
$nonce_pos  = strpos( $ajax, "check_ajax_referer( self::NONCE, 'nonce' );" );
if ( false === $method_pos || false === $nonce_pos || $method_pos >= $nonce_pos ) {
	$fail( 'DocsAjax must reject non-POST requests before nonce processing.' );
}

foreach ( [ 'search', 'link', 'unlink' ] as $action ) {
	if ( ! str_contains( $js, "config.actions?.{$action}" ) ) {
		$fail( 'Docs JavaScript action contract missing: ' . $action );
	}
}

echo "CRM Docs decomposition smoke passed.\n";

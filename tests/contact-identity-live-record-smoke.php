<?php
declare(strict_types=1);

$root = dirname( __DIR__ );
$source = file_get_contents( $root . '/src/Content/ContactIdentity.php' );
$failed = false;

$assert = static function ( bool $condition, string $message ) use ( &$failed ): void {
	if ( ! $condition ) {
		fwrite( STDERR, $message . "\n" );
		$failed = true;
	}
};

$assert(
	str_contains( $source, "p.post_status NOT IN ('auto-draft','trash')" ),
	'Canonical WP User links must ignore trashed and auto-draft Contacts.'
);
$assert(
	str_contains( $source, 'INNER JOIN {$wpdb->posts} p ON p.ID = cm.owner_id' ),
	'Email identity candidates must be resolved through the Contact post lifecycle.'
);
$assert(
	substr_count( $source, "p.post_status NOT IN ('auto-draft','trash')" ) >= 2,
	'Both user-link and email candidate resolution must exclude non-live Contacts.'
);

exit( $failed ? 1 : 0 );

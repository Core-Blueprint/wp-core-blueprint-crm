<?php
declare(strict_types=1);

$root = dirname( __DIR__ );
$source = file_get_contents( $root . '/src/Application/RecordUpdater.php' );
if ( false === $source ) {
	fwrite( STDERR, "FAIL: could not read RecordUpdater.php\n" );
	exit( 1 );
}

$checks = [
	'meta helper receives failure accumulator' => str_contains( $source, 'update_meta_if_changed( int $record_id, string $key, mixed $value, array &$failures )' ),
	'meta helper checks WordPress persistence result' => str_contains( $source, '$result = update_post_meta( $record_id, $key, $value );' ) && str_contains( $source, 'if ( false === $result )' ),
	'meta helper reports detail failure' => str_contains( $source, "\$failures[] = 'details';" ),
	'status persistence forwards failures' => str_contains( $source, 'Meta::STATUS, $status, $failures' ),
	'job title persistence forwards failures' => str_contains( $source, 'Meta::JOB_TITLE, sanitize_text_field( (string) $input[' . "'job_title'" . '] ), $failures' ),
	'name prefix persistence forwards failures' => str_contains( $source, 'Meta::NAME_PREFIX, sanitize_text_field( (string) $input[' . "'name_prefix'" . '] ), $failures' ),
	'user-link persistence forwards failures' => str_contains( $source, 'Meta::WP_USER_ID, absint( $input[' . "'wp_user_id'" . '] ), $failures' ),
	'email-mode persistence forwards failures' => str_contains( $source, 'Meta::EMAIL_MODE, $mode, $failures' ),
	'name override persistence forwards failures' => str_contains( $source, '$record_id, $meta_key, $value, $failures' ),
];

foreach ( $checks as $label => $passed ) {
	if ( ! $passed ) {
		fwrite( STDERR, 'RecordUpdater persistence conformance failed: ' . $label . "\n" );
		exit( 1 );
	}
}

echo "CRM RecordUpdater persistence smoke passed.\n";

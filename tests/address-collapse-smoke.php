<?php
declare(strict_types=1);

$root = dirname( __DIR__ );
$failed = false;

$assert = static function ( bool $condition, string $message ) use ( &$failed ): void {
	if ( ! $condition ) {
		fwrite( STDERR, $message . "\n" );
		$failed = true;
	}
};

$addresses = (string) file_get_contents( $root . '/src/Admin/Panels/AddressesPanel.php' );
$css = (string) file_get_contents( $root . '/assets/admin.css' );

$assert(
	str_contains( $addresses, '<details class="cb-crm-address-card cb-crm-source-address-card"' )
		&& str_contains( $addresses, '<summary class="cb-crm-source-address-summary">' ),
	'Woo billing and shipping addresses must render as independently collapsible native details blocks.'
);
$assert(
	str_contains( $addresses, "\$has_value ? ' open' : ''" )
		&& str_contains( $addresses, "'No address set.', 'woocommerce'" ),
	'Populated Woo addresses must default open while empty addresses remain compact and closed.'
);
$assert(
	! str_contains( $addresses, 'if ( ! $has_value && ! $can_edit )' ),
	'Empty Woo address sources must remain visible as compact provenance for read-only operators.'
);
$assert(
	str_contains( $css, '.cb-crm-source-address-card[open] > .cb-crm-source-address-summary::before' )
		&& str_contains( $css, '.cb-crm-source-address-body' )
		&& str_contains( $css, 'var(--cb-border)' )
		&& str_contains( $css, 'var(--cb-interactive-hover)' ),
	'Collapsible Woo address blocks must stay on the semantic Core Blueprint token surface.'
);

if ( ! $failed ) {
	echo "CRM address collapse smoke passed.\n";
}
exit( $failed ? 1 : 0 );

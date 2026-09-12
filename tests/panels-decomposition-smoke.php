<?php
declare(strict_types=1);

$root = dirname( __DIR__ );

$fail = static function ( string $message ): never {
	fwrite( STDERR, "FAIL: {$message}\n" );
	exit( 1 );
};

$read = static function ( string $relative ) use ( $root, $fail ): string {
	$path = $root . '/' . $relative;
	$contents = file_get_contents( $path );
	if ( false === $contents ) {
		$fail( 'Could not read ' . $relative );
	}
	return $contents;
};

$coordinator = $read( 'src/Admin/Panels.php' );
$order = [
	'DetailsPanel::register();',
	'ContactMethodsPanel::register();',
	'AddressesPanel::register();',
	'NamesPanel::register();',
	'OrganizationsPanel::register();',
	'NotesActivityPanel::register();',
];
$previous = -1;
foreach ( $order as $needle ) {
	$position = strpos( $coordinator, $needle );
	if ( false === $position || $position <= $previous ) {
		$fail( 'Panels coordinator registration order changed at: ' . $needle );
	}
	$previous = $position;
}

$wrappers = [ 'details', 'contact_methods', 'addresses', 'names', 'organizations', 'notes_activity' ];
foreach ( $wrappers as $method ) {
	if ( ! str_contains( $coordinator, 'function ' . $method . '(' ) ) {
		$fail( 'Panels compatibility wrapper missing: ' . $method );
	}
}

$contracts = [
	'src/Admin/Panels/DetailsPanel.php' => [
		"'id'         => 'details'",
		"[ \\CB\\CRM\\Admin\\Panels::class, 'details' ]",
		'cb_crm_save_record',
		'cb_crm_nonce',
		'cb_crm_details[status]',
		'cb_crm_details[first_name]',
		'cb_crm_details[last_name]',
		'cb_crm_details[job_title]',
		'cb_crm_details[wp_user_id]',
		'cb_crm_details[email_mode]',
		'cb_crm_details[legal_name]',
		'data-cb-crm-user-picker',
		'data-cb-crm-user-search',
		'data-cb-crm-email-mode',
	],
	'src/Admin/Panels/ContactMethodsPanel.php' => [
		"'id'         => 'contact-methods'",
		'cb_crm_contact_methods_present',
		'cb_crm_contact_methods[',
		'data-cb-crm-row',
		'data-cb-crm-remove-row',
	],
	'src/Admin/Panels/AddressesPanel.php' => [
		"'id'         => 'addresses'",
		'cb_crm_addresses_present',
		'cb_crm_addresses[',
		'data-cb-crm-repeater',
		'data-cb-crm-add-row',
	],
	'src/Admin/Panels/NamesPanel.php' => [
		"'id'         => 'names'",
		'cb_crm_names_present',
		'cb_crm_names[',
		'Names::for_owner( $owner_type, $post_id )',
	],
	'src/Admin/Panels/OrganizationsPanel.php' => [
		"'id'         => 'organizations'",
		'cb_crm_organizations_present',
		'cb_crm_organizations[',
		'Organizations::for_contact( $post_id )',
	],
	'src/Admin/Panels/NotesActivityPanel.php' => [
		"'id'         => 'notes-activity'",
		'cb_crm_new_note',
		'Notes::for_owner( $owner_type, $post_id, 20 )',
		'Activity::for_owner( $owner_type, $post_id, 30 )',
	],
	'src/Admin/Panels/RepeatableTable.php' => [
		'data-cb-crm-repeater',
		'data-cb-crm-rows',
		'data-cb-crm-add-row',
		'cb-crm-repeatable-table',
	],
];

foreach ( $contracts as $file => $needles ) {
	$contents = $read( $file );
	foreach ( $needles as $needle ) {
		if ( ! str_contains( $contents, $needle ) ) {
			$fail( $file . ' lost contract literal: ' . $needle );
		}
	}
}

echo "CRM panel decomposition smoke passed.\n";

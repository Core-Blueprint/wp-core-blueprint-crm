<?php
declare(strict_types=1);

$root = dirname( __DIR__ );
$failed = false;

$required = [
	'core-blueprint-crm.php',
	'src/Content/Entity.php',
	'src/Content/PostTypes.php',
	'src/Database/Schema.php',
	'src/Repository/BusinessIdentifiers.php',
	'src/Repository/ServiceAgreements.php',
	'src/Admin/Admin.php',
	'src/Admin/BusinessIdentifiersPanel.php',
	'src/Admin/UserSearch.php',
	'src/Integration/Docs.php',
	'src/Integration/WorkPricing.php',
	'src/Frontend/Data/Organization.php',
	'src/Frontend/Queries/ServiceAgreements.php',
	'src/Integration/Builders/Bricks/Queries.php',
	'src/Integration/Builders/Bricks/Conditions.php',
	'docs/INTEGRATION-API.md',
];
$forbidden_files = [
	'src/Content/ServicePricing.php',
	'src/Repository/Services.php',
	'src/Repository/TaxRates.php',
	'src/Frontend/Data/Service.php',
	'src/Frontend/Queries/Services.php',
	'src/Admin/PricingUi.php',
	'src/Admin/TaxRatesPage.php',
	'assets/pricing.css',
	'assets/pricing.js',
	'assets/tax-rates.js',
];
foreach ( $required as $file ) {
	if ( ! is_file( $root . '/' . $file ) ) {
		fwrite( STDERR, "Missing required file: {$file}\n" );
		$failed = true;
	}
}
foreach ( $forbidden_files as $file ) {
	if ( file_exists( $root . '/' . $file ) ) {
		fwrite( STDERR, "Legacy file still exists: {$file}\n" );
		$failed = true;
	}
}

$source = '';
$source_files = [];
foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root . '/src' ) ) as $file ) {
	if ( $file->isFile() && 'php' === strtolower( $file->getExtension() ) ) {
		$contents = file_get_contents( $file->getPathname() );
		$relative = str_replace( $root . '/', '', $file->getPathname() );
		$source_files[ $relative ] = $contents;
		$source .= "\n" . $contents;
	}
}

$find_token = static function ( string $token ) use ( $source_files ): array {
	$matches = [];
	foreach ( $source_files as $path => $contents ) {
		if ( str_contains( $contents, $token ) ) {
			$matches[] = $path;
		}
	}
	return $matches;
};

$bootstrap   = file_get_contents( $root . '/core-blueprint-crm.php' );
$schema      = file_get_contents( $root . '/src/Database/Schema.php' );
$entity      = file_get_contents( $root . '/src/Content/Entity.php' );
$post_types  = file_get_contents( $root . '/src/Content/PostTypes.php' );
$admin       = file_get_contents( $root . '/src/Admin/Admin.php' );
$userSearch  = file_get_contents( $root . '/src/Admin/UserSearch.php' );
$docsIntegration = file_get_contents( $root . '/src/Integration/Docs.php' );
$workPricing = file_get_contents( $root . '/src/Integration/WorkPricing.php' );
$agreements  = file_get_contents( $root . '/src/Repository/ServiceAgreements.php' );
$identifiers = file_get_contents( $root . '/src/Repository/BusinessIdentifiers.php' );
$organizationData = file_get_contents( $root . '/src/Frontend/Data/Organization.php' );
$identifierUi = file_get_contents( $root . '/src/Admin/BusinessIdentifiersPanel.php' );
$lifecycle   = file_get_contents( $root . '/src/Lifecycle.php' );
$agreementUi = file_get_contents( $root . '/src/Admin/ServiceAgreements.php' );
$panels      = file_get_contents( $root . '/src/Admin/Panels.php' );
$adminCss    = file_get_contents( $root . '/assets/admin.css' );
$adminJs     = file_get_contents( $root . '/assets/admin.js' );
$bricks      = file_get_contents( $root . '/src/Integration/Builders/Bricks/DynamicData.php' )
	. file_get_contents( $root . '/src/Integration/Builders/Bricks/Queries.php' )
	. file_get_contents( $root . '/src/Integration/Builders/Bricks/Conditions.php' );

$forbidden_tokens = [
	'PostTypes::SERVICE',
	'Entity::SERVICE',
	'Repository\\Services',
	'Repository\\TaxRates',
	'Content\\ServicePricing',
	'cb_crm_service_assignments',
	'cb_crm_tax_rates',
];
foreach ( $forbidden_tokens as $token ) {
	$matches = $find_token( $token );
	if ( [] !== $matches ) {
		fwrite( STDERR, 'Forbidden token ' . $token . ' found in: ' . implode( ', ', $matches ) . "\n" );
		$failed = true;
	}
}

$checks = [
	'candidate version is rc1 with schema 1.4' => str_contains( $bootstrap, 'Version:           1.0.0-rc1' ) && str_contains( $bootstrap, "CB_CRM_SCHEMA_VERSION', '1.4'" ),
	'CRM targets Core API without Base RC pin' => str_contains( $bootstrap, "CB_CRM_REQUIRED_API', '1.0'" ) && ! str_contains( $bootstrap, 'CB_CRM_REQUIRED_BASE' ),
	'CRM no longer owns Service post type' => ! str_contains( $post_types, 'cb_crm_service' ) && ! str_contains( $entity, 'SERVICE' ),
	'CRM schema owns agreements and business identifiers but no old assignments or VAT catalog' => str_contains( $schema, 'cb_crm_service_agreements' ) && str_contains( $schema, 'cb_crm_business_identifiers' ) && ! str_contains( $schema, 'cb_crm_service_assignments' ) && ! str_contains( $schema, 'cb_crm_tax_rates' ),
	'Business identifiers are organization-owned and transactional' => str_contains( $identifiers, 'Entity::ORGANIZATION' ) && str_contains( $identifiers, "START TRANSACTION" ) && str_contains( $identifiers, "ROLLBACK" ) && str_contains( $identifiers, "COMMIT" ),
	'Organization projection exposes identifier map and records' => str_contains( $organizationData, "'business_identifiers'" ) && str_contains( $organizationData, "'business_identifier_records'" ) && str_contains( $organizationData, 'BusinessIdentifiers::canonical_map' ),
	'Business identifier projections are staff-only' => str_contains( $organizationData, '$staff = Access::is_staff()' ) && str_contains( $organizationData, '$staff ? BusinessIdentifiers::for_organization' ) && str_contains( $organizationData, "'business_identifiers' => \$staff ?" ) && str_contains( $organizationData, "'business_identifier_records' => \$staff ?" ),
	'CRM AJAX contracts reject non-POST before nonce processing' => str_contains( $userSearch, 'self::require_post_request();' ) && str_contains( $userSearch, "\$_SERVER['REQUEST_METHOD']" ) && str_contains( $userSearch, "'crm_method_not_allowed'" ) && str_contains( $docsIntegration, 'self::require_post_request();' ) && str_contains( $docsIntegration, "\$_SERVER['REQUEST_METHOD']" ) && str_contains( $docsIntegration, "'crm_method_not_allowed'" ),
	'Identifier admin is wired and organization-only' => str_contains( $admin, 'BusinessIdentifiersPanel::init()' ) && str_contains( $identifierUi, "'post_types' => [ Entity::ORGANIZATION ]" ) && str_contains( $identifierUi, 'cb_crm_business_identifiers_present' ),
	'Organization deletion cleans identifier rows' => str_contains( $lifecycle, 'business_identifiers_table()' ) && str_contains( $lifecycle, "'organization_id' => \$owner_id" ),
	'Work provider preserves agreement reference' => str_contains( $workPricing, "'reference_type' => 'service_agreement'" ) && str_contains( $workPricing, 'pricing_projection' ),
	'Agreement editor posts stable row IDs' => str_contains( $agreementUi, '[id]' ) && str_contains( $agreementUi, '$agreement_id' ),
	'Agreement repository preserves retained IDs' => str_contains( $agreements, '$wpdb->update(' ) && str_contains( $agreements, '$retained_ids' ) && str_contains( $agreements, "'id' => \$row['id']" ),
	'Contact user-picker markup still matches admin.js contract' => str_contains( $panels, 'data-cb-crm-user-selected' ) && str_contains( $panels, 'data-cb-crm-user-selected-name' ) && str_contains( $panels, 'data-cb-crm-user-selected-email' ) && str_contains( $panels, 'data-cb-crm-user-remove' ) && str_contains( $panels, 'data-cb-crm-email-mode' ) && str_contains( $adminJs, 'data-cb-crm-user-selected' ),
	'CRM timeline presentation uses semantic Base tokens' => str_contains( $panels, 'cb-crm-timeline-entry' ) && str_contains( $panels, 'cb-crm-timeline-body' ) && ! str_contains( $panels, '#dcdcde' ) && str_contains( $adminCss, '.cb-crm-timeline-entry' ) && str_contains( $adminCss, 'var(--cb-border)' ) && str_contains( $adminCss, 'var(--cb-text-muted)' ),
	'Work coupling uses public API only' => 0 === preg_match( '/CB\\\\Work\\\\(?!PublicApi\\\\)/', $source ),
	'Bricks exposes agreements not CRM Service catalog' => str_contains( $bricks, 'cb_crm_service_agreements' ) && str_contains( $bricks, 'cb_crm_contact_has_service_agreement' ) && ! str_contains( $bricks, 'cb_crm_services' ) && ! str_contains( $bricks, 'cb_crm_service_name' ),
];
foreach ( $checks as $label => $passed ) {
	if ( ! $passed ) {
		fwrite( STDERR, "Conformance failed: {$label}\n" );
		$failed = true;
	}
}

exit( $failed ? 1 : 0 );

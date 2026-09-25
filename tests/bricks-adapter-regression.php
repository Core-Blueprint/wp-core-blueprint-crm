<?php
declare(strict_types=1);

$root = dirname( __DIR__ );

function cb_crm_bricks_read( string $relative ): string {
    $path = $root = dirname( __DIR__ );
    $contents = file_get_contents( $path . '/' . $relative );
    if ( false === $contents ) {
        fwrite( STDERR, "CRM Bricks regression failed: unable to read {$relative}\n" );
        exit( 1 );
    }
    return $contents;
}

function cb_crm_bricks_assert( bool $condition, string $message ): void {
    if ( $condition ) {
        return;
    }
    fwrite( STDERR, "CRM Bricks regression failed: {$message}\n" );
    exit( 1 );
}

$dynamic      = cb_crm_bricks_read( 'src/Integration/Builders/Bricks/DynamicData.php' );
$integration  = cb_crm_bricks_read( 'src/Integration/Builders/Bricks/IntegrationData.php' );
$context      = cb_crm_bricks_read( 'src/Integration/Builders/Bricks/RecordContext.php' );
$queries      = cb_crm_bricks_read( 'src/Integration/Builders/Bricks/Queries.php' );
$conditions   = cb_crm_bricks_read( 'src/Integration/Builders/Bricks/Conditions.php' );
$form_actions = cb_crm_bricks_read( 'src/Integration/Builders/Bricks/FormActions.php' );

cb_crm_bricks_assert( str_contains( $dynamic, 'RecordContext::data(' ), 'dynamic data must resolve through canonical CRM record context' );
cb_crm_bricks_assert( str_contains( $context, 'Contact::current()' ) && str_contains( $context, 'Contact::get(' ), 'contact context must use frontend Contact projection' );
cb_crm_bricks_assert( str_contains( $context, 'Organization::get(' ), 'organization context must use frontend Organization projection' );

foreach ( [ $dynamic, $integration, $context, $queries, $conditions, $form_actions ] as $source ) {
    cb_crm_bricks_assert( ! str_contains( $source, 'global $wpdb' ), 'Bricks adapter must not access CRM storage directly' );
    cb_crm_bricks_assert( ! str_contains( $source, 'new \\WP_Query' ), 'Bricks adapter must not own WordPress query execution' );
    cb_crm_bricks_assert( ! str_contains( $source, 'get_post_meta(' ), 'Bricks adapter must not read CRM post meta directly' );
    cb_crm_bricks_assert( ! str_contains( $source, 'update_post_meta(' ), 'Bricks adapter must not mutate CRM post meta directly' );
    cb_crm_bricks_assert( ! str_contains( $source, 'wp_update_post(' ), 'Bricks adapter must not mutate CRM records directly' );
}

cb_crm_bricks_assert( str_contains( $queries, 'Contacts::staff(' ), 'contacts query must delegate to frontend provider' );
cb_crm_bricks_assert( str_contains( $queries, 'Organizations::staff(' ), 'organizations query must delegate to frontend provider' );
cb_crm_bricks_assert( str_contains( $queries, 'ServiceAgreements::for_customer(' ), 'agreement query must delegate to frontend provider' );
cb_crm_bricks_assert( str_contains( $queries, 'DocumentLinks::for_owner(' ) && str_contains( $queries, 'DocumentLinks::for_document(' ), 'Docs queries must delegate to CRM frontend integration provider' );
cb_crm_bricks_assert( str_contains( $queries, 'HelpdeskTickets::for_contact(' ), 'Helpdesk query must delegate to CRM frontend integration provider' );

cb_crm_bricks_assert( str_contains( $conditions, 'Records::current_user_has_contact()' ), 'conditions must use canonical frontend condition provider' );
cb_crm_bricks_assert( str_contains( $conditions, 'Records::record_status_is(' ), 'record status condition must stay in frontend condition provider' );
cb_crm_bricks_assert( str_contains( $conditions, 'WorkServices::all(' ), 'service choices must use Work public API' );
cb_crm_bricks_assert( str_contains( $conditions, 'Organizations::staff(' ), 'organization choices must use CRM frontend query provider' );

cb_crm_bricks_assert( str_contains( $form_actions, 'UpdateContact::execute(' ), 'contact form action must delegate to application action' );
cb_crm_bricks_assert( str_contains( $form_actions, 'UpdateOrganization::execute(' ), 'organization form action must delegate to application action' );

cb_crm_bricks_assert( str_contains( $integration, 'DocumentLinks::for_owner(' ), 'integration data must use canonical document link provider' );
cb_crm_bricks_assert( str_contains( $integration, 'HelpdeskTickets::count_for_contact(' ), 'integration data must use canonical Helpdesk provider' );

fwrite( STDOUT, "CRM Bricks adapter regression: PASS\n" );

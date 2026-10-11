<?php
declare(strict_types=1);

/**
 * W2A native WordPress Admin rendering acceptance.
 * Execute ONLY via the existing disposable WordPress/MariaDB runner.
 */
if ( ! defined( 'WP_CLI' ) || ! WP_CLI
	|| ! defined( 'CB_CRM_INTEGRATION_DISPOSABLE' ) || ! CB_CRM_INTEGRATION_DISPOSABLE
	|| ! str_starts_with( (string) DB_NAME, 'cb_crm_it_' )
	|| ! str_contains( (string) DB_HOST, '127.0.0.1:3307' ) ) {
	fwrite( STDERR, "W2A Admin integration refuses non-disposable database.\n" );
	exit( 1 );
}

use CB\CRM\Admin\Menu;
use CB\CRM\Admin\SalesPage;
use CB\CRM\Application\Sales\StaffActions;
use CB\CRM\Content\PostTypes;
use CB\CRM\Database\Schema;
use CB\CRM\Domain\Sales\Access;

global $wpdb;
$assert = static function ( bool $ok, string $message ): void {
	if ( ! $ok ) {
		fwrite( STDERR, "CRM W2A MariaDB FAIL: {$message}\n" );
		exit( 1 );
	}
};

$admin = get_user_by( 'login', 'crm-it-admin' );
$assert( $admin instanceof WP_User, 'disposable Base-approved operator missing' );
wp_set_current_user( $admin->ID );
$assert( Access::can_view() && current_user_can( 'cb_manage_crm_sales' ), 'operator lacks Sales view/write capability' );

$pipelines = Schema::sales_pipelines_table();
$stages = Schema::sales_stages_table();
$lead_pipeline = (int) $wpdb->get_var( $wpdb->prepare(
	"SELECT id FROM {$pipelines} WHERE pipeline_key = %s", 'default_leads'
) );
$deal_pipeline = (int) $wpdb->get_var( $wpdb->prepare(
	"SELECT id FROM {$pipelines} WHERE pipeline_key = %s", 'default_opportunities'
) );
$stage = static fn ( int $pipeline, string $key ): int => (int) $wpdb->get_var( $wpdb->prepare(
	"SELECT id FROM {$stages} WHERE pipeline_id = %d AND stage_key = %s", $pipeline, $key
) );
$lead_new = $stage( $lead_pipeline, 'new' );
$deal_discovery = $stage( $deal_pipeline, 'discovery' );
$assert( $lead_new > 0 && $deal_discovery > 0, 'fixture default pipeline stages missing' );

$contact = wp_insert_post( [
	'post_type' => PostTypes::CONTACT,
	'post_status' => 'publish',
	'post_title' => 'Synthetic W2A Contact',
], true );
$assert( is_int( $contact ) && $contact > 0, 'synthetic Contact could not be created' );
$parties = [ [ 'party_type' => 'contact', 'party_id' => $contact, 'role' => 'primary_contact' ] ];
$lead_own = StaffActions::create_lead(
	[ 'title' => 'Synthetic W2A own Lead', 'pipeline_id' => $lead_pipeline, 'stage_id' => $lead_new ],
	$parties
);
$lead_other = StaffActions::create_lead(
	[ 'title' => 'Synthetic W2A other Lead', 'pipeline_id' => $lead_pipeline, 'stage_id' => $lead_new ],
	$parties
);
$opportunity = StaffActions::create_opportunity(
	[ 'title' => 'Synthetic W2A Opportunity', 'pipeline_id' => $deal_pipeline, 'stage_id' => $deal_discovery ],
	$parties
);
$assert( is_int( $lead_own ) && is_int( $lead_other ) && is_int( $opportunity ),
	'could not create synthetic Sales fixtures' );
$assert( 2 === StaffActions::qualify_lead( $lead_own, 1 ), 'W2A fixture qualification failed' );

// Synthetic legacy-content injection and other-owner metadata: test-only SQL,
// never an Admin UI write path and never outside the fresh isolated DB.
$lead_table = Schema::sales_leads_table();
$assert( false !== $wpdb->update( $lead_table,
	[ 'title' => '<img src=x onerror=alert(1)> & synthetic', 'owner_user_id' => 900001 ],
	[ 'id' => $lead_other ],
	[ '%s', '%d' ], [ '%d' ]
), 'could not establish the isolated HTML escaping/owner fixture' );

$render = static function ( callable $callback ): string {
	ob_start();
	try {
		$callback();
		return (string) ob_get_clean();
	} catch ( Throwable $error ) {
		ob_end_clean();
		throw $error;
	}
};

// No POST/AJAX or anonymous surface: all request parameters are GET read
// selectors and rendering remains inside WP Admin with C1 capability checks.
$_GET = [ 'page' => Menu::SALES_LEADS_SLUG, 'state' => 'qualified', 'mine' => '1' ];
$qualified = $render( [ SalesPage::class, 'render_leads' ] );
$assert( str_contains( $qualified, 'Synthetic W2A own Lead' )
	&& ! str_contains( $qualified, 'Synthetic W2A other Lead' )
	&& str_contains( $qualified, 'value="qualified"  selected="selected"' )
	&& str_contains( $qualified, 'value="1"  selected="selected"' ),
	'W2A Lead state/owner filter or cursor links failed' );
$assert( ! str_contains( $qualified, '<script' ) && ! str_contains( $qualified, 'onerror=' ),
	'W2A filtered list emitted HTML injection' );

$_GET = [ 'page' => Menu::SALES_LEADS_SLUG ];
$all = $render( [ SalesPage::class, 'render_leads' ] );
$assert( str_contains( $all, 'Synthetic W2A own Lead' )
	&& str_contains( $all, '&lt;img src=x onerror=alert(1)&gt; &amp; synthetic' )
	&& ! str_contains( $all, '<img src=x' )
	&& str_contains( $all, 'record=' . $lead_own ),
	'W2A list did not escape untrusted database title or link to read-only detail' );

$_GET = [ 'page' => Menu::SALES_LEADS_SLUG, 'record' => (string) $lead_own ];
$detail = $render( [ SalesPage::class, 'render_leads' ] );
$assert( str_contains( $detail, 'Synthetic W2A own Lead' )
	&& str_contains( $detail, 'Participants' )
	&& str_contains( $detail, 'contact #' . $contact )
	&& str_contains( $detail, 'Activity history' )
	&& ! str_contains( $detail, 'method="post"' )
	&& ! str_contains( $detail, 'type="submit"' ),
	'W2A Lead detail unexpectedly mutated data or omitted C1 history' );

$_GET = [ 'page' => Menu::SALES_OPPORTUNITIES_SLUG ];
$deals = $render( [ SalesPage::class, 'render_opportunities' ] );
$assert( str_contains( $deals, 'Synthetic W2A Opportunity' )
	&& ! str_contains( $deals, 'Synthetic W2A own Lead' ),
	'W2A Opportunity page leaked Lead content' );

$_GET = [ 'page' => Menu::SALES_OPPORTUNITIES_SLUG, 'record' => (string) $opportunity ];
$deal_detail = $render( [ SalesPage::class, 'render_opportunities' ] );
$assert( str_contains( $deal_detail, 'Synthetic W2A Opportunity' )
	&& str_contains( $deal_detail, 'Currency' )
	&& str_contains( $deal_detail, 'Activity history' ),
	'W2A Opportunity detail missing read-only commercial fields' );

foreach ( [
	[ 'page' => Menu::SALES_LEADS_SLUG, 'state' => 'won' ],
	[ 'page' => Menu::SALES_LEADS_SLUG, 'state' => [ 'open' ] ],
	[ 'page' => Menu::SALES_LEADS_SLUG, 'after' => '-1' ],
	[ 'page' => Menu::SALES_LEADS_SLUG, 'after' => '99999999999999999999999999' ],
	[ 'page' => Menu::SALES_LEADS_SLUG, 'mine' => [ '1' ] ],
	[ 'page' => Menu::SALES_LEADS_SLUG, 'record' => [ (string) $lead_own ] ],
] as $request ) {
	$_GET = $request;
	$invalid = $render( [ SalesPage::class, 'render_leads' ] );
	$assert( str_contains( $invalid, 'Unable to display CRM Sales records.' )
		&& ! str_contains( $invalid, 'Synthetic W2A own Lead' ),
		'invalid W2A URL did not fail closed before displaying record data' );
}

$_GET = [];
$assert( 2 === (int) $wpdb->get_var( $wpdb->prepare(
	'SELECT revision FROM ' . Schema::sales_leads_table() . ' WHERE id = %d', $lead_own
) ), 'read-only W2A page changed Lead revision' );
$assert( 1 === (int) $wpdb->get_var( $wpdb->prepare(
	'SELECT revision FROM ' . Schema::sales_opportunities_table() . ' WHERE id = %d', $opportunity
) ), 'read-only W2A page changed Opportunity revision' );

echo "PASS: real WordPress/MariaDB W2A native Leads/Opportunities staff list, filters, detail and escaped read-only history\n";

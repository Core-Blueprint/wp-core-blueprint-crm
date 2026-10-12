<?php
declare(strict_types=1);

$root = dirname( __DIR__ );
define( 'ABSPATH', $root . '/' );

$GLOBALS['crm_sales_runtime_ready'] = false;
$GLOBALS['crm_sales_capabilities'] = [];

function cb_crm_runtime_ready(): bool { return $GLOBALS['crm_sales_runtime_ready']; }
function current_user_can( string $capability ): bool {
	return in_array( $capability, $GLOBALS['crm_sales_capabilities'], true );
}

require $root . '/src/Capabilities.php';
require $root . '/src/Domain/Sales/Rules.php';
require $root . '/src/Domain/Sales/Access.php';

use CB\CRM\Domain\Sales\Access;
use CB\CRM\Domain\Sales\Rules;

$assert = static function ( bool $condition, string $message ): void {
	if ( ! $condition ) {
		fwrite( STDERR, "CRM Sales Foundation smoke failed: {$message}\n" );
		exit( 1 );
	}
};

$assert( Rules::valid_entity_type( Rules::LEAD ), 'Lead identity accepted' );
$assert( Rules::valid_entity_type( Rules::OPPORTUNITY ), 'Opportunity identity accepted' );
$assert( ! Rules::valid_entity_type( 'contact' ), 'Contact is not a sales entity' );
$assert( ! Rules::valid_entity_type( 'work_item' ), 'Work Item is not a sales entity' );
$assert( Rules::can_transition_lead( 'open', 'qualified' ), 'qualify lead' );
$assert( Rules::can_transition_lead( 'qualified', 'converted' ), 'convert qualified lead' );
$assert( Rules::can_transition_lead( 'open', 'disqualified' ), 'disqualify lead' );
$assert( ! Rules::can_transition_lead( 'open', 'converted' ), 'cannot skip qualification' );
$assert( ! Rules::can_transition_lead( 'converted', 'qualified' ), 'converted lead terminal' );
$assert( ! Rules::can_transition_lead( 'disqualified', 'converted' ), 'disqualified lead terminal' );
$assert( Rules::can_close_deal( 'open', 'won' ), 'win open deal' );
$assert( Rules::can_close_deal( 'open', 'lost' ), 'lose open deal' );
$assert( ! Rules::can_close_deal( 'won', 'lost' ), 'cannot silently change won to lost' );
$assert( ! Rules::can_close_deal( 'planned', 'won' ), 'Work task status is not a deal status' );
$assert( Rules::can_reopen_deal( 'lost', 'open' ), 'explicit reopen' );
$assert( ! Rules::can_reopen_deal( 'open', 'open' ), 'cannot reopen an open deal' );
$assert( Rules::valid_estimate( null, null ), 'no optional estimate' );
$assert( Rules::valid_estimate( 0, 'EUR' ), 'zero EUR minor amount' );
$assert( Rules::valid_estimate( 1099, 'USD' ), 'USD estimate is accepted' );
$assert( ! Rules::valid_estimate( -1, 'EUR' ), 'negative estimate blocked' );
$assert( ! Rules::valid_estimate( 500, '' ), 'amount without currency blocked' );
$assert( ! Rules::valid_estimate( null, 'EUR' ), 'currency without amount blocked' );
$assert( ! Rules::valid_estimate( 500, 'eur' ), 'noncanonical currency blocked' );
$assert( ! Rules::valid_estimate( 500, 'EURO' ), 'non-ISO shape blocked' );
$valid = [
	[ 'party_type' => 'organization', 'party_id' => 42, 'role' => 'other' ],
	[ 'party_type' => 'contact', 'party_id' => 7, 'role' => 'primary_contact' ],
	[ 'party_type' => 'contact', 'party_id' => 8, 'role' => 'decision_maker' ],
];
$assert( Rules::valid_participants( $valid ), 'multi-contact organization prospect supported' );
$assert( Rules::valid_participants( [ [ 'party_type' => 'organization', 'party_id' => 42 ] ] ), 'organization-only prospect supported' );
$assert( ! Rules::valid_participants( [] ), 'strict participant validation remains default' );
$assert( Rules::valid_participants( [], true ), 'creation explicitly permits empty participant set' );
$assert( ! Rules::valid_participants( [ [ 'party_type' => 'contact', 'party_id' => 0 ] ] ), 'unknown identity refused' );
$assert( ! Rules::valid_participants( [ [ 'party_type' => 'lead', 'party_id' => 2 ] ] ), 'lead cannot be a party' );
$assert( ! Rules::valid_participants( [ $valid[0], $valid[0] ] ), 'duplicate participant blocked' );
$assert( ! Rules::valid_participants( [ [ 'party_type' => 'contact', 'party_id' => 10, 'role' => 'admin' ] ] ), 'unknown role blocked' );

$assert( ! Access::can_view() && ! Access::can_manage(), 'fail closed with unavailable Base runtime' );
$GLOBALS['crm_sales_runtime_ready'] = true;
$assert( ! Access::can_view() && ! Access::can_manage(), 'fail closed without CRM capability' );
$GLOBALS['crm_sales_capabilities'] = [ 'cb_manage_crm' ];
$assert( ! Access::can_view() && ! Access::can_manage(), 'legacy CRM manager alone has no Sales access' );
$GLOBALS['crm_sales_capabilities'] = [ 'cb_manage_crm', 'cb_view_crm_sales' ];
$assert( Access::can_view() && ! Access::can_manage(), 'CRM staff with Sales view is read-only' );
$GLOBALS['crm_sales_capabilities'] = [ 'cb_manage_crm', 'cb_manage_crm_sales' ];
$assert( Access::can_view() && Access::can_manage(), 'Sales manager may also read' );
$GLOBALS['crm_sales_capabilities'] = [ 'cb_view_crm_sales', 'cb_manage_crm_sales' ];
$assert( ! Access::can_view() && ! Access::can_manage(), 'Sales capabilities cannot bypass canonical CRM grant' );
$GLOBALS['crm_sales_capabilities'] = [ 'cb_manage_crm', 'cb_view_crm_sales', 'cb_manage_crm_sales' ];
$GLOBALS['crm_sales_runtime_ready'] = false;
$assert( ! Access::can_view() && ! Access::can_manage(), 'capabilities cannot bypass unavailable runtime' );

echo "CRM Sales domain foundation smoke passed.\n";

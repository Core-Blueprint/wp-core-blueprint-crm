<?php
declare(strict_types=1);

namespace CB\CRM\Admin;

use CB\CRM\Capabilities;
use CB\CRM\Domain\Sales\Access;
use CB\CRM\Domain\Sales\Rules;
use CB\CRM\Repository\Sales\StaffProjections;

defined( 'ABSPATH' ) || exit;

/**
 * Wave 2A: native WordPress Admin read-only Sales workspace.
 *
 * No direct SQL, mutation, REST, AJAX, exports, or client-side data cache.
 * Lists and details are projected only by the capability-gated C1 service.
 * Any future writes must use the C2 StaffActions facade + explicit CSRF gate.
 */
final class SalesPage {
	private const PER_PAGE = 20;
	private const TEXT_DOMAIN = 'core-blueprint-crm';

	public static function render_leads(): void {
		self::render( Rules::LEAD );
	}

	public static function render_opportunities(): void {
		self::render( Rules::OPPORTUNITY );
	}

	private static function render( string $type ): void {
		if ( ! Access::can_view() || ! function_exists( 'get_current_user_id' ) || get_current_user_id() <= 0 ) {
			wp_die( esc_html__( 'You do not have permission to view CRM Sales.', self::TEXT_DOMAIN ) );
			return;
		}

		$slug = self::slug( $type );
		$label = self::heading( $type );
		$record = self::query_id( 'record', false );
		?>
		<div class="wrap cb-crm-sales">
			<h1><?php echo esc_html( $label ); ?></h1>
			<?php
			if ( $record instanceof \WP_Error ) {
				self::render_error();
			} elseif ( $record > 0 ) {
				self::render_detail( $type, $record );
			} else {
				self::render_list( $type, $slug );
			}
			?>
		</div>
		<?php
	}

	private static function render_list( string $type, string $slug ): void {
		$state = self::query_state( $type );
		$mine = self::query_mine();
		$after = self::query_id( 'after', true );
		if ( $state instanceof \WP_Error || $mine instanceof \WP_Error || $after instanceof \WP_Error ) {
			self::render_error();
			return;
		}

		$filters = [];
		if ( '' !== $state ) {
			$filters['state'] = $state;
		}
		if ( $mine ) {
			$filters['owner_user_id'] = get_current_user_id();
		}
		$page = StaffProjections::page( $type, $filters, $after, self::PER_PAGE );
		if ( $page instanceof \WP_Error ) {
			self::render_error();
			return;
		}

		$url = self::url( $slug );
		$states = self::states( $type );
		?>
		<p class="description"><?php esc_html_e( 'Internal staff records. Read-only overview.', self::TEXT_DOMAIN ); ?></p>
		<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" class="cb-crm-sales-filters">
			<input type="hidden" name="page" value="<?php echo esc_attr( $slug ); ?>">
			<label for="cb-crm-sales-state"><?php esc_html_e( 'Status', self::TEXT_DOMAIN ); ?></label>
			<select id="cb-crm-sales-state" name="state">
				<option value=""><?php esc_html_e( 'All statuses', self::TEXT_DOMAIN ); ?></option>
				<?php foreach ( $states as $value => $text ) : ?>
				<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $state, $value ); ?>><?php echo esc_html( $text ); ?></option>
				<?php endforeach; ?>
			</select>
			<label for="cb-crm-sales-mine"><?php esc_html_e( 'Owner', self::TEXT_DOMAIN ); ?></label>
			<select id="cb-crm-sales-mine" name="mine">
				<option value=""><?php esc_html_e( 'All records', self::TEXT_DOMAIN ); ?></option>
				<option value="1" <?php selected( $mine, true ); ?>><?php esc_html_e( 'Assigned to me', self::TEXT_DOMAIN ); ?></option>
			</select>
			<button type="submit" class="button"><?php esc_html_e( 'Filter' ); ?></button>
			<a class="button-link" href="<?php echo esc_url( $url ); ?>"><?php esc_html_e( 'Reset filters', self::TEXT_DOMAIN ); ?></a>
		</form>

		<div class="cb-crm-sales-table-scroll">
			<table class="wp-list-table widefat fixed striped cb-crm-sales-list">
				<caption class="screen-reader-text"><?php echo esc_html( self::heading( $type ) ); ?></caption>
				<thead>
					<tr>
						<th scope="col"><?php esc_html_e( 'Title' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Status', self::TEXT_DOMAIN ); ?></th>
						<th scope="col"><?php esc_html_e( 'Owner', self::TEXT_DOMAIN ); ?></th>
						<th scope="col"><?php esc_html_e( 'Next action', self::TEXT_DOMAIN ); ?></th>
						<th scope="col"><?php esc_html_e( 'Updated', self::TEXT_DOMAIN ); ?></th>
					</tr>
				</thead>
				<tbody>
				<?php if ( [] === $page['rows'] ) : ?>
					<tr><td colspan="5"><?php esc_html_e( 'No matching records.', self::TEXT_DOMAIN ); ?></td></tr>
				<?php else : ?>
					<?php foreach ( $page['rows'] as $item ) : ?>
					<tr>
						<td><strong><a href="<?php echo esc_url( self::url( $slug, [ 'record' => $item['id'] ] ) ); ?>"><?php echo esc_html( $item['title'] ); ?></a></strong>
							<span class="description"><?php echo esc_html( '#' . $item['id'] ); ?></span>
						</td>
						<td><?php echo esc_html( self::state_label( $type, $item['state'] ) ); ?></td>
						<td><?php echo esc_html( (int) $item['owner_user_id'] > 0 ? '#' . $item['owner_user_id'] : '—' ); ?></td>
						<td><?php echo esc_html( $item['next_action_on'] ?? '—' ); ?></td>
						<td><?php echo esc_html( $item['updated_at'] ); ?></td>
					</tr>
					<?php endforeach; ?>
				<?php endif; ?>
				</tbody>
			</table>
		</div>
		<nav class="cb-crm-sales-pagination" aria-label="<?php esc_attr_e( 'Sales pagination', self::TEXT_DOMAIN ); ?>">
			<?php if ( $after > 0 ) : ?>
				<a class="button" href="<?php echo esc_url( self::url( $slug, self::filter_args( $state, $mine ) ) ); ?>"><?php esc_html_e( 'First page', self::TEXT_DOMAIN ); ?></a>
			<?php endif; ?>
			<?php if ( $page['has_more'] && [] !== $page['rows'] ) : ?>
				<a class="button" href="<?php echo esc_url( self::url( $slug, array_merge( self::filter_args( $state, $mine ), [ 'after' => $page['cursor'] ] ) ) ); ?>"><?php esc_html_e( 'Next page', self::TEXT_DOMAIN ); ?></a>
			<?php endif; ?>
		</nav>
		<?php
	}

	private static function render_detail( string $type, int $id ): void {
		$detail = StaffProjections::detail( $type, $id );
		if ( $detail instanceof \WP_Error ) {
			self::render_error();
			return;
		}
		$slug = self::slug( $type );
		echo '<p><a href="' . esc_url( self::url( $slug ) ) . '">' . esc_html__( 'Back to overview', self::TEXT_DOMAIN ) . '</a></p>';
		if ( null === $detail ) {
			echo '<p>' . esc_html__( 'Record not found.', self::TEXT_DOMAIN ) . '</p>';
			return;
		}
		$item = $detail['record'];
		echo '<h2>' . esc_html( $item['title'] ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'Read-only detail. Changes are not available on this screen.', self::TEXT_DOMAIN ) . '</p>';
		echo '<div class="cb-crm-sales-table-scroll"><table class="widefat striped cb-crm-sales-detail"><tbody>';
		foreach ( [
			__( 'Status', self::TEXT_DOMAIN ) => self::state_label( $type, $item['state'] ),
			__( 'Owner', self::TEXT_DOMAIN ) => $item['owner_user_id'] > 0 ? '#' . $item['owner_user_id'] : '—',
			__( 'Pipeline ID', self::TEXT_DOMAIN ) => (string) $item['pipeline_id'],
			__( 'Stage ID', self::TEXT_DOMAIN ) => (string) $item['stage_id'],
			__( 'Revision', self::TEXT_DOMAIN ) => (string) $item['revision'],
			__( 'Next action', self::TEXT_DOMAIN ) => $item['next_action_on'] ?? '—',
			__( 'Created', self::TEXT_DOMAIN ) => $item['created_at'],
			__( 'Updated', self::TEXT_DOMAIN ) => $item['updated_at'],
		] as $name => $value ) {
			echo '<tr><th scope="row">' . esc_html( $name ) . '</th><td>' . esc_html( (string) $value ) . '</td></tr>';
		}
		if ( Rules::LEAD === $type ) {
			self::detail_line( __( 'Source', self::TEXT_DOMAIN ), $item['source'] );
			self::detail_line( __( 'Converted Opportunity ID', self::TEXT_DOMAIN ), $item['converted_opportunity_id'] );
		} else {
			self::detail_line( __( 'Amount (minor units)', self::TEXT_DOMAIN ), $item['amount_minor'] );
			self::detail_line( __( 'Currency', self::TEXT_DOMAIN ), $item['currency'] );
			self::detail_line( __( 'Source Lead ID', self::TEXT_DOMAIN ), $item['source_lead_id'] );
		}
		echo '</tbody></table></div>';

		echo '<h3>' . esc_html__( 'Participants', self::TEXT_DOMAIN ) . '</h3>';
		if ( [] === $detail['participants'] ) {
			echo '<p>' . esc_html__( 'No participants.', self::TEXT_DOMAIN ) . '</p>';
		} else {
			echo '<ul class="ul-disc">';
			foreach ( $detail['participants'] as $participant ) {
				echo '<li>' . esc_html( $participant['party_type'] . ' #' . $participant['party_id'] . ' (' . $participant['role'] . ')' ) . '</li>';
			}
			echo '</ul>';
		}
		$history_after = self::query_id( 'history_after', true );
		if ( $history_after instanceof \WP_Error ) {
			self::render_error();
			return;
		}
		$history = StaffProjections::history( $type, $id, $history_after, self::PER_PAGE );
		echo '<h3>' . esc_html__( 'Activity history', self::TEXT_DOMAIN ) . '</h3>';
		if ( $history instanceof \WP_Error ) {
			self::render_error();
			return;
		}
		echo '<div class="cb-crm-sales-table-scroll"><table class="widefat striped cb-crm-sales-history">';
		echo '<thead><tr><th scope="col">' . esc_html__( 'Event', self::TEXT_DOMAIN ) . '</th><th scope="col">' . esc_html__( 'Revision', self::TEXT_DOMAIN ) . '</th><th scope="col">' . esc_html__( 'Actor ID', self::TEXT_DOMAIN ) . '</th><th scope="col">' . esc_html__( 'Created', self::TEXT_DOMAIN ) . '</th></tr></thead><tbody>';
		if ( [] === $history['rows'] ) {
			echo '<tr><td colspan="4">' . esc_html__( 'No history entries.', self::TEXT_DOMAIN ) . '</td></tr>';
		}
		foreach ( $history['rows'] as $event ) {
			echo '<tr><td>' . esc_html( $event['event_type'] ) . '</td><td>' . esc_html( (string) $event['revision'] ) . '</td><td>' . esc_html( (string) $event['actor_user_id'] ) . '</td><td>' . esc_html( $event['created_at'] ) . '</td></tr>';
		}
		echo '</tbody></table></div>';
		echo '<nav class="cb-crm-sales-pagination" aria-label="' . esc_attr__( 'Sales pagination', self::TEXT_DOMAIN ) . '">';
		if ( $history_after > 0 ) {
			echo '<a class="button" href="' . esc_url( self::url( $slug, [ 'record' => $id ] ) ) . '">' . esc_html__( 'First page', self::TEXT_DOMAIN ) . '</a> ';
		}
		if ( $history['has_more'] && [] !== $history['rows'] ) {
			echo '<a class="button" href="' . esc_url( self::url( $slug, [ 'record' => $id, 'history_after' => $history['cursor'] ] ) ) . '">' . esc_html__( 'Next page', self::TEXT_DOMAIN ) . '</a>';
		}
		echo '</nav>';
	}

	private static function detail_line( string $label, mixed $value ): void {
		echo '<tr><th scope="row">' . esc_html( $label ) . '</th><td>' .
			esc_html( null === $value || '' === $value ? '—' : (string) $value ) . '</td></tr>';
	}

	private static function render_error(): void {
		echo '<div class="notice notice-error inline" role="alert"><p>' .
			esc_html__( 'Unable to display CRM Sales records. Check the filters or your access.', self::TEXT_DOMAIN ) .
			'</p></div>';
	}

	/** Strictly parse only scalar non-negative integer query values. */
	private static function query_id( string $key, bool $allow_zero ): int|\WP_Error {
		if ( ! isset( $_GET[$key] ) ) {
			return 0;
		}
		if ( ! is_string( $_GET[$key] ) ) {
			return new \WP_Error( 'crm_sales_invalid_query' );
		}
		$raw = wp_unslash( $_GET[$key] );
		if ( ! preg_match( '/^(0|[1-9][0-9]{0,17})$/D', $raw ) ) {
			return new \WP_Error( 'crm_sales_invalid_query' );
		}
		$id = filter_var( $raw, FILTER_VALIDATE_INT, [ 'options' => [ 'min_range' => 0 ] ] );
		if ( false === $id || ( ! $allow_zero && 0 === $id ) ) {
			return new \WP_Error( 'crm_sales_invalid_query' );
		}
		return $id;
	}

	private static function query_state( string $type ): string|\WP_Error {
		if ( ! isset( $_GET['state'] ) ) {
			return '';
		}
		if ( ! is_string( $_GET['state'] ) ) {
			return new \WP_Error( 'crm_sales_invalid_query' );
		}
		$state = sanitize_key( wp_unslash( $_GET['state'] ) );
		if ( '' !== $state && ! array_key_exists( $state, self::states( $type ) ) ) {
			return new \WP_Error( 'crm_sales_invalid_query' );
		}
		return $state;
	}

	private static function query_mine(): bool|\WP_Error {
		if ( ! isset( $_GET['mine'] ) ) {
			return false;
		}
		if ( ! is_string( $_GET['mine'] ) || ! in_array( wp_unslash( $_GET['mine'] ), [ '', '1' ], true ) ) {
			return new \WP_Error( 'crm_sales_invalid_query' );
		}
		return '1' === wp_unslash( $_GET['mine'] );
	}

	/** @return array<string,string> */
	private static function states( string $type ): array {
		return Rules::LEAD === $type ? [
			Rules::LEAD_OPEN => __( 'Open', self::TEXT_DOMAIN ),
			Rules::LEAD_QUALIFIED => __( 'Qualified', self::TEXT_DOMAIN ),
			Rules::LEAD_DISQUALIFIED => __( 'Disqualified', self::TEXT_DOMAIN ),
			Rules::LEAD_CONVERTED => __( 'Converted', self::TEXT_DOMAIN ),
		] : [
			Rules::DEAL_OPEN => __( 'Open', self::TEXT_DOMAIN ),
			Rules::DEAL_WON => __( 'Won', self::TEXT_DOMAIN ),
			Rules::DEAL_LOST => __( 'Lost', self::TEXT_DOMAIN ),
		];
	}

	private static function state_label( string $type, string $state ): string {
		return self::states( $type )[$state] ?? $state;
	}

	private static function heading( string $type ): string {
		return Rules::LEAD === $type ? __( 'Leads', self::TEXT_DOMAIN ) : __( 'Opportunities', self::TEXT_DOMAIN );
	}

	private static function slug( string $type ): string {
		return Rules::LEAD === $type ? Menu::SALES_LEADS_SLUG : Menu::SALES_OPPORTUNITIES_SLUG;
	}

	/** @param array<string,int|string> $args */
	private static function url( string $slug, array $args = [] ): string {
		return add_query_arg( array_merge( [ 'page' => $slug ], $args ), admin_url( 'admin.php' ) );
	}

	/** @return array<string,string> */
	private static function filter_args( string $state, bool $mine ): array {
		$args = [];
		if ( '' !== $state ) {
			$args['state'] = $state;
		}
		if ( $mine ) {
			$args['mine'] = '1';
		}
		return $args;
	}
}

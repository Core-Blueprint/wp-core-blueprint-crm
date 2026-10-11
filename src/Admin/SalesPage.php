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

	public static function render_leads(): void {
		self::render( Rules::LEAD );
	}

	public static function render_opportunities(): void {
		self::render( Rules::OPPORTUNITY );
	}

	private static function render( string $type ): void {
		if ( ! Access::can_view() || ! function_exists( 'get_current_user_id' ) || get_current_user_id() <= 0 ) {
			wp_die( esc_html__( 'You do not have permission to view CRM Sales.', 'core-blueprint-crm' ) );
			return;
		}

		$slug = self::slug( $type );
		$label = self::heading( $type );
		$record = self::query_id( 'record', false );
		?>
		<div class="wrap cb-crm-sales">
			<h1><?php echo esc_html( $label ); ?></h1>
			<?php if ( isset( $_GET['sales_notice'] ) && is_string( $_GET['sales_notice'] ) && 'updated' === wp_unslash( $_GET['sales_notice'] ) ) : ?>
				<div class="notice notice-success inline" role="status"><p><?php esc_html_e( 'Sales record updated.', 'core-blueprint-crm' ); ?></p></div>
			<?php endif; ?>
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
		<p class="description"><?php esc_html_e( 'Internal staff records. Read-only overview.', 'core-blueprint-crm' ); ?></p>
		<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" class="cb-crm-sales-filters">
			<input type="hidden" name="page" value="<?php echo esc_attr( $slug ); ?>">
			<label for="cb-crm-sales-state"><?php esc_html_e( 'Status', 'core-blueprint-crm' ); ?></label>
			<select id="cb-crm-sales-state" name="state">
				<option value=""><?php esc_html_e( 'All statuses', 'core-blueprint-crm' ); ?></option>
				<?php foreach ( $states as $value => $text ) : ?>
				<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $state, $value ); ?>><?php echo esc_html( $text ); ?></option>
				<?php endforeach; ?>
			</select>
			<label for="cb-crm-sales-mine"><?php esc_html_e( 'Owner', 'core-blueprint-crm' ); ?></label>
			<select id="cb-crm-sales-mine" name="mine">
				<option value=""><?php esc_html_e( 'All records', 'core-blueprint-crm' ); ?></option>
				<option value="1" <?php selected( $mine, true ); ?>><?php esc_html_e( 'Assigned to me', 'core-blueprint-crm' ); ?></option>
			</select>
			<button type="submit" class="button"><?php esc_html_e( 'Filter', 'core-blueprint-crm' ); ?></button>
			<a class="button-link" href="<?php echo esc_url( $url ); ?>"><?php esc_html_e( 'Reset filters', 'core-blueprint-crm' ); ?></a>
		</form>

		<div class="cb-crm-sales-table-scroll">
			<table class="wp-list-table widefat fixed striped cb-crm-sales-list">
				<caption class="screen-reader-text"><?php echo esc_html( self::heading( $type ) ); ?></caption>
				<thead>
					<tr>
						<th scope="col"><?php esc_html_e( 'Title', 'core-blueprint-crm' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Status', 'core-blueprint-crm' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Owner', 'core-blueprint-crm' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Next action', 'core-blueprint-crm' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Updated', 'core-blueprint-crm' ); ?></th>
					</tr>
				</thead>
				<tbody>
				<?php if ( [] === $page['rows'] ) : ?>
					<tr><td colspan="5"><?php esc_html_e( 'No matching records.', 'core-blueprint-crm' ); ?></td></tr>
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
		<nav class="cb-crm-sales-pagination" aria-label="<?php esc_attr_e( 'Sales pagination', 'core-blueprint-crm' ); ?>">
			<?php if ( $after > 0 ) : ?>
				<a class="button" href="<?php echo esc_url( self::url( $slug, self::filter_args( $state, $mine ) ) ); ?>"><?php esc_html_e( 'First page', 'core-blueprint-crm' ); ?></a>
			<?php endif; ?>
			<?php if ( $page['has_more'] && [] !== $page['rows'] ) : ?>
				<a class="button" href="<?php echo esc_url( self::url( $slug, array_merge( self::filter_args( $state, $mine ), [ 'after' => $page['cursor'] ] ) ) ); ?>"><?php esc_html_e( 'Next page', 'core-blueprint-crm' ); ?></a>
			<?php endif; ?>
		</nav>
		<?php
	}

	private static function render_detail( string $type, int $id ): void {
		$history_after = self::query_id( 'history_after', true );
		if ( $history_after instanceof \WP_Error ) {
			self::render_error();
			return;
		}
		$detail = StaffProjections::detail( $type, $id );
		if ( $detail instanceof \WP_Error ) {
			self::render_error();
			return;
		}
		$slug = self::slug( $type );
		echo '<p><a href="' . esc_url( self::url( $slug ) ) . '">' . esc_html__( 'Back to overview', 'core-blueprint-crm' ) . '</a></p>';
		if ( null === $detail ) {
			echo '<p>' . esc_html__( 'Record not found.', 'core-blueprint-crm' ) . '</p>';
			return;
		}
		$item = $detail['record'];
		echo '<h2>' . esc_html( $item['title'] ) . '</h2>';
		if ( ! Access::can_manage() ) {
		echo '<p class="description">' . esc_html__( 'Read-only detail. Changes are not available on this screen.', 'core-blueprint-crm' ) . '</p>';
		}
		echo '<div class="cb-crm-sales-table-scroll"><table class="widefat striped cb-crm-sales-detail"><tbody>';
		foreach ( [
			__( 'Status', 'core-blueprint-crm' ) => self::state_label( $type, $item['state'] ),
			__( 'Owner', 'core-blueprint-crm' ) => $item['owner_user_id'] > 0 ? '#' . $item['owner_user_id'] : '—',
			__( 'Pipeline ID', 'core-blueprint-crm' ) => (string) $item['pipeline_id'],
			__( 'Stage ID', 'core-blueprint-crm' ) => (string) $item['stage_id'],
			__( 'Revision', 'core-blueprint-crm' ) => (string) $item['revision'],
			__( 'Next action', 'core-blueprint-crm' ) => $item['next_action_on'] ?? '—',
			__( 'Created', 'core-blueprint-crm' ) => $item['created_at'],
			__( 'Updated', 'core-blueprint-crm' ) => $item['updated_at'],
		] as $name => $value ) {
			echo '<tr><th scope="row">' . esc_html( $name ) . '</th><td>' . esc_html( (string) $value ) . '</td></tr>';
		}
		if ( Rules::LEAD === $type ) {
			self::detail_line( __( 'Source', 'core-blueprint-crm' ), $item['source'] );
			self::detail_line( __( 'Converted Opportunity ID', 'core-blueprint-crm' ), $item['converted_opportunity_id'] );
		} else {
			self::detail_line( __( 'Amount (minor units)', 'core-blueprint-crm' ), $item['amount_minor'] );
			self::detail_line( __( 'Currency', 'core-blueprint-crm' ), $item['currency'] );
			self::detail_line( __( 'Source Lead ID', 'core-blueprint-crm' ), $item['source_lead_id'] );
		}
		echo '</tbody></table></div>';

		SalesStatusActions::render_for_record( $type, $item );

		echo '<h3>' . esc_html__( 'Participants', 'core-blueprint-crm' ) . '</h3>';
		if ( [] === $detail['participants'] ) {
			echo '<p>' . esc_html__( 'No participants.', 'core-blueprint-crm' ) . '</p>';
		} else {
			echo '<ul class="ul-disc">';
			foreach ( $detail['participants'] as $participant ) {
				echo '<li>' . esc_html( $participant['party_type'] . ' #' . $participant['party_id'] . ' (' . $participant['role'] . ')' ) . '</li>';
			}
			echo '</ul>';
		}
		$history = StaffProjections::history( $type, $id, $history_after, self::PER_PAGE );
		echo '<h3>' . esc_html__( 'Activity history', 'core-blueprint-crm' ) . '</h3>';
		if ( $history instanceof \WP_Error ) {
			self::render_error();
			return;
		}
		echo '<div class="cb-crm-sales-table-scroll"><table class="widefat striped cb-crm-sales-history">';
		echo '<thead><tr><th scope="col">' . esc_html__( 'Event', 'core-blueprint-crm' ) . '</th><th scope="col">' . esc_html__( 'Revision', 'core-blueprint-crm' ) . '</th><th scope="col">' . esc_html__( 'Actor ID', 'core-blueprint-crm' ) . '</th><th scope="col">' . esc_html__( 'Created', 'core-blueprint-crm' ) . '</th></tr></thead><tbody>';
		if ( [] === $history['rows'] ) {
			echo '<tr><td colspan="4">' . esc_html__( 'No history entries.', 'core-blueprint-crm' ) . '</td></tr>';
		}
		foreach ( $history['rows'] as $event ) {
			echo '<tr><td>' . esc_html( $event['event_type'] ) . '</td><td>' . esc_html( (string) $event['revision'] ) . '</td><td>' . esc_html( (string) $event['actor_user_id'] ) . '</td><td>' . esc_html( $event['created_at'] ) . '</td></tr>';
		}
		echo '</tbody></table></div>';
		echo '<nav class="cb-crm-sales-pagination" aria-label="' . esc_attr__( 'Sales pagination', 'core-blueprint-crm' ) . '">';
		if ( $history_after > 0 ) {
			echo '<a class="button" href="' . esc_url( self::url( $slug, [ 'record' => $id ] ) ) . '">' . esc_html__( 'First page', 'core-blueprint-crm' ) . '</a> ';
		}
		if ( $history['has_more'] && [] !== $history['rows'] ) {
			echo '<a class="button" href="' . esc_url( self::url( $slug, [ 'record' => $id, 'history_after' => $history['cursor'] ] ) ) . '">' . esc_html__( 'Next page', 'core-blueprint-crm' ) . '</a>';
		}
		echo '</nav>';
	}

	private static function detail_line( string $label, mixed $value ): void {
		echo '<tr><th scope="row">' . esc_html( $label ) . '</th><td>' .
			esc_html( null === $value || '' === $value ? '—' : (string) $value ) . '</td></tr>';
	}

	private static function render_error(): void {
		echo '<div class="notice notice-error inline" role="alert"><p>' .
			esc_html__( 'Unable to display CRM Sales records. Check the filters or your access.', 'core-blueprint-crm' ) .
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
			Rules::LEAD_OPEN => __( 'Open', 'core-blueprint-crm' ),
			Rules::LEAD_QUALIFIED => __( 'Qualified', 'core-blueprint-crm' ),
			Rules::LEAD_DISQUALIFIED => __( 'Disqualified', 'core-blueprint-crm' ),
			Rules::LEAD_CONVERTED => __( 'Converted', 'core-blueprint-crm' ),
		] : [
			Rules::DEAL_OPEN => __( 'Open', 'core-blueprint-crm' ),
			Rules::DEAL_WON => __( 'Won', 'core-blueprint-crm' ),
			Rules::DEAL_LOST => __( 'Lost', 'core-blueprint-crm' ),
		];
	}

	private static function state_label( string $type, string $state ): string {
		return self::states( $type )[$state] ?? $state;
	}

	private static function heading( string $type ): string {
		return Rules::LEAD === $type ? __( 'Leads', 'core-blueprint-crm' ) : __( 'Opportunities', 'core-blueprint-crm' );
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

<?php
declare(strict_types=1);

namespace CB\CRM\CLI;

use CB\CRM\Repository\LifecycleRecovery;
use CB\CRM\Repository\RecoveryOversight;
use CB\CRM\Repository\UserAnonymizationRecovery;

defined( 'ABSPATH' ) || exit;

/**
 * Single-record, permission-checked CRM recovery operations.
 *
 * WP-CLI global --user must identify a real approved operator. There are no
 * force/bulk actions and no exposure of the original option payload.
 */
final class RecoveryCommand {
	/** wp cb-crm-recovery status [--after=0] [--limit=20] --user=<approved_operator> */
	public function status( array $args, array $assoc_args ): void {
		self::authorize();
		$after = self::integer_arg( $assoc_args, 'after', 0, 0, PHP_INT_MAX );
		$limit = self::integer_arg( $assoc_args, 'limit', 20, 1, RecoveryOversight::MAX_PAGE_SIZE );
		$page = RecoveryOversight::page( $after, $limit );
		if ( is_wp_error( $page ) ) {
			\WP_CLI::error( $page->get_error_message() );
			return;
		}
		\WP_CLI::line( 'kind	id	state	scheduled	option_id' );
		foreach ( $page['rows'] as $entry ) {
			\WP_CLI::line( implode( "\t", [
				$entry['kind'], (string) $entry['id'], $entry['state'], $entry['scheduled'], (string) $entry['option_id'],
			] ) );
		}
		\WP_CLI::line( 'NEXT_CURSOR=' . $page['cursor'] );
		\WP_CLI::line( 'HAS_MORE=' . ( $page['has_more'] ? 'yes' : 'no' ) );
	}

	/**
	 * wp cb-crm-recovery retry --kind=user|contact|organization --id=N
	 *   --confirm=1 --user=<approved_operator>
	 */
	public function retry( array $args, array $assoc_args ): void {
		self::authorize();
		if ( ( $assoc_args['confirm'] ?? null ) !== '1' ) {
			\WP_CLI::error( 'Single-ID recovery requires --confirm=1; no bulk or force retry is supported.' );
			return;
		}
		$kind = (string) ( $assoc_args['kind'] ?? '' );
		$id = self::integer_arg( $assoc_args, 'id', 0, 1, PHP_INT_MAX );
		$inspection = RecoveryOversight::inspect( $kind, $id );
		if ( is_wp_error( $inspection ) ) {
			\WP_CLI::error( $inspection->get_error_message() );
			return;
		}
		if ( 'ready' !== $inspection['state'] ) {
			\WP_CLI::error( 'Recovery refused: the durable intent is not ready for replay (' . $inspection['state'] . ').' );
			return;
		}
		$success = 'user' === $kind
			? UserAnonymizationRecovery::retry( $id )
			: LifecycleRecovery::retry( $id );
		if ( ! $success ) {
			\WP_CLI::error( 'Recovery failed; original durable intent remains for controlled reprocessing.' );
			return;
		}
		\WP_CLI::success( 'Single CRM recovery completed for ' . $kind . ' #' . $id . '.' );
	}

	private static function authorize(): void {
		if ( ! function_exists( 'cb_crm_runtime_ready' ) || ! \cb_crm_runtime_ready()
			|| get_current_user_id() <= 0 || ! current_user_can( 'cb_manage_crm' ) ) {
			\WP_CLI::error( 'An authenticated and approved CRM operator is required. Use WP-CLI --user.' );
		}
	}

	private static function integer_arg( array $opts, string $key, int $default, int $min, int $max ): int {
		if ( ! array_key_exists( $key, $opts ) ) {
			return $default;
		}
		$value = filter_var( $opts[ $key ], FILTER_VALIDATE_INT, [
			'options' => [ 'min_range' => $min, 'max_range' => $max ],
		] );
		if ( false === $value ) {
			\WP_CLI::error( 'Invalid --' . $key . ' parameter.' );
			return $default;
		}
		return $value;
	}
}

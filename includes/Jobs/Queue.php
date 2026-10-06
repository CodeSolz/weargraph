<?php
/**
 * Thin wrapper over Action Scheduler.
 *
 * @package WearGraph
 */

namespace WearGraph\Jobs;

defined( 'ABSPATH' ) || exit;

/**
 * Group names mirror the SaaS queue design (blueprint §29), so moving a job to
 * BullMQ later is a one-to-one mapping. Nothing outside this class calls as_*().
 */
final class Queue {

	public const GROUP_TRYON     = 'weargraph-tryon-fast';
	public const GROUP_STUDIO    = 'weargraph-studio';
	public const GROUP_CATALOG   = 'weargraph-catalog-scan';
	public const GROUP_CLEANUP   = 'weargraph-cleanup';
	public const GROUP_ANALYTICS = 'weargraph-analytics';

	/**
	 * Every group this plugin owns (Pro adds its own and cleans them up itself).
	 *
	 * @return string[]
	 */
	public static function groups(): array {
		return array( self::GROUP_TRYON, self::GROUP_STUDIO, self::GROUP_CATALOG, self::GROUP_CLEANUP, self::GROUP_ANALYTICS );
	}

	/**
	 * Whether Action Scheduler has finished loading.
	 */
	public static function ready(): bool {
		return function_exists( 'as_enqueue_async_action' );
	}

	/**
	 * Run a job as soon as possible.
	 *
	 * @param string       $hook  Action hook.
	 * @param array<mixed> $args  Arguments (must be JSON-serialisable scalars/arrays).
	 * @param string       $group Queue group.
	 * @return int Action id, 0 on failure.
	 */
	public static function enqueue_now( string $hook, array $args, string $group ): int {
		if ( ! self::ready() ) {
			return 0;
		}

		return (int) as_enqueue_async_action( $hook, $args, $group );
	}

	/**
	 * Run a job at a timestamp.
	 *
	 * @param int          $timestamp Unix time.
	 * @param string       $hook      Action hook.
	 * @param array<mixed> $args      Arguments.
	 * @param string       $group     Queue group.
	 */
	public static function enqueue_at( int $timestamp, string $hook, array $args, string $group ): int {
		if ( ! self::ready() ) {
			return 0;
		}

		return (int) as_schedule_single_action( $timestamp, $hook, $args, $group );
	}

	/**
	 * Schedule a recurring job once; a no-op when it is already scheduled.
	 *
	 * @param string $hook     Action hook.
	 * @param int    $interval Seconds between runs.
	 * @param string $group    Queue group.
	 */
	public static function ensure_recurring( string $hook, int $interval, string $group ): void {
		if ( ! self::ready() || as_has_scheduled_action( $hook, array(), $group ) ) {
			return;
		}

		as_schedule_recurring_action( time() + $interval, $interval, $hook, array(), $group );
	}

	/**
	 * Whether a pending action with these args exists (used to debounce).
	 *
	 * @param string       $hook  Action hook.
	 * @param array<mixed> $args  Arguments.
	 * @param string       $group Queue group.
	 */
	public static function is_pending( string $hook, array $args, string $group ): bool {
		return self::ready() && as_has_scheduled_action( $hook, $args, $group );
	}

	/**
	 * Cancel every pending action for one of our hooks, whatever its arguments.
	 * (With a group and empty args, Action Scheduler only matches actions whose args
	 * are exactly empty, so the hook alone is used; WearGraph hooks are ours anyway.)
	 *
	 * @param string $hook  Action hook (must be a weargraph_* hook).
	 * @param string $group Queue group (documentation only).
	 */
	public static function unschedule_hook( string $hook, string $group = '' ): void {
		unset( $group );
		if ( function_exists( 'as_unschedule_all_actions' ) && 0 === strpos( $hook, 'weargraph_' ) ) {
			as_unschedule_all_actions( $hook );
		}
	}

	/**
	 * WearGraph actions still pending well after they were due: background processing
	 * is not running (WP-Cron disabled with no server cron, or loopbacks blocked).
	 *
	 * @param int $late_by Seconds past due.
	 */
	public static function overdue( int $late_by ): int {
		if ( ! function_exists( 'as_get_scheduled_actions' ) ) {
			return 0;
		}

		$count = 0;
		foreach ( self::groups() as $group ) {
			$count += count(
				as_get_scheduled_actions(
					array(
						'status'       => 'pending',
						'group'        => $group,
						'date'         => gmdate( 'Y-m-d H:i:s', time() - $late_by ),
						'date_compare' => '<=',
						'per_page'     => 5,
					),
					'ids'
				)
			);
		}

		return $count;
	}

	/**
	 * Cancel every pending WearGraph action.
	 */
	public static function unschedule_all(): void {
		if ( ! function_exists( 'as_unschedule_all_actions' ) ) {
			return;
		}

		foreach ( self::groups() as $group ) {
			as_unschedule_all_actions( '', array(), $group );
		}
	}
}

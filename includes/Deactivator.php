<?php
/**
 * Deactivation routine.
 *
 * @package WearGraph
 */

namespace WearGraph;

defined( 'ABSPATH' ) || exit;

use WearGraph\Jobs\Queue;

/**
 * Stops background work. Data stays until uninstall.
 */
final class Deactivator {

	/**
	 * Deactivation hook callback.
	 */
	public static function deactivate(): void {
		Queue::unschedule_all();
		delete_option( 'weargraph_do_activation_redirect' );
		self::delete_transients();
	}

	/**
	 * Remove WearGraph transients.
	 */
	private static function delete_transients(): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- bulk cleanup of our own transients.
		$wpdb->query(
			$wpdb->prepare(
				'DELETE FROM %i WHERE option_name LIKE %s OR option_name LIKE %s',
				$wpdb->options,
				$wpdb->esc_like( '_transient_weargraph_' ) . '%',
				$wpdb->esc_like( '_transient_timeout_weargraph_' ) . '%'
			)
		);
	}
}

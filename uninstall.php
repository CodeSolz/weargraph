<?php
/**
 * Uninstall routine.
 *
 * Shopper images are always removed — they must never outlive the plugin.
 * Everything else (tables, settings, generated media records) is removed only
 * when the merchant opted in via "Delete all data on uninstall".
 *
 * @package WearGraph
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

$weargraph_autoload = __DIR__ . '/vendor/autoload.php';
if ( ! file_exists( $weargraph_autoload ) ) {
	return;
}
require_once $weargraph_autoload;

\WearGraph\Storage\PrivateStore::delete_all();

if ( function_exists( 'as_unschedule_all_actions' ) ) {
	\WearGraph\Jobs\Queue::unschedule_all();
}

$weargraph_settings = get_option( 'weargraph_settings', array() );
if ( empty( $weargraph_settings['delete_data_on_uninstall'] ) ) {
	return;
}

\WearGraph\Database\Migrator::rollback( 0 );
\WearGraph\Support\Capabilities::revoke();

global $wpdb;

// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one-off uninstall cleanup.
$weargraph_options = $wpdb->get_col(
	$wpdb->prepare(
		'SELECT option_name FROM %i WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s',
		$wpdb->options,
		$wpdb->esc_like( 'weargraph_' ) . '%',
		$wpdb->esc_like( '_transient_weargraph_' ) . '%',
		$wpdb->esc_like( '_transient_timeout_weargraph_' ) . '%'
	)
);

foreach ( $weargraph_options as $weargraph_option ) {
	delete_option( $weargraph_option );
}

// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one-off uninstall cleanup.
$wpdb->query(
	$wpdb->prepare(
		'DELETE FROM %i WHERE meta_key LIKE %s',
		$wpdb->postmeta,
		$wpdb->esc_like( '_weargraph_' ) . '%'
	)
);

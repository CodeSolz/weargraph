<?php
/**
 * Activation routine.
 *
 * @package WearGraph
 */

namespace WearGraph;

defined( 'ABSPATH' ) || exit;

use WearGraph\Database\Migrator;
use WearGraph\Storage\PrivateStore;
use WearGraph\Support\Capabilities;
use WearGraph\Support\Settings;

/**
 * Creates tables, capabilities, default options and the private upload directory.
 */
final class Activator {

	/**
	 * Activation hook callback.
	 */
	public static function activate(): void {
		Migrator::migrate( (int) WEARGRAPH_DB_VERSION );
		Capabilities::grant();

		add_option( Settings::OPTION, array(), '', false );
		add_option( 'weargraph_activated_at', time(), '', false );
		update_option( 'weargraph_version', WEARGRAPH_VERSION, true );

		PrivateStore::ensure_dir();
	}
}

<?php
/**
 * Whether the separate WearGraph Pro add-on is running, and where to get it.
 *
 * @package WearGraph
 */

namespace WearGraph\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Nothing in WearGraph is gated on this: every free feature works the same either
 * way. It only decides whether the two "Upgrade to Pro" pointers (the admin submenu
 * item and the Plugins-screen link) are shown. Keep it to those two call sites
 * (to-do/09-WPORG-COMPLIANCE.md C8).
 */
final class Pro {

	private const UPGRADE_URL = 'https://codesolz.net/our-products/wordpress-plugin/weargraph';

	/**
	 * Fallback plugin file, for when Pro is present but stopped before defining its constant.
	 */
	private const PLUGIN_FILE = 'weargraph-pro/weargraph-pro.php';

	/**
	 * Whether Pro is running.
	 */
	public static function is_active(): bool {
		if ( defined( 'WEARGRAPH_PRO_VERSION' ) ) {
			return true;
		}
		if ( ! function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		return is_plugin_active( self::PLUGIN_FILE );
	}

	/**
	 * Product page URL.
	 *
	 * @param string $source Where the click came from (campaign tracking).
	 */
	public static function upgrade_url( string $source ): string {
		return add_query_arg(
			array(
				'utm_source'   => $source,
				'utm_medium'   => 'wp-dash',
				'utm_campaign' => 'gopro',
			),
			self::UPGRADE_URL
		);
	}
}

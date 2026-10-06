<?php
/**
 * Plugin Name:          WearGraph – AI Virtual Try-On & Product Studio for WooCommerce
 * Plugin URI:           https://codesolz.net/our-products/wordpress-plugin/weargraph
 * Description:          Let shoppers see products on themselves with an AI fitting room, generate on-model product images with your own AI key, and find out which products are ready for virtual try-on.
 * Version:              1.0.0
 * Requires at least:    6.5
 * Requires PHP:         7.4
 * Requires Plugins:     woocommerce
 * WC requires at least: 8.5
 * WC tested up to:      11.0
 * Author:               CodeSolz
 * Author URI:           https://codesolz.net
 * License:              GPL v2 or later
 * License URI:          https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:          weargraph
 * Domain Path:          /languages
 *
 * @package WearGraph
 */

defined( 'ABSPATH' ) || exit;

define( 'WEARGRAPH_VERSION', '1.0.0' );
define( 'WEARGRAPH_DB_VERSION', '3' );
define( 'WEARGRAPH_FILE', __FILE__ );
define( 'WEARGRAPH_DIR', plugin_dir_path( __FILE__ ) );
define( 'WEARGRAPH_URL', plugin_dir_url( __FILE__ ) );
define( 'WEARGRAPH_BASENAME', plugin_basename( __FILE__ ) );
define( 'WEARGRAPH_MIN_PHP', '7.4' );
define( 'WEARGRAPH_MIN_WC', '8.5' );

/**
 * Print an error notice for a requirement WearGraph cannot run without.
 *
 * @param string $message Already-translated message.
 */
function weargraph_requirement_notice( $message ) {
	add_action(
		'admin_notices',
		static function () use ( $message ) {
			printf( '<div class="notice notice-error"><p>%s</p></div>', esc_html( $message ) );
		}
	);
}

// PHP guard runs before the autoloader, which may contain syntax this PHP cannot parse.
if ( version_compare( PHP_VERSION, WEARGRAPH_MIN_PHP, '<' ) ) {
	weargraph_requirement_notice(
		sprintf(
			/* translators: 1: required PHP version, 2: current PHP version */
			__( 'WearGraph requires PHP %1$s or higher. Your site is running PHP %2$s.', 'weargraph' ),
			WEARGRAPH_MIN_PHP,
			PHP_VERSION
		)
	);
	return;
}

$weargraph_autoload = WEARGRAPH_DIR . 'vendor/autoload.php';
if ( ! file_exists( $weargraph_autoload ) ) {
	weargraph_requirement_notice( __( 'WearGraph is missing its dependencies. Please reinstall the plugin.', 'weargraph' ) );
	return;
}
require_once $weargraph_autoload;

// Action Scheduler resolves duplicate copies (WooCommerce bundles one) and loads the newest.
require_once WEARGRAPH_DIR . 'vendor/woocommerce/action-scheduler/action-scheduler.php';

register_activation_hook( WEARGRAPH_FILE, array( \WearGraph\Activator::class, 'activate' ) );
register_deactivation_hook( WEARGRAPH_FILE, array( \WearGraph\Deactivator::class, 'deactivate' ) );

// Declare compatibility with WooCommerce order storage (HPOS) and the Cart/Checkout blocks.
add_action(
	'before_woocommerce_init',
	static function () {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', WEARGRAPH_FILE, true );
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', WEARGRAPH_FILE, true );
		}
	}
);

/**
 * Access the plugin container.
 *
 * @return \WearGraph\Plugin
 */
function weargraph() {
	return \WearGraph\Plugin::instance();
}

add_action( 'plugins_loaded', array( weargraph(), 'init' ) );

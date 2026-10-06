<?php
/**
 * Plugins-screen action links and the "Upgrade to Pro" menu item.
 *
 * @package WearGraph
 */

namespace WearGraph\Admin;

defined( 'ABSPATH' ) || exit;

use WearGraph\Support\Capabilities;
use WearGraph\Support\Pro;

/**
 * The upgrade menu item has no page of its own: a click lands on admin_init, which
 * sends a plain header redirect to the product page before any output (no script).
 * Both upgrade pointers disappear once Pro is running.
 */
final class PluginLinks {

	public const UPGRADE_PAGE = 'weargraph-go-pro';

	/**
	 * Register hooks.
	 */
	public function boot(): void {
		add_filter( 'plugin_action_links_' . WEARGRAPH_BASENAME, array( $this, 'action_links' ) );
		add_action( 'admin_menu', array( $this, 'upgrade_menu' ), 99 );
		add_action( 'admin_init', array( $this, 'maybe_redirect_to_upgrade' ) );
	}

	/**
	 * Settings, Dashboard and (without Pro) Upgrade links under the plugin name.
	 *
	 * @param mixed $links Existing links.
	 * @return array<string,string>
	 */
	public function action_links( $links ): array {
		$links  = is_array( $links ) ? $links : array();
		$custom = array();

		if ( ! Pro::is_active() ) {
			$custom['weargraph-go-pro'] = sprintf(
				'<a href="%1$s" target="_blank" rel="noopener noreferrer" style="color:#c60051;font-weight:600;" aria-label="%2$s">%3$s</a>',
				esc_url( Pro::upgrade_url( 'pl-action-links' ) ),
				esc_attr__( 'Upgrade to WearGraph Pro (opens in a new tab)', 'weargraph' ),
				esc_html__( 'Upgrade to Pro', 'weargraph' )
			);
		}

		$custom['weargraph-dashboard'] = sprintf(
			'<a href="%1$s">%2$s</a>',
			esc_url( admin_url( 'admin.php?page=' . AdminMenu::SLUG ) ),
			esc_html__( 'Dashboard', 'weargraph' )
		);
		$custom['weargraph-settings']  = sprintf(
			'<a href="%1$s">%2$s</a>',
			esc_url( admin_url( 'admin.php?page=' . SettingsPage::SLUG ) ),
			esc_html__( 'Settings', 'weargraph' )
		);

		return array_merge( $custom, $links );
	}

	/**
	 * Last item of the WearGraph menu, only without Pro.
	 */
	public function upgrade_menu(): void {
		if ( Pro::is_active() ) {
			return;
		}

		add_submenu_page(
			AdminMenu::SLUG,
			__( 'Upgrade to Pro', 'weargraph' ),
			esc_html__( 'Upgrade to Pro', 'weargraph' ),
			Capabilities::MANAGE,
			self::UPGRADE_PAGE,
			'__return_null'
		);
	}

	/**
	 * Send a click on the menu item to the product page, before any output.
	 */
	public function maybe_redirect_to_upgrade(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- navigation only; the target is a fixed URL.
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		if ( self::UPGRADE_PAGE !== $page || ! current_user_can( Capabilities::MANAGE ) ) {
			return;
		}

		// The destination is off-site on purpose: allow exactly its host, for this redirect only.
		add_filter( 'allowed_redirect_hosts', array( $this, 'allow_upgrade_host' ) );
		wp_safe_redirect( Pro::upgrade_url( 'wp-menu' ) );
		exit;
	}

	/**
	 * Let wp_safe_redirect() reach the product page's host.
	 *
	 * @param string[] $hosts Allowed hosts.
	 * @return string[]
	 */
	public function allow_upgrade_host( $hosts ): array {
		$hosts   = (array) $hosts;
		$hosts[] = (string) wp_parse_url( Pro::upgrade_url( 'wp-menu' ), PHP_URL_HOST );

		return $hosts;
	}
}

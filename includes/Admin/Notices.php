<?php
/**
 * Welcome notice on the Dashboard and Plugins screens, until dismissed.
 *
 * @package WearGraph
 */

namespace WearGraph\Admin;

defined( 'ABSPATH' ) || exit;

use WearGraph\Providers\ProviderRegistry;
use WearGraph\Support\Capabilities;

/**
 * Never shown on WearGraph's own screens or anywhere else in wp-admin. Dismissal
 * is site-wide, sent by WordPress's own dismiss button through authenticated AJAX
 * (nonce plus capability), never a bare GET link.
 */
final class Notices {

	public const DISMISSED_OPTION = 'weargraph_welcome_dismissed';

	private const AJAX_ACTION = 'weargraph_dismiss_notice';

	/**
	 * Screens the welcome notice may appear on (Guideline 11: limited in scope).
	 */
	private const SCREENS = array( 'dashboard', 'plugins' );

	/**
	 * Providers (to point at Settings until a key is saved).
	 *
	 * @var ProviderRegistry
	 */
	private $providers;

	/**
	 * Constructor.
	 *
	 * @param ProviderRegistry $providers Providers.
	 */
	public function __construct( ProviderRegistry $providers ) {
		$this->providers = $providers;
	}

	/**
	 * Register hooks.
	 */
	public function boot(): void {
		add_action( 'admin_notices', array( $this, 'render' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
		add_action( 'wp_ajax_' . self::AJAX_ACTION, array( $this, 'ajax_dismiss' ) );
	}

	/**
	 * Whether the welcome notice belongs on this screen.
	 */
	public function should_render(): bool {
		if ( get_option( self::DISMISSED_OPTION ) || ! current_user_can( Capabilities::MANAGE ) || is_network_admin() ) {
			return false;
		}
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		// Only where a site owner lands after installing: the Dashboard and the Plugins screen.
		return $screen && in_array( (string) $screen->id, self::SCREENS, true );
	}

	/**
	 * Print the notice.
	 */
	public function render(): void {
		if ( ! $this->should_render() ) {
			return;
		}

		$provider = $this->providers->configured();
		$has_key  = $provider && $provider->is_configured();
		$url      = $has_key ? admin_url( 'admin.php?page=' . AdminMenu::SLUG ) : admin_url( 'admin.php?page=' . SettingsPage::SLUG . '&tab=provider' );
		?>
		<div class="notice notice-info is-dismissible" data-weargraph-notice="welcome">
			<p>
				<strong><?php esc_html_e( 'Thanks for installing WearGraph!', 'weargraph' ); ?></strong>
				<?php
				echo esc_html(
					$has_key
						? __( 'Your AI key is connected. See how shoppers use your fitting room on the WearGraph dashboard.', 'weargraph' )
						: __( 'Connect your Google Gemini API key to start creating try-on previews and product images.', 'weargraph' )
				);
				?>
				<a href="<?php echo esc_url( $url ); ?>"><?php echo esc_html( $has_key ? __( 'Open the dashboard', 'weargraph' ) : __( 'Connect your key', 'weargraph' ) ); ?></a>
			</p>
		</div>
		<?php
	}

	/**
	 * Dismiss script, only when the notice is shown.
	 */
	public function enqueue(): void {
		if ( ! $this->should_render() ) {
			return;
		}

		wp_enqueue_script( 'weargraph-notices', WEARGRAPH_URL . 'assets/admin/notices.js', array(), AdminMenu::asset_version( 'assets/admin/notices.js' ), true );
		wp_localize_script(
			'weargraph-notices',
			'weargraphNotices',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'action'  => self::AJAX_ACTION,
				'nonce'   => wp_create_nonce( self::AJAX_ACTION ),
			)
		);
	}

	/**
	 * AJAX: remember the dismissal.
	 */
	public function ajax_dismiss(): void {
		check_ajax_referer( self::AJAX_ACTION, 'nonce' );
		if ( ! current_user_can( Capabilities::MANAGE ) ) {
			wp_send_json_error( null, 403 );
		}

		update_option( self::DISMISSED_OPTION, time(), false );
		wp_send_json_success();
	}
}

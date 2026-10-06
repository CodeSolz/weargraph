<?php
/**
 * Admin menu and the shared page shell every WearGraph screen renders inside.
 *
 * @package WearGraph
 */

namespace WearGraph\Admin;

defined( 'ABSPATH' ) || exit;

use WearGraph\Jobs\Queue;
use WearGraph\Support\Capabilities;

/**
 * Registers the WearGraph menu and owns the page chrome.
 *
 * Shell contract (see to-do/09-WPORG-COMPLIANCE.md C11): every screen calls
 * open_shell() — which emits a screen-reader <h1> as the first child of .wrap so
 * core's notice relocation has an anchor — then render_page_header() (which calls
 * render_own_notices() exactly once), and finally close_shell(). Visible headings
 * are <h2>. Pro pages follow the same contract.
 */
final class AdminMenu {

	public const SLUG = 'weargraph';

	/**
	 * Page hook suffixes returned by add_(sub)menu_page, keyed by slug.
	 *
	 * @var array<string,string>
	 */
	private $hooks = array();

	/**
	 * Dashboard page.
	 *
	 * @var Dashboard|null
	 */
	private $dashboard;

	/**
	 * Constructor.
	 *
	 * @param Dashboard|null $dashboard Dashboard page.
	 */
	public function __construct( ?Dashboard $dashboard = null ) {
		$this->dashboard = $dashboard;
	}

	/**
	 * Register hooks.
	 */
	public function boot(): void {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_filter( 'admin_body_class', array( $this, 'body_class' ) );
		add_filter( 'weargraph_admin_notices', array( $this, 'background_notice' ) );
	}

	/**
	 * Warn on WearGraph screens when background tasks are not running.
	 *
	 * @param array<int,array> $notices Notices.
	 * @return array<int,array>
	 */
	public function background_notice( array $notices ): array {
		$late = Queue::overdue( 15 * MINUTE_IN_SECONDS );
		if ( ! $late || ! current_user_can( Capabilities::MANAGE ) ) {
			return $notices;
		}

		$cause = defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON
			? __( 'WP-Cron is turned off on this site (DISABLE_WP_CRON). Ask your host to run wp-cron.php every few minutes from a server cron job.', 'weargraph' )
			: __( 'Your site may be blocking requests to itself. Check Tools → Site Health for "loopback request" problems.', 'weargraph' );

		$notices[] = array(
			'type'    => 'warning',
			'message' => esc_html__( 'WearGraph background tasks are not running, so catalog scans, image generation and the privacy clean-up are waiting.', 'weargraph' ) . ' ' . esc_html( $cause ),
		);

		return $notices;
	}

	/**
	 * Register the top-level menu and core pages.
	 */
	public function register_menu(): void {
		$this->hooks[ self::SLUG ] = (string) add_menu_page(
			__( 'WearGraph', 'weargraph' ),
			__( 'WearGraph', 'weargraph' ),
			Capabilities::VIEW_REPORTS,
			self::SLUG,
			array( $this, 'render_dashboard' ),
			'dashicons-camera-alt',
			56
		);

		add_submenu_page(
			self::SLUG,
			__( 'Dashboard', 'weargraph' ),
			__( 'Dashboard', 'weargraph' ),
			Capabilities::VIEW_REPORTS,
			self::SLUG,
			array( $this, 'render_dashboard' )
		);

		/**
		 * Register additional WearGraph admin pages (free milestones and Pro).
		 *
		 * Each entry: slug => array( 'title' => string, 'capability' => string, 'callback' => callable ).
		 *
		 * @param array<string,array> $pages Pages to add under the WearGraph menu.
		 */
		$pages = apply_filters( 'weargraph_admin_pages', array() );

		foreach ( (array) $pages as $slug => $page ) {
			if ( empty( $page['title'] ) || empty( $page['callback'] ) || ! is_callable( $page['callback'] ) ) {
				continue;
			}
			$this->hooks[ $slug ] = (string) add_submenu_page(
				self::SLUG,
				$page['title'],
				$page['title'],
				$page['capability'] ?? Capabilities::MANAGE,
				$slug,
				$page['callback']
			);
		}
	}

	/**
	 * Page hook suffix for a registered page slug ('' when not registered).
	 *
	 * @param string $slug Page slug.
	 */
	public function hook_for( string $slug ): string {
		return $this->hooks[ $slug ] ?? '';
	}

	/**
	 * Whether the current admin screen belongs to WearGraph.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public function is_own_screen( string $hook ): bool {
		return in_array( $hook, $this->hooks, true );
	}

	/**
	 * Enqueue admin styles on WearGraph screens only.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public function enqueue_assets( string $hook ): void {
		if ( ! $this->is_own_screen( $hook ) ) {
			return;
		}

		wp_enqueue_style( 'weargraph-admin', WEARGRAPH_URL . 'assets/admin/admin.css', array(), self::asset_version( 'assets/admin/admin.css' ) );
	}

	/**
	 * Mark WearGraph screens (they sit on the warm canvas, admin.css).
	 *
	 * @param string $classes Space-separated body classes.
	 */
	public function body_class( $classes ): string {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( $screen && $this->is_own_screen( (string) $screen->id ) ) {
			$classes .= ' weargraph-screen';
		}

		return (string) $classes;
	}

	/**
	 * Cache-busting version: file mtime during development, plugin version otherwise.
	 *
	 * @param string $relative Path relative to the plugin root.
	 */
	public static function asset_version( string $relative ): string {
		$path = WEARGRAPH_DIR . $relative;

		return file_exists( $path ) ? (string) filemtime( $path ) : WEARGRAPH_VERSION;
	}

	/**
	 * Dashboard.
	 */
	public function render_dashboard(): void {
		if ( $this->dashboard ) {
			$this->dashboard->render();
			return;
		}

		self::open_shell( __( 'WearGraph Dashboard', 'weargraph' ) );
		self::render_page_header( __( 'Dashboard', 'weargraph' ), __( 'AI fitting room, product studio and catalog readiness for your store.', 'weargraph' ) );
		?>
		<div class="weargraph-card weargraph-empty">
			<h3><?php esc_html_e( 'Welcome to WearGraph', 'weargraph' ); ?></h3>
			<p><?php esc_html_e( 'Connect your AI provider key and scan your catalog to see which products are ready for virtual try-on.', 'weargraph' ); ?></p>
		</div>
		<?php
		self::close_shell();
	}

	/**
	 * Open the page wrapper.
	 *
	 * @param string $page_title Accessible page title for the anchor heading.
	 */
	public static function open_shell( string $page_title = '' ): void {
		$page_title = '' !== $page_title ? $page_title : __( 'WearGraph', 'weargraph' );

		echo '<div class="wrap weargraph-app">';
		// Anchor for core's notice relocation (wp-admin/js/common.js); keeps exactly one <h1>.
		printf( '<h1 class="screen-reader-text">%s</h1>', esc_html( $page_title ) );
		echo '<div class="weargraph-shell">';
		self::render_masthead();
	}

	/**
	 * The brand bar above the page header (decorative: the page's name is in the <h1>).
	 */
	private static function render_masthead(): void {
		/**
		 * Edition shown next to the name in the WearGraph masthead ('' = none).
		 *
		 * @param string $edition Edition label, e.g. "Pro".
		 */
		$edition = (string) apply_filters( 'weargraph_admin_edition', '' );
		/**
		 * Version shown on the right of the WearGraph masthead.
		 *
		 * @param string $version Version text.
		 */
		$version = (string) apply_filters( 'weargraph_admin_version', WEARGRAPH_VERSION );
		?>
		<div class="weargraph-masthead">
			<span class="weargraph-masthead__brand">
				<span class="weargraph-mark" aria-hidden="true"><svg viewBox="0 0 24 24" width="18" height="18" focusable="false"><path d="M3 5.5 7 19l5-10 5 10 4-13.5" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"/></svg></span>
				<span class="weargraph-masthead__name"><?php esc_html_e( 'WearGraph', 'weargraph' ); ?></span>
				<?php if ( '' !== $edition ) : ?>
					<span class="weargraph-masthead__edition"><?php echo esc_html( $edition ); ?></span>
				<?php endif; ?>
			</span>
			<?php if ( '' !== $version ) : ?>
				<span class="weargraph-masthead__meta">
					<?php
					/* translators: %s: plugin version */
					echo esc_html( sprintf( __( 'Version %s', 'weargraph' ), $version ) );
					?>
				</span>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Close the page wrapper.
	 */
	public static function close_shell(): void {
		echo '</div></div>';
	}

	/**
	 * Compact page header (title, description, optional actions), then own notices.
	 *
	 * @param string $title        Visible title.
	 * @param string $desc         Short description.
	 * @param string $actions_html Pre-escaped HTML for header actions.
	 */
	public static function render_page_header( string $title, string $desc = '', string $actions_html = '' ): void {
		?>
		<div class="weargraph-page-header">
			<div>
				<h2 class="weargraph-page-title"><?php echo esc_html( $title ); ?></h2>
				<?php if ( $desc ) : ?>
					<p class="weargraph-page-desc"><?php echo esc_html( $desc ); ?></p>
				<?php endif; ?>
			</div>
			<?php if ( $actions_html ) : ?>
				<div class="weargraph-page-actions"><?php echo wp_kses_post( $actions_html ); ?></div>
			<?php endif; ?>
		</div>
		<?php
		self::render_own_notices();
	}

	/**
	 * WearGraph's own notices, printed inline under the page header. Other plugins'
	 * and core notices are never suppressed.
	 */
	public static function render_own_notices(): void {
		/**
		 * Notices to show inside WearGraph screens.
		 *
		 * Each entry: array( 'type' => 'info'|'warning'|'error'|'success', 'message' => string ).
		 *
		 * @param array<int,array{type:string,message:string}> $notices Notices.
		 */
		$notices = (array) apply_filters( 'weargraph_admin_notices', array() );

		foreach ( $notices as $notice ) {
			if ( empty( $notice['message'] ) ) {
				continue;
			}
			$type = in_array( $notice['type'] ?? '', array( 'info', 'warning', 'error', 'success' ), true ) ? $notice['type'] : 'info';
			printf(
				'<div class="weargraph-notice weargraph-notice--%1$s"><p>%2$s</p></div>',
				esc_attr( $type ),
				wp_kses_post( $notice['message'] )
			);
		}
	}
}

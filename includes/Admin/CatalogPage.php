<?php
/**
 * Catalog screen: readiness summary, scan control, product table, bulk actions.
 *
 * @package WearGraph
 */

namespace WearGraph\Admin;

defined( 'ABSPATH' ) || exit;

use WearGraph\Catalog\CatalogMessages;
use WearGraph\Catalog\Pixels\Sampler;
use WearGraph\Database\Repositories\CatalogRepository;
use WearGraph\Jobs\CatalogScanJob;
use WearGraph\Services\CatalogService;
use WearGraph\Support\Capabilities;

/**
 * Registered through weargraph_admin_pages. Bulk actions post back to this screen
 * and are handled on its load hook (nonce + capability), then redirect.
 */
final class CatalogPage {

	public const SLUG = 'weargraph-catalog';

	/**
	 * Rows.
	 *
	 * @var CatalogRepository
	 */
	private $repo;

	/**
	 * Scan job.
	 *
	 * @var CatalogScanJob
	 */
	private $job;

	/**
	 * Service.
	 *
	 * @var CatalogService
	 */
	private $catalog;

	/**
	 * Constructor.
	 *
	 * @param CatalogRepository $repo    Rows.
	 * @param CatalogScanJob    $job     Scan job.
	 * @param CatalogService    $catalog Service.
	 */
	public function __construct( CatalogRepository $repo, CatalogScanJob $job, CatalogService $catalog ) {
		$this->repo    = $repo;
		$this->job     = $job;
		$this->catalog = $catalog;
	}

	/**
	 * Register hooks.
	 */
	public function boot(): void {
		add_filter( 'weargraph_admin_pages', array( $this, 'register_page' ), 5 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
		add_filter( 'weargraph_admin_notices', array( $this, 'notices' ) );
		add_action( 'admin_init', array( $this, 'maybe_handle_bulk' ) );
	}

	/**
	 * Add the Catalog page.
	 *
	 * @param array<string,array> $pages Pages.
	 * @return array<string,array>
	 */
	public function register_page( array $pages ): array {
		return array(
			self::SLUG => array(
				'title'      => __( 'Catalog', 'weargraph' ),
				'capability' => Capabilities::VIEW_REPORTS,
				'callback'   => array( $this, 'render' ),
			),
		) + $pages;
	}

	/**
	 * Handle bulk actions posted from the table.
	 */
	public function maybe_handle_bulk(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- nonce verified below before any change.
		$page = isset( $_REQUEST['page'] ) ? sanitize_key( wp_unslash( $_REQUEST['page'] ) ) : '';
		if ( self::SLUG !== $page || empty( $_REQUEST['product'] ) ) {
			return;
		}
		$action = isset( $_REQUEST['action'] ) ? sanitize_key( wp_unslash( $_REQUEST['action'] ) ) : '';
		if ( '' === $action || '-1' === $action ) {
			$action = isset( $_REQUEST['action2'] ) ? sanitize_key( wp_unslash( $_REQUEST['action2'] ) ) : '';
		}
		// phpcs:enable
		$extra = isset( CatalogTable::extra_bulk_actions()[ $action ] );
		if ( ! $extra && ! in_array( $action, CatalogService::BULK_ACTIONS, true ) ) {
			return;
		}

		check_admin_referer( 'bulk-weargraph-products' );
		if ( ! current_user_can( Capabilities::MANAGE ) ) {
			wp_die( esc_html__( 'You are not allowed to change the catalog.', 'weargraph' ), '', array( 'response' => 403 ) );
		}

		$ids = isset( $_REQUEST['product'] ) ? array_map( 'absint', (array) wp_unslash( $_REQUEST['product'] ) ) : array();
		$ids = array_values( array_filter( $ids ) );
		if ( $extra ) {
			/**
			 * An extension's bulk action was applied (nonce and capability already checked).
			 * The handler usually redirects and exits; otherwise the screen is shown again.
			 *
			 * @param string $action Action key.
			 * @param int[]  $ids    Ticked product ids.
			 */
			do_action( 'weargraph_catalog_bulk_action', $action, $ids );
			wp_safe_redirect( admin_url( 'admin.php?page=' . self::SLUG ) );
			exit;
		}

		$value = '';
		if ( 'category' === $action ) {
			$value = isset( $_REQUEST['bulk_category'] ) ? sanitize_key( wp_unslash( $_REQUEST['bulk_category'] ) ) : '';
		} elseif ( 'layer' === $action ) {
			$value = isset( $_REQUEST['bulk_layer'] ) ? sanitize_key( wp_unslash( $_REQUEST['bulk_layer'] ) ) : '';
		}

		$count = $this->catalog->bulk( $ids, $action, $value );

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'       => self::SLUG,
					'wg_bulk'    => $action,
					'wg_updated' => $count,
					'wg_status'  => isset( $_REQUEST['wg_status'] ) ? sanitize_key( wp_unslash( $_REQUEST['wg_status'] ) ) : false,
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * "N products updated." after a bulk action.
	 *
	 * @param array<int,array> $notices Notices.
	 * @return array<int,array>
	 */
	public function notices( array $notices ): array {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display flag only.
		if ( isset( $_GET['page'], $_GET['wg_updated'] ) && self::SLUG === $_GET['page'] ) {
			$notices[] = array(
				'type'    => 'success',
				'message' => sprintf(
					/* translators: %d: number of products */
					_n( '%d product updated.', '%d products updated.', absint( $_GET['wg_updated'] ), 'weargraph' ), // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only.
					absint( $_GET['wg_updated'] ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only.
				),
			);
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- page check only.
		if ( isset( $_GET['page'] ) && self::SLUG === $_GET['page'] && current_user_can( Capabilities::MANAGE ) ) {
			$unscanned = (int) $this->repo->summary()['unscanned'];
			if ( $unscanned > 0 && 'running' !== ( $this->job->status()['status'] ?? '' ) ) {
				$notices[] = array(
					'type'    => 'info',
					'message' => esc_html(
						sprintf(
							/* translators: %s: number of products */
							_n( '%s product has not been checked yet. Click "Scan catalog" to check it (no AI, no cost).', '%s products have not been checked yet. Click "Scan catalog" to check them (no AI, no cost).', $unscanned, 'weargraph' ),
							number_format_i18n( $unscanned )
						)
					),
				);
			}
		}

		return $notices;
	}

	/**
	 * Render the screen.
	 */
	public function render(): void {
		$counts = $this->repo->summary();
		$scan   = $this->job->status();
		$table  = new CatalogTable( $this->repo );
		$table->prepare_items();

		$button = sprintf(
			'<button type="button" class="button button-primary" data-action="scan">%s</button> <button type="button" class="button" data-action="rescan-all">%s</button>',
			esc_html__( 'Scan catalog', 'weargraph' ),
			esc_html__( 'Rescan everything', 'weargraph' )
		);

		AdminMenu::open_shell( __( 'WearGraph Catalog', 'weargraph' ) );
		AdminMenu::render_page_header(
			__( 'Catalog', 'weargraph' ),
			__( 'Which products are ready for virtual try-on, and what to fix for the rest.', 'weargraph' ),
			current_user_can( Capabilities::MANAGE ) ? $button : ''
		);
		?>
		<div class="weargraph-catalog" data-status="<?php echo esc_attr( (string) ( $scan['status'] ?? 'never' ) ); ?>">
			<div class="weargraph-summary">
				<?php
				$cards = array(
					'ready'        => array( 'green', __( 'Try-On Ready', 'weargraph' ) ),
					'needs_image'  => array( 'amber', __( 'Need a better image', 'weargraph' ) ),
					'needs_review' => array( 'blue', __( 'Need review', 'weargraph' ) ),
					'unsupported'  => array( 'slate', __( 'Not supported', 'weargraph' ) ),
					'unscanned'    => array( 'slate', __( 'Not scanned yet', 'weargraph' ) ),
				);
				foreach ( $cards as $key => $card ) :
					?>
					<a class="weargraph-summary__card is-<?php echo esc_attr( $card[0] ); ?>" href="<?php echo esc_url( add_query_arg( 'wg_status', $key, admin_url( 'admin.php?page=' . self::SLUG ) ) ); ?>">
						<span class="weargraph-summary__num" data-count="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( number_format_i18n( (int) $counts[ $key ] ) ); ?></span>
						<span class="weargraph-summary__label"><?php echo esc_html( $card[1] ); ?></span>
					</a>
				<?php endforeach; ?>
			</div>

			<p class="weargraph-headline">
				<?php
				printf(
					/* translators: 1: ready products, 2: all products */
					esc_html__( '%1$s of %2$s products are Try-On Ready.', 'weargraph' ),
					'<strong data-count="ready">' . esc_html( number_format_i18n( (int) $counts['ready'] ) ) . '</strong>',
					'<strong data-count="total">' . esc_html( number_format_i18n( (int) $counts['total'] ) ) . '</strong>'
				);
				?>
			</p>

			<div class="weargraph-scan" <?php echo 'running' === ( $scan['status'] ?? '' ) ? '' : 'hidden'; ?>>
				<div class="weargraph-progress"><span style="width: <?php echo esc_attr( (string) (int) ( $scan['percent'] ?? 0 ) ); ?>%"></span></div>
				<span class="weargraph-scan__text" role="status" aria-live="polite"></span>
				<button type="button" class="button-link" data-action="cancel-scan"><?php esc_html_e( 'Stop', 'weargraph' ); ?></button>
			</div>

			<?php if ( '' === Sampler::library() ) : ?>
				<div class="weargraph-notice weargraph-notice--info"><p>
					<?php esc_html_e( 'Your server has no image library (GD or Imagick) enabled, so images are judged by size only. Ask your host to enable the PHP GD extension for background and framing checks; WordPress also needs it to create thumbnails.', 'weargraph' ); ?>
				</p></div>
			<?php endif; ?>

			<?php
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only view choice.
			$view = isset( $_GET['wg_status'] ) ? sanitize_key( wp_unslash( $_GET['wg_status'] ) ) : '';
			if ( isset( CatalogTable::extra_views()[ $view ] ) ) :
				$table->views();
				/**
				 * Render an extension's Catalog view (added through weargraph_catalog_views)
				 * in place of the product table.
				 *
				 * @param string $view View key.
				 */
				do_action( 'weargraph_catalog_view', $view );
			else :
				?>
			<form method="get">
				<input type="hidden" name="page" value="<?php echo esc_attr( self::SLUG ); ?>">
				<?php
				$table->views();
				$table->search_box( __( 'Search products', 'weargraph' ), 'weargraph-catalog' );
				$table->display();
				?>
			</form>
			<?php endif; ?>
		</div>
		<?php
		AdminMenu::close_shell();
	}

	/**
	 * Enqueue on the Catalog screen only.
	 *
	 * @param string $hook Admin hook.
	 */
	public function enqueue( string $hook ): void {
		$menu = weargraph()->get( 'admin_menu' );
		if ( ! $menu instanceof AdminMenu || $menu->hook_for( self::SLUG ) !== $hook ) {
			return;
		}

		wp_enqueue_script( 'weargraph-catalog', WEARGRAPH_URL . 'assets/admin/catalog.js', array(), AdminMenu::asset_version( 'assets/admin/catalog.js' ), true );
		wp_localize_script(
			'weargraph-catalog',
			'weargraphCatalog',
			array(
				'root'  => esc_url_raw( rest_url( 'weargraph/v1/' ) ),
				'nonce' => wp_create_nonce( 'wp_rest' ),
				'i18n'  => array(
					/* translators: 1: processed, 2: total */
					'progress'   => __( 'Scanning… %1$s of %2$s products', 'weargraph' ),
					'done'       => __( 'Scan complete. Reloading…', 'weargraph' ),
					'failed'     => __( 'Could not start the scan. Please try again.', 'weargraph' ),
					'confirmAll' => __( 'Re-check every product, including unchanged ones?', 'weargraph' ),
				),
			)
		);
	}

	/**
	 * Category label helper for the table.
	 *
	 * @param string $key Category key.
	 */
	public static function category_label( string $key ): string {
		return CatalogMessages::categories()[ $key ] ?? $key;
	}
}

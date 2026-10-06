<?php
/**
 * Product readiness table (WP_List_Table).
 *
 * @package WearGraph
 */

namespace WearGraph\Admin;

defined( 'ABSPATH' ) || exit;

use WearGraph\Catalog\CatalogMessages;
use WearGraph\Catalog\Readiness;
use WearGraph\Database\Repositories\CatalogRepository;
use WearGraph\Services\CatalogService;
use WearGraph\Support\Capabilities;

if ( ! class_exists( '\WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * Columns: product, try-on, garment, source image, readiness, try-ons, last scanned.
 */
final class CatalogTable extends \WP_List_Table {

	/**
	 * Rows.
	 *
	 * @var CatalogRepository
	 */
	private $repo;

	/**
	 * Try-on counts for the current page.
	 *
	 * @var array<int,int>
	 */
	private $tryons = array();

	/**
	 * Constructor.
	 *
	 * @param CatalogRepository $repo Rows.
	 */
	public function __construct( CatalogRepository $repo ) {
		parent::__construct(
			array(
				'singular' => 'weargraph-product',
				'plural'   => 'weargraph-products',
				'ajax'     => false,
			)
		);
		$this->repo = $repo;
	}

	/**
	 * Columns.
	 *
	 * @return array<string,string>
	 */
	public function get_columns(): array {
		$columns = array(
			'product'   => __( 'Product', 'weargraph' ),
			'tryon'     => __( 'Try-on', 'weargraph' ),
			'garment'   => __( 'Garment', 'weargraph' ),
			'image'     => __( 'Source image', 'weargraph' ),
			'readiness' => __( 'Readiness', 'weargraph' ),
			'tryons'    => __( 'Try-ons', 'weargraph' ),
			'scanned'   => __( 'Last scanned', 'weargraph' ),
		);

		if ( current_user_can( Capabilities::MANAGE ) ) {
			$columns = array( 'cb' => '<input type="checkbox">' ) + $columns;
		}

		return $columns;
	}

	/**
	 * Sortable columns.
	 *
	 * @return array<string,array{0:string,1:bool}>
	 */
	protected function get_sortable_columns(): array {
		return array(
			'product'   => array( 'title', false ),
			'readiness' => array( 'readiness', true ),
			'scanned'   => array( 'scanned', true ),
		);
	}

	/**
	 * Status views with counts.
	 *
	 * @return array<string,string>
	 */
	protected function get_views(): array {
		$counts  = $this->repo->summary();
		$current = $this->current_status();
		$base    = admin_url( 'admin.php?page=' . CatalogPage::SLUG );
		$views   = array(
			''             => array( __( 'All', 'weargraph' ), (int) $counts['total'] ),
			'ready'        => array( CatalogMessages::status( 'ready' ), (int) $counts['ready'] ),
			'needs_image'  => array( CatalogMessages::status( 'needs_image' ), (int) $counts['needs_image'] ),
			'needs_review' => array( CatalogMessages::status( 'needs_review' ), (int) $counts['needs_review'] ),
			'unsupported'  => array( CatalogMessages::status( 'unsupported' ), (int) $counts['unsupported'] ),
			'unscanned'    => array( CatalogMessages::status( 'unscanned' ), (int) $counts['unscanned'] ),
		) + self::extra_views();

		$out = array();
		foreach ( $views as $key => $view ) {
			$url                               = '' === $key ? $base : add_query_arg( 'wg_status', $key, $base );
			$out[ '' === $key ? 'all' : $key ] = sprintf(
				'<a href="%1$s"%2$s>%3$s <span class="count">(%4$s)</span></a>',
				esc_url( $url ),
				$current === $key ? ' class="current" aria-current="page"' : '',
				esc_html( $view[0] ),
				esc_html( number_format_i18n( $view[1] ) )
			);
		}

		return $out;
	}

	/**
	 * Bulk actions.
	 *
	 * @return array<string,string>
	 */
	protected function get_bulk_actions(): array {
		if ( ! current_user_can( Capabilities::MANAGE ) ) {
			return array();
		}

		return array(
			'enable'   => __( 'Turn try-on on', 'weargraph' ),
			'disable'  => __( 'Turn try-on off', 'weargraph' ),
			'auto'     => __( 'Try-on: automatic', 'weargraph' ),
			'rescan'   => __( 'Rescan', 'weargraph' ),
			'category' => __( 'Change category…', 'weargraph' ),
			'layer'    => __( 'Change layer…', 'weargraph' ),
		) + self::extra_bulk_actions();
	}

	/**
	 * Bulk actions added by extensions (handled through weargraph_catalog_bulk_action).
	 *
	 * @return array<string,string> Action key => label.
	 */
	public static function extra_bulk_actions(): array {
		/**
		 * Extra bulk actions on the Catalog screen (e.g. Pro's batch campaigns). Keys must
		 * not clash with the built-in actions; the handler receives the ticked product ids.
		 *
		 * @param array<string,string> $actions Action key => label.
		 */
		$extra = (array) apply_filters( 'weargraph_catalog_bulk_actions', array() );

		return array_diff_key( array_map( 'strval', $extra ), array_flip( CatalogService::BULK_ACTIONS ) );
	}

	/**
	 * Views added by extensions: key => array{0:string label, 1:int count}. The screen shows
	 * them after the status views and renders them through weargraph_catalog_view.
	 *
	 * @return array<string,array>
	 */
	public static function extra_views(): array {
		/**
		 * Extra views on the Catalog screen (e.g. Pro's campaigns to review).
		 *
		 * @param array<string,array> $views Key => array{0:string label, 1:int count}.
		 */
		$extra = (array) apply_filters( 'weargraph_catalog_views', array() );
		$out   = array();
		foreach ( $extra as $key => $view ) {
			$key = sanitize_key( (string) $key );
			if ( '' !== $key && ! in_array( $key, array( 'all', 'ready', 'needs_image', 'needs_review', 'unsupported', 'unscanned' ), true ) && is_array( $view ) ) {
				$out[ $key ] = array( (string) ( $view[0] ?? $key ), (int) ( $view[1] ?? 0 ) );
			}
		}

		return $out;
	}

	/**
	 * Extra selects for "change category / layer" next to the bulk dropdown.
	 *
	 * @param string $which top|bottom.
	 */
	protected function extra_tablenav( $which ): void {
		if ( 'top' !== $which || ! current_user_can( Capabilities::MANAGE ) ) {
			return;
		}
		echo '<div class="alignleft actions weargraph-bulk-values">';
		echo '<label class="screen-reader-text" for="wg-bulk-category">' . esc_html__( 'New category', 'weargraph' ) . '</label>';
		echo '<select name="bulk_category" id="wg-bulk-category"><option value="">' . esc_html__( '— Category —', 'weargraph' ) . '</option>';
		foreach ( CatalogService::category_options() as $key => $label ) {
			printf( '<option value="%1$s">%2$s</option>', esc_attr( $key ), esc_html( $label ) );
		}
		echo '</select> ';
		echo '<label class="screen-reader-text" for="wg-bulk-layer">' . esc_html__( 'New layer', 'weargraph' ) . '</label>';
		echo '<select name="bulk_layer" id="wg-bulk-layer"><option value="">' . esc_html__( '— Layer —', 'weargraph' ) . '</option>';
		foreach ( CatalogMessages::layers() as $key => $label ) {
			printf( '<option value="%1$s">%2$s</option>', esc_attr( $key ), esc_html( $label ) );
		}
		echo '</select>';
		printf( '<input type="hidden" name="wg_status" value="%s">', esc_attr( $this->current_status() ) );
		echo '</div>';
	}

	/**
	 * Load rows.
	 */
	public function prepare_items(): void {
		$per_page = 20;
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only list parameters.
		$result = $this->repo->list(
			array(
				'status'   => $this->current_status(),
				'search'   => isset( $_REQUEST['s'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['s'] ) ) : '',
				'orderby'  => isset( $_REQUEST['orderby'] ) ? sanitize_key( wp_unslash( $_REQUEST['orderby'] ) ) : 'id',
				'order'    => isset( $_REQUEST['order'] ) ? sanitize_key( wp_unslash( $_REQUEST['order'] ) ) : 'desc',
				'page'     => $this->get_pagenum(),
				'per_page' => $per_page,
			)
		);
		// phpcs:enable

		$this->items           = $result['items'];
		$this->tryons          = $this->repo->tryon_counts( array_column( $result['items'], 'product_id' ) );
		$this->_column_headers = array( $this->get_columns(), array(), $this->get_sortable_columns(), 'product' );
		$this->set_pagination_args(
			array(
				'total_items' => $result['total'],
				'per_page'    => $per_page,
			)
		);
	}

	/**
	 * Current status filter.
	 */
	private function current_status(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only filter.
		return isset( $_REQUEST['wg_status'] ) ? sanitize_key( wp_unslash( $_REQUEST['wg_status'] ) ) : '';
	}

	/**
	 * Empty table message.
	 */
	public function no_items(): void {
		if ( 0 === $this->repo->summary()['total'] ) {
			esc_html_e( 'Your store has no products yet. Add clothing products in WooCommerce, then scan your catalog here.', 'weargraph' );
			return;
		}
		if ( '' !== $this->current_status() || isset( $_REQUEST['s'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only filter.
			esc_html_e( 'No products match this filter.', 'weargraph' );
			return;
		}
		esc_html_e( 'No products here. Run a scan to check your catalog.', 'weargraph' );
	}

	/**
	 * Checkbox column.
	 *
	 * @param array<string,mixed> $item Row.
	 */
	protected function column_cb( $item ): string {
		return sprintf( '<input type="checkbox" name="product[]" value="%d">', (int) $item['product_id'] );
	}

	/**
	 * Product column.
	 *
	 * @param array<string,mixed> $item Row.
	 */
	protected function column_product( $item ): string {
		$id    = (int) $item['product_id'];
		$thumb = get_the_post_thumbnail( $id, array( 40, 40 ) );
		$link  = get_edit_post_link( $id );

		return sprintf(
			'<div class="weargraph-cell-product">%1$s<div><strong><a href="%2$s">%3$s</a></strong><br><span class="description">%4$s</span></div></div>',
			$thumb ? $thumb : '<span class="weargraph-thumb-placeholder"></span>',
			esc_url( (string) $link . '#weargraph-studio' ),
			esc_html( (string) $item['post_title'] ),
			esc_html( 'publish' === $item['post_status'] ? '#' . $id : '#' . $id . ' · ' . $item['post_status'] )
		);
	}

	/**
	 * Try-on column.
	 *
	 * @param array<string,mixed> $item Row.
	 */
	protected function column_tryon( $item ): string {
		if ( empty( $item['id'] ) ) {
			return '<span class="weargraph-muted">—</span>';
		}
		$on   = CatalogService::is_enabled( $item );
		$auto = 'merchant' !== $item['tryon_enabled_source'];

		return sprintf(
			'<span class="weargraph-pill %1$s">%2$s</span> <span class="weargraph-muted">%3$s</span>',
			$on ? 'is-on' : 'is-off',
			esc_html( $on ? __( 'On', 'weargraph' ) : __( 'Off', 'weargraph' ) ),
			esc_html( $auto ? __( 'auto', 'weargraph' ) : __( 'set by you', 'weargraph' ) )
		);
	}

	/**
	 * Garment column.
	 *
	 * @param array<string,mixed> $item Row.
	 */
	protected function column_garment( $item ): string {
		if ( empty( $item['id'] ) ) {
			return '<span class="weargraph-muted">—</span>';
		}
		$layers = CatalogMessages::layers();
		$layer  = (string) $item['layer'];

		return sprintf(
			'%1$s%2$s<br><span class="weargraph-muted">%3$s</span>',
			esc_html( CatalogPage::category_label( (string) $item['category'] ) ),
			'merchant' === $item['category_source'] ? ' <span class="weargraph-muted">(' . esc_html__( 'set by you', 'weargraph' ) . ')</span>' : '',
			esc_html( '' !== $layer ? strtok( $layers[ $layer ] ?? $layer, '(' ) : '' )
		);
	}

	/**
	 * Source image column.
	 *
	 * @param array<string,mixed> $item Row.
	 */
	protected function column_image( $item ): string {
		$id = (int) ( $item['best_image_id'] ?? 0 );
		if ( ! $id ) {
			return '<span class="weargraph-muted">—</span>';
		}

		return (string) wp_get_attachment_image( $id, array( 48, 48 ), false, array( 'class' => 'weargraph-source-thumb' ) );
	}

	/**
	 * Readiness column.
	 *
	 * @param array<string,mixed> $item Row.
	 */
	protected function column_readiness( $item ): string {
		$status   = empty( $item['id'] ) ? Readiness::UNSCANNED : (string) $item['readiness_status'];
		$score    = (int) ( $item['readiness_score'] ?? 0 );
		$problems = empty( $item['id'] ) ? array() : CatalogMessages::problems_from_json( (string) $item['problems_json'] );

		$html = sprintf(
			'<span class="weargraph-status-pill is-%1$s">%2$s</span>',
			esc_attr( $status ),
			esc_html( CatalogMessages::status( $status ) )
		);
		if ( Readiness::UNSCANNED !== $status && Readiness::UNSUPPORTED !== $status ) {
			$html .= sprintf( ' <span class="weargraph-score">%d/100</span><div class="weargraph-bar"><span style="width:%d%%"></span></div>', $score, $score );
		}
		if ( $problems ) {
			$html .= '<ul class="weargraph-problems">';
			foreach ( array_slice( $problems, 0, 2 ) as $problem ) {
				$html .= '<li>' . esc_html( $problem ) . '</li>';
			}
			if ( count( $problems ) > 2 ) {
				/* translators: %d: number of further problems */
				$html .= '<li class="weargraph-muted">' . esc_html( sprintf( __( '+%d more', 'weargraph' ), count( $problems ) - 2 ) ) . '</li>';
			}
			$html .= '</ul>';
		}

		return $html;
	}

	/**
	 * Try-on count column.
	 *
	 * @param array<string,mixed> $item Row.
	 */
	protected function column_tryons( $item ): string {
		return esc_html( number_format_i18n( $this->tryons[ (int) $item['product_id'] ] ?? 0 ) );
	}

	/**
	 * Last scanned column.
	 *
	 * @param array<string,mixed> $item Row.
	 */
	protected function column_scanned( $item ): string {
		if ( empty( $item['scanned_at'] ) ) {
			return '<span class="weargraph-muted">' . esc_html__( 'Never', 'weargraph' ) . '</span>';
		}

		/* translators: %s: human time difference, e.g. "5 mins" */
		return esc_html( sprintf( __( '%s ago', 'weargraph' ), human_time_diff( (int) strtotime( $item['scanned_at'] . ' UTC' ) ) ) );
	}
}

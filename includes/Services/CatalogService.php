<?php
/**
 * Catalog read models, merchant overrides and bulk actions.
 *
 * @package WearGraph
 */

namespace WearGraph\Services;

defined( 'ABSPATH' ) || exit;

use WearGraph\Catalog\CatalogMessages;
use WearGraph\Catalog\Keywords;
use WearGraph\Catalog\Readiness;
use WearGraph\Commerce\ProductImages;
use WearGraph\Database\Repositories\CatalogRepository;
use WearGraph\Jobs\CatalogScanJob;

/**
 * Shared by the Catalog screen, the product Try-On tab and the REST API, so every
 * override goes through the same validation and is followed by a rescan.
 */
final class CatalogService {

	public const BULK_ACTIONS = array( 'enable', 'disable', 'auto', 'rescan', 'category', 'layer' );

	/**
	 * Rows.
	 *
	 * @var CatalogRepository
	 */
	private $repo;

	/**
	 * Scanner.
	 *
	 * @var CatalogScanService
	 */
	private $scanner;

	/**
	 * Scan job.
	 *
	 * @var CatalogScanJob
	 */
	private $job;

	/**
	 * Constructor.
	 *
	 * @param CatalogRepository  $repo    Rows.
	 * @param CatalogScanService $scanner Scanner.
	 * @param CatalogScanJob     $job     Scan job.
	 */
	public function __construct( CatalogRepository $repo, CatalogScanService $scanner, CatalogScanJob $job ) {
		$this->repo    = $repo;
		$this->scanner = $scanner;
		$this->job     = $job;
	}

	/**
	 * Whether try-on is effectively on for a product row (before the store-wide switch).
	 *
	 * @param array<string,mixed>|null $row Product row.
	 */
	public static function is_enabled( ?array $row ): bool {
		return $row && 1 === (int) $row['tryon_enabled'] && 'none' !== (string) $row['tryon_mode'];
	}

	/**
	 * Product detail for the Try-On tab and REST.
	 *
	 * @param int $product_id Product.
	 * @return array<string,mixed>
	 */
	public function detail( int $product_id ): array {
		$row = $this->repo->get( $product_id );

		$images = array();
		$scores = $row ? json_decode( (string) $row['image_scores_json'], true ) : array();
		foreach ( ProductImages::ids( $product_id ) as $attachment_id ) {
			$score    = is_array( $scores ) && isset( $scores[ $attachment_id ] ) ? $scores[ $attachment_id ] : null;
			$images[] = array(
				'id'        => $attachment_id,
				'thumbnail' => (string) wp_get_attachment_image_url( $attachment_id, 'thumbnail' ),
				'score'     => $score ? (int) round( 100 * (float) $score['total'] ) : null,
				'style'     => $score ? (string) $score['style'] : null,
				'size'      => $score ? $score['width'] . '×' . $score['height'] : null,
			);
		}

		$variants = array();
		foreach ( $this->repo->for_product( $product_id ) as $variant_row ) {
			if ( 0 === (int) $variant_row['variant_id'] ) {
				continue;
			}
			$variants[] = array(
				'id'            => (int) $variant_row['variant_id'],
				'label'         => (string) $variant_row['variant_label'],
				'best_image_id' => (int) $variant_row['best_image_id'],
			);
		}

		$status = $row ? (string) $row['readiness_status'] : Readiness::UNSCANNED;

		return array(
			'product_id'     => $product_id,
			'scanned'        => (bool) $row,
			'status'         => $status,
			'status_label'   => CatalogMessages::status( $status ),
			'score'          => $row ? (int) $row['readiness_score'] : 0,
			'problems'       => $row ? CatalogMessages::problems_from_json( (string) $row['problems_json'] ) : array(),
			'category'       => $row ? (string) $row['category'] : '',
			'category_label' => $row ? ( CatalogMessages::categories()[ (string) $row['category'] ] ?? (string) $row['category'] ) : '',
			'category_auto'  => ! $row || 'merchant' !== $row['category_source'],
			'layer'          => $row ? (string) $row['layer'] : '',
			'layer_auto'     => ! $row || 'merchant' !== $row['layer_source'],
			'tryon_mode'     => $row ? (string) $row['tryon_mode'] : '',
			'enabled'        => self::is_enabled( $row ),
			'enabled_auto'   => ! $row || 'merchant' !== $row['tryon_enabled_source'],
			'best_image_id'  => $row ? (int) $row['best_image_id'] : 0,
			'best_auto'      => ! $row || 'merchant' !== $row['best_image_source'],
			'images'         => $images,
			'variants'       => $variants,
			'scanned_at'     => $row && $row['scanned_at'] ? mysql_to_rfc3339( (string) $row['scanned_at'] ) : null,
		);
	}

	/**
	 * Apply merchant overrides, then rescan the product.
	 *
	 * @param int                 $product_id Product.
	 * @param array<string,mixed> $changes    category ('auto'|key), layer ('auto'|key),
	 *                                        tryon ('auto'|'on'|'off'), best_image_id ('auto'|id), reset (bool).
	 * @return true|\WP_Error
	 */
	public function update( int $product_id, array $changes ) {
		if ( ! $this->repo->get( $product_id ) ) {
			$this->scanner->scan_product( $product_id, true );
		}

		if ( ! empty( $changes['reset'] ) ) {
			foreach ( array_keys( CatalogRepository::OVERRIDABLE ) as $field ) {
				$this->repo->set_override( $product_id, $field, null );
			}
		}

		if ( isset( $changes['category'] ) ) {
			$category = (string) $changes['category'];
			if ( 'auto' === $category ) {
				$this->repo->set_override( $product_id, 'category', null );
			} elseif ( isset( CatalogMessages::categories()[ $category ] ) ) {
				$this->repo->set_override( $product_id, 'category', $category );
			} else {
				return new \WP_Error( 'weargraph_bad_category', __( 'Unknown category.', 'weargraph' ), array( 'status' => 400 ) );
			}
		}

		if ( isset( $changes['layer'] ) ) {
			$layer = (string) $changes['layer'];
			if ( 'auto' === $layer ) {
				$this->repo->set_override( $product_id, 'layer', null );
			} elseif ( isset( CatalogMessages::layers()[ $layer ] ) ) {
				$this->repo->set_override( $product_id, 'layer', $layer );
			} else {
				return new \WP_Error( 'weargraph_bad_layer', __( 'Unknown layer.', 'weargraph' ), array( 'status' => 400 ) );
			}
		}

		if ( isset( $changes['tryon'] ) ) {
			$tryon = (string) $changes['tryon'];
			if ( 'auto' === $tryon ) {
				$this->repo->set_override( $product_id, 'tryon_enabled', null );
			} elseif ( in_array( $tryon, array( 'on', 'off' ), true ) ) {
				$this->repo->set_override( $product_id, 'tryon_enabled', 'on' === $tryon ? 1 : 0 );
			} else {
				return new \WP_Error( 'weargraph_bad_value', __( 'Unknown try-on setting.', 'weargraph' ), array( 'status' => 400 ) );
			}
		}

		if ( isset( $changes['best_image_id'] ) ) {
			$best = $changes['best_image_id'];
			if ( 'auto' === $best ) {
				$this->repo->set_override( $product_id, 'best_image_id', null );
			} elseif ( ProductImages::contains( $product_id, absint( $best ) ) ) {
				$this->repo->set_override( $product_id, 'best_image_id', absint( $best ) );
			} else {
				return new \WP_Error( 'weargraph_bad_reference', __( 'Choose images that belong to this product.', 'weargraph' ), array( 'status' => 400 ) );
			}
		}

		$this->scanner->scan_product( $product_id, true );

		return true;
	}

	/**
	 * Bulk action on several products.
	 *
	 * @param int[]  $product_ids Products.
	 * @param string $action      One of BULK_ACTIONS.
	 * @param string $value       Category / layer key for those actions.
	 * @return int Products changed.
	 */
	public function bulk( array $product_ids, string $action, string $value = '' ): int {
		if ( ! in_array( $action, self::BULK_ACTIONS, true ) ) {
			return 0;
		}

		$changes = array(
			'enable'   => array( 'tryon' => 'on' ),
			'disable'  => array( 'tryon' => 'off' ),
			'auto'     => array( 'tryon' => 'auto' ),
			'category' => array( 'category' => $value ),
			'layer'    => array( 'layer' => $value ),
		);

		$done = 0;
		foreach ( array_slice( array_unique( array_map( 'absint', $product_ids ) ), 0, 500 ) as $product_id ) {
			if ( ! $product_id || 'product' !== get_post_type( $product_id ) ) {
				continue;
			}
			if ( 'rescan' === $action ) {
				$this->scanner->scan_product( $product_id, true );
				++$done;
				continue;
			}
			if ( true === $this->update( $product_id, $changes[ $action ] ) ) {
				++$done;
			}
		}

		return $done;
	}

	/**
	 * Category options for selects (key => label), apparel first.
	 *
	 * @return array<string,string>
	 */
	public static function category_options(): array {
		return CatalogMessages::categories();
	}

	/**
	 * Default layer for a category.
	 *
	 * @param string $category Category key.
	 */
	public static function default_layer( string $category ): string {
		return (string) ( Keywords::categories()[ $category ]['layer'] ?? '' );
	}
}

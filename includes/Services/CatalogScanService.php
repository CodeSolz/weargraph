<?php
/**
 * Scans one product: classify, score images, pick sources, compute readiness.
 *
 * @package WearGraph
 */

namespace WearGraph\Services;

defined( 'ABSPATH' ) || exit;

use WearGraph\Catalog\Classification;
use WearGraph\Catalog\Classifier;
use WearGraph\Catalog\ImageScore;
use WearGraph\Catalog\ImageScorer;
use WearGraph\Catalog\Keywords;
use WearGraph\Catalog\Pixels\Sampler;
use WearGraph\Catalog\Readiness;
use WearGraph\Catalog\ReadinessCalculator;
use WearGraph\Catalog\SourceImageSelector;
use WearGraph\Commerce\ImageDTO;
use WearGraph\Commerce\ProductDTO;
use WearGraph\Commerce\WooCatalogReader;
use WearGraph\Database\Repositories\CatalogRepository;
use WearGraph\Domain\ImageOptions;
use WearGraph\Domain\ImageRef;
use WearGraph\Domain\TryOnInput;
use WearGraph\Providers\ProviderRegistry;
use WearGraph\Providers\TryOnCapable;

/**
 * Local, rule-based and free: no AI calls (decision X5). Unchanged products are
 * skipped via a hash of their content plus the scanner version and image library,
 * so enabling GD later automatically re-scores images. Merchant overrides win.
 */
final class CatalogScanService {

	/**
	 * Bump when scanning logic changes so every product is rescored once.
	 */
	public const SCAN_VERSION = '1';

	/**
	 * Reader.
	 *
	 * @var WooCatalogReader
	 */
	private $reader;

	/**
	 * Rows.
	 *
	 * @var CatalogRepository
	 */
	private $repo;

	/**
	 * Providers (for garment-region fit).
	 *
	 * @var ProviderRegistry
	 */
	private $providers;

	/**
	 * Per-request image score cache.
	 *
	 * @var array<int,ImageScore>
	 */
	private $image_cache = array();

	/**
	 * Constructor.
	 *
	 * @param WooCatalogReader  $reader    Reader.
	 * @param CatalogRepository $repo      Rows.
	 * @param ProviderRegistry  $providers Providers.
	 */
	public function __construct( WooCatalogReader $reader, CatalogRepository $repo, ProviderRegistry $providers ) {
		$this->reader    = $reader;
		$this->repo      = $repo;
		$this->providers = $providers;
	}

	/**
	 * Scan one product.
	 *
	 * @param int  $product_id Product.
	 * @param bool $force      Rescan even if unchanged.
	 * @return string scanned | skipped | removed
	 */
	public function scan_product( int $product_id, bool $force = false ): string {
		$dto = $this->reader->read( $product_id );
		if ( ! $dto ) {
			return $this->record_unscannable( $product_id );
		}

		$existing = $this->repo->get( $product_id );
		$hash     = hash( 'sha256', $dto->content_hash . '|' . self::SCAN_VERSION . '|' . Sampler::library() );
		if ( ! $force && $existing && $existing['content_hash'] === $hash && ! empty( $existing['scanned_at'] ) ) {
			return 'skipped';
		}

		$cls = $this->classify( $dto, $existing );

		// Image scores (featured first).
		$scores = array();
		foreach ( $dto->images as $image ) {
			$scores[ $image->attachment_id ] = $this->score_image( $image );
		}
		$selector = new SourceImageSelector();
		$best_id  = $selector->best( $scores );
		if ( $existing && 'merchant' === $existing['best_image_source'] && isset( $scores[ (int) $existing['best_image_id'] ] ) ) {
			$best_id = (int) $existing['best_image_id'];
		}
		$best = null !== $best_id ? $scores[ $best_id ] : null;

		// Colour variants: one entry per distinct colour.
		list( $colours, $variant_rows ) = $this->variants( $dto, $scores, $best_id );

		$readiness = ( new ReadinessCalculator() )->calculate( $cls, $best, $colours, $this->provider_fit( $cls, $dto, $best_id ) );

		/**
		 * Adjust a product's readiness verdict before it is stored.
		 *
		 * @param Readiness      $readiness Verdict.
		 * @param Classification $cls     Classification.
		 * @param ProductDTO     $dto       Product.
		 */
		$readiness = apply_filters( 'weargraph_catalog_readiness', $readiness, $cls, $dto );

		$row = array(
			'garment_region'      => $cls->region,
			'gender_presentation' => $cls->gender,
			'pattern'             => $cls->pattern,
			'dominant_colors'     => implode( ',', $cls->colors ),
			'tryon_mode'          => $cls->tryon_mode,
			'image_scores_json'   => wp_json_encode( array_map( static function ( ImageScore $s ) { return $s->to_array(); }, $scores ) ), // phpcs:ignore Squiz.Functions.MultiLineFunctionDeclaration.ContentAfterBrace -- compact mapper.
			'readiness_score'     => $readiness->score,
			'readiness_status'    => $readiness->status,
			'problems_json'       => wp_json_encode( $readiness->problems ),
			'classification_json' => wp_json_encode( $cls->to_array() ),
			'confidence'          => $cls->confidence,
			'content_hash'        => $hash,
			'scanned_at'          => current_time( 'mysql', true ),
		);

		// Automatic values only where the merchant has not overridden them.
		if ( ! $existing || 'merchant' !== $existing['category_source'] ) {
			$row['category']        = $cls->category;
			$row['category_source'] = 'ai';
		}
		if ( ! $existing || 'merchant' !== $existing['layer_source'] ) {
			$row['layer']        = $cls->layer;
			$row['layer_source'] = 'ai';
		}
		if ( ! $existing || 'merchant' !== $existing['best_image_source'] ) {
			$row['best_image_id']     = $best_id;
			$row['best_image_source'] = 'ai';
		}
		if ( ! $existing || 'merchant' !== $existing['tryon_enabled_source'] ) {
			$row['tryon_enabled']        = Readiness::READY === $readiness->status ? 1 : 0;
			$row['tryon_enabled_source'] = 'ai';
		}

		$this->repo->upsert( $product_id, 0, $row );

		foreach ( $variant_rows as $variation_id => $variant_row ) {
			$this->repo->upsert(
				$product_id,
				$variation_id,
				array_merge(
					$variant_row,
					array(
						'category'         => $cls->category,
						'garment_region'   => $cls->region,
						'layer'            => $cls->layer,
						'tryon_mode'       => $cls->tryon_mode,
						'readiness_score'  => $readiness->score,
						'readiness_status' => $readiness->status,
						'confidence'       => $cls->confidence,
						'content_hash'     => $hash,
						'scanned_at'       => current_time( 'mysql', true ),
					)
				)
			);
		}
		$this->repo->prune_variants( $product_id, array_keys( $variant_rows ) );

		/**
		 * A product was (re)scanned.
		 *
		 * @param int       $product_id Product.
		 * @param Readiness $readiness  Verdict.
		 */
		do_action( 'weargraph_catalog_scanned', $product_id, $readiness );

		return 'scanned';
	}

	/**
	 * A product that exists but cannot be visualised (grouped, bundles…) is recorded as
	 * unsupported so every product is accounted for; a deleted one loses its rows.
	 *
	 * @param int $product_id Product.
	 * @return string scanned | removed
	 */
	private function record_unscannable( int $product_id ): string {
		$product = 'product' === get_post_type( $product_id ) ? wc_get_product( $product_id ) : null;
		if ( ! $product || ! in_array( $product->get_status(), WooCatalogReader::STATUSES, true ) ) {
			$this->repo->delete_product( $product_id );
			return 'removed';
		}

		$type = $product->get_type();
		$this->repo->upsert(
			$product_id,
			0,
			array(
				'category'             => 'other',
				'category_source'      => 'ai',
				'tryon_mode'           => 'none',
				'tryon_enabled'        => 0,
				'tryon_enabled_source' => 'ai',
				'readiness_score'      => 0,
				'readiness_status'     => Readiness::UNSUPPORTED,
				'problems_json'        => wp_json_encode(
					array(
						array(
							'code'   => 'unsupported_product_type',
							'params' => array( 'type' => $type ),
						),
					)
				),
				'content_hash'         => hash( 'sha256', 'type:' . $type . '|' . self::SCAN_VERSION ),
				'scanned_at'           => current_time( 'mysql', true ),
			)
		);
		$this->repo->prune_variants( $product_id, array() );

		return 'scanned';
	}

	/**
	 * Classification, with a merchant category override applied.
	 *
	 * @param ProductDTO               $dto      Product.
	 * @param array<string,mixed>|null $existing Existing row.
	 */
	private function classify( ProductDTO $dto, ?array $existing ): Classification {
		$classifier = new Classifier(
			/**
			 * Classification vocabulary (see Catalog\Keywords::categories()).
			 *
			 * @param array<string,array> $categories Categories.
			 */
			(array) apply_filters( 'weargraph_catalog_keywords', Keywords::categories() )
		);
		$cls = $classifier->classify( $dto->title, $dto->categories, $dto->tags, $dto->attributes );

		/**
		 * Replace or refine a product's classification (Pro P7: vision model when confidence is low).
		 *
		 * @param Classification $cls Classification.
		 * @param ProductDTO     $dto   Product.
		 */
		$cls = apply_filters( 'weargraph_catalog_classify', $cls, $dto );

		if ( $existing && 'merchant' === $existing['category_source'] ) {
			$def = Keywords::categories()[ (string) $existing['category'] ] ?? null;
			if ( $def ) {
				$cls->category   = (string) $existing['category'];
				$cls->region     = (string) $def['region'];
				$cls->layer      = (string) $def['layer'];
				$cls->tryon_mode = (string) $def['mode'];
				$cls->confidence = 1.0;
			} elseif ( 'other' === $existing['category'] ) {
				$cls->category   = 'other';
				$cls->region     = '';
				$cls->tryon_mode = 'none';
				$cls->confidence = 1.0;
			}
		}
		if ( $existing && 'merchant' === $existing['layer_source'] && '' !== (string) $existing['layer'] ) {
			$cls->layer = (string) $existing['layer'];
		}

		return $cls;
	}

	/**
	 * Score one image (cached per request).
	 *
	 * @param ImageDTO $image Image.
	 */
	private function score_image( ImageDTO $image ): ImageScore {
		if ( ! isset( $this->image_cache[ $image->attachment_id ] ) ) {
			$sample = Sampler::sample( $image->path );

			$this->image_cache[ $image->attachment_id ] = ( new ImageScorer() )->score(
				$image->width,
				$image->height,
				$sample ? $sample['grid'] : null,
				$sample ? $sample['hash'] : null
			);
		}

		return $this->image_cache[ $image->attachment_id ];
	}

	/**
	 * Colour variant coverage and per-variation rows.
	 *
	 * @param ProductDTO            $dto     Product.
	 * @param array<int,ImageScore> $scores  Product image scores.
	 * @param int|null              $best_id Product best image.
	 * @return array{0:array<int,array{label:string,has_image:bool,dhash:string}>,1:array<int,array<string,mixed>>}
	 */
	private function variants( ProductDTO $dto, array $scores, ?int $best_id ): array {
		$selector = new SourceImageSelector();
		$colours  = array();
		$rows     = array();

		foreach ( $dto->variants as $variant ) {
			$colour = '';
			foreach ( $variant->attributes as $label => $value ) {
				if ( '' !== $value && preg_match( '/colou?r|farbe|couleur|colore|cor\b/i', (string) $label ) ) {
					$colour = (string) $value;
					break;
				}
			}

			$own_id = $variant->image ? $variant->image->attachment_id : null;
			$own    = $variant->image ? $this->score_image( $variant->image ) : null;

			$rows[ $variant->variation_id ] = array(
				'variant_label'     => implode( ' / ', array_filter( array_map( 'strval', $variant->attributes ) ) ),
				'dominant_colors'   => $colour,
				'best_image_id'     => $selector->for_variant( $own_id, $best_id ),
				'best_image_source' => 'ai',
				'image_scores_json' => $own ? wp_json_encode( array( $own_id => $own->to_array() ) ) : null,
			);

			if ( '' !== $colour && ! isset( $colours[ $colour ] ) ) {
				$colours[ $colour ] = array(
					'label'     => $colour,
					'has_image' => null !== $own_id,
					'dhash'     => $own ? $own->dhash : '',
				);
			} elseif ( '' !== $colour && null !== $own_id && ! $colours[ $colour ]['has_image'] ) {
				$colours[ $colour ]['has_image'] = true;
				$colours[ $colour ]['dhash']     = $own ? $own->dhash : '';
			}
		}

		return array( array_values( $colours ), $rows );
	}

	/**
	 * How well the configured provider handles this garment region (0–1).
	 *
	 * @param Classification $cls   Classification.
	 * @param ProductDTO     $dto     Product.
	 * @param int|null       $best_id Best image.
	 */
	private function provider_fit( Classification $cls, ProductDTO $dto, ?int $best_id ): float {
		$provider = $this->providers->configured();
		if ( ! $provider instanceof TryOnCapable || ! in_array( $cls->region, TryOnInput::REGIONS, true ) ) {
			return 0.8;
		}

		foreach ( $dto->images as $image ) {
			if ( $image->attachment_id === $best_id && in_array( $image->mime, array( 'image/jpeg', 'image/png', 'image/webp' ), true ) ) {
				$ref = new ImageRef( '' !== $image->path ? $image->path : 'x', $image->mime );
				return $provider->can_handle( new TryOnInput( $ref, array( $ref ), $cls->region, '', new ImageOptions() ) )->confidence;
			}
		}

		return 0.8;
	}
}

<?php
/**
 * Picks the best try-on source image (blueprint §13).
 *
 * @package WearGraph
 */

namespace WearGraph\Catalog;

defined( 'ABSPATH' ) || exit;

/**
 * Highest total wins; ties go to the earlier image (the featured image comes first,
 * and merchants usually put their best shot there).
 */
final class SourceImageSelector {

	/**
	 * Best image key.
	 *
	 * @param array<int|string,ImageScore> $scores Scores in display order (featured first).
	 * @return int|string|null Key of the best image, or null when there are none.
	 */
	public function best( array $scores ) {
		$best_key   = null;
		$best_total = -1.0;
		foreach ( $scores as $key => $score ) {
			if ( $score->total > $best_total + 1e-9 ) {
				$best_key   = $key;
				$best_total = $score->total;
			}
		}

		return $best_key;
	}

	/**
	 * Source for a variant: its own image whenever it has one — even a weaker photo of
	 * the right colour beats a sharp photo of the wrong colour — else the product's best
	 * (the scan reports that variant as missing its own image).
	 *
	 * @param int|string|null $own_key      Variant's own image key.
	 * @param int|string|null $product_best Product best key.
	 * @return int|string|null
	 */
	public function for_variant( $own_key, $product_best ) {
		return null !== $own_key ? $own_key : $product_best;
	}
}

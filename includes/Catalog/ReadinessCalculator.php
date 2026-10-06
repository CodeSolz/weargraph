<?php
/**
 * Computes try-on readiness from classification, image quality and variant coverage.
 *
 * @package WearGraph
 */

namespace WearGraph\Catalog;

defined( 'ABSPATH' ) || exit;

/**
 * Pure. score = 45·image + 25·category confidence + 15·variant coverage + 15·provider fit.
 *
 * Status rules, in order:
 *   try-on mode "none"            → unsupported (score 0)
 *   no usable image               → needs_image
 *   best image total < 0.55       → needs_image
 *   accessory, or confidence < .5 → needs_review
 *   score ≥ 70                    → ready
 *   otherwise                     → needs_review
 */
final class ReadinessCalculator {

	public const READY_SCORE     = 70;
	public const MIN_IMAGE_TOTAL = 0.55;
	public const MIN_CONFIDENCE  = 0.5;

	/**
	 * Calculate.
	 *
	 * @param Classification                                                 $cls     Classification.
	 * @param ImageScore|null                                                $best      Best source image score.
	 * @param array<int,array{label:string,has_image:bool,dhash:string}>     $variants  Colour variants (empty for simple products).
	 * @param float                                                          $provider  Provider confidence for this garment region (0–1).
	 */
	public function calculate( Classification $cls, ?ImageScore $best, array $variants = array(), float $provider = 0.8 ): Readiness {
		$r = new Readiness();

		if ( 'none' === $cls->tryon_mode ) {
			$r->status = Readiness::UNSUPPORTED;
			if ( in_array( $cls->category, array( 'swimwear', 'lingerie' ), true ) ) {
				$r->add( 'sensitive_category', array( 'category' => $cls->category ) );
			} elseif ( 'other' === $cls->category ) {
				$r->add( 'not_wearable' );
			} else {
				$r->add( 'not_visualisable', array( 'category' => $cls->category ) );
			}
			return $r;
		}

		if ( ! $best ) {
			$r->status = Readiness::NEEDS_IMAGE;
			$r->score  = (int) round( 25 * $cls->confidence + 15 * $provider );
			$r->add( 'no_image' );
			return $r;
		}

		foreach ( $best->notes as $note ) {
			$params = 'low_resolution' === $note ? array(
				'width'  => $best->width,
				'height' => $best->height,
			) : array();
			$r->add( $note, $params );
		}

		$coverage = $this->variant_coverage( $variants, $r );

		$r->score = (int) round( 45 * $best->total + 25 * $cls->confidence + 15 * $coverage + 15 * $provider );
		$r->score = max( 0, min( 100, $r->score ) );

		if ( $cls->confidence < self::MIN_CONFIDENCE ) {
			$r->add( 'low_category_confidence' );
		}
		if ( 'accessory' === $cls->tryon_mode ) {
			$r->add( 'experimental_accessory' );
		}

		if ( $best->total < self::MIN_IMAGE_TOTAL ) {
			$r->status = Readiness::NEEDS_IMAGE;
		} elseif ( 'accessory' === $cls->tryon_mode || $cls->confidence < self::MIN_CONFIDENCE ) {
			$r->status = Readiness::NEEDS_REVIEW;
		} else {
			$r->status = $r->score >= self::READY_SCORE ? Readiness::READY : Readiness::NEEDS_REVIEW;
		}

		return $r;
	}

	/**
	 * Share of colour variants with their own, distinct image; records problems.
	 *
	 * @param array<int,array{label:string,has_image:bool,dhash:string}> $variants Colour variants.
	 * @param Readiness                                                  $r        Result to annotate.
	 */
	private function variant_coverage( array $variants, Readiness $r ): float {
		if ( count( $variants ) < 2 ) {
			return 1.0;
		}

		$missing = array();
		$hashes  = array();
		foreach ( $variants as $variant ) {
			if ( ! $variant['has_image'] ) {
				$missing[] = $variant['label'];
				continue;
			}
			if ( '' !== $variant['dhash'] ) {
				$hashes[ $variant['label'] ] = $variant['dhash'];
			}
		}

		if ( $missing ) {
			$r->add( 'variant_missing_image', array( 'variants' => $missing ) );
		}

		// Different colours pointing at the same photo: the preview would show the wrong colour.
		$labels = array_keys( $hashes );
		$n      = count( $labels );
		$same   = array();
		for ( $i = 0; $i < $n; $i++ ) {
			for ( $j = $i + 1; $j < $n; $j++ ) {
				if ( ImageScorer::distance( $hashes[ $labels[ $i ] ], $hashes[ $labels[ $j ] ] ) <= 4 ) {
					$same[] = $labels[ $i ];
					$same[] = $labels[ $j ];
				}
			}
		}
		if ( $same ) {
			$r->add( 'variant_images_identical', array( 'variants' => array_values( array_unique( $same ) ) ) );
		}

		$good = count( $variants ) - count( $missing ) - (int) floor( count( array_unique( $same ) ) / 2 );

		return max( 0.0, $good / count( $variants ) );
	}
}

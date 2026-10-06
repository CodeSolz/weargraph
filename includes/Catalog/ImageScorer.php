<?php
/**
 * Scores product images as try-on garment sources (blueprint §13).
 *
 * @package WearGraph
 */

namespace WearGraph\Catalog;

defined( 'ABSPATH' ) || exit;

/**
 * Pure arithmetic over dimensions and an optional PixelGrid. Heuristics favour
 * precision: they flag clear problems (tiny, busy background, product lost in the
 * frame) and never pretend to know more than the pixels show.
 *
 *   total = 0.40·resolution + 0.15·aspect + 0.25·background + 0.20·style suitability
 *
 * Without pixels, background and style count as a neutral 0.7.
 */
final class ImageScorer {

	private const NEUTRAL = 0.7;

	/**
	 * Score one image.
	 *
	 * @param int            $width  Width in px.
	 * @param int            $height Height in px.
	 * @param PixelGrid|null $grid   Downscaled pixels (≈32×32), or null when unavailable.
	 * @param PixelGrid|null $hash   9×8 grid for the perceptual hash, or null.
	 */
	public function score( int $width, int $height, ?PixelGrid $grid = null, ?PixelGrid $hash = null ): ImageScore {
		$s         = new ImageScore();
		$s->width  = $width;
		$s->height = $height;

		$s->resolution = self::resolution_score( min( $width, $height ) );
		if ( $s->resolution < 0.7 ) {
			$s->notes[] = 'low_resolution';
		}

		$s->aspect = self::aspect_score( $width, $height );
		if ( $s->aspect < 0.7 ) {
			$s->notes[] = 'extreme_aspect';
		}

		$style_fit = self::NEUTRAL;
		$bg_score  = self::NEUTRAL;

		if ( $grid ) {
			$s->analysed   = true;
			$bg            = self::border_stats( $grid );
			$s->background = max( 0.0, min( 1.0, 1 - $bg['std'] / 60 ) );
			$s->coverage   = self::coverage( $grid, $bg['mean'] );
			$skin          = self::skin_ratio( $grid );

			if ( $skin > 0.08 ) {
				$s->style   = ImageScore::STYLE_ON_MODEL;
				$style_fit  = 0.75;
				$s->notes[] = 'on_model_image';
			} elseif ( $s->background >= 0.75 ) {
				$s->style  = ImageScore::STYLE_PRODUCT;
				$style_fit = 1.0;
			} else {
				$s->style  = ImageScore::STYLE_SCENE;
				$style_fit = 0.55;
			}

			if ( $s->background < 0.5 ) {
				$s->notes[] = 'busy_background';
			}
			if ( ImageScore::STYLE_PRODUCT === $s->style && $s->coverage < 0.08 ) {
				$s->notes[] = 'product_too_small';
				$style_fit  = 0.5;
			}
			$bg_score = $s->background;
		}

		if ( $hash ) {
			$s->dhash = self::dhash( $hash );
		}

		$s->total = 0.40 * $s->resolution + 0.15 * $s->aspect + 0.25 * $bg_score + 0.20 * $style_fit;

		return $s;
	}

	/**
	 * Resolution component from the shorter side.
	 *
	 * @param int $short_side Pixels.
	 */
	public static function resolution_score( int $short_side ): float {
		if ( $short_side <= 0 ) {
			return 0.0;
		}
		if ( $short_side < 300 ) {
			return 0.1;
		}
		if ( $short_side < 500 ) {
			return 0.4;
		}
		if ( $short_side < 800 ) {
			return 0.7;
		}

		return $short_side < 1200 ? 0.9 : 1.0;
	}

	/**
	 * Aspect component: portrait and square frames suit garments; wide banners do not.
	 *
	 * @param int $width  Width.
	 * @param int $height Height.
	 */
	public static function aspect_score( int $width, int $height ): float {
		if ( $width <= 0 || $height <= 0 ) {
			return 0.0;
		}
		$ratio = $height / $width;
		if ( $ratio >= 0.8 && $ratio <= 1.6 ) {
			return 1.0;
		}
		if ( ( $ratio >= 0.6 && $ratio < 0.8 ) || ( $ratio > 1.6 && $ratio <= 2.2 ) ) {
			return 0.8;
		}

		return 0.5;
	}

	/**
	 * Mean colour and luminance spread of the outer ring of pixels.
	 *
	 * @param PixelGrid $grid Grid.
	 * @return array{mean:array{0:float,1:float,2:float},std:float}
	 */
	public static function border_stats( PixelGrid $grid ): array {
		$ring = array();
		for ( $x = 0; $x < $grid->width; $x++ ) {
			$ring[] = $grid->pixels[0][ $x ];
			$ring[] = $grid->pixels[ $grid->height - 1 ][ $x ];
		}
		for ( $y = 1; $y < $grid->height - 1; $y++ ) {
			$ring[] = $grid->pixels[ $y ][0];
			$ring[] = $grid->pixels[ $y ][ $grid->width - 1 ];
		}

		$n    = count( $ring );
		$mean = array( 0.0, 0.0, 0.0 );
		$lums = array();
		foreach ( $ring as $px ) {
			$mean[0] += $px[0] / $n;
			$mean[1] += $px[1] / $n;
			$mean[2] += $px[2] / $n;
			$lums[]   = PixelGrid::luma( $px );
		}

		$avg = array_sum( $lums ) / $n;
		$var = 0.0;
		foreach ( $lums as $l ) {
			$var += ( $l - $avg ) ** 2 / $n;
		}

		return array(
			'mean' => $mean,
			'std'  => sqrt( $var ),
		);
	}

	/**
	 * Fraction of pixels that differ clearly from the background colour.
	 *
	 * @param PixelGrid                     $grid Grid.
	 * @param array{0:float,1:float,2:float} $bg   Background mean.
	 */
	public static function coverage( PixelGrid $grid, array $bg ): float {
		$diff  = 0;
		$total = $grid->width * $grid->height;
		foreach ( $grid->pixels as $row ) {
			foreach ( $row as $px ) {
				$distance = sqrt( ( $px[0] - $bg[0] ) ** 2 + ( $px[1] - $bg[1] ) ** 2 + ( $px[2] - $bg[2] ) ** 2 );
				if ( $distance > 40 ) {
					++$diff;
				}
			}
		}

		return $total ? $diff / $total : 0.0;
	}

	/**
	 * Fraction of the central area with skin-like colours (a person in the photo).
	 *
	 * @param PixelGrid $grid Grid.
	 */
	public static function skin_ratio( PixelGrid $grid ): float {
		$x0    = (int) floor( $grid->width * 0.2 );
		$x1    = (int) ceil( $grid->width * 0.8 );
		$y0    = (int) floor( $grid->height * 0.05 );
		$y1    = (int) ceil( $grid->height * 0.95 );
		$skin  = 0;
		$total = 0;

		for ( $y = $y0; $y < $y1; $y++ ) {
			for ( $x = $x0; $x < $x1; $x++ ) {
				list( $r, $g, $b ) = $grid->pixels[ $y ][ $x ];
				++$total;
				// Classic RGB skin rule (Peer et al.), adequate for a coarse "person present" signal.
				if ( $r > 95 && $g > 40 && $b > 20 && $r > $g && $r > $b && abs( $r - $g ) > 15 && ( max( $r, $g, $b ) - min( $r, $g, $b ) ) > 15 ) {
					++$skin;
				}
			}
		}

		return $total ? $skin / $total : 0.0;
	}

	/**
	 * Difference hash of a 9×8 grid → 16 hex chars.
	 *
	 * @param PixelGrid $grid 9 columns × 8 rows.
	 */
	public static function dhash( PixelGrid $grid ): string {
		$bits = '';
		$rows = min( 8, $grid->height );
		$cols = min( 8, $grid->width - 1 );
		for ( $y = 0; $y < $rows; $y++ ) {
			for ( $x = 0; $x < $cols; $x++ ) {
				$bits .= PixelGrid::luma( $grid->pixels[ $y ][ $x ] ) > PixelGrid::luma( $grid->pixels[ $y ][ $x + 1 ] ) ? '1' : '0';
			}
		}
		$bits = str_pad( $bits, 64, '0' );
		$hex  = '';
		foreach ( str_split( $bits, 4 ) as $nibble ) {
			$hex .= dechex( (int) bindec( $nibble ) );
		}

		return $hex;
	}

	/**
	 * Hamming distance between two dhashes (64 when either is empty).
	 *
	 * @param string $a Hash.
	 * @param string $b Hash.
	 */
	public static function distance( string $a, string $b ): int {
		if ( 16 !== strlen( $a ) || 16 !== strlen( $b ) ) {
			return 64;
		}
		$d = 0;
		for ( $i = 0; $i < 16; $i++ ) {
			$d += substr_count( decbin( hexdec( $a[ $i ] ) ^ hexdec( $b[ $i ] ) ), '1' );
		}

		return $d;
	}
}

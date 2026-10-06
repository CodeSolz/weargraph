<?php
/**
 * Output shape → pixel dimensions for providers that want width × height.
 *
 * @package WearGraph
 */

namespace WearGraph\Providers;

defined( 'ABSPATH' ) || exit;

use WearGraph\Domain\ImageOptions;

/**
 * Pure. The size bucket sets the long edge (512, 1024, 2048, 4096 before limits); the
 * aspect ratio sets the short edge; both are rounded to the provider's step and kept
 * within its edge, pixel-count and ratio limits.
 */
final class ImageSizing {

	private const LONG_EDGE = array(
		'512' => 512,
		'1K'  => 1024,
		'2K'  => 2048,
		'4K'  => 4096,
	);

	/**
	 * Width and height.
	 *
	 * @param ImageOptions $options    Aspect ratio and size bucket.
	 * @param int          $step       Both edges are multiples of this.
	 * @param int          $max_edge   Longest allowed edge.
	 * @param int          $max_pixels Largest allowed width × height.
	 * @param int          $min_edge   Shortest allowed edge.
	 * @return array{0:int,1:int}
	 */
	public static function dimensions( ImageOptions $options, int $step = 16, int $max_edge = 2048, int $max_pixels = 4194304, int $min_edge = 256 ): array {
		list( $rw, $rh ) = self::ratio( $options->aspect_ratio );
		$long            = min( self::LONG_EDGE[ $options->size ] ?? 1024, $max_edge );

		if ( $rw >= $rh ) {
			$w = $long;
			$h = $long * $rh / $rw;
		} else {
			$h = $long;
			$w = $long * $rw / $rh;
		}

		// Too many pixels: scale both edges down evenly.
		if ( $w * $h > $max_pixels ) {
			$scale = sqrt( $max_pixels / ( $w * $h ) );
			$w    *= $scale;
			$h    *= $scale;
		}

		$w = max( $min_edge, (int) floor( $w / $step ) * $step );
		$h = max( $min_edge, (int) floor( $h / $step ) * $step );

		return array( $w, $h );
	}

	/**
	 * The closest of a provider's fixed sizes, by aspect ratio then by size.
	 *
	 * @param ImageOptions $options Output shape.
	 * @param string[]     $sizes   e.g. array( '1024x1024', '1536x1024', '1024x1536' ).
	 */
	public static function nearest( ImageOptions $options, array $sizes ): string {
		list( $rw, $rh ) = self::ratio( $options->aspect_ratio );
		$want            = log( $rw / $rh );
		$best            = (string) reset( $sizes );
		$best_gap        = INF;
		foreach ( $sizes as $size ) {
			if ( ! preg_match( '/^(\d+)x(\d+)$/', (string) $size, $m ) ) {
				continue;
			}
			$gap = abs( log( (int) $m[1] / (int) $m[2] ) - $want );
			if ( $gap < $best_gap - 1e-9 ) {
				$best     = (string) $size;
				$best_gap = $gap;
			}
		}

		return $best;
	}

	/**
	 * Megapixels of a size (for per-megapixel pricing).
	 *
	 * @param int $width  Width.
	 * @param int $height Height.
	 */
	public static function megapixels( int $width, int $height ): float {
		return $width * $height / 1048576;
	}

	/**
	 * "4:5" → array( 4, 5 ).
	 *
	 * @param string $ratio Aspect ratio.
	 * @return array{0:int,1:int}
	 */
	public static function ratio( string $ratio ): array {
		if ( preg_match( '/^(\d+):(\d+)$/', $ratio, $m ) && (int) $m[1] > 0 && (int) $m[2] > 0 ) {
			return array( (int) $m[1], (int) $m[2] );
		}

		return array( 4, 5 );
	}
}

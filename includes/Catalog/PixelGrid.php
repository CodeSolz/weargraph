<?php
/**
 * A small downscaled RGB grid of an image.
 *
 * @package WearGraph
 */

namespace WearGraph\Catalog;

defined( 'ABSPATH' ) || exit;

/**
 * Samplers (GD, Imagick) produce this; the scorer only ever reads it, so all image
 * heuristics are plain arithmetic that can be unit-tested with synthetic grids.
 */
final class PixelGrid {

	/**
	 * Columns.
	 *
	 * @var int
	 */
	public $width;

	/**
	 * Rows.
	 *
	 * @var int
	 */
	public $height;

	/**
	 * Row-major pixels: $pixels[ y ][ x ] = array( r, g, b ).
	 *
	 * @var array<int,array<int,array{0:int,1:int,2:int}>>
	 */
	public $pixels;

	/**
	 * Constructor.
	 *
	 * @param array<int,array<int,array{0:int,1:int,2:int}>> $pixels Rows of RGB triples.
	 * @throws \InvalidArgumentException On an empty or ragged grid.
	 */
	public function __construct( array $pixels ) {
		if ( ! $pixels || ! is_array( $pixels[0] ) || ! $pixels[0] ) {
			throw new \InvalidArgumentException( 'Empty pixel grid.' );
		}
		$width = count( $pixels[0] );
		foreach ( $pixels as $row ) {
			if ( count( $row ) !== $width ) {
				throw new \InvalidArgumentException( 'Ragged pixel grid.' );
			}
		}

		$this->pixels = array_values( $pixels );
		$this->height = count( $pixels );
		$this->width  = $width;
	}

	/**
	 * Grid filled with one colour (tests and fixtures).
	 *
	 * @param int                     $w   Width.
	 * @param int                     $h   Height.
	 * @param array{0:int,1:int,2:int} $rgb Colour.
	 */
	public static function filled( int $w, int $h, array $rgb ): self {
		return new self( array_fill( 0, $h, array_fill( 0, $w, $rgb ) ) );
	}

	/**
	 * Copy with a rectangle painted (tests and fixtures).
	 *
	 * @param int                     $x0  Left.
	 * @param int                     $y0  Top.
	 * @param int                     $x1  Right (exclusive).
	 * @param int                     $y1  Bottom (exclusive).
	 * @param array{0:int,1:int,2:int} $rgb Colour.
	 */
	public function with_rect( int $x0, int $y0, int $x1, int $y1, array $rgb ): self {
		$pixels = $this->pixels;
		$y_end  = min( $this->height, $y1 );
		$x_end  = min( $this->width, $x1 );
		for ( $y = max( 0, $y0 ); $y < $y_end; $y++ ) {
			for ( $x = max( 0, $x0 ); $x < $x_end; $x++ ) {
				$pixels[ $y ][ $x ] = $rgb;
			}
		}

		return new self( $pixels );
	}

	/**
	 * Luminance 0–255 of one pixel.
	 *
	 * @param array{0:int,1:int,2:int} $rgb Pixel.
	 */
	public static function luma( array $rgb ): float {
		return 0.299 * $rgb[0] + 0.587 * $rgb[1] + 0.114 * $rgb[2];
	}
}

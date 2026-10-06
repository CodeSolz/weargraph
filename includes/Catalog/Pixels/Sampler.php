<?php
/**
 * Reads an image file into small PixelGrids (GD or Imagick).
 *
 * @package WearGraph
 */

namespace WearGraph\Catalog\Pixels;

defined( 'ABSPATH' ) || exit;

use WearGraph\Catalog\PixelGrid;

/**
 * Picks whichever image library the server has. When neither GD nor Imagick is
 * available, sample() returns null and scoring falls back to size-only.
 * Refuses very large images before decoding them (memory and decompression-bomb guard).
 */
final class Sampler {

	public const GRID     = 32;
	public const MAX_SIDE = 8000;
	public const MAX_PIX  = 40000000;

	/**
	 * Library in use: gd | imagick | '' (none).
	 */
	public static function library(): string {
		if ( extension_loaded( 'imagick' ) && class_exists( '\Imagick' ) ) {
			return 'imagick';
		}
		if ( extension_loaded( 'gd' ) && function_exists( 'imagecreatetruecolor' ) ) {
			return 'gd';
		}

		return '';
	}

	/**
	 * Sample a local image: a 32×32 grid for scoring and a 9×8 grid for the hash.
	 *
	 * @param string $path Local file path.
	 * @return array{grid:PixelGrid,hash:PixelGrid}|null
	 */
	public static function sample( string $path ): ?array {
		$library = self::library();
		if ( '' === $library || '' === $path || ! is_readable( $path ) ) {
			return null;
		}

		$size = wp_getimagesize( $path );
		if ( ! $size || $size[0] < 1 || $size[1] < 1 || $size[0] > self::MAX_SIDE || $size[1] > self::MAX_SIDE || $size[0] * $size[1] > self::MAX_PIX ) {
			return null;
		}

		try {
			return 'imagick' === $library ? self::with_imagick( $path ) : self::with_gd( $path, (string) $size['mime'] );
		} catch ( \Throwable $e ) {
			return null;
		}
	}

	/**
	 * GD implementation.
	 *
	 * @param string $path Path.
	 * @param string $mime MIME type.
	 * @return array{grid:PixelGrid,hash:PixelGrid}|null
	 */
	private static function with_gd( string $path, string $mime ): ?array {
		$loaders = array(
			'image/jpeg' => 'imagecreatefromjpeg',
			'image/png'  => 'imagecreatefrompng',
			'image/webp' => 'imagecreatefromwebp',
			'image/gif'  => 'imagecreatefromgif',
		);
		if ( ! isset( $loaders[ $mime ] ) || ! function_exists( $loaders[ $mime ] ) ) {
			return null;
		}

		$src = @$loaders[ $mime ]( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- corrupt files must not emit warnings; null is handled.
		if ( ! $src ) {
			return null;
		}

		$grid = self::gd_grid( $src, self::GRID, self::GRID );
		$hash = self::gd_grid( $src, 9, 8 );
		imagedestroy( $src );

		return $grid && $hash ? array(
			'grid' => $grid,
			'hash' => $hash,
		) : null;
	}

	/**
	 * Resample a GD image to w×h and read the pixels (transparent areas count as white).
	 *
	 * @param \GdImage|resource $src Source image.
	 * @param int               $w   Width.
	 * @param int               $h   Height.
	 */
	private static function gd_grid( $src, int $w, int $h ): ?PixelGrid {
		$dst = imagecreatetruecolor( $w, $h );
		imagefill( $dst, 0, 0, imagecolorallocate( $dst, 255, 255, 255 ) );
		imagecopyresampled( $dst, $src, 0, 0, 0, 0, $w, $h, imagesx( $src ), imagesy( $src ) );

		$rows = array();
		for ( $y = 0; $y < $h; $y++ ) {
			$row = array();
			for ( $x = 0; $x < $w; $x++ ) {
				$c     = imagecolorat( $dst, $x, $y );
				$row[] = array( ( $c >> 16 ) & 0xFF, ( $c >> 8 ) & 0xFF, $c & 0xFF );
			}
			$rows[] = $row;
		}
		imagedestroy( $dst );

		return new PixelGrid( $rows );
	}

	/**
	 * Imagick implementation.
	 *
	 * @param string $path Path.
	 * @return array{grid:PixelGrid,hash:PixelGrid}|null
	 */
	private static function with_imagick( string $path ): ?array {
		$read = static function ( int $w, int $h ) use ( $path ): PixelGrid {
			$im = new \Imagick( $path );
			$im->setImageBackgroundColor( 'white' );
			$im = $im->mergeImageLayers( \Imagick::LAYERMETHOD_FLATTEN );
			$im->resizeImage( $w, $h, \Imagick::FILTER_TRIANGLE, 1, false );
			$flat = $im->exportImagePixels( 0, 0, $w, $h, 'RGB', \Imagick::PIXEL_CHAR );
			$im->clear();

			$rows = array();
			for ( $y = 0; $y < $h; $y++ ) {
				$row = array();
				for ( $x = 0; $x < $w; $x++ ) {
					$i     = ( $y * $w + $x ) * 3;
					$row[] = array( (int) $flat[ $i ], (int) $flat[ $i + 1 ], (int) $flat[ $i + 2 ] );
				}
				$rows[] = $row;
			}

			return new PixelGrid( $rows );
		};

		return array(
			'grid' => $read( self::GRID, self::GRID ),
			'hash' => $read( 9, 8 ),
		);
	}
}

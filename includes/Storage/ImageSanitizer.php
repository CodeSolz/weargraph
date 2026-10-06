<?php
/**
 * Validates and cleans shopper photo uploads (blueprint §43).
 *
 * @package WearGraph
 */

namespace WearGraph\Storage;

defined( 'ABSPATH' ) || exit;

use WearGraph\Catalog\Pixels\Sampler;
use WearGraph\Support\Files;

/**
 * 1. size limit; 2. real type from the bytes (never the client's claim);
 * 3. dimensions and decompression-bomb guard; 4. with GD/Imagick: full decode,
 * EXIF orientation applied, downscale to 2048 px, re-encode as JPEG (drops every
 * byte of metadata and anything hidden after the image); without: structural
 * metadata strip (JPEG/PNG only).
 */
final class ImageSanitizer {

	public const MAX_BYTES  = 10485760;
	public const MIN_SIDE   = 256;
	public const MAX_SIDE   = 8000;
	public const MAX_PIXELS = 40000000;
	public const OUT_SIDE   = 2048;

	/**
	 * Sanitize an uploaded file.
	 *
	 * @param string $path Temporary upload path.
	 * @return array{bytes:string,mime:string,width:int,height:int}
	 * @throws SanitizeException With a code for the client.
	 */
	public static function sanitize( string $path ): array {
		if ( '' === $path || ! is_readable( $path ) ) {
			throw new SanitizeException( 'unreadable' );
		}
		$size = (int) filesize( $path );
		if ( $size <= 0 ) {
			throw new SanitizeException( 'unreadable' );
		}
		if ( $size > self::MAX_BYTES ) {
			throw new SanitizeException( 'too_large' );
		}

		$bytes = Files::read( $path );
		$mime  = self::sniff( $bytes );
		if ( '' === $mime ) {
			throw new SanitizeException( 'bad_type' );
		}

		$info = getimagesizefromstring( $bytes );
		if ( ! $info || $info[0] < 1 || $info[1] < 1 ) {
			throw new SanitizeException( 'bad_type' );
		}
		list( $w, $h ) = $info;
		if ( $w > self::MAX_SIDE || $h > self::MAX_SIDE || $w * $h > self::MAX_PIXELS ) {
			throw new SanitizeException( 'too_large' );
		}
		if ( min( $w, $h ) < self::MIN_SIDE ) {
			throw new SanitizeException( 'too_small' );
		}

		$library = Sampler::library();
		if ( 'gd' === $library ) {
			return self::reencode_gd( $bytes, $mime );
		}
		if ( 'imagick' === $library ) {
			return self::reencode_imagick( $bytes );
		}

		$clean = 'image/jpeg' === $mime ? MetadataStripper::jpeg( $bytes ) : ( 'image/png' === $mime ? MetadataStripper::png( $bytes ) : null );
		if ( null === $clean ) {
			// WebP can only be cleaned by re-encoding; without a library it is refused.
			throw new SanitizeException( 'image/webp' === $mime ? 'bad_type' : 'corrupt' );
		}

		return array(
			'bytes'  => $clean,
			'mime'   => $mime,
			'width'  => (int) $w,
			'height' => (int) $h,
		);
	}

	/**
	 * Detect the real type from magic bytes.
	 *
	 * @param string $bytes File bytes.
	 * @return string MIME type, or '' when not an accepted image.
	 */
	public static function sniff( string $bytes ): string {
		if ( 0 === strpos( $bytes, "\xFF\xD8\xFF" ) ) {
			return 'image/jpeg';
		}
		if ( 0 === strpos( $bytes, "\x89PNG\r\n\x1a\n" ) ) {
			return 'image/png';
		}
		if ( 'RIFF' === substr( $bytes, 0, 4 ) && 'WEBP' === substr( $bytes, 8, 4 ) ) {
			return 'image/webp';
		}

		return '';
	}

	/**
	 * GD path: decode, orient, downscale, re-encode JPEG.
	 *
	 * @param string $bytes Bytes.
	 * @param string $mime  Detected type.
	 * @return array{bytes:string,mime:string,width:int,height:int}
	 * @throws SanitizeException When decoding fails.
	 */
	private static function reencode_gd( string $bytes, string $mime ): array {
		$img = @imagecreatefromstring( $bytes ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- corrupt uploads must not emit warnings.
		if ( ! $img ) {
			throw new SanitizeException( 'corrupt' );
		}

		if ( 'image/jpeg' === $mime && function_exists( 'exif_read_data' ) ) {
			$exif   = @exif_read_data( 'data://image/jpeg;base64,' . base64_encode( $bytes ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- orientation only; malformed EXIF is ignored.
			$orient = is_array( $exif ) ? (int) ( $exif['Orientation'] ?? 1 ) : 1;
			$angle  = array(
				3 => 180,
				6 => -90,
				8 => 90,
			);
			if ( isset( $angle[ $orient ] ) ) {
				$rotated = imagerotate( $img, $angle[ $orient ], 0 );
				if ( $rotated ) {
					imagedestroy( $img );
					$img = $rotated;
				}
			}
		}

		$w     = imagesx( $img );
		$h     = imagesy( $img );
		$scale = min( 1, self::OUT_SIDE / max( $w, $h ) );
		$nw    = max( 1, (int) round( $w * $scale ) );
		$nh    = max( 1, (int) round( $h * $scale ) );

		$out = imagecreatetruecolor( $nw, $nh );
		imagefill( $out, 0, 0, imagecolorallocate( $out, 255, 255, 255 ) );
		imagecopyresampled( $out, $img, 0, 0, 0, 0, $nw, $nh, $w, $h );
		imagedestroy( $img );

		ob_start();
		imagejpeg( $out, null, 90 );
		$jpeg = (string) ob_get_clean();
		imagedestroy( $out );

		return array(
			'bytes'  => $jpeg,
			'mime'   => 'image/jpeg',
			'width'  => $nw,
			'height' => $nh,
		);
	}

	/**
	 * Imagick path.
	 *
	 * @param string $bytes Bytes.
	 * @return array{bytes:string,mime:string,width:int,height:int}
	 * @throws SanitizeException When decoding fails.
	 */
	private static function reencode_imagick( string $bytes ): array {
		try {
			$im = new \Imagick();
			$im->readImageBlob( $bytes );
			$im->autoOrient();
			$im->setImageBackgroundColor( 'white' );
			$im = $im->mergeImageLayers( \Imagick::LAYERMETHOD_FLATTEN );
			// Downscale only, like the GD path: bestfit alone would enlarge a
			// small photo up to OUT_SIDE.
			if ( max( $im->getImageWidth(), $im->getImageHeight() ) > self::OUT_SIDE ) {
				$im->thumbnailImage( self::OUT_SIDE, self::OUT_SIDE, true, false );
			}
			$im->stripImage();
			$im->setImageFormat( 'jpeg' );
			$im->setImageCompressionQuality( 90 );
			$out = array(
				'bytes'  => $im->getImageBlob(),
				'mime'   => 'image/jpeg',
				'width'  => $im->getImageWidth(),
				'height' => $im->getImageHeight(),
			);
			$im->clear();

			return $out;
		} catch ( \Throwable $e ) {
			throw new SanitizeException( 'corrupt' );
		}
	}
}

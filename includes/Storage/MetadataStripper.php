<?php
/**
 * Removes metadata (EXIF, GPS, XMP, ICC, comments) from JPEG and PNG bytes.
 *
 * @package WearGraph
 */

namespace WearGraph\Storage;

defined( 'ABSPATH' ) || exit;

/**
 * Pure PHP, used when no image library can re-encode the photo. It walks the file
 * structure and keeps only what is needed to display the pixels; anything malformed
 * is rejected rather than passed through.
 */
final class MetadataStripper {

	/**
	 * PNG chunks kept: the critical ones plus transparency and gamma.
	 */
	private const PNG_KEEP = array( 'IHDR', 'PLTE', 'IDAT', 'IEND', 'tRNS', 'gAMA', 'sRGB' );

	/**
	 * Strip a JPEG. Keeps SOI, JFIF (APP0), Adobe (APP14, needed for colour transforms),
	 * DQT/DHT/SOF/DRI and the scan; drops APP1–APP13, APP15 and COM.
	 *
	 * @param string $bytes JPEG bytes.
	 * @return string|null Stripped bytes, or null when the structure is invalid.
	 */
	public static function jpeg( string $bytes ): ?string {
		$len = strlen( $bytes );
		if ( $len < 4 || "\xFF\xD8" !== substr( $bytes, 0, 2 ) ) {
			return null;
		}

		$out = "\xFF\xD8";
		$pos = 2;
		while ( $pos + 4 <= $len ) {
			if ( "\xFF" !== $bytes[ $pos ] ) {
				return null;
			}
			$marker = ord( $bytes[ $pos + 1 ] );

			if ( 0xFF === $marker ) { // Fill byte.
				++$pos;
				continue;
			}
			if ( 0xD9 === $marker ) { // EOI before any scan.
				return null;
			}

			$seg_len = unpack( 'n', substr( $bytes, $pos + 2, 2 ) )[1];
			if ( $seg_len < 2 || $pos + 2 + $seg_len > $len ) {
				return null;
			}
			$segment = substr( $bytes, $pos, 2 + $seg_len );

			if ( 0xDA === $marker ) { // Start of scan: the rest is image data up to EOI.
				$rest = substr( $bytes, $pos );
				if ( false === strpos( $rest, "\xFF\xD9" ) ) {
					return null;
				}
				return $out . $rest;
			}

			$is_app = $marker >= 0xE0 && $marker <= 0xEF;
			$keep   = ! $is_app || 0xE0 === $marker || 0xEE === $marker;
			if ( 0xFE === $marker ) { // Comment.
				$keep = false;
			}
			if ( $keep ) {
				$out .= $segment;
			}
			$pos += 2 + $seg_len;
		}

		return null;
	}

	/**
	 * Strip a PNG down to rendering chunks, verifying every CRC.
	 *
	 * @param string $bytes PNG bytes.
	 * @return string|null Stripped bytes, or null when the structure is invalid.
	 */
	public static function png( string $bytes ): ?string {
		$sig = "\x89PNG\r\n\x1a\n";
		if ( 0 !== strpos( $bytes, $sig ) ) {
			return null;
		}

		$out      = $sig;
		$pos      = 8;
		$len      = strlen( $bytes );
		$seen_end = false;
		while ( $pos + 12 <= $len ) {
			$chunk_len = unpack( 'N', substr( $bytes, $pos, 4 ) )[1];
			$type      = substr( $bytes, $pos + 4, 4 );
			if ( $chunk_len > $len || $pos + 12 + $chunk_len > $len || ! preg_match( '/^[A-Za-z]{4}$/', $type ) ) {
				return null;
			}
			$data = substr( $bytes, $pos + 8, $chunk_len );
			$crc  = unpack( 'N', substr( $bytes, $pos + 8 + $chunk_len, 4 ) )[1];
			if ( ( crc32( $type . $data ) & 0xFFFFFFFF ) !== $crc ) {
				return null;
			}
			if ( in_array( $type, self::PNG_KEEP, true ) ) {
				$out .= substr( $bytes, $pos, 12 + $chunk_len );
			}
			$pos += 12 + $chunk_len;
			if ( 'IEND' === $type ) {
				$seen_end = true;
				break;
			}
		}

		return $seen_end ? $out : null;
	}
}

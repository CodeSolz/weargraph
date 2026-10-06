<?php
/**
 * ULID generator for public ids.
 *
 * @package WearGraph
 */

namespace WearGraph\Support;

defined( 'ABSPATH' ) || exit;

/**
 * 26-character, time-sortable, unguessable ids (48-bit ms timestamp + 80 random bits,
 * Crockford base32). Used wherever an id reaches a browser; sequential ids never do.
 */
final class Ulid {

	private const ALPHABET = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';

	/**
	 * New ULID.
	 */
	public static function generate(): string {
		$time = (int) floor( microtime( true ) * 1000 );
		$out  = '';

		for ( $i = 9; $i >= 0; $i-- ) {
			$out  = self::ALPHABET[ $time % 32 ] . $out;
			$time = intdiv( $time, 32 );
		}

		$random = random_bytes( 16 );
		for ( $i = 0; $i < 16; $i++ ) {
			$out .= self::ALPHABET[ ord( $random[ $i ] ) % 32 ];
		}

		return $out;
	}

	/**
	 * Whether a string looks like a ULID.
	 *
	 * @param string $value Candidate.
	 */
	public static function is_valid( string $value ): bool {
		return 1 === preg_match( '/^[0-9A-HJKMNP-TV-Z]{26}$/', $value );
	}
}

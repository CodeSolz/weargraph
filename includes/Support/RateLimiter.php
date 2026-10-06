<?php
/**
 * Fixed-window rate limiting (blueprint §45).
 *
 * @package WearGraph
 */

namespace WearGraph\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Counters live in the object cache when a persistent one exists, otherwise in
 * transients. Keys are hashed so no IP address is stored in clear.
 */
final class RateLimiter {

	/**
	 * Count one hit; returns false when the limit for the window is already reached.
	 *
	 * @param string $bucket Bucket name, e.g. "tryon-ip".
	 * @param string $key    Identity within the bucket (hashed before storage).
	 * @param int    $limit  Hits allowed per window.
	 * @param int    $window Window length in seconds.
	 */
	public static function hit( string $bucket, string $key, int $limit, int $window ): bool {
		if ( $limit <= 0 ) {
			return true;
		}

		$slot  = (int) floor( time() / max( 1, $window ) );
		$name  = 'weargraph_rl_' . substr( hash( 'sha256', $bucket . '|' . $key . '|' . $slot ), 0, 32 );
		$count = (int) self::get( $name );

		if ( $count >= $limit ) {
			return false;
		}

		self::set( $name, $count + 1, $window );

		return true;
	}

	/**
	 * Read a counter.
	 *
	 * @param string $name Counter name.
	 * @return int|false
	 */
	private static function get( string $name ) {
		return wp_using_ext_object_cache() ? wp_cache_get( $name, 'weargraph' ) : get_transient( $name );
	}

	/**
	 * Write a counter.
	 *
	 * @param string $name  Counter name.
	 * @param int    $value Value.
	 * @param int    $ttl   Seconds.
	 */
	private static function set( string $name, int $value, int $ttl ): void {
		if ( wp_using_ext_object_cache() ) {
			wp_cache_set( $name, $value, 'weargraph', $ttl );
		} else {
			set_transient( $name, $value, $ttl );
		}
	}

	/**
	 * Keyed hash of the client IP (never stored raw).
	 */
	public static function ip_hash(): string {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';

		return Crypto::sign( $ip, 'ip' );
	}
}

<?php
/**
 * Debug logger that can never write a credential or image payload.
 *
 * @package WearGraph
 */

namespace WearGraph\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Writes to the PHP error log only when WP_DEBUG is on. Context arrays are scrubbed
 * at any depth before encoding.
 */
final class Logger {

	/**
	 * Keys whose values are always redacted (matched case-insensitively as substrings).
	 */
	private const SENSITIVE = array( 'key', 'token', 'secret', 'authorization', 'password', 'image', 'photo', 'base64', 'inline_data', 'cookie' );

	/**
	 * Log a message when debugging is enabled.
	 *
	 * @param string              $message Message.
	 * @param array<string,mixed> $context Extra data; scrubbed before writing.
	 * @param string              $level   Level label.
	 */
	public static function log( string $message, array $context = array(), string $level = 'debug' ): void {
		if ( ! defined( 'WP_DEBUG' ) || ! WP_DEBUG ) {
			return;
		}

		$line = sprintf( '[WearGraph][%s] %s', $level, $message );
		if ( $context ) {
			$line .= ' ' . wp_json_encode( self::scrub( $context ) );
		}

		error_log( $line ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- gated on WP_DEBUG.
	}

	/**
	 * Log an error.
	 *
	 * @param string              $message Message.
	 * @param array<string,mixed> $context Extra data.
	 */
	public static function error( string $message, array $context = array() ): void {
		self::log( $message, $context, 'error' );
	}

	/**
	 * Replace sensitive values recursively.
	 *
	 * @param array<mixed> $data Data to scrub.
	 * @return array<mixed>
	 */
	public static function scrub( array $data ): array {
		foreach ( $data as $k => $v ) {
			if ( is_string( $k ) && self::is_sensitive( $k ) ) {
				$data[ $k ] = '[redacted]';
			} elseif ( is_array( $v ) ) {
				$data[ $k ] = self::scrub( $v );
			} elseif ( is_string( $v ) && strlen( $v ) > 2000 ) {
				$data[ $k ] = '[truncated ' . strlen( $v ) . ' bytes]';
			}
		}

		return $data;
	}

	/**
	 * Whether a key name looks sensitive.
	 *
	 * @param string $key Array key.
	 */
	private static function is_sensitive( string $key ): bool {
		$key = strtolower( $key );
		foreach ( self::SENSITIVE as $needle ) {
			if ( false !== strpos( $key, $needle ) ) {
				return true;
			}
		}

		return false;
	}
}

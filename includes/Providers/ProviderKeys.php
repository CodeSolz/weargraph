<?php
/**
 * Encrypted storage for provider API keys.
 *
 * @package WearGraph
 */

namespace WearGraph\Providers;

defined( 'ABSPATH' ) || exit;

use WearGraph\Support\Crypto;
use WearGraph\Support\Settings;

/**
 * Keys are stored encrypted in settings['provider_keys'][ provider_id ]. The plain
 * key is only ever decrypted server-side at the moment of an API call; UI code may
 * use masked() and has() but must never print get().
 */
final class ProviderKeys {

	/**
	 * Decrypted key, or '' when none.
	 *
	 * @param string $provider_id Provider id.
	 */
	public static function get( string $provider_id ): string {
		$keys = (array) Settings::get( 'provider_keys', array() );

		return isset( $keys[ $provider_id ] ) ? Crypto::decrypt( (string) $keys[ $provider_id ] ) : '';
	}

	/**
	 * Whether a usable key is stored.
	 *
	 * @param string $provider_id Provider id.
	 */
	public static function has( string $provider_id ): bool {
		return '' !== self::get( $provider_id );
	}

	/**
	 * "••••last4" for display, or '' when none.
	 *
	 * @param string $provider_id Provider id.
	 */
	public static function masked( string $provider_id ): string {
		return Crypto::mask( self::get( $provider_id ) );
	}

	/**
	 * Store (encrypt) a key. Whitespace is trimmed; an empty key removes it.
	 *
	 * @param string $provider_id Provider id.
	 * @param string $key         Plain key.
	 */
	public static function set( string $provider_id, string $key ): void {
		$key = trim( $key );
		if ( '' === $key ) {
			self::remove( $provider_id );
			return;
		}

		$keys                 = (array) Settings::get( 'provider_keys', array() );
		$keys[ $provider_id ] = Crypto::encrypt( $key );
		Settings::set( 'provider_keys', $keys );
	}

	/**
	 * Delete a stored key.
	 *
	 * @param string $provider_id Provider id.
	 */
	public static function remove( string $provider_id ): void {
		$keys = (array) Settings::get( 'provider_keys', array() );
		unset( $keys[ $provider_id ] );
		Settings::set( 'provider_keys', $keys );
	}
}

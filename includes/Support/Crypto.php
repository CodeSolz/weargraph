<?php
/**
 * Authenticated encryption for stored credentials (provider API keys).
 *
 * @package WearGraph
 */

namespace WearGraph\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Libsodium secretbox (XSalsa20-Poly1305). The key is derived from WordPress's auth
 * salt plus a random per-install secret kept in its own option, so a leaked options
 * export alone cannot decrypt, and a copied database on a host with different salts
 * cannot either. Not a defence against full server compromise — WordPress has no
 * better secret store — but it keeps keys out of plain text, backups and exports.
 */
final class Crypto {

	private const PREFIX         = 'wg1:';
	private const INSTALL_OPTION = 'weargraph_install_secret';

	/**
	 * Key override used by the test harness only.
	 *
	 * @var string|null
	 */
	private static $key_override = null;

	/**
	 * Encrypt a string. Empty input yields an empty string.
	 *
	 * @param string $plaintext Secret to store.
	 */
	public static function encrypt( string $plaintext ): string {
		if ( '' === $plaintext ) {
			return '';
		}

		$nonce  = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$cipher = sodium_crypto_secretbox( $plaintext, $nonce, self::key() );

		return self::PREFIX . base64_encode( $nonce . $cipher ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- storage encoding, not obfuscation.
	}

	/**
	 * Decrypt a value produced by encrypt(). Returns '' when it cannot be decrypted
	 * (tampered, wrong key, or not ours) — callers treat that as "no key configured".
	 *
	 * @param string $encoded Stored value.
	 */
	public static function decrypt( string $encoded ): string {
		if ( 0 !== strpos( $encoded, self::PREFIX ) ) {
			return '';
		}

		$raw = base64_decode( substr( $encoded, strlen( self::PREFIX ) ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- storage encoding.
		if ( false === $raw || strlen( $raw ) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES + SODIUM_CRYPTO_SECRETBOX_MACBYTES ) {
			return '';
		}

		$nonce  = substr( $raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$cipher = substr( $raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$plain  = sodium_crypto_secretbox_open( $cipher, $nonce, self::key() );

		return false === $plain ? '' : $plain;
	}

	/**
	 * Show only the last four characters of a secret.
	 *
	 * @param string $secret Plain secret.
	 */
	public static function mask( string $secret ): string {
		if ( '' === $secret ) {
			return '';
		}

		return str_repeat( '•', 4 ) . substr( $secret, -4 );
	}

	/**
	 * HMAC-SHA256 with a key derived from the same material, for signed tokens.
	 *
	 * @param string $data    Data to sign.
	 * @param string $context Domain separation label, e.g. "media" or "session".
	 */
	public static function sign( string $data, string $context ): string {
		return hash_hmac( 'sha256', $context . '|' . $data, self::key() );
	}

	/**
	 * Constant-time check of a signature from sign().
	 *
	 * @param string $data      Signed data.
	 * @param string $context   Same label passed to sign().
	 * @param string $signature Signature to check.
	 */
	public static function verify( string $data, string $context, string $signature ): bool {
		return hash_equals( self::sign( $data, $context ), $signature );
	}

	/**
	 * Test harness hook: pin a fixed key. Never called in production code.
	 *
	 * @param string|null $key 32-byte key, or null to restore normal derivation.
	 */
	public static function set_key_for_tests( ?string $key ): void {
		self::$key_override = $key;
	}

	/**
	 * Derive the 32-byte secretbox key.
	 */
	private static function key(): string {
		if ( null !== self::$key_override ) {
			return self::$key_override;
		}

		$salt = defined( 'AUTH_KEY' ) && AUTH_KEY ? AUTH_KEY : wp_salt( 'auth' );

		return sodium_crypto_generichash( $salt . '|' . self::install_secret(), '', SODIUM_CRYPTO_SECRETBOX_KEYBYTES );
	}

	/**
	 * Random per-install secret, created on first use.
	 */
	private static function install_secret(): string {
		$secret = get_option( self::INSTALL_OPTION, '' );

		if ( ! is_string( $secret ) || '' === $secret ) {
			$secret = bin2hex( random_bytes( 32 ) );
			add_option( self::INSTALL_OPTION, $secret, '', false );
		}

		return $secret;
	}
}

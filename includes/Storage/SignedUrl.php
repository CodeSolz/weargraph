<?php
/**
 * Short-lived signed URLs for private assets (blueprint §42).
 *
 * @package WearGraph
 */

namespace WearGraph\Storage;

defined( 'ABSPATH' ) || exit;

use WearGraph\Support\Crypto;

/**
 * Token = base64url( asset_public_id | expires | session ) . "." . HMAC. The session
 * part binds shopper assets to the browser session that created them (checked by
 * the media controller); it is empty for merchant (studio) assets.
 */
final class SignedUrl {

	public const TTL = 600;

	/**
	 * Build a signed media URL.
	 *
	 * @param string   $asset_public_id Asset ULID.
	 * @param string   $session_id      Owning session ('' for merchant assets).
	 * @param int|null $now             Current time (tests).
	 */
	public static function for_asset( string $asset_public_id, string $session_id = '', ?int $now = null ): string {
		return rest_url( 'weargraph/v1/media/' . self::token( $asset_public_id, $session_id, $now ) );
	}

	/**
	 * Build a token.
	 *
	 * @param string   $asset_public_id Asset ULID.
	 * @param string   $session_id      Owning session.
	 * @param int|null $now             Current time.
	 */
	public static function token( string $asset_public_id, string $session_id = '', ?int $now = null ): string {
		$expires = ( $now ?? time() ) + self::TTL;
		$payload = $asset_public_id . '|' . $expires . '|' . $session_id;

		return self::b64( $payload ) . '.' . Crypto::sign( $payload, 'media' );
	}

	/**
	 * Verify a token. Returns array( asset, session ) or null when invalid or expired.
	 *
	 * @param string   $token Token.
	 * @param int|null $now   Current time.
	 * @return array{asset:string,session:string}|null
	 */
	public static function verify( string $token, ?int $now = null ): ?array {
		$parts = explode( '.', $token );
		if ( 2 !== count( $parts ) ) {
			return null;
		}

		$payload = self::unb64( $parts[0] );
		if ( '' === $payload || ! Crypto::verify( $payload, 'media', $parts[1] ) ) {
			return null;
		}

		$fields = explode( '|', $payload );
		if ( 3 !== count( $fields ) || (int) $fields[1] < ( $now ?? time() ) ) {
			return null;
		}

		return array(
			'asset'   => $fields[0],
			'session' => $fields[2],
		);
	}

	/**
	 * URL-safe base64 without padding.
	 *
	 * @param string $data Data.
	 */
	private static function b64( string $data ): string {
		return rtrim( strtr( base64_encode( $data ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- URL token encoding.
	}

	/**
	 * Decode URL-safe base64.
	 *
	 * @param string $data Data.
	 */
	private static function unb64( string $data ): string {
		$decoded = base64_decode( strtr( $data, '-_', '+/' ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- URL token encoding.

		return false === $decoded ? '' : $decoded;
	}
}

<?php
/**
 * Idempotency key for generation requests (blueprint §27, to-do/07 §B4).
 *
 * @package WearGraph
 */

namespace WearGraph\Domain;

defined( 'ABSPATH' ) || exit;

/**
 * sha256( scope | subject_hash | target_id | look_graph_hash | mode | canonical_json(settings) ).
 * Identical on the WordPress and SaaS sides, so both can reuse each other's results.
 */
final class IdempotencyKey {

	/**
	 * Compute the key.
	 *
	 * @param string              $scope           Tenant/site (plus session for private shopper output).
	 * @param string              $subject_hash    Hash of the person photo ('' for studio work).
	 * @param string              $target_id       Product or variant id.
	 * @param string              $look_graph_hash Look graph hash ('' for single items).
	 * @param string              $mode            Provider mode (auto, economy…).
	 * @param array<string,mixed> $settings        Normalised request settings.
	 */
	public static function compute( string $scope, string $subject_hash, string $target_id, string $look_graph_hash, string $mode, array $settings ): string {
		return hash(
			'sha256',
			implode( '|', array( $scope, $subject_hash, $target_id, $look_graph_hash, $mode, self::canonical_json( $settings ) ) )
		);
	}

	/**
	 * JSON with recursively sorted object keys and no whitespace. Lists keep their order.
	 *
	 * @param mixed $value Value.
	 */
	public static function canonical_json( $value ): string {
		return (string) json_encode( self::canonicalize( $value ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- pure domain code; must match the SaaS byte-for-byte.
	}

	/**
	 * Sort associative arrays by key, recursively.
	 *
	 * @param mixed $value Value.
	 * @return mixed
	 */
	private static function canonicalize( $value ) {
		if ( ! is_array( $value ) ) {
			return $value;
		}

		$is_list = array_keys( $value ) === range( 0, count( $value ) - 1 );
		foreach ( $value as $k => $v ) {
			$value[ $k ] = self::canonicalize( $v );
		}
		if ( ! $is_list ) {
			ksort( $value, SORT_STRING );
		}

		return $value;
	}
}

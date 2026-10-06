<?php
/**
 * Analytics event names and properties (blueprint §46).
 *
 * @package WearGraph
 */

namespace WearGraph\Domain;

defined( 'ABSPATH' ) || exit;

/**
 * Pure. Turns a raw event into the row that is stored, or refuses it. Events never
 * carry raw personal data: free-form properties are limited to short scalar values,
 * and anything that looks like an email address or an IP address is dropped.
 */
final class EventSchema {

	/**
	 * Every event name the plugin (and Pro) may record.
	 */
	public const NAMES = array(
		'fitting_room_opened',
		'photo_uploaded',
		'photo_validated',
		'tryon_requested',
		'tryon_completed',
		'tryon_failed',
		'tryon_regenerated',
		'variant_changed',
		'look_item_added',
		'look_generated',
		'add_to_cart_after_tryon',
		'checkout_started_after_tryon',
		'purchase_after_tryon',
		'return_after_tryon',
	);

	/**
	 * Events a browser may report through the beacon; everything else is recorded
	 * on the server only, so it cannot be forged.
	 */
	public const CLIENT_NAMES = array( 'variant_changed' );

	private const ID_COLUMNS  = array( 'product_id', 'variant_id', 'generation_id', 'order_id' );
	private const ULID_FIELDS = array( 'session_id', 'anonymous_shopper_id', 'look_id' );
	private const MAX_PROPS   = 12;
	private const MAX_VALUE   = 64;

	/**
	 * Build the stored row.
	 *
	 * @param string              $name Event name.
	 * @param array<string,mixed> $data Base properties plus optional 'props'.
	 * @param int                 $now  Unix time.
	 * @return array<string,mixed>|null Row, or null if the event is not allowed.
	 */
	public static function normalize( string $name, array $data, int $now ): ?array {
		if ( ! self::is_name( $name ) ) {
			return null;
		}

		$row = array( 'name' => $name );
		foreach ( self::ULID_FIELDS as $field ) {
			$value         = strtoupper( (string) ( $data[ $field ] ?? '' ) );
			$row[ $field ] = preg_match( '/^[0-9A-HJKMNP-TV-Z]{26}$/', $value ) ? $value : null;
		}
		foreach ( self::ID_COLUMNS as $field ) {
			$value         = (int) ( $data[ $field ] ?? 0 );
			$row[ $field ] = $value > 0 ? $value : null;
		}

		$props = self::props( isset( $data['props'] ) && is_array( $data['props'] ) ? $data['props'] : array() );

		$row['props_json'] = $props ? (string) json_encode( $props ) : null; // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- pure domain class; values are scalars.
		$row['created_at'] = gmdate( 'Y-m-d H:i:s', $now );

		return $row;
	}

	/**
	 * Whether a name is part of the schema.
	 *
	 * @param string $name Name.
	 */
	public static function is_name( string $name ): bool {
		return in_array( $name, self::NAMES, true );
	}

	/**
	 * Whether a browser may report this event.
	 *
	 * @param string $name Name.
	 */
	public static function is_client_name( string $name ): bool {
		return in_array( $name, self::CLIENT_NAMES, true );
	}

	/**
	 * Keep short scalar properties with simple keys; drop anything that looks personal.
	 *
	 * @param array<mixed,mixed> $props Raw properties.
	 * @return array<string,int|float|bool|string>
	 */
	public static function props( array $props ): array {
		$clean = array();
		foreach ( $props as $key => $value ) {
			if ( count( $clean ) >= self::MAX_PROPS ) {
				break;
			}
			if ( ! is_string( $key ) || ! preg_match( '/^[a-z][a-z0-9_]{0,31}$/', $key ) ) {
				continue;
			}
			if ( is_bool( $value ) || is_int( $value ) ) {
				$clean[ $key ] = $value;
				continue;
			}
			if ( is_float( $value ) ) {
				$clean[ $key ] = round( $value, 4 );
				continue;
			}
			if ( ! is_string( $value ) ) {
				continue;
			}
			$value = trim( $value );
			if ( '' === $value || strlen( $value ) > self::MAX_VALUE || self::looks_personal( $value ) ) {
				continue;
			}
			$clean[ $key ] = $value;
		}

		return $clean;
	}

	/**
	 * Email or IP address.
	 *
	 * @param string $value Value.
	 */
	public static function looks_personal( string $value ): bool {
		if ( preg_match( '/[^\s@]+@[^\s@]+\.[^\s@]+/', $value ) ) {
			return true;
		}
		if ( preg_match( '/\b\d{1,3}(\.\d{1,3}){3}\b/', $value ) ) {
			return true;
		}

		return (bool) preg_match( '/\b[0-9a-f]{1,4}(:[0-9a-f]{0,4}){2,7}\b/i', $value );
	}
}

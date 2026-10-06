<?php
/**
 * Per-provider settings and cached model lists.
 *
 * @package WearGraph
 */

namespace WearGraph\Providers;

defined( 'ABSPATH' ) || exit;

use WearGraph\Support\Settings;

/**
 * Values live in settings['provider_options'][ provider ][ field ]; live model lists in
 * a transient per provider and field. A select accepts a value from its static choices
 * or from the cached live list.
 */
final class ProviderOptions {

	private const LIVE_TTL = 12 * HOUR_IN_SECONDS;

	/**
	 * One value.
	 *
	 * @param string $provider Provider id.
	 * @param string $field    Field name.
	 * @param mixed  $fallback Default.
	 * @return mixed
	 */
	public static function get( string $provider, string $field, $fallback = null ) {
		$all = (array) Settings::get( 'provider_options', array() );

		return $all[ $provider ][ $field ] ?? $fallback;
	}

	/**
	 * Store values for one provider.
	 *
	 * @param string              $provider Provider id.
	 * @param array<string,mixed> $values   Field => value.
	 */
	public static function set( string $provider, array $values ): void {
		$all              = (array) Settings::get( 'provider_options', array() );
		$all[ $provider ] = array_merge( (array) ( $all[ $provider ] ?? array() ), $values );
		Settings::set( 'provider_options', $all );
	}

	/**
	 * Values of a field set, defaults filled in.
	 *
	 * @param string                            $provider Provider id.
	 * @param array<string,array<string,mixed>> $fields   Field specs.
	 * @return array<string,mixed>
	 */
	public static function values( string $provider, array $fields ): array {
		$out = array();
		foreach ( $fields as $name => $spec ) {
			$out[ $name ] = self::get( $provider, (string) $name, $spec['default'] ?? '' );
		}

		return $out;
	}

	/**
	 * Validate and store posted values against field specs. Invalid values keep the
	 * current value.
	 *
	 * @param string                            $provider Provider id.
	 * @param array<string,array<string,mixed>> $fields   Field specs.
	 * @param array<string,string>              $posted   Posted values.
	 */
	public static function save( string $provider, array $fields, array $posted ): void {
		$clean = array();
		foreach ( $fields as $name => $spec ) {
			if ( ! array_key_exists( $name, $posted ) ) {
				continue;
			}
			$value = self::validate( $provider, (string) $name, $spec, (string) $posted[ $name ] );
			if ( null !== $value ) {
				$clean[ $name ] = $value;
			}
		}
		if ( $clean ) {
			self::set( $provider, $clean );
		}
	}

	/**
	 * One value checked against its spec, or null when invalid.
	 *
	 * @param string              $provider Provider id.
	 * @param string              $name     Field name.
	 * @param array<string,mixed> $spec     Field spec.
	 * @param string              $value    Posted value.
	 * @return mixed|null
	 */
	public static function validate( string $provider, string $name, array $spec, string $value ) {
		$value = trim( $value );
		switch ( $spec['type'] ?? 'text' ) {
			case 'select':
				return isset( self::choices( $provider, $name, $spec )[ $value ] ) ? $value : null;
			case 'number':
				if ( ! is_numeric( $value ) ) {
					return null;
				}
				$number = (float) $value;
				if ( ( isset( $spec['min'] ) && $number < (float) $spec['min'] ) || ( isset( $spec['max'] ) && $number > (float) $spec['max'] ) ) {
					return null;
				}
				return $number;
			default:
				return sanitize_text_field( $value );
		}
	}

	/**
	 * Choices of a select: static ones plus the cached live list.
	 *
	 * @param string              $provider Provider id.
	 * @param string              $name     Field name.
	 * @param array<string,mixed> $spec     Field spec.
	 * @return array<string,string>
	 */
	public static function choices( string $provider, string $name, array $spec ): array {
		$choices = (array) ( $spec['choices'] ?? array() );
		if ( ! empty( $spec['live'] ) ) {
			$choices += self::live( $provider, $name );
		}

		return $choices;
	}

	/**
	 * Cached live model list ('' → none yet).
	 *
	 * @param string $provider Provider id.
	 * @param string $field    Field name.
	 * @return array<string,string>
	 */
	public static function live( string $provider, string $field ): array {
		$cached = get_transient( self::live_key( $provider, $field ) );

		return is_array( $cached ) ? $cached : array();
	}

	/**
	 * Cache a live model list.
	 *
	 * @param string               $provider Provider id.
	 * @param string               $field    Field name.
	 * @param array<string,string> $models   Id => label.
	 */
	public static function remember_live( string $provider, string $field, array $models ): void {
		set_transient( self::live_key( $provider, $field ), $models, self::LIVE_TTL );
	}

	/**
	 * Transient name.
	 *
	 * @param string $provider Provider id.
	 * @param string $field    Field name.
	 */
	private static function live_key( string $provider, string $field ): string {
		return 'weargraph_models_' . substr( md5( $provider . '|' . $field ), 0, 16 );
	}
}

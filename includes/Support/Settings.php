<?php
/**
 * Typed accessor for the single weargraph_settings option.
 *
 * @package WearGraph
 */

namespace WearGraph\Support;

defined( 'ABSPATH' ) || exit;

/**
 * All merchant settings live in one option. Missing keys fall back to defaults(),
 * so adding a setting never requires a migration.
 */
final class Settings {

	public const OPTION = 'weargraph_settings';

	/**
	 * Request-level cache of the stored option.
	 *
	 * @var array<string,mixed>|null
	 */
	private static $cache = null;

	/**
	 * Default values for every known setting.
	 *
	 * @return array<string,mixed>
	 */
	public static function defaults(): array {
		return array(
			// General.
			'delete_data_on_uninstall' => false,

			// AI provider.
			'provider'                 => 'gemini',
			'provider_keys'            => array(), // provider id => encrypted key (Crypto).
			'gemini_image_model'       => 'gemini-3.1-flash-image',
			'gemini_vision_model'      => 'gemini-3.5-flash-lite',
			'provider_tryon'           => '',      // '' = automatic (see ProviderRegistry::for_task).
			'provider_vision'          => '',      // '' = automatic.
			'provider_options'         => array(), // provider id => field => value (ProviderOptions).

			// Try-on storefront.
			'tryon_enabled'            => false,   // Store-wide switch; off until the merchant enables it.
			'preview_mode'             => false,   // Store managers see the button while it is off for shoppers.
			'button_label'             => 'try_on',
			'button_position'          => 'after_add_to_cart',
			'selector_fallback'        => false,
			'fallback_selector'        => '.single_add_to_cart_button',
			'archive_button'           => false,
			'ip_daily_limit'           => 30,

			// Cost and abuse limits.
			'max_cost_per_request_usd' => 0.50,
			'daily_cap_usd'            => 10.00,
			'session_tryon_quota'      => 10,
			'session_regen_quota'      => 3,

			// Privacy.
			'retention_mode'           => 'ephemeral',
			'consent_text'             => '',
			'event_retention_days'     => 90,
		);
	}

	/**
	 * Stored settings merged over defaults.
	 *
	 * @return array<string,mixed>
	 */
	public static function all(): array {
		if ( null === self::$cache ) {
			$stored      = get_option( self::OPTION, array() );
			self::$cache = array_merge( self::defaults(), is_array( $stored ) ? $stored : array() );
		}

		return self::$cache;
	}

	/**
	 * Read one setting.
	 *
	 * @param string $key           Setting key.
	 * @param mixed  $default_value Returned when the key is unknown.
	 * @return mixed
	 */
	public static function get( string $key, $default_value = null ) {
		$all = self::all();

		return array_key_exists( $key, $all ) ? $all[ $key ] : $default_value;
	}

	/**
	 * Write one setting.
	 *
	 * @param string $key   Setting key.
	 * @param mixed  $value Value (already sanitized by the caller).
	 */
	public static function set( string $key, $value ): void {
		self::update( array( $key => $value ) );
	}

	/**
	 * Merge several values and persist.
	 *
	 * @param array<string,mixed> $values Values (already sanitized by the caller).
	 */
	public static function update( array $values ): void {
		$stored = get_option( self::OPTION, array() );
		$stored = array_merge( is_array( $stored ) ? $stored : array(), $values );
		update_option( self::OPTION, $stored, false );
		self::$cache = null;
	}

	/**
	 * Drop the request cache (tests, or after an external update_option()).
	 */
	public static function flush_cache(): void {
		self::$cache = null;
	}
}

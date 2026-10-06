<?php
/**
 * Gemini model catalog: ids, capabilities and prices.
 *
 * @package WearGraph
 */

namespace WearGraph\Providers\Gemini;

defined( 'ABSPATH' ) || exit;

/**
 * Verified on 2026-09-23 against ai.google.dev/gemini-api/docs/models,
 * …/docs/image-generation and …/docs/pricing (see to-do/PROGRESS.md "Provider facts").
 * Prices are USD. Filterable so a site can adjust when Google changes them, without
 * waiting for a plugin release.
 */
final class GeminiModels {

	public const DEFAULT_IMAGE  = 'gemini-3.1-flash-image';
	public const DEFAULT_VISION = 'gemini-3.5-flash-lite';

	/**
	 * Rough token count Gemini bills per input image (used for estimates only).
	 */
	public const INPUT_IMAGE_TOKENS = 1290;

	/**
	 * The catalog.
	 *
	 * Image model keys: label, kind=image, sizes (supported), max_refs, input_per_m,
	 * output_per_m, per_image (size => USD), latency_ms.
	 * Vision model keys: label, kind=vision, input_per_m, output_per_m, latency_ms.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public static function all(): array {
		$models = array(
			'gemini-3.1-flash-image'      => array(
				'label'        => 'Gemini 3.1 Flash Image (Nano Banana 2) — balanced',
				'kind'         => 'image',
				'sizes'        => array( '512', '1K', '2K', '4K' ),
				'max_refs'     => 14,
				'input_per_m'  => 0.50,
				'output_per_m' => 60.00,
				'per_image'    => array(
					'512' => 0.067,
					'1K'  => 0.067,
					'2K'  => 0.101,
					'4K'  => 0.151,
				),
				'latency_ms'   => 15000,
			),
			'gemini-3.1-flash-lite-image' => array(
				'label'        => 'Gemini 3.1 Flash Lite Image (Nano Banana 2 Lite) — economy, 1K only',
				'kind'         => 'image',
				'sizes'        => array( '1K' ),
				'max_refs'     => 14,
				'input_per_m'  => 0.25,
				'output_per_m' => 30.00,
				'per_image'    => array( '1K' => 0.0336 ),
				'latency_ms'   => 8000,
			),
			'gemini-3-pro-image'          => array(
				'label'        => 'Gemini 3 Pro Image (Nano Banana Pro) — premium',
				'kind'         => 'image',
				'sizes'        => array( '1K', '2K', '4K' ),
				'max_refs'     => 11,
				'input_per_m'  => 2.00,
				'output_per_m' => 120.00,
				'per_image'    => array(
					'1K' => 0.134,
					'2K' => 0.134,
					'4K' => 0.24,
				),
				'latency_ms'   => 30000,
			),
			'gemini-3.5-flash-lite'       => array(
				'label'        => 'Gemini 3.5 Flash-Lite',
				'kind'         => 'vision',
				'input_per_m'  => 0.30,
				'output_per_m' => 2.50,
				'latency_ms'   => 3000,
			),
		);

		/**
		 * Adjust the Gemini model catalog (new models, price changes).
		 *
		 * @param array<string,array<string,mixed>> $models Catalog.
		 */
		return (array) apply_filters( 'weargraph_gemini_models', $models );
	}

	/**
	 * One model's entry, or null.
	 *
	 * @param string $id Model id.
	 * @return array<string,mixed>|null
	 */
	public static function get( string $id ): ?array {
		$all = self::all();

		return isset( $all[ $id ] ) && is_array( $all[ $id ] ) ? $all[ $id ] : null;
	}

	/**
	 * Models of one kind (image|vision), id => label.
	 *
	 * @param string $kind Kind.
	 * @return array<string,string>
	 */
	public static function options( string $kind ): array {
		$out = array();
		foreach ( self::all() as $id => $model ) {
			if ( ( $model['kind'] ?? '' ) === $kind ) {
				$out[ $id ] = (string) ( $model['label'] ?? $id );
			}
		}

		return $out;
	}

	/**
	 * Largest supported size not above the requested one (falls back to the smallest).
	 *
	 * @param string $model_id  Model id.
	 * @param string $requested Requested size bucket.
	 */
	public static function clamp_size( string $model_id, string $requested ): string {
		$order     = array( '512', '1K', '2K', '4K' );
		$supported = (array) ( self::get( $model_id )['sizes'] ?? array( '1K' ) );
		$wanted    = array_search( $requested, $order, true );
		$wanted    = false === $wanted ? 1 : $wanted;

		for ( $i = $wanted; $i >= 0; $i-- ) {
			if ( in_array( $order[ $i ], $supported, true ) ) {
				return $order[ $i ];
			}
		}

		return (string) reset( $supported );
	}

	/**
	 * Actual USD cost from reported token usage.
	 *
	 * @param string $model_id   Model id.
	 * @param int    $tokens_in  Prompt tokens.
	 * @param int    $tokens_out Output tokens (candidates + thinking).
	 */
	public static function cost_from_usage( string $model_id, int $tokens_in, int $tokens_out ): float {
		$model = self::get( $model_id );
		if ( ! $model ) {
			return 0.0;
		}

		return round( ( $tokens_in * (float) $model['input_per_m'] + $tokens_out * (float) $model['output_per_m'] ) / 1000000, 5 );
	}
}

<?php
/**
 * OpenRouter image models: curated defaults, supported parameters and price estimates.
 *
 * @package WearGraph
 */

namespace WearGraph\Providers\OpenRouter;

defined( 'ABSPATH' ) || exit;

/**
 * Pure. The curated list comes from https://openrouter.ai/api/v1/images/models (checked
 * 2026-09-26); "Refresh models" adds every image model that accepts reference images.
 * Estimates are for the cost guard only: the real cost comes back in usage.cost.
 */
final class OpenRouterModels {

	public const DEFAULT_IMAGE  = 'google/gemini-3.1-flash-image';
	public const DEFAULT_VISION = 'google/gemini-3.5-flash-lite';

	/**
	 * id => label, params the model accepts, estimated USD per image at 1K.
	 */
	public const IMAGE = array(
		'google/gemini-3.1-flash-image'    => array(
			'label'  => 'Google Nano Banana 2 (Gemini 3.1 Flash Image)',
			'params' => array( 'aspect_ratio', 'input_references', 'n', 'resolution' ),
			'usd'    => 0.068,
		),
		'google/gemini-3-pro-image'        => array(
			'label'  => 'Google Nano Banana Pro (Gemini 3 Pro Image)',
			'params' => array( 'aspect_ratio', 'input_references', 'n', 'resolution' ),
			'usd'    => 0.136,
		),
		'openai/gpt-image-2'               => array(
			'label'  => 'OpenAI GPT Image 2',
			'params' => array( 'aspect_ratio', 'background', 'input_references', 'n', 'output_compression', 'quality' ),
			'usd'    => 0.05,
		),
		'black-forest-labs/flux.2-pro'     => array(
			'label'  => 'FLUX.2 [pro]',
			'params' => array( 'aspect_ratio', 'input_references', 'n', 'output_format', 'seed' ),
			'usd'    => 0.045,
		),
		'bytedance-seed/seedream-5-0-pro'  => array(
			'label'  => 'ByteDance Seedream 5 Pro',
			'params' => array( 'aspect_ratio', 'input_references', 'n', 'resolution', 'seed' ),
			'usd'    => 0.05,
		),
		'bytedance-seed/seedream-5-0-lite' => array(
			'label'  => 'ByteDance Seedream 5 Lite',
			'params' => array( 'aspect_ratio', 'input_references', 'n', 'resolution', 'seed' ),
			'usd'    => 0.035,
		),
		'qwen/qwen-image-3'                => array(
			'label'  => 'Qwen Image 3',
			'params' => array( 'aspect_ratio', 'input_references', 'n', 'resolution', 'seed' ),
			'usd'    => 0.035,
		),
		'x-ai/grok-imagine-image-2.0'      => array(
			'label'  => 'xAI Grok Imagine Image 2.0',
			'params' => array( 'aspect_ratio', 'input_references', 'n', 'quality', 'resolution' ),
			'usd'    => 0.04,
		),
	);

	/**
	 * Vision models (chat completions with image input).
	 */
	public const VISION = array(
		'google/gemini-3.5-flash-lite' => 'Google Gemini 3.5 Flash-Lite (recommended)',
		'openai/gpt-5.4-mini'          => 'OpenAI GPT-5.4 mini',
		'openai/gpt-5.4-nano'          => 'OpenAI GPT-5.4 nano (lowest cost)',
	);

	/**
	 * Unknown models are assumed to cost this much (guard only).
	 */
	public const FALLBACK_USD = 0.08;

	/**
	 * Select choices.
	 *
	 * @return array<string,string>
	 */
	public static function image_options(): array {
		return array_map(
			static function ( $m ) {
				return $m['label'];
			},
			self::IMAGE
		);
	}

	/**
	 * Params a model accepts: curated list, else what the live listing said, else the
	 * safe minimum.
	 *
	 * @param string               $model Model id.
	 * @param array<string,array>  $live  Live params by model id.
	 * @return string[]
	 */
	public static function params( string $model, array $live ): array {
		if ( isset( self::IMAGE[ $model ] ) ) {
			return self::IMAGE[ $model ]['params'];
		}

		return isset( $live[ $model ] ) ? (array) $live[ $model ] : array( 'aspect_ratio', 'input_references' );
	}

	/**
	 * Estimated USD for one image.
	 *
	 * @param string $model Model id.
	 * @param string $size  Size bucket.
	 */
	public static function estimate( string $model, string $size ): float {
		$base   = (float) ( self::IMAGE[ $model ]['usd'] ?? self::FALLBACK_USD );
		$factor = array(
			'512' => 0.8,
			'1K'  => 1.0,
			'2K'  => 1.5,
			'4K'  => 2.2,
		);

		return round( $base * ( $factor[ $size ] ?? 1.0 ), 4 );
	}
}

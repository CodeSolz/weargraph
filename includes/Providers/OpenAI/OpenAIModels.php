<?php
/**
 * OpenAI image and vision models, prices and sizes.
 *
 * @package WearGraph
 */

namespace WearGraph\Providers\OpenAI;

defined( 'ABSPATH' ) || exit;

use WearGraph\Domain\ImageOptions;
use WearGraph\Providers\ImageSizing;

/**
 * Pure. Prices in USD per million tokens from https://developers.openai.com/api/docs/pricing
 * (checked 2026-09-26). Image output is metered in tokens: a 1024×1024 image is about 272 /
 * 1,056 / 4,160 tokens at low / medium / high quality, scaling with the pixel count.
 */
final class OpenAIModels {

	public const DEFAULT_IMAGE  = 'gpt-image-2';
	public const DEFAULT_VISION = 'gpt-5.4-mini';

	/**
	 * Image models. flexible = any WIDTHxHEIGHT (multiples of 16); otherwise the three
	 * fixed sizes. fidelity = accepts input_fidelity.
	 */
	public const IMAGE = array(
		'gpt-image-2'            => array(
			'label'     => 'GPT Image 2 (recommended)',
			'text_in'   => 5.0,
			'image_in'  => 8.0,
			'image_out' => 30.0,
			'flexible'  => true,
			'fidelity'  => false,
			'qualities' => array( 'low', 'medium', 'high' ),
		),
		'gpt-image-2.5-flare'    => array(
			'label'     => 'GPT Image 2.5 Flare',
			'text_in'   => 5.0,
			'image_in'  => 8.0,
			'image_out' => 30.0,
			'flexible'  => true,
			'fidelity'  => true,
			'qualities' => array( 'low', 'medium', 'high', 'xhigh', 'max' ),
		),
		'gpt-image-2.5-sunburst' => array(
			'label'     => 'GPT Image 2.5 Sunburst',
			'text_in'   => 5.0,
			'image_in'  => 8.0,
			'image_out' => 30.0,
			'flexible'  => true,
			'fidelity'  => true,
			'qualities' => array( 'low', 'medium', 'high', 'xhigh', 'max' ),
		),
		'gpt-image-1.5'          => array(
			'label'     => 'GPT Image 1.5',
			'text_in'   => 5.0,
			'image_in'  => 8.0,
			'image_out' => 32.0,
			'flexible'  => false,
			'fidelity'  => true,
			'qualities' => array( 'low', 'medium', 'high' ),
		),
		'gpt-image-1-mini'       => array(
			'label'     => 'GPT Image 1 Mini (lowest cost)',
			'text_in'   => 2.0,
			'image_in'  => 2.5,
			'image_out' => 8.0,
			'flexible'  => false,
			'fidelity'  => true,
			'qualities' => array( 'low', 'medium', 'high' ),
		),
	);

	/**
	 * Vision models (alt text, classification, quality check).
	 */
	public const VISION = array(
		'gpt-5.4-mini' => array(
			'label' => 'GPT-5.4 mini (recommended)',
			'in'    => 0.75,
			'out'   => 4.5,
		),
		'gpt-5.4-nano' => array(
			'label' => 'GPT-5.4 nano (lowest cost)',
			'in'    => 0.2,
			'out'   => 1.25,
		),
	);

	/**
	 * Output tokens of a 1024×1024 image by quality.
	 */
	private const TOKENS_PER_MP = array(
		'low'    => 272,
		'medium' => 1056,
		'high'   => 4160,
		'xhigh'  => 6240,
		'max'    => 8320,
	);

	/**
	 * Tokens an input image counts for (estimate).
	 */
	public const INPUT_IMAGE_TOKENS = 1100;

	/**
	 * Fixed sizes of the older models.
	 */
	public const FIXED_SIZES = array( '1024x1024', '1536x1024', '1024x1536' );

	/**
	 * Select choices.
	 *
	 * @param string $kind image or vision.
	 * @return array<string,string>
	 */
	public static function options( string $kind ): array {
		$out = array();
		foreach ( 'vision' === $kind ? self::VISION : self::IMAGE as $id => $model ) {
			$out[ $id ] = $model['label'];
		}

		return $out;
	}

	/**
	 * Quality choices.
	 *
	 * @return array<string,string>
	 */
	public static function quality_options(): array {
		return array(
			'low'    => __( 'Low (fastest, cheapest)', 'weargraph' ),
			'medium' => __( 'Medium (recommended)', 'weargraph' ),
			'high'   => __( 'High', 'weargraph' ),
			'xhigh'  => __( 'Extra high (GPT Image 2.5 only)', 'weargraph' ),
			'max'    => __( 'Maximum (GPT Image 2.5 only)', 'weargraph' ),
		);
	}

	/**
	 * Model data, with the default model's prices for unknown (live-listed) ids.
	 *
	 * @param string $model Model id.
	 * @return array<string,mixed>
	 */
	public static function image( string $model ): array {
		return self::IMAGE[ $model ] ?? array_merge( self::IMAGE[ self::DEFAULT_IMAGE ], array( 'label' => $model ) );
	}

	/**
	 * Quality the model supports (high when asked for more than it has).
	 *
	 * @param string $model   Model id.
	 * @param string $quality Requested quality.
	 */
	public static function quality( string $model, string $quality ): string {
		$supported = (array) self::image( $model )['qualities'];

		return in_array( $quality, $supported, true ) ? $quality : ( in_array( $quality, array( 'xhigh', 'max' ), true ) ? 'high' : 'medium' );
	}

	/**
	 * The size parameter for a request.
	 *
	 * @param string       $model   Model id.
	 * @param ImageOptions $options Output shape.
	 */
	public static function size( string $model, ImageOptions $options ): string {
		if ( ! empty( self::image( $model )['flexible'] ) ) {
			// Up to 2560×1440-equivalent pixels: larger outputs are experimental on OpenAI's side.
			list( $w, $h ) = ImageSizing::dimensions( $options, 16, 2560, 3686400, 512 );

			return $w . 'x' . $h;
		}

		return ImageSizing::nearest( $options, self::FIXED_SIZES );
	}

	/**
	 * Estimated USD for one image.
	 *
	 * @param string $model         Model id.
	 * @param string $size          WIDTHxHEIGHT.
	 * @param string $quality       Quality.
	 * @param int    $input_images  Input images sent.
	 */
	public static function estimate( string $model, string $size, string $quality, int $input_images ): float {
		$m      = self::image( $model );
		$pixels = preg_match( '/^(\d+)x(\d+)$/', $size, $px ) ? (int) $px[1] * (int) $px[2] : 1048576;
		$out    = ( self::TOKENS_PER_MP[ self::quality( $model, $quality ) ] ?? 1056 ) * $pixels / 1048576;
		$in     = $input_images * self::INPUT_IMAGE_TOKENS * (float) $m['image_in'] + 300 * (float) $m['text_in'];

		return round( ( $out * (float) $m['image_out'] + $in ) / 1000000, 5 );
	}

	/**
	 * Actual USD from a response's usage block.
	 *
	 * @param string              $model Model id.
	 * @param array<string,mixed> $usage usage object.
	 */
	public static function cost_from_usage( string $model, array $usage ): ?float {
		if ( ! isset( $usage['output_tokens'] ) ) {
			return null;
		}
		$m        = self::image( $model );
		$image_in = (int) ( $usage['input_tokens_details']['image_tokens'] ?? 0 );
		$text_in  = (int) ( $usage['input_tokens_details']['text_tokens'] ?? max( 0, (int) ( $usage['input_tokens'] ?? 0 ) - $image_in ) );
		$out      = (int) $usage['output_tokens'];
		$usd      = $image_in * (float) $m['image_in'] + $text_in * (float) $m['text_in'] + $out * (float) $m['image_out'];

		return round( $usd / 1000000, 5 );
	}

	/**
	 * Actual USD of a vision call.
	 *
	 * @param string              $model Model id.
	 * @param array<string,mixed> $usage Chat usage (prompt_tokens, completion_tokens).
	 */
	public static function vision_cost( string $model, array $usage ): float {
		$m = self::VISION[ $model ] ?? self::VISION[ self::DEFAULT_VISION ];

		return round( ( (int) ( $usage['prompt_tokens'] ?? 0 ) * (float) $m['in'] + (int) ( $usage['completion_tokens'] ?? 0 ) * (float) $m['out'] ) / 1000000, 6 );
	}
}

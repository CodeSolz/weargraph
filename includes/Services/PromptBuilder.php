<?php
/**
 * Builds studio prompts from structured choices.
 *
 * @package WearGraph
 */

namespace WearGraph\Services;

defined( 'ABSPATH' ) || exit;

/**
 * Deterministic: the same choices always give the same prompt (which keeps the
 * idempotency cache effective). Merchants pick presets; the wording is ours.
 */
final class PromptBuilder {

	public const VERSION = 'v1';

	public const MODE_PRODUCT  = 'product';
	public const MODE_ON_MODEL = 'on_model';

	/**
	 * Normalise raw studio form input.
	 *
	 * @param array<string,mixed> $raw Form input.
	 * @return array{mode:string,scene:string,background:string,style:string,model:string,custom:string,negative:string}
	 */
	public static function normalize( array $raw ): array {
		$pick = static function ( string $group, $value, string $fallback ): string {
			$value = (string) $value;
			return isset( PresetLibrary::options( $group )[ $value ] ) ? $value : $fallback;
		};

		$choices = array(
			'mode'       => self::MODE_ON_MODEL === ( $raw['mode'] ?? '' ) ? self::MODE_ON_MODEL : self::MODE_PRODUCT,
			'scene'      => $pick( 'scene', $raw['scene'] ?? '', 'none' ),
			'background' => $pick( 'background', $raw['background'] ?? '', 'white' ),
			'style'      => $pick( 'style', $raw['style'] ?? '', 'catalog' ),
			'model'      => $pick( 'model', $raw['model'] ?? '', 'any' ),
			'custom'     => mb_substr( trim( wp_strip_all_tags( (string) ( $raw['custom'] ?? '' ) ) ), 0, 1000 ),
			'negative'   => mb_substr( trim( wp_strip_all_tags( (string) ( $raw['negative'] ?? '' ) ) ), 0, 500 ),
		);

		/**
		 * Normalised studio choices; extensions add and validate their own keys from the raw
		 * request (e.g. Pro's custom prompt mode) and read them back in weargraph_prompt_context.
		 *
		 * @param array<string,string> $choices Normalised choices.
		 * @param array<string,mixed>  $raw     Raw request parameters.
		 */
		return (array) apply_filters( 'weargraph_studio_choices', $choices, $raw );
	}

	/**
	 * Build the prompt.
	 *
	 * @param array<string,string> $choices       Output of normalize().
	 * @param string               $product_title Product name.
	 * @param int                  $ref_count     Number of reference images sent.
	 */
	public static function build( array $choices, string $product_title, int $ref_count ): string {
		$product = '' !== trim( $product_title ) ? '"' . trim( wp_strip_all_tags( $product_title ) ) . '"' : 'the product';
		$views   = $ref_count > 1 ? 'the reference images (different views of the same product)' : 'the reference image';

		$parts = array(
			'intro'    => "Create a professional online-store photograph of {$product}, exactly as shown in {$views}.",
			'subject'  => self::MODE_ON_MODEL === $choices['mode']
				? 'Show it worn by ' . PresetLibrary::prompt( 'model', $choices['model'] ) . ', with the whole product clearly visible and a natural pose.'
				: 'Show the product on its own, well framed and fully visible.',
			'setting'  => 'none' !== $choices['scene']
				? 'Setting: ' . PresetLibrary::prompt( 'scene', $choices['scene'] ) . '.'
				: 'Setting: ' . PresetLibrary::prompt( 'background', $choices['background'] ) . '.',
			'style'    => 'Style: ' . PresetLibrary::prompt( 'style', $choices['style'] ) . '.',
			'fidelity' => 'Keep the product identical to the reference: the same shape, proportions, colour, pattern, fabric texture, logos, printed text and details. Do not add other products, text, watermarks or logos.',
		);

		if ( '' !== $choices['custom'] ) {
			$parts['custom'] = 'Additional direction: ' . $choices['custom'];
		}

		/**
		 * Adjust prompt parts before they are joined (Pro: Brand Kit rules, custom prompts).
		 *
		 * @param array<string,string> $parts         Named prompt parts, in order.
		 * @param array<string,string> $choices       Normalised studio choices.
		 * @param string               $product_title Product title.
		 */
		$parts = (array) apply_filters( 'weargraph_prompt_context', $parts, $choices, $product_title );

		return implode( ' ', array_filter( array_map( 'strval', $parts ) ) );
	}
}

<?php
/**
 * Studio image generation request.
 *
 * @package WearGraph
 */

namespace WearGraph\Domain;

defined( 'ABSPATH' ) || exit;

/**
 * A product image to generate from a prompt plus reference images.
 * The prompt is already built (Services\PromptBuilder); providers only transport it.
 */
final class ImageGenerationInput {

	/**
	 * Final positive prompt.
	 *
	 * @var string
	 */
	public $prompt;

	/**
	 * Things to avoid. Providers without a native field fold it into the prompt.
	 *
	 * @var string
	 */
	public $negative_prompt;

	/**
	 * Reference images (product angles, style references).
	 *
	 * @var ImageRef[]
	 */
	public $references;

	/**
	 * Output shape.
	 *
	 * @var ImageOptions
	 */
	public $options;

	/**
	 * Constructor.
	 *
	 * @param string       $prompt          Prompt (required).
	 * @param ImageRef[]   $references      Reference images.
	 * @param ImageOptions $options         Output shape.
	 * @param string       $negative_prompt Things to avoid.
	 * @throws \InvalidArgumentException On an empty prompt or a non-ImageRef reference.
	 */
	public function __construct( string $prompt, array $references, ImageOptions $options, string $negative_prompt = '' ) {
		if ( '' === trim( $prompt ) ) {
			throw new \InvalidArgumentException( 'Prompt is empty.' );
		}
		foreach ( $references as $ref ) {
			if ( ! $ref instanceof ImageRef ) {
				throw new \InvalidArgumentException( 'References must be ImageRef instances.' );
			}
		}

		$this->prompt          = trim( $prompt );
		$this->references      = array_values( $references );
		$this->options         = $options;
		$this->negative_prompt = trim( $negative_prompt );
	}
}

<?php
/**
 * Image understanding request (alt text, classification, quality scoring).
 *
 * @package WearGraph
 */

namespace WearGraph\Domain;

defined( 'ABSPATH' ) || exit;

/**
 * Images plus a question. When $json is true the provider must return a decoded
 * JSON object; otherwise the answer comes back under the "text" key.
 */
final class VisionInput {

	/**
	 * Images to look at.
	 *
	 * @var ImageRef[]
	 */
	public $images;

	/**
	 * Instruction / question.
	 *
	 * @var string
	 */
	public $prompt;

	/**
	 * Ask for a JSON object response.
	 *
	 * @var bool
	 */
	public $json;

	/**
	 * Constructor.
	 *
	 * @param ImageRef[] $images Images (at least one).
	 * @param string     $prompt Instruction.
	 * @param bool       $json   Request a JSON object.
	 * @throws \InvalidArgumentException On no images or an empty prompt.
	 */
	public function __construct( array $images, string $prompt, bool $json = false ) {
		if ( ! $images ) {
			throw new \InvalidArgumentException( 'At least one image is required.' );
		}
		foreach ( $images as $image ) {
			if ( ! $image instanceof ImageRef ) {
				throw new \InvalidArgumentException( 'Images must be ImageRef instances.' );
			}
		}
		if ( '' === trim( $prompt ) ) {
			throw new \InvalidArgumentException( 'Prompt is empty.' );
		}

		$this->images = array_values( $images );
		$this->prompt = trim( $prompt );
		$this->json   = $json;
	}
}

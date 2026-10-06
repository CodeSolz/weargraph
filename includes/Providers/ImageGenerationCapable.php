<?php
/**
 * Capability: generate an image from a prompt and references.
 *
 * @package WearGraph
 */

namespace WearGraph\Providers;

defined( 'ABSPATH' ) || exit;

use WearGraph\Domain\ImageGenerationInput;

/**
 * Providers that can generate product images.
 */
interface ImageGenerationCapable {

	/**
	 * Generate an image.
	 *
	 * @param ImageGenerationInput $input Request.
	 * @throws ProviderException On any provider failure.
	 */
	public function generate_image( ImageGenerationInput $input ): ProviderOutput;
}

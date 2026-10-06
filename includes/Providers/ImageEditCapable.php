<?php
/**
 * Capability: edit an existing image.
 *
 * @package WearGraph
 */

namespace WearGraph\Providers;

defined( 'ABSPATH' ) || exit;

use WearGraph\Domain\ImageEditInput;

/**
 * Providers that can edit images (clean-up, recolour, background, ghost mannequin).
 */
interface ImageEditCapable {

	/**
	 * Edit an image.
	 *
	 * @param ImageEditInput $input Request.
	 * @throws ProviderException On any provider failure.
	 */
	public function edit_image( ImageEditInput $input ): ProviderOutput;
}

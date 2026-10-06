<?php
/**
 * Capability: understand images.
 *
 * @package WearGraph
 */

namespace WearGraph\Providers;

defined( 'ABSPATH' ) || exit;

use WearGraph\Domain\VisionInput;

/**
 * Providers that can describe or score images (alt text, classification, quality).
 */
interface VisionCapable {

	/**
	 * Analyse images. The answer is in ProviderOutput::$data (decoded JSON when
	 * VisionInput::$json is true, otherwise array( "text" => string )).
	 *
	 * @param VisionInput $input Request.
	 * @throws ProviderException On any provider failure.
	 */
	public function evaluate( VisionInput $input ): ProviderOutput;
}

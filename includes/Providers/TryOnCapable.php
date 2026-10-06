<?php
/**
 * Capability: virtual try-on.
 *
 * @package WearGraph
 */

namespace WearGraph\Providers;

defined( 'ABSPATH' ) || exit;

use WearGraph\Domain\TryOnInput;

/**
 * Providers that can dress a person photo in a garment.
 */
interface TryOnCapable {

	/**
	 * Whether (and how well) this provider can handle the request.
	 *
	 * @param TryOnInput $input Request.
	 */
	public function can_handle( TryOnInput $input ): ProviderCapability;

	/**
	 * Render the try-on.
	 *
	 * @param TryOnInput $input Request.
	 * @throws ProviderException On any provider failure.
	 */
	public function try_on( TryOnInput $input ): ProviderOutput;
}

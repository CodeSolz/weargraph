<?php
/**
 * Platform-neutral product image.
 *
 * @package WearGraph
 */

namespace WearGraph\Commerce;

defined( 'ABSPATH' ) || exit;

/**
 * A product image as the catalog reads it. `path` is the local file (never sent anywhere
 * except as image bytes to the merchant's chosen AI provider).
 */
final class ImageDTO {

	/**
	 * Platform id, e.g. "att:789".
	 *
	 * @var string
	 */
	public $id = '';

	/**
	 * Attachment id (WooCommerce).
	 *
	 * @var int
	 */
	public $attachment_id = 0;

	/**
	 * Public URL.
	 *
	 * @var string
	 */
	public $url = '';

	/**
	 * Local file path (never serialised).
	 *
	 * @var string
	 */
	public $path = '';

	/**
	 * Width in px (0 if unknown).
	 *
	 * @var int
	 */
	public $width = 0;

	/**
	 * Height in px (0 if unknown).
	 *
	 * @var int
	 */
	public $height = 0;

	/**
	 * MIME type.
	 *
	 * @var string
	 */
	public $mime = '';

	/**
	 * Alt text.
	 *
	 * @var string
	 */
	public $alt = '';

	/**
	 * Last-modified marker used for change detection.
	 *
	 * @var string
	 */
	public $modified = '';

	/**
	 * Contract form.
	 *
	 * @return array<string,mixed>
	 */
	public function to_array(): array {
		return array(
			'id'     => $this->id,
			'url'    => $this->url,
			'width'  => $this->width,
			'height' => $this->height,
		);
	}
}

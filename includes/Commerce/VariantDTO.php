<?php
/**
 * Platform-neutral product variant.
 *
 * @package WearGraph
 */

namespace WearGraph\Commerce;

defined( 'ABSPATH' ) || exit;

/**
 * One purchasable variation.
 */
final class VariantDTO {

	/**
	 * Platform id, e.g. "woo:123:456".
	 *
	 * @var string
	 */
	public $id = '';

	/**
	 * Variation post id.
	 *
	 * @var int
	 */
	public $variation_id = 0;

	/**
	 * Attribute name => selected value (human-readable).
	 *
	 * @var array<string,string>
	 */
	public $attributes = array();

	/**
	 * Variation's own image, if it has one different from the parent.
	 *
	 * @var ImageDTO|null
	 */
	public $image = null;

	/**
	 * In stock.
	 *
	 * @var bool
	 */
	public $in_stock = true;

	/**
	 * Price.
	 *
	 * @var string
	 */
	public $price = '';

	/**
	 * Contract form.
	 *
	 * @return array<string,mixed>
	 */
	public function to_array(): array {
		return array(
			'id'         => $this->id,
			'attributes' => $this->attributes,
			'image'      => $this->image ? $this->image->to_array() : null,
			'in_stock'   => $this->in_stock,
			'price'      => $this->price,
		);
	}
}

<?php
/**
 * Platform-neutral product.
 *
 * @package WearGraph
 */

namespace WearGraph\Commerce;

defined( 'ABSPATH' ) || exit;

/**
 * Everything the catalog intelligence needs, with no WooCommerce types, so the same
 * classifier and readiness logic can run for Shopify or in the SaaS.
 */
final class ProductDTO {

	/**
	 * Platform id, e.g. "woo:123".
	 *
	 * @var string
	 */
	public $id = '';

	/**
	 * Post id.
	 *
	 * @var int
	 */
	public $product_id = 0;

	/**
	 * simple | variable | external | …
	 *
	 * @var string
	 */
	public $type = '';

	/**
	 * Title.
	 *
	 * @var string
	 */
	public $title = '';

	/**
	 * Post status.
	 *
	 * @var string
	 */
	public $status = '';

	/**
	 * Category names (including ancestors).
	 *
	 * @var string[]
	 */
	public $categories = array();

	/**
	 * Tag names.
	 *
	 * @var string[]
	 */
	public $tags = array();

	/**
	 * Attribute label => list of values.
	 *
	 * @var array<string,string[]>
	 */
	public $attributes = array();

	/**
	 * Images: featured first, then gallery.
	 *
	 * @var ImageDTO[]
	 */
	public $images = array();

	/**
	 * Variants (variable products).
	 *
	 * @var VariantDTO[]
	 */
	public $variants = array();

	/**
	 * Price.
	 *
	 * @var string
	 */
	public $price = '';

	/**
	 * Currency.
	 *
	 * @var string
	 */
	public $currency = '';

	/**
	 * Last modified (UTC, ISO 8601).
	 *
	 * @var string
	 */
	public $updated_at = '';

	/**
	 * Hash of everything that affects classification and readiness.
	 *
	 * @var string
	 */
	public $content_hash = '';

	/**
	 * Compute content_hash from the fields that matter for scanning.
	 */
	public function compute_hash(): string {
		$images = array();
		foreach ( $this->images as $image ) {
			$images[] = $image->id . '@' . $image->modified;
		}
		$variants = array();
		foreach ( $this->variants as $variant ) {
			$variants[] = array( $variant->id, $variant->attributes, $variant->image ? $variant->image->id . '@' . $variant->image->modified : '' );
		}

		$this->content_hash = hash( 'sha256', (string) wp_json_encode( array( $this->title, $this->type, $this->categories, $this->tags, $this->attributes, $images, $variants ) ) );

		return $this->content_hash;
	}

	/**
	 * Contract form (07-API-CONTRACT §B3).
	 *
	 * @return array<string,mixed>
	 */
	public function to_array(): array {
		return array(
			'id'           => $this->id,
			'type'         => $this->type,
			'title'        => $this->title,
			'status'       => $this->status,
			'categories'   => $this->categories,
			'attributes'   => $this->attributes,
			'images'       => array_map(
				static function ( ImageDTO $i ) {
					return $i->to_array();
				},
				$this->images
			),
			'variants'     => array_map(
				static function ( VariantDTO $v ) {
					return $v->to_array();
				},
				$this->variants
			),
			'price'        => $this->price,
			'currency'     => $this->currency,
			'updated_at'   => $this->updated_at,
			'content_hash' => $this->content_hash,
		);
	}
}

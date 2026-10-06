<?php
/**
 * Result of classifying one product.
 *
 * @package WearGraph
 */

namespace WearGraph\Catalog;

defined( 'ABSPATH' ) || exit;

/**
 * Normalised garment metadata (blueprint §5). Values are machine keys; labels are
 * produced at display time.
 */
final class Classification {

	/**
	 * Category key from Keywords::categories(), or "other".
	 *
	 * @var string
	 */
	public $category = 'other';

	/**
	 * upper | lower | one_piece | outer | footwear | accessory | '' (unknown).
	 *
	 * @var string
	 */
	public $region = '';

	/**
	 * inner | outer | base | accessory | ''.
	 *
	 * @var string
	 */
	public $layer = '';

	/**
	 * men | women | kids | unisex | ''.
	 *
	 * @var string
	 */
	public $gender = '';

	/**
	 * Canonical colours found.
	 *
	 * @var string[]
	 */
	public $colors = array();

	/**
	 * solid (unknown) | striped | checked | floral | …
	 *
	 * @var string
	 */
	public $pattern = '';

	/**
	 * fashion_vto | accessory | none.
	 *
	 * @var string
	 */
	public $tryon_mode = 'none';

	/**
	 * 0–1.
	 *
	 * @var float
	 */
	public $confidence = 0.0;

	/**
	 * Why: list of "source:keyword" matches (debugging, never shown raw to shoppers).
	 *
	 * @var string[]
	 */
	public $evidence = array();

	/**
	 * Array form.
	 *
	 * @return array<string,mixed>
	 */
	public function to_array(): array {
		return array(
			'category'   => $this->category,
			'region'     => $this->region,
			'layer'      => $this->layer,
			'gender'     => $this->gender,
			'colors'     => $this->colors,
			'pattern'    => $this->pattern,
			'tryon_mode' => $this->tryon_mode,
			'confidence' => $this->confidence,
			'evidence'   => $this->evidence,
		);
	}
}

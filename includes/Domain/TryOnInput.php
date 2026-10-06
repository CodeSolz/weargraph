<?php
/**
 * Virtual try-on request.
 *
 * @package WearGraph
 */

namespace WearGraph\Domain;

defined( 'ABSPATH' ) || exit;

/**
 * One person photo plus one or more garments. The garment metadata comes from the
 * catalog classification so providers can tell the model which region to replace.
 */
final class TryOnInput {

	public const REGIONS = array( 'upper', 'lower', 'one_piece', 'outer', 'footwear', 'accessory' );

	/**
	 * Shopper (or sample-person) photo.
	 *
	 * @var ImageRef
	 */
	public $person;

	/**
	 * Garment images, in layer order (inner first).
	 *
	 * @var ImageRef[]
	 */
	public $garments;

	/**
	 * Garment region: upper, lower, one_piece, outer, footwear or accessory.
	 *
	 * @var string
	 */
	public $region;

	/**
	 * Human-readable category, e.g. "denim jacket".
	 *
	 * @var string
	 */
	public $category;

	/**
	 * Output shape.
	 *
	 * @var ImageOptions
	 */
	public $options;

	/**
	 * Constructor.
	 *
	 * @param ImageRef     $person   Person photo.
	 * @param ImageRef[]   $garments Garment images (at least one).
	 * @param string       $region   Garment region.
	 * @param string       $category Category label.
	 * @param ImageOptions $options  Output shape.
	 * @throws \InvalidArgumentException On a missing garment or unknown region.
	 */
	public function __construct( ImageRef $person, array $garments, string $region, string $category, ImageOptions $options ) {
		if ( ! $garments ) {
			throw new \InvalidArgumentException( 'At least one garment image is required.' );
		}
		foreach ( $garments as $garment ) {
			if ( ! $garment instanceof ImageRef ) {
				throw new \InvalidArgumentException( 'Garments must be ImageRef instances.' );
			}
		}
		if ( ! in_array( $region, self::REGIONS, true ) ) {
			throw new \InvalidArgumentException( 'Unknown garment region.' );
		}

		$this->person   = $person;
		$this->garments = array_values( $garments );
		$this->region   = $region;
		$this->category = trim( $category );
		$this->options  = $options;
	}
}

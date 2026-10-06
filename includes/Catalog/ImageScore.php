<?php
/**
 * How suitable one image is as a try-on garment source.
 *
 * @package WearGraph
 */

namespace WearGraph\Catalog;

defined( 'ABSPATH' ) || exit;

/**
 * All component scores are 0–1. `analysed` is false when no image library was
 * available and only size information could be used.
 */
final class ImageScore {

	public const STYLE_PRODUCT  = 'product_only';
	public const STYLE_ON_MODEL = 'on_model';
	public const STYLE_SCENE    = 'lifestyle';
	public const STYLE_UNKNOWN  = 'unknown';

	/**
	 * Overall 0–1.
	 *
	 * @var float
	 */
	public $total = 0.0;

	/**
	 * Resolution component.
	 *
	 * @var float
	 */
	public $resolution = 0.0;

	/**
	 * Aspect ratio component.
	 *
	 * @var float
	 */
	public $aspect = 0.0;

	/**
	 * Background cleanliness (null when not analysed).
	 *
	 * @var float|null
	 */
	public $background = null;

	/**
	 * Fraction of the frame the product covers (null when not analysed).
	 *
	 * @var float|null
	 */
	public $coverage = null;

	/**
	 * product_only | on_model | lifestyle | unknown.
	 *
	 * @var string
	 */
	public $style = self::STYLE_UNKNOWN;

	/**
	 * Perceptual hash (16 hex chars) or ''.
	 *
	 * @var string
	 */
	public $dhash = '';

	/**
	 * Pixel analysis ran.
	 *
	 * @var bool
	 */
	public $analysed = false;

	/**
	 * Width in px.
	 *
	 * @var int
	 */
	public $width = 0;

	/**
	 * Height in px.
	 *
	 * @var int
	 */
	public $height = 0;

	/**
	 * Problem codes: low_resolution, extreme_aspect, busy_background, product_too_small, on_model_image.
	 *
	 * @var string[]
	 */
	public $notes = array();

	/**
	 * Array form (stored in catalog_meta.image_scores_json).
	 *
	 * @return array<string,mixed>
	 */
	public function to_array(): array {
		return array(
			'total'      => round( $this->total, 3 ),
			'resolution' => round( $this->resolution, 3 ),
			'aspect'     => round( $this->aspect, 3 ),
			'background' => null === $this->background ? null : round( $this->background, 3 ),
			'coverage'   => null === $this->coverage ? null : round( $this->coverage, 3 ),
			'style'      => $this->style,
			'dhash'      => $this->dhash,
			'analysed'   => $this->analysed,
			'width'      => $this->width,
			'height'     => $this->height,
			'notes'      => $this->notes,
		);
	}

	/**
	 * Rebuild from to_array() output.
	 *
	 * @param array<string,mixed> $data Stored data.
	 */
	public static function from_array( array $data ): self {
		$score             = new self();
		$score->total      = (float) ( $data['total'] ?? 0 );
		$score->resolution = (float) ( $data['resolution'] ?? 0 );
		$score->aspect     = (float) ( $data['aspect'] ?? 0 );
		$score->background = isset( $data['background'] ) ? (float) $data['background'] : null;
		$score->coverage   = isset( $data['coverage'] ) ? (float) $data['coverage'] : null;
		$score->style      = (string) ( $data['style'] ?? self::STYLE_UNKNOWN );
		$score->dhash      = (string) ( $data['dhash'] ?? '' );
		$score->analysed   = ! empty( $data['analysed'] );
		$score->width      = (int) ( $data['width'] ?? 0 );
		$score->height     = (int) ( $data['height'] ?? 0 );
		$score->notes      = array_values( (array) ( $data['notes'] ?? array() ) );

		return $score;
	}
}

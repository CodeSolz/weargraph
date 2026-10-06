<?php
/**
 * Output shape shared by every image-producing request.
 *
 * @package WearGraph
 */

namespace WearGraph\Domain;

defined( 'ABSPATH' ) || exit;

/**
 * Aspect ratio and resolution. Providers downgrade what they cannot do and report
 * the size they actually produced.
 */
final class ImageOptions {

	public const ASPECT_RATIOS = array( '1:1', '2:3', '3:2', '3:4', '4:3', '4:5', '5:4', '9:16', '16:9', '21:9' );
	public const SIZES         = array( '512', '1K', '2K', '4K' );

	/**
	 * Aspect ratio, e.g. "4:5".
	 *
	 * @var string
	 */
	public $aspect_ratio;

	/**
	 * Resolution bucket: 512, 1K, 2K or 4K.
	 *
	 * @var string
	 */
	public $size;

	/**
	 * Constructor. Unknown values fall back to safe defaults rather than failing.
	 *
	 * @param string $aspect_ratio Aspect ratio.
	 * @param string $size         Resolution bucket.
	 */
	public function __construct( string $aspect_ratio = '4:5', string $size = '1K' ) {
		$this->aspect_ratio = in_array( $aspect_ratio, self::ASPECT_RATIOS, true ) ? $aspect_ratio : '4:5';
		$this->size         = in_array( $size, self::SIZES, true ) ? $size : '1K';
	}

	/**
	 * Stable array form (idempotency keys, logging).
	 *
	 * @return array{aspect_ratio:string,size:string}
	 */
	public function to_array(): array {
		return array(
			'aspect_ratio' => $this->aspect_ratio,
			'size'         => $this->size,
		);
	}
}

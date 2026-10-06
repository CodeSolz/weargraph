<?php
/**
 * Black Forest Labs FLUX models, limits and price estimates.
 *
 * @package WearGraph
 */

namespace WearGraph\Providers\Flux;

defined( 'ABSPATH' ) || exit;

use WearGraph\Domain\ImageOptions;
use WearGraph\Providers\ImageSizing;

/**
 * Pure. Endpoints and fields from https://api.bfl.ai/openapi.json; prices from
 * https://docs.bfl.ml/quick_start/pricing.md (checked 2026-09-26; 1 credit = $0.01,
 * FLUX.2 priced per megapixel). Estimates guard spending; the submit response carries
 * the real cost.
 */
final class FluxModels {

	public const DEFAULT_IMAGE = 'flux-2-pro';
	public const VTO_ENDPOINT  = 'flux-tools/vto-v2';

	/**
	 * Endpoint => label, reference images accepted, USD for the first output megapixel,
	 * per extra output megapixel and per input megapixel.
	 */
	public const IMAGE = array(
		'flux-2-pro'      => array(
			'label'  => 'FLUX.2 [pro] (recommended)',
			'refs'   => 8,
			'first'  => 0.03,
			'extra'  => 0.015,
			'per_in' => 0.015,
		),
		'flux-2-max'      => array(
			'label'  => 'FLUX.2 [max] (highest quality)',
			'refs'   => 8,
			'first'  => 0.07,
			'extra'  => 0.03,
			'per_in' => 0.03,
		),
		'flux-2-flex'     => array(
			'label'  => 'FLUX.2 [flex] (fine control, text)',
			'refs'   => 8,
			'first'  => 0.05,
			'extra'  => 0.05,
			'per_in' => 0.05,
		),
		'flux-2-klein-9b' => array(
			'label'  => 'FLUX.2 [klein] 9B (fast, low cost)',
			'refs'   => 4,
			'first'  => 0.015,
			'extra'  => 0.002,
			'per_in' => 0.0,
		),
		'flux-2-klein-4b' => array(
			'label'  => 'FLUX.2 [klein] 4B (fastest, lowest cost)',
			'refs'   => 4,
			'first'  => 0.014,
			'extra'  => 0.001,
			'per_in' => 0.0,
		),
	);

	/**
	 * Try-on estimate in USD (the dedicated endpoint; its price is not published yet).
	 */
	public const VTO_ESTIMATE = 0.03;

	/**
	 * Select choices.
	 *
	 * @return array<string,string>
	 */
	public static function options(): array {
		return array_map(
			static function ( $m ) {
				return $m['label'];
			},
			self::IMAGE
		);
	}

	/**
	 * Model data (default for unknown).
	 *
	 * @param string $model Endpoint.
	 * @return array<string,mixed>
	 */
	public static function get( string $model ): array {
		return self::IMAGE[ $model ] ?? self::IMAGE[ self::DEFAULT_IMAGE ];
	}

	/**
	 * Output width and height (multiples of 16, up to 4 megapixels).
	 *
	 * @param ImageOptions $options Output shape.
	 * @return array{0:int,1:int}
	 */
	public static function dimensions( ImageOptions $options ): array {
		return ImageSizing::dimensions( $options, 16, 2560, 4194304, 256 );
	}

	/**
	 * Estimated USD.
	 *
	 * @param string       $model      Endpoint.
	 * @param ImageOptions $options    Output shape.
	 * @param int          $input_imgs Reference images (about 1 MP each).
	 */
	public static function estimate( string $model, ImageOptions $options, int $input_imgs ): float {
		$m             = self::get( $model );
		list( $w, $h ) = self::dimensions( $options );
		$mp            = max( 1.0, ImageSizing::megapixels( $w, $h ) );

		return round( (float) $m['first'] + ( $mp - 1 ) * (float) $m['extra'] + $input_imgs * (float) $m['per_in'], 4 );
	}
}

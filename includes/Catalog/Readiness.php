<?php
/**
 * Readiness verdict for one product (blueprint §57).
 *
 * @package WearGraph
 */

namespace WearGraph\Catalog;

defined( 'ABSPATH' ) || exit;

/**
 * Score 0–100, a status, and problems as codes + parameters (translated at display).
 */
final class Readiness {

	public const READY        = 'ready';
	public const NEEDS_IMAGE  = 'needs_image';
	public const NEEDS_REVIEW = 'needs_review';
	public const UNSUPPORTED  = 'unsupported';
	public const UNSCANNED    = 'unscanned';

	/**
	 * 0–100.
	 *
	 * @var int
	 */
	public $score = 0;

	/**
	 * One of the constants.
	 *
	 * @var string
	 */
	public $status = self::UNSCANNED;

	/**
	 * Problems: list of array( 'code' => string, 'params' => array ).
	 *
	 * @var array<int,array{code:string,params:array<string,mixed>}>
	 */
	public $problems = array();

	/**
	 * Add a problem.
	 *
	 * @param string              $code   Code.
	 * @param array<string,mixed> $params Parameters.
	 */
	public function add( string $code, array $params = array() ): void {
		$this->problems[] = array(
			'code'   => $code,
			'params' => $params,
		);
	}

	/**
	 * Whether a problem code is present.
	 *
	 * @param string $code Code.
	 */
	public function has( string $code ): bool {
		return in_array( $code, array_column( $this->problems, 'code' ), true );
	}
}

<?php
/**
 * Answer to "can this provider handle this request?".
 *
 * @package WearGraph
 */

namespace WearGraph\Providers;

defined( 'ABSPATH' ) || exit;

/**
 * Used by the router (Pro P8) and the readiness scanner to pick or rule out a provider.
 */
final class ProviderCapability {

	/**
	 * Supported at all.
	 *
	 * @var bool
	 */
	public $supported;

	/**
	 * Expected fidelity 0–1 for this kind of request.
	 *
	 * @var float
	 */
	public $confidence;

	/**
	 * Why not / caveats (user-safe).
	 *
	 * @var string
	 */
	public $reason;

	/**
	 * Constructor.
	 *
	 * @param bool   $supported  Supported.
	 * @param float  $confidence 0–1.
	 * @param string $reason     Explanation.
	 */
	public function __construct( bool $supported, float $confidence = 1.0, string $reason = '' ) {
		$this->supported  = $supported;
		$this->confidence = max( 0.0, min( 1.0, $confidence ) );
		$this->reason     = $reason;
	}
}

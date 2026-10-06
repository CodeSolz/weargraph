<?php
/**
 * Result of a provider health check.
 *
 * @package WearGraph
 */

namespace WearGraph\Providers;

defined( 'ABSPATH' ) || exit;

/**
 * Whether the provider answered, how fast, and a user-safe message.
 */
final class ProviderHealth {

	/**
	 * Provider reachable and credential accepted.
	 *
	 * @var bool
	 */
	public $ok;

	/**
	 * Round-trip time.
	 *
	 * @var int
	 */
	public $latency_ms;

	/**
	 * Model that was checked.
	 *
	 * @var string
	 */
	public $model;

	/**
	 * Machine error code when not ok (ProviderException codes).
	 *
	 * @var string
	 */
	public $error_code;

	/**
	 * User-safe message.
	 *
	 * @var string
	 */
	public $message;

	/**
	 * Constructor.
	 *
	 * @param bool   $ok         Healthy.
	 * @param int    $latency_ms Round trip.
	 * @param string $model      Model checked.
	 * @param string $message    User-safe message.
	 * @param string $error_code Error code when not ok.
	 */
	public function __construct( bool $ok, int $latency_ms, string $model, string $message = '', string $error_code = '' ) {
		$this->ok         = $ok;
		$this->latency_ms = $latency_ms;
		$this->model      = $model;
		$this->message    = $message;
		$this->error_code = $error_code;
	}
}

<?php
/**
 * A generation request that was refused before it started.
 *
 * @package WearGraph
 */

namespace WearGraph\Generation;

defined( 'ABSPATH' ) || exit;

/**
 * Carries a machine code, an escaped user-safe message and the HTTP status a REST
 * controller should answer with.
 */
final class GenerationException extends \RuntimeException {

	/**
	 * Machine code.
	 *
	 * @var string
	 */
	private $error_code;

	/**
	 * HTTP status.
	 *
	 * @var int
	 */
	private $http_status;

	/**
	 * Constructor.
	 *
	 * @param string $error_code  Code (bad_input, not_configured, cost_limit, unknown_kind…).
	 * @param string $message     Escaped, user-safe message.
	 * @param int    $http_status HTTP status.
	 */
	public function __construct( string $error_code, string $message, int $http_status = 400 ) {
		parent::__construct( $message );
		$this->error_code  = $error_code;
		$this->http_status = $http_status;
	}

	/**
	 * Machine code.
	 */
	public function error_code(): string {
		return $this->error_code;
	}

	/**
	 * HTTP status.
	 */
	public function http_status(): int {
		return $this->http_status;
	}
}

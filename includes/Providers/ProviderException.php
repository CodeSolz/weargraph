<?php
/**
 * Normalised provider failure.
 *
 * @package WearGraph
 */

namespace WearGraph\Providers;

defined( 'ABSPATH' ) || exit;

/**
 * Every provider error becomes one of a fixed set of codes with a retryable flag.
 * getMessage() is always user-safe: it never contains a key, header, raw response
 * body or stack detail.
 */
final class ProviderException extends \RuntimeException {

	public const AUTH           = 'auth';
	public const QUOTA          = 'quota';
	public const RATE_LIMITED   = 'rate_limited';
	public const TIMEOUT        = 'timeout';
	public const SAFETY_BLOCKED = 'safety_blocked';
	public const BAD_INPUT      = 'bad_input';
	public const NO_OUTPUT      = 'no_output';
	public const NOT_CONFIGURED = 'not_configured';
	public const SERVER         = 'server';

	/**
	 * Machine code.
	 *
	 * @var string
	 */
	private $error_code;

	/**
	 * Worth retrying.
	 *
	 * @var bool
	 */
	private $retryable;

	/**
	 * Constructor.
	 *
	 * @param string $error_code One of the class constants.
	 * @param string $message    User-safe message.
	 * @param bool   $retryable  Whether a retry may succeed.
	 */
	public function __construct( string $error_code, string $message, bool $retryable = false ) {
		parent::__construct( $message );
		$this->error_code = $error_code;
		$this->retryable  = $retryable;
	}

	/**
	 * Credential missing for the provider.
	 *
	 * @param string $message User-safe, already-escaped message.
	 */
	public static function not_configured( string $message ): self {
		return new self( self::NOT_CONFIGURED, $message );
	}

	/**
	 * Input the provider cannot process.
	 *
	 * @param string $message User-safe, already-escaped message.
	 */
	public static function bad_input( string $message ): self {
		return new self( self::BAD_INPUT, $message );
	}

	/**
	 * Provider answered without usable output (retryable).
	 *
	 * @param string $message User-safe, already-escaped message.
	 */
	public static function no_output( string $message ): self {
		return new self( self::NO_OUTPUT, $message, true );
	}

	/**
	 * Machine code.
	 */
	public function error_code(): string {
		return $this->error_code;
	}

	/**
	 * Whether a retry may succeed.
	 */
	public function is_retryable(): bool {
		return $this->retryable;
	}
}

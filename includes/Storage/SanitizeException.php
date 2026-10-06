<?php
/**
 * An upload that failed validation.
 *
 * @package WearGraph
 */

namespace WearGraph\Storage;

defined( 'ABSPATH' ) || exit;

/**
 * The code is mapped to a shopper-facing message by the controller:
 * unreadable | too_large | too_small | bad_type | corrupt.
 */
final class SanitizeException extends \RuntimeException {

	/**
	 * Machine code.
	 *
	 * @var string
	 */
	private $reason;

	/**
	 * Constructor.
	 *
	 * @param string $reason Code.
	 */
	public function __construct( string $reason ) {
		parent::__construct( $reason );
		$this->reason = $reason;
	}

	/**
	 * Machine code.
	 */
	public function reason(): string {
		return $this->reason;
	}
}

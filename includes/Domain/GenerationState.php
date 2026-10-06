<?php
/**
 * Generation request state machine (blueprint §26).
 *
 * @package WearGraph
 */

namespace WearGraph\Domain;

defined( 'ABSPATH' ) || exit;

/**
 * Every generation moves through these states; illegal moves throw. Terminal
 * states never change again.
 */
final class GenerationState {

	public const CREATED           = 'CREATED';
	public const VALIDATING_INPUT  = 'VALIDATING_INPUT';
	public const PREPROCESSING     = 'PREPROCESSING';
	public const QUEUED            = 'QUEUED';
	public const ROUTING           = 'ROUTING';
	public const GENERATING        = 'GENERATING';
	public const QUALITY_CHECK     = 'QUALITY_CHECK';
	public const RETRYING          = 'RETRYING';
	public const FALLBACK_PROVIDER = 'FALLBACK_PROVIDER';
	public const READY             = 'READY';
	public const FAILED            = 'FAILED';
	public const CANCELLED         = 'CANCELLED';

	/**
	 * Legal transitions: from => allowed targets. FAILED and CANCELLED are reachable
	 * from every non-terminal state and are added in allowed().
	 */
	private const TRANSITIONS = array(
		self::CREATED           => array( self::VALIDATING_INPUT ),
		self::VALIDATING_INPUT  => array( self::PREPROCESSING ),
		self::PREPROCESSING     => array( self::QUEUED ),
		self::QUEUED            => array( self::ROUTING ),
		self::ROUTING           => array( self::GENERATING ),
		self::GENERATING        => array( self::QUALITY_CHECK, self::RETRYING ),
		self::QUALITY_CHECK     => array( self::READY, self::RETRYING, self::FALLBACK_PROVIDER ),
		self::RETRYING          => array( self::ROUTING, self::GENERATING, self::FALLBACK_PROVIDER ),
		self::FALLBACK_PROVIDER => array( self::ROUTING, self::GENERATING ),
		self::READY             => array(),
		self::FAILED            => array(),
		self::CANCELLED         => array(),
	);

	private const TERMINAL = array( self::READY, self::FAILED, self::CANCELLED );

	/**
	 * Every state.
	 *
	 * @return string[]
	 */
	public static function all(): array {
		return array_keys( self::TRANSITIONS );
	}

	/**
	 * Whether a state is final.
	 *
	 * @param string $state State.
	 */
	public static function is_terminal( string $state ): bool {
		return in_array( $state, self::TERMINAL, true );
	}

	/**
	 * States reachable from $from.
	 *
	 * @param string $from Current state.
	 * @return string[]
	 */
	public static function allowed( string $from ): array {
		if ( ! isset( self::TRANSITIONS[ $from ] ) || self::is_terminal( $from ) ) {
			return array();
		}

		return array_merge( self::TRANSITIONS[ $from ], array( self::FAILED, self::CANCELLED ) );
	}

	/**
	 * Whether $from → $to is legal.
	 *
	 * @param string $from Current state.
	 * @param string $to   Target state.
	 */
	public static function can( string $from, string $to ): bool {
		return in_array( $to, self::allowed( $from ), true );
	}

	/**
	 * Throw unless $from → $to is legal.
	 *
	 * @param string $from Current state.
	 * @param string $to   Target state.
	 * @throws \DomainException On an illegal transition.
	 */
	public static function assert_can( string $from, string $to ): void {
		if ( ! self::can( $from, $to ) ) {
			// A programming error between two internal state constants; never shown to a user.
			$error = new \DomainException( sprintf( 'Illegal generation transition %s → %s.', $from, $to ) );
			throw $error;
		}
	}

	/**
	 * Simplified status for clients: queued, processing, ready, failed, cancelled.
	 *
	 * @param string $state State.
	 */
	public static function public_status( string $state ): string {
		switch ( $state ) {
			case self::READY:
				return 'ready';
			case self::FAILED:
				return 'failed';
			case self::CANCELLED:
				return 'cancelled';
			case self::CREATED:
			case self::VALIDATING_INPUT:
			case self::PREPROCESSING:
			case self::QUEUED:
				return 'queued';
			default:
				return 'processing';
		}
	}
}

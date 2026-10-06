<?php
/**
 * How long shopper photos and try-on results are kept (blueprint §41).
 *
 * @package WearGraph
 */

namespace WearGraph\Domain;

defined( 'ABSPATH' ) || exit;

/**
 * Pure. A mode is either tied to the fitting-room session ("session": deleted when the
 * visit ends) or a fixed number of seconds. The default is the shortest (rule 13).
 */
final class RetentionPolicy {

	public const EPHEMERAL = 'ephemeral';
	public const DAY       = '24h';

	/**
	 * Modes: key => seconds (null = until the session expires).
	 *
	 * @var array<string,int|null>
	 */
	private $modes;

	/**
	 * Constructor.
	 *
	 * @param array<string,int|null> $modes Modes; defaults to ephemeral + 24h.
	 */
	public function __construct( array $modes = array() ) {
		$this->modes = $modes ? $modes : self::defaults();
	}

	/**
	 * Built-in modes.
	 *
	 * @return array<string,int|null>
	 */
	public static function defaults(): array {
		return array(
			self::EPHEMERAL => null,
			self::DAY       => 86400,
		);
	}

	/**
	 * Whether a mode exists.
	 *
	 * @param string $mode Mode.
	 */
	public function has( string $mode ): bool {
		return array_key_exists( $mode, $this->modes );
	}

	/**
	 * Normalise an unknown mode to the most private one.
	 *
	 * @param string $mode Mode.
	 */
	public function normalize( string $mode ): string {
		return $this->has( $mode ) ? $mode : self::EPHEMERAL;
	}

	/**
	 * Expiry timestamp for shopper media created now.
	 *
	 * @param string $mode           Mode.
	 * @param int    $session_expiry Unix time the fitting-room session ends.
	 * @param int    $now            Current time.
	 */
	public function expires_at( string $mode, int $session_expiry, int $now ): int {
		$seconds = $this->modes[ $this->normalize( $mode ) ];

		// Media never outlives the session by less than the mode promises, nor lives
		// shorter than the session (the shopper must be able to finish their visit).
		return null === $seconds ? $session_expiry : max( $session_expiry, $now + (int) $seconds );
	}

	/**
	 * Lifetime in seconds for media when the session end is not known (fallback).
	 *
	 * @param string $mode        Mode.
	 * @param int    $session_ttl Session lifetime in seconds.
	 */
	public function seconds( string $mode, int $session_ttl ): int {
		$seconds = $this->modes[ $this->normalize( $mode ) ];

		return null === $seconds ? $session_ttl : max( $session_ttl, (int) $seconds );
	}
}

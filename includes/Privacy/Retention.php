<?php
/**
 * Retention modes available on this site (filterable) and the active one.
 *
 * @package WearGraph
 */

namespace WearGraph\Privacy;

defined( 'ABSPATH' ) || exit;

use WearGraph\Domain\RetentionPolicy;
use WearGraph\Storefront\SessionManager;
use WearGraph\Support\Settings;

/**
 * WordPress wrapper around Domain\RetentionPolicy. Pro adds "7 days" and
 * "until the shopper deletes their account" (with consent) via weargraph_retention_modes.
 */
final class Retention {

	/**
	 * Modes with labels.
	 *
	 * @return array<string,array{label:string,seconds:int|null}>
	 */
	public static function modes(): array {
		$modes = array(
			RetentionPolicy::EPHEMERAL => array(
				'label'   => __( 'Delete when the shopper\'s visit ends (recommended)', 'weargraph' ),
				'seconds' => null,
			),
			RetentionPolicy::DAY       => array(
				'label'   => __( 'Keep for 24 hours', 'weargraph' ),
				'seconds' => DAY_IN_SECONDS,
			),
		);

		/**
		 * Retention modes. Each: array( 'label' => string, 'seconds' => int|null ) where
		 * null means "until the fitting-room session ends", and optionally 'notice': the
		 * sentence shoppers see under the consent box (it must say how long photos are kept).
		 *
		 * @param array<string,array> $modes Modes.
		 */
		return (array) apply_filters( 'weargraph_retention_modes', $modes );
	}

	/**
	 * Policy object for the current modes.
	 */
	public static function policy(): RetentionPolicy {
		$modes = array();
		foreach ( self::modes() as $key => $mode ) {
			$modes[ (string) $key ] = isset( $mode['seconds'] ) ? (int) $mode['seconds'] : null;
		}

		return new RetentionPolicy( $modes );
	}

	/**
	 * Active mode (normalised).
	 */
	public static function mode(): string {
		return self::policy()->normalize( (string) Settings::get( 'retention_mode', RetentionPolicy::EPHEMERAL ) );
	}

	/**
	 * Expiry for shopper media created now.
	 *
	 * @param int $session_expiry Unix time the session ends.
	 */
	public static function expires_at( int $session_expiry ): int {
		return self::policy()->expires_at( self::mode(), $session_expiry, time() );
	}

	/**
	 * Lifetime for storefront generation outputs.
	 */
	public static function storefront_seconds(): int {
		return self::policy()->seconds( self::mode(), SessionManager::ttl() );
	}

	/**
	 * Shopper-facing sentence for the active mode ('' when the mode has none: the
	 * fitting room then uses its built-in "after this visit" or "within 24 hours").
	 */
	public static function notice(): string {
		$modes = self::modes();

		return isset( $modes[ self::mode() ]['notice'] ) ? (string) $modes[ self::mode() ]['notice'] : '';
	}

	/**
	 * Whether the active mode keeps media beyond the visit (for shopper-facing wording).
	 */
	public static function keeps_beyond_session(): bool {
		$modes = self::modes();

		return isset( $modes[ self::mode() ]['seconds'] ) && null !== $modes[ self::mode() ]['seconds'];
	}
}

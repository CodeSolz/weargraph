<?php
/**
 * Spending limits (blueprint §52).
 *
 * @package WearGraph
 */

namespace WearGraph\Domain;

defined( 'ABSPATH' ) || exit;

/**
 * Pure decision logic. The per-request ceiling covers every attempt of one request
 * (first try, retries, fallbacks); the daily cap covers all spend on the store today.
 */
final class CostGuard {

	public const OK             = '';
	public const OVER_REQUEST   = 'over_request_cap';
	public const OVER_DAILY_CAP = 'over_daily_cap';

	/**
	 * Per-request ceiling in USD.
	 *
	 * @var float
	 */
	private $max_per_request;

	/**
	 * Daily cap in USD (0 = no cap).
	 *
	 * @var float
	 */
	private $daily_cap;

	/**
	 * Constructor.
	 *
	 * @param float $max_per_request Per-request ceiling.
	 * @param float $daily_cap       Daily cap; 0 disables it.
	 */
	public function __construct( float $max_per_request, float $daily_cap ) {
		$this->max_per_request = max( 0.0, $max_per_request );
		$this->daily_cap       = max( 0.0, $daily_cap );
	}

	/**
	 * Check one attempt. Returns OK ('') or a reason code.
	 *
	 * @param float $estimate          Estimated cost of this attempt.
	 * @param float $spent_on_request  Already spent on earlier attempts of this request.
	 * @param float $spent_today       Store spend so far today (including this request).
	 */
	public function check( float $estimate, float $spent_on_request, float $spent_today ): string {
		if ( $spent_on_request + $estimate > $this->max_per_request + 1e-9 ) {
			return self::OVER_REQUEST;
		}
		if ( $this->daily_cap > 0 && $spent_today + $estimate > $this->daily_cap + 1e-9 ) {
			return self::OVER_DAILY_CAP;
		}

		return self::OK;
	}
}

<?php
/**
 * Capability: estimate cost and latency before running.
 *
 * @package WearGraph
 */

namespace WearGraph\Providers;

defined( 'ABSPATH' ) || exit;

/**
 * Lets the cost guard reject a request before any money is spent.
 */
interface CostEstimating {

	/**
	 * Estimated USD cost of one attempt.
	 *
	 * @param object $input Any *Input DTO.
	 */
	public function estimate_cost( object $input ): float;

	/**
	 * Estimated latency in milliseconds.
	 *
	 * @param object $input Any *Input DTO.
	 */
	public function estimate_latency_ms( object $input ): int;
}

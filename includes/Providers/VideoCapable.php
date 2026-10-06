<?php
/**
 * Capability: generate video (long-running).
 *
 * @package WearGraph
 */

namespace WearGraph\Providers;

defined( 'ABSPATH' ) || exit;

/**
 * Providers that can generate product video. Implemented in Pro (P3); declared here
 * so the capability name is part of the shared contract.
 */
interface VideoCapable {

	/**
	 * Start a video job.
	 *
	 * @param object $input Video request (defined by the implementing add-on).
	 * @return string Provider operation id.
	 * @throws ProviderException On any provider failure.
	 */
	public function start_video( object $input ): string;

	/**
	 * Poll a video job. Returns null while still running.
	 *
	 * @param string $operation_id Id from start_video().
	 * @throws ProviderException On any provider failure.
	 */
	public function poll_video( string $operation_id ): ?ProviderOutput;
}

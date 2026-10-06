<?php
/**
 * Where a queued generation is executed.
 *
 * @package WearGraph
 */

namespace WearGraph\Transport;

defined( 'ABSPATH' ) || exit;

/**
 * "local" runs the job inside this WordPress (Action Scheduler). The interface lets an
 * extension register another way to run jobs; WearGraph itself only ships "local".
 */
interface TransportInterface {

	/**
	 * Transport id.
	 */
	public function id(): string;

	/**
	 * Hand off a QUEUED generation. Must return quickly.
	 *
	 * @param array<string,mixed> $row Generation row.
	 */
	public function submit( array $row ): void;
}

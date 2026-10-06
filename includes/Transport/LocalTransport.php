<?php
/**
 * Run generations inside this WordPress.
 *
 * @package WearGraph
 */

namespace WearGraph\Transport;

defined( 'ABSPATH' ) || exit;

use WearGraph\Jobs\AsyncKick;
use WearGraph\Jobs\GenerationJob;
use WearGraph\Jobs\Queue;

/**
 * Enqueues an Action Scheduler action (durable; WP-Cron will run it eventually) and
 * fires a non-blocking loopback "kick" so the job normally starts within a second
 * instead of waiting for the next cron tick. Whichever runs first claims the job;
 * the other becomes a no-op.
 */
final class LocalTransport implements TransportInterface {

	/**
	 * Transport id.
	 */
	public function id(): string {
		return 'local';
	}

	/**
	 * Enqueue and kick.
	 *
	 * @param array<string,mixed> $row Generation row.
	 */
	public function submit( array $row ): void {
		$id    = (int) $row['id'];
		$group = self::group_for( (string) $row['context'] );

		Queue::enqueue_now( GenerationJob::HOOK, array( 'id' => $id ), $group );
		AsyncKick::dispatch( $id );
	}

	/**
	 * Queue group for a context (storefront work is highest priority, §29).
	 *
	 * @param string $context Context.
	 */
	public static function group_for( string $context ): string {
		return 'storefront' === $context ? Queue::GROUP_TRYON : Queue::GROUP_STUDIO;
	}
}

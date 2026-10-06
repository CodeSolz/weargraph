<?php
/**
 * Daily rollups and raw-event retention, run by the hourly clean-up.
 *
 * @package WearGraph
 */

namespace WearGraph\Events;

defined( 'ABSPATH' ) || exit;

use WearGraph\Database\Repositories\EventsRepository;
use WearGraph\Support\Settings;

/**
 * Rollups are rebuilt from the last day already rolled up (at least yesterday, so
 * late events near midnight are counted) through today. Raw events are then deleted
 * after the retention period; the rollups are kept.
 */
final class EventMaintenance {

	public const THROUGH_OPTION = 'weargraph_rollup_through';

	/**
	 * Recorder (flushed before the rollup so this request's events are counted).
	 *
	 * @var EventRecorder
	 */
	private $recorder;

	/**
	 * Events.
	 *
	 * @var EventsRepository
	 */
	private $events;

	/**
	 * Constructor.
	 *
	 * @param EventRecorder    $recorder Recorder.
	 * @param EventsRepository $events   Events.
	 */
	public function __construct( EventRecorder $recorder, EventsRepository $events ) {
		$this->recorder = $recorder;
		$this->events   = $events;
	}

	/**
	 * Register hooks.
	 */
	public function boot(): void {
		add_action( 'weargraph_cleanup_run', array( $this, 'run' ) );
	}

	/**
	 * Raw events are kept this many days.
	 */
	public static function retention_days(): int {
		/**
		 * Days to keep raw analytics events (daily rollups are kept regardless).
		 *
		 * @param int $days Default from the event_retention_days setting (90).
		 */
		return max( 7, (int) apply_filters( 'weargraph_event_retention_days', (int) Settings::get( 'event_retention_days', 90 ) ) );
	}

	/**
	 * Roll up, then enforce retention.
	 *
	 * @return array{rolled:int,deleted:int}
	 */
	public function run(): array {
		$this->recorder->flush();

		$rolled  = $this->rollup();
		$cutoff  = gmdate( 'Y-m-d H:i:s', time() - self::retention_days() * DAY_IN_SECONDS );
		$deleted = 0;
		do {
			$batch    = $this->events->delete_before( $cutoff );
			$deleted += $batch;
		} while ( 5000 === $batch );

		return array(
			'rolled'  => $rolled,
			'deleted' => $deleted,
		);
	}

	/**
	 * Rebuild the rollups that may have changed.
	 *
	 * @return int Rollup rows written.
	 */
	public function rollup(): int {
		$tz        = wp_timezone();
		$today     = new \DateTimeImmutable( 'now', $tz );
		$offset    = $tz->getOffset( $today );
		$yesterday = $today->modify( '-1 day' )->format( 'Y-m-d' );
		$floor     = $today->modify( '-' . self::retention_days() . ' days' )->format( 'Y-m-d' );

		$through = (string) get_option( self::THROUGH_OPTION, '' );
		if ( '' === $through ) {
			$oldest  = $this->events->oldest();
			$through = $oldest ? wp_date( 'Y-m-d', (int) strtotime( $oldest . ' UTC' ) ) : $yesterday;
		}

		$from = min( $through, $yesterday );
		$from = max( $from, $floor );
		$to   = $today->format( 'Y-m-d' );

		$rows = $this->events->rollup( $from, $to, $offset );
		update_option( self::THROUGH_OPTION, $to, false );

		return $rows;
	}
}

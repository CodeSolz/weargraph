<?php
/**
 * Records analytics events (blueprint §46, rule 15).
 *
 * @package WearGraph
 */

namespace WearGraph\Events;

defined( 'ABSPATH' ) || exit;

use WearGraph\Database\Repositories\EventsRepository;
use WearGraph\Domain\EventSchema;

/**
 * Events are buffered and written when the request ends, so recording never slows
 * a shopper's request down while it is being handled.
 */
final class EventRecorder {

	private const FLUSH_AT = 50;

	/**
	 * Repository.
	 *
	 * @var EventsRepository
	 */
	private $events;

	/**
	 * Rows waiting to be written.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	private $buffer = array();

	/**
	 * Whether the shutdown flush is hooked.
	 *
	 * @var bool
	 */
	private $hooked = false;

	/**
	 * Constructor.
	 *
	 * @param EventsRepository $events Repository.
	 */
	public function __construct( EventsRepository $events ) {
		$this->events = $events;
	}

	/**
	 * Record an event.
	 *
	 * @param string              $name Event name from EventSchema::NAMES.
	 * @param array<string,mixed> $data Base properties (session_id, product_id…) and 'props'.
	 * @return bool Whether it was accepted.
	 */
	public function record( string $name, array $data = array() ): bool {
		/**
		 * Change an event before it is recorded (Pro adds anonymous_shopper_id, look_id),
		 * or return false to drop it.
		 *
		 * @param array<string,mixed>|false $data Event data.
		 * @param string                    $name Event name.
		 */
		$data = apply_filters( 'weargraph_event', $data, $name );
		if ( ! is_array( $data ) ) {
			return false;
		}

		$row = EventSchema::normalize( $name, $data, time() );
		if ( null === $row ) {
			return false;
		}

		$this->buffer[] = $row;
		if ( ! $this->hooked ) {
			add_action( 'shutdown', array( $this, 'flush' ), 5 );
			$this->hooked = true;
		}
		if ( count( $this->buffer ) >= self::FLUSH_AT ) {
			$this->flush();
		}

		return true;
	}

	/**
	 * Write buffered events.
	 *
	 * @return int Rows written.
	 */
	public function flush(): int {
		$rows         = $this->buffer;
		$this->buffer = array();
		if ( ! $rows ) {
			return 0;
		}

		$written = $this->events->insert_many( $rows );

		foreach ( $rows as $row ) {
			/**
			 * An event was stored (extensions can react, e.g. to build their own reports).
			 *
			 * @param array<string,mixed> $row Stored row (without id).
			 */
			do_action( 'weargraph_event_recorded', $row );
		}

		return $written;
	}
}

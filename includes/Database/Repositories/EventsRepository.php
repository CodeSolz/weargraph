<?php
/**
 * Raw analytics events and their daily rollups.
 *
 * @package WearGraph
 */

namespace WearGraph\Database\Repositories;

defined( 'ABSPATH' ) || exit;

use WearGraph\Database\Schema;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom tables.

/**
 * Raw rows are kept for a limited time; `event_daily` keeps per-product counts for
 * good. Rollup days are the store's local days (site time zone offset).
 */
final class EventsRepository {

	private const COLUMNS = array( 'name', 'session_id', 'anonymous_shopper_id', 'product_id', 'variant_id', 'look_id', 'generation_id', 'order_id', 'props_json', 'created_at' );

	/**
	 * Insert normalized rows.
	 *
	 * @param array<int,array<string,mixed>> $rows Rows from EventSchema::normalize().
	 * @return int Rows inserted.
	 */
	public function insert_many( array $rows ): int {
		global $wpdb;

		$inserted = 0;
		foreach ( $rows as $row ) {
			$data = array();
			foreach ( self::COLUMNS as $col ) {
				$data[ $col ] = $row[ $col ] ?? null;
			}
			$formats   = array_map(
				static function ( $value ) {
					return is_int( $value ) ? '%d' : '%s';
				},
				array_values( $data )
			);
			$inserted += (int) $wpdb->insert( $this->t_events(), $data, $formats );
		}

		return $inserted;
	}

	/**
	 * Products (and variants) a session has seen a try-on of.
	 *
	 * @param string $session_id Session public id.
	 * @return array<int,int[]> product id => variant ids (0 = the product itself).
	 */
	public function tried_products( string $session_id ): array {
		global $wpdb;

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT DISTINCT product_id, variant_id FROM %i WHERE session_id = %s AND name = 'tryon_completed' AND product_id IS NOT NULL",
				$this->t_events(),
				$session_id
			),
			ARRAY_A
		);

		$tried = array();
		foreach ( $rows as $row ) {
			$tried[ (int) $row['product_id'] ][] = (int) $row['variant_id'];
		}

		return $tried;
	}

	/**
	 * Recompute the rollups for a range of local days (inclusive). Idempotent.
	 *
	 * @param string $from   First local day, Y-m-d.
	 * @param string $to     Last local day, Y-m-d.
	 * @param int    $offset Site UTC offset in seconds.
	 * @return int Rollup rows written.
	 */
	public function rollup( string $from, string $to, int $offset ): int {
		global $wpdb;

		$daily  = Schema::table( 'event_daily' );
		$events = Schema::table( 'events' );
		$start  = gmdate( 'Y-m-d H:i:s', (int) strtotime( $from . ' 00:00:00 UTC' ) - $offset );
		$end    = gmdate( 'Y-m-d H:i:s', (int) strtotime( $to . ' 00:00:00 UTC' ) + DAY_IN_SECONDS - $offset );

		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE day BETWEEN %s AND %s', $daily, $from, $to ) );

		return (int) $wpdb->query(
			$wpdb->prepare(
				'INSERT INTO %i (day, product_id, name, count)
				SELECT DATE(DATE_ADD(created_at, INTERVAL %d SECOND)) AS d, COALESCE(product_id, 0) AS p, name, COUNT(*)
				FROM %i WHERE created_at >= %s AND created_at < %s
				GROUP BY d, p, name',
				$daily,
				$offset,
				$events,
				$start,
				$end
			)
		);
	}

	/**
	 * Delete raw events older than a cut-off, in batches.
	 *
	 * @param string $before UTC datetime.
	 * @param int    $limit  Batch size.
	 * @return int Rows deleted.
	 */
	public function delete_before( string $before, int $limit = 5000 ): int {
		global $wpdb;

		return (int) $wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE created_at < %s LIMIT %d', $this->t_events(), $before, $limit ) );
	}

	/**
	 * Oldest raw event time (UTC), or null.
	 */
	public function oldest(): ?string {
		global $wpdb;

		$value = $wpdb->get_var( $wpdb->prepare( 'SELECT MIN(created_at) FROM %i', $this->t_events() ) );

		return $value ? (string) $value : null;
	}

	/**
	 * Count events by name since a UTC time.
	 *
	 * @param string[] $names Names.
	 * @param string   $since UTC datetime.
	 */
	public function count( array $names, string $since ): int {
		global $wpdb;

		$total = 0;
		foreach ( array_unique( $names ) as $name ) {
			$total += (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE name = %s AND created_at >= %s', $this->t_events(), $name, $since ) );
		}

		return $total;
	}

	/**
	 * Distinct sessions with an event since a UTC time.
	 *
	 * @param string $name  Name.
	 * @param string $since UTC datetime.
	 */
	public function distinct_sessions( string $name, string $since ): int {
		global $wpdb;

		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(DISTINCT session_id) FROM %i WHERE name = %s AND created_at >= %s AND session_id IS NOT NULL', $this->t_events(), $name, $since ) );
	}

	/**
	 * Distinct orders with an event since a UTC time.
	 *
	 * @param string $name  Name.
	 * @param string $since UTC datetime.
	 */
	public function distinct_orders( string $name, string $since ): int {
		global $wpdb;

		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(DISTINCT order_id) FROM %i WHERE name = %s AND created_at >= %s AND order_id IS NOT NULL', $this->t_events(), $name, $since ) );
	}

	/**
	 * Sum of a numeric property of one event since a UTC time, for one currency.
	 *
	 * @param string $name     Name.
	 * @param string $property Property key, e.g. line_total.
	 * @param string $currency Currency code the property is in.
	 * @param string $since    UTC datetime.
	 */
	public function sum_property( string $name, string $property, string $currency, string $since ): float {
		global $wpdb;

		$total = 0.0;
		$last  = 0;
		$batch = 500;
		do {
			$rows    = $wpdb->get_results(
				$wpdb->prepare( 'SELECT id, props_json FROM %i WHERE name = %s AND created_at >= %s AND id > %d ORDER BY id ASC LIMIT %d', $this->t_events(), $name, $since, $last, $batch ),
				ARRAY_A
			);
			$fetched = count( $rows );
			foreach ( $rows as $row ) {
				$last  = (int) $row['id'];
				$props = json_decode( (string) $row['props_json'], true );
				if ( is_array( $props ) && ( $props['currency'] ?? '' ) === $currency && is_numeric( $props[ $property ] ?? null ) ) {
					$total += (float) $props[ $property ];
				}
			}
		} while ( $batch === $fetched );

		return $total;
	}

	/**
	 * Top products by rolled-up count of one event since a local day.
	 *
	 * @param string $name  Name.
	 * @param string $since Local day, Y-m-d.
	 * @param int    $limit Rows.
	 * @return array<int,int> product id => count.
	 */
	public function top_products( string $name, string $since, int $limit ): array {
		global $wpdb;

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT product_id, SUM(count) AS total FROM %i WHERE name = %s AND day >= %s AND product_id > 0 GROUP BY product_id ORDER BY total DESC, product_id ASC LIMIT %d',
				$this->t_event_daily(),
				$name,
				$since,
				$limit
			),
			ARRAY_A
		);

		$top = array();
		foreach ( $rows as $row ) {
			$top[ (int) $row['product_id'] ] = (int) $row['total'];
		}

		return $top;
	}

	/**
	 * Rolled-up totals of one event per local day.
	 *
	 * @param string $name  Name.
	 * @param string $since Local day, Y-m-d.
	 * @return array<string,int> day => count.
	 */
	public function daily( string $name, string $since ): array {
		global $wpdb;

		$rows = $wpdb->get_results(
			$wpdb->prepare( 'SELECT day, SUM(count) AS total FROM %i WHERE name = %s AND day >= %s GROUP BY day', $this->t_event_daily(), $name, $since ),
			ARRAY_A
		);

		return array_map( 'intval', array_column( $rows, 'total', 'day' ) );
	}

	/**
	 * Latest events.
	 *
	 * @param int $limit Rows.
	 * @return array<int,array<string,mixed>>
	 */
	public function recent( int $limit ): array {
		global $wpdb;

		return (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i ORDER BY id DESC LIMIT %d', $this->t_events(), $limit ), ARRAY_A );
	}

	/**
	 * Unlink a session from its events (privacy erase). Counts stay; the link to the
	 * person goes.
	 *
	 * @param string $session_id Session public id.
	 */
	public function forget_session( string $session_id ): void {
		global $wpdb;

		$wpdb->update( Schema::table( 'events' ), array( 'session_id' => null ), array( 'session_id' => $session_id ) );
	}

	/**
	 * Events table.
	 */
	private function t_events(): string {
		return Schema::table( 'events' );
	}

	/**
	 * Rollups table.
	 */
	private function t_event_daily(): string {
		return Schema::table( 'event_daily' );
	}
}

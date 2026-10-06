<?php
/**
 * Generations and their state audit trail.
 *
 * @package WearGraph
 */

namespace WearGraph\Database\Repositories;

defined( 'ABSPATH' ) || exit;

use WearGraph\Database\Schema;
use WearGraph\Domain\GenerationState;
use WearGraph\Support\Ulid;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom tables; rows change on every job step, caching would serve stale state.

/**
 * All writes to generations.state go through transition() or claim(), which validate
 * against the state machine, write the audit row and fire
 * weargraph_generation_state_changed.
 */
final class GenerationsRepository {

	/**
	 * Table name.
	 */
	private function table(): string {
		return Schema::table( 'generations' );
	}

	/**
	 * Insert a new generation in CREATED. Returns the row.
	 *
	 * @param array<string,mixed> $data Column values (kind, context, product_id, variant_id, session_id, user_id, idempotency_key, input_json, cost_estimate_usd, expires_at).
	 * @return array<string,mixed>
	 */
	public function create( array $data ): array {
		global $wpdb;

		$now = current_time( 'mysql', true );
		$row = array_merge(
			array(
				'public_id'  => Ulid::generate(),
				'state'      => GenerationState::CREATED,
				'transport'  => 'local',
				'created_at' => $now,
				'updated_at' => $now,
			),
			$data
		);

		$wpdb->insert( $this->table(), $row );
		$row['id'] = (int) $wpdb->insert_id;

		$this->log( $row['id'], null, GenerationState::CREATED );

		return $this->find( $row['id'] );
	}

	/**
	 * Row by internal id.
	 *
	 * @param int $id Id.
	 * @return array<string,mixed>|null
	 */
	public function find( int $id ): ?array {
		global $wpdb;

		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', $this->table(), $id ), ARRAY_A );

		return $row ? $row : null;
	}

	/**
	 * Row by public id.
	 *
	 * @param string $public_id ULID.
	 * @return array<string,mixed>|null
	 */
	public function find_public( string $public_id ): ?array {
		global $wpdb;

		if ( ! Ulid::is_valid( $public_id ) ) {
			return null;
		}

		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE public_id = %s', $this->table(), $public_id ), ARRAY_A );

		return $row ? $row : null;
	}

	/**
	 * Newest reusable READY generation for an idempotency key (L1 cache, §28).
	 *
	 * @param string $key Idempotency key.
	 * @return array<string,mixed>|null
	 */
	public function find_reusable( string $key ): ?array {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE idempotency_key = %s AND state = %s AND ( expires_at IS NULL OR expires_at > %s ) ORDER BY id DESC LIMIT 1',
				$this->table(),
				$key,
				GenerationState::READY,
				current_time( 'mysql', true )
			),
			ARRAY_A
		);

		return $row ? $row : null;
	}

	/**
	 * Move to a new state (validated). Extra columns can be written in the same UPDATE.
	 *
	 * @param int                 $id     Generation id.
	 * @param string              $to     Target state.
	 * @param array<string,mixed> $fields Extra columns.
	 * @param array<string,mixed> $meta   Audit metadata (no secrets, no image data).
	 * @return bool False when the row changed state concurrently.
	 * @throws \DomainException On an illegal transition.
	 */
	public function transition( int $id, string $to, array $fields = array(), array $meta = array() ): bool {
		$row = $this->find( $id );
		// A terminal row (e.g. cancelled while a worker was busy) is a lost race, not a bug.
		if ( ! $row || GenerationState::is_terminal( (string) $row['state'] ) ) {
			return false;
		}

		GenerationState::assert_can( $row['state'], $to );

		return $this->compare_and_set( $id, array( $row['state'] ), $to, $fields, $meta );
	}

	/**
	 * Atomically move from one of $from to $to. Used by workers to claim a job so two
	 * runners can never process the same generation.
	 *
	 * @param int      $id   Generation id.
	 * @param string[] $from Acceptable current states.
	 * @param string   $to   Target state.
	 * @return bool Whether this caller won the claim.
	 */
	public function claim( int $id, array $from, string $to ): bool {
		foreach ( $from as $state ) {
			GenerationState::assert_can( $state, $to );
		}

		return $this->compare_and_set( $id, $from, $to, array(), array( 'claim' => true ) );
	}

	/**
	 * Update non-state columns.
	 *
	 * @param int                 $id     Generation id.
	 * @param array<string,mixed> $fields Columns.
	 */
	public function update( int $id, array $fields ): void {
		global $wpdb;

		unset( $fields['state'], $fields['id'], $fields['public_id'] );
		$fields['updated_at'] = current_time( 'mysql', true );
		$wpdb->update( $this->table(), $fields, array( 'id' => $id ) );
	}

	/**
	 * Real provider spend since a UTC datetime (includes failed and cancelled attempts).
	 *
	 * @param string $since UTC 'Y-m-d H:i:s'.
	 */
	public function spent_since( string $since ): float {
		global $wpdb;

		return (float) $wpdb->get_var( $wpdb->prepare( 'SELECT COALESCE(SUM(cost_usd),0) FROM %i WHERE created_at >= %s', $this->table(), $since ) );
	}

	/**
	 * Failed generations since a UTC datetime, outside one context (storefront
	 * failures are counted from events, because their rows expire within hours).
	 *
	 * @param string $since           UTC 'Y-m-d H:i:s'.
	 * @param string $exclude_context Context to leave out.
	 */
	public function failed_since( string $since, string $exclude_context ): int {
		global $wpdb;

		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE state = 'FAILED' AND context <> %s AND created_at >= %s", $this->table(), $exclude_context, $since ) );
	}

	/**
	 * Newest generations for a product.
	 *
	 * @param int      $product_id Product.
	 * @param string   $context    studio|storefront|sandbox.
	 * @param string[] $kinds      Kinds to include.
	 * @param int      $limit      Max rows.
	 * @return array<int,array<string,mixed>>
	 */
	public function recent_for_product( int $product_id, string $context, array $kinds, int $limit = 20 ): array {
		return $this->history_for_product( $product_id, $context, $kinds, array(), $limit, 0 );
	}

	/**
	 * A product's generations, newest first, optionally limited to some states, paged.
	 *
	 * @param int      $product_id Product.
	 * @param string   $context    studio|storefront|sandbox.
	 * @param string[] $kinds      Kinds to include.
	 * @param string[] $states     States to include (empty = all).
	 * @param int      $limit      Max rows.
	 * @param int      $offset     Rows to skip.
	 * @return array<int,array<string,mixed>>
	 */
	public function history_for_product( int $product_id, string $context, array $kinds, array $states, int $limit, int $offset ): array {
		global $wpdb;

		if ( ! $kinds ) {
			return array();
		}
		$kinds  = self::csv( $kinds );
		$states = self::csv( $states );

		return (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM %i WHERE product_id = %d AND context = %s AND FIND_IN_SET(kind, %s) AND ( %s = '' OR FIND_IN_SET(state, %s) ) ORDER BY id DESC LIMIT %d OFFSET %d",
				$this->table(),
				$product_id,
				$context,
				$kinds,
				$states,
				$states,
				max( 1, $limit ),
				max( 0, $offset )
			),
			ARRAY_A
		);
	}

	/**
	 * Number of rows history_for_product() would page through.
	 *
	 * @param int      $product_id Product.
	 * @param string   $context    Context.
	 * @param string[] $kinds      Kinds.
	 * @param string[] $states     States (empty = all).
	 */
	public function count_history( int $product_id, string $context, array $kinds, array $states ): int {
		global $wpdb;

		if ( ! $kinds ) {
			return 0;
		}
		$kinds  = self::csv( $kinds );
		$states = self::csv( $states );

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM %i WHERE product_id = %d AND context = %s AND FIND_IN_SET(kind, %s) AND ( %s = '' OR FIND_IN_SET(state, %s) )",
				$this->table(),
				$product_id,
				$context,
				$kinds,
				$states,
				$states
			)
		);
	}

	/**
	 * A list of kinds or states as one FIND_IN_SET() value (they are code constants
	 * and never contain a comma).
	 *
	 * @param string[] $values Values.
	 */
	private static function csv( array $values ): string {
		return implode( ',', array_map( 'strval', $values ) );
	}

	/**
	 * Finished image count and total spend for a product's studio work.
	 *
	 * @param int $product_id Product.
	 * @return array{ready:int,cost:float}
	 */
	public function product_stats( int $product_id ): array {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT SUM(CASE WHEN state = %s AND kind = %s THEN 1 ELSE 0 END) AS ready, COALESCE(SUM(cost_usd),0) AS cost FROM %i WHERE product_id = %d AND context = %s',
				GenerationState::READY,
				'image_generate',
				$this->table(),
				$product_id,
				'studio'
			),
			ARRAY_A
		);

		return array(
			'ready' => (int) ( $row['ready'] ?? 0 ),
			'cost'  => (float) ( $row['cost'] ?? 0 ),
		);
	}

	/**
	 * Permanently delete a generation and its audit rows (outputs are removed by the caller;
	 * the usage ledger keeps its row as the spending record).
	 *
	 * @param int $id Generation id.
	 */
	public function delete( int $id ): void {
		global $wpdb;

		$wpdb->delete( Schema::table( 'generation_states' ), array( 'generation_id' => $id ) );
		$wpdb->delete( $this->table(), array( 'id' => $id ) );
	}

	/**
	 * Generations of a storefront session.
	 *
	 * @param string $session_id Session public id.
	 * @return array<int,array<string,mixed>>
	 */
	public function for_session( string $session_id ): array {
		global $wpdb;

		return (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE session_id = %s ORDER BY id ASC', $this->table(), $session_id ), ARRAY_A );
	}

	/**
	 * Audit trail for a generation, oldest first.
	 *
	 * @param int $id Generation id.
	 * @return array<int,array<string,mixed>>
	 */
	public function history( int $id ): array {
		global $wpdb;

		$table = Schema::table( 'generation_states' );

		return (array) $wpdb->get_results( $wpdb->prepare( 'SELECT from_state, to_state, meta_json, created_at FROM %i WHERE generation_id = %d ORDER BY id ASC', $table, $id ), ARRAY_A );
	}

	/**
	 * Conditional UPDATE … WHERE state = (the state read), audit row, hook.
	 *
	 * @param int                 $id     Generation id.
	 * @param string[]            $from   Acceptable current states.
	 * @param string              $to     Target state.
	 * @param array<string,mixed> $fields Extra columns.
	 * @param array<string,mixed> $meta   Audit metadata.
	 */
	private function compare_and_set( int $id, array $from, string $to, array $fields, array $meta ): bool {
		global $wpdb;

		$current = $this->find( $id );
		if ( ! $current || ! in_array( $current['state'], $from, true ) ) {
			return false;
		}

		unset( $fields['id'], $fields['public_id'] );
		$fields['state']      = $to;
		$fields['updated_at'] = current_time( 'mysql', true );

		// The WHERE on the state read above makes this a compare-and-set: it matches no
		// row when another request moved the generation in the meantime.
		$affected = $wpdb->update(
			$this->table(),
			$fields,
			array(
				'id'    => $id,
				'state' => (string) $current['state'],
			)
		);

		if ( 1 !== (int) $affected ) {
			return false;
		}

		$this->log( $id, $current['state'], $to, $meta );

		/**
		 * A generation changed state.
		 *
		 * @param int    $id   Generation id.
		 * @param string $from Previous state.
		 * @param string $to   New state.
		 */
		do_action( 'weargraph_generation_state_changed', $id, $current['state'], $to );

		return true;
	}

	/**
	 * Write an audit row.
	 *
	 * @param int                 $id   Generation id.
	 * @param string|null         $from Previous state.
	 * @param string              $to   New state.
	 * @param array<string,mixed> $meta Metadata.
	 */
	private function log( int $id, ?string $from, string $to, array $meta = array() ): void {
		global $wpdb;

		$wpdb->insert(
			Schema::table( 'generation_states' ),
			array(
				'generation_id' => $id,
				'from_state'    => $from,
				'to_state'      => $to,
				'meta_json'     => $meta ? wp_json_encode( $meta ) : null,
				'created_at'    => current_time( 'mysql', true ),
			)
		);
	}
}

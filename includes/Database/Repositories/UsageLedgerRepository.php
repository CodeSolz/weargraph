<?php
/**
 * Usage ledger (decision X4): what was generated, what it really cost, and whether
 * it counts toward quota.
 *
 * @package WearGraph
 */

namespace WearGraph\Database\Repositories;

defined( 'ABSPATH' ) || exit;

use WearGraph\Database\Schema;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table.

/**
 * One row per finished generation (unique on generation_id, so a result can never be
 * counted twice). Only accepted READY results count toward quota (rule 6).
 */
final class UsageLedgerRepository {

	/**
	 * Table name.
	 */
	private function table(): string {
		return Schema::table( 'usage_ledger' );
	}

	/**
	 * Record a finished generation. A second call for the same generation is ignored.
	 *
	 * @param int    $generation_id Generation id.
	 * @param string $kind          Generation kind.
	 * @param bool   $counts        Counts toward quota (only accepted READY results).
	 * @param float  $cost_usd      Actual provider cost.
	 * @param string $provider      Provider id.
	 * @param string $model         Model.
	 * @param int    $units         Units (1 for single items; N stages for looks).
	 * @return bool Whether a row was written.
	 */
	public function record( int $generation_id, string $kind, bool $counts, float $cost_usd, string $provider, string $model, int $units = 1 ): bool {
		global $wpdb;

		// INSERT IGNORE relies on the UNIQUE KEY on generation_id: idempotent by construction.
		$written = $wpdb->query(
			$wpdb->prepare(
				'INSERT IGNORE INTO %i (generation_id, kind, units, counts_toward_quota, provider, model, cost_usd, created_at) VALUES (%d, %s, %d, %d, %s, %s, %f, %s)',
				$this->table(),
				$generation_id,
				$kind,
				max( 1, $units ),
				$counts ? 1 : 0,
				$provider,
				$model,
				$cost_usd,
				current_time( 'mysql', true )
			)
		);

		return 1 === (int) $written;
	}

	/**
	 * Quota units used since a UTC datetime.
	 *
	 * @param string $since UTC 'Y-m-d H:i:s'.
	 */
	public function units_since( string $since ): int {
		global $wpdb;

		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COALESCE(SUM(units),0) FROM %i WHERE counts_toward_quota = 1 AND created_at >= %s', $this->table(), $since ) );
	}

	/**
	 * Ledger row for a generation.
	 *
	 * @param int $generation_id Generation id.
	 * @return array<string,mixed>|null
	 */
	public function for_generation( int $generation_id ): ?array {
		global $wpdb;

		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE generation_id = %d', $this->table(), $generation_id ), ARRAY_A );

		return $row ? $row : null;
	}

	/**
	 * Spend and generated images since a UTC datetime (dashboard).
	 *
	 * @param string $since UTC 'Y-m-d H:i:s'.
	 * @return array{cost:float,images:int} Cost includes failed attempts; images are accepted results that are not text.
	 */
	public function totals_since( string $since ): array {
		global $wpdb;

		$row = $wpdb->get_row( $wpdb->prepare( "SELECT COALESCE(SUM(cost_usd),0) AS cost, COALESCE(SUM(CASE WHEN counts_toward_quota = 1 AND kind <> 'alt_text' THEN units ELSE 0 END),0) AS images FROM %i WHERE created_at >= %s", $this->table(), $since ), ARRAY_A );

		return array(
			'cost'   => (float) ( $row['cost'] ?? 0 ),
			'images' => (int) ( $row['images'] ?? 0 ),
		);
	}
}

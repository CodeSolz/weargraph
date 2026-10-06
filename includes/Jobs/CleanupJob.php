<?php
/**
 * Hourly retention enforcement (rule 12, blueprint §41).
 *
 * @package WearGraph
 */

namespace WearGraph\Jobs;

defined( 'ABSPATH' ) || exit;

use WearGraph\Database\Repositories\GenerationsRepository;
use WearGraph\Database\Repositories\UsageLedgerRepository;
use WearGraph\Database\Schema;
use WearGraph\Domain\GenerationState;
use WearGraph\Storage\PrivateStore;
use WearGraph\Support\Logger;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- bulk maintenance on custom tables.

/**
 * Order matters: expired files first, then the rows that pointed to them, then
 * sessions nothing refers to any more (session rows are kept while their media
 * lives, so the privacy eraser can still find a logged-in shopper's files).
 */
final class CleanupJob {

	public const HOOK = 'weargraph_cleanup';

	private const STALLED_AFTER = HOUR_IN_SECONDS;
	private const ORPHAN_GRACE  = HOUR_IN_SECONDS;
	private const BATCH         = 500;

	/**
	 * Generations.
	 *
	 * @var GenerationsRepository
	 */
	private $generations;

	/**
	 * Ledger.
	 *
	 * @var UsageLedgerRepository
	 */
	private $ledger;

	/**
	 * Constructor.
	 *
	 * @param GenerationsRepository $generations Generations.
	 * @param UsageLedgerRepository $ledger      Ledger.
	 */
	public function __construct( GenerationsRepository $generations, UsageLedgerRepository $ledger ) {
		$this->generations = $generations;
		$this->ledger      = $ledger;
	}

	/**
	 * Register the handler and keep the hourly schedule in place.
	 */
	public function boot(): void {
		add_action( self::HOOK, array( $this, 'run' ) );
		add_action(
			'action_scheduler_init',
			static function () {
				Queue::ensure_recurring( self::HOOK, HOUR_IN_SECONDS, Queue::GROUP_CLEANUP );
			}
		);
	}

	/**
	 * Run every step.
	 *
	 * @return array<string,int> Counts per step.
	 */
	public function run(): array {
		$stats = array(
			'stalled'     => $this->reap_stalled(),
			'assets'      => $this->delete_expired_assets(),
			'generations' => $this->delete_expired_generations(),
			'sessions'    => $this->delete_dead_sessions(),
			'orphans'     => $this->delete_orphan_files(),
		);

		/**
		 * Extra retention work (events, Pro data) after the core cleanup.
		 *
		 * @param array<string,int> $stats Counts so far.
		 */
		do_action( 'weargraph_cleanup_run', $stats );

		if ( array_sum( $stats ) > 0 ) {
			Logger::log( 'Cleanup', $stats );
		}

		return $stats;
	}

	/**
	 * Generations stuck in a non-final state for over an hour become FAILED (uncounted).
	 */
	private function reap_stalled(): int {
		global $wpdb;

		$table    = Schema::table( 'generations' );
		$terminal = array( GenerationState::READY, GenerationState::FAILED, GenerationState::CANCELLED );
		$ids      = $wpdb->get_col(
			$wpdb->prepare(
				'SELECT id FROM %i WHERE state NOT IN (%s, %s, %s) AND updated_at < %s LIMIT %d',
				$table,
				$terminal[0],
				$terminal[1],
				$terminal[2],
				gmdate( 'Y-m-d H:i:s', time() - self::STALLED_AFTER ),
				self::BATCH
			)
		);

		$count = 0;
		foreach ( $ids as $id ) {
			$moved = $this->generations->transition(
				(int) $id,
				GenerationState::FAILED,
				array(
					'error_code'    => 'stalled',
					'error_message' => esc_html__( 'This request took too long and was stopped. Please try again.', 'weargraph' ),
				),
				array( 'error' => 'stalled' )
			);
			if ( $moved ) {
				$row = $this->generations->find( (int) $id );
				$this->ledger->record( (int) $id, (string) $row['kind'], false, (float) $row['cost_usd'], (string) $row['provider'], (string) $row['model'] );
				/** This action is documented in includes/Jobs/GenerationJob.php */
				do_action( 'weargraph_generation_failed', (int) $id, 'stalled' );
				++$count;
			}
		}

		return $count;
	}

	/**
	 * Delete expired private files and their rows; purge soft-deleted rows.
	 */
	private function delete_expired_assets(): int {
		global $wpdb;

		$table = Schema::table( 'assets' );
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, path FROM %i WHERE storage = 'private' AND ( deleted_at IS NOT NULL OR ( expires_at IS NOT NULL AND expires_at < %s ) ) LIMIT %d",
				$table,
				gmdate( 'Y-m-d H:i:s' ),
				self::BATCH
			),
			ARRAY_A
		);

		foreach ( $rows as $row ) {
			PrivateStore::delete( (string) $row['path'] );
			$wpdb->delete( $table, array( 'id' => (int) $row['id'] ) );
		}

		return count( $rows );
	}

	/**
	 * Delete finished generations past their expiry (and their audit rows). The usage
	 * ledger keeps its row: it is the spending record, and holds no personal data.
	 */
	private function delete_expired_generations(): int {
		global $wpdb;

		$table = Schema::table( 'generations' );
		$ids   = $wpdb->get_col(
			$wpdb->prepare(
				'SELECT id FROM %i WHERE state IN (%s, %s, %s) AND expires_at IS NOT NULL AND expires_at < %s LIMIT %d',
				$table,
				GenerationState::READY,
				GenerationState::FAILED,
				GenerationState::CANCELLED,
				gmdate( 'Y-m-d H:i:s' ),
				self::BATCH
			)
		);

		$assets = Schema::table( 'assets' );
		foreach ( $ids as $id ) {
			// Outputs normally expired in the step before; catch any stragglers.
			foreach ( $wpdb->get_results( $wpdb->prepare( "SELECT id, path FROM %i WHERE generation_id = %d AND storage = 'private'", $assets, (int) $id ), ARRAY_A ) as $asset ) {
				PrivateStore::delete( (string) $asset['path'] );
				$wpdb->delete( $assets, array( 'id' => (int) $asset['id'] ) );
			}
			$this->generations->delete( (int) $id );
		}

		return count( $ids );
	}

	/**
	 * Delete expired sessions that no stored media refers to any more.
	 */
	private function delete_dead_sessions(): int {
		global $wpdb;

		$sessions = Schema::table( 'sessions' );
		$assets   = Schema::table( 'assets' );

		return (int) $wpdb->query(
			$wpdb->prepare(
				'DELETE s FROM %i s LEFT JOIN %i a ON a.session_id = s.public_id WHERE s.expires_at < %s AND a.id IS NULL',
				$sessions,
				$assets,
				gmdate( 'Y-m-d H:i:s' )
			)
		);
	}

	/**
	 * Files in the private store that no asset row points to (e.g. a crash between
	 * writing the file and inserting the row). A grace period protects writes in flight.
	 */
	private function delete_orphan_files(): int {
		global $wpdb;

		$base  = PrivateStore::base_dir();
		$files = array();
		foreach ( array( 'png', 'jpg', 'webp' ) as $ext ) { // No GLOB_BRACE: unavailable on some systems.
			$found = glob( $base . '/[0-9][0-9][0-9][0-9]/[0-9][0-9]/*.' . $ext );
			if ( is_array( $found ) ) {
				$files = array_merge( $files, $found );
			}
		}
		if ( ! $files ) {
			return 0;
		}

		$known = array_flip( (array) $wpdb->get_col( $wpdb->prepare( 'SELECT path FROM %i WHERE path IS NOT NULL', Schema::table( 'assets' ) ) ) );
		$count = 0;
		foreach ( $files as $file ) {
			$relative = ltrim( str_replace( '\\', '/', substr( $file, strlen( $base ) ) ), '/' );
			if ( isset( $known[ $relative ] ) || time() - (int) filemtime( $file ) < self::ORPHAN_GRACE ) {
				continue;
			}
			if ( PrivateStore::delete( $relative ) ) {
				++$count;
			}
		}

		return $count;
	}
}

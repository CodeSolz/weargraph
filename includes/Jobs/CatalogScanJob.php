<?php
/**
 * Full-catalog scan in resumable batches, plus debounced single-product rescans.
 *
 * @package WearGraph
 */

namespace WearGraph\Jobs;

defined( 'ABSPATH' ) || exit;

use WearGraph\Commerce\WooCatalogReader;
use WearGraph\Database\Repositories\CatalogRepository;
use WearGraph\Services\CatalogScanService;
use WearGraph\Support\Logger;
use WearGraph\Support\Ulid;

/**
 * Progress lives in one option and is saved after every product (rule 9: resumable).
 * If a batch dies mid-way (timeout, fatal, server restart), status() notices the
 * stalled run and re-queues it from the saved cursor.
 */
final class CatalogScanJob {

	public const HOOK_BATCH = 'weargraph_catalog_scan_batch';
	public const HOOK_ONE   = 'weargraph_catalog_scan_product';
	public const OPTION     = 'weargraph_catalog_scan';

	private const BATCH_SIZE    = 25;
	private const BATCH_SECONDS = 20;
	private const STALL_SECONDS = 300;
	private const DEBOUNCE      = 30;

	/**
	 * Reader.
	 *
	 * @var WooCatalogReader
	 */
	private $reader;

	/**
	 * Scanner.
	 *
	 * @var CatalogScanService
	 */
	private $scanner;

	/**
	 * Rows.
	 *
	 * @var CatalogRepository
	 */
	private $repo;

	/**
	 * Constructor.
	 *
	 * @param WooCatalogReader   $reader  Reader.
	 * @param CatalogScanService $scanner Scanner.
	 * @param CatalogRepository  $repo    Rows.
	 */
	public function __construct( WooCatalogReader $reader, CatalogScanService $scanner, CatalogRepository $repo ) {
		$this->reader  = $reader;
		$this->scanner = $scanner;
		$this->repo    = $repo;
	}

	/**
	 * Register hooks: AS handlers and incremental triggers (F4.7).
	 */
	public function boot(): void {
		add_action(
			self::HOOK_BATCH,
			function ( $run_id ) {
				$this->run_batch( (string) $run_id );
			}
		);
		add_action(
			self::HOOK_ONE,
			function ( $product_id, $force = false ) {
				// $force: rescan even if the product is unchanged (new rules or settings).
				$this->scanner->scan_product( (int) $product_id, (bool) $force );
			},
			10,
			2
		);

		add_action( 'woocommerce_new_product', array( $this, 'queue_product' ) );
		add_action( 'woocommerce_update_product', array( $this, 'queue_product' ) );
		add_action( 'woocommerce_new_product_variation', array( $this, 'queue_variation' ) );
		add_action( 'woocommerce_update_product_variation', array( $this, 'queue_variation' ) );
		add_action( 'untrashed_post', array( $this, 'queue_if_product' ) );
		add_action( 'wp_trash_post', array( $this, 'forget_if_product' ) );
		add_action( 'before_delete_post', array( $this, 'forget_if_product' ) );
	}

	/**
	 * Start (or restart) a full scan.
	 *
	 * @param bool $force Rescan unchanged products too.
	 * @return array<string,mixed> State.
	 */
	public function start( bool $force = false ): array {
		Queue::unschedule_hook( self::HOOK_BATCH, Queue::GROUP_CATALOG );

		$state = array(
			'run'         => Ulid::generate(),
			'status'      => 'running',
			'force'       => $force,
			'cursor'      => 0,
			'processed'   => 0,
			'scanned'     => 0,
			'skipped'     => 0,
			'errors'      => 0,
			'total'       => $this->reader->count(),
			'started_at'  => time(),
			'updated_at'  => time(),
			'finished_at' => null,
		);
		$this->save( $state );
		Queue::enqueue_now( self::HOOK_BATCH, array( 'run' => $state['run'] ), Queue::GROUP_CATALOG );

		return $state;
	}

	/**
	 * Stop a running scan (progress so far is kept).
	 */
	public function cancel(): array {
		$state = $this->state();
		if ( 'running' === ( $state['status'] ?? '' ) ) {
			$state['status']      = 'cancelled';
			$state['finished_at'] = time();
			$this->save( $state );
		}
		Queue::unschedule_hook( self::HOOK_BATCH, Queue::GROUP_CATALOG );

		return $state;
	}

	/**
	 * Current state; revives a stalled run.
	 *
	 * @return array<string,mixed>
	 */
	public function status(): array {
		$state = $this->state();

		if ( 'running' === ( $state['status'] ?? '' )
			&& time() - (int) $state['updated_at'] > self::STALL_SECONDS
			&& ! Queue::is_pending( self::HOOK_BATCH, array( 'run' => $state['run'] ), Queue::GROUP_CATALOG ) ) {
			Logger::log( 'Resuming stalled catalog scan', array( 'cursor' => $state['cursor'] ) );
			$state['updated_at'] = time();
			$this->save( $state );
			Queue::enqueue_now( self::HOOK_BATCH, array( 'run' => $state['run'] ), Queue::GROUP_CATALOG );
		}

		$state['percent'] = ! empty( $state['total'] ) ? min( 100, (int) floor( 100 * (int) $state['processed'] / (int) $state['total'] ) ) : ( 'done' === ( $state['status'] ?? '' ) ? 100 : 0 );

		return $state;
	}

	/**
	 * Process one batch; saves progress after every product, re-queues itself.
	 *
	 * @param string $run_id Run id (a stale action for an old run does nothing).
	 */
	public function run_batch( string $run_id ): void {
		$state = $this->state();
		if ( 'running' !== ( $state['status'] ?? '' ) || $state['run'] !== $run_id ) {
			return;
		}

		$started = microtime( true );
		$ids     = $this->reader->next_ids( (int) $state['cursor'], self::BATCH_SIZE );

		foreach ( $ids as $product_id ) {
			try {
				$result = $this->scanner->scan_product( $product_id, (bool) $state['force'] );
				if ( 'scanned' === $result ) {
					++$state['scanned'];
				} elseif ( 'skipped' === $result ) {
					++$state['skipped'];
				}
			} catch ( \Throwable $e ) {
				++$state['errors'];
				Logger::error(
					'Catalog scan failed for product',
					array(
						'product' => $product_id,
						'error'   => $e->getMessage(),
					)
				);
			}

			$state['cursor']     = $product_id;
			$state['updated_at'] = time();
			++$state['processed'];
			$this->save( $state );

			// Stop early and continue in a fresh request well before PHP time limits.
			if ( microtime( true ) - $started > self::BATCH_SECONDS ) {
				Queue::enqueue_now( self::HOOK_BATCH, array( 'run' => $run_id ), Queue::GROUP_CATALOG );
				return;
			}
		}

		if ( count( $ids ) < self::BATCH_SIZE ) {
			$this->repo->prune_stale();
			$state['status']      = 'done';
			$state['finished_at'] = time();
			$state['total']       = max( (int) $state['total'], (int) $state['processed'] );
			$this->save( $state );

			/**
			 * A full catalog scan finished.
			 *
			 * @param array<string,mixed> $state Final state.
			 */
			do_action( 'weargraph_catalog_scan_done', $state );
			return;
		}

		Queue::enqueue_now( self::HOOK_BATCH, array( 'run' => $run_id ), Queue::GROUP_CATALOG );
	}

	/**
	 * Debounced rescan of one product (product saved).
	 *
	 * @param int $product_id Product.
	 */
	public function queue_product( $product_id ): void {
		$product_id = (int) $product_id;
		if ( $product_id <= 0 || Queue::is_pending( self::HOOK_ONE, array( 'product_id' => $product_id ), Queue::GROUP_CATALOG ) ) {
			return;
		}

		Queue::enqueue_at( time() + self::DEBOUNCE, self::HOOK_ONE, array( 'product_id' => $product_id ), Queue::GROUP_CATALOG );
	}

	/**
	 * A variation changed: rescan its parent.
	 *
	 * @param int $variation_id Variation.
	 */
	public function queue_variation( $variation_id ): void {
		$parent = (int) wp_get_post_parent_id( (int) $variation_id );
		if ( $parent ) {
			$this->queue_product( $parent );
		}
	}

	/**
	 * Restored from trash.
	 *
	 * @param int $post_id Post.
	 */
	public function queue_if_product( $post_id ): void {
		if ( 'product' === get_post_type( (int) $post_id ) ) {
			$this->queue_product( (int) $post_id );
		}
	}

	/**
	 * Trashed or deleted: its readiness rows go (blueprint §72 "deleted product becomes inactive").
	 *
	 * @param int $post_id Post.
	 */
	public function forget_if_product( $post_id ): void {
		if ( 'product' === get_post_type( (int) $post_id ) ) {
			$this->repo->delete_product( (int) $post_id );
		}
	}

	/**
	 * Stored state.
	 *
	 * @return array<string,mixed>
	 */
	private function state(): array {
		$state = get_option( self::OPTION, array() );

		return is_array( $state ) && isset( $state['run'] ) ? $state : array(
			'run'       => '',
			'status'    => 'never',
			'processed' => 0,
			'total'     => 0,
		);
	}

	/**
	 * Persist state (not autoloaded).
	 *
	 * @param array<string,mixed> $state State.
	 */
	private function save( array $state ): void {
		update_option( self::OPTION, $state, false );
	}
}

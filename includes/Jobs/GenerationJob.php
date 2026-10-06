<?php
/**
 * Background worker for one generation.
 *
 * @package WearGraph
 */

namespace WearGraph\Jobs;

defined( 'ABSPATH' ) || exit;

use WearGraph\Database\Repositories\AssetsRepository;
use WearGraph\Database\Repositories\GenerationsRepository;
use WearGraph\Database\Repositories\UsageLedgerRepository;
use WearGraph\Domain\CostGuard;
use WearGraph\Domain\GenerationState;
use WearGraph\Generation\GenerationException;
use WearGraph\Generation\KindInterface;
use WearGraph\Generation\KindRegistry;
use WearGraph\Generation\LongRunningKind;
use WearGraph\Providers\CostEstimating;
use WearGraph\Providers\ProviderException;
use WearGraph\Providers\ProviderInterface;
use WearGraph\Providers\ProviderOutput;
use WearGraph\Providers\ProviderRegistry;
use WearGraph\Services\GenerationService;
use WearGraph\Storage\ImageRefResolver;
use WearGraph\Storage\PrivateStore;
use WearGraph\Support\Logger;
use WearGraph\Transport\LocalTransport;

/**
 * QUEUED|RETRYING → ROUTING → GENERATING → QUALITY_CHECK → READY, or FAILED /
 * CANCELLED. Every step is a validated, audited transition. A result that finishes
 * after the generation was cancelled is discarded and never counted (rule 6).
 */
final class GenerationJob {

	public const HOOK = 'weargraph_run_generation';

	public const POLL_HOOK = 'weargraph_poll_generation';

	private const PROMPT_VERSION = 'v1';

	/**
	 * Kinds.
	 *
	 * @var KindRegistry
	 */
	private $kinds;

	/**
	 * Providers.
	 *
	 * @var ProviderRegistry
	 */
	private $providers;

	/**
	 * Generations.
	 *
	 * @var GenerationsRepository
	 */
	private $generations;

	/**
	 * Assets.
	 *
	 * @var AssetsRepository
	 */
	private $assets;

	/**
	 * Usage ledger.
	 *
	 * @var UsageLedgerRepository
	 */
	private $ledger;

	/**
	 * Resolver.
	 *
	 * @var ImageRefResolver
	 */
	private $resolver;

	/**
	 * Service (cost guard, spend).
	 *
	 * @var GenerationService
	 */
	private $service;

	/**
	 * Constructor.
	 *
	 * @param KindRegistry          $kinds       Kinds.
	 * @param ProviderRegistry      $providers   Providers.
	 * @param GenerationsRepository $generations Generations.
	 * @param AssetsRepository      $assets      Assets.
	 * @param UsageLedgerRepository $ledger      Ledger.
	 * @param ImageRefResolver      $resolver    Resolver.
	 * @param GenerationService     $service     Service.
	 */
	public function __construct( KindRegistry $kinds, ProviderRegistry $providers, GenerationsRepository $generations, AssetsRepository $assets, UsageLedgerRepository $ledger, ImageRefResolver $resolver, GenerationService $service ) {
		$this->kinds       = $kinds;
		$this->providers   = $providers;
		$this->generations = $generations;
		$this->assets      = $assets;
		$this->ledger      = $ledger;
		$this->resolver    = $resolver;
		$this->service     = $service;
	}

	/**
	 * Maximum provider attempts per request (first try + retries).
	 */
	public static function max_attempts(): int {
		/**
		 * Maximum provider attempts for one generation, including the first.
		 *
		 * @param int $attempts Default 2.
		 */
		return max( 1, (int) apply_filters( 'weargraph_generation_max_attempts', 2 ) );
	}

	/**
	 * Run one generation. Safe to call concurrently: only the claimer proceeds.
	 *
	 * @param int $id Generation id.
	 */
	public function run( int $id ): void {
		if ( ! $this->generations->claim( $id, array( GenerationState::QUEUED, GenerationState::RETRYING ), GenerationState::ROUTING ) ) {
			return;
		}

		$row  = $this->generations->find( $id );
		$kind = $this->kinds->get( (string) $row['kind'] );
		if ( ! $kind ) {
			$this->fail( $id, 'unknown_kind', esc_html__( 'This kind of generation is no longer available.', 'weargraph' ) );
			return;
		}

		$spec = json_decode( (string) $row['input_json'], true );
		try {
			$input = $kind->build( is_array( $spec ) ? $spec : array(), $this->resolver );
		} catch ( \InvalidArgumentException $e ) {
			$this->fail( $id, 'bad_input', esc_html__( 'An image for this request is no longer available.', 'weargraph' ) );
			return;
		}

		$pin = is_array( $spec ) ? (string) ( $spec['_provider'] ?? '' ) : '';
		try {
			// A request pinned to one provider (Pro's sandbox) is never routed elsewhere.
			$provider = '' !== $pin ? $this->service->pinned( $pin, $kind->capability() ) : $this->providers->resolve( $kind->capability(), $input );
		} catch ( ProviderException $e ) {
			$this->fail( $id, $e->error_code(), $e->getMessage() );
			return;
		} catch ( GenerationException $e ) {
			$this->fail( $id, $e->error_code(), $e->getMessage() );
			return;
		}

		$estimate = $provider instanceof CostEstimating ? $provider->estimate_cost( $input ) : 0.0;
		$verdict  = $this->service->cost_guard()->check( $estimate, (float) $row['cost_usd'], $this->service->spent_today() );
		if ( CostGuard::OK !== $verdict ) {
			$this->fail( $id, 'cost_limit', esc_html__( 'Stopped: this request reached the spending limit.', 'weargraph' ) );
			return;
		}

		$attempt = (int) $row['attempts'] + 1;
		if ( ! $this->generations->transition(
			$id,
			GenerationState::GENERATING,
			array(
				'provider' => $provider->id(),
				'attempts' => $attempt,
			),
			array( 'attempt' => $attempt )
		) ) {
			return; // Cancelled while routing.
		}

		if ( $kind instanceof LongRunningKind ) {
			$this->start_long_running( $id, $row, $kind, $provider, $input, $attempt );
			return;
		}

		try {
			$output = $kind->run( $provider, $input );
		} catch ( ProviderException $e ) {
			$this->handle_provider_error( $id, $row, $e, $attempt );
			return;
		} catch ( \Throwable $e ) {
			Logger::error(
				'Generation crashed',
				array(
					'id'    => $id,
					'error' => $e->getMessage(),
				)
			);
			$this->fail( $id, ProviderException::SERVER, esc_html__( 'Something went wrong while generating. Please try again.', 'weargraph' ) );
			return;
		}

		$this->finish( $id, $kind, $output );
	}

	/**
	 * Start a long-running kind and schedule the first poll.
	 *
	 * @param int                 $id       Generation id.
	 * @param array<string,mixed> $row      Row at claim time.
	 * @param LongRunningKind     $kind     Kind.
	 * @param ProviderInterface   $provider Provider.
	 * @param object              $input    Input DTO.
	 * @param int                 $attempt  Attempt number.
	 */
	private function start_long_running( int $id, array $row, LongRunningKind $kind, ProviderInterface $provider, object $input, int $attempt ): void {
		try {
			$operation = $kind->start( $provider, $input );
		} catch ( ProviderException $e ) {
			$this->handle_provider_error( $id, $row, $e, $attempt );
			return;
		} catch ( \Throwable $e ) {
			Logger::error(
				'Generation crashed',
				array(
					'id'    => $id,
					'error' => $e->getMessage(),
				)
			);
			$this->fail( $id, ProviderException::SERVER, esc_html__( 'Something went wrong while generating. Please try again.', 'weargraph' ) );
			return;
		}

		$this->schedule_poll( $id, (string) $row['context'], $operation, time(), $kind->poll_interval() );
	}

	/**
	 * Queue the next poll.
	 *
	 * @param int    $id        Generation id.
	 * @param string $context   Context (queue group).
	 * @param string $operation Operation id.
	 * @param int    $started   When the work started.
	 * @param int    $delay     Seconds from now.
	 */
	private function schedule_poll( int $id, string $context, string $operation, int $started, int $delay ): void {
		Queue::enqueue_at(
			time() + max( 1, $delay ),
			self::POLL_HOOK,
			array(
				'id'        => $id,
				'operation' => $operation,
				'started'   => $started,
			),
			LocalTransport::group_for( $context )
		);
	}

	/**
	 * Check a long-running generation once (POLL_HOOK). Stops when the generation is no
	 * longer GENERATING (cancelled): the provider may still charge, so the estimate is
	 * recorded as spend that does not count toward quota.
	 *
	 * @param int    $id        Generation id.
	 * @param string $operation Operation id.
	 * @param int    $started   When the work started.
	 */
	public function poll( int $id, string $operation, int $started ): void {
		$row  = $this->generations->find( $id );
		$kind = $row ? $this->kinds->get( (string) $row['kind'] ) : null;
		if ( ! $row || ! $kind instanceof LongRunningKind ) {
			return;
		}

		$spec     = json_decode( (string) $row['input_json'], true );
		$provider = $this->providers->get( (string) $row['provider'] );
		try {
			$input = $kind->build( is_array( $spec ) ? $spec : array(), $this->resolver );
		} catch ( \InvalidArgumentException $e ) {
			$input = null;
		}

		if ( GenerationState::GENERATING !== $row['state'] ) {
			if ( GenerationState::CANCELLED === $row['state'] && $input && $provider instanceof CostEstimating ) {
				$this->ledger->record( $id, $kind->id(), false, $provider->estimate_cost( $input ), (string) $row['provider'], (string) $row['model'] );
			}
			return;
		}
		if ( ! $provider || ! $input ) {
			$this->fail( $id, 'bad_input', esc_html__( 'This request can no longer be completed.', 'weargraph' ) );
			return;
		}

		$timed_out = time() - $started >= $kind->max_wait();
		try {
			$output = $kind->poll( $provider, $input, $operation );
		} catch ( ProviderException $e ) {
			// A hiccup while checking is not a failed generation: check again.
			if ( $e->is_retryable() && ! $timed_out ) {
				$this->schedule_poll( $id, (string) $row['context'], $operation, $started, $kind->poll_interval() );
				return;
			}
			$this->fail( $id, $e->error_code(), $e->getMessage() );
			return;
		} catch ( \Throwable $e ) {
			Logger::error(
				'Generation poll crashed',
				array(
					'id'    => $id,
					'error' => $e->getMessage(),
				)
			);
			$this->fail( $id, ProviderException::SERVER, esc_html__( 'Something went wrong while generating. Please try again.', 'weargraph' ) );
			return;
		}

		if ( null === $output ) {
			if ( $timed_out ) {
				$this->fail( $id, ProviderException::TIMEOUT, esc_html__( 'The AI provider took too long. Please try again later.', 'weargraph' ) );
				return;
			}
			$this->schedule_poll( $id, (string) $row['context'], $operation, $started, $kind->poll_interval() );
			return;
		}

		$output->latency_ms = max( $output->latency_ms, ( time() - $started ) * 1000 );
		$this->finish( $id, $kind, $output );
	}

	/**
	 * Retry (with backoff, bounded by attempts and cost) or fail.
	 *
	 * @param int                 $id      Generation id.
	 * @param array<string,mixed> $row     Row at claim time.
	 * @param ProviderException   $e       Error.
	 * @param int                 $attempt Attempt number just made.
	 */
	private function handle_provider_error( int $id, array $row, ProviderException $e, int $attempt ): void {
		if ( $e->is_retryable() && $attempt < self::max_attempts() ) {
			$moved = $this->generations->transition(
				$id,
				GenerationState::RETRYING,
				array(),
				array(
					'error'   => $e->error_code(),
					'attempt' => $attempt,
				)
			);
			if ( $moved ) {
				$delay = (int) min( 60, 5 * ( 2 ** ( $attempt - 1 ) ) );
				Queue::enqueue_at( time() + $delay, self::HOOK, array( 'id' => $id ), LocalTransport::group_for( (string) $row['context'] ) );
			}
			return;
		}

		$this->fail( $id, $e->error_code(), $e->getMessage() );
	}

	/**
	 * Record cost, run the quality hook, store outputs, mark READY.
	 *
	 * @param int            $id     Generation id.
	 * @param KindInterface  $kind   Kind.
	 * @param ProviderOutput $output Provider output.
	 */
	private function finish( int $id, KindInterface $kind, ProviderOutput $output ): void {
		$row  = $this->generations->find( $id );
		$cost = round( (float) $row['cost_usd'] + $output->cost_usd, 5 );

		$this->generations->update(
			$id,
			array(
				'cost_usd'   => $cost,
				'latency_ms' => $output->latency_ms,
				'model'      => $output->model,
				'tokens_in'  => $output->tokens_in,
				'tokens_out' => $output->tokens_out,
			)
		);

		if ( ! $this->generations->transition( $id, GenerationState::QUALITY_CHECK ) ) {
			$this->discard_if_cancelled( $id, $kind, $cost, $output );
			return;
		}

		/**
		 * Evaluate a generated result before anyone sees it. Return null to accept
		 * (free default), or an array with at least 'decision' => accept|retry|reject
		 * plus any scores; it is stored as quality_json. Pro's QualityGate (P7) hooks here.
		 * An evaluator that pays for its check adds that cost to the generation's cost_usd;
		 * it is then included in the usage ledger with the generation.
		 *
		 * @param array|null          $verdict Null = accept.
		 * @param array<string,mixed> $row     Generation row.
		 * @param ProviderOutput      $output  Provider output.
		 */
		$verdict  = apply_filters( 'weargraph_quality_evaluate', null, $this->generations->find( $id ), $output );
		$decision = is_array( $verdict ) ? (string) ( $verdict['decision'] ?? 'accept' ) : 'accept';
		$quality  = is_array( $verdict ) ? wp_json_encode( $verdict ) : null;

		// A quality check may add its own (evaluation) cost to the generation.
		$cost = round( (float) $this->generations->find( $id )['cost_usd'], 5 );

		if ( 'retry' === $decision && (int) $row['attempts'] < self::max_attempts() ) {
			$this->generations->transition( $id, GenerationState::RETRYING, array( 'quality_json' => $quality ), array( 'quality' => 'retry' ) );
			Queue::enqueue_now( self::HOOK, array( 'id' => $id ), LocalTransport::group_for( (string) $row['context'] ) );
			return;
		}
		if ( 'reject' === $decision || 'retry' === $decision ) {
			$this->fail( $id, 'quality', esc_html__( 'The result did not pass the quality check. Please try another photo.', 'weargraph' ), $quality );
			return;
		}

		// Image and video outputs go to the private store; structured outputs (alt text…) to output_json.
		$media     = $output->images || $output->videos;
		$asset_ids = $media ? $this->store_outputs( $row, $kind, $output ) : array();
		if ( $media && ! $asset_ids ) {
			$this->fail( $id, ProviderException::SERVER, esc_html__( 'The result could not be saved. Please check that the uploads folder is writable.', 'weargraph' ) );
			return;
		}
		if ( ! $asset_ids && ! $output->data ) {
			$this->fail( $id, ProviderException::NO_OUTPUT, esc_html__( 'The AI provider returned an empty result. Please try again.', 'weargraph' ) );
			return;
		}

		$ready = $this->generations->transition(
			$id,
			GenerationState::READY,
			array(
				'output_asset_ids' => $asset_ids ? wp_json_encode( $asset_ids ) : null,
				'output_json'      => $output->data ? wp_json_encode( $output->data ) : null,
				'quality_json'     => $quality,
			)
		);

		if ( ! $ready ) {
			$this->delete_assets( $asset_ids );
			$this->discard_if_cancelled( $id, $kind, $cost, $output );
			return;
		}

		$this->ledger->record( $id, $kind->id(), true, $cost, $output->provider, $output->model );

		/**
		 * A generation finished successfully.
		 *
		 * @param int $id Generation id.
		 */
		do_action( 'weargraph_generation_ready', $id );
	}

	/**
	 * Write output images to the private store.
	 *
	 * @param array<string,mixed> $row    Generation row.
	 * @param KindInterface       $kind   Kind.
	 * @param ProviderOutput      $output Output.
	 * @return int[] Asset ids.
	 */
	private function store_outputs( array $row, KindInterface $kind, ProviderOutput $output ): array {
		$spec = json_decode( (string) $row['input_json'], true );
		$ids  = array();

		foreach ( array_merge( $output->images, $output->videos ) as $image ) {
			$file = PrivateStore::write( (string) $image['bytes'], (string) $image['mime'] );
			if ( ! $file ) {
				continue;
			}

			$asset = $this->assets->create(
				array(
					'type'            => $kind->output_asset_type(),
					'path'            => $file['path'],
					'generation_id'   => (int) $row['id'],
					'session_id'      => $row['session_id'],
					'sha256'          => $file['sha256'],
					'width'           => $file['width'],
					'height'          => $file['height'],
					'mime'            => (string) $image['mime'],
					'bytes'           => $file['bytes'],
					'expires_at'      => $row['expires_at'],
					'provenance_json' => array(
						'kind'           => $kind->id(),
						'prompt_version' => self::PROMPT_VERSION,
						'provider'       => $output->provider,
						'model'          => $output->model,
						'request_id'     => $output->provider_request_id,
						'references'     => is_array( $spec ) ? ( $spec['references'] ?? ( $spec['garments'] ?? array() ) ) : array(),
						'options'        => is_array( $spec ) ? ( $spec['options'] ?? array() ) : array(),
						'cost_usd'       => $output->cost_usd,
					),
				)
			);
			$ids[] = (int) $asset['id'];
		}

		return $ids;
	}

	/**
	 * When a transition lost because the generation was cancelled, record the real
	 * cost as not counting toward quota and drop the output.
	 *
	 * @param int            $id     Generation id.
	 * @param KindInterface  $kind   Kind.
	 * @param float          $cost   Total cost.
	 * @param ProviderOutput $output Output.
	 */
	private function discard_if_cancelled( int $id, KindInterface $kind, float $cost, ProviderOutput $output ): void {
		$row = $this->generations->find( $id );
		if ( $row && GenerationState::CANCELLED === $row['state'] ) {
			$this->ledger->record( $id, $kind->id(), false, $cost, $output->provider, $output->model );
		}
	}

	/**
	 * Move to FAILED and record the (uncounted) cost.
	 *
	 * @param int         $id      Generation id.
	 * @param string      $code    Error code.
	 * @param string      $message Escaped, user-safe message.
	 * @param string|null $quality Quality JSON.
	 */
	private function fail( int $id, string $code, string $message, ?string $quality = null ): void {
		$moved = $this->generations->transition(
			$id,
			GenerationState::FAILED,
			array(
				'error_code'    => substr( $code, 0, 32 ),
				'error_message' => substr( $message, 0, 255 ),
				'quality_json'  => $quality,
			),
			array( 'error' => $code )
		);

		if ( $moved ) {
			$row = $this->generations->find( $id );
			$this->ledger->record( $id, (string) $row['kind'], false, (float) $row['cost_usd'], (string) $row['provider'], (string) $row['model'] );

			/**
			 * A generation failed for good (after any retries).
			 *
			 * @param int    $id   Generation id.
			 * @param string $code Error code.
			 */
			do_action( 'weargraph_generation_failed', $id, $code );
		}
	}

	/**
	 * Delete stored outputs (used when a result is discarded).
	 *
	 * @param int[] $asset_ids Asset ids.
	 */
	private function delete_assets( array $asset_ids ): void {
		foreach ( $this->assets->find_many( $asset_ids ) as $asset ) {
			PrivateStore::delete( (string) $asset['path'] );
			$this->assets->mark_deleted( (int) $asset['id'] );
		}
	}
}

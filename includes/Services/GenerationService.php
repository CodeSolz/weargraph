<?php
/**
 * Creates, cancels and presents generations.
 *
 * @package WearGraph
 */

namespace WearGraph\Services;

defined( 'ABSPATH' ) || exit;

use WearGraph\Database\Repositories\AssetsRepository;
use WearGraph\Database\Repositories\GenerationsRepository;
use WearGraph\Domain\CostGuard;
use WearGraph\Domain\GenerationState;
use WearGraph\Domain\IdempotencyKey;
use WearGraph\Generation\GenerationException;
use WearGraph\Generation\KindRegistry;
use WearGraph\Privacy\Retention;
use WearGraph\Providers\CostEstimating;
use WearGraph\Providers\ProviderException;
use WearGraph\Providers\ProviderRegistry;
use WearGraph\Storage\ImageRefResolver;
use WearGraph\Storage\PrivateStore;
use WearGraph\Storage\SignedUrl;
use WearGraph\Support\Settings;
use WearGraph\Transport\LocalTransport;
use WearGraph\Transport\TransportInterface;

/**
 * create() does everything that must happen inside the web request — validation,
 * idempotency lookup, cost guard, persistence, enqueue — and returns immediately.
 * The provider is only ever called from a background job (rule 10).
 */
final class GenerationService {

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
	 * Image resolver.
	 *
	 * @var ImageRefResolver
	 */
	private $resolver;

	/**
	 * Constructor.
	 *
	 * @param KindRegistry          $kinds       Kinds.
	 * @param ProviderRegistry      $providers   Providers.
	 * @param GenerationsRepository $generations Generations.
	 * @param AssetsRepository      $assets      Assets.
	 * @param ImageRefResolver      $resolver    Image resolver.
	 */
	public function __construct( KindRegistry $kinds, ProviderRegistry $providers, GenerationsRepository $generations, AssetsRepository $assets, ImageRefResolver $resolver ) {
		$this->kinds       = $kinds;
		$this->providers   = $providers;
		$this->generations = $generations;
		$this->assets      = $assets;
		$this->resolver    = $resolver;
	}

	/**
	 * Create and enqueue a generation.
	 *
	 * @param string              $kind_id Kind id.
	 * @param array<string,mixed> $raw     Raw spec.
	 * @param array<string,mixed> $ctx     context (studio|storefront|sandbox), product_id, variant_id,
	 *                                     session_id, user_id, mode, bypass_cache, provider (pin one
	 *                                     configured provider instead of routing, e.g. Pro's sandbox).
	 * @return array{id:string,status:string,cached:bool}
	 * @throws GenerationException When refused before starting.
	 */
	public function create( string $kind_id, array $raw, array $ctx = array() ): array {
		$ctx = array_merge(
			array(
				'context'      => 'studio',
				'product_id'   => 0,
				'variant_id'   => 0,
				'session_id'   => '',
				'user_id'      => 0,
				'mode'         => 'auto',
				'bypass_cache' => false,
				'provider'     => '',
			),
			$ctx
		);

		list( $kind, $spec, $input, $provider ) = $this->prepare( $kind_id, $raw );

		// A pinned provider is kept with the request, so the job runs it too.
		$pin = sanitize_key( (string) $ctx['provider'] );
		if ( '' !== $pin ) {
			$provider = $this->pinned( $pin, $kind->capability() );
			$spec     = array_merge( $spec, array( '_provider' => $pin ) );
		}

		$target = (string) ( $ctx['variant_id'] ? $ctx['variant_id'] : $ctx['product_id'] );
		// Private shopper output is never reused across sessions (§28).
		$scope = get_current_blog_id() . ':' . ( 'storefront' === $ctx['context'] ? (string) $ctx['session_id'] : $ctx['context'] );
		$key   = IdempotencyKey::compute(
			$scope,
			'',
			$target,
			'',
			(string) $ctx['mode'],
			array(
				'kind' => $kind_id,
				'spec' => $kind->cache_settings( $spec, $this->resolver ),
			) + ( '' !== $pin ? array( 'provider' => $pin ) : array() )
		);

		if ( ! $ctx['bypass_cache'] ) {
			$existing = $this->generations->find_reusable( $key );
			if ( $existing && $this->outputs_exist( $existing ) ) {
				/**
				 * A request was answered from an earlier identical result (no provider call).
				 *
				 * @param array<string,mixed> $existing Reused generation row.
				 */
				do_action( 'weargraph_generation_reused', $existing );

				return array(
					'id'     => (string) $existing['public_id'],
					'status' => 'ready',
					'cached' => true,
				);
			}
		}

		$estimate = $provider instanceof CostEstimating ? $provider->estimate_cost( $input ) : 0.0;
		$verdict  = $this->cost_guard()->check( $estimate, 0.0, $this->spent_today() );
		if ( CostGuard::OK !== $verdict ) {
			throw new GenerationException(
				'cost_limit',
				CostGuard::OVER_DAILY_CAP === $verdict
					? esc_html__( 'The daily AI spending limit for this store has been reached. Please try again tomorrow.', 'weargraph' )
					: esc_html__( 'This request would cost more than the per-request limit set in WearGraph → Settings.', 'weargraph' ),
				429
			);
		}

		$row = $this->generations->create(
			array(
				'kind'              => $kind_id,
				'context'           => (string) $ctx['context'],
				'product_id'        => $ctx['product_id'] ? (int) $ctx['product_id'] : null,
				'variant_id'        => $ctx['variant_id'] ? (int) $ctx['variant_id'] : null,
				'session_id'        => '' !== $ctx['session_id'] ? (string) $ctx['session_id'] : null,
				'user_id'           => $ctx['user_id'] ? (int) $ctx['user_id'] : null,
				'idempotency_key'   => $key,
				'input_json'        => wp_json_encode( $spec ),
				'provider'          => $provider->id(),
				'cost_estimate_usd' => $estimate,
				'expires_at'        => gmdate( 'Y-m-d H:i:s', time() + $this->ttl( (string) $ctx['context'] ) ),
			)
		);

		$id = (int) $row['id'];
		$this->generations->transition( $id, GenerationState::VALIDATING_INPUT );
		$this->generations->transition( $id, GenerationState::PREPROCESSING );
		$this->generations->transition( $id, GenerationState::QUEUED );

		$this->transport( $row )->submit( $this->generations->find( $id ) );

		return array(
			'id'     => (string) $row['public_id'],
			'status' => 'queued',
			'cached' => false,
		);
	}

	/**
	 * What a request would cost with the provider that would run it, without starting it
	 * (e.g. Pro's batch campaign estimate). 0 when the provider gives no estimate.
	 *
	 * @param string              $kind_id Kind id.
	 * @param array<string,mixed> $raw     Request spec.
	 * @throws GenerationException When the request is invalid or no provider can run it.
	 */
	public function estimate( string $kind_id, array $raw ): float {
		list( , , $input, $provider ) = $this->prepare( $kind_id, $raw );

		return $provider instanceof CostEstimating ? (float) $provider->estimate_cost( $input ) : 0.0;
	}

	/**
	 * A pinned provider, if it is set up and can do this.
	 *
	 * @param string $id         Provider id.
	 * @param string $capability Capability.
	 * @throws GenerationException When it cannot be used.
	 */
	public function pinned( string $id, string $capability ): \WearGraph\Providers\ProviderInterface {
		$provider = $this->providers->get( $id );
		if ( ! $provider || ! $provider->is_configured() || ! ProviderRegistry::supports( $provider, $capability ) ) {
			throw new GenerationException( 'not_configured', esc_html__( 'That AI provider is not set up for this.', 'weargraph' ), 409 );
		}

		return $provider;
	}

	/**
	 * Validate a request and pick its provider.
	 *
	 * @param string              $kind_id Kind id.
	 * @param array<string,mixed> $raw     Request spec.
	 * @return array{0:\WearGraph\Generation\KindInterface,1:array<string,mixed>,2:object,3:\WearGraph\Providers\ProviderInterface}
	 * @throws GenerationException When refused.
	 */
	private function prepare( string $kind_id, array $raw ): array {
		$kind = $this->kinds->get( $kind_id );
		if ( ! $kind ) {
			throw new GenerationException( 'unknown_kind', esc_html__( 'This kind of generation is not available.', 'weargraph' ) );
		}

		try {
			$spec  = $kind->normalize_spec( $raw );
			$input = $kind->build( $spec, $this->resolver );
		} catch ( \InvalidArgumentException $e ) {
			throw new GenerationException( 'bad_input', esc_html__( 'The request is incomplete or one of its images could not be found.', 'weargraph' ) );
		}

		try {
			$provider = $this->providers->resolve( $kind->capability(), $input );
		} catch ( ProviderException $e ) {
			$refused = new GenerationException( $e->error_code(), $e->getMessage(), 409 );
			throw $refused; // ProviderException messages are already escaped.
		}

		return array( $kind, $spec, $input, $provider );
	}

	/**
	 * Cancel a generation that has not finished. A running job discards its result
	 * (and it never counts toward quota).
	 *
	 * @param array<string,mixed> $row Generation row.
	 */
	public function cancel( array $row ): bool {
		if ( GenerationState::is_terminal( (string) $row['state'] ) ) {
			return false;
		}

		return $this->generations->transition( (int) $row['id'], GenerationState::CANCELLED, array(), array( 'by' => get_current_user_id() ) );
	}

	/**
	 * Run a finished generation again with identical input (new provider call).
	 *
	 * @param array<string,mixed> $row Generation row.
	 * @return array{id:string,status:string,cached:bool}
	 * @throws GenerationException When refused.
	 */
	public function rerun( array $row ): array {
		$spec = json_decode( (string) $row['input_json'], true );

		return $this->create(
			(string) $row['kind'],
			is_array( $spec ) ? $spec : array(),
			array(
				'context'      => (string) $row['context'],
				'product_id'   => (int) $row['product_id'],
				'variant_id'   => (int) $row['variant_id'],
				'session_id'   => (string) $row['session_id'],
				'user_id'      => get_current_user_id(),
				'bypass_cache' => true,
			)
		);
	}

	/**
	 * Delete a generation and its private outputs (cancels it first if running).
	 * Attachments already saved to the Media Library are the merchant's and stay.
	 *
	 * @param array<string,mixed> $row Generation row.
	 */
	public function delete( array $row ): void {
		$this->cancel( $row );

		$ids = json_decode( (string) $row['output_asset_ids'], true );
		foreach ( $this->assets->find_many( is_array( $ids ) ? $ids : array() ) as $asset ) {
			PrivateStore::delete( (string) $asset['path'] );
			$this->assets->delete( (int) $asset['id'] );
		}

		$this->generations->delete( (int) $row['id'] );
	}

	/**
	 * Client-facing representation.
	 *
	 * @param array<string,mixed> $row        Generation row.
	 * @param bool                $with_costs Include cost and provider details (merchant only).
	 * @return array<string,mixed>
	 */
	public function present( array $row, bool $with_costs = false ): array {
		$state   = (string) $row['state'];
		$outputs = array();

		if ( GenerationState::READY === $state ) {
			$ids = json_decode( (string) $row['output_asset_ids'], true );
			foreach ( $this->assets->find_many( is_array( $ids ) ? $ids : array() ) as $asset ) {
				$outputs[] = array(
					'id'     => (string) $asset['public_id'],
					'url'    => SignedUrl::for_asset( (string) $asset['public_id'], (string) $asset['session_id'] ),
					'width'  => (int) $asset['width'],
					'height' => (int) $asset['height'],
				);
			}
		}

		$result = GenerationState::READY === $state ? json_decode( (string) ( $row['output_json'] ?? '' ), true ) : null;

		$data = array(
			'id'         => (string) $row['public_id'],
			'kind'       => (string) $row['kind'],
			'status'     => GenerationState::public_status( $state ),
			'outputs'    => $outputs,
			'data'       => is_array( $result ) ? $result : null,
			'error'      => GenerationState::FAILED === $state ? array(
				'code'    => (string) $row['error_code'],
				'message' => (string) $row['error_message'],
			) : null,
			'created_at' => mysql_to_rfc3339( (string) $row['created_at'] ),
		);

		if ( $with_costs ) {
			$spec                  = json_decode( (string) $row['input_json'], true );
			$data['meta']          = is_array( $spec ) && isset( $spec['meta'] ) && is_array( $spec['meta'] ) ? $spec['meta'] : array();
			$data['state']         = $state;
			$data['provider']      = (string) $row['provider'];
			$data['model']         = (string) $row['model'];
			$data['cost_usd']      = (float) $row['cost_usd'];
			$data['cost_estimate'] = (float) $row['cost_estimate_usd'];
			$data['latency_ms']    = (int) $row['latency_ms'];
			$data['attempts']      = (int) $row['attempts'];
		}

		/**
		 * Client-facing generation data (Pro adds a shopper-safe quality summary). Never
		 * add provider details, costs or internal ids unless $with_costs is true.
		 *
		 * @param array<string,mixed> $data       Data.
		 * @param array<string,mixed> $row        Generation row.
		 * @param bool                $with_costs Merchant view.
		 */
		return (array) apply_filters( 'weargraph_generation_present', $data, $row, $with_costs );
	}

	/**
	 * Cost guard from settings.
	 */
	public function cost_guard(): CostGuard {
		return new CostGuard( (float) Settings::get( 'max_cost_per_request_usd', 0.5 ), (float) Settings::get( 'daily_cap_usd', 10 ) );
	}

	/**
	 * Store spend since local midnight.
	 */
	public function spent_today(): float {
		return $this->generations->spent_since( get_gmt_from_date( wp_date( 'Y-m-d 00:00:00' ) ) );
	}

	/**
	 * Seconds a generation's outputs are kept.
	 *
	 * @param string $context studio|storefront|sandbox.
	 */
	private function ttl( string $context ): int {
		if ( 'storefront' === $context ) {
			$ttl = Retention::storefront_seconds();
		} else {
			$ttl = 30 * DAY_IN_SECONDS; // Studio drafts; saving to the Media Library copies them out.
		}

		/**
		 * How long generated outputs are kept.
		 *
		 * @param int    $ttl     Seconds.
		 * @param string $context studio|storefront|sandbox.
		 */
		return (int) apply_filters( 'weargraph_generation_ttl', $ttl, $context );
	}

	/**
	 * Whether a READY row's output files still exist.
	 *
	 * @param array<string,mixed> $row Generation row.
	 */
	private function outputs_exist( array $row ): bool {
		$ids = json_decode( (string) $row['output_asset_ids'], true );

		return is_array( $ids ) && $ids && count( $this->assets->find_many( $ids ) ) === count( $ids );
	}

	/**
	 * Transport for a generation.
	 *
	 * @param array<string,mixed> $row Generation row.
	 */
	private function transport( array $row ): TransportInterface {
		/**
		 * Register transports. WearGraph ships "local" (runs in this WordPress); an extension may add others.
		 *
		 * @param array<string,TransportInterface> $transports id => transport.
		 */
		$transports = (array) apply_filters( 'weargraph_transports', array( 'local' => new LocalTransport() ) );

		/**
		 * Choose the transport for a generation.
		 *
		 * @param string              $id  Transport id.
		 * @param array<string,mixed> $row Generation row.
		 */
		$id = (string) apply_filters( 'weargraph_active_transport', 'local', $row );

		$transport = $transports[ $id ] ?? null;

		return $transport instanceof TransportInterface ? $transport : new LocalTransport();
	}
}

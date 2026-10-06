<?php
/**
 * Registered AI providers and per-request provider resolution.
 *
 * @package WearGraph
 */

namespace WearGraph\Providers;

defined( 'ABSPATH' ) || exit;

use WearGraph\Support\Settings;

/**
 * Providers come from the weargraph_providers filter (Pro adds more there). Each task
 * (Studio images, try-on, image understanding) has its own provider: the one assigned
 * in Settings when it is connected and able, else the main (Studio) provider when able,
 * else the first connected provider that can do it. The weargraph_resolve_provider
 * filter gets that default; Pro's router hooks it.
 */
final class ProviderRegistry {

	/**
	 * Map of capability id => interface the provider must implement for it.
	 */
	private const CAPABILITY_INTERFACES = array(
		ProviderInterface::CAP_IMAGE_GENERATE => ImageGenerationCapable::class,
		ProviderInterface::CAP_IMAGE_EDIT     => ImageEditCapable::class,
		ProviderInterface::CAP_TRYON          => TryOnCapable::class,
		ProviderInterface::CAP_VIDEO          => VideoCapable::class,
		ProviderInterface::CAP_VISION         => VisionCapable::class,
	);

	/**
	 * Settings key holding the assigned provider, per task.
	 */
	public const TASK_SETTINGS = array(
		ProviderInfo::TASK_STUDIO => 'provider',
		ProviderInfo::TASK_TRYON  => 'provider_tryon',
		ProviderInfo::TASK_VISION => 'provider_vision',
	);

	/**
	 * Task per capability.
	 */
	private const CAPABILITY_TASKS = array(
		ProviderInterface::CAP_IMAGE_GENERATE => ProviderInfo::TASK_STUDIO,
		ProviderInterface::CAP_IMAGE_EDIT     => ProviderInfo::TASK_STUDIO,
		ProviderInterface::CAP_TRYON          => ProviderInfo::TASK_TRYON,
		ProviderInterface::CAP_VISION         => ProviderInfo::TASK_VISION,
	);

	/**
	 * Capability a task needs.
	 */
	private const TASK_CAPABILITY = array(
		ProviderInfo::TASK_STUDIO => ProviderInterface::CAP_IMAGE_GENERATE,
		ProviderInfo::TASK_TRYON  => ProviderInterface::CAP_TRYON,
		ProviderInfo::TASK_VISION => ProviderInterface::CAP_VISION,
	);

	/**
	 * Built providers by id (lazy).
	 *
	 * @var array<string,ProviderInterface>|null
	 */
	private $providers = null;

	/**
	 * Built-in provider classes. Instantiated lazily so the filter can replace them.
	 *
	 * @var array<string,string|ProviderInterface>
	 */
	private $defaults;

	/**
	 * Constructor.
	 *
	 * @param array<string,string|ProviderInterface> $defaults id => class name or instance.
	 */
	public function __construct( array $defaults ) {
		$this->defaults = $defaults;
	}

	/**
	 * All valid registered providers.
	 *
	 * @return array<string,ProviderInterface>
	 */
	public function all(): array {
		if ( null !== $this->providers ) {
			return $this->providers;
		}

		/**
		 * Register AI providers.
		 *
		 * @param array<string,string|ProviderInterface> $providers id => class name (no-arg constructor) or instance.
		 */
		$registered = (array) apply_filters( 'weargraph_providers', $this->defaults );

		$this->providers = array();
		foreach ( $registered as $id => $provider ) {
			if ( is_string( $provider ) && class_exists( $provider ) ) {
				$provider = new $provider();
			}
			// Anything that is not a ProviderInterface, or whose id() disagrees with its key, is ignored.
			if ( $provider instanceof ProviderInterface && $provider->id() === $id ) {
				$this->providers[ $id ] = $provider;
			}
		}

		return $this->providers;
	}

	/**
	 * Providers that can be the store's main provider (they generate images). Add-on
	 * providers for other tasks only (e.g. video) are left out of the Settings choice.
	 *
	 * @return array<string,ProviderInterface>
	 */
	public function primary(): array {
		return array_filter(
			$this->all(),
			static function ( ProviderInterface $provider ): bool {
				return self::supports( $provider, ProviderInterface::CAP_IMAGE_GENERATE );
			}
		);
	}

	/**
	 * One provider by id.
	 *
	 * @param string $id Provider id.
	 */
	public function get( string $id ): ?ProviderInterface {
		$all = $this->all();

		return $all[ $id ] ?? null;
	}

	/**
	 * The merchant's configured provider (or the first registered one).
	 */
	public function configured(): ?ProviderInterface {
		$primary  = $this->primary();
		$provider = $primary[ (string) Settings::get( 'provider', '' ) ] ?? null;
		if ( $provider ) {
			return $provider;
		}

		$all = $primary ? $primary : $this->all();

		return $all ? reset( $all ) : null;
	}

	/**
	 * Providers able to do a task (Settings choices), connected or not.
	 *
	 * @param string $task ProviderInfo::TASK_*.
	 * @return array<string,ProviderInterface>
	 */
	public function able( string $task ): array {
		$capability = self::TASK_CAPABILITY[ $task ] ?? '';

		return array_filter(
			$this->all(),
			static function ( ProviderInterface $provider ) use ( $capability ): bool {
				return '' !== $capability && self::supports( $provider, $capability );
			}
		);
	}

	/**
	 * The provider for a task: assigned (connected and able) → main provider (connected
	 * and able) → first connected able provider → the configured provider as a last resort
	 * (resolve() then explains what is missing).
	 *
	 * @param string $task ProviderInfo::TASK_*.
	 */
	public function for_task( string $task ): ?ProviderInterface {
		$able = $this->able( $task );
		$ids  = array(
			(string) Settings::get( self::TASK_SETTINGS[ $task ] ?? 'provider', '' ),
			(string) Settings::get( 'provider', '' ),
		);
		foreach ( $ids as $id ) {
			if ( '' !== $id && isset( $able[ $id ] ) && $able[ $id ]->is_configured() ) {
				return $able[ $id ];
			}
		}
		foreach ( $able as $provider ) {
			if ( $provider->is_configured() ) {
				return $provider;
			}
		}
		$main = $this->configured();

		return $main && isset( $able[ $main->id() ] ) ? $main : ( $able ? reset( $able ) : $main );
	}

	/**
	 * Whether a provider supports a capability (declared AND implemented).
	 *
	 * @param ProviderInterface $provider   Provider.
	 * @param string            $capability Capability id.
	 */
	public static function supports( ProviderInterface $provider, string $capability ): bool {
		$interface = self::CAPABILITY_INTERFACES[ $capability ] ?? '';

		return '' !== $interface
			&& $provider instanceof $interface
			&& in_array( $capability, $provider->capabilities(), true );
	}

	/**
	 * Pick the provider for one request.
	 *
	 * @param string      $capability Capability id.
	 * @param object|null $request    The *Input DTO, for routing decisions.
	 * @throws ProviderException When nothing suitable is configured.
	 */
	public function resolve( string $capability, ?object $request = null ): ProviderInterface {
		$default = $this->for_task( self::CAPABILITY_TASKS[ $capability ] ?? ProviderInfo::TASK_STUDIO );

		/**
		 * Choose the provider id for a request. Free returns the configured provider;
		 * Pro's router (P8) scores providers here.
		 *
		 * @param string      $provider_id Default provider id.
		 * @param string      $capability  Capability id.
		 * @param object|null $request     Request DTO.
		 */
		$id       = (string) apply_filters( 'weargraph_resolve_provider', $default ? $default->id() : '', $capability, $request );
		$provider = $this->get( $id );

		if ( ! $provider || ! self::supports( $provider, $capability ) ) {
			throw ProviderException::not_configured( esc_html__( 'No AI provider is set up for this task. Add your API key in WearGraph → Settings.', 'weargraph' ) );
		}

		if ( ! $provider->is_configured() ) {
			throw ProviderException::not_configured( esc_html__( 'Your AI provider has no API key yet. Add it in WearGraph → Settings.', 'weargraph' ) );
		}

		return $provider;
	}
}

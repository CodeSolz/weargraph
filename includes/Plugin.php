<?php
/**
 * Plugin bootstrap / service container.
 *
 * @package WearGraph
 */

namespace WearGraph;

defined( 'ABSPATH' ) || exit;

use WearGraph\Admin\AdminMenu;
use WearGraph\Admin\CatalogPage;
use WearGraph\Admin\Dashboard;
use WearGraph\Admin\Notices;
use WearGraph\Admin\PluginLinks;
use WearGraph\Admin\ProductPanel;
use WearGraph\Admin\PrivacySettingsTab;
use WearGraph\Admin\ProductTryOnTab;
use WearGraph\Admin\TryOnSettingsTab;
use WearGraph\Admin\SettingsPage;
use WearGraph\Commerce\WooCatalogReader;
use WearGraph\Database\Migrator;
use WearGraph\Database\Repositories\CatalogRepository;
use WearGraph\Database\Repositories\AssetsRepository;
use WearGraph\Database\Repositories\EventsRepository;
use WearGraph\Database\Repositories\GenerationsRepository;
use WearGraph\Database\Repositories\SessionsRepository;
use WearGraph\Database\Repositories\UsageLedgerRepository;
use WearGraph\Events\EventMaintenance;
use WearGraph\Events\EventRecorder;
use WearGraph\Events\EventSubscriber;
use WearGraph\Generation\KindRegistry;
use WearGraph\Jobs\AsyncKick;
use WearGraph\Jobs\CatalogScanJob;
use WearGraph\Jobs\CleanupJob;
use WearGraph\Jobs\GenerationJob;
use WearGraph\Privacy\PersonalData;
use WearGraph\Providers\Flux\FluxProvider;
use WearGraph\Providers\Gemini\GeminiProvider;
use WearGraph\Providers\OpenAI\OpenAIProvider;
use WearGraph\Providers\OpenRouter\OpenRouterProvider;
use WearGraph\Providers\ProviderRegistry;
use WearGraph\Providers\Xai\XaiProvider;
use WearGraph\Rest\CatalogController;
use WearGraph\Rest\GenerationsController;
use WearGraph\Rest\MediaController;
use WearGraph\Rest\StorefrontController;
use WearGraph\Rest\StudioController;
use WearGraph\Services\CatalogScanService;
use WearGraph\Services\CatalogService;
use WearGraph\Services\GenerationService;
use WearGraph\Storage\ImageRefResolver;
use WearGraph\Storage\MediaLibraryWriter;
use WearGraph\Storefront\AccountPage;
use WearGraph\Storefront\ButtonRenderer;
use WearGraph\Storefront\SessionManager;
use WearGraph\Storefront\StorefrontService;

/**
 * The one place that builds services and registers their hooks.
 */
final class Plugin {

	/**
	 * Singleton.
	 *
	 * @var self|null
	 */
	private static $instance = null;

	/**
	 * Built services by id.
	 *
	 * @var array<string,object>
	 */
	private $services = array();

	/**
	 * Guard against a double init().
	 *
	 * @var bool
	 */
	private $booted = false;

	/**
	 * Container instance.
	 */
	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Use instance().
	 */
	private function __construct() {}

	/**
	 * Boot. Hooked to plugins_loaded.
	 */
	public function init(): void {
		if ( $this->booted ) {
			return;
		}
		$this->booted = true;

		if ( ! $this->woocommerce_ok() ) {
			return;
		}

		// Translations for wordpress.org-hosted plugins load automatically (WP 4.6+).
		$this->maybe_upgrade();

		$this->services['providers'] = new ProviderRegistry(
			array(
				GeminiProvider::ID     => GeminiProvider::class,
				OpenAIProvider::ID     => OpenAIProvider::class,
				OpenRouterProvider::ID => OpenRouterProvider::class,
				FluxProvider::ID       => FluxProvider::class,
				XaiProvider::ID        => XaiProvider::class,
			)
		);
		$this->boot_generation();
		$this->boot_catalog();
		$this->boot_storefront();
		$this->boot_privacy();
		$this->boot_events();

		if ( is_admin() ) {
			$this->services['admin_menu'] = new AdminMenu( new Dashboard( $this->services['events'], $this->services['ledger'], $this->services['generations'], $this->services['catalog_repo'], $this->services['providers'] ) );
			$this->services['settings']   = new SettingsPage( $this->services['providers'] );

			$this->services['product_panel'] = new ProductPanel( $this->services['providers'], $this->services['generations'] );

			$this->services['admin_menu']->boot();
			$this->services['settings']->boot();
			$this->services['product_panel']->boot();

			$this->services['catalog_page'] = new CatalogPage( $this->services['catalog_repo'], $this->services['catalog_job'], $this->services['catalog'] );
			$this->services['catalog_page']->boot();
			( new ProductTryOnTab() )->boot();
			( new TryOnSettingsTab() )->boot();
			( new PrivacySettingsTab( $this->services['personal_data'] ) )->boot();
			( new Notices( $this->services['providers'] ) )->boot();
			( new PluginLinks() )->boot();
		}

		/**
		 * Fires once WearGraph's services are booted. Add-ons register here.
		 *
		 * @param Plugin $plugin The container.
		 */
		do_action( 'weargraph_loaded', $this );
	}

	/**
	 * Fetch a service.
	 *
	 * @param string $id Service id.
	 * @return object|null
	 */
	public function get( string $id ) {
		return $this->services[ $id ] ?? null;
	}

	/**
	 * Generation pipeline: repositories, service, worker, REST routes.
	 */
	private function boot_generation(): void {
		$generations = new GenerationsRepository();
		$assets      = new AssetsRepository();
		$ledger      = new UsageLedgerRepository();
		$kinds       = new KindRegistry();
		$resolver    = new ImageRefResolver( $assets );
		$service     = new GenerationService( $kinds, $this->services['providers'], $generations, $assets, $resolver );
		$job         = new GenerationJob( $kinds, $this->services['providers'], $generations, $assets, $ledger, $resolver, $service );

		$this->services['generations'] = $generations;
		$this->services['assets']      = $assets;
		$this->services['ledger']      = $ledger;
		$this->services['kinds']       = $kinds;
		$this->services['resolver']    = $resolver;
		$this->services['generation']  = $service;
		$this->services['job']         = $job;

		// Action Scheduler passes array( 'id' => n ) as the first argument.
		add_action(
			GenerationJob::HOOK,
			static function ( $id ) use ( $job ) {
				$job->run( (int) $id );
			}
		);

		// Long-running kinds (Pro video) are polled with the id, the operation and the start time.
		add_action(
			GenerationJob::POLL_HOOK,
			static function ( $id, $operation, $started ) use ( $job ) {
				$job->poll( (int) $id, (string) $operation, (int) $started );
			},
			10,
			3
		);

		$kick = static function () use ( $job ) {
			AsyncKick::handle( $job );
		};
		add_action( 'wp_ajax_' . AsyncKick::ACTION, $kick );
		add_action( 'wp_ajax_nopriv_' . AsyncKick::ACTION, $kick );

		add_action(
			'rest_api_init',
			static function () use ( $generations, $service, $assets ) {
				( new GenerationsController( $generations, $service ) )->register_routes();
				( new MediaController( $assets ) )->register_routes();
				( new StudioController( $service, $generations, $assets, new MediaLibraryWriter( $assets ) ) )->register_routes();
			}
		);
	}

	/**
	 * Catalog readiness: reader, scanner, resumable scan job, REST.
	 */
	private function boot_catalog(): void {
		$reader  = new WooCatalogReader();
		$repo    = new CatalogRepository();
		$scanner = new CatalogScanService( $reader, $repo, $this->services['providers'] );
		$job     = new CatalogScanJob( $reader, $scanner, $repo );
		$catalog = new CatalogService( $repo, $scanner, $job );

		$this->services['catalog_reader']  = $reader;
		$this->services['catalog_repo']    = $repo;
		$this->services['catalog_scanner'] = $scanner;
		$this->services['catalog_job']     = $job;
		$this->services['catalog']         = $catalog;

		$job->boot();

		add_action(
			'rest_api_init',
			static function () use ( $repo, $job, $catalog ) {
				( new CatalogController( $repo, $job, $catalog ) )->register_routes();
			}
		);
	}

	/**
	 * Storefront: shopper sessions, Try On button, fitting-room REST.
	 */
	private function boot_storefront(): void {
		$sessions_repo = new SessionsRepository();
		$sessions      = new SessionManager( $sessions_repo );
		$storefront    = new StorefrontService( $this->services['catalog_repo'], $this->services['providers'], $this->services['generation'], $sessions_repo, $this->services['assets'] );
		$button        = new ButtonRenderer( $storefront );

		$account = new AccountPage( $sessions_repo, $this->services['assets'], $storefront );

		$this->services['sessions']     = $sessions;
		$this->services['storefront']   = $storefront;
		$this->services['button']       = $button;
		$this->services['account_page'] = $account;

		$sessions->boot();
		$button->boot();
		$account->boot();

		add_action(
			'rest_api_init',
			static function () use ( $sessions, $storefront ) {
				( new StorefrontController( $sessions, $storefront ) )->register_routes();
			}
		);
	}

	/**
	 * Privacy: hourly retention clean-up, personal-data exporter/eraser, policy text.
	 */
	private function boot_privacy(): void {
		$cleanup  = new CleanupJob( $this->services['generations'], $this->services['ledger'] );
		$personal = new PersonalData( new SessionsRepository(), $this->services['generations'], $this->services['providers'] );

		$this->services['cleanup']       = $cleanup;
		$this->services['personal_data'] = $personal;

		$cleanup->boot();
		$personal->boot();
	}

	/**
	 * Analytics events: recorder, storefront and order subscribers, rollups and retention.
	 */
	private function boot_events(): void {
		$events   = new EventsRepository();
		$recorder = new EventRecorder( $events );

		$this->services['events']         = $events;
		$this->services['event_recorder'] = $recorder;

		( new EventSubscriber( $recorder, $events, $this->services['generations'], $this->services['sessions'] ) )->boot();
		( new EventMaintenance( $recorder, $events ) )->boot();
	}

	/**
	 * WooCommerce present and new enough; shows a notice and returns false otherwise.
	 */
	private function woocommerce_ok(): bool {
		if ( defined( 'WC_VERSION' ) && version_compare( WC_VERSION, WEARGRAPH_MIN_WC, '>=' ) ) {
			return true;
		}

		weargraph_requirement_notice(
			sprintf(
				/* translators: %s: minimum WooCommerce version */
				__( 'WearGraph requires WooCommerce %s or higher to be installed and active.', 'weargraph' ),
				WEARGRAPH_MIN_WC
			)
		);

		return false;
	}

	/**
	 * Run schema migrations and version-change housekeeping.
	 */
	private function maybe_upgrade(): void {
		if ( Migrator::installed_version() < (int) WEARGRAPH_DB_VERSION ) {
			Migrator::migrate( (int) WEARGRAPH_DB_VERSION );
		}

		if ( get_option( 'weargraph_version' ) !== WEARGRAPH_VERSION ) {
			update_option( 'weargraph_version', WEARGRAPH_VERSION, true );
		}
	}
}

<?php
/**
 * Settings screen: tabbed, extensible by add-ons.
 *
 * @package WearGraph
 */

namespace WearGraph\Admin;

defined( 'ABSPATH' ) || exit;

use WearGraph\Providers\ProviderInfo;
use WearGraph\Providers\ProviderRegistry;
use WearGraph\Support\Capabilities;
use WearGraph\Support\Settings;

/**
 * Tabs come from the weargraph_settings_tabs filter. A tab either supplies render/save
 * callbacks or is rendered through the weargraph_settings_render_tab_{slug} action and
 * saved through weargraph_settings_save_tab_{slug}. Every save goes through one
 * admin-post handler with one nonce and a capability check.
 */
final class SettingsPage {

	public const SLUG = 'weargraph-settings';

	private const NONCE_SAVE = 'weargraph_save_settings';
	private const NONCE_TEST = 'weargraph_test_connection';

	/**
	 * Provider registry.
	 *
	 * @var ProviderRegistry
	 */
	private $providers;

	/**
	 * The AI Provider tab.
	 *
	 * @var ProvidersPanel
	 */
	private $panel;

	/**
	 * Constructor.
	 *
	 * @param ProviderRegistry $providers Registry.
	 */
	public function __construct( ProviderRegistry $providers ) {
		$this->providers = $providers;
		$this->panel     = new ProvidersPanel( $providers );
	}

	/**
	 * Register hooks.
	 */
	public function boot(): void {
		add_filter( 'weargraph_admin_pages', array( $this, 'register_page' ) );
		add_filter( 'weargraph_admin_notices', array( $this, 'saved_notice' ) );
		add_action( 'admin_post_weargraph_save_settings', array( $this, 'handle_save' ) );
		add_action( 'wp_ajax_weargraph_test_connection', array( $this, 'ajax_test_connection' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
		$this->panel->boot();
	}

	/**
	 * Add the Settings page under the WearGraph menu.
	 *
	 * @param array<string,array> $pages Pages.
	 * @return array<string,array>
	 */
	public function register_page( array $pages ): array {
		$pages[ self::SLUG ] = array(
			'title'      => __( 'Settings', 'weargraph' ),
			'capability' => Capabilities::MANAGE,
			'callback'   => array( $this, 'render' ),
		);

		return $pages;
	}

	/**
	 * Registered tabs.
	 *
	 * @return array<string,array{label:string,render?:callable,save?:callable,fields?:array<string,string>,form?:bool}>
	 */
	public function tabs(): array {
		$tabs = array(
			'general'  => array(
				'label'  => __( 'General', 'weargraph' ),
				'render' => array( $this, 'render_general' ),
				'save'   => array( $this, 'save_general' ),
				'fields' => array(
					'max_cost_per_request_usd' => 'float',
					'daily_cap_usd'            => 'float',
					'delete_data_on_uninstall' => 'bool',
				),
			),
			'provider' => array(
				'label'  => __( 'AI Provider', 'weargraph' ),
				'render' => array( $this->panel, 'render' ),
				'save'   => array( $this->panel, 'save' ),
				'fields' => array_merge( $this->panel->fields(), array( 'gemini_image_model' => 'text' ) ),
			),
		);

		/**
		 * Settings tabs. Keys are slugs.
		 *
		 * Each tab: label (string), and optionally render (callable), save (callable), fields
		 * (array of field name => type: text, textarea, key, int, float or bool) and form (bool,
		 * default true — false when the tab renders its own forms). Only the declared fields
		 * reach save and weargraph_settings_save_tab_{slug}, already sanitized by type.
		 * Without render, the weargraph_settings_render_tab_{slug} action fires.
		 *
		 * @param array<string,array> $tabs Tabs.
		 */
		$tabs = (array) apply_filters( 'weargraph_settings_tabs', $tabs );

		return array_filter(
			$tabs,
			static function ( $tab ) {
				return is_array( $tab ) && ! empty( $tab['label'] );
			}
		);
	}

	/**
	 * Current tab slug from the URL.
	 */
	private function current_tab(): string {
		$tabs = $this->tabs();
		$tab  = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only tab selection.

		return isset( $tabs[ $tab ] ) ? $tab : (string) array_key_first( $tabs );
	}

	/**
	 * Render the screen.
	 */
	public function render(): void {
		if ( ! current_user_can( Capabilities::MANAGE ) ) {
			return;
		}

		$tabs    = $this->tabs();
		$current = $this->current_tab();
		$tab     = $tabs[ $current ];

		AdminMenu::open_shell( __( 'WearGraph Settings', 'weargraph' ) );
		AdminMenu::render_page_header( __( 'Settings', 'weargraph' ), __( 'Connect your AI provider and control spending, privacy and storefront behaviour.', 'weargraph' ) );
		?>
		<div class="weargraph-settings">
			<nav class="weargraph-settings__nav" aria-label="<?php esc_attr_e( 'Settings sections', 'weargraph' ); ?>">
				<?php foreach ( $tabs as $slug => $item ) : ?>
					<a href="<?php echo esc_url( add_query_arg( 'tab', $slug, admin_url( 'admin.php?page=' . self::SLUG ) ) ); ?>"
						class="weargraph-settings__tab<?php echo $slug === $current ? ' is-active' : ''; ?>"
						<?php echo $slug === $current ? 'aria-current="page"' : ''; ?>>
						<?php echo esc_html( $item['label'] ); ?>
					</a>
				<?php endforeach; ?>
			</nav>
			<div class="weargraph-settings__panel weargraph-card">
				<?php if ( false !== ( $tab['form'] ?? true ) ) : ?>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<input type="hidden" name="action" value="weargraph_save_settings">
						<input type="hidden" name="tab" value="<?php echo esc_attr( $current ); ?>">
						<?php wp_nonce_field( self::NONCE_SAVE ); ?>
						<?php $this->render_tab_body( $current, $tab ); ?>
						<p class="weargraph-settings__actions"><?php submit_button( __( 'Save changes', 'weargraph' ), 'primary', 'submit', false ); ?></p>
					</form>
				<?php else : ?>
					<?php $this->render_tab_body( $current, $tab ); ?>
				<?php endif; ?>
				<?php
				/**
				 * Output after a tab's settings form (for separate forms such as tools).
				 */
				do_action( 'weargraph_settings_after_form_' . $current );
				?>
			</div>
		</div>
		<?php
		AdminMenu::close_shell();
	}

	/**
	 * Render one tab's fields.
	 *
	 * @param string $slug Tab slug.
	 * @param array  $tab  Tab definition.
	 */
	private function render_tab_body( string $slug, array $tab ): void {
		if ( isset( $tab['render'] ) && is_callable( $tab['render'] ) ) {
			call_user_func( $tab['render'] );
			return;
		}

		/**
		 * Render a settings tab registered without a render callback.
		 */
		do_action( 'weargraph_settings_render_tab_' . $slug );
	}

	/**
	 * General tab.
	 */
	public function render_general(): void {
		?>
		<h3><?php esc_html_e( 'Spending limits', 'weargraph' ); ?></h3>
		<p class="description"><?php esc_html_e( 'Requests that would go over these limits are refused before anything is sent to your AI provider.', 'weargraph' ); ?></p>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="wg-max-cost"><?php esc_html_e( 'Maximum cost per request (USD)', 'weargraph' ); ?></label></th>
				<td>
					<input type="number" id="wg-max-cost" name="max_cost_per_request_usd" min="0.01" step="0.01" class="small-text" value="<?php echo esc_attr( (string) Settings::get( 'max_cost_per_request_usd' ) ); ?>">
					<p class="description"><?php esc_html_e( 'Includes automatic retries.', 'weargraph' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="wg-daily-cap"><?php esc_html_e( 'Daily spending cap (USD)', 'weargraph' ); ?></label></th>
				<td><input type="number" id="wg-daily-cap" name="daily_cap_usd" min="0" step="0.5" class="small-text" value="<?php echo esc_attr( (string) Settings::get( 'daily_cap_usd' ) ); ?>"></td>
			</tr>
		</table>

		<h3><?php esc_html_e( 'Data', 'weargraph' ); ?></h3>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'Delete data on uninstall', 'weargraph' ); ?></th>
				<td>
					<label><input type="checkbox" name="delete_data_on_uninstall" value="1" <?php checked( (bool) Settings::get( 'delete_data_on_uninstall' ) ); ?>>
					<?php esc_html_e( 'Remove all WearGraph settings, history and statistics when the plugin is deleted.', 'weargraph' ); ?></label>
					<p class="description"><?php esc_html_e( 'Shopper photos are always deleted when the plugin is deleted, whatever this setting says.', 'weargraph' ); ?></p>
				</td>
			</tr>
		</table>
		<?php
	}

	/**
	 * Save the General tab.
	 *
	 * @param array<string,mixed> $post Declared fields, already sanitized by type.
	 */
	public function save_general( array $post ): void {
		Settings::update(
			array(
				'max_cost_per_request_usd' => max( 0.01, round( (float) ( $post['max_cost_per_request_usd'] ?? 0.5 ), 2 ) ),
				'daily_cap_usd'            => max( 0.0, round( (float) ( $post['daily_cap_usd'] ?? 10 ), 2 ) ),
				'delete_data_on_uninstall' => ! empty( $post['delete_data_on_uninstall'] ),
			)
		);
	}

	/**
	 * admin-post handler for every tab.
	 */
	public function handle_save(): void {
		if ( ! current_user_can( Capabilities::MANAGE ) ) {
			wp_die( esc_html__( 'You are not allowed to change WearGraph settings.', 'weargraph' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( self::NONCE_SAVE );

		$tabs = $this->tabs();
		$slug = isset( $_POST['tab'] ) ? sanitize_key( wp_unslash( $_POST['tab'] ) ) : '';

		if ( isset( $tabs[ $slug ] ) ) {
			$data = self::posted_fields( isset( $tabs[ $slug ]['fields'] ) ? (array) $tabs[ $slug ]['fields'] : array() );

			if ( isset( $tabs[ $slug ]['save'] ) && is_callable( $tabs[ $slug ]['save'] ) ) {
				call_user_func( $tabs[ $slug ]['save'], $data );
			}

			/**
			 * Save a settings tab (fires for every tab, after its save callback).
			 *
			 * @param array<string,mixed> $data The tab's declared fields that were posted, sanitized by type.
			 */
			do_action( 'weargraph_settings_save_tab_' . $slug, $data );
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'     => self::SLUG,
					'tab'      => $slug,
					'updated'  => 1,
					'provider' => isset( $_POST['active_provider'] ) ? sanitize_key( wp_unslash( $_POST['active_provider'] ) ) : false,
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * The declared fields that were posted, each sanitized by its type. Undeclared fields
	 * are dropped. Call only after the nonce and capability checks.
	 *
	 * @param array<string,string> $fields Field name => text|textarea|key|int|float|bool.
	 * @return array<string,string|int|float|bool>
	 */
	private static function posted_fields( array $fields ): array {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- handle_save() verified the nonce before calling this.
		$data = array();
		foreach ( $fields as $name => $type ) {
			$name = (string) $name;
			if ( ! isset( $_POST[ $name ] ) || is_array( $_POST[ $name ] ) ) {
				continue;
			}
			switch ( $type ) {
				case 'bool':
					$data[ $name ] = ! empty( $_POST[ $name ] );
					break;
				case 'int':
					$data[ $name ] = absint( $_POST[ $name ] );
					break;
				case 'float':
					$data[ $name ] = (float) sanitize_text_field( wp_unslash( $_POST[ $name ] ) );
					break;
				case 'key':
					$data[ $name ] = sanitize_key( wp_unslash( $_POST[ $name ] ) );
					break;
				case 'textarea':
					$data[ $name ] = sanitize_textarea_field( wp_unslash( $_POST[ $name ] ) );
					break;
				default:
					$data[ $name ] = sanitize_text_field( wp_unslash( $_POST[ $name ] ) );
			}
		}
		// phpcs:enable

		return $data;
	}

	/**
	 * "Settings saved." after a redirect.
	 *
	 * @param array<int,array> $notices Notices.
	 * @return array<int,array>
	 */
	public function saved_notice( array $notices ): array {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display flag only.
		if ( isset( $_GET['page'], $_GET['updated'] ) && self::SLUG === $_GET['page'] ) {
			$notices[] = array(
				'type'    => 'success',
				'message' => __( 'Settings saved.', 'weargraph' ),
			);
		}

		return $notices;
	}

	/**
	 * AJAX: test the saved key.
	 */
	public function ajax_test_connection(): void {
		check_ajax_referer( self::NONCE_TEST, 'nonce' );
		if ( ! current_user_can( Capabilities::MANAGE ) ) {
			wp_send_json_error( array( 'message' => __( 'You are not allowed to do this.', 'weargraph' ) ), 403 );
		}

		$id       = isset( $_POST['provider'] ) ? sanitize_key( wp_unslash( $_POST['provider'] ) ) : '';
		$provider = '' !== $id ? $this->providers->get( $id ) : $this->providers->for_task( ProviderInfo::TASK_STUDIO );
		if ( ! $provider || ! $provider->is_configured() ) {
			wp_send_json_error( array( 'message' => __( 'Save an API key first.', 'weargraph' ) ) );
		}

		$health = $provider->health_check();
		$data   = array(
			'message'    => $health->message,
			'latency_ms' => $health->latency_ms,
			'model'      => $health->model,
			'code'       => $health->error_code,
		);

		if ( $health->ok ) {
			wp_send_json_success( $data );
		}
		wp_send_json_error( $data );
	}

	/**
	 * Script for the settings screen.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public function enqueue( string $hook ): void {
		$menu = weargraph()->get( 'admin_menu' );
		if ( ! $menu instanceof AdminMenu || $menu->hook_for( self::SLUG ) !== $hook ) {
			return;
		}

		wp_enqueue_script( 'weargraph-settings', WEARGRAPH_URL . 'assets/admin/settings.js', array(), AdminMenu::asset_version( 'assets/admin/settings.js' ), true );
		wp_localize_script(
			'weargraph-settings',
			'weargraphSettings',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( self::NONCE_TEST ),
				'models'  => wp_create_nonce( ProvidersPanel::NONCE_AJAX ),
				'i18n'    => array(
					'testing'    => __( 'Testing…', 'weargraph' ),
					'refreshing' => __( 'Loading models…', 'weargraph' ),
					'show'       => __( 'Show', 'weargraph' ),
					'hide'       => __( 'Hide', 'weargraph' ),
					/* translators: %d: milliseconds */
					'latency'    => __( '(%d ms)', 'weargraph' ),
					'failed'     => __( 'The test could not run. Please reload the page and try again.', 'weargraph' ),
				),
			)
		);
	}
}

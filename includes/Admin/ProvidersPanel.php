<?php
/**
 * Settings → AI Provider: provider cards, one configuration panel per provider, and
 * which provider does what.
 *
 * @package WearGraph
 */

namespace WearGraph\Admin;

defined( 'ABSPATH' ) || exit;

use WearGraph\Providers\DescribedProvider;
use WearGraph\Providers\ModelListing;
use WearGraph\Providers\ProviderException;
use WearGraph\Providers\ProviderInfo;
use WearGraph\Providers\ProviderInterface;
use WearGraph\Providers\ProviderKeys;
use WearGraph\Providers\ProviderOptions;
use WearGraph\Providers\ProviderRegistry;
use WearGraph\Support\Capabilities;
use WearGraph\Support\Settings;

/**
 * Every provider implementing DescribedProvider gets a card and a panel (Pro's too).
 * Field names are flat (key__{id}, remove__{id}, opt__{id}__{field}, task_{task}) so they
 * go through SettingsPage's declared-fields sanitising. Without JavaScript every panel is
 * shown; settings.js turns the cards into a picker, and runs Test connection and Refresh
 * models (only the provider id and nonce are sent: saved keys never leave the server).
 */
final class ProvidersPanel {

	public const NONCE_AJAX = 'weargraph_providers';

	private const NOTICE = 'weargraph_provider_notice_';

	/**
	 * Registry.
	 *
	 * @var ProviderRegistry
	 */
	private $registry;

	/**
	 * Constructor.
	 *
	 * @param ProviderRegistry $registry Registry.
	 */
	public function __construct( ProviderRegistry $registry ) {
		$this->registry = $registry;
	}

	/**
	 * Register hooks.
	 */
	public function boot(): void {
		add_action( 'wp_ajax_weargraph_refresh_models', array( $this, 'ajax_refresh_models' ) );
		add_filter( 'weargraph_admin_notices', array( $this, 'notices' ) );
	}

	/**
	 * Described providers, free ones first (then in registration order).
	 *
	 * @return array<string,ProviderInterface&DescribedProvider>
	 */
	public function described(): array {
		$list = array_filter(
			$this->registry->all(),
			static function ( $provider ) {
				return $provider instanceof DescribedProvider;
			}
		);
		uasort(
			$list,
			static function ( $a, $b ) {
				return ( 'free' === $a->info()->origin ? 0 : 1 ) <=> ( 'free' === $b->info()->origin ? 0 : 1 );
			}
		);

		return $list;
	}

	/**
	 * Declared form fields for SettingsPage.
	 *
	 * @return array<string,string>
	 */
	public function fields(): array {
		$fields = array(
			'active_provider' => 'key',
			// Older field names, still accepted (the main provider's key).
			'provider'        => 'key',
			'api_key'         => 'text',
			'remove_key'      => 'bool',
		);
		foreach ( array_keys( ProviderRegistry::TASK_SETTINGS ) as $task ) {
			$fields[ 'task_' . $task ] = 'key';
		}
		foreach ( $this->described() as $id => $provider ) {
			$fields[ 'key__' . $id ]    = 'text';
			$fields[ 'remove__' . $id ] = 'bool';
			foreach ( array_keys( $provider->info()->fields ) as $field ) {
				$fields[ 'opt__' . $id . '__' . $field ] = 'text';
			}
		}

		return $fields;
	}

	/**
	 * Render the tab body (inside SettingsPage's form).
	 */
	public function render(): void {
		$providers = $this->described();
		$active    = $this->active_id( $providers );
		?>
		<div class="weargraph-providers" data-active="<?php echo esc_attr( $active ); ?>">
			<input type="hidden" name="active_provider" value="<?php echo esc_attr( $active ); ?>" data-active-input>

			<div class="weargraph-providers__intro">
				<h3><?php esc_html_e( 'Your AI providers', 'weargraph' ); ?></h3>
				<p class="description"><?php esc_html_e( 'Connect one or more providers with your own API keys. You pay each provider directly; keys are stored encrypted and never shown again.', 'weargraph' ); ?></p>
			</div>

			<div class="weargraph-provider-grid" role="group" aria-label="<?php esc_attr_e( 'AI providers', 'weargraph' ); ?>">
				<?php foreach ( $providers as $id => $provider ) : ?>
					<?php
					$info      = $provider->info();
					$connected = $provider->is_configured();
					?>
					<button type="button" class="weargraph-provider-card<?php echo $connected ? ' is-connected' : ''; ?><?php echo $id === $active ? ' is-active' : ''; ?>" data-provider="<?php echo esc_attr( $id ); ?>" aria-controls="weargraph-provider-<?php echo esc_attr( $id ); ?>" aria-pressed="<?php echo $id === $active ? 'true' : 'false'; ?>">
						<span class="weargraph-provider-card__mark" aria-hidden="true"><?php echo esc_html( $info->initials ); ?></span>
						<span class="weargraph-provider-card__name"><?php echo esc_html( $info->label ); ?></span>
						<span class="weargraph-provider-card__status">
							<?php echo esc_html( $connected ? __( 'Connected', 'weargraph' ) : __( 'Not connected', 'weargraph' ) ); ?>
						</span>
						<?php if ( $info->badges ) : ?>
							<span class="weargraph-provider-card__badges">
								<?php foreach ( $info->badges as $badge ) : ?>
									<span><?php echo esc_html( $badge ); ?></span>
								<?php endforeach; ?>
							</span>
						<?php endif; ?>
					</button>
				<?php endforeach; ?>
			</div>

			<?php foreach ( $providers as $id => $provider ) : ?>
				<?php $this->render_panel( (string) $id, $provider, $id === $active ); ?>
			<?php endforeach; ?>

			<?php $this->render_tasks( $providers ); ?>
		</div>
		<?php
	}

	/**
	 * One provider's configuration.
	 *
	 * @param string                               $id       Provider id.
	 * @param ProviderInterface&DescribedProvider $provider Provider.
	 * @param bool                                 $active   Shown first.
	 */
	private function render_panel( string $id, $provider, bool $active ): void {
		$info   = $provider->info();
		$masked = ProviderKeys::masked( $id );
		$values = $provider->setting_values();
		$prefix = 'weargraph-' . $id;
		?>
		<section class="weargraph-provider-panel<?php echo $active ? ' is-active' : ''; ?>" id="weargraph-provider-<?php echo esc_attr( $id ); ?>" data-provider="<?php echo esc_attr( $id ); ?>" aria-labelledby="<?php echo esc_attr( $prefix ); ?>-title">
			<header class="weargraph-provider-panel__head">
				<span class="weargraph-provider-card__mark" aria-hidden="true"><?php echo esc_html( $info->initials ); ?></span>
				<div>
					<h3 id="<?php echo esc_attr( $prefix ); ?>-title"><?php echo esc_html( $info->label ); ?></h3>
					<p class="description"><?php echo esc_html( $info->tagline ); ?></p>
				</div>
			</header>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="<?php echo esc_attr( $prefix ); ?>-key"><?php esc_html_e( 'API key', 'weargraph' ); ?></label></th>
					<td>
						<?php if ( '' !== $masked ) : ?>
							<p class="weargraph-key-status is-set">
								<?php
								/* translators: %s: last characters of the key, e.g. ••••1a2b */
								echo esc_html( sprintf( __( 'A key is saved (%s).', 'weargraph' ), $masked ) );
								?>
							</p>
						<?php else : ?>
							<p class="weargraph-key-status"><?php esc_html_e( 'No key saved yet.', 'weargraph' ); ?></p>
						<?php endif; ?>
						<div class="weargraph-key-row">
							<input type="password" id="<?php echo esc_attr( $prefix ); ?>-key" name="key__<?php echo esc_attr( $id ); ?>" class="regular-text" autocomplete="new-password" spellcheck="false"
								placeholder="<?php echo esc_attr( '' !== $masked ? __( 'Leave blank to keep the saved key', 'weargraph' ) : __( 'Paste your API key', 'weargraph' ) ); ?>">
							<button type="button" class="button weargraph-key-toggle" data-key-toggle="<?php echo esc_attr( $prefix ); ?>-key" aria-pressed="false" hidden><?php esc_html_e( 'Show', 'weargraph' ); ?></button>
							<?php if ( '' !== $info->key_url ) : ?>
								<a class="button" href="<?php echo esc_url( $info->key_url ); ?>" target="_blank" rel="noopener noreferrer">
									<?php esc_html_e( 'Get a key', 'weargraph' ); ?><span class="screen-reader-text"> <?php esc_html_e( '(opens in a new tab)', 'weargraph' ); ?></span>
								</a>
							<?php endif; ?>
						</div>
						<?php if ( '' !== $info->key_help ) : ?>
							<p class="description"><?php echo esc_html( $info->key_help ); ?></p>
						<?php endif; ?>
						<?php if ( '' !== $masked ) : ?>
							<p><label><input type="checkbox" name="remove__<?php echo esc_attr( $id ); ?>" value="1"> <?php esc_html_e( 'Remove the saved key', 'weargraph' ); ?></label></p>
						<?php endif; ?>
					</td>
				</tr>
				<?php foreach ( $info->fields as $field => $spec ) : ?>
					<?php $this->render_field( $id, (string) $field, $spec, $values[ $field ] ?? '' ); ?>
				<?php endforeach; ?>
				<tr>
					<th scope="row"><?php esc_html_e( 'Connection', 'weargraph' ); ?></th>
					<td>
						<button type="button" class="button weargraph-test" data-test="<?php echo esc_attr( $id ); ?>" <?php disabled( '' === $masked ); ?>><?php esc_html_e( 'Test connection', 'weargraph' ); ?></button>
						<span class="weargraph-test-result" data-test-result="<?php echo esc_attr( $id ); ?>" role="status" aria-live="polite"></span>
						<p class="description">
							<?php
							echo esc_html(
								'' === $masked
									? __( 'Save a key first, then test it. Testing does not use any credits.', 'weargraph' )
									: __( 'Checks the saved key without generating anything, so it does not use credits.', 'weargraph' )
							);
							?>
						</p>
					</td>
				</tr>
			</table>
			<?php if ( '' !== $info->data_note ) : ?>
				<details class="weargraph-disclosure">
					<summary><?php esc_html_e( 'What is shared with this provider', 'weargraph' ); ?></summary>
					<p><?php echo esc_html( $info->data_note ); ?></p>
					<?php if ( '' !== $info->terms_url || '' !== $info->privacy_url ) : ?>
						<p>
							<?php if ( '' !== $info->terms_url ) : ?>
								<a href="<?php echo esc_url( $info->terms_url ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Terms of service', 'weargraph' ); ?></a>
							<?php endif; ?>
							<?php if ( '' !== $info->privacy_url ) : ?>
								<?php echo '' !== $info->terms_url ? ' · ' : ''; ?>
								<a href="<?php echo esc_url( $info->privacy_url ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Privacy policy', 'weargraph' ); ?></a>
							<?php endif; ?>
						</p>
					<?php endif; ?>
				</details>
			<?php endif; ?>
		</section>
		<?php
	}

	/**
	 * One settings field.
	 *
	 * @param string              $id    Provider id.
	 * @param string              $field Field name.
	 * @param array<string,mixed> $spec  Spec.
	 * @param mixed               $value Current value.
	 */
	private function render_field( string $id, string $field, array $spec, $value ): void {
		$name  = 'opt__' . $id . '__' . $field;
		$input = 'weargraph-' . $id . '-' . $field;
		$type  = (string) ( $spec['type'] ?? 'text' );
		?>
		<tr>
			<th scope="row"><label for="<?php echo esc_attr( $input ); ?>"><?php echo esc_html( (string) ( $spec['label'] ?? $field ) ); ?></label></th>
			<td>
				<?php if ( 'select' === $type ) : ?>
					<?php $choices = ProviderOptions::choices( $id, $field, $spec ); ?>
					<div class="weargraph-key-row">
						<select id="<?php echo esc_attr( $input ); ?>" name="<?php echo esc_attr( $name ); ?>" data-field="<?php echo esc_attr( $field ); ?>">
							<?php if ( ! isset( $choices[ (string) $value ] ) && '' !== (string) $value ) : ?>
								<option value="<?php echo esc_attr( (string) $value ); ?>" selected><?php echo esc_html( (string) $value ); ?></option>
							<?php endif; ?>
							<?php foreach ( $choices as $choice => $label ) : ?>
								<option value="<?php echo esc_attr( (string) $choice ); ?>" <?php selected( (string) $value, (string) $choice ); ?>><?php echo esc_html( (string) $label ); ?></option>
							<?php endforeach; ?>
						</select>
						<?php if ( ! empty( $spec['live'] ) ) : ?>
							<button type="button" class="button weargraph-refresh" data-refresh="<?php echo esc_attr( $id ); ?>" data-refresh-field="<?php echo esc_attr( $field ); ?>" <?php disabled( ! ProviderKeys::has( $id ) ); ?>><?php esc_html_e( 'Refresh models', 'weargraph' ); ?></button>
						<?php endif; ?>
					</div>
					<?php if ( ! empty( $spec['live'] ) ) : ?>
						<span class="weargraph-test-result" data-refresh-result="<?php echo esc_attr( $id . '-' . $field ); ?>" role="status" aria-live="polite"></span>
					<?php endif; ?>
				<?php elseif ( 'number' === $type ) : ?>
					<input type="number" id="<?php echo esc_attr( $input ); ?>" name="<?php echo esc_attr( $name ); ?>" class="small-text" value="<?php echo esc_attr( (string) $value ); ?>"
						<?php echo isset( $spec['min'] ) ? ' min="' . esc_attr( (string) $spec['min'] ) . '"' : ''; ?>
						<?php echo isset( $spec['max'] ) ? ' max="' . esc_attr( (string) $spec['max'] ) . '"' : ''; ?>
						<?php echo isset( $spec['step'] ) ? ' step="' . esc_attr( (string) $spec['step'] ) . '"' : ''; ?>>
				<?php else : ?>
					<input type="text" id="<?php echo esc_attr( $input ); ?>" name="<?php echo esc_attr( $name ); ?>" class="regular-text" value="<?php echo esc_attr( (string) $value ); ?>">
				<?php endif; ?>
				<?php if ( ! empty( $spec['description'] ) ) : ?>
					<p class="description"><?php echo esc_html( (string) $spec['description'] ); ?></p>
				<?php endif; ?>
			</td>
		</tr>
		<?php
	}

	/**
	 * Which provider does what.
	 *
	 * @param array<string,ProviderInterface&DescribedProvider> $providers Providers.
	 */
	private function render_tasks( array $providers ): void {
		$tasks = array(
			ProviderInfo::TASK_STUDIO => array(
				__( 'Product images (Studio)', 'weargraph' ),
				__( 'Generating and editing product photos.', 'weargraph' ),
			),
			ProviderInfo::TASK_TRYON  => array(
				__( 'Virtual try-on', 'weargraph' ),
				__( 'Shoppers\' try-on previews.', 'weargraph' ),
			),
			ProviderInfo::TASK_VISION => array(
				__( 'Alt text and image checks', 'weargraph' ),
				__( 'Describing and checking images.', 'weargraph' ),
			),
		);
		?>
		<div class="weargraph-tasks">
			<h3><?php esc_html_e( 'Use for', 'weargraph' ); ?></h3>
			<p class="description"><?php esc_html_e( 'Choose a provider for each job, or leave it on automatic: the main provider when it can do the job, otherwise the first connected one that can.', 'weargraph' ); ?></p>
			<table class="form-table" role="presentation">
				<?php foreach ( $tasks as $task => $words ) : ?>
					<?php
					$setting = ProviderRegistry::TASK_SETTINGS[ $task ];
					$current = (string) Settings::get( $setting, '' );
					$able    = array_intersect_key( $providers, $this->registry->able( $task ) );
					$now     = $this->registry->for_task( $task );
					?>
					<tr>
						<th scope="row"><label for="weargraph-task-<?php echo esc_attr( $task ); ?>"><?php echo esc_html( $words[0] ); ?></label></th>
						<td>
							<select id="weargraph-task-<?php echo esc_attr( $task ); ?>" name="task_<?php echo esc_attr( $task ); ?>">
								<?php if ( ProviderInfo::TASK_STUDIO !== $task ) : ?>
									<option value="" <?php selected( $current, '' ); ?>><?php esc_html_e( 'Automatic', 'weargraph' ); ?></option>
								<?php endif; ?>
								<?php foreach ( $able as $id => $provider ) : ?>
									<option value="<?php echo esc_attr( (string) $id ); ?>" <?php selected( $current, (string) $id ); ?>>
										<?php
										echo esc_html(
											$provider->is_configured()
												? $provider->info()->label
												/* translators: %s: provider name */
												: sprintf( __( '%s (add a key first)', 'weargraph' ), $provider->info()->label )
										);
										?>
									</option>
								<?php endforeach; ?>
							</select>
							<p class="description">
								<?php
								echo esc_html( $words[1] ) . ' ';
								if ( $now && $now->is_configured() ) {
									/* translators: %s: provider name */
									echo esc_html( sprintf( __( 'Now done by %s.', 'weargraph' ), $now->label() ) );
								} else {
									esc_html_e( 'No connected provider can do this yet.', 'weargraph' );
								}
								?>
							</p>
						</td>
					</tr>
				<?php endforeach; ?>
			</table>
		</div>
		<?php
	}

	/**
	 * Save the tab.
	 *
	 * @param array<string,mixed> $post Declared fields, sanitised by type.
	 */
	public function save( array $post ): void {
		$problems  = array();
		$providers = $this->described();

		// Older field names: the main provider's key and choice.
		$main = sanitize_key( (string) ( $post['provider'] ?? $post['task_studio'] ?? Settings::get( 'provider', '' ) ) );
		if ( '' !== (string) ( $post['api_key'] ?? '' ) && isset( $providers[ $main ] ) ) {
			$post[ 'key__' . $main ] = $post['api_key'];
		}
		if ( ! empty( $post['remove_key'] ) && isset( $providers[ $main ] ) ) {
			$post[ 'remove__' . $main ] = true;
		}
		if ( isset( $post['provider'] ) && ! isset( $post['task_studio'] ) ) {
			$post['task_studio'] = $post['provider'];
		}

		foreach ( $providers as $id => $provider ) {
			$info = $provider->info();

			// Keys are opaque tokens: strip all whitespace (pasting often adds a newline).
			$key = (string) preg_replace( '/\s+/', '', (string) ( $post[ 'key__' . $id ] ?? '' ) );
			if ( '' !== $key ) {
				if ( $info->key_looks_valid( $key ) ) {
					ProviderKeys::set( $id, $key );
				} else {
					/* translators: %s: provider name */
					$problems[] = sprintf( __( 'That does not look like a %s key, so it was not saved. Check that you copied the whole key.', 'weargraph' ), $info->label );
				}
			} elseif ( ! empty( $post[ 'remove__' . $id ] ) ) {
				ProviderKeys::remove( $id );
			}

			$values = array();
			foreach ( array_keys( $info->fields ) as $field ) {
				if ( isset( $post[ 'opt__' . $id . '__' . $field ] ) ) {
					$values[ $field ] = (string) $post[ 'opt__' . $id . '__' . $field ];
				}
			}
			if ( isset( $post['gemini_image_model'] ) && 'gemini' === $id ) {
				$values['image_model'] = (string) $post['gemini_image_model'];
			}
			if ( $values ) {
				$provider->save_settings( $values );
			}
		}

		$updates = array();
		foreach ( ProviderRegistry::TASK_SETTINGS as $task => $setting ) {
			if ( ! isset( $post[ 'task_' . $task ] ) ) {
				continue;
			}
			$choice = sanitize_key( (string) $post[ 'task_' . $task ] );
			if ( ( '' === $choice && ProviderInfo::TASK_STUDIO !== $task ) || isset( $this->registry->able( $task )[ $choice ] ) ) {
				$updates[ $setting ] = $choice;
			}
		}
		if ( $updates ) {
			Settings::update( $updates );
		}

		if ( $problems ) {
			set_transient( self::NOTICE . get_current_user_id(), $problems, 120 );
		}
	}

	/**
	 * Problems from the last save, shown once.
	 *
	 * @param array<int,array> $notices Notices.
	 * @return array<int,array>
	 */
	public function notices( array $notices ): array {
		$key      = self::NOTICE . get_current_user_id();
		$problems = get_transient( $key );
		if ( is_array( $problems ) && $problems ) {
			delete_transient( $key );
			foreach ( $problems as $problem ) {
				$notices[] = array(
					'type'    => 'error',
					'message' => esc_html( (string) $problem ),
				);
			}
		}

		return $notices;
	}

	/**
	 * AJAX: refresh a provider's model list.
	 */
	public function ajax_refresh_models(): void {
		check_ajax_referer( self::NONCE_AJAX, 'nonce' );
		if ( ! current_user_can( Capabilities::MANAGE ) ) {
			wp_send_json_error( array( 'message' => __( 'You are not allowed to do this.', 'weargraph' ) ), 403 );
		}
		$id       = isset( $_POST['provider'] ) ? sanitize_key( wp_unslash( $_POST['provider'] ) ) : '';
		$field    = isset( $_POST['field'] ) ? sanitize_key( wp_unslash( $_POST['field'] ) ) : '';
		$provider = $this->registry->get( $id );
		$spec     = $provider instanceof DescribedProvider ? ( $provider->info()->fields[ $field ] ?? array() ) : array();
		if ( ! $provider instanceof ModelListing || empty( $spec['live'] ) ) {
			wp_send_json_error( array( 'message' => __( 'This provider has no model list to refresh.', 'weargraph' ) ) );
		}
		if ( ! $provider->is_configured() ) {
			wp_send_json_error( array( 'message' => __( 'Save an API key first.', 'weargraph' ) ) );
		}

		try {
			$models = $provider->list_models( $field );
		} catch ( ProviderException $e ) {
			wp_send_json_error( array( 'message' => $e->getMessage() ) );
		}
		if ( ! $models ) {
			wp_send_json_error( array( 'message' => __( 'The provider returned no models for your key.', 'weargraph' ) ) );
		}
		ProviderOptions::remember_live( $id, $field, $models );

		wp_send_json_success(
			array(
				'choices' => ProviderOptions::choices( $id, $field, $spec ),
				/* translators: %d: number of models */
				'message' => sprintf( _n( '%d model available.', '%d models available.', count( $models ), 'weargraph' ), count( $models ) ),
			)
		);
	}

	/**
	 * Which panel opens first: the one just saved, else the main provider.
	 *
	 * @param array<string,mixed> $providers Providers.
	 */
	private function active_id( array $providers ): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display state only.
		$asked = isset( $_GET['provider'] ) ? sanitize_key( wp_unslash( $_GET['provider'] ) ) : '';
		if ( isset( $providers[ $asked ] ) ) {
			return $asked;
		}
		$main = $this->registry->for_task( ProviderInfo::TASK_STUDIO );

		return $main && isset( $providers[ $main->id() ] ) ? $main->id() : (string) array_key_first( $providers );
	}
}

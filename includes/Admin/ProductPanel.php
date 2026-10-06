<?php
/**
 * "WearGraph" panel on the product edit screen (Studio).
 *
 * @package WearGraph
 */

namespace WearGraph\Admin;

defined( 'ABSPATH' ) || exit;

use WearGraph\Commerce\ProductImages;
use WearGraph\Database\Repositories\GenerationsRepository;
use WearGraph\Domain\ImageOptions;
use WearGraph\Providers\Gemini\GeminiModels;
use WearGraph\Providers\Gemini\GeminiProvider;
use WearGraph\Providers\ProviderRegistry;
use WearGraph\Services\PresetLibrary;
use WearGraph\Support\Capabilities;

/**
 * A metabox with tabs from the weargraph_studio_tabs filter. All actions go through
 * the studio REST routes; the inputs carry no name attribute so nothing is posted
 * with the product form itself.
 */
final class ProductPanel {

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
	 * Constructor.
	 *
	 * @param ProviderRegistry      $providers   Providers.
	 * @param GenerationsRepository $generations Generations.
	 */
	public function __construct( ProviderRegistry $providers, GenerationsRepository $generations ) {
		$this->providers   = $providers;
		$this->generations = $generations;
	}

	/**
	 * Register hooks.
	 */
	public function boot(): void {
		add_action( 'add_meta_boxes_product', array( $this, 'add_meta_box' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	/**
	 * Add the metabox.
	 */
	public function add_meta_box(): void {
		if ( ! current_user_can( Capabilities::STUDIO ) ) {
			return;
		}

		add_meta_box( 'weargraph-studio', __( 'WearGraph Studio', 'weargraph' ), array( $this, 'render' ), 'product', 'normal', 'default' );
	}

	/**
	 * Studio tabs.
	 *
	 * @return array<string,array{label:string,render:callable}>
	 */
	private function tabs(): array {
		$tabs = array(
			'generate' => array(
				'label'  => __( 'Generate', 'weargraph' ),
				'render' => array( $this, 'render_generate' ),
			),
			'overview' => array(
				'label'  => __( 'Images & alt text', 'weargraph' ),
				'render' => array( $this, 'render_overview' ),
			),
		);

		/**
		 * Product studio tabs. Each: array( 'label' => string, 'render' => callable( WP_Post $post ) ).
		 *
		 * @param array<string,array> $tabs Tabs.
		 */
		$tabs = (array) apply_filters( 'weargraph_studio_tabs', $tabs );

		return array_filter(
			$tabs,
			static function ( $tab ) {
				return is_array( $tab ) && ! empty( $tab['label'] ) && isset( $tab['render'] ) && is_callable( $tab['render'] );
			}
		);
	}

	/**
	 * Metabox body.
	 *
	 * @param \WP_Post $post Product post.
	 */
	public function render( \WP_Post $post ): void {
		$provider = $this->providers->configured();
		$ready    = $provider && $provider->is_configured();
		$tabs     = $this->tabs();
		$first    = (string) array_key_first( $tabs );
		?>
		<div class="weargraph-studio" data-product="<?php echo esc_attr( (string) $post->ID ); ?>">
			<?php if ( ! $ready ) : ?>
				<div class="weargraph-studio__notice">
					<?php
					printf(
						/* translators: %s: link to settings */
						esc_html__( 'Add your AI provider key in %s to start generating images.', 'weargraph' ),
						'<a href="' . esc_url( admin_url( 'admin.php?page=weargraph-settings&tab=provider' ) ) . '">' . esc_html__( 'WearGraph → Settings', 'weargraph' ) . '</a>'
					);
					?>
				</div>
			<?php endif; ?>

			<div class="weargraph-studio__tabs" role="tablist">
				<?php foreach ( $tabs as $slug => $tab ) : ?>
					<button type="button" role="tab" class="weargraph-studio__tab<?php echo $slug === $first ? ' is-active' : ''; ?>"
						id="weargraph-tab-<?php echo esc_attr( $slug ); ?>"
						aria-controls="weargraph-panel-<?php echo esc_attr( $slug ); ?>"
						aria-selected="<?php echo $slug === $first ? 'true' : 'false'; ?>"
						data-tab="<?php echo esc_attr( $slug ); ?>"><?php echo esc_html( $tab['label'] ); ?></button>
				<?php endforeach; ?>
			</div>

			<?php foreach ( $tabs as $slug => $tab ) : ?>
				<div class="weargraph-studio__panel" role="tabpanel" id="weargraph-panel-<?php echo esc_attr( $slug ); ?>"
					aria-labelledby="weargraph-tab-<?php echo esc_attr( $slug ); ?>" <?php echo $slug === $first ? '' : 'hidden'; ?>>
					<?php call_user_func( $tab['render'], $post, $ready ); ?>
				</div>
			<?php endforeach; ?>
		</div>
		<?php
	}

	/**
	 * Generate tab.
	 *
	 * @param \WP_Post $post  Product post.
	 * @param bool     $ready Provider configured.
	 */
	public function render_generate( \WP_Post $post, bool $ready = true ): void {
		$images = ProductImages::ids( (int) $post->ID );
		?>
		<?php if ( ! $images ) : ?>
			<p class="weargraph-studio__empty"><?php esc_html_e( 'Add a product image first (Product image or Product gallery). WearGraph works from your own product photos.', 'weargraph' ); ?></p>
			<?php
			return;
		endif;
		?>
		<div class="weargraph-gen" data-ready="<?php echo $ready ? '1' : '0'; ?>">
			<fieldset class="weargraph-field">
				<legend><?php esc_html_e( 'Product images to work from', 'weargraph' ); ?></legend>
				<div class="weargraph-refs">
					<?php foreach ( $images as $i => $attachment_id ) : ?>
						<label class="weargraph-ref">
							<input type="checkbox" data-ref value="<?php echo esc_attr( (string) $attachment_id ); ?>" <?php checked( 0 === $i ); ?>>
							<?php echo wp_get_attachment_image( $attachment_id, 'thumbnail', false, array( 'loading' => 'lazy' ) ); ?>
						</label>
					<?php endforeach; ?>
				</div>
				<p class="description"><?php esc_html_e( 'Several angles of the same product give more accurate results.', 'weargraph' ); ?></p>
			</fieldset>

			<div class="weargraph-grid">
				<fieldset class="weargraph-field">
					<legend><?php esc_html_e( 'Shot', 'weargraph' ); ?></legend>
					<label><input type="radio" data-field="mode" form="weargraph-studio-detached" name="weargraph_mode_<?php echo esc_attr( (string) $post->ID ); ?>" value="product" checked> <?php esc_html_e( 'Product only', 'weargraph' ); ?></label>
					<label><input type="radio" data-field="mode" form="weargraph-studio-detached" name="weargraph_mode_<?php echo esc_attr( (string) $post->ID ); ?>" value="on_model"> <?php esc_html_e( 'Worn by a model', 'weargraph' ); ?></label>
					<label class="weargraph-model" hidden><?php esc_html_e( 'Model', 'weargraph' ); ?>
						<?php $this->select( 'model', PresetLibrary::options( 'model' ), 'any' ); ?>
					</label>
				</fieldset>
				<label class="weargraph-field"><?php esc_html_e( 'Scene', 'weargraph' ); ?>
					<?php $this->select( 'scene', PresetLibrary::options( 'scene' ), 'none' ); ?>
				</label>
				<label class="weargraph-field weargraph-background"><?php esc_html_e( 'Background', 'weargraph' ); ?>
					<?php $this->select( 'background', PresetLibrary::options( 'background' ), 'white' ); ?>
				</label>
				<label class="weargraph-field"><?php esc_html_e( 'Style', 'weargraph' ); ?>
					<?php $this->select( 'style', PresetLibrary::options( 'style' ), 'catalog' ); ?>
				</label>
				<label class="weargraph-field"><?php esc_html_e( 'Aspect ratio', 'weargraph' ); ?>
					<?php $this->select( 'aspect_ratio', array_combine( ImageOptions::ASPECT_RATIOS, ImageOptions::ASPECT_RATIOS ), '4:5' ); ?>
				</label>
				<label class="weargraph-field"><?php esc_html_e( 'Resolution', 'weargraph' ); ?>
					<?php $this->select( 'size', $this->size_options(), '1K' ); ?>
				</label>
			</div>

			<details class="weargraph-advanced">
				<summary><?php esc_html_e( 'More options', 'weargraph' ); ?></summary>
				<label class="weargraph-field"><?php esc_html_e( 'Extra direction (optional)', 'weargraph' ); ?>
					<textarea data-field="custom" rows="2" maxlength="1000" placeholder="<?php esc_attr_e( 'e.g. model holding a coffee cup, sleeves rolled up', 'weargraph' ); ?>"></textarea>
				</label>
				<label class="weargraph-field"><?php esc_html_e( 'Avoid (optional)', 'weargraph' ); ?>
					<textarea data-field="negative" rows="2" maxlength="500" placeholder="<?php esc_attr_e( 'e.g. mannequin, text, busy background', 'weargraph' ); ?>"></textarea>
				</label>
				<?php
				/**
				 * Extra controls in the Generate tab's "More options". Controls with a
				 * data-extra-field="name" attribute are sent with the request as "name"
				 * (see the weargraph_studio_choices filter).
				 *
				 * @param \WP_Post $post Product.
				 */
				do_action( 'weargraph_studio_generate_fields', $post );
				?>
			</details>

			<p class="weargraph-actions">
				<button type="button" class="button button-primary" data-action="generate" <?php disabled( ! $ready ); ?>><?php esc_html_e( 'Generate image', 'weargraph' ); ?></button>
				<button type="button" class="button" data-action="cancel" hidden><?php esc_html_e( 'Cancel', 'weargraph' ); ?></button>
				<span class="weargraph-status" role="status" aria-live="polite"></span>
			</p>

			<div class="weargraph-result" hidden></div>

			<h4 class="weargraph-history__title"><?php esc_html_e( 'Recent results for this product', 'weargraph' ); ?></h4>
			<div class="weargraph-history" data-loaded="0"><p class="description"><?php esc_html_e( 'Loading…', 'weargraph' ); ?></p></div>
			<p class="description weargraph-originals-note"><?php esc_html_e( 'Your original product photos are never changed. Saved results are added as new images.', 'weargraph' ); ?></p>
		</div>
		<?php
	}

	/**
	 * Images & alt text tab.
	 *
	 * @param \WP_Post $post  Product post.
	 * @param bool     $ready Provider configured.
	 */
	public function render_overview( \WP_Post $post, bool $ready = true ): void {
		$images = ProductImages::ids( (int) $post->ID );
		$stats  = $this->generations->product_stats( (int) $post->ID );
		?>
		<p class="weargraph-stats">
			<?php
			printf(
				/* translators: 1: number of generated images, 2: total AI cost in USD */
				esc_html__( '%1$d images generated for this product · AI cost so far: $%2$s', 'weargraph' ),
				(int) $stats['ready'],
				esc_html( number_format_i18n( (float) $stats['cost'], 2 ) )
			);
			?>
		</p>
		<?php if ( ! $images ) : ?>
			<p class="weargraph-studio__empty"><?php esc_html_e( 'This product has no images yet.', 'weargraph' ); ?></p>
			<?php
			/** This action is documented below. */
			do_action( 'weargraph_studio_overview_after', $post );
			return;
		endif;
		?>
		<ul class="weargraph-alts">
			<?php foreach ( $images as $attachment_id ) : ?>
				<?php $alt = (string) get_post_meta( $attachment_id, '_wp_attachment_image_alt', true ); ?>
				<li class="weargraph-alt" data-attachment="<?php echo esc_attr( (string) $attachment_id ); ?>">
					<?php echo wp_get_attachment_image( $attachment_id, 'thumbnail', false, array( 'loading' => 'lazy' ) ); ?>
					<div class="weargraph-alt__body">
						<label>
							<span class="weargraph-alt__label"><?php esc_html_e( 'Alt text', 'weargraph' ); ?>
								<?php if ( '' === $alt ) : ?>
									<em class="weargraph-badge weargraph-badge--warn"><?php esc_html_e( 'missing', 'weargraph' ); ?></em>
								<?php endif; ?>
							</span>
							<input type="text" data-alt value="<?php echo esc_attr( $alt ); ?>" maxlength="250">
						</label>
						<p>
							<button type="button" class="button" data-action="suggest-alt" <?php disabled( ! $ready ); ?>><?php esc_html_e( 'Suggest with AI', 'weargraph' ); ?></button>
							<button type="button" class="button button-primary" data-action="apply-alt"><?php esc_html_e( 'Save alt text', 'weargraph' ); ?></button>
							<span class="weargraph-status" role="status" aria-live="polite"></span>
						</p>
					</div>
				</li>
			<?php endforeach; ?>
		</ul>
		<?php
		/**
		 * After the "Images & alt text" tab (Pro: image version history and restore).
		 *
		 * @param \WP_Post $post Product.
		 */
		do_action( 'weargraph_studio_overview_after', $post );
	}

	/**
	 * Enqueue studio assets on the product editor only.
	 *
	 * @param string $hook Admin page hook.
	 */
	public function enqueue( string $hook ): void {
		if ( ! in_array( $hook, array( 'post.php', 'post-new.php' ), true ) || 'product' !== get_post_type() || ! current_user_can( Capabilities::STUDIO ) ) {
			return;
		}

		// The shared design tokens, fields and buttons (scoped to .weargraph-studio here).
		wp_enqueue_style( 'weargraph-admin', WEARGRAPH_URL . 'assets/admin/admin.css', array(), AdminMenu::asset_version( 'assets/admin/admin.css' ) );
		wp_enqueue_style( 'weargraph-studio', WEARGRAPH_URL . 'assets/admin/studio.css', array( 'weargraph-admin' ), AdminMenu::asset_version( 'assets/admin/studio.css' ) );
		wp_enqueue_script( 'weargraph-studio', WEARGRAPH_URL . 'assets/admin/studio.js', array(), AdminMenu::asset_version( 'assets/admin/studio.js' ), true );
		wp_localize_script(
			'weargraph-studio',
			'weargraphStudio',
			/**
			 * Data passed to studio.js (tabs can add their own keys).
			 *
			 * @param array<string,mixed> $data Localised data.
			 */
			apply_filters(
				'weargraph_studio_script_data',
				array(
					'root'      => esc_url_raw( rest_url( 'weargraph/v1/' ) ),
					'nonce'     => wp_create_nonce( 'wp_rest' ),
					'productId' => (int) get_the_ID(),
					'i18n'      => array(
						'queued'        => __( 'Queued…', 'weargraph' ),
						'processing'    => __( 'Generating… this usually takes 10–30 seconds.', 'weargraph' ),
						'slow'          => __( 'Still working. You can leave this page; the result will appear under Recent results.', 'weargraph' ),
						'cancelled'     => __( 'Cancelled.', 'weargraph' ),
						'failed'        => __( 'Generation failed.', 'weargraph' ),
						'network'       => __( 'Could not reach your site. Please try again.', 'weargraph' ),
						'noRefs'        => __( 'Choose at least one product image.', 'weargraph' ),
						'cached'        => __( 'Same settings as an earlier result, shown without a new AI call.', 'weargraph' ),
						'addGallery'    => __( 'Add to gallery', 'weargraph' ),
						'setFeatured'   => __( 'Set as product image', 'weargraph' ),
						'saveMedia'     => __( 'Save to Media Library', 'weargraph' ),
						'regenerate'    => __( 'Regenerate', 'weargraph' ),
						'delete'        => __( 'Delete', 'weargraph' ),
						'show'          => __( 'Show', 'weargraph' ),
						'confirmDelete' => __( 'Delete this result? Images already saved to the Media Library are kept.', 'weargraph' ),
						'saved'         => __( 'Saved.', 'weargraph' ),
						'savedGallery'  => __( 'Added to the gallery. Save or update the product to see it in the gallery box.', 'weargraph' ),
						'savedFeatured' => __( 'Set as product image. Reload the page to see it in the Product image box.', 'weargraph' ),
						'noHistory'     => __( 'No results yet.', 'weargraph' ),
						'expired'       => __( 'Preview expired', 'weargraph' ),
						'newer'         => __( 'Newer', 'weargraph' ),
						'older'         => __( 'Older', 'weargraph' ),
						'suggesting'    => __( 'Thinking…', 'weargraph' ),
						'reviewAlt'     => __( 'Suggestion ready — review it, then save.', 'weargraph' ),
						/* translators: %s: cost in USD */
						'cost'          => __( 'AI cost: $%s', 'weargraph' ),
					),
				)
			)
		);
	}

	/**
	 * Resolution options, with the price per image for the configured Gemini model.
	 *
	 * @return array<string,string>
	 */
	private function size_options(): array {
		$provider = $this->providers->configured();
		$model    = $provider instanceof GeminiProvider ? GeminiModels::get( $provider->image_model() ) : null;
		$out      = array();

		foreach ( array( '1K', '2K', '4K' ) as $size ) {
			if ( $model && ! in_array( $size, (array) $model['sizes'], true ) ) {
				continue;
			}
			$price        = $model ? (float) ( $model['per_image'][ $size ] ?? 0 ) : 0;
			$out[ $size ] = $price > 0
				/* translators: 1: resolution, 2: approximate price in USD */
				? sprintf( __( '%1$s (~$%2$s)', 'weargraph' ), $size, number_format_i18n( $price, 3 ) )
				: $size;
		}

		return $out;
	}

	/**
	 * Print a select bound to a studio field.
	 *
	 * @param string               $field    Field name.
	 * @param array<string,string> $options  value => label.
	 * @param string               $selected Selected value.
	 */
	private function select( string $field, array $options, string $selected ): void {
		echo '<select data-field="' . esc_attr( $field ) . '">';
		foreach ( $options as $value => $label ) {
			printf( '<option value="%1$s"%2$s>%3$s</option>', esc_attr( (string) $value ), selected( (string) $value, $selected, false ), esc_html( $label ) );
		}
		echo '</select>';
	}
}

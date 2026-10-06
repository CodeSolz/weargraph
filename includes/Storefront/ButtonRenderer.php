<?php
/**
 * Try On button: WooCommerce hook, archive cards, shortcode, block, selector fallback.
 *
 * @package WearGraph
 */

namespace WearGraph\Storefront;

defined( 'ABSPATH' ) || exit;

use WearGraph\Admin\AdminMenu;
use WearGraph\Support\Settings;

/**
 * The markup is identical for every visitor (no nonce, no session), so full-page
 * caches are safe. The heavy fitting-room script loads only after the first click.
 * Placement order (blueprint §36): hook → block → shortcode → JS selector fallback.
 */
final class ButtonRenderer {

	public const POSITIONS = array(
		'after_add_to_cart'  => array( 'woocommerce_after_add_to_cart_button', 10 ),
		'before_add_to_cart' => array( 'woocommerce_before_add_to_cart_form', 10 ),
		'after_meta'         => array( 'woocommerce_product_meta_end', 10 ),
		'after_summary'      => array( 'woocommerce_after_single_product_summary', 5 ),
		'none'               => array( '', 0 ),
	);

	/**
	 * Storefront rules.
	 *
	 * @var StorefrontService
	 */
	private $storefront;

	/**
	 * Whether a product-page button was printed this request.
	 *
	 * @var bool
	 */
	private $rendered = false;

	/**
	 * The only markup markup() produces, for wp_kses() when it is printed.
	 */
	private const BUTTON_KSES = array(
		'button' => array(
			'type'                   => true,
			'class'                  => true,
			'data-weargraph-product' => true,
			'aria-haspopup'          => true,
		),
		'span'   => array(
			'class'       => true,
			'aria-hidden' => true,
		),
	);

	/**
	 * Constructor.
	 *
	 * @param StorefrontService $storefront Storefront rules.
	 */
	public function __construct( StorefrontService $storefront ) {
		$this->storefront = $storefront;
	}

	/**
	 * Register hooks.
	 */
	public function boot(): void {
		$position = self::POSITIONS[ (string) Settings::get( 'button_position', 'after_add_to_cart' ) ] ?? self::POSITIONS['after_add_to_cart'];
		if ( '' !== $position[0] ) {
			add_action( $position[0], array( $this, 'render_single' ), $position[1] );
		}
		if ( Settings::get( 'archive_button' ) ) {
			add_action( 'woocommerce_after_shop_loop_item', array( $this, 'render_archive' ), 15 );
		}

		add_shortcode( 'weargraph_try_on', array( $this, 'shortcode' ) );
		add_action( 'init', array( $this, 'register_block' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'register_assets' ) );
		add_action( 'wp_footer', array( $this, 'fallback' ) );
	}

	/**
	 * Button labels a merchant can choose (§15.1).
	 *
	 * @return array<string,string>
	 */
	public static function labels(): array {
		return array(
			'try_on'               => __( 'Try On', 'weargraph' ),
			'see_it_on_me'         => __( 'See It On Me', 'weargraph' ),
			'virtual_fitting_room' => __( 'Virtual Fitting Room', 'weargraph' ),
			'try_this_look'        => __( 'Try This Look', 'weargraph' ),
			'preview_on_me'        => __( 'Preview On Me', 'weargraph' ),
		);
	}

	/**
	 * Current label.
	 */
	public static function label(): string {
		$labels = self::labels();

		/**
		 * Try On button text.
		 *
		 * @param string $label Label.
		 */
		return (string) apply_filters( 'weargraph_button_label', $labels[ (string) Settings::get( 'button_label', 'try_on' ) ] ?? $labels['try_on'] );
	}

	/**
	 * Button HTML for a product, or '' when not eligible.
	 *
	 * @param int    $product_id Product.
	 * @param string $context    single | archive | shortcode | block.
	 */
	public function markup( int $product_id, string $context = 'single' ): string {
		if ( ! $product_id || ! $this->storefront->product_enabled( $product_id ) ) {
			return '';
		}

		wp_enqueue_script( 'weargraph-tryon-button' );
		wp_enqueue_style( 'weargraph-tryon-button' );

		return sprintf(
			'<button type="button" class="weargraph-tryon-button button%1$s" data-weargraph-product="%2$d" aria-haspopup="dialog"><span class="weargraph-tryon-button__icon" aria-hidden="true"></span>%3$s</button>',
			'archive' === $context ? ' weargraph-tryon-button--small' : '',
			$product_id,
			esc_html( self::label() )
		);
	}

	/**
	 * Single product page (hook).
	 */
	public function render_single(): void {
		global $product;
		if ( $this->rendered || ! $product instanceof \WC_Product ) {
			return;
		}
		$html = $this->markup( $product->get_id() );
		if ( '' !== $html ) {
			$this->rendered = true;
			echo wp_kses( $html, self::BUTTON_KSES );
		}
	}

	/**
	 * Shop / category cards.
	 */
	public function render_archive(): void {
		global $product;
		if ( $product instanceof \WC_Product ) {
			echo wp_kses( $this->markup( $product->get_id(), 'archive' ), self::BUTTON_KSES );
		}
	}

	/**
	 * [weargraph_try_on id="123"] — defaults to the current product.
	 *
	 * @param array<string,string>|string $atts Attributes.
	 */
	public function shortcode( $atts ): string {
		$atts = shortcode_atts( array( 'id' => 0 ), (array) $atts, 'weargraph_try_on' );
		$id   = absint( $atts['id'] ) ? absint( $atts['id'] ) : (int) get_the_ID();
		$html = 'product' === get_post_type( $id ) ? $this->markup( $id, 'shortcode' ) : '';
		if ( '' !== $html && is_product() && (int) get_queried_object_id() === $id ) {
			$this->rendered = true;
		}

		return $html;
	}

	/**
	 * Register the "Try On button" block (dynamic; rendered by render_block()).
	 */
	public function register_block(): void {
		wp_register_script(
			'weargraph-block-editor',
			WEARGRAPH_URL . 'assets/blocks/try-on-button/editor.js',
			array( 'wp-blocks', 'wp-element', 'wp-block-editor', 'wp-components', 'wp-i18n' ),
			AdminMenu::asset_version( 'assets/blocks/try-on-button/editor.js' ),
			true
		);
		wp_set_script_translations( 'weargraph-block-editor', 'weargraph' ); // wp.org language packs ship JSON for wp.i18n strings.
		register_block_type(
			WEARGRAPH_DIR . 'assets/blocks/try-on-button',
			array(
				'render_callback' => array( $this, 'render_block' ),
			)
		);
	}

	/**
	 * Block render callback.
	 *
	 * @param array<string,mixed> $attributes Block attributes.
	 * @param string              $content    Inner content (unused).
	 * @param \WP_Block|null      $block      Block instance (for postId context).
	 */
	public function render_block( $attributes, $content = '', $block = null ): string {
		$id = absint( $attributes['productId'] ?? 0 );
		if ( ! $id && $block instanceof \WP_Block && ! empty( $block->context['postId'] ) ) {
			$id = absint( $block->context['postId'] );
		}
		if ( ! $id ) {
			$id = (int) get_the_ID();
		}
		$html = 'product' === get_post_type( $id ) ? $this->markup( $id, 'block' ) : '';
		if ( '' !== $html && is_product() && (int) get_queried_object_id() === $id ) {
			$this->rendered = true;
		}

		return '' !== $html ? '<div class="wp-block-weargraph-try-on-button">' . $html . '</div>' : '';
	}

	/**
	 * Register the tiny loader script and button styles.
	 */
	public function register_assets(): void {
		wp_register_style( 'weargraph-tryon-button', WEARGRAPH_URL . 'assets/storefront/button.css', array(), AdminMenu::asset_version( 'assets/storefront/button.css' ) );
		wp_register_script( 'weargraph-tryon-button', WEARGRAPH_URL . 'assets/storefront/button.js', array(), AdminMenu::asset_version( 'assets/storefront/button.js' ), true );
		wp_localize_script( 'weargraph-tryon-button', 'weargraphTryOn', self::client_config() );
	}

	/**
	 * Static, cache-safe config for the storefront scripts.
	 *
	 * @return array<string,mixed>
	 */
	public static function client_config(): array {
		return array(
			'root'     => esc_url_raw( rest_url( 'weargraph/v1/' ) ),
			'cartUrl'  => function_exists( 'wc_get_cart_url' ) ? wc_get_cart_url() : '',
			'ajaxCart' => class_exists( '\WC_AJAX' ) ? \WC_AJAX::get_endpoint( 'add_to_cart' ) : '',
			'modalJs'  => WEARGRAPH_URL . 'assets/storefront/fitting-room.js?ver=' . AdminMenu::asset_version( 'assets/storefront/fitting-room.js' ),
			'modalCss' => WEARGRAPH_URL . 'assets/storefront/fitting-room.css?ver=' . AdminMenu::asset_version( 'assets/storefront/fitting-room.css' ),
			/**
			 * Extra script URLs loaded after the fitting room (add-ons listen for the
			 * weargraph:fitting-room-view event). Must be same-site, cache-safe URLs.
			 *
			 * @param string[] $urls Script URLs.
			 */
			'extraJs'  => array_values( array_filter( array_map( 'esc_url_raw', (array) apply_filters( 'weargraph_fitting_room_scripts', array() ) ) ) ),
			'selector' => Settings::get( 'selector_fallback' ) ? (string) Settings::get( 'fallback_selector', '.single_add_to_cart_button' ) : '',
			'i18n'     => Strings::all(),
		);
	}

	/**
	 * Selector fallback: when no button was printed on a product page, print a hidden
	 * one that button.js moves next to the configured element.
	 */
	public function fallback(): void {
		if ( $this->rendered || ! Settings::get( 'selector_fallback' ) || ! function_exists( 'is_product' ) || ! is_product() ) {
			return;
		}
		$html = $this->markup( (int) get_queried_object_id() );
		if ( '' !== $html ) {
			echo '<div class="weargraph-tryon-fallback" hidden>' . wp_kses( $html, self::BUTTON_KSES ) . '</div>';
		}
	}
}

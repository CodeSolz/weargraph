<?php
/**
 * "Try-On" tab in the product Studio box: readiness and merchant overrides.
 *
 * @package WearGraph
 */

namespace WearGraph\Admin;

defined( 'ABSPATH' ) || exit;

use WearGraph\Catalog\CatalogMessages;

/**
 * Registered through weargraph_studio_tabs. The tab body is rendered by studio.js
 * from GET /catalog/products/{id}; changes go to POST /catalog/products/{id}, which
 * saves the override and rescans the product immediately.
 */
final class ProductTryOnTab {

	/**
	 * Register hooks.
	 */
	public function boot(): void {
		add_filter( 'weargraph_studio_tabs', array( $this, 'register_tab' ) );
		add_filter( 'weargraph_studio_script_data', array( $this, 'script_data' ) );
	}

	/**
	 * Add the tab after Generate.
	 *
	 * @param array<string,array> $tabs Tabs.
	 * @return array<string,array>
	 */
	public function register_tab( array $tabs ): array {
		$tabs['tryon'] = array(
			'label'  => __( 'Try-On', 'weargraph' ),
			'render' => array( $this, 'render' ),
		);

		return $tabs;
	}

	/**
	 * Tab shell (filled by studio.js).
	 *
	 * @param \WP_Post $post Product.
	 */
	public function render( \WP_Post $post ): void {
		printf(
			'<div class="weargraph-tryon-tab" data-product="%d"><p class="description">%s</p></div>',
			(int) $post->ID,
			esc_html__( 'Loading…', 'weargraph' )
		);
	}

	/**
	 * Labels the tab needs in studio.js.
	 *
	 * @param array<string,mixed> $data Localised data.
	 * @return array<string,mixed>
	 */
	public function script_data( array $data ): array {
		$data['catalog'] = array(
			'categories' => CatalogMessages::categories(),
			'layers'     => CatalogMessages::layers(),
			'i18n'       => array(
				'notScanned'   => __( 'This product has not been checked yet.', 'weargraph' ),
				'scanNow'      => __( 'Check now', 'weargraph' ),
				'rescan'       => __( 'Check again', 'weargraph' ),
				'reset'        => __( 'Reset everything to automatic', 'weargraph' ),
				'tryon'        => __( 'Try-on for this product', 'weargraph' ),
				'tryonAuto'    => __( 'Automatic (on when Try-On Ready)', 'weargraph' ),
				'tryonOn'      => __( 'Always on', 'weargraph' ),
				'tryonOff'     => __( 'Off', 'weargraph' ),
				'category'     => __( 'Garment category', 'weargraph' ),
				'layer'        => __( 'Layer', 'weargraph' ),
				/* translators: %s: automatically detected value */
				'automatic'    => __( 'Automatic (%s)', 'weargraph' ),
				'sourceImage'  => __( 'Image used for try-on', 'weargraph' ),
				'autoImage'    => __( 'Choose automatically', 'weargraph' ),
				'variants'     => __( 'Colour variants', 'weargraph' ),
				'currentlyOn'  => __( 'Try-on is ON for this product.', 'weargraph' ),
				'currentlyOff' => __( 'Try-on is OFF for this product.', 'weargraph' ),
				'storeNote'    => __( 'Shoppers see the button only when try-on is also switched on for the store (WearGraph → Settings → Try-On).', 'weargraph' ),
				'saving'       => __( 'Saving…', 'weargraph' ),
				'saved'        => __( 'Saved and re-checked.', 'weargraph' ),
				'score'        => __( 'Readiness', 'weargraph' ),
				'noProblems'   => __( 'No problems found.', 'weargraph' ),
			),
		);

		return $data;
	}
}

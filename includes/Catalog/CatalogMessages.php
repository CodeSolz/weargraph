<?php
/**
 * Human-readable labels for catalog codes.
 *
 * @package WearGraph
 */

namespace WearGraph\Catalog;

defined( 'ABSPATH' ) || exit;

/**
 * The scanner stores codes + params; everything shown to merchants is translated
 * here, at display time.
 */
final class CatalogMessages {

	/**
	 * Status label.
	 *
	 * @param string $status Readiness status.
	 */
	public static function status( string $status ): string {
		$labels = array(
			Readiness::READY        => __( 'Try-On Ready', 'weargraph' ),
			Readiness::NEEDS_IMAGE  => __( 'Needs better image', 'weargraph' ),
			Readiness::NEEDS_REVIEW => __( 'Needs review', 'weargraph' ),
			Readiness::UNSUPPORTED  => __( 'Not supported', 'weargraph' ),
			Readiness::UNSCANNED    => __( 'Not scanned', 'weargraph' ),
		);

		return $labels[ $status ] ?? $labels[ Readiness::UNSCANNED ];
	}

	/**
	 * Category labels (translated), keyed like Keywords::categories() plus "other".
	 *
	 * @return array<string,string>
	 */
	public static function categories(): array {
		$labels = array(
			'tshirt'   => __( 'T-shirt', 'weargraph' ),
			'shirt'    => __( 'Shirt', 'weargraph' ),
			'blouse'   => __( 'Blouse', 'weargraph' ),
			'top'      => __( 'Top', 'weargraph' ),
			'polo'     => __( 'Polo', 'weargraph' ),
			'sweater'  => __( 'Sweater', 'weargraph' ),
			'hoodie'   => __( 'Hoodie / sweatshirt', 'weargraph' ),
			'jacket'   => __( 'Jacket', 'weargraph' ),
			'coat'     => __( 'Coat', 'weargraph' ),
			'blazer'   => __( 'Blazer', 'weargraph' ),
			'cardigan' => __( 'Cardigan', 'weargraph' ),
			'vest'     => __( 'Vest / gilet', 'weargraph' ),
			'trousers' => __( 'Trousers', 'weargraph' ),
			'jeans'    => __( 'Jeans', 'weargraph' ),
			'shorts'   => __( 'Shorts', 'weargraph' ),
			'skirt'    => __( 'Skirt', 'weargraph' ),
			'leggings' => __( 'Leggings', 'weargraph' ),
			'dress'    => __( 'Dress', 'weargraph' ),
			'jumpsuit' => __( 'Jumpsuit', 'weargraph' ),
			'shoes'    => __( 'Shoes', 'weargraph' ),
			'hat'      => __( 'Hat / cap', 'weargraph' ),
			'eyewear'  => __( 'Eyewear', 'weargraph' ),
			'bag'      => __( 'Bag', 'weargraph' ),
			'watch'    => __( 'Watch', 'weargraph' ),
			'jewelry'  => __( 'Jewellery', 'weargraph' ),
			'scarf'    => __( 'Scarf', 'weargraph' ),
			'belt'     => __( 'Belt', 'weargraph' ),
			'swimwear' => __( 'Swimwear', 'weargraph' ),
			'lingerie' => __( 'Lingerie / underwear', 'weargraph' ),
			'socks'    => __( 'Socks', 'weargraph' ),
			'other'    => __( 'Not clothing', 'weargraph' ),
		);

		// Categories added through weargraph_catalog_keywords fall back to their English label.
		foreach ( (array) apply_filters( 'weargraph_catalog_keywords', Keywords::categories() ) as $key => $def ) {
			if ( ! isset( $labels[ $key ] ) ) {
				$labels[ $key ] = (string) ( $def['label'] ?? $key );
			}
		}

		return $labels;
	}

	/**
	 * Layer labels.
	 *
	 * @return array<string,string>
	 */
	public static function layers(): array {
		return array(
			'inner'     => __( 'Inner layer (worn next to the body)', 'weargraph' ),
			'outer'     => __( 'Outer layer (worn over other clothes)', 'weargraph' ),
			'base'      => __( 'Base (bottoms, dresses, shoes)', 'weargraph' ),
			'accessory' => __( 'Accessory', 'weargraph' ),
		);
	}

	/**
	 * One problem as a sentence.
	 *
	 * @param array{code:string,params?:array<string,mixed>} $problem Problem.
	 */
	public static function problem( array $problem ): string {
		$p = (array) ( $problem['params'] ?? array() );

		switch ( $problem['code'] ) {
			case 'no_image':
				return __( 'No product image yet.', 'weargraph' );
			case 'low_resolution':
				/* translators: 1: width px, 2: height px */
				return sprintf( __( 'The best image is small (%1$d×%2$d px). Use at least 800 px on the short side.', 'weargraph' ), (int) ( $p['width'] ?? 0 ), (int) ( $p['height'] ?? 0 ) );
			case 'extreme_aspect':
				return __( 'The image is very wide or very tall. A portrait or square photo works best.', 'weargraph' );
			case 'busy_background':
				return __( 'Busy background. A plain background gives more accurate try-ons.', 'weargraph' );
			case 'product_too_small':
				return __( 'The product fills only a small part of the photo. Crop closer.', 'weargraph' );
			case 'on_model_image':
				return __( 'The best photo shows the product on a person. A product-only photo usually gives better results.', 'weargraph' );
			case 'variant_missing_image':
				/* translators: %s: list of variant names, e.g. "Blue, Green" */
				return sprintf( __( '%s has no photo of its own, so try-on would show a different colour.', 'weargraph' ), implode( ', ', array_map( 'strval', (array) ( $p['variants'] ?? array() ) ) ) );
			case 'variant_images_identical':
				/* translators: %s: list of variant names */
				return sprintf( __( 'These colours use the same photo: %s.', 'weargraph' ), implode( ', ', array_map( 'strval', (array) ( $p['variants'] ?? array() ) ) ) );
			case 'low_category_confidence':
				return __( 'Not sure what kind of garment this is. Please confirm the category.', 'weargraph' );
			case 'experimental_accessory':
				return __( 'Accessory previews are experimental. Test before enabling.', 'weargraph' );
			case 'sensitive_category':
				return __( 'Swimwear and underwear are not offered for try-on.', 'weargraph' );
			case 'not_wearable':
				return __( 'This does not look like something that can be worn.', 'weargraph' );
			case 'not_visualisable':
				return __( 'This kind of item cannot be shown on a person yet.', 'weargraph' );
			case 'unsupported_product_type':
				return 'grouped' === ( $p['type'] ?? '' )
					? __( 'Grouped products are containers; try-on is set on each product inside the group.', 'weargraph' )
					/* translators: %s: WooCommerce product type */
					: sprintf( __( 'Products of type "%s" are not supported for try-on.', 'weargraph' ), (string) ( $p['type'] ?? '' ) );
			default:
				return (string) $problem['code'];
		}
	}

	/**
	 * Decode stored problems_json into sentences.
	 *
	 * @param string|null $json Stored JSON.
	 * @return string[]
	 */
	public static function problems_from_json( ?string $json ): array {
		$problems = json_decode( (string) $json, true );
		if ( ! is_array( $problems ) ) {
			return array();
		}

		return array_map( array( self::class, 'problem' ), $problems );
	}
}

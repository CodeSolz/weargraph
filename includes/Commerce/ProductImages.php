<?php
/**
 * A product's own images (featured, gallery, variation images).
 *
 * @package WearGraph
 */

namespace WearGraph\Commerce;

defined( 'ABSPATH' ) || exit;

/**
 * The studio only works with images that belong to the product being edited, so a
 * request cannot pull an arbitrary attachment from the site into a prompt.
 */
final class ProductImages {

	/**
	 * Attachment ids: featured first, then gallery, then variation images (unique).
	 *
	 * @param int $product_id Product id.
	 * @return int[]
	 */
	public static function ids( int $product_id ): array {
		$product = wc_get_product( $product_id );
		if ( ! $product ) {
			return array();
		}

		$ids = array_merge( array( (int) $product->get_image_id() ), array_map( 'intval', $product->get_gallery_image_ids() ) );

		if ( $product->is_type( 'variable' ) ) {
			foreach ( $product->get_children() as $variation_id ) {
				$variation = wc_get_product( $variation_id );
				if ( $variation ) {
					$ids[] = (int) $variation->get_image_id();
				}
			}
		}

		return array_values( array_unique( array_filter( $ids ) ) );
	}

	/**
	 * Whether an attachment is one of the product's images.
	 *
	 * @param int $product_id    Product id.
	 * @param int $attachment_id Attachment id.
	 */
	public static function contains( int $product_id, int $attachment_id ): bool {
		return in_array( $attachment_id, self::ids( $product_id ), true );
	}
}

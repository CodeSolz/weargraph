<?php
/**
 * Reads the WooCommerce catalog into platform-neutral DTOs.
 *
 * @package WearGraph
 */

namespace WearGraph\Commerce;

defined( 'ABSPATH' ) || exit;

/**
 * The only class (with ProductImages) that talks to WooCommerce product APIs.
 * Paging uses an id cursor so a scan can resume exactly where it stopped.
 */
final class WooCatalogReader {

	/**
	 * Statuses included in catalog scans. The SQL here and in CatalogRepository lists
	 * them too: keep them in step when this list changes.
	 */
	public const STATUSES = array( 'publish', 'private', 'draft', 'pending', 'future' );

	/**
	 * Product types that can be visualised.
	 */
	public const TYPES = array( 'simple', 'variable', 'external' );

	/**
	 * Next batch of product ids after a cursor.
	 *
	 * @param int $after_id Cursor (last product id processed).
	 * @param int $limit    Batch size.
	 * @return int[]
	 */
	public function next_ids( int $after_id, int $limit = 25 ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- cursor paging over posts; WP_Query cannot express "ID > cursor".
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT ID FROM %i WHERE post_type = 'product' AND post_status IN ('publish', 'private', 'draft', 'pending', 'future') AND ID > %d ORDER BY ID ASC LIMIT %d",
				$wpdb->posts,
				$after_id,
				max( 1, $limit )
			)
		);

		return array_map( 'intval', (array) $ids );
	}

	/**
	 * Number of products a full scan will visit.
	 */
	public function count(): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- progress total for the scan.
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM %i WHERE post_type = 'product' AND post_status IN ('publish', 'private', 'draft', 'pending', 'future')",
				$wpdb->posts
			)
		);
	}

	/**
	 * One product as a DTO, or null when it is not a scannable product.
	 *
	 * @param int $product_id Product id.
	 */
	public function read( int $product_id ): ?ProductDTO {
		$product = wc_get_product( $product_id );
		if ( ! $product || ! in_array( $product->get_type(), self::TYPES, true ) || ! in_array( $product->get_status(), self::STATUSES, true ) ) {
			return null;
		}

		$dto             = new ProductDTO();
		$dto->id         = 'woo:' . $product_id;
		$dto->product_id = $product_id;
		$dto->type       = $product->get_type();
		$dto->title      = wp_strip_all_tags( $product->get_name() );
		$dto->status     = $product->get_status();
		$dto->categories = $this->term_names( $product_id, 'product_cat', true );
		$dto->tags       = $this->term_names( $product_id, 'product_tag', false );
		$dto->attributes = $this->attributes( $product );
		$dto->price      = (string) $product->get_price();
		$dto->currency   = get_woocommerce_currency();
		$modified        = $product->get_date_modified();
		$dto->updated_at = $modified ? $modified->date( 'c' ) : '';

		$image_ids = array_merge( array( (int) $product->get_image_id() ), array_map( 'intval', $product->get_gallery_image_ids() ) );
		foreach ( array_unique( array_filter( $image_ids ) ) as $attachment_id ) {
			$image = $this->image( $attachment_id );
			if ( $image ) {
				$dto->images[] = $image;
			}
		}

		if ( $product->is_type( 'variable' ) ) {
			foreach ( $product->get_children() as $variation_id ) {
				$variation = wc_get_product( $variation_id );
				if ( ! $variation ) {
					continue;
				}
				$variant               = new VariantDTO();
				$variant->id           = 'woo:' . $product_id . ':' . $variation_id;
				$variant->variation_id = (int) $variation_id;
				$variant->in_stock     = $variation->is_in_stock();
				$variant->price        = (string) $variation->get_price();
				foreach ( $variation->get_variation_attributes( false ) as $taxonomy => $value ) {
					$variant->attributes[ wc_attribute_label( $taxonomy, $product ) ] = $this->attribute_value_label( (string) $taxonomy, (string) $value );
				}
				$own_image = (int) $variation->get_image_id( 'edit' );
				if ( $own_image && $own_image !== (int) $product->get_image_id() ) {
					$variant->image = $this->image( $own_image );
				}
				$dto->variants[] = $variant;
			}
		}

		$dto->compute_hash();

		return $dto;
	}

	/**
	 * Image DTO for an attachment.
	 *
	 * @param int $attachment_id Attachment id.
	 */
	public function image( int $attachment_id ): ?ImageDTO {
		if ( 'attachment' !== get_post_type( $attachment_id ) ) {
			return null;
		}

		$meta                 = wp_get_attachment_metadata( $attachment_id );
		$image                = new ImageDTO();
		$image->id            = 'att:' . $attachment_id;
		$image->attachment_id = $attachment_id;
		$image->url           = (string) wp_get_attachment_url( $attachment_id );
		$image->path          = (string) get_attached_file( $attachment_id );
		$image->mime          = (string) get_post_mime_type( $attachment_id );
		$image->alt           = (string) get_post_meta( $attachment_id, '_wp_attachment_image_alt', true );
		$image->width         = is_array( $meta ) ? (int) ( $meta['width'] ?? 0 ) : 0;
		$image->height        = is_array( $meta ) ? (int) ( $meta['height'] ?? 0 ) : 0;
		$image->modified      = (string) get_post_field( 'post_modified_gmt', $attachment_id );

		// Metadata can be missing (no image library when uploaded): read the header instead.
		if ( ( ! $image->width || ! $image->height ) && '' !== $image->path && is_readable( $image->path ) ) {
			$size = wp_getimagesize( $image->path );
			if ( $size ) {
				$image->width  = (int) $size[0];
				$image->height = (int) $size[1];
			}
		}

		return $image;
	}

	/**
	 * Term names, optionally with ancestors (so "Men > Jackets" yields both).
	 *
	 * @param int    $product_id Product id.
	 * @param string $taxonomy   Taxonomy.
	 * @param bool   $ancestors  Include ancestors.
	 * @return string[]
	 */
	private function term_names( int $product_id, string $taxonomy, bool $ancestors ): array {
		$terms = get_the_terms( $product_id, $taxonomy );
		if ( ! is_array( $terms ) ) {
			return array();
		}

		$names = array();
		foreach ( $terms as $term ) {
			$names[] = $term->name;
			if ( $ancestors ) {
				foreach ( get_ancestors( $term->term_id, $taxonomy, 'taxonomy' ) as $ancestor_id ) {
					$ancestor = get_term( $ancestor_id, $taxonomy );
					if ( $ancestor && ! is_wp_error( $ancestor ) ) {
						$names[] = $ancestor->name;
					}
				}
			}
		}

		return array_values( array_unique( array_map( 'html_entity_decode', $names ) ) );
	}

	/**
	 * Attribute label => values.
	 *
	 * @param \WC_Product $product Product.
	 * @return array<string,string[]>
	 */
	private function attributes( \WC_Product $product ): array {
		$out = array();
		foreach ( $product->get_attributes() as $attribute ) {
			if ( ! $attribute instanceof \WC_Product_Attribute ) {
				continue;
			}
			$label = wc_attribute_label( $attribute->get_name(), $product );
			if ( $attribute->is_taxonomy() ) {
				$values = array();
				foreach ( $attribute->get_terms() ? $attribute->get_terms() : array() as $term ) {
					$values[] = $term->name;
				}
			} else {
				$values = array_map( 'strval', $attribute->get_options() );
			}
			$out[ $label ] = array_values( array_filter( $values ) );
		}

		return $out;
	}

	/**
	 * Human label for a variation attribute value (term slug → term name).
	 *
	 * @param string $taxonomy Attribute key, e.g. attribute_pa_color.
	 * @param string $value    Stored value.
	 */
	private function attribute_value_label( string $taxonomy, string $value ): string {
		$taxonomy = preg_replace( '/^attribute_/', '', $taxonomy );
		if ( '' !== $value && taxonomy_exists( $taxonomy ) ) {
			$term = get_term_by( 'slug', $value, $taxonomy );
			if ( $term ) {
				return $term->name;
			}
		}

		return $value;
	}
}

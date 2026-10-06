<?php
/**
 * Copies a generated result into the Media Library and attaches it to a product.
 *
 * @package WearGraph
 */

namespace WearGraph\Storage;

defined( 'ABSPATH' ) || exit;

use WearGraph\Database\Repositories\AssetsRepository;
use WearGraph\Support\Files;

/**
 * Always creates a NEW attachment (rule 11: merchant originals are never modified).
 * The attachment carries its provenance (§88) and a link back to the WearGraph asset,
 * so saving the same result twice reuses the first attachment.
 */
final class MediaLibraryWriter {

	public const META_ASSET      = '_weargraph_asset_id';
	public const META_GENERATION = '_weargraph_generation_id';
	public const META_PROVENANCE = '_weargraph_provenance';

	/**
	 * Assets.
	 *
	 * @var AssetsRepository
	 */
	private $assets;

	/**
	 * Constructor.
	 *
	 * @param AssetsRepository $assets Assets.
	 */
	public function __construct( AssetsRepository $assets ) {
		$this->assets = $assets;
	}

	/**
	 * Save a private asset as a Media Library attachment (or return the existing one).
	 *
	 * @param array<string,mixed> $asset      Asset row.
	 * @param int                 $product_id Parent product.
	 * @param string              $title      Attachment title.
	 * @return int Attachment id, 0 on failure.
	 */
	public function save( array $asset, int $product_id, string $title ): int {
		$existing = $this->existing_attachment( (string) $asset['public_id'] );
		if ( $existing ) {
			return $existing;
		}

		$bytes = Files::read( PrivateStore::absolute( (string) $asset['path'] ) );
		if ( '' === $bytes ) {
			return 0;
		}

		$ext    = array(
			'image/png'  => 'png',
			'image/jpeg' => 'jpg',
			'image/webp' => 'webp',
			'video/mp4'  => 'mp4',
		);
		$mime   = (string) $asset['mime'];
		$name   = sanitize_file_name( sanitize_title( $title ? $title : 'product' ) . '-weargraph-' . strtolower( substr( (string) $asset['public_id'], -8 ) ) . '.' . ( $ext[ $mime ] ?? 'png' ) );
		$upload = wp_upload_bits( $name, null, $bytes );
		if ( ! empty( $upload['error'] ) ) {
			return 0;
		}

		$attachment_id = wp_insert_attachment(
			array(
				'post_mime_type' => $mime,
				'post_title'     => $title,
				'post_status'    => 'inherit',
				'post_content'   => '',
			),
			$upload['file'],
			$product_id
		);
		if ( ! $attachment_id || is_wp_error( $attachment_id ) ) {
			return 0;
		}

		require_once ABSPATH . 'wp-admin/includes/image.php';
		require_once ABSPATH . 'wp-admin/includes/media.php'; // Video metadata (length, size) without GD.
		wp_update_attachment_metadata( $attachment_id, wp_generate_attachment_metadata( $attachment_id, $upload['file'] ) );

		update_post_meta( $attachment_id, self::META_ASSET, (string) $asset['public_id'] );
		update_post_meta( $attachment_id, self::META_GENERATION, (int) $asset['generation_id'] );
		update_post_meta( $attachment_id, self::META_PROVENANCE, (string) $asset['provenance_json'] );

		$this->record_destination( $asset, 'media', $attachment_id );

		return (int) $attachment_id;
	}

	/**
	 * Make an attachment the product's featured image.
	 *
	 * @param int $product_id    Product.
	 * @param int $attachment_id Attachment.
	 */
	public function set_featured( int $product_id, int $attachment_id ): bool {
		$product  = wc_get_product( $product_id );
		$previous = $product ? (int) $product->get_image_id() : 0;

		if ( ! $product || $previous === $attachment_id ) {
			return (bool) $product;
		}

		// Keep the previous product image on the product (first in the gallery), so
		// replacing the main image never drops the merchant's own photo.
		$gallery = array_values( array_diff( array_map( 'intval', $product->get_gallery_image_ids() ), array( $attachment_id ) ) );
		if ( $previous && ! in_array( $previous, $gallery, true ) ) {
			array_unshift( $gallery, $previous );
		}

		$product->set_image_id( $attachment_id );
		$product->set_gallery_image_ids( $gallery );
		$product->save();

		return true;
	}

	/**
	 * Append an attachment to the product gallery.
	 *
	 * @param int $product_id    Product.
	 * @param int $attachment_id Attachment.
	 */
	public function add_to_gallery( int $product_id, int $attachment_id ): bool {
		$product = wc_get_product( $product_id );
		if ( ! $product ) {
			return false;
		}

		$ids = array_map( 'intval', $product->get_gallery_image_ids() );
		if ( ! in_array( $attachment_id, $ids, true ) ) {
			$ids[] = $attachment_id;
			$product->set_gallery_image_ids( $ids );
			$product->save();
		}

		return true;
	}

	/**
	 * Attachment already created from this asset, if any.
	 *
	 * @param string $asset_public_id Asset ULID.
	 */
	private function existing_attachment( string $asset_public_id ): int {
		$found = get_posts(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'meta_key'       => self::META_ASSET, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- one-off lookup on save.
				'meta_value'     => $asset_public_id, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- one-off lookup on save.
			)
		);

		return $found ? (int) $found[0] : 0;
	}

	/**
	 * Record where an asset was published (assets.published_to).
	 *
	 * @param array<string,mixed> $asset         Asset row.
	 * @param string              $destination   media|featured|gallery.
	 * @param int                 $attachment_id Attachment.
	 */
	public function record_destination( array $asset, string $destination, int $attachment_id ): void {
		$fresh = $this->assets->find( (int) $asset['id'] );
		$list  = $fresh ? json_decode( (string) $fresh['published_to'], true ) : array();
		$list  = is_array( $list ) ? $list : array();

		$list[] = array(
			'to'         => $destination,
			'attachment' => $attachment_id,
			'at'         => gmdate( 'c' ),
		);
		$this->assets->update( (int) $asset['id'], array( 'published_to' => wp_json_encode( $list ) ) );
	}
}

<?php
/**
 * catalog_meta: readiness and classification per product / variant.
 *
 * @package WearGraph
 */

namespace WearGraph\Database\Repositories;

defined( 'ABSPATH' ) || exit;

use WearGraph\Database\Schema;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table; values change on every scan.

/**
 * variant_id 0 is the product-level row; variant rows hold per-colour source images.
 * Columns ending in _source are 'ai' or 'merchant'; merchant values are never
 * overwritten by a scan (rule 8).
 */
final class CatalogRepository {

	public const OVERRIDABLE = array(
		'category'      => 'category_source',
		'layer'         => 'layer_source',
		'tryon_enabled' => 'tryon_enabled_source',
		'best_image_id' => 'best_image_source',
	);

	/**
	 * Table name.
	 */
	private function table(): string {
		return Schema::table( 'catalog_meta' );
	}

	/**
	 * One row.
	 *
	 * @param int $product_id Product.
	 * @param int $variant_id Variation (0 = product row).
	 * @return array<string,mixed>|null
	 */
	public function get( int $product_id, int $variant_id = 0 ): ?array {
		global $wpdb;

		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE product_id = %d AND variant_id = %d', $this->table(), $product_id, $variant_id ), ARRAY_A );

		return $row ? $row : null;
	}

	/**
	 * All rows for a product (product row first).
	 *
	 * @param int $product_id Product.
	 * @return array<int,array<string,mixed>>
	 */
	public function for_product( int $product_id ): array {
		global $wpdb;

		return (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE product_id = %d ORDER BY variant_id ASC', $this->table(), $product_id ), ARRAY_A );
	}

	/**
	 * Insert or update a row.
	 *
	 * @param int                 $product_id Product.
	 * @param int                 $variant_id Variation (0 = product row).
	 * @param array<string,mixed> $fields     Columns.
	 */
	public function upsert( int $product_id, int $variant_id, array $fields ): void {
		global $wpdb;

		unset( $fields['id'] );
		$fields['product_id'] = $product_id;
		$fields['variant_id'] = $variant_id;

		if ( $this->get( $product_id, $variant_id ) ) {
			$wpdb->update(
				$this->table(),
				$fields,
				array(
					'product_id' => $product_id,
					'variant_id' => $variant_id,
				)
			);
		} else {
			$wpdb->insert( $this->table(), $fields );
		}
	}

	/**
	 * Remove variant rows that are no longer variations of the product.
	 *
	 * @param int   $product_id Product.
	 * @param int[] $keep       Variation ids that still exist.
	 */
	public function prune_variants( int $product_id, array $keep ): void {
		global $wpdb;

		$keep   = array_map( 'intval', $keep );
		$keep[] = 0;

		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE product_id = %d AND NOT FIND_IN_SET(variant_id, %s)', $this->table(), $product_id, implode( ',', $keep ) ) );
	}

	/**
	 * Delete every row for a product.
	 *
	 * @param int $product_id Product.
	 */
	public function delete_product( int $product_id ): void {
		global $wpdb;

		$wpdb->delete( $this->table(), array( 'product_id' => $product_id ) );
	}

	/**
	 * Delete rows whose product no longer exists or left the scanned statuses.
	 *
	 * @return int Rows removed.
	 */
	public function prune_stale(): int {
		global $wpdb;

		return (int) $wpdb->query(
			$wpdb->prepare(
				"DELETE m FROM %i m LEFT JOIN %i p ON p.ID = m.product_id AND p.post_type = 'product' AND p.post_status IN ('publish', 'private', 'draft', 'pending', 'future') WHERE p.ID IS NULL",
				$this->table(),
				$wpdb->posts
			)
		);
	}

	/**
	 * Product counts per readiness status (products never scanned count as "unscanned").
	 *
	 * @return array<string,int>
	 */
	public function summary(): array {
		global $wpdb;

		$rows = (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT COALESCE(m.readiness_status, 'unscanned') AS status, COUNT(*) AS n FROM %i p LEFT JOIN %i m ON m.product_id = p.ID AND m.variant_id = 0 WHERE p.post_type = 'product' AND p.post_status IN ('publish', 'private', 'draft', 'pending', 'future') GROUP BY status",
				$wpdb->posts,
				$this->table()
			),
			ARRAY_A
		);

		$out = array(
			'ready'        => 0,
			'needs_image'  => 0,
			'needs_review' => 0,
			'unsupported'  => 0,
			'unscanned'    => 0,
		);
		foreach ( $rows as $row ) {
			$out[ (string) $row['status'] ] = (int) $row['n'];
		}
		$out['total'] = array_sum( $out );

		return $out;
	}

	/**
	 * Products with their product-level readiness row, for the Catalog screen.
	 *
	 * @param array{status?:string,search?:string,orderby?:string,order?:string,page?:int,per_page?:int} $args Query.
	 * @return array{items:array<int,array<string,mixed>>,total:int}
	 */
	public function list( array $args ): array {
		global $wpdb;

		$per_page = max( 1, min( 200, (int) ( $args['per_page'] ?? 20 ) ) );
		$page     = max( 1, (int) ( $args['page'] ?? 1 ) );

		// Every filter and sort order is always in the SQL, switched off by an empty or
		// non-matching value, so the query text is fixed and all input is a placeholder.
		// A status filter matches the summary() counts ("unscanned" = no score yet).
		$status = (string) ( $args['status'] ?? '' );
		if ( ! in_array( $status, array( 'unscanned', 'ready', 'needs_image', 'needs_review', 'unsupported' ), true ) ) {
			$status = '';
		}
		$search = trim( (string) ( $args['search'] ?? '' ) );
		$like   = '' === $search ? '' : '%' . $wpdb->esc_like( $search ) . '%';

		$orderby = (string) ( $args['orderby'] ?? 'id' );
		if ( ! in_array( $orderby, array( 'title', 'readiness', 'scanned', 'id' ), true ) ) {
			$orderby = 'id';
		}
		$sort = $orderby . '_' . ( 'asc' === strtolower( (string) ( $args['order'] ?? 'desc' ) ) ? 'asc' : 'desc' );

		$total = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM %i p LEFT JOIN %i m ON m.product_id = p.ID AND m.variant_id = 0
				WHERE p.post_type = 'product' AND p.post_status IN ('publish', 'private', 'draft', 'pending', 'future')
				AND ( %s = '' OR COALESCE(m.readiness_status, 'unscanned') = %s )
				AND ( %s = '' OR p.post_title LIKE %s )",
				$wpdb->posts,
				$this->table(),
				$status,
				$status,
				$like,
				$like
			)
		);

		$items = (array) $wpdb->get_results(
			$wpdb->prepare(
				// m.* first so an unscanned product's NULL m.product_id cannot overwrite p.ID.
				"SELECT m.*, p.ID AS product_id, p.post_title, p.post_status
				FROM %i p LEFT JOIN %i m ON m.product_id = p.ID AND m.variant_id = 0
				WHERE p.post_type = 'product' AND p.post_status IN ('publish', 'private', 'draft', 'pending', 'future')
				AND ( %s = '' OR COALESCE(m.readiness_status, 'unscanned') = %s )
				AND ( %s = '' OR p.post_title LIKE %s )
				ORDER BY
					CASE WHEN %s = 'title_asc' THEN p.post_title END ASC,
					CASE WHEN %s = 'title_desc' THEN p.post_title END DESC,
					CASE WHEN %s = 'readiness_asc' THEN m.readiness_score END ASC,
					CASE WHEN %s = 'readiness_desc' THEN m.readiness_score END DESC,
					CASE WHEN %s = 'scanned_asc' THEN m.scanned_at END ASC,
					CASE WHEN %s = 'scanned_desc' THEN m.scanned_at END DESC,
					CASE WHEN %s = 'id_asc' THEN p.ID END ASC,
					p.ID DESC
				LIMIT %d OFFSET %d",
				$wpdb->posts,
				$this->table(),
				$status,
				$status,
				$like,
				$like,
				$sort,
				$sort,
				$sort,
				$sort,
				$sort,
				$sort,
				$sort,
				$per_page,
				( $page - 1 ) * $per_page
			),
			ARRAY_A
		);

		return array(
			'items' => $items,
			'total' => $total,
		);
	}

	/**
	 * Merchant override for one field (value null clears it back to automatic).
	 *
	 * @param int    $product_id Product.
	 * @param string $field      One of OVERRIDABLE keys.
	 * @param mixed  $value      Value, or null to reset.
	 */
	public function set_override( int $product_id, string $field, $value ): void {
		if ( ! isset( self::OVERRIDABLE[ $field ] ) ) {
			return;
		}

		$source = self::OVERRIDABLE[ $field ];
		if ( null === $value ) {
			$this->upsert( $product_id, 0, array( $source => 'ai' ) );
			return;
		}

		$this->upsert(
			$product_id,
			0,
			array(
				$field  => $value,
				$source => 'merchant',
			)
		);
	}

	/**
	 * Try-on counts per product (storefront generations), for listed products.
	 *
	 * @param int[] $product_ids Products.
	 * @return array<int,int>
	 */
	public function tryon_counts( array $product_ids ): array {
		global $wpdb;

		$product_ids = array_values( array_filter( array_map( 'intval', $product_ids ) ) );
		if ( ! $product_ids ) {
			return array();
		}
		$rows = (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT product_id, COUNT(*) AS n FROM %i WHERE kind = 'tryon' AND context = 'storefront' AND FIND_IN_SET(product_id, %s) GROUP BY product_id",
				Schema::table( 'generations' ),
				implode( ',', $product_ids )
			),
			ARRAY_A
		);

		$out = array();
		foreach ( $rows as $row ) {
			$out[ (int) $row['product_id'] ] = (int) $row['n'];
		}

		return $out;
	}
}

<?php
/**
 * Private and Media Library assets with provenance.
 *
 * @package WearGraph
 */

namespace WearGraph\Database\Repositories;

defined( 'ABSPATH' ) || exit;

use WearGraph\Database\Schema;
use WearGraph\Support\Ulid;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table.

/**
 * Asset rows. Paths are stored relative to the private store, never absolute.
 */
final class AssetsRepository {

	public const TYPE_SHOPPER_ORIGINAL   = 'shopper_original';
	public const TYPE_SHOPPER_NORMALIZED = 'shopper_normalized';
	public const TYPE_TRYON_OUTPUT       = 'tryon_output';
	public const TYPE_STUDIO_OUTPUT      = 'studio_output';
	public const TYPE_REFERENCE          = 'reference';

	/**
	 * Table name.
	 */
	private function table(): string {
		return Schema::table( 'assets' );
	}

	/**
	 * Insert an asset.
	 *
	 * @param array<string,mixed> $data Columns.
	 * @return array<string,mixed>
	 */
	public function create( array $data ): array {
		global $wpdb;

		$row = array_merge(
			array(
				'public_id'  => Ulid::generate(),
				'storage'    => 'private',
				'created_at' => current_time( 'mysql', true ),
			),
			$data
		);
		if ( isset( $row['provenance_json'] ) && is_array( $row['provenance_json'] ) ) {
			$row['provenance_json'] = wp_json_encode( $row['provenance_json'] );
		}

		$wpdb->insert( $this->table(), $row );

		return $this->find( (int) $wpdb->insert_id );
	}

	/**
	 * By internal id (excludes deleted).
	 *
	 * @param int $id Id.
	 * @return array<string,mixed>|null
	 */
	public function find( int $id ): ?array {
		global $wpdb;

		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d AND deleted_at IS NULL', $this->table(), $id ), ARRAY_A );

		return $row ? $row : null;
	}

	/**
	 * By public id (excludes deleted).
	 *
	 * @param string $public_id ULID.
	 * @return array<string,mixed>|null
	 */
	public function find_public( string $public_id ): ?array {
		global $wpdb;

		if ( ! Ulid::is_valid( $public_id ) ) {
			return null;
		}

		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE public_id = %s AND deleted_at IS NULL', $this->table(), $public_id ), ARRAY_A );

		return $row ? $row : null;
	}

	/**
	 * Several assets by id, in the given order, skipping missing or deleted ones.
	 *
	 * @param int[] $ids Ids.
	 * @return array<int,array<string,mixed>>
	 */
	public function find_many( array $ids ): array {
		$out = array();
		foreach ( $ids as $id ) {
			$row = $this->find( (int) $id );
			if ( $row ) {
				$out[] = $row;
			}
		}

		return $out;
	}

	/**
	 * Update columns.
	 *
	 * @param int                 $id     Id.
	 * @param array<string,mixed> $fields Columns.
	 */
	public function update( int $id, array $fields ): void {
		global $wpdb;

		unset( $fields['id'], $fields['public_id'] );
		$wpdb->update( $this->table(), $fields, array( 'id' => $id ) );
	}

	/**
	 * Remove the row entirely (the file is removed by the caller). Used when the owning
	 * generation itself is deleted, so no orphan rows remain.
	 *
	 * @param int $id Id.
	 */
	public function delete( int $id ): void {
		global $wpdb;

		$wpdb->delete( $this->table(), array( 'id' => $id ) );
	}

	/**
	 * Mark deleted (the file is removed by the caller).
	 *
	 * @param int $id Id.
	 */
	public function mark_deleted( int $id ): void {
		global $wpdb;

		$wpdb->update( $this->table(), array( 'deleted_at' => current_time( 'mysql', true ) ), array( 'id' => $id ) );
	}
}

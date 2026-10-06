<?php
/**
 * Storefront shopper sessions.
 *
 * @package WearGraph
 */

namespace WearGraph\Database\Repositories;

defined( 'ABSPATH' ) || exit;

use WearGraph\Database\Schema;
use WearGraph\Support\Ulid;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table; per-request session state.

/**
 * Only a hash of the session token is stored, so a database leak cannot be used to
 * hijack shopper sessions.
 */
final class SessionsRepository {

	/**
	 * Table name.
	 */
	private function table(): string {
		return Schema::table( 'sessions' );
	}

	/**
	 * Create a session.
	 *
	 * @param string $token_hash sha256 of the cookie token.
	 * @param int    $ttl        Seconds until expiry.
	 * @param string $ip_hash    Keyed hash of the IP.
	 * @param string $ua_hash    Keyed hash of the user agent.
	 * @param int    $user_id    Logged-in customer (0 for guests).
	 * @return array<string,mixed>
	 */
	public function create( string $token_hash, int $ttl, string $ip_hash, string $ua_hash, int $user_id = 0 ): array {
		global $wpdb;

		$now = time();
		$wpdb->insert(
			$this->table(),
			array(
				'public_id'  => Ulid::generate(),
				'token_hash' => $token_hash,
				'ip_hash'    => $ip_hash,
				'ua_hash'    => $ua_hash,
				'user_id'    => $user_id > 0 ? $user_id : null,
				'created_at' => gmdate( 'Y-m-d H:i:s', $now ),
				'expires_at' => gmdate( 'Y-m-d H:i:s', $now + $ttl ),
			)
		);
		$id = (int) $wpdb->insert_id;

		/**
		 * A fitting-room session was created (Pro: reattach a customer's saved photo).
		 *
		 * @param array<string,mixed> $session Session row.
		 */
		do_action( 'weargraph_session_created', $this->find( $id ) );

		return $this->find( $id );
	}

	/**
	 * By id.
	 *
	 * @param int $id Id.
	 * @return array<string,mixed>|null
	 */
	public function find( int $id ): ?array {
		global $wpdb;

		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', $this->table(), $id ), ARRAY_A );

		return $row ? $row : null;
	}

	/**
	 * By public id (live or expired).
	 *
	 * @param string $public_id Session ULID.
	 * @return array<string,mixed>|null
	 */
	public function find_public( string $public_id ): ?array {
		global $wpdb;

		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE public_id = %s', $this->table(), $public_id ), ARRAY_A );

		return $row ? $row : null;
	}

	/**
	 * Live (unexpired) session by token hash.
	 *
	 * @param string $token_hash sha256 of the cookie token.
	 * @return array<string,mixed>|null
	 */
	public function find_live( string $token_hash ): ?array {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM %i WHERE token_hash = %s AND expires_at > %s', $this->table(), $token_hash, gmdate( 'Y-m-d H:i:s' ) ),
			ARRAY_A
		);

		return $row ? $row : null;
	}

	/**
	 * Update columns.
	 *
	 * @param int                 $id     Id.
	 * @param array<string,mixed> $fields Columns.
	 */
	public function update( int $id, array $fields ): void {
		global $wpdb;

		unset( $fields['id'], $fields['public_id'], $fields['token_hash'] );
		$wpdb->update( $this->table(), $fields, array( 'id' => $id ) );
	}

	/**
	 * Atomically increment a counter if it is below a limit.
	 *
	 * @param int    $id     Session id.
	 * @param string $column tryon_count | regen_count.
	 * @param int    $limit  Maximum.
	 * @return bool Whether the increment happened (false = limit reached).
	 */
	public function increment_below( int $id, string $column, int $limit ): bool {
		global $wpdb;

		if ( ! in_array( $column, array( 'tryon_count', 'regen_count' ), true ) ) {
			return false;
		}

		$affected = $wpdb->query( $wpdb->prepare( 'UPDATE %i SET %i = %i + 1 WHERE id = %d AND %i < %d', $this->table(), $column, $column, $id, $column, $limit ) );

		return 1 === (int) $affected;
	}

	/**
	 * Undo one increment (the request was refused later).
	 *
	 * @param int    $id     Session id.
	 * @param string $column Column.
	 */
	public function decrement( int $id, string $column ): void {
		global $wpdb;

		if ( in_array( $column, array( 'tryon_count', 'regen_count' ), true ) ) {
			$wpdb->query( $wpdb->prepare( 'UPDATE %i SET %i = GREATEST(%i - 1, 0) WHERE id = %d', $this->table(), $column, $column, $id ) );
		}
	}

	/**
	 * Sessions of a customer (privacy export/erase).
	 *
	 * @param int $user_id User.
	 * @param int $limit   Page size.
	 * @param int $offset  Offset.
	 * @return array<int,array<string,mixed>>
	 */
	public function for_user( int $user_id, int $limit = 50, int $offset = 0 ): array {
		global $wpdb;

		return (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE user_id = %d ORDER BY id ASC LIMIT %d OFFSET %d', $this->table(), $user_id, $limit, $offset ), ARRAY_A );
	}

	/**
	 * Delete a session row.
	 *
	 * @param int $id Id.
	 */
	public function delete( int $id ): void {
		global $wpdb;

		$wpdb->delete( $this->table(), array( 'id' => $id ) );
	}
}

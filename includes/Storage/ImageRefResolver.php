<?php
/**
 * Turns stored image references into local files for providers.
 *
 * @package WearGraph
 */

namespace WearGraph\Storage;

defined( 'ABSPATH' ) || exit;

use WearGraph\Database\Repositories\AssetsRepository;
use WearGraph\Domain\ImageRef;

/**
 * Generation specs never store absolute paths. An image reference is either
 * array( 'type' => 'attachment', 'id' => 123 ) — a Media Library image — or
 * array( 'type' => 'asset', 'id' => 'ULID' ) — a file in the private store.
 */
final class ImageRefResolver {

	private const ALLOWED_MIMES = array( 'image/jpeg', 'image/png', 'image/webp' );

	/**
	 * Asset rows.
	 *
	 * @var AssetsRepository
	 */
	private $assets;

	/**
	 * Fingerprint cache for this request.
	 *
	 * @var array<string,string>
	 */
	private $fingerprints = array();

	/**
	 * Constructor.
	 *
	 * @param AssetsRepository $assets Asset rows.
	 */
	public function __construct( AssetsRepository $assets ) {
		$this->assets = $assets;
	}

	/**
	 * Resolve a reference to a readable local image.
	 *
	 * @param array<string,mixed> $ref  Reference.
	 * @param string              $role ImageRef role.
	 * @throws \InvalidArgumentException When the image is missing or not a supported type.
	 */
	public function resolve( array $ref, string $role ): ImageRef {
		list( $path, $mime ) = $this->locate( $ref );

		if ( '' === $path || ! is_readable( $path ) ) {
			throw new \InvalidArgumentException( 'Image not found.' );
		}
		if ( ! in_array( $mime, self::ALLOWED_MIMES, true ) ) {
			throw new \InvalidArgumentException( 'Unsupported image type.' );
		}

		return new ImageRef( $path, $mime, $role );
	}

	/**
	 * Content hash of a referenced image (for idempotency keys). '' when missing.
	 *
	 * @param array<string,mixed> $ref Reference.
	 */
	public function fingerprint( array $ref ): string {
		$cache_key = (string) ( $ref['type'] ?? '' ) . ':' . (string) ( $ref['id'] ?? '' );
		if ( isset( $this->fingerprints[ $cache_key ] ) ) {
			return $this->fingerprints[ $cache_key ];
		}

		$hash = '';
		if ( 'asset' === ( $ref['type'] ?? '' ) ) {
			$asset = $this->assets->find_public( (string) $ref['id'] );
			$hash  = $asset ? (string) $asset['sha256'] : '';
		} else {
			list( $path ) = $this->locate( $ref );
			$hash         = '' !== $path && is_readable( $path ) ? (string) hash_file( 'sha256', $path ) : '';
		}

		$this->fingerprints[ $cache_key ] = $hash;

		return $hash;
	}

	/**
	 * Normalise a user-supplied reference; null when malformed.
	 *
	 * @param mixed $ref Candidate.
	 * @return array{type:string,id:int|string}|null
	 */
	public static function normalize( $ref ): ?array {
		if ( ! is_array( $ref ) ) {
			return null;
		}
		$type = (string) ( $ref['type'] ?? '' );
		if ( 'attachment' === $type && (int) ( $ref['id'] ?? 0 ) > 0 ) {
			return array(
				'type' => 'attachment',
				'id'   => (int) $ref['id'],
			);
		}
		if ( 'asset' === $type && is_string( $ref['id'] ?? null ) && '' !== $ref['id'] ) {
			return array(
				'type' => 'asset',
				'id'   => (string) $ref['id'],
			);
		}

		return null;
	}

	/**
	 * Path and MIME for a reference.
	 *
	 * @param array<string,mixed> $ref Reference.
	 * @return array{0:string,1:string}
	 */
	private function locate( array $ref ): array {
		$type = (string) ( $ref['type'] ?? '' );

		if ( 'attachment' === $type ) {
			$id = (int) ( $ref['id'] ?? 0 );
			if ( $id <= 0 || 'attachment' !== get_post_type( $id ) ) {
				return array( '', '' );
			}

			return array( (string) get_attached_file( $id ), (string) get_post_mime_type( $id ) );
		}

		if ( 'asset' === $type ) {
			$asset = $this->assets->find_public( (string) ( $ref['id'] ?? '' ) );
			if ( ! $asset || 'private' !== $asset['storage'] ) {
				return array( '', '' );
			}

			return array( PrivateStore::absolute( (string) $asset['path'] ), (string) $asset['mime'] );
		}

		return array( '', '' );
	}
}

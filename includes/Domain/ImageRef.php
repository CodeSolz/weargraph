<?php
/**
 * A local image handed to a provider.
 *
 * @package WearGraph
 */

namespace WearGraph\Domain;

defined( 'ABSPATH' ) || exit;

/**
 * Local file path plus its MIME type and role. Providers never receive URLs —
 * files are read from disk so no remote fetch (and no SSRF surface) exists.
 */
final class ImageRef {

	public const ROLE_SOURCE    = 'source';
	public const ROLE_REFERENCE = 'reference';
	public const ROLE_PERSON    = 'person';
	public const ROLE_GARMENT   = 'garment';
	public const ROLE_MASK      = 'mask';

	private const MIMES = array( 'image/jpeg', 'image/png', 'image/webp' );

	/**
	 * Absolute file path.
	 *
	 * @var string
	 */
	public $path;

	/**
	 * MIME type.
	 *
	 * @var string
	 */
	public $mime;

	/**
	 * Role in the request.
	 *
	 * @var string
	 */
	public $role;

	/**
	 * Constructor.
	 *
	 * @param string $path Absolute path.
	 * @param string $mime One of image/jpeg, image/png, image/webp.
	 * @param string $role One of the ROLE_* constants.
	 * @throws \InvalidArgumentException On an unsupported MIME type or empty path.
	 */
	public function __construct( string $path, string $mime, string $role = self::ROLE_REFERENCE ) {
		if ( '' === $path ) {
			throw new \InvalidArgumentException( 'Image path is empty.' );
		}
		if ( ! in_array( $mime, self::MIMES, true ) ) {
			throw new \InvalidArgumentException( 'Unsupported image type.' );
		}

		$this->path = $path;
		$this->mime = $mime;
		$this->role = $role;
	}
}

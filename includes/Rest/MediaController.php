<?php
/**
 * REST: stream a private asset behind a signed token.
 *
 * @package WearGraph
 */

namespace WearGraph\Rest;

defined( 'ABSPATH' ) || exit;

use WearGraph\Database\Repositories\AssetsRepository;
use WearGraph\Storage\PrivateStore;
use WearGraph\Storage\SignedUrl;
use WearGraph\Support\Files;

/**
 * GET /weargraph/v1/media/{token}. The HMAC token is the authorisation (an <img>
 * tag cannot send a REST nonce). Tokens expire after SignedUrl::TTL. Tokens bound to
 * a shopper session are also checked against the visitor's session via the
 * weargraph_media_session_ok filter (implemented with the storefront, F5).
 */
final class MediaController {

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
	 * Register the route.
	 */
	public function register_routes(): void {
		register_rest_route(
			GenerationsController::NAMESPACE_V1,
			'/media/(?P<token>[A-Za-z0-9_\-]+\.[a-f0-9]{64})',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'serve' ),
				'permission_callback' => '__return_true', // Authorised by the signed token in serve().
			)
		);
	}

	/**
	 * Stream the file, or answer 403 / 404.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_Error|void
	 */
	public function serve( \WP_REST_Request $request ) {
		$claims = SignedUrl::verify( (string) $request['token'] );
		if ( ! $claims ) {
			return new \WP_Error( 'weargraph_forbidden', __( 'This link has expired.', 'weargraph' ), array( 'status' => 403 ) );
		}

		$asset = $this->assets->find_public( $claims['asset'] );
		if ( ! $asset || 'private' !== $asset['storage'] ) {
			return new \WP_Error( 'weargraph_not_found', __( 'Not found.', 'weargraph' ), array( 'status' => 404 ) );
		}

		/**
		 * Whether the visitor owns the shopper session a media token is bound to.
		 *
		 * @param bool   $ok         Default false.
		 * @param string $session_id Session the token is bound to.
		 */
		if ( '' !== $claims['session'] && ! apply_filters( 'weargraph_media_session_ok', false, $claims['session'] ) ) {
			return new \WP_Error( 'weargraph_forbidden', __( 'This link has expired.', 'weargraph' ), array( 'status' => 403 ) );
		}

		$file = PrivateStore::absolute( (string) $asset['path'] );
		$size = '' !== $file && false === strpos( $file, '://' ) && is_readable( $file ) ? (int) filesize( $file ) : 0;
		if ( $size <= 0 ) {
			return new \WP_Error( 'weargraph_not_found', __( 'Not found.', 'weargraph' ), array( 'status' => 404 ) );
		}

		$mime = in_array( $asset['mime'], array( 'image/png', 'image/jpeg', 'image/webp', 'video/mp4' ), true ) ? $asset['mime'] : 'application/octet-stream';

		nocache_headers();
		header( 'Content-Type: ' . $mime );
		header( 'Content-Length: ' . $size );
		header( 'Cache-Control: private, no-store, max-age=0' );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'Content-Disposition: inline; filename="weargraph-' . $asset['public_id'] . '"' );

		// Streamed, never echoed: a binary image or video body is not HTML (as wp-includes/ms-files.php).
		readfile( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile -- WP_Filesystem has no streaming read.
		exit;
	}
}

<?php
/**
 * REST: shopper-facing fitting-room endpoints.
 *
 * @package WearGraph
 */

namespace WearGraph\Rest;

defined( 'ABSPATH' ) || exit;

use WearGraph\Domain\EventSchema;
use WearGraph\Generation\GenerationException;
use WearGraph\Storage\ImageSanitizer;
use WearGraph\Storage\SanitizeException;
use WearGraph\Storefront\SessionManager;
use WearGraph\Storefront\StorefrontService;
use WearGraph\Support\RateLimiter;

/**
 * GET    /storefront/session?product_id=  start/resume session; CSRF token; product data
 * POST   /storefront/photo                upload (multipart "photo", "consent")
 * DELETE /storefront/photo                delete all photos and results of the session
 * POST   /storefront/tryons               { product_id, variation_id, regenerate }
 * POST   /storefront/events               { name, product_id, variation_id } (client-only events)
 *
 * Mutations require the session cookie AND the X-WearGraph-CSRF header. Polling uses
 * GET /generations/{id} (session-owned, see SessionManager::owns_generation()).
 * Add to cart uses WooCommerce's own wc-ajax=add_to_cart (keeps the shopper's cart).
 */
final class StorefrontController {

	/**
	 * Sessions.
	 *
	 * @var SessionManager
	 */
	private $sessions;

	/**
	 * Storefront rules.
	 *
	 * @var StorefrontService
	 */
	private $storefront;

	/**
	 * Constructor.
	 *
	 * @param SessionManager    $sessions   Sessions.
	 * @param StorefrontService $storefront Storefront rules.
	 */
	public function __construct( SessionManager $sessions, StorefrontService $storefront ) {
		$this->sessions   = $sessions;
		$this->storefront = $storefront;
	}

	/**
	 * Register routes.
	 */
	public function register_routes(): void {
		$ns = GenerationsController::NAMESPACE_V1;

		register_rest_route(
			$ns,
			'/storefront/session',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'session' ),
				'permission_callback' => '__return_true', // Public: starts an anonymous fitting-room session.
			)
		);
		register_rest_route(
			$ns,
			'/storefront/photo',
			array(
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'upload_photo' ),
					'permission_callback' => array( $this, 'can_mutate' ),
				),
				array(
					'methods'             => \WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_photos' ),
					'permission_callback' => array( $this, 'can_mutate' ),
				),
			)
		);
		register_rest_route(
			$ns,
			'/storefront/tryons',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'tryon' ),
				'permission_callback' => array( $this, 'can_mutate' ),
			)
		);
		register_rest_route(
			$ns,
			'/storefront/events',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'event' ),
				'permission_callback' => array( $this, 'can_mutate' ),
			)
		);
	}

	/**
	 * Session cookie + CSRF header.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return bool|\WP_Error
	 */
	public function can_mutate( \WP_REST_Request $request ) {
		return $this->sessions->verify_csrf( $request )
			? true
			: new \WP_Error( 'weargraph_session', __( 'Your fitting-room session expired. Please reload the page.', 'weargraph' ), array( 'status' => 403 ) );
	}

	/**
	 * GET /storefront/session.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function session( \WP_REST_Request $request ) {
		if ( ! RateLimiter::hit( 'session-ip', RateLimiter::ip_hash(), 120, HOUR_IN_SECONDS ) ) {
			return new \WP_Error( 'weargraph_rate', __( 'Too many requests. Please wait a moment.', 'weargraph' ), array( 'status' => 429 ) );
		}

		$product_id = absint( $request['product_id'] );
		$product    = $product_id ? $this->storefront->product_payload( $product_id ) : null;
		if ( $product_id && ! $product ) {
			return $this->nocache( array( 'available' => false ) );
		}

		$session = $this->sessions->start();
		if ( $product ) {
			/**
			 * A shopper opened the fitting room for a product.
			 *
			 * @param int    $product_id Product.
			 * @param string $session_id Session public id.
			 */
			do_action( 'weargraph_storefront_opened', $product_id, (string) $session['public_id'] );
		}

		/**
		 * Fitting-room configuration sent to the browser (Pro adds profile, looks…).
		 *
		 * @param array<string,mixed> $config  Config.
		 * @param array<string,mixed> $session Session row.
		 */
		$config = apply_filters(
			'weargraph_modal_config',
			array(
				'available' => true,
				'session'   => $this->storefront->session_payload( $session ),
				'product'   => $product,
			),
			$session
		);

		return $this->nocache( $config );
	}

	/**
	 * POST /storefront/photo.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function upload_photo( \WP_REST_Request $request ) {
		if ( ! RateLimiter::hit( 'photo-ip', RateLimiter::ip_hash(), 30, HOUR_IN_SECONDS ) ) {
			return new \WP_Error( 'weargraph_rate', __( 'Too many uploads. Please wait a moment.', 'weargraph' ), array( 'status' => 429 ) );
		}

		$files = $request->get_file_params();
		$file  = $files['photo'] ?? null;
		if ( ! is_array( $file ) || ! empty( $file['error'] ) || empty( $file['tmp_name'] ) || ! is_uploaded_file( $file['tmp_name'] ) ) {
			return $this->photo_error( 'unreadable' );
		}

		try {
			$image = ImageSanitizer::sanitize( (string) $file['tmp_name'] );
		} catch ( SanitizeException $e ) {
			$session = $this->sessions->current();

			/**
			 * A shopper's photo arrived but was refused (no image data passed).
			 *
			 * @param string $reason     too_large|too_small|bad_type|corrupt.
			 * @param string $session_id Session public id.
			 */
			do_action( 'weargraph_storefront_photo_rejected', $e->reason(), $session ? (string) $session['public_id'] : '' );

			return $this->photo_error( $e->reason() );
		}

		try {
			$payload = $this->storefront->save_photo( $this->sessions->current(), $image, (bool) $request['consent'] );
		} catch ( GenerationException $e ) {
			return new \WP_Error( 'weargraph_' . $e->error_code(), $e->getMessage(), array( 'status' => $e->http_status() ) );
		}

		return $this->nocache( $payload );
	}

	/**
	 * DELETE /storefront/photo.
	 */
	public function delete_photos(): \WP_REST_Response {
		$removed = $this->storefront->delete_session_media( $this->sessions->current() );

		return $this->nocache(
			array(
				'removed' => $removed,
				'session' => $this->storefront->session_payload( $this->sessions->refresh() ),
			)
		);
	}

	/**
	 * POST /storefront/tryons.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function tryon( \WP_REST_Request $request ) {
		try {
			$result = $this->storefront->request_tryon(
				$this->sessions->current(),
				absint( $request['product_id'] ),
				absint( $request['variation_id'] ),
				(bool) $request['regenerate']
			);
		} catch ( GenerationException $e ) {
			return new \WP_Error( 'weargraph_' . $e->error_code(), $e->getMessage(), array( 'status' => $e->http_status() ) );
		}

		$session = $this->sessions->refresh();

		return new \WP_REST_Response(
			array_merge(
				$result,
				array(
					'tryons' => $this->storefront->session_payload( $session )['tryons'],
					'regens' => $this->storefront->session_payload( $session )['regens'],
				)
			),
			$result['cached'] ? 200 : 202
		);
	}

	/**
	 * POST /storefront/events: the few events only the browser sees (EventSchema::CLIENT_NAMES).
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function event( \WP_REST_Request $request ) {
		$name    = sanitize_key( (string) $request['name'] );
		$session = $this->sessions->current();
		if ( ! $session || ! EventSchema::is_client_name( $name ) ) {
			return new \WP_Error( 'weargraph_event', __( 'Unknown event.', 'weargraph' ), array( 'status' => 400 ) );
		}
		if ( ! RateLimiter::hit( 'event-session', (string) $session['public_id'], 120, HOUR_IN_SECONDS ) ) {
			return new \WP_Error( 'weargraph_rate', __( 'Too many requests. Please wait a moment.', 'weargraph' ), array( 'status' => 429 ) );
		}

		$product_id = absint( $request['product_id'] );
		if ( ! $product_id || ! $this->storefront->product_enabled( $product_id ) ) {
			return new \WP_Error( 'weargraph_event', __( 'Unknown product.', 'weargraph' ), array( 'status' => 400 ) );
		}

		/**
		 * A browser-reported event (the recorder validates it again).
		 *
		 * @param string $name         Event name.
		 * @param string $session_id   Session public id.
		 * @param int    $product_id   Product.
		 * @param int    $variation_id Variation.
		 */
		do_action( 'weargraph_storefront_client_event', $name, (string) $session['public_id'], $product_id, absint( $request['variation_id'] ) );

		return new \WP_REST_Response( null, 204 );
	}

	/**
	 * Upload failure → shopper message.
	 *
	 * @param string $reason SanitizeException code.
	 */
	private function photo_error( string $reason ): \WP_Error {
		$messages = array(
			'too_large'  => __( 'This photo is too large. Please use a photo under 10 MB.', 'weargraph' ),
			'too_small'  => __( 'This photo is too small. Please use a sharper photo (at least 512 pixels wide).', 'weargraph' ),
			'bad_type'   => __( 'Please use a JPG or PNG photo.', 'weargraph' ),
			'corrupt'    => __( 'This photo could not be read. Please try another one.', 'weargraph' ),
			'unreadable' => __( 'The photo did not arrive. Please try again.', 'weargraph' ),
		);

		return new \WP_Error( 'weargraph_photo_' . $reason, $messages[ $reason ] ?? $messages['corrupt'], array( 'status' => 400 ) );
	}

	/**
	 * Response that must never be cached.
	 *
	 * @param array<string,mixed> $data Data.
	 */
	private function nocache( array $data ): \WP_REST_Response {
		$response = new \WP_REST_Response( $data );
		$response->header( 'Cache-Control', 'no-store, private' );

		return $response;
	}
}

<?php
/**
 * REST: generation status and cancel.
 *
 * @package WearGraph
 */

namespace WearGraph\Rest;

defined( 'ABSPATH' ) || exit;

use WearGraph\Database\Repositories\GenerationsRepository;
use WearGraph\Services\GenerationService;
use WearGraph\Support\Capabilities;

/**
 * GET  /weargraph/v1/generations/{id}
 * POST /weargraph/v1/generations/{id}/cancel
 *
 * Merchants with the studio capability can read any generation. Anyone else needs
 * the weargraph_generation_access filter to vouch for them — the storefront session
 * check (F5) hooks there.
 */
final class GenerationsController {

	public const NAMESPACE_V1 = 'weargraph/v1';

	/**
	 * Generations.
	 *
	 * @var GenerationsRepository
	 */
	private $generations;

	/**
	 * Service.
	 *
	 * @var GenerationService
	 */
	private $service;

	/**
	 * Constructor.
	 *
	 * @param GenerationsRepository $generations Generations.
	 * @param GenerationService     $service     Service.
	 */
	public function __construct( GenerationsRepository $generations, GenerationService $service ) {
		$this->generations = $generations;
		$this->service     = $service;
	}

	/**
	 * Register routes (rest_api_init).
	 */
	public function register_routes(): void {
		$id = '(?P<id>[0-9A-HJKMNP-TV-Z]{26})';

		register_rest_route(
			self::NAMESPACE_V1,
			'/generations/' . $id,
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_item' ),
				'permission_callback' => array( $this, 'can_access' ),
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/generations/' . $id . '/cancel',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'cancel_item' ),
				'permission_callback' => array( $this, 'can_access' ),
			)
		);
	}

	/**
	 * Permission check.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return bool|\WP_Error
	 */
	public function can_access( \WP_REST_Request $request ) {
		$row = $this->generations->find_public( (string) $request['id'] );
		if ( ! $row ) {
			return new \WP_Error( 'weargraph_not_found', __( 'Not found.', 'weargraph' ), array( 'status' => 404 ) );
		}

		if ( current_user_can( Capabilities::STUDIO ) ) {
			return true;
		}

		/**
		 * Whether the current visitor may read or cancel this generation.
		 *
		 * @param bool                $allowed Default false.
		 * @param array<string,mixed> $row     Generation row.
		 * @param \WP_REST_Request    $request Request.
		 */
		if ( apply_filters( 'weargraph_generation_access', false, $row, $request ) ) {
			return true;
		}

		// Same answer as a missing id, so ids cannot be probed.
		return new \WP_Error( 'weargraph_not_found', __( 'Not found.', 'weargraph' ), array( 'status' => 404 ) );
	}

	/**
	 * GET handler.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function get_item( \WP_REST_Request $request ): \WP_REST_Response {
		$row      = $this->generations->find_public( (string) $request['id'] );
		$response = new \WP_REST_Response( $this->service->present( $row, current_user_can( Capabilities::STUDIO ) ) );
		$response->header( 'Cache-Control', 'no-store' );

		return $response;
	}

	/**
	 * Cancel handler.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function cancel_item( \WP_REST_Request $request ): \WP_REST_Response {
		$row = $this->generations->find_public( (string) $request['id'] );
		$this->service->cancel( $row );

		return new \WP_REST_Response( $this->service->present( $this->generations->find( (int) $row['id'] ), current_user_can( Capabilities::STUDIO ) ) );
	}
}

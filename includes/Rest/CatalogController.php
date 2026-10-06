<?php
/**
 * REST: catalog readiness, scans, overrides.
 *
 * @package WearGraph
 */

namespace WearGraph\Rest;

defined( 'ABSPATH' ) || exit;

use WearGraph\Catalog\Pixels\Sampler;
use WearGraph\Database\Repositories\CatalogRepository;
use WearGraph\Jobs\CatalogScanJob;
use WearGraph\Services\CatalogService;
use WearGraph\Support\Capabilities;

/**
 * GET  /catalog/summary            counts + scan state            (view reports)
 * GET  /catalog/scan               scan state                      (view reports)
 * POST /catalog/scan               start {force}                   (manage)
 * POST /catalog/scan/cancel        stop                            (manage)
 * GET  /catalog/products/{id}      readiness detail                (studio + edit product)
 * POST /catalog/products/{id}      overrides {category,layer,tryon,best_image_id,reset} (studio + edit product)
 * POST /catalog/bulk               {ids[],action,value}            (manage)
 */
final class CatalogController {

	/**
	 * Rows.
	 *
	 * @var CatalogRepository
	 */
	private $repo;

	/**
	 * Scan job.
	 *
	 * @var CatalogScanJob
	 */
	private $job;

	/**
	 * Catalog service.
	 *
	 * @var CatalogService
	 */
	private $catalog;

	/**
	 * Constructor.
	 *
	 * @param CatalogRepository $repo    Rows.
	 * @param CatalogScanJob    $job     Scan job.
	 * @param CatalogService    $catalog Service.
	 */
	public function __construct( CatalogRepository $repo, CatalogScanJob $job, CatalogService $catalog ) {
		$this->repo    = $repo;
		$this->job     = $job;
		$this->catalog = $catalog;
	}

	/**
	 * Register routes.
	 */
	public function register_routes(): void {
		$ns = GenerationsController::NAMESPACE_V1;

		$this->route( $ns, '/catalog/summary', 'GET', 'summary', 'can_view' );
		$this->route( $ns, '/catalog/scan', 'GET', 'scan_status', 'can_view' );
		$this->route( $ns, '/catalog/scan', 'POST', 'scan_start', 'can_manage' );
		$this->route( $ns, '/catalog/scan/cancel', 'POST', 'scan_cancel', 'can_manage' );
		$this->route( $ns, '/catalog/products/(?P<id>\d+)', 'GET', 'product', 'can_edit_product' );
		$this->route( $ns, '/catalog/products/(?P<id>\d+)', 'POST', 'update_product', 'can_edit_product' );
		$this->route( $ns, '/catalog/bulk', 'POST', 'bulk', 'can_manage' );
	}

	/**
	 * Register one route.
	 *
	 * @param string $ns         Namespace.
	 * @param string $path       Path.
	 * @param string $method     Method.
	 * @param string $callback   Handler.
	 * @param string $permission Permission handler.
	 */
	private function route( string $ns, string $path, string $method, string $callback, string $permission ): void {
		register_rest_route(
			$ns,
			$path,
			array(
				'methods'             => $method,
				'callback'            => array( $this, $callback ),
				'permission_callback' => array( $this, $permission ),
			)
		);
	}

	/**
	 * Can view reports.
	 */
	public function can_view(): bool {
		return current_user_can( Capabilities::VIEW_REPORTS );
	}

	/**
	 * Can manage the catalog.
	 */
	public function can_manage(): bool {
		return current_user_can( Capabilities::MANAGE );
	}

	/**
	 * Can edit this product in the studio.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function can_edit_product( \WP_REST_Request $request ): bool {
		$id = absint( $request['id'] );

		return 'product' === get_post_type( $id ) && current_user_can( Capabilities::STUDIO ) && current_user_can( 'edit_post', $id );
	}

	/**
	 * GET /catalog/summary.
	 */
	public function summary(): \WP_REST_Response {
		return $this->nocache(
			array(
				'counts'        => $this->repo->summary(),
				'scan'          => $this->job->status(),
				'image_library' => Sampler::library(),
			)
		);
	}

	/**
	 * GET /catalog/scan.
	 */
	public function scan_status(): \WP_REST_Response {
		return $this->nocache( $this->job->status() );
	}

	/**
	 * POST /catalog/scan.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function scan_start( \WP_REST_Request $request ): \WP_REST_Response {
		return new \WP_REST_Response( $this->job->start( (bool) $request['force'] ), 202 );
	}

	/**
	 * POST /catalog/scan/cancel.
	 */
	public function scan_cancel(): \WP_REST_Response {
		return new \WP_REST_Response( $this->job->cancel() );
	}

	/**
	 * GET /catalog/products/{id}.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function product( \WP_REST_Request $request ): \WP_REST_Response {
		return $this->nocache( $this->catalog->detail( absint( $request['id'] ) ) );
	}

	/**
	 * POST /catalog/products/{id}.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function update_product( \WP_REST_Request $request ) {
		$id      = absint( $request['id'] );
		$changes = array();
		foreach ( array( 'category', 'layer', 'tryon', 'best_image_id' ) as $key ) {
			if ( null !== $request[ $key ] && '' !== $request[ $key ] ) {
				$changes[ $key ] = sanitize_text_field( (string) $request[ $key ] );
			}
		}
		$changes['reset'] = (bool) $request['reset'];

		$result = $this->catalog->update( $id, $changes );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return new \WP_REST_Response( $this->catalog->detail( $id ) );
	}

	/**
	 * POST /catalog/bulk.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function bulk( \WP_REST_Request $request ) {
		$action = sanitize_key( (string) $request['action'] );
		if ( ! in_array( $action, CatalogService::BULK_ACTIONS, true ) ) {
			return new \WP_Error( 'weargraph_bad_action', __( 'Unknown bulk action.', 'weargraph' ), array( 'status' => 400 ) );
		}

		$count = $this->catalog->bulk( array_map( 'absint', (array) $request['ids'] ), $action, sanitize_key( (string) $request['value'] ) );

		return new \WP_REST_Response( array( 'updated' => $count ) );
	}

	/**
	 * Response that must not be cached.
	 *
	 * @param array<string,mixed> $data Data.
	 */
	private function nocache( array $data ): \WP_REST_Response {
		$response = new \WP_REST_Response( $data );
		$response->header( 'Cache-Control', 'no-store' );

		return $response;
	}
}

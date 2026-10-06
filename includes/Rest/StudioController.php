<?php
/**
 * REST: merchant studio (generate, history, save to Media Library, alt text).
 *
 * @package WearGraph
 */

namespace WearGraph\Rest;

defined( 'ABSPATH' ) || exit;

use WearGraph\Commerce\ProductImages;
use WearGraph\Database\Repositories\AssetsRepository;
use WearGraph\Database\Repositories\GenerationsRepository;
use WearGraph\Domain\GenerationState;
use WearGraph\Domain\ImageOptions;
use WearGraph\Generation\GenerationException;
use WearGraph\Generation\Kinds\AltTextKind;
use WearGraph\Generation\Kinds\ImageGenerateKind;
use WearGraph\Services\GenerationService;
use WearGraph\Services\PresetLibrary;
use WearGraph\Services\PromptBuilder;
use WearGraph\Storage\MediaLibraryWriter;
use WearGraph\Support\Capabilities;

/**
 * Every route requires the weargraph_studio capability AND edit rights on the
 * product. Cookie-authenticated (X-WP-Nonce).
 */
final class StudioController {

	public const HISTORY_LIMIT = 20;

	/**
	 * Service.
	 *
	 * @var GenerationService
	 */
	private $service;

	/**
	 * Generations.
	 *
	 * @var GenerationsRepository
	 */
	private $generations;

	/**
	 * Assets.
	 *
	 * @var AssetsRepository
	 */
	private $assets;

	/**
	 * Media writer.
	 *
	 * @var MediaLibraryWriter
	 */
	private $media;

	/**
	 * Constructor.
	 *
	 * @param GenerationService     $service     Service.
	 * @param GenerationsRepository $generations Generations.
	 * @param AssetsRepository      $assets      Assets.
	 * @param MediaLibraryWriter    $media       Media writer.
	 */
	public function __construct( GenerationService $service, GenerationsRepository $generations, AssetsRepository $assets, MediaLibraryWriter $media ) {
		$this->service     = $service;
		$this->generations = $generations;
		$this->assets      = $assets;
		$this->media       = $media;
	}

	/**
	 * Register routes.
	 */
	public function register_routes(): void {
		$ns  = GenerationsController::NAMESPACE_V1;
		$gid = '(?P<id>[0-9A-HJKMNP-TV-Z]{26})';

		$this->route( $ns, '/studio/generate', 'POST', 'generate', 'product_permission' );
		$this->route( $ns, '/studio/history', 'GET', 'history', 'product_permission' );
		$this->route( $ns, '/studio/generations/' . $gid . '/rerun', 'POST', 'rerun', 'generation_permission' );
		$this->route( $ns, '/studio/generations/' . $gid, 'DELETE', 'delete', 'generation_permission' );
		$this->route( $ns, '/studio/generations/' . $gid . '/save', 'POST', 'save', 'generation_permission' );
		$this->route( $ns, '/studio/alt-text', 'POST', 'alt_text', 'product_permission' );
		$this->route( $ns, '/studio/alt-text/apply', 'POST', 'alt_text_apply', 'product_permission' );
	}

	/**
	 * Register one route.
	 *
	 * @param string $ns         Namespace.
	 * @param string $path       Path.
	 * @param string $method     HTTP method.
	 * @param string $callback   Method name.
	 * @param string $permission Permission method name.
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
	 * Caller can use the studio on the product in the request.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function product_permission( \WP_REST_Request $request ): bool {
		$product_id = absint( $request['product_id'] );

		return $product_id > 0
			&& 'product' === get_post_type( $product_id )
			&& current_user_can( Capabilities::STUDIO )
			&& current_user_can( 'edit_post', $product_id );
	}

	/**
	 * Caller can use the studio on the generation's product.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function generation_permission( \WP_REST_Request $request ): bool {
		$row = $this->generations->find_public( (string) $request['id'] );

		return $row
			&& 'studio' === $row['context']
			&& current_user_can( Capabilities::STUDIO )
			&& current_user_can( 'edit_post', (int) $row['product_id'] );
	}

	/**
	 * POST /studio/generate.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function generate( \WP_REST_Request $request ) {
		$product_id = absint( $request['product_id'] );

		$refs = array();
		foreach ( array_slice( (array) $request['references'], 0, 14 ) as $attachment_id ) {
			$attachment_id = absint( $attachment_id );
			if ( ! ProductImages::contains( $product_id, $attachment_id ) ) {
				return new \WP_Error( 'weargraph_bad_reference', __( 'Choose images that belong to this product.', 'weargraph' ), array( 'status' => 400 ) );
			}
			$refs[] = array(
				'type' => 'attachment',
				'id'   => $attachment_id,
			);
		}
		if ( ! $refs ) {
			return new \WP_Error( 'weargraph_no_reference', __( 'Choose at least one product image to work from.', 'weargraph' ), array( 'status' => 400 ) );
		}

		return $this->create( ImageGenerateKind::ID, self::image_spec( $product_id, (array) $request->get_params(), $refs ), $product_id, (bool) $request['regenerate'] );
	}

	/**
	 * The Studio image request for a product: prompt, references, options and meta, after
	 * extensions have adjusted it. Shared with Pro's batch campaigns so a campaign image is
	 * built exactly like one made in the Studio.
	 *
	 * @param int                 $product_id Product.
	 * @param array<string,mixed> $params     Studio choices (mode, scene, background, style, model,
	 *                                        custom, negative, aspect_ratio, size, extension keys).
	 * @param array<int,array>    $refs       References: array{type:'attachment',id:int}.
	 * @return array<string,mixed>
	 */
	public static function image_spec( int $product_id, array $params, array $refs ): array {
		$product = wc_get_product( $product_id );
		$choices = PromptBuilder::normalize( $params );
		$options = new ImageOptions( (string) ( $params['aspect_ratio'] ?? '' ), (string) ( $params['size'] ?? '' ) );
		$title   = $product ? $product->get_name() : '';

		$spec = array(
			'prompt'          => PromptBuilder::build( $choices, $title, count( $refs ) ),
			'negative_prompt' => $choices['negative'],
			'references'      => $refs,
			'options'         => $options->to_array(),
			'meta'            => array(
				'mode'           => $choices['mode'],
				'scene'          => PresetLibrary::options( 'scene' )[ $choices['scene'] ] ?? '',
				'background'     => PresetLibrary::options( 'background' )[ $choices['background'] ] ?? '',
				'style'          => PresetLibrary::options( 'style' )[ $choices['style'] ] ?? '',
				'custom'         => $choices['custom'],
				'prompt_version' => PromptBuilder::VERSION,
			),
		);

		/**
		 * Adjust a Studio image request before it is queued (e.g. Pro records the Brand Kit
		 * version in meta). References and options are validated again by the kind.
		 *
		 * @param array<string,mixed>  $spec       Request spec.
		 * @param array<string,string> $choices    Normalised studio choices.
		 * @param int                  $product_id Product.
		 */
		return (array) apply_filters( 'weargraph_studio_generate_spec', $spec, $choices, $product_id );
	}

	/**
	 * GET /studio/history.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function history( \WP_REST_Request $request ): \WP_REST_Response {
		/**
		 * Generation kinds listed in the product Studio history (Pro adds its edit kinds).
		 *
		 * @param string[] $kinds Kind ids.
		 */
		$kinds = array_values( array_filter( array_map( 'strval', (array) apply_filters( 'weargraph_studio_history_kinds', array( ImageGenerateKind::ID ) ) ) ) );

		// Optional filters (Pro's history filter bar): one kind, a status, a page.
		$kind = sanitize_key( (string) $request['kind'] );
		if ( '' !== $kind ) {
			$kinds = in_array( $kind, $kinds, true ) ? array( $kind ) : array();
		}
		$states = array(
			'ready'  => array( GenerationState::READY ),
			'failed' => array( GenerationState::FAILED, GenerationState::CANCELLED ),
		)[ sanitize_key( (string) $request['status'] ) ] ?? array();

		$product_id = absint( $request['product_id'] );
		$page       = max( 1, absint( $request['page'] ) );
		$total      = $this->generations->count_history( $product_id, 'studio', $kinds, $states );
		$rows       = $this->generations->history_for_product( $product_id, 'studio', $kinds, $states, self::HISTORY_LIMIT, ( $page - 1 ) * self::HISTORY_LIMIT );

		$items = array();
		foreach ( $rows as $row ) {
			$items[] = $this->service->present( $row, true );
		}

		$response = new \WP_REST_Response(
			array(
				'items' => $items,
				'total' => $total,
				'page'  => $page,
				'pages' => max( 1, (int) ceil( $total / self::HISTORY_LIMIT ) ),
			)
		);
		$response->header( 'Cache-Control', 'no-store' );

		return $response;
	}

	/**
	 * POST /studio/generations/{id}/rerun.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function rerun( \WP_REST_Request $request ) {
		try {
			return new \WP_REST_Response( $this->service->rerun( $this->generations->find_public( (string) $request['id'] ) ), 202 );
		} catch ( GenerationException $e ) {
			return $this->error( $e );
		}
	}

	/**
	 * DELETE /studio/generations/{id}.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function delete( \WP_REST_Request $request ): \WP_REST_Response {
		$this->service->delete( $this->generations->find_public( (string) $request['id'] ) );

		return new \WP_REST_Response( array( 'deleted' => true ) );
	}

	/**
	 * POST /studio/generations/{id}/save — target: media | featured | gallery.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function save( \WP_REST_Request $request ) {
		$row    = $this->generations->find_public( (string) $request['id'] );
		$target = (string) $request['target'];
		if ( ! in_array( $target, array( 'media', 'featured', 'gallery' ), true ) ) {
			return new \WP_Error( 'weargraph_bad_target', __( 'Unknown destination.', 'weargraph' ), array( 'status' => 400 ) );
		}
		if ( GenerationState::READY !== $row['state'] ) {
			return new \WP_Error( 'weargraph_not_ready', __( 'This result is not ready yet.', 'weargraph' ), array( 'status' => 409 ) );
		}
		if ( 'media' !== $target && ! current_user_can( 'upload_files' ) ) {
			return new \WP_Error( 'weargraph_forbidden', __( 'You are not allowed to upload files.', 'weargraph' ), array( 'status' => 403 ) );
		}

		$asset = $this->assets->find_public( (string) $request['asset_id'] );
		$owned = json_decode( (string) $row['output_asset_ids'], true );
		if ( ! $asset || ! is_array( $owned ) || ! in_array( (int) $asset['id'], array_map( 'intval', $owned ), true ) ) {
			return new \WP_Error( 'weargraph_bad_asset', __( 'That image does not belong to this result.', 'weargraph' ), array( 'status' => 400 ) );
		}

		// Product images and galleries hold images only; a video is saved to the Media Library.
		if ( 'media' !== $target && 0 !== strpos( (string) $asset['mime'], 'image/' ) ) {
			return new \WP_Error( 'weargraph_bad_target', __( 'Only images can be the product image or go in the product gallery.', 'weargraph' ), array( 'status' => 400 ) );
		}

		$product_id    = (int) $row['product_id'];
		$product       = wc_get_product( $product_id );
		$attachment_id = $this->media->save( $asset, $product_id, $product ? $product->get_name() : '' );
		if ( ! $attachment_id ) {
			return new \WP_Error( 'weargraph_save_failed', __( 'The image could not be added to the Media Library.', 'weargraph' ), array( 'status' => 500 ) );
		}

		if ( 'featured' === $target ) {
			$this->media->set_featured( $product_id, $attachment_id );
		} elseif ( 'gallery' === $target ) {
			$this->media->add_to_gallery( $product_id, $attachment_id );
		}
		if ( 'media' !== $target ) {
			$this->media->record_destination( $asset, $target, $attachment_id );
		}

		return new \WP_REST_Response(
			array(
				'attachment_id' => $attachment_id,
				'target'        => $target,
				'thumbnail'     => wp_get_attachment_image_url( $attachment_id, 'thumbnail' ),
				'edit_url'      => get_edit_post_link( $attachment_id, 'raw' ),
			)
		);
	}

	/**
	 * POST /studio/alt-text — suggest alt text for one product image.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function alt_text( \WP_REST_Request $request ) {
		$product_id    = absint( $request['product_id'] );
		$attachment_id = absint( $request['attachment_id'] );
		if ( ! ProductImages::contains( $product_id, $attachment_id ) ) {
			return new \WP_Error( 'weargraph_bad_reference', __( 'Choose images that belong to this product.', 'weargraph' ), array( 'status' => 400 ) );
		}

		$product = wc_get_product( $product_id );
		$spec    = array(
			'image'   => array(
				'type' => 'attachment',
				'id'   => $attachment_id,
			),
			'product' => $product ? $product->get_name() : '',
		);

		return $this->create( AltTextKind::ID, $spec, $product_id, false );
	}

	/**
	 * POST /studio/alt-text/apply — the merchant accepts (possibly edited) alt text.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function alt_text_apply( \WP_REST_Request $request ) {
		$product_id    = absint( $request['product_id'] );
		$attachment_id = absint( $request['attachment_id'] );
		if ( ! ProductImages::contains( $product_id, $attachment_id ) || ! current_user_can( 'edit_post', $attachment_id ) ) {
			return new \WP_Error( 'weargraph_bad_reference', __( 'Choose images that belong to this product.', 'weargraph' ), array( 'status' => 400 ) );
		}

		$alt = mb_substr( sanitize_text_field( (string) $request['alt'] ), 0, 250 );
		update_post_meta( $attachment_id, '_wp_attachment_image_alt', $alt );

		return new \WP_REST_Response( array( 'alt' => $alt ) );
	}

	/**
	 * Create a studio generation and map refusals to REST errors.
	 *
	 * @param string              $kind       Kind id.
	 * @param array<string,mixed> $spec       Spec.
	 * @param int                 $product_id Product.
	 * @param bool                $bypass     Skip the result cache (regenerate).
	 * @return \WP_REST_Response|\WP_Error
	 */
	private function create( string $kind, array $spec, int $product_id, bool $bypass ) {
		try {
			$result = $this->service->create(
				$kind,
				$spec,
				array(
					'context'      => 'studio',
					'product_id'   => $product_id,
					'user_id'      => get_current_user_id(),
					'bypass_cache' => $bypass,
				)
			);
		} catch ( GenerationException $e ) {
			return $this->error( $e );
		}

		return new \WP_REST_Response( $result, $result['cached'] ? 200 : 202 );
	}

	/**
	 * GenerationException → WP_Error.
	 *
	 * @param GenerationException $e Exception.
	 */
	private function error( GenerationException $e ): \WP_Error {
		return new \WP_Error( 'weargraph_' . $e->error_code(), $e->getMessage(), array( 'status' => $e->http_status() ) );
	}
}

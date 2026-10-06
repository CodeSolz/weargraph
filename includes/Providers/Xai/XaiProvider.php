<?php
/**
 * xAI Grok Imagine provider (BYOK).
 *
 * @package WearGraph
 */

namespace WearGraph\Providers\Xai;

defined( 'ABSPATH' ) || exit;

use WearGraph\Domain\ImageEditInput;
use WearGraph\Domain\ImageGenerationInput;
use WearGraph\Domain\ImageOptions;
use WearGraph\Domain\ImageRef;
use WearGraph\Domain\TryOnInput;
use WearGraph\Providers\CostEstimating;
use WearGraph\Providers\Http\ApiClient;
use WearGraph\Providers\Http\ErrorMap;
use WearGraph\Providers\ImageEditCapable;
use WearGraph\Providers\ImageGenerationCapable;
use WearGraph\Providers\ModelListing;
use WearGraph\Providers\ProviderCapability;
use WearGraph\Providers\ProviderException;
use WearGraph\Providers\ProviderHealth;
use WearGraph\Providers\ProviderInfo;
use WearGraph\Providers\ProviderOutput;
use WearGraph\Providers\Prompts;
use WearGraph\Providers\RestImageProvider;
use WearGraph\Providers\TryOnCapable;

/**
 * Grok Imagine through xAI's Images API (JSON; up to 5 source images as data URLs for
 * edits; base64 answers). Prices per image from https://docs.x.ai/developers/models.md
 * (checked 2026-09-26). xAI answers a bad key with HTTP 400, which ErrorMap reads as auth.
 */
class XaiProvider extends RestImageProvider implements ImageGenerationCapable, ImageEditCapable, TryOnCapable, CostEstimating, ModelListing {

	public const ID       = 'xai';
	public const BASE_URL = 'https://api.x.ai/v1/';

	private const MAX_IMAGES = 5;

	/**
	 * Models: label and USD per image.
	 */
	public const MODELS = array(
		'grok-imagine-image-2.0'     => array(
			'label' => 'Grok Imagine Image 2.0 (recommended)',
			'usd'   => 0.04,
		),
		'grok-imagine-image-quality' => array(
			'label' => 'Grok Imagine Image Quality',
			'usd'   => 0.05,
		),
		'grok-imagine-image'         => array(
			'label' => 'Grok Imagine Image (lowest cost)',
			'usd'   => 0.02,
		),
	);

	public const DEFAULT_IMAGE = 'grok-imagine-image-2.0';

	/**
	 * Aspect ratios xAI accepts (others become "auto" = follow the first image).
	 */
	private const RATIOS = array( '1:1', '3:4', '4:3', '9:16', '16:9', '2:3', '3:2', '21:9' );

	/**
	 * Provider id.
	 */
	public function id(): string {
		return self::ID;
	}

	/**
	 * Display name.
	 */
	public function label(): string {
		return 'xAI Grok';
	}

	/**
	 * Capabilities (no vision here).
	 *
	 * @return string[]
	 */
	public function capabilities(): array {
		return array( self::CAP_IMAGE_GENERATE, self::CAP_IMAGE_EDIT, self::CAP_TRYON );
	}

	/**
	 * Catalog entry.
	 */
	public function info(): ProviderInfo {
		$choices = array();
		foreach ( self::MODELS as $id => $model ) {
			$choices[ $id ] = $model['label'];
		}

		return ProviderInfo::make(
			array(
				'id'          => self::ID,
				'label'       => $this->label(),
				'initials'    => 'XA',
				'tagline'     => __( 'Grok Imagine: quick, low-cost image generation and editing with up to 5 references.', 'weargraph' ),
				'badges'      => array( __( 'Images', 'weargraph' ), __( 'Try-on', 'weargraph' ), __( 'From $0.02', 'weargraph' ) ),
				'key_url'     => 'https://console.x.ai/',
				'key_help'    => __( 'Create an API key in the xAI console and add credit.', 'weargraph' ),
				'key_pattern' => '/^xai-[A-Za-z0-9_\-]{20,}$/',
				'tasks'       => array( ProviderInfo::TASK_STUDIO, ProviderInfo::TASK_TRYON ),
				'fields'      => array(
					'image_model' => array(
						'label'   => __( 'Image model', 'weargraph' ),
						'type'    => 'select',
						'choices' => $choices,
						'default' => self::DEFAULT_IMAGE,
						'live'    => true,
					),
				),
				'data_note'   => __( 'Instructions and the images involved (product photos and, for try-on, the shopper\'s photo) are sent from your server directly to the xAI API using your key. Nothing is sent to WearGraph or CodeSolz.', 'weargraph' ),
				'terms_url'   => 'https://x.ai/legal/terms-of-service-enterprise',
				'privacy_url' => 'https://x.ai/legal/privacy-policy',
			)
		);
	}

	/**
	 * Real client.
	 *
	 * @param string $key Key.
	 */
	protected function make_client( string $key ): ApiClient {
		return new ApiClient( self::BASE_URL, array( 'Authorization' => 'Bearer ' . $key ), $this->label() );
	}

	/**
	 * Selected model.
	 */
	public function image_model(): string {
		return (string) $this->setting( 'image_model' );
	}

	/**
	 * Key check: listing image models is free.
	 */
	public function health_check(): ProviderHealth {
		try {
			$result = $this->client()->get( 'image-generation-models' );
		} catch ( ProviderException $e ) {
			return new ProviderHealth( false, 0, $this->image_model(), $e->getMessage(), $e->error_code() );
		}

		return new ProviderHealth( true, $result['latency_ms'], $this->image_model(), __( 'Connected. Your xAI key works.', 'weargraph' ) );
	}

	/**
	 * Live image models.
	 *
	 * @param string $field Field.
	 * @return array<string,string>
	 */
	public function list_models( string $field ): array {
		$result = $this->client()->get( 'image-generation-models' );
		$out    = array();
		foreach ( (array) ( $result['body']['models'] ?? ( $result['body']['data'] ?? array() ) ) as $model ) {
			$id = (string) ( $model['id'] ?? '' );
			if ( '' !== $id ) {
				$out[ $id ] = self::MODELS[ $id ]['label'] ?? $id;
			}
		}

		return $out;
	}

	/**
	 * Studio image.
	 *
	 * @param ImageGenerationInput $input Request.
	 * @throws ProviderException On failure.
	 */
	public function generate_image( ImageGenerationInput $input ): ProviderOutput {
		return $this->run( Prompts::generation( $input ), $input->references, $input->options );
	}

	/**
	 * Edit.
	 *
	 * @param ImageEditInput $input Request.
	 * @throws ProviderException On failure.
	 */
	public function edit_image( ImageEditInput $input ): ProviderOutput {
		return $this->run( Prompts::edit( $input ), self::images_of( $input ), $input->options );
	}

	/**
	 * Region support.
	 *
	 * @param TryOnInput $input Request.
	 */
	public function can_handle( TryOnInput $input ): ProviderCapability {
		return $this->region_capability( $input, 0.65 );
	}

	/**
	 * Try-on.
	 *
	 * @param TryOnInput $input Request.
	 * @throws ProviderException On failure.
	 */
	public function try_on( TryOnInput $input ): ProviderOutput {
		$images = array_slice( self::images_of( $input ), 0, self::MAX_IMAGES );

		return $this->run( Prompts::tryon( $input, count( $images ) - 1 ), $images, $input->options );
	}

	/**
	 * Estimated USD.
	 *
	 * @param object $input Any *Input DTO.
	 */
	public function estimate_cost( object $input ): float {
		return (float) ( self::MODELS[ $this->image_model() ]['usd'] ?? self::MODELS[ self::DEFAULT_IMAGE ]['usd'] );
	}

	/**
	 * Estimated latency.
	 *
	 * @param object $input Any *Input DTO.
	 */
	public function estimate_latency_ms( object $input ): int {
		return 15000;
	}

	/**
	 * Request body (public for tests).
	 *
	 * @param string       $prompt  Prompt.
	 * @param string[]     $refs    Data URLs.
	 * @param ImageOptions $options Output shape.
	 * @return array<string,mixed>
	 */
	public function body( string $prompt, array $refs, ImageOptions $options ): array {
		$body = array(
			'model'           => $this->image_model(),
			'prompt'          => $prompt,
			'n'               => 1,
			'response_format' => 'b64_json',
			'aspect_ratio'    => in_array( $options->aspect_ratio, self::RATIOS, true ) ? $options->aspect_ratio : 'auto',
			'resolution'      => in_array( $options->size, array( '2K', '4K' ), true ) ? '2k' : '1k',
		);
		if ( 1 === count( $refs ) ) {
			$body['image'] = array( 'url' => $refs[0] );
		} elseif ( $refs ) {
			$body['images'] = array_map(
				static function ( string $url ): array {
					return array( 'url' => $url );
				},
				$refs
			);
		}

		return $body;
	}

	/**
	 * Call generations (no references) or edits.
	 *
	 * @param string       $prompt  Prompt.
	 * @param ImageRef[]   $images  Images in prompt order.
	 * @param ImageOptions $options Output shape.
	 * @throws ProviderException On failure.
	 */
	private function run( string $prompt, array $images, ImageOptions $options ): ProviderOutput {
		$refs = array();
		foreach ( array_slice( $images, 0, self::MAX_IMAGES ) as $image ) {
			$refs[] = $this->data_url( $image );
		}
		$result = $this->client()->post_json( $refs ? 'images/edits' : 'images/generations', $this->body( $prompt, $refs, $options ), 180 );

		$model  = $this->image_model();
		$output = $this->output( $model, $result['latency_ms'] );
		foreach ( (array) ( $result['body']['data'] ?? array() ) as $item ) {
			if ( ! empty( $item['b64_json'] ) ) {
				self::add_b64_image( $output, (string) $item['b64_json'] );
			}
		}
		if ( ! $output->images ) {
			throw ErrorMap::no_image();
		}
		$output->cost_usd = $this->estimate_cost( $options );
		$output->metadata = array(
			'aspect_ratio' => $options->aspect_ratio,
			'size'         => $options->size,
		);

		return $output;
	}
}

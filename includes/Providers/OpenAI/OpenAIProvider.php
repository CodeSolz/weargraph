<?php
/**
 * OpenAI provider (GPT Image + vision, BYOK).
 *
 * @package WearGraph
 */

namespace WearGraph\Providers\OpenAI;

defined( 'ABSPATH' ) || exit;

use WearGraph\Domain\ImageEditInput;
use WearGraph\Domain\ImageGenerationInput;
use WearGraph\Domain\ImageOptions;
use WearGraph\Domain\ImageRef;
use WearGraph\Domain\TryOnInput;
use WearGraph\Domain\VisionInput;
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
use WearGraph\Providers\VisionCapable;

/**
 * Images through the Image API: generations without references, edits (multipart,
 * image[] ≤ 16) for references, edits, masks and try-on. Vision through Chat
 * Completions. GPT image models always answer in base64.
 */
class OpenAIProvider extends RestImageProvider implements ImageGenerationCapable, ImageEditCapable, TryOnCapable, VisionCapable, CostEstimating, ModelListing {

	public const ID       = 'openai';
	public const BASE_URL = 'https://api.openai.com/v1/';

	private const MAX_IMAGES = 16;

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
		return 'OpenAI';
	}

	/**
	 * Capabilities.
	 *
	 * @return string[]
	 */
	public function capabilities(): array {
		return array( self::CAP_IMAGE_GENERATE, self::CAP_IMAGE_EDIT, self::CAP_TRYON, self::CAP_VISION );
	}

	/**
	 * Catalog entry.
	 */
	public function info(): ProviderInfo {
		return ProviderInfo::make(
			array(
				'id'          => self::ID,
				'label'       => $this->label(),
				'initials'    => 'OA',
				'tagline'     => __( 'GPT Image: precise edits and strong text rendering, up to 16 reference images.', 'weargraph' ),
				'badges'      => array( __( 'Images', 'weargraph' ), __( 'Try-on', 'weargraph' ), __( 'Alt text', 'weargraph' ) ),
				'key_url'     => 'https://platform.openai.com/api-keys',
				'key_help'    => __( 'Create a secret key on the OpenAI platform. GPT Image models may ask you to verify your organisation first.', 'weargraph' ),
				'key_pattern' => '/^sk-[A-Za-z0-9_\-]{20,}$/',
				'tasks'       => array( ProviderInfo::TASK_STUDIO, ProviderInfo::TASK_TRYON, ProviderInfo::TASK_VISION ),
				'fields'      => array(
					'image_model'  => array(
						'label'   => __( 'Image model', 'weargraph' ),
						'type'    => 'select',
						'choices' => OpenAIModels::options( 'image' ),
						'default' => OpenAIModels::DEFAULT_IMAGE,
						'live'    => true,
					),
					'quality'      => array(
						'label'       => __( 'Quality', 'weargraph' ),
						'type'        => 'select',
						'choices'     => OpenAIModels::quality_options(),
						'default'     => 'medium',
						'description' => __( 'Higher quality costs more and takes longer. Medium suits product photos.', 'weargraph' ),
					),
					'vision_model' => array(
						'label'       => __( 'Model for alt text and checks', 'weargraph' ),
						'type'        => 'select',
						'choices'     => OpenAIModels::options( 'vision' ),
						'default'     => OpenAIModels::DEFAULT_VISION,
						'description' => __( 'Used when OpenAI writes alt text or checks images.', 'weargraph' ),
					),
				),
				'data_note'   => __( 'Instructions and the images involved (product photos and, for try-on, the shopper\'s photo) are sent from your server directly to the OpenAI API using your key. Nothing is sent to WearGraph or CodeSolz.', 'weargraph' ),
				'terms_url'   => 'https://openai.com/policies/services-agreement/',
				'privacy_url' => 'https://openai.com/policies/privacy-policy/',
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
	 * Selected image model.
	 */
	public function image_model(): string {
		return (string) $this->setting( 'image_model' );
	}

	/**
	 * Selected vision model.
	 */
	public function vision_model(): string {
		$model = (string) $this->setting( 'vision_model' );

		return isset( OpenAIModels::VISION[ $model ] ) ? $model : OpenAIModels::DEFAULT_VISION;
	}

	/**
	 * Key check: reading the image model's metadata is free.
	 */
	public function health_check(): ProviderHealth {
		$model = $this->image_model();
		try {
			$result = $this->client()->get( 'models/' . rawurlencode( $model ) );
		} catch ( ProviderException $e ) {
			return new ProviderHealth( false, 0, $model, $e->getMessage(), $e->error_code() );
		}

		return new ProviderHealth( true, $result['latency_ms'], $model, __( 'Connected. Your OpenAI key works and can see the image model.', 'weargraph' ) );
	}

	/**
	 * Live model lists.
	 *
	 * @param string $field Field name.
	 * @return array<string,string>
	 */
	public function list_models( string $field ): array {
		$result = $this->client()->get( 'models' );
		$out    = array();
		foreach ( (array) ( $result['body']['data'] ?? array() ) as $model ) {
			$id = (string) ( $model['id'] ?? '' );
			if ( 'image_model' === $field && 1 === preg_match( '/^(gpt-image|chatgpt-image)/', $id ) ) {
				$out[ $id ] = OpenAIModels::IMAGE[ $id ]['label'] ?? $id;
			}
		}
		ksort( $out );

		return $out;
	}

	/**
	 * Studio image.
	 *
	 * @param ImageGenerationInput $input Request.
	 * @throws ProviderException On failure.
	 */
	public function generate_image( ImageGenerationInput $input ): ProviderOutput {
		$prompt = Prompts::generation( $input );
		if ( ! $input->references ) {
			$model  = $this->image_model();
			$result = $this->client()->post_json( 'images/generations', $this->params( $model, $input->options, $prompt, false ) );

			return $this->image_output( $model, $result, $input->options, 0 );
		}

		return $this->edit_call( $prompt, $input->references, $input->options );
	}

	/**
	 * Edit (the mask travels as the second image, described in the prompt).
	 *
	 * @param ImageEditInput $input Request.
	 * @throws ProviderException On failure.
	 */
	public function edit_image( ImageEditInput $input ): ProviderOutput {
		return $this->edit_call( Prompts::edit( $input ), self::images_of( $input ), $input->options );
	}

	/**
	 * How well a garment region works.
	 *
	 * @param TryOnInput $input Request.
	 */
	public function can_handle( TryOnInput $input ): ProviderCapability {
		return $this->region_capability( $input, 0.75 );
	}

	/**
	 * Try-on: person first, then the garments.
	 *
	 * @param TryOnInput $input Request.
	 * @throws ProviderException On failure.
	 */
	public function try_on( TryOnInput $input ): ProviderOutput {
		$images = array_slice( self::images_of( $input ), 0, self::MAX_IMAGES );

		return $this->edit_call( Prompts::tryon( $input, count( $images ) - 1 ), $images, $input->options );
	}

	/**
	 * Alt text, classification, quality scoring.
	 *
	 * @param VisionInput $input Request.
	 * @throws ProviderException On failure.
	 */
	public function evaluate( VisionInput $input ): ProviderOutput {
		$model   = $this->vision_model();
		$content = array(
			array(
				'type' => 'text',
				'text' => $input->prompt . ( $input->json ? "\n\nAnswer with JSON only." : '' ),
			),
		);
		foreach ( $input->images as $image ) {
			$content[] = array(
				'type'      => 'image_url',
				'image_url' => array( 'url' => $this->data_url( $image ) ),
			);
		}
		$body = array(
			'model'                 => $model,
			'messages'              => array(
				array(
					'role'    => 'user',
					'content' => $content,
				),
			),
			'max_completion_tokens' => 2000,
		);
		if ( $input->json ) {
			$body['response_format'] = array( 'type' => 'json_object' );
		}

		$result = $this->client()->post_json( 'chat/completions', $body, 90 );

		return VisionParser::output( $this->output( $model, $result['latency_ms'], (string) ( $result['body']['id'] ?? '' ) ), $result['body'], $input->json, OpenAIModels::vision_cost( $model, (array) ( $result['body']['usage'] ?? array() ) ) );
	}

	/**
	 * Estimated USD of one attempt.
	 *
	 * @param object $input Any *Input DTO.
	 */
	public function estimate_cost( object $input ): float {
		if ( $input instanceof VisionInput ) {
			$m = OpenAIModels::VISION[ $this->vision_model() ];

			return round( ( ( count( $input->images ) * 800 + 500 ) * $m['in'] + 400 * $m['out'] ) / 1000000, 5 );
		}
		$model   = $this->image_model();
		$options = isset( $input->options ) && $input->options instanceof ImageOptions ? $input->options : new ImageOptions();

		return OpenAIModels::estimate( $model, OpenAIModels::size( $model, $options ), (string) $this->setting( 'quality' ), count( self::images_of( $input ) ) );
	}

	/**
	 * Estimated latency.
	 *
	 * @param object $input Any *Input DTO.
	 */
	public function estimate_latency_ms( object $input ): int {
		return $input instanceof VisionInput ? 6000 : 45000;
	}

	/**
	 * Common image parameters.
	 *
	 * @param string       $model   Model.
	 * @param ImageOptions $options Output shape.
	 * @param string       $prompt  Prompt.
	 * @param bool         $edit    Edit call (input_fidelity applies).
	 * @return array<string,string>
	 */
	public function params( string $model, ImageOptions $options, string $prompt, bool $edit ): array {
		$params = array(
			'model'         => $model,
			'prompt'        => $prompt,
			'size'          => OpenAIModels::size( $model, $options ),
			'quality'       => OpenAIModels::quality( $model, (string) $this->setting( 'quality' ) ),
			'n'             => '1',
			'output_format' => 'png',
		);
		if ( $edit && ! empty( OpenAIModels::image( $model )['fidelity'] ) ) {
			// Keep the product and the shopper as they are.
			$params['input_fidelity'] = 'high';
		}

		return $params;
	}

	/**
	 * Call images/edits with local images.
	 *
	 * @param string       $prompt  Prompt.
	 * @param ImageRef[]   $images  Images in prompt order.
	 * @param ImageOptions $options Output shape.
	 * @throws ProviderException On failure.
	 */
	private function edit_call( string $prompt, array $images, ImageOptions $options ): ProviderOutput {
		$model = $this->image_model();
		$parts = array();
		foreach ( $this->params( $model, $options, $prompt, true ) as $name => $value ) {
			$parts[] = array(
				'name'  => $name,
				'value' => $value,
			);
		}
		foreach ( array_slice( $images, 0, self::MAX_IMAGES ) as $i => $image ) {
			$parts[] = array(
				'name'     => 'image[]',
				'filename' => 'image-' . ( $i + 1 ) . '.' . self::extension( $image->mime ),
				'mime'     => $image->mime,
				'bytes'    => $this->bytes( $image ),
			);
		}

		return $this->image_output( $model, $this->client()->post_multipart( 'images/edits', $parts ), $options, min( count( $images ), self::MAX_IMAGES ) );
	}

	/**
	 * Parse an Image API response.
	 *
	 * @param string                                          $model   Model.
	 * @param array{body:array<string,mixed>,latency_ms:int} $result  Client result.
	 * @param ImageOptions                                    $options Output shape.
	 * @param int                                             $inputs  Input images sent (estimate when usage is missing).
	 * @throws ProviderException When there is no image.
	 */
	private function image_output( string $model, array $result, ImageOptions $options, int $inputs ): ProviderOutput {
		$body   = $result['body'];
		$output = $this->output( $model, $result['latency_ms'] );
		foreach ( (array) ( $body['data'] ?? array() ) as $item ) {
			if ( ! empty( $item['b64_json'] ) ) {
				self::add_b64_image( $output, (string) $item['b64_json'], 'image/png' );
			}
		}
		if ( ! $output->images ) {
			throw ErrorMap::no_image();
		}

		$usage              = (array) ( $body['usage'] ?? array() );
		$output->tokens_in  = isset( $usage['input_tokens'] ) ? (int) $usage['input_tokens'] : null;
		$output->tokens_out = isset( $usage['output_tokens'] ) ? (int) $usage['output_tokens'] : null;
		$output->cost_usd   = OpenAIModels::cost_from_usage( $model, $usage ) ?? OpenAIModels::estimate( $model, OpenAIModels::size( $model, $options ), (string) $this->setting( 'quality' ), $inputs );
		$output->metadata   = array(
			'size'         => OpenAIModels::size( $model, $options ),
			'aspect_ratio' => $options->aspect_ratio,
			'quality'      => (string) ( $body['quality'] ?? OpenAIModels::quality( $model, (string) $this->setting( 'quality' ) ) ),
		);

		return $output;
	}

	/**
	 * File extension for a MIME type.
	 *
	 * @param string $mime MIME.
	 */
	private static function extension( string $mime ): string {
		return 'image/png' === $mime ? 'png' : ( 'image/webp' === $mime ? 'webp' : 'jpg' );
	}
}

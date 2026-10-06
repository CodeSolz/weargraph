<?php
/**
 * OpenRouter provider: many image models with one key (BYOK).
 *
 * @package WearGraph
 */

namespace WearGraph\Providers\OpenRouter;

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
use WearGraph\Providers\OpenAI\VisionParser;
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
 * Images through OpenRouter's Image API (POST /images, normalised resolution and aspect
 * ratio, reference images as data URLs, base64 answers with the real cost). Only the
 * parameters the chosen model accepts are sent. Vision through Chat Completions.
 */
class OpenRouterProvider extends RestImageProvider implements ImageGenerationCapable, ImageEditCapable, TryOnCapable, VisionCapable, CostEstimating, ModelListing {

	public const ID       = 'openrouter';
	public const BASE_URL = 'https://openrouter.ai/api/v1/';

	private const PARAMS_TRANSIENT = 'weargraph_openrouter_params';

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
		return 'OpenRouter';
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
				'initials'    => 'OR',
				'tagline'     => __( 'One key for 50+ image models: Nano Banana, GPT Image, FLUX.2, Seedream, Qwen, Grok and more.', 'weargraph' ),
				'badges'      => array( __( '50+ models', 'weargraph' ), __( 'Try-on', 'weargraph' ), __( 'Alt text', 'weargraph' ) ),
				'key_url'     => 'https://openrouter.ai/settings/keys',
				'key_help'    => __( 'Create a key on OpenRouter and add credit. Failed generations are not charged.', 'weargraph' ),
				'key_pattern' => '/^sk-or-[A-Za-z0-9_\-]{20,}$/',
				'tasks'       => array( ProviderInfo::TASK_STUDIO, ProviderInfo::TASK_TRYON, ProviderInfo::TASK_VISION ),
				'fields'      => array(
					'image_model'  => array(
						'label'       => __( 'Image model', 'weargraph' ),
						'type'        => 'select',
						'choices'     => OpenRouterModels::image_options(),
						'default'     => OpenRouterModels::DEFAULT_IMAGE,
						'live'        => true,
						'description' => __( 'Refresh to see every image model that accepts reference images.', 'weargraph' ),
					),
					'vision_model' => array(
						'label'   => __( 'Model for alt text and checks', 'weargraph' ),
						'type'    => 'select',
						'choices' => OpenRouterModels::VISION,
						'default' => OpenRouterModels::DEFAULT_VISION,
					),
				),
				'data_note'   => __( 'Instructions and the images involved (product photos and, for try-on, the shopper\'s photo) are sent from your server to OpenRouter using your key, and OpenRouter passes them to the company running the chosen model (for example Google, OpenAI or Black Forest Labs). Nothing is sent to WearGraph or CodeSolz.', 'weargraph' ),
				'terms_url'   => 'https://openrouter.ai/terms',
				'privacy_url' => 'https://openrouter.ai/privacy',
			)
		);
	}

	/**
	 * Real client.
	 *
	 * @param string $key Key.
	 */
	protected function make_client( string $key ): ApiClient {
		return new ApiClient(
			self::BASE_URL,
			array(
				'Authorization' => 'Bearer ' . $key,
				'X-Title'       => 'WearGraph',
			),
			$this->label()
		);
	}

	/**
	 * Selected image model.
	 */
	public function image_model(): string {
		return (string) $this->setting( 'image_model' );
	}

	/**
	 * Key check (free: reads the key's own details).
	 */
	public function health_check(): ProviderHealth {
		try {
			$result = $this->client()->get( 'key' );
		} catch ( ProviderException $e ) {
			return new ProviderHealth( false, 0, $this->image_model(), $e->getMessage(), $e->error_code() );
		}
		$data = (array) ( $result['body']['data'] ?? array() );
		if ( isset( $data['limit_remaining'] ) && is_numeric( $data['limit_remaining'] ) && (float) $data['limit_remaining'] <= 0 ) {
			return new ProviderHealth( false, $result['latency_ms'], $this->image_model(), __( 'Your OpenRouter key works but has no credit left. Add credit on OpenRouter.', 'weargraph' ), ProviderException::QUOTA );
		}

		return new ProviderHealth( true, $result['latency_ms'], $this->image_model(), __( 'Connected. Your OpenRouter key works.', 'weargraph' ) );
	}

	/**
	 * Image models that accept reference images (public list; parameters remembered).
	 *
	 * @param string $field Field name.
	 * @return array<string,string>
	 */
	public function list_models( string $field ): array {
		if ( 'image_model' !== $field ) {
			return array();
		}
		$result = $this->client()->get( 'images/models' );
		$out    = array();
		$params = array();
		foreach ( (array) ( $result['body']['data'] ?? array() ) as $model ) {
			$id       = (string) ( $model['id'] ?? '' );
			$accepted = array_keys( (array) ( $model['supported_parameters'] ?? array() ) );
			if ( '' === $id || ! in_array( 'input_references', $accepted, true ) || ! in_array( 'image', (array) ( $model['architecture']['input_modalities'] ?? array() ), true ) ) {
				continue;
			}
			$out[ $id ]    = (string) ( $model['name'] ?? $id );
			$params[ $id ] = $accepted;
		}
		asort( $out );
		set_transient( self::PARAMS_TRANSIENT, $params, 12 * HOUR_IN_SECONDS );

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
		return $this->region_capability( $input, 0.75 );
	}

	/**
	 * Try-on.
	 *
	 * @param TryOnInput $input Request.
	 * @throws ProviderException On failure.
	 */
	public function try_on( TryOnInput $input ): ProviderOutput {
		$images = self::images_of( $input );

		return $this->run( Prompts::tryon( $input, count( $images ) - 1 ), $images, $input->options );
	}

	/**
	 * Vision through chat completions.
	 *
	 * @param VisionInput $input Request.
	 * @throws ProviderException On failure.
	 */
	public function evaluate( VisionInput $input ): ProviderOutput {
		$model = (string) $this->setting( 'vision_model' );
		$model = isset( OpenRouterModels::VISION[ $model ] ) ? $model : OpenRouterModels::DEFAULT_VISION;

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
			'model'      => $model,
			'messages'   => array(
				array(
					'role'    => 'user',
					'content' => $content,
				),
			),
			'max_tokens' => 2000,
		);
		if ( $input->json ) {
			$body['response_format'] = array( 'type' => 'json_object' );
		}

		$result = $this->client()->post_json( 'chat/completions', $body, 90 );

		return VisionParser::output( $this->output( $model, $result['latency_ms'], (string) ( $result['body']['id'] ?? '' ) ), $result['body'], $input->json, null );
	}

	/**
	 * Estimated USD.
	 *
	 * @param object $input Any *Input DTO.
	 */
	public function estimate_cost( object $input ): float {
		if ( $input instanceof VisionInput ) {
			return 0.002;
		}
		$options = isset( $input->options ) && $input->options instanceof ImageOptions ? $input->options : new ImageOptions();

		return OpenRouterModels::estimate( $this->image_model(), $options->size );
	}

	/**
	 * Estimated latency.
	 *
	 * @param object $input Any *Input DTO.
	 */
	public function estimate_latency_ms( object $input ): int {
		return $input instanceof VisionInput ? 6000 : 40000;
	}

	/**
	 * Request body for an image call (public for tests).
	 *
	 * @param string       $model   Model.
	 * @param string       $prompt  Prompt.
	 * @param string[]     $refs    Reference data URLs.
	 * @param ImageOptions $options Output shape.
	 * @return array<string,mixed>
	 */
	public function image_body( string $model, string $prompt, array $refs, ImageOptions $options ): array {
		$live   = get_transient( self::PARAMS_TRANSIENT );
		$params = OpenRouterModels::params( $model, is_array( $live ) ? $live : array() );
		$body   = array(
			'model'  => $model,
			'prompt' => $prompt,
		);
		if ( in_array( 'n', $params, true ) ) {
			$body['n'] = 1;
		}
		if ( in_array( 'aspect_ratio', $params, true ) ) {
			$body['aspect_ratio'] = $options->aspect_ratio;
		}
		if ( in_array( 'resolution', $params, true ) ) {
			$body['resolution'] = $options->size;
		}
		if ( in_array( 'quality', $params, true ) ) {
			$body['quality'] = '4K' === $options->size || '2K' === $options->size ? 'high' : 'medium';
		}
		if ( $refs ) {
			$body['input_references'] = array_map(
				static function ( string $url ): array {
					return array(
						'type'      => 'image_url',
						'image_url' => array( 'url' => $url ),
					);
				},
				$refs
			);
		}

		return $body;
	}

	/**
	 * Call the Image API.
	 *
	 * @param string       $prompt  Prompt.
	 * @param ImageRef[]   $images  Images in prompt order.
	 * @param ImageOptions $options Output shape.
	 * @throws ProviderException On failure.
	 */
	private function run( string $prompt, array $images, ImageOptions $options ): ProviderOutput {
		$model = $this->image_model();
		$refs  = array();
		foreach ( $images as $image ) {
			$refs[] = $this->data_url( $image );
		}
		$result = $this->client()->post_json( 'images', $this->image_body( $model, $prompt, $refs, $options ), 240 );

		$body   = $result['body'];
		$output = $this->output( $model, $result['latency_ms'] );
		foreach ( (array) ( $body['data'] ?? array() ) as $item ) {
			if ( ! empty( $item['b64_json'] ) ) {
				self::add_b64_image( $output, (string) $item['b64_json'], (string) ( $item['media_type'] ?? '' ) );
			}
		}
		if ( ! $output->images ) {
			throw ErrorMap::no_image();
		}

		$usage              = (array) ( $body['usage'] ?? array() );
		$output->tokens_in  = isset( $usage['prompt_tokens'] ) ? (int) $usage['prompt_tokens'] : null;
		$output->tokens_out = isset( $usage['completion_tokens'] ) ? (int) $usage['completion_tokens'] : null;
		$output->cost_usd   = isset( $usage['cost'] ) && is_numeric( $usage['cost'] ) ? (float) $usage['cost'] : OpenRouterModels::estimate( $model, $options->size );
		$output->metadata   = array(
			'size'         => $options->size,
			'aspect_ratio' => $options->aspect_ratio,
		);

		return $output;
	}
}

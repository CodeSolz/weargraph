<?php
/**
 * Google Gemini provider (BYOK).
 *
 * @package WearGraph
 */

namespace WearGraph\Providers\Gemini;

defined( 'ABSPATH' ) || exit;

use WearGraph\Domain\ImageEditInput;
use WearGraph\Domain\ImageGenerationInput;
use WearGraph\Domain\ImageOptions;
use WearGraph\Domain\ImageRef;
use WearGraph\Domain\TryOnInput;
use WearGraph\Domain\VisionInput;
use WearGraph\Providers\CostEstimating;
use WearGraph\Providers\DescribedProvider;
use WearGraph\Providers\ImageEditCapable;
use WearGraph\Providers\ImageGenerationCapable;
use WearGraph\Providers\ProviderCapability;
use WearGraph\Providers\ProviderException;
use WearGraph\Providers\ProviderHealth;
use WearGraph\Providers\ProviderInfo;
use WearGraph\Providers\ProviderInterface;
use WearGraph\Providers\ProviderKeys;
use WearGraph\Providers\ProviderOutput;
use WearGraph\Providers\Prompts;
use WearGraph\Providers\TryOnCapable;
use WearGraph\Providers\VisionCapable;
use WearGraph\Support\Files;
use WearGraph\Support\Settings;

/**
 * Image generation, editing, try-on and vision through the Gemini generateContent API.
 * All prompt wording here is WearGraph's own (clean-room rule, README rule 18).
 */
class GeminiProvider implements ProviderInterface, DescribedProvider, ImageGenerationCapable, ImageEditCapable, TryOnCapable, VisionCapable, CostEstimating {

	public const ID = 'gemini';

	/**
	 * Builds a client for a key (injectable for tests).
	 *
	 * @var callable
	 */
	private $client_factory;

	/**
	 * Constructor.
	 *
	 * @param callable|null $client_factory fn( string $api_key ): GeminiClient.
	 */
	public function __construct( ?callable $client_factory = null ) {
		$this->client_factory = $client_factory ?? static function ( string $key ): GeminiClient {
			return new GeminiClient( $key );
		};
	}

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
		return 'Google Gemini';
	}

	/**
	 * Catalog entry for Settings → AI Provider.
	 */
	public function info(): ProviderInfo {
		return ProviderInfo::make(
			array(
				'id'          => self::ID,
				'label'       => $this->label(),
				'initials'    => 'GE',
				'tagline'     => __( 'Nano Banana image models: product photos, edits, try-on and alt text with one key.', 'weargraph' ),
				'badges'      => array( __( 'Images', 'weargraph' ), __( 'Try-on', 'weargraph' ), __( 'Alt text', 'weargraph' ) ),
				'key_url'     => 'https://aistudio.google.com/apikey',
				'key_help'    => __( 'Create a key in Google AI Studio. Use a key from a Google Cloud project with billing enabled.', 'weargraph' ),
				'key_pattern' => '/^[A-Za-z0-9_\-]{30,60}$/',
				'tasks'       => array( ProviderInfo::TASK_STUDIO, ProviderInfo::TASK_TRYON, ProviderInfo::TASK_VISION ),
				'fields'      => array(
					'image_model' => array(
						'label'       => __( 'Image model', 'weargraph' ),
						'type'        => 'select',
						'choices'     => GeminiModels::options( 'image' ),
						'default'     => GeminiModels::DEFAULT_IMAGE,
						'description' => __( 'Used for product images and try-on. The balanced model suits most stores.', 'weargraph' ),
					),
				),
				'data_note'   => __( 'When you generate an image, or a shopper uses try-on, your server sends the instructions and the images involved (product photos and, for try-on, the shopper\'s photo) directly to the Google Gemini API using your key. Nothing is sent to WearGraph or CodeSolz. On Google\'s free (unpaid) tier, Google may use submitted content to improve its products and human reviewers may see it: do not use a free-tier key for shopper photos.', 'weargraph' ),
				'terms_url'   => 'https://ai.google.dev/gemini-api/terms',
				'privacy_url' => 'https://policies.google.com/privacy',
			)
		);
	}

	/**
	 * Current settings (the image model keeps its original settings key).
	 *
	 * @return array<string,mixed>
	 */
	public function setting_values(): array {
		return array( 'image_model' => $this->image_model() );
	}

	/**
	 * Save settings.
	 *
	 * @param array<string,string> $values Posted values.
	 */
	public function save_settings( array $values ): void {
		$model = (string) ( $values['image_model'] ?? '' );
		if ( isset( GeminiModels::options( 'image' )[ $model ] ) ) {
			Settings::update( array( 'gemini_image_model' => $model ) );
		}
	}

	/**
	 * Supported capabilities.
	 *
	 * @return string[]
	 */
	public function capabilities(): array {
		return array( self::CAP_IMAGE_GENERATE, self::CAP_IMAGE_EDIT, self::CAP_TRYON, self::CAP_VISION );
	}

	/**
	 * Key stored.
	 */
	public function is_configured(): bool {
		return ProviderKeys::has( self::ID );
	}

	/**
	 * Merchant-selected image model.
	 */
	public function image_model(): string {
		$model = (string) Settings::get( 'gemini_image_model', GeminiModels::DEFAULT_IMAGE );

		return 'image' === ( GeminiModels::get( $model )['kind'] ?? '' ) ? $model : GeminiModels::DEFAULT_IMAGE;
	}

	/**
	 * Merchant-selected vision model.
	 */
	public function vision_model(): string {
		$model = (string) Settings::get( 'gemini_vision_model', GeminiModels::DEFAULT_VISION );

		return 'vision' === ( GeminiModels::get( $model )['kind'] ?? '' ) ? $model : GeminiModels::DEFAULT_VISION;
	}

	/**
	 * Check the key by reading the image model's metadata (free; no generation).
	 */
	public function health_check(): ProviderHealth {
		$model = $this->image_model();

		try {
			$result = $this->client()->get( 'models/' . rawurlencode( $model ) );
		} catch ( ProviderException $e ) {
			return new ProviderHealth( false, 0, $model, $e->getMessage(), $e->error_code() );
		}

		// A valid key is not enough: free-tier keys have no quota for image models. A
		// five-token text reply from the image model proves the key can actually use it
		// (a fraction of a cent on paid keys; refused at no cost on free-tier keys).
		try {
			$this->client()->post(
				$model,
				'generateContent',
				array(
					'contents'         => array(
						array(
							'role'  => 'user',
							'parts' => array( array( 'text' => 'Reply with the single word OK.' ) ),
						),
					),
					'generationConfig' => array(
						'responseModalities' => array( 'TEXT' ),
						'maxOutputTokens'    => 5,
					),
				),
				30
			);
		} catch ( ProviderException $e ) {
			// Only quota and key problems are conclusive; a model that refuses a text-only
			// probe (bad_input) is not a reason to report the key as broken.
			if ( in_array( $e->error_code(), array( ProviderException::QUOTA, ProviderException::AUTH ), true ) ) {
				return new ProviderHealth( false, $result['latency_ms'], $model, $e->getMessage(), $e->error_code() );
			}
		}

		return new ProviderHealth( true, $result['latency_ms'], $model, __( 'Connected. Your API key works and can use the image model.', 'weargraph' ) );
	}

	/**
	 * Generate a product image.
	 *
	 * @param ImageGenerationInput $input Request.
	 * @throws ProviderException On failure.
	 */
	public function generate_image( ImageGenerationInput $input ): ProviderOutput {
		$model = $this->image_model();
		$parts = array_merge(
			array( array( 'text' => Prompts::generation( $input ) ) ),
			$this->image_parts( $this->limit_refs( $input->references, $model ) )
		);

		return $this->run_image( $model, $parts, $input->options );
	}

	/**
	 * Edit an image.
	 *
	 * @param ImageEditInput $input Request.
	 * @throws ProviderException On failure.
	 */
	public function edit_image( ImageEditInput $input ): ProviderOutput {
		$images = array( $input->source );
		$text   = Prompts::edit( $input );
		if ( $input->mask ) {
			$images[] = $input->mask;
		}

		$model  = $this->image_model();
		$images = array_merge( $images, $input->references );
		$parts  = array_merge( array( array( 'text' => $text ) ), $this->image_parts( $this->limit_refs( $images, $model ) ) );

		return $this->run_image( $model, $parts, $input->options );
	}

	/**
	 * How well Gemini handles this garment region.
	 *
	 * @param TryOnInput $input Request.
	 */
	public function can_handle( TryOnInput $input ): ProviderCapability {
		switch ( $input->region ) {
			case 'upper':
			case 'lower':
			case 'one_piece':
			case 'outer':
				return new ProviderCapability( true, 0.8 );
			case 'footwear':
				return new ProviderCapability( true, 0.55, __( 'Footwear previews are less reliable.', 'weargraph' ) );
			default:
				return new ProviderCapability( true, 0.4, __( 'Accessory previews are experimental.', 'weargraph' ) );
		}
	}

	/**
	 * Render a try-on.
	 *
	 * @param TryOnInput $input Request.
	 * @throws ProviderException On failure.
	 */
	public function try_on( TryOnInput $input ): ProviderOutput {
		$model  = $this->image_model();
		$images = $this->limit_refs( array_merge( array( $input->person ), $input->garments ), $model );
		$parts  = array_merge( array( array( 'text' => $this->tryon_prompt( $input, count( $images ) - 1 ) ) ), $this->image_parts( $images ) );

		return $this->run_image( $model, $parts, $input->options );
	}

	/**
	 * Build the try-on instruction.
	 *
	 * @param TryOnInput $input         Request.
	 * @param int        $garment_count Garment images actually sent.
	 */
	public function tryon_prompt( TryOnInput $input, int $garment_count ): string {
		return Prompts::tryon( $input, $garment_count );
	}

	/**
	 * Describe or score images.
	 *
	 * @param VisionInput $input Request.
	 * @throws ProviderException On failure.
	 */
	public function evaluate( VisionInput $input ): ProviderOutput {
		$model = $this->vision_model();
		$body  = array(
			'contents'         => array(
				array(
					'role'  => 'user',
					'parts' => array_merge( array( array( 'text' => $input->prompt ) ), $this->image_parts( $input->images ) ),
				),
			),
			'generationConfig' => array( 'temperature' => 0 ),
		);
		if ( $input->json ) {
			$body['generationConfig']['responseMimeType'] = 'application/json';
		}

		$result = $this->client()->post( $model, 'generateContent', $body, 60 );
		GeminiErrors::assert_content( $result['body'], false );

		$text = '';
		foreach ( (array) ( $result['body']['candidates'][0]['content']['parts'] ?? array() ) as $part ) {
			$text .= (string) ( $part['text'] ?? '' );
		}

		$output = $this->base_output( $model, $result );
		if ( $input->json ) {
			$decoded = json_decode( $this->strip_fences( $text ), true );
			if ( ! is_array( $decoded ) ) {
				throw ProviderException::no_output( esc_html__( 'The AI provider returned an unreadable answer. Please try again.', 'weargraph' ) );
			}
			$output->data = $decoded;
		} else {
			$output->data = array( 'text' => trim( $text ) );
		}

		return $output;
	}

	/**
	 * Estimated USD cost of one attempt.
	 *
	 * @param object $input Any *Input DTO.
	 */
	public function estimate_cost( object $input ): float {
		if ( $input instanceof VisionInput ) {
			$model = GeminiModels::get( $this->vision_model() );
			$in    = count( $input->images ) * GeminiModels::INPUT_IMAGE_TOKENS + 500;

			return round( ( $in * (float) $model['input_per_m'] + 400 * (float) $model['output_per_m'] ) / 1000000, 5 );
		}

		$model_id = $this->image_model();
		$model    = GeminiModels::get( $model_id );
		$options  = isset( $input->options ) && $input->options instanceof ImageOptions ? $input->options : new ImageOptions();
		$size     = GeminiModels::clamp_size( $model_id, $options->size );
		$out      = (float) ( $model['per_image'][ $size ] ?? max( (array) $model['per_image'] ) );
		$in       = ( $this->count_images( $input ) * GeminiModels::INPUT_IMAGE_TOKENS + 500 ) * (float) $model['input_per_m'] / 1000000;

		return round( $out + $in, 5 );
	}

	/**
	 * Estimated latency.
	 *
	 * @param object $input Any *Input DTO.
	 */
	public function estimate_latency_ms( object $input ): int {
		$model = GeminiModels::get( $input instanceof VisionInput ? $this->vision_model() : $this->image_model() );

		return (int) ( $model['latency_ms'] ?? 15000 );
	}

	/**
	 * Build the request body for an image call (public for tests).
	 *
	 * @param string              $model   Model id.
	 * @param array<int,array>    $parts   Content parts.
	 * @param ImageOptions        $options Output shape.
	 * @return array<string,mixed>
	 */
	public function image_body( string $model, array $parts, ImageOptions $options ): array {
		$image_config = array( 'aspectRatio' => $options->aspect_ratio );
		$supported    = (array) ( GeminiModels::get( $model )['sizes'] ?? array() );
		if ( count( $supported ) > 1 ) {
			$size                      = GeminiModels::clamp_size( $model, $options->size );
			$image_config['imageSize'] = '512' === $size ? '512px' : $size;
		}

		return array(
			'contents'         => array(
				array(
					'role'  => 'user',
					'parts' => $parts,
				),
			),
			'generationConfig' => array(
				'responseModalities' => array( 'IMAGE' ),
				'imageConfig'        => $image_config,
			),
		);
	}

	/**
	 * Call the image model and collect images.
	 *
	 * @param string           $model   Model id.
	 * @param array<int,array> $parts   Content parts.
	 * @param ImageOptions     $options Output shape.
	 * @throws ProviderException On failure.
	 */
	private function run_image( string $model, array $parts, ImageOptions $options ): ProviderOutput {
		$result = $this->client()->post( $model, 'generateContent', $this->image_body( $model, $parts, $options ) );
		GeminiErrors::assert_content( $result['body'], true );

		$output = $this->base_output( $model, $result );
		foreach ( (array) ( $result['body']['candidates'][0]['content']['parts'] ?? array() ) as $part ) {
			$inline = $part['inlineData'] ?? ( $part['inline_data'] ?? null );
			if ( ! is_array( $inline ) || empty( $inline['data'] ) ) {
				continue;
			}
			$bytes = base64_decode( (string) $inline['data'], true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- API image payload.
			if ( false !== $bytes ) {
				$output->images[] = array(
					'mime'  => (string) ( $inline['mimeType'] ?? ( $inline['mime_type'] ?? 'image/png' ) ),
					'bytes' => $bytes,
				);
			}
		}

		if ( ! $output->images ) {
			throw ProviderException::no_output( esc_html__( 'The AI provider returned no image. Please try again.', 'weargraph' ) );
		}

		$output->metadata['size']         = GeminiModels::clamp_size( $model, $options->size );
		$output->metadata['aspect_ratio'] = $options->aspect_ratio;

		return $output;
	}

	/**
	 * Fill provider, model, usage, cost and latency.
	 *
	 * @param string                                          $model  Model id.
	 * @param array{body:array<string,mixed>,latency_ms:int} $result Client result.
	 */
	private function base_output( string $model, array $result ): ProviderOutput {
		$usage = (array) ( $result['body']['usageMetadata'] ?? array() );
		$in    = (int) ( $usage['promptTokenCount'] ?? 0 );
		$out   = (int) ( $usage['candidatesTokenCount'] ?? 0 ) + (int) ( $usage['thoughtsTokenCount'] ?? 0 );

		$output                      = new ProviderOutput();
		$output->provider            = self::ID;
		$output->model               = (string) ( $result['body']['modelVersion'] ?? $model );
		$output->provider_request_id = (string) ( $result['body']['responseId'] ?? '' );
		$output->latency_ms          = $result['latency_ms'];
		$output->tokens_in           = $in ? $in : null;
		$output->tokens_out          = $out ? $out : null;
		$output->cost_usd            = GeminiModels::cost_from_usage( $model, $in, $out );
		$output->metadata            = array( 'finish_reason' => (string) ( $result['body']['candidates'][0]['finishReason'] ?? '' ) );

		return $output;
	}

	/**
	 * Inline-data parts for local images.
	 *
	 * @param ImageRef[] $images Images.
	 * @return array<int,array>
	 * @throws ProviderException When an image cannot be read.
	 */
	private function image_parts( array $images ): array {
		$parts = array();
		foreach ( $images as $image ) {
			$bytes = Files::read( $image->path );
			if ( '' === $bytes ) {
				throw ProviderException::bad_input( esc_html__( 'An image for this request could not be read.', 'weargraph' ) );
			}
			$parts[] = array(
				'inlineData' => array(
					'mimeType' => $image->mime,
					'data'     => base64_encode( $bytes ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- API image payload.
				),
			);
		}

		return $parts;
	}

	/**
	 * Keep only as many images as the model accepts.
	 *
	 * @param ImageRef[] $images Images in priority order.
	 * @param string     $model  Model id.
	 * @return ImageRef[]
	 */
	private function limit_refs( array $images, string $model ): array {
		$max = (int) ( GeminiModels::get( $model )['max_refs'] ?? 6 );

		return array_slice( $images, 0, max( 1, $max ) );
	}

	/**
	 * Number of images a request will send.
	 *
	 * @param object $input Any *Input DTO.
	 */
	private function count_images( object $input ): int {
		if ( $input instanceof TryOnInput ) {
			return 1 + count( $input->garments );
		}
		if ( $input instanceof ImageEditInput ) {
			return 1 + ( $input->mask ? 1 : 0 ) + count( $input->references );
		}
		if ( $input instanceof ImageGenerationInput ) {
			return count( $input->references );
		}

		return 0;
	}

	/**
	 * Remove a ```json fence if the model added one.
	 *
	 * @param string $text Raw text.
	 */
	private function strip_fences( string $text ): string {
		$text = trim( $text );
		if ( preg_match( '/^```(?:json)?\s*(.*?)\s*```$/s', $text, $m ) ) {
			return $m[1];
		}

		return $text;
	}

	/**
	 * Client for the stored key.
	 *
	 * @throws ProviderException When no key is stored.
	 */
	private function client(): GeminiClient {
		$key = ProviderKeys::get( self::ID );
		if ( '' === $key ) {
			throw ProviderException::not_configured( esc_html__( 'Your AI provider has no API key yet. Add it in WearGraph → Settings.', 'weargraph' ) );
		}

		return ( $this->client_factory )( $key );
	}
}

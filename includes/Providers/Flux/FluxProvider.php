<?php
/**
 * Black Forest Labs FLUX provider (BYOK).
 *
 * @package WearGraph
 */

namespace WearGraph\Providers\Flux;

defined( 'ABSPATH' ) || exit;

use WearGraph\Domain\ImageEditInput;
use WearGraph\Domain\ImageGenerationInput;
use WearGraph\Domain\ImageOptions;
use WearGraph\Domain\ImageRef;
use WearGraph\Domain\TryOnInput;
use WearGraph\Providers\CostEstimating;
use WearGraph\Providers\Http\ApiClient;
use WearGraph\Providers\Http\ErrorMap;
use WearGraph\Providers\Http\ResultDownloader;
use WearGraph\Providers\ImageEditCapable;
use WearGraph\Providers\ImageGenerationCapable;
use WearGraph\Providers\ProviderCapability;
use WearGraph\Providers\ProviderException;
use WearGraph\Providers\ProviderHealth;
use WearGraph\Providers\ProviderInfo;
use WearGraph\Providers\ProviderOutput;
use WearGraph\Providers\Prompts;
use WearGraph\Providers\RestImageProvider;
use WearGraph\Providers\TryOnCapable;

/**
 * FLUX.2 for images and edits (up to 8 references), the dedicated FLUX VTO endpoint for
 * try-on. Asynchronous: submit, poll the returned polling_url, download the signed result
 * (valid 10 minutes, served from *.bfl.ai only). Images travel as base64.
 */
class FluxProvider extends RestImageProvider implements ImageGenerationCapable, ImageEditCapable, TryOnCapable, CostEstimating {

	public const ID       = 'flux';
	public const BASE_URL = 'https://api.bfl.ai/v1/';

	/**
	 * Longest wait for a result, seconds.
	 */
	private const MAX_WAIT = 180;

	/**
	 * Downloader for results (injectable for tests).
	 *
	 * @var ResultDownloader|null
	 */
	private $downloader;

	/**
	 * Sleep function (injectable for tests).
	 *
	 * @var callable
	 */
	private $sleep;

	/**
	 * Constructor.
	 *
	 * @param callable|null         $client_factory fn( string $key ): ApiClient.
	 * @param ResultDownloader|null $downloader     Result downloader.
	 * @param callable|null         $sleep          fn( float $seconds ).
	 */
	public function __construct( ?callable $client_factory = null, ?ResultDownloader $downloader = null, ?callable $sleep = null ) {
		parent::__construct( $client_factory );
		$this->downloader = $downloader;
		$this->sleep      = $sleep ?? static function ( float $seconds ): void {
			usleep( (int) ( $seconds * 1000000 ) );
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
		return 'Black Forest Labs FLUX';
	}

	/**
	 * Capabilities (no vision).
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
		return ProviderInfo::make(
			array(
				'id'          => self::ID,
				'label'       => $this->label(),
				'initials'    => 'FX',
				'tagline'     => __( 'FLUX.2 photoreal product images with up to 8 references, plus a dedicated virtual try-on model.', 'weargraph' ),
				'badges'      => array( __( 'Images', 'weargraph' ), __( 'Try-on model', 'weargraph' ), __( 'From $0.014', 'weargraph' ) ),
				'key_url'     => 'https://dashboard.bfl.ai/api/keys',
				'key_help'    => __( 'Create a key in the Black Forest Labs dashboard and add credits (1 credit = $0.01).', 'weargraph' ),
				'key_pattern' => '/^[A-Za-z0-9_\-]{20,}$/',
				'tasks'       => array( ProviderInfo::TASK_STUDIO, ProviderInfo::TASK_TRYON ),
				'fields'      => array(
					'image_model' => array(
						'label'   => __( 'Image model', 'weargraph' ),
						'type'    => 'select',
						'choices' => FluxModels::options(),
						'default' => FluxModels::DEFAULT_IMAGE,
					),
					'tryon_mode'  => array(
						'label'       => __( 'Try-on', 'weargraph' ),
						'type'        => 'select',
						'choices'     => array(
							'vto'   => __( 'FLUX Virtual Try-On (recommended)', 'weargraph' ),
							'model' => __( 'The image model above', 'weargraph' ),
						),
						'default'     => 'vto',
						'description' => __( 'The try-on model keeps the shopper\'s face and pose best and is fast.', 'weargraph' ),
					),
				),
				'data_note'   => __( 'Instructions and the images involved (product photos and, for try-on, the shopper\'s photo) are sent from your server directly to the Black Forest Labs API using your key; the result is downloaded from Black Forest Labs right away. Nothing is sent to WearGraph or CodeSolz.', 'weargraph' ),
				'terms_url'   => 'https://bfl.ai/legal/terms-of-service',
				'privacy_url' => 'https://bfl.ai/legal/privacy-policy',
			)
		);
	}

	/**
	 * Real client.
	 *
	 * @param string $key Key.
	 */
	protected function make_client( string $key ): ApiClient {
		return new ApiClient( self::BASE_URL, array( 'x-key' => $key ), $this->label() );
	}

	/**
	 * Selected model.
	 */
	public function image_model(): string {
		$model = (string) $this->setting( 'image_model' );

		return isset( FluxModels::IMAGE[ $model ] ) ? $model : FluxModels::DEFAULT_IMAGE;
	}

	/**
	 * Key check: the credit balance is free to read.
	 */
	public function health_check(): ProviderHealth {
		try {
			$result = $this->client()->get( 'credits' );
		} catch ( ProviderException $e ) {
			return new ProviderHealth( false, 0, $this->image_model(), $e->getMessage(), $e->error_code() );
		}
		$credits = $result['body']['credits'] ?? null;
		if ( is_numeric( $credits ) && (float) $credits <= 0 ) {
			return new ProviderHealth( false, $result['latency_ms'], $this->image_model(), __( 'Your Black Forest Labs key works but has no credits left. Add credits in the dashboard.', 'weargraph' ), ProviderException::QUOTA );
		}

		return new ProviderHealth(
			true,
			$result['latency_ms'],
			$this->image_model(),
			is_numeric( $credits )
				/* translators: %s: number of credits */
				? sprintf( __( 'Connected. %s credits left.', 'weargraph' ), number_format_i18n( (float) $credits, 0 ) )
				: __( 'Connected. Your Black Forest Labs key works.', 'weargraph' )
		);
	}

	/**
	 * Studio image.
	 *
	 * @param ImageGenerationInput $input Request.
	 * @throws ProviderException On failure.
	 */
	public function generate_image( ImageGenerationInput $input ): ProviderOutput {
		$model = $this->image_model();

		return $this->run( $model, $this->model_body( $model, Prompts::generation( $input ), $input->references, $input->options ), $input->options );
	}

	/**
	 * Edit.
	 *
	 * @param ImageEditInput $input Request.
	 * @throws ProviderException On failure.
	 */
	public function edit_image( ImageEditInput $input ): ProviderOutput {
		$model = $this->image_model();

		return $this->run( $model, $this->model_body( $model, Prompts::edit( $input ), self::images_of( $input ), $input->options ), $input->options );
	}

	/**
	 * Region support: the try-on model is built for clothing.
	 *
	 * @param TryOnInput $input Request.
	 */
	public function can_handle( TryOnInput $input ): ProviderCapability {
		return $this->region_capability( $input, 'vto' === $this->setting( 'tryon_mode' ) ? 0.85 : 0.7 );
	}

	/**
	 * Try-on.
	 *
	 * @param TryOnInput $input Request.
	 * @throws ProviderException On failure.
	 */
	public function try_on( TryOnInput $input ): ProviderOutput {
		if ( 'model' === $this->setting( 'tryon_mode' ) ) {
			$model  = $this->image_model();
			$images = self::images_of( $input );

			return $this->run( $model, $this->model_body( $model, Prompts::tryon( $input, count( $images ) - 1 ), $images, $input->options ), $input->options );
		}

		// The try-on model takes one garment image (several garments go on one canvas).
		$body = array(
			'prompt'           => Prompts::tryon_short( $input ),
			'person'           => $this->b64( $input->person ),
			'garment'          => $this->b64( $input->garments[0] ),
			'output_format'    => 'png',
			'safety_tolerance' => 2,
		);

		return $this->run( FluxModels::VTO_ENDPOINT, $body, $input->options );
	}

	/**
	 * Estimated USD.
	 *
	 * @param object $input Any *Input DTO.
	 */
	public function estimate_cost( object $input ): float {
		if ( $input instanceof TryOnInput && 'model' !== $this->setting( 'tryon_mode' ) ) {
			return FluxModels::VTO_ESTIMATE;
		}
		$options = isset( $input->options ) && $input->options instanceof ImageOptions ? $input->options : new ImageOptions();

		return FluxModels::estimate( $this->image_model(), $options, count( self::images_of( $input ) ) );
	}

	/**
	 * Estimated latency.
	 *
	 * @param object $input Any *Input DTO.
	 */
	public function estimate_latency_ms( object $input ): int {
		return $input instanceof TryOnInput ? 12000 : 25000;
	}

	/**
	 * Request body for a FLUX.2 model (public for tests).
	 *
	 * @param string       $model   Endpoint.
	 * @param string       $prompt  Prompt.
	 * @param ImageRef[]   $images  Images in prompt order.
	 * @param ImageOptions $options Output shape.
	 * @return array<string,mixed>
	 * @throws ProviderException When an image cannot be read.
	 */
	public function model_body( string $model, string $prompt, array $images, ImageOptions $options ): array {
		list( $w, $h ) = FluxModels::dimensions( $options );
		$body          = array(
			'prompt'           => $prompt,
			'width'            => $w,
			'height'           => $h,
			'output_format'    => 'png',
			'safety_tolerance' => 2,
		);
		foreach ( array_slice( $images, 0, (int) FluxModels::get( $model )['refs'] ) as $i => $image ) {
			$body[ 0 === $i ? 'input_image' : 'input_image_' . ( $i + 1 ) ] = $this->b64( $image );
		}

		return $body;
	}

	/**
	 * Submit, poll, download.
	 *
	 * @param string              $endpoint Endpoint path.
	 * @param array<string,mixed> $body     Body.
	 * @param ImageOptions        $options  Output shape.
	 * @throws ProviderException On failure.
	 */
	private function run( string $endpoint, array $body, ImageOptions $options ): ProviderOutput {
		$client  = $this->client();
		$started = microtime( true );
		$submit  = $client->post_json( $endpoint, $body, 60 );
		$id      = (string) ( $submit['body']['id'] ?? '' );
		$poll    = (string) ( $submit['body']['polling_url'] ?? '' );
		if ( '' === $poll && '' !== $id ) {
			$poll = 'get_result?id=' . rawurlencode( $id );
		}
		if ( '' === $poll ) {
			throw ErrorMap::no_image();
		}
		if ( 0 === strpos( $poll, 'https://' ) && ! ( new ResultDownloader( array( '.bfl.ai' ) ) )->allowed( $poll ) ) {
			throw ProviderException::no_output( esc_html__( 'The AI provider returned a link WearGraph does not trust.', 'weargraph' ) );
		}

		$sample = '';
		$wait   = 0.0;
		while ( $wait < self::MAX_WAIT ) {
			$delay = $wait < 10 ? 1.0 : 2.0;
			( $this->sleep )( $delay );
			$wait  += $delay;
			$status = $client->get( $poll, 20 )['body'];
			$state  = (string) ( $status['status'] ?? '' );
			if ( 'Ready' === $state ) {
				$sample = (string) ( $status['result']['sample'] ?? '' );
				break;
			}
			if ( in_array( $state, array( 'Request Moderated', 'Content Moderated' ), true ) ) {
				throw ErrorMap::safety();
			}
			if ( in_array( $state, array( 'Error', 'Failed', 'Task not found' ), true ) ) {
				$error = new ProviderException( ProviderException::SERVER, esc_html__( 'The AI provider could not finish this image. Please try again.', 'weargraph' ), true );
				throw $error;
			}
		}
		if ( '' === $sample ) {
			$error = new ProviderException( ProviderException::TIMEOUT, esc_html__( 'The AI provider took too long to respond. Please try again.', 'weargraph' ), true );
			throw $error;
		}

		$image  = ( $this->downloader ?? new ResultDownloader( array( '.bfl.ai' ) ) )->fetch( $sample );
		$output = $this->output( $endpoint, (int) round( ( microtime( true ) - $started ) * 1000 ), $id );

		$output->images[] = $image;
		$cost             = $submit['body']['cost'] ?? null;
		$output->cost_usd = is_numeric( $cost ) ? round( (float) $cost / 100, 4 ) : ( FluxModels::VTO_ENDPOINT === $endpoint ? FluxModels::VTO_ESTIMATE : FluxModels::estimate( $endpoint, $options, 0 ) );
		$output->metadata = array(
			'aspect_ratio' => $options->aspect_ratio,
			'size'         => $options->size,
			'output_mp'    => $submit['body']['output_mp'] ?? null,
		);

		return $output;
	}

	/**
	 * Base64 of a local image (FLUX takes it without a data: prefix).
	 *
	 * @param ImageRef $image Image.
	 * @throws ProviderException When unreadable.
	 */
	private function b64( ImageRef $image ): string {
		return base64_encode( $this->bytes( $image ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- API image payload.
	}
}

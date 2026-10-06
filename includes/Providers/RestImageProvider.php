<?php
/**
 * Common ground for the REST image providers (OpenAI, OpenRouter, FLUX, xAI…).
 *
 * @package WearGraph
 */

namespace WearGraph\Providers;

defined( 'ABSPATH' ) || exit;

use WearGraph\Domain\ImageEditInput;
use WearGraph\Domain\ImageGenerationInput;
use WearGraph\Domain\ImageRef;
use WearGraph\Domain\TryOnInput;
use WearGraph\Providers\Http\ApiClient;
use WearGraph\Support\Files;

/**
 * Key handling, a replaceable client factory (tests), image reading, the default
 * settings store and a garment-region confidence table. Subclasses implement the
 * capability interfaces they support.
 */
abstract class RestImageProvider implements ProviderInterface, DescribedProvider {

	/**
	 * Builds the client for a key: fn( string $key ): ApiClient.
	 *
	 * @var callable
	 */
	private $client_factory;

	/**
	 * Constructor.
	 *
	 * @param callable|null $client_factory fn( string $key ): ApiClient; null = the real client.
	 */
	public function __construct( ?callable $client_factory = null ) {
		$this->client_factory = $client_factory ?? function ( string $key ): ApiClient {
			return $this->make_client( $key );
		};
	}

	/**
	 * The real client for a key.
	 *
	 * @param string $key Plain key.
	 */
	abstract protected function make_client( string $key ): ApiClient;

	/**
	 * Key stored.
	 */
	public function is_configured(): bool {
		return ProviderKeys::has( $this->id() );
	}

	/**
	 * Current values (default store: ProviderOptions).
	 *
	 * @return array<string,mixed>
	 */
	public function setting_values(): array {
		$values = ProviderOptions::values( $this->id(), $this->info()->fields );
		foreach ( $this->info()->fields as $name => $spec ) {
			// A saved value that is no longer offered falls back to the default.
			if ( 'select' === ( $spec['type'] ?? '' ) && ! isset( ProviderOptions::choices( $this->id(), (string) $name, $spec )[ (string) $values[ $name ] ] ) ) {
				$values[ $name ] = $spec['default'] ?? '';
			}
		}

		return $values;
	}

	/**
	 * Save posted values (validated against the fields).
	 *
	 * @param array<string,string> $values Posted values.
	 */
	public function save_settings( array $values ): void {
		ProviderOptions::save( $this->id(), $this->info()->fields, $values );
	}

	/**
	 * One current setting.
	 *
	 * @param string $name Field name.
	 * @return mixed
	 */
	protected function setting( string $name ) {
		return $this->setting_values()[ $name ] ?? '';
	}

	/**
	 * Client for the stored key.
	 *
	 * @throws ProviderException When no key is stored.
	 */
	protected function client(): ApiClient {
		$key = ProviderKeys::get( $this->id() );
		if ( '' === $key ) {
			/* translators: %s: provider name */
			throw ProviderException::not_configured( esc_html( sprintf( __( '%s has no API key yet. Add it in WearGraph → Settings → AI Provider.', 'weargraph' ), $this->label() ) ) );
		}

		return ( $this->client_factory )( $key );
	}

	/**
	 * File bytes of a local image.
	 *
	 * @param ImageRef $image Image.
	 * @throws ProviderException When unreadable.
	 */
	protected function bytes( ImageRef $image ): string {
		$bytes = Files::read( $image->path );
		if ( '' === $bytes ) {
			throw ProviderException::bad_input( esc_html__( 'An image for this request could not be read.', 'weargraph' ) );
		}

		return $bytes;
	}

	/**
	 * data: URL of a local image.
	 *
	 * @param ImageRef $image Image.
	 * @throws ProviderException When unreadable.
	 */
	protected function data_url( ImageRef $image ): string {
		return 'data:' . $image->mime . ';base64,' . base64_encode( $this->bytes( $image ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- API image payload.
	}

	/**
	 * Images of a request, in the order the prompt refers to them.
	 *
	 * @param object $input Any image *Input DTO.
	 * @return ImageRef[]
	 */
	protected static function images_of( object $input ): array {
		if ( $input instanceof TryOnInput ) {
			return array_merge( array( $input->person ), $input->garments );
		}
		if ( $input instanceof ImageEditInput ) {
			return array_merge( array( $input->source ), $input->mask ? array( $input->mask ) : array(), $input->references );
		}
		if ( $input instanceof ImageGenerationInput ) {
			return $input->references;
		}

		return array();
	}

	/**
	 * Garment regions: general image models are good at clothing, weaker on shoes and
	 * accessories.
	 *
	 * @param TryOnInput $input Request.
	 * @param float      $base  Confidence for clothing.
	 */
	protected function region_capability( TryOnInput $input, float $base ): ProviderCapability {
		switch ( $input->region ) {
			case 'footwear':
				return new ProviderCapability( true, $base * 0.7, __( 'Footwear previews are less reliable.', 'weargraph' ) );
			case 'accessory':
				return new ProviderCapability( true, $base * 0.5, __( 'Accessory previews are experimental.', 'weargraph' ) );
			default:
				return new ProviderCapability( true, $base );
		}
	}

	/**
	 * Output with the common fields filled.
	 *
	 * @param string $model      Model id.
	 * @param int    $latency_ms Latency.
	 * @param string $request_id Provider request id.
	 */
	protected function output( string $model, int $latency_ms, string $request_id = '' ): ProviderOutput {
		$output                      = new ProviderOutput();
		$output->provider            = $this->id();
		$output->model               = $model;
		$output->latency_ms          = $latency_ms;
		$output->provider_request_id = $request_id;

		return $output;
	}

	/**
	 * Add a base64 image to an output.
	 *
	 * @param ProviderOutput $output Output.
	 * @param string         $b64    Base64 (or data: URL).
	 * @param string         $mime   MIME if known.
	 */
	protected static function add_b64_image( ProviderOutput $output, string $b64, string $mime = '' ): void {
		if ( 0 === strpos( $b64, 'data:' ) ) {
			$comma = strpos( $b64, ',' );
			$mime  = '' !== $mime ? $mime : (string) substr( $b64, 5, (int) strpos( $b64, ';' ) - 5 );
			$b64   = false === $comma ? '' : substr( $b64, $comma + 1 );
		}
		$bytes = base64_decode( $b64, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- API image payload.
		if ( false === $bytes || '' === $bytes ) {
			return;
		}
		$output->images[] = array(
			'mime'  => in_array( $mime, array( 'image/png', 'image/jpeg', 'image/webp' ), true ) ? $mime : Http\ResultDownloader::sniff( $bytes ),
			'bytes' => $bytes,
		);
	}
}

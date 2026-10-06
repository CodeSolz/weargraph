<?php
/**
 * HTTP client for the Gemini API.
 *
 * @package WearGraph
 */

namespace WearGraph\Providers\Gemini;

defined( 'ABSPATH' ) || exit;

use WearGraph\Providers\ProviderException;
use WearGraph\Support\Logger;

/**
 * The key travels in the x-goog-api-key header, never in the URL, so it cannot
 * leak into proxy or server access logs. Generation calls run inside background
 * jobs only (to-do/01-ARCHITECTURE.md §6), so a long timeout is acceptable here.
 */
class GeminiClient {

	public const BASE_URL = 'https://generativelanguage.googleapis.com/v1beta/';

	/**
	 * API key.
	 *
	 * @var string
	 */
	private $api_key;

	/**
	 * Constructor.
	 *
	 * @param string $api_key Plain API key.
	 */
	public function __construct( string $api_key ) {
		$this->api_key = $api_key;
	}

	/**
	 * POST models/{model}:{action}.
	 *
	 * @param string              $model   Model id.
	 * @param string              $action  e.g. generateContent.
	 * @param array<string,mixed> $body    Request body.
	 * @param int                 $timeout Seconds.
	 * @return array{body:array<string,mixed>,latency_ms:int}
	 * @throws ProviderException On transport, HTTP or decode failure.
	 */
	public function post( string $model, string $action, array $body, int $timeout = 120 ): array {
		return $this->request( 'POST', 'models/' . rawurlencode( $model ) . ':' . $action, $body, $timeout );
	}

	/**
	 * GET a path relative to BASE_URL.
	 *
	 * @param string $path    e.g. models/gemini-3.1-flash-image.
	 * @param int    $timeout Seconds.
	 * @return array{body:array<string,mixed>,latency_ms:int}
	 * @throws ProviderException On transport, HTTP or decode failure.
	 */
	public function get( string $path, int $timeout = 15 ): array {
		return $this->request( 'GET', $path, null, $timeout );
	}

	/**
	 * Perform the request and normalise failures.
	 *
	 * @param string                   $method  HTTP method.
	 * @param string                   $path    Path relative to BASE_URL.
	 * @param array<string,mixed>|null $body    JSON body.
	 * @param int                      $timeout Seconds.
	 * @return array{body:array<string,mixed>,latency_ms:int}
	 * @throws ProviderException On failure.
	 */
	protected function request( string $method, string $path, ?array $body, int $timeout ): array {
		$started  = microtime( true );
		$response = $this->send( $method, self::BASE_URL . $path, $body, $timeout );
		$latency  = (int) round( ( microtime( true ) - $started ) * 1000 );

		if ( isset( $response['transport_error'] ) ) {
			Logger::error(
				'Gemini transport error',
				array(
					'path'  => $path,
					'error' => $response['transport_error'],
				)
			);
			$exception = GeminiErrors::from_transport( (string) $response['transport_error'] );
			throw $exception; // Carries only an escaped, user-safe message.
		}

		$decoded = json_decode( (string) $response['body'], true );
		$decoded = is_array( $decoded ) ? $decoded : array();
		$status  = (int) $response['status'];

		if ( $status < 200 || $status >= 300 ) {
			Logger::error(
				'Gemini HTTP error',
				array(
					'path'    => $path,
					'status'  => $status,
					'message' => (string) ( $decoded['error']['message'] ?? '' ),
				)
			);
			$exception = GeminiErrors::from_http( $status, $decoded );
			throw $exception; // Carries only an escaped, user-safe message.
		}

		return array(
			'body'       => $decoded,
			'latency_ms' => $latency,
		);
	}

	/**
	 * Raw HTTP call. Overridden by the test double.
	 *
	 * @param string                   $method  HTTP method.
	 * @param string                   $url     Full URL.
	 * @param array<string,mixed>|null $body    JSON body.
	 * @param int                      $timeout Seconds.
	 * @return array{status?:int,body?:string,transport_error?:string}
	 */
	protected function send( string $method, string $url, ?array $body, int $timeout ): array {
		$response = wp_remote_request(
			$url,
			array(
				'method'  => $method,
				'timeout' => $timeout,
				'headers' => array(
					'x-goog-api-key' => $this->api_key,
					'Content-Type'   => 'application/json',
				),
				'body'    => null === $body ? null : wp_json_encode( $body ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return array( 'transport_error' => $response->get_error_message() );
		}

		return array(
			'status' => (int) wp_remote_retrieve_response_code( $response ),
			'body'   => (string) wp_remote_retrieve_body( $response ),
		);
	}
}

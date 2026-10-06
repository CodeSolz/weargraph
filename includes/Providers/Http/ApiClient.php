<?php
/**
 * HTTP client shared by the REST image providers.
 *
 * @package WearGraph
 */

namespace WearGraph\Providers\Http;

defined( 'ABSPATH' ) || exit;

use WearGraph\Providers\ProviderException;
use WearGraph\Support\Logger;

/**
 * JSON and multipart requests with the key in a header (never in the URL), timing, and
 * failures normalised through ErrorMap. Generation calls run in background jobs, so long
 * timeouts are fine. send() is the only method that touches the network (tests override it).
 */
class ApiClient {

	/**
	 * Base URL, with trailing slash.
	 *
	 * @var string
	 */
	private $base_url;

	/**
	 * Auth and fixed headers.
	 *
	 * @var array<string,string>
	 */
	private $headers;

	/**
	 * Provider name for messages.
	 *
	 * @var string
	 */
	private $label;

	/**
	 * Constructor.
	 *
	 * @param string               $base_url Base URL.
	 * @param array<string,string> $headers  Headers sent with every request (auth).
	 * @param string               $label    Provider name, e.g. "OpenAI".
	 */
	public function __construct( string $base_url, array $headers, string $label ) {
		$this->base_url = rtrim( $base_url, '/' ) . '/';
		$this->headers  = $headers;
		$this->label    = $label;
	}

	/**
	 * GET a path (or full https URL).
	 *
	 * @param string $path    Path relative to the base, or an absolute URL.
	 * @param int    $timeout Seconds.
	 * @return array{body:array<string,mixed>,latency_ms:int}
	 * @throws ProviderException On failure.
	 */
	public function get( string $path, int $timeout = 20 ): array {
		return $this->request( 'GET', $path, null, array(), $timeout );
	}

	/**
	 * POST JSON.
	 *
	 * @param string              $path    Path.
	 * @param array<string,mixed> $body    Body.
	 * @param int                 $timeout Seconds.
	 * @return array{body:array<string,mixed>,latency_ms:int}
	 * @throws ProviderException On failure.
	 */
	public function post_json( string $path, array $body, int $timeout = 180 ): array {
		return $this->request( 'POST', $path, (string) wp_json_encode( $body ), array( 'Content-Type' => 'application/json' ), $timeout );
	}

	/**
	 * POST multipart/form-data.
	 *
	 * @param string                                                           $path    Path.
	 * @param array<int,array{name:string,value?:string,filename?:string,mime?:string,bytes?:string}> $parts Parts; file parts carry filename, mime and bytes.
	 * @param int                                                              $timeout Seconds.
	 * @return array{body:array<string,mixed>,latency_ms:int}
	 * @throws ProviderException On failure.
	 */
	public function post_multipart( string $path, array $parts, int $timeout = 180 ): array {
		$boundary = 'wg' . bin2hex( random_bytes( 12 ) );

		return $this->request( 'POST', $path, self::multipart_body( $parts, $boundary ), array( 'Content-Type' => 'multipart/form-data; boundary=' . $boundary ), $timeout );
	}

	/**
	 * Build a multipart body (public for tests).
	 *
	 * @param array<int,array<string,string>> $parts    Parts.
	 * @param string                          $boundary Boundary.
	 */
	public static function multipart_body( array $parts, string $boundary ): string {
		$body = '';
		foreach ( $parts as $part ) {
			$name  = str_replace( array( '"', "\r", "\n" ), '', (string) $part['name'] );
			$body .= '--' . $boundary . "\r\n";
			if ( isset( $part['bytes'] ) ) {
				$file  = str_replace( array( '"', "\r", "\n" ), '', (string) ( $part['filename'] ?? 'image' ) );
				$body .= 'Content-Disposition: form-data; name="' . $name . '"; filename="' . $file . "\"\r\n";
				$body .= 'Content-Type: ' . ( $part['mime'] ?? 'application/octet-stream' ) . "\r\n\r\n";
				$body .= $part['bytes'] . "\r\n";
			} else {
				$body .= 'Content-Disposition: form-data; name="' . $name . "\"\r\n\r\n";
				$body .= (string) ( $part['value'] ?? '' ) . "\r\n";
			}
		}

		return $body . '--' . $boundary . "--\r\n";
	}

	/**
	 * Perform a request and normalise failures.
	 *
	 * @param string               $method  Method.
	 * @param string               $path    Path or absolute URL.
	 * @param string|null          $body    Raw body.
	 * @param array<string,string> $headers Extra headers.
	 * @param int                  $timeout Seconds.
	 * @return array{body:array<string,mixed>,latency_ms:int}
	 * @throws ProviderException On failure.
	 */
	protected function request( string $method, string $path, ?string $body, array $headers, int $timeout ): array {
		$url      = 0 === strpos( $path, 'https://' ) ? $path : $this->base_url . ltrim( $path, '/' );
		$started  = microtime( true );
		$response = $this->send( $method, $url, $body, array_merge( $this->headers, $headers ), $timeout );
		$latency  = (int) round( ( microtime( true ) - $started ) * 1000 );

		if ( isset( $response['transport_error'] ) ) {
			Logger::error(
				$this->label . ' transport error',
				array(
					'url'   => self::path_of( $url ),
					'error' => $response['transport_error'],
				)
			);
			$error = ErrorMap::from_transport( (string) $response['transport_error'] ); // Messages are escaped where ErrorMap builds them.
			throw $error;
		}

		$decoded = json_decode( (string) ( $response['body'] ?? '' ), true );
		$decoded = is_array( $decoded ) ? $decoded : array();
		$status  = (int) ( $response['status'] ?? 0 );

		if ( $status < 200 || $status >= 300 ) {
			Logger::error(
				$this->label . ' HTTP error',
				array(
					'url'     => self::path_of( $url ),
					'status'  => $status,
					'message' => substr( self::redact( ErrorMap::error_text( $decoded ) ), 0, 300 ),
				)
			);
			$error = ErrorMap::from_http( $status, $decoded, $this->label ); // Messages are escaped where ErrorMap builds them.
			throw $error;
		}

		return array(
			'body'       => $decoded,
			'latency_ms' => $latency,
		);
	}

	/**
	 * Raw HTTP call. Overridden by test doubles.
	 *
	 * @param string               $method  Method.
	 * @param string               $url     URL.
	 * @param string|null          $body    Raw body.
	 * @param array<string,string> $headers Headers.
	 * @param int                  $timeout Seconds.
	 * @return array{status?:int,body?:string,transport_error?:string}
	 */
	protected function send( string $method, string $url, ?string $body, array $headers, int $timeout ): array {
		$response = wp_remote_request(
			$url,
			array(
				'method'  => $method,
				'timeout' => $timeout,
				'headers' => $headers,
				'body'    => $body,
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

	/**
	 * URL without query (logs).
	 *
	 * @param string $url URL.
	 */
	private static function path_of( string $url ): string {
		return (string) strtok( $url, '?' );
	}

	/**
	 * Strip anything that looks like a key from provider text before logging.
	 *
	 * @param string $text Text.
	 */
	public static function redact( string $text ): string {
		return (string) preg_replace( '/\b(sk-[A-Za-z0-9_\-]{4,}|sk-or-[A-Za-z0-9_\-]{4,}|xai-[A-Za-z0-9_\-]{4,}|AIza[0-9A-Za-z_\-]{10,}|[A-Fa-f0-9]{32,})\b/', '[redacted]', $text );
	}
}

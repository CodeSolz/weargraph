<?php
/**
 * Downloads a generated image from a provider's delivery URL.
 *
 * @package WearGraph
 */

namespace WearGraph\Providers\Http;

defined( 'ABSPATH' ) || exit;

use WearGraph\Providers\ProviderException;

/**
 * Only HTTPS, only hosts the provider is known to deliver from (so a hostile response
 * cannot make the server fetch internal addresses), with a size cap. Retries a few
 * times: signed URLs can lag a moment behind the "ready" status.
 */
class ResultDownloader {

	public const MAX_BYTES = 25 * 1024 * 1024;

	/**
	 * Allowed host suffixes, e.g. ".bfl.ai".
	 *
	 * @var string[]
	 */
	private $hosts;

	/**
	 * Constructor.
	 *
	 * @param string[] $hosts Allowed host suffixes (each starting with a dot) or exact hosts.
	 */
	public function __construct( array $hosts ) {
		$this->hosts = $hosts;
	}

	/**
	 * Whether a URL may be fetched.
	 *
	 * @param string $url URL.
	 */
	public function allowed( string $url ): bool {
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || 'https' !== strtolower( (string) ( $parts['scheme'] ?? '' ) ) || empty( $parts['host'] ) || isset( $parts['user'] ) || isset( $parts['port'] ) ) {
			return false;
		}
		$host = strtolower( (string) $parts['host'] );
		foreach ( $this->hosts as $allowed ) {
			$allowed = strtolower( $allowed );
			if ( ltrim( $allowed, '.' ) === $host || ( '.' === $allowed[0] && substr( $host, -strlen( $allowed ) ) === $allowed ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Fetch the image.
	 *
	 * @param string $url URL from the provider's response.
	 * @return array{mime:string,bytes:string}
	 * @throws ProviderException When refused or unavailable.
	 */
	public function fetch( string $url ): array {
		if ( ! $this->allowed( $url ) ) {
			throw ProviderException::no_output( esc_html__( 'The AI provider returned an image link WearGraph does not trust.', 'weargraph' ) );
		}

		$last = array();
		for ( $attempt = 0; $attempt < 3; $attempt++ ) {
			$last = $this->send( $url );
			if ( isset( $last['bytes'] ) && '' !== $last['bytes'] ) {
				break;
			}
			if ( $attempt < 2 ) {
				$this->pause( 1 );
			}
		}

		$bytes = (string) ( $last['bytes'] ?? '' );
		if ( '' === $bytes || strlen( $bytes ) > self::MAX_BYTES ) {
			throw ProviderException::no_output( esc_html__( 'The generated image could not be downloaded. Please try again.', 'weargraph' ) );
		}

		return array(
			'mime'  => self::sniff( $bytes ),
			'bytes' => $bytes,
		);
	}

	/**
	 * MIME from the first bytes.
	 *
	 * @param string $bytes Bytes.
	 */
	public static function sniff( string $bytes ): string {
		if ( 0 === strncmp( $bytes, "\x89PNG", 4 ) ) {
			return 'image/png';
		}
		if ( 0 === strncmp( $bytes, 'RIFF', 4 ) && 'WEBP' === substr( $bytes, 8, 4 ) ) {
			return 'image/webp';
		}

		return 'image/jpeg';
	}

	/**
	 * Raw GET. Overridden by test doubles.
	 *
	 * @param string $url URL.
	 * @return array{bytes?:string}
	 */
	protected function send( string $url ): array {
		$response = wp_safe_remote_get(
			$url,
			array(
				'timeout'             => 60,
				'redirection'         => 0,
				'limit_response_size' => self::MAX_BYTES + 1,
			)
		);
		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return array();
		}

		return array( 'bytes' => (string) wp_remote_retrieve_body( $response ) );
	}

	/**
	 * Wait between attempts. Overridden by test doubles.
	 *
	 * @param int $seconds Seconds.
	 */
	protected function pause( int $seconds ): void {
		sleep( $seconds );
	}
}

<?php
/**
 * HTTP failures → ProviderException, for the REST providers.
 *
 * @package WearGraph
 */

namespace WearGraph\Providers\Http;

defined( 'ABSPATH' ) || exit;

use WearGraph\Providers\ProviderException;

/**
 * Status codes and the provider's own error words decide the code; the message is always
 * WearGraph's own (provider text is never shown: some providers echo the key back, e.g.
 * OpenAI's "Incorrect API key provided: sk-…").
 */
final class ErrorMap {

	/**
	 * Transport failure (no HTTP response).
	 *
	 * @param string $transport_message wp_remote_* error message.
	 */
	public static function from_transport( string $transport_message ): ProviderException {
		$msg = strtolower( $transport_message );
		if ( false !== strpos( $msg, 'timed out' ) || false !== strpos( $msg, 'curl error 28' ) ) {
			return new ProviderException( ProviderException::TIMEOUT, esc_html__( 'The AI provider took too long to respond. Please try again.', 'weargraph' ), true );
		}

		return new ProviderException( ProviderException::SERVER, esc_html__( 'Could not reach the AI provider. Please check the server connection and try again.', 'weargraph' ), true );
	}

	/**
	 * Non-2xx response.
	 *
	 * @param int                 $status HTTP status.
	 * @param array<string,mixed> $body   Decoded body (may be empty).
	 * @param string              $label  Provider name for messages, e.g. "OpenAI".
	 */
	public static function from_http( int $status, array $body, string $label ): ProviderException {
		$text = strtolower( self::error_text( $body ) );
		/* translators: %s: provider name, e.g. OpenAI */
		$auth = esc_html( sprintf( __( '%s rejected the API key. Check the key in WearGraph → Settings → AI Provider.', 'weargraph' ), $label ) );

		$key_words = array( 'api key', 'api_key', 'apikey', 'unauthorized', 'authentication', 'invalid key', 'incorrect api key' );
		$says_key  = false;
		foreach ( $key_words as $word ) {
			$says_key = $says_key || false !== strpos( $text, $word );
		}

		if ( 401 === $status || 403 === $status ) {
			if ( 403 === $status && ( false !== strpos( $text, 'verif' ) || false !== strpos( $text, 'organization must be verified' ) ) ) {
				/* translators: %s: provider name */
				return new ProviderException( ProviderException::AUTH, esc_html( sprintf( __( '%s needs your account to be verified before this model can be used. Verify your organisation on the provider\'s website, or choose another model.', 'weargraph' ), $label ) ) );
			}
			return new ProviderException( ProviderException::AUTH, $auth );
		}

		if ( 402 === $status || false !== strpos( $text, 'insufficient' ) || false !== strpos( $text, 'billing' ) || ( false !== strpos( $text, 'credits' ) && false !== strpos( $text, 'not enough' ) ) ) {
			/* translators: %s: provider name */
			return new ProviderException( ProviderException::QUOTA, esc_html( sprintf( __( 'Your %s account has no credit left. Add credit or check billing on the provider\'s website.', 'weargraph' ), $label ) ) );
		}

		if ( 400 === $status || 422 === $status ) {
			if ( $says_key ) {
				return new ProviderException( ProviderException::AUTH, $auth );
			}
			if ( false !== strpos( $text, 'safety' ) || false !== strpos( $text, 'moderat' ) || false !== strpos( $text, 'content policy' ) || false !== strpos( $text, 'nsfw' ) ) {
				return self::safety();
			}

			return new ProviderException( ProviderException::BAD_INPUT, esc_html__( 'The AI provider could not process this request. Try a different image or settings.', 'weargraph' ) );
		}

		if ( 404 === $status ) {
			return new ProviderException( ProviderException::BAD_INPUT, esc_html__( 'The selected AI model is not available for your API key. Choose another model in WearGraph → Settings.', 'weargraph' ) );
		}

		if ( 429 === $status ) {
			if ( false !== strpos( $text, 'quota' ) ) {
				return new ProviderException( ProviderException::QUOTA, esc_html__( 'Your AI provider quota is used up. Check the plan or billing on your provider account.', 'weargraph' ) );
			}

			return new ProviderException( ProviderException::RATE_LIMITED, esc_html__( 'The AI provider is busy. Please try again in a moment.', 'weargraph' ), true );
		}

		if ( 504 === $status || 408 === $status ) {
			return new ProviderException( ProviderException::TIMEOUT, esc_html__( 'The AI provider took too long to respond. Please try again.', 'weargraph' ), true );
		}

		if ( $status >= 500 ) {
			return new ProviderException( ProviderException::SERVER, esc_html__( 'The AI provider had a temporary problem. Please try again.', 'weargraph' ), true );
		}

		return new ProviderException( ProviderException::SERVER, esc_html__( 'Unexpected response from the AI provider.', 'weargraph' ) );
	}

	/**
	 * The provider declined the content.
	 */
	public static function safety(): ProviderException {
		return new ProviderException( ProviderException::SAFETY_BLOCKED, esc_html__( 'The AI provider declined this image. Please use a different photo or product image.', 'weargraph' ) );
	}

	/**
	 * No image in an otherwise successful answer.
	 */
	public static function no_image(): ProviderException {
		return ProviderException::no_output( esc_html__( 'The AI provider returned no image. Please try again.', 'weargraph' ) );
	}

	/**
	 * The provider's error words, wherever its format puts them (for classification and
	 * logs only — never shown).
	 *
	 * @param array<string,mixed> $body Decoded body.
	 */
	public static function error_text( array $body ): string {
		$candidates = array(
			$body['error']['message'] ?? null,
			$body['error']['code'] ?? null,
			is_string( $body['error'] ?? null ) ? $body['error'] : null,
			$body['message'] ?? null,
			$body['code'] ?? null,
			is_string( $body['detail'] ?? null ) ? $body['detail'] : null,
			$body['detail'][0]['msg'] ?? null,
		);
		$parts      = array();
		foreach ( $candidates as $candidate ) {
			if ( is_scalar( $candidate ) && '' !== (string) $candidate ) {
				$parts[] = (string) $candidate;
			}
		}

		return implode( ' ', $parts );
	}
}

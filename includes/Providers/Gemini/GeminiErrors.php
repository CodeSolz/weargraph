<?php
/**
 * Maps Gemini HTTP and content failures to ProviderException codes.
 *
 * @package WearGraph
 */

namespace WearGraph\Providers\Gemini;

defined( 'ABSPATH' ) || exit;

use WearGraph\Providers\ProviderException;

/**
 * Pure mapping, no I/O. Messages shown to merchants/shoppers never include the
 * provider's raw text, which can echo request details.
 */
final class GeminiErrors {

	/**
	 * Finish / block reasons that mean the content was refused.
	 */
	private const SAFETY_REASONS = array( 'SAFETY', 'IMAGE_SAFETY', 'PROHIBITED_CONTENT', 'IMAGE_PROHIBITED_CONTENT', 'BLOCKLIST', 'SPII', 'RECITATION', 'IMAGE_RECITATION' );

	/**
	 * Exception for a transport failure (no HTTP response).
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
	 * Exception for a non-2xx HTTP response.
	 *
	 * @param int                 $status HTTP status.
	 * @param array<string,mixed> $body   Decoded body (may be empty).
	 */
	public static function from_http( int $status, array $body ): ProviderException {
		$error   = isset( $body['error'] ) && is_array( $body['error'] ) ? $body['error'] : array();
		$message = strtolower( (string) ( $error['message'] ?? '' ) );
		$reasons = array();
		foreach ( (array) ( $error['details'] ?? array() ) as $detail ) {
			if ( is_array( $detail ) && isset( $detail['reason'] ) ) {
				$reasons[] = (string) $detail['reason'];
			}
		}

		$auth = esc_html__( 'Your AI provider rejected the API key. Check the key in WearGraph → Settings.', 'weargraph' );

		if ( 401 === $status || 403 === $status ) {
			return new ProviderException( ProviderException::AUTH, $auth );
		}

		if ( 400 === $status ) {
			if ( in_array( 'API_KEY_INVALID', $reasons, true ) || false !== strpos( $message, 'api key' ) ) {
				return new ProviderException( ProviderException::AUTH, $auth );
			}

			return new ProviderException( ProviderException::BAD_INPUT, esc_html__( 'The AI provider could not process this request. Try a different image or settings.', 'weargraph' ) );
		}

		if ( 404 === $status ) {
			return new ProviderException( ProviderException::BAD_INPUT, esc_html__( 'The selected AI model is not available for your API key. Choose another model in WearGraph → Settings.', 'weargraph' ) );
		}

		if ( 429 === $status ) {
			// Free-tier keys have a quota of 0 for image models: only billing fixes this.
			if ( false !== strpos( $message, 'free_tier' ) || false !== strpos( (string) wp_json_encode( $error['details'] ?? array() ), 'FreeTier' ) ) {
				return new ProviderException( ProviderException::QUOTA, esc_html__( 'Your Gemini API key is on the Google free tier, which has no quota for image models. Turn on billing for the Google Cloud project this key belongs to (Google AI Studio: Settings, Billing), wait a few minutes, then try again.', 'weargraph' ) );
			}
			if ( false !== strpos( $message, 'quota' ) && ( false !== strpos( $message, 'per day' ) || false !== strpos( $message, 'billing' ) || false !== strpos( $message, 'exceeded your current quota' ) ) ) {
				return new ProviderException( ProviderException::QUOTA, esc_html__( 'Your AI provider quota is used up. Check the plan or billing on your provider account.', 'weargraph' ) );
			}

			return new ProviderException( ProviderException::RATE_LIMITED, esc_html__( 'The AI provider is busy. Please try again in a moment.', 'weargraph' ), true );
		}

		if ( 504 === $status ) {
			return new ProviderException( ProviderException::TIMEOUT, esc_html__( 'The AI provider took too long to respond. Please try again.', 'weargraph' ), true );
		}

		if ( $status >= 500 ) {
			return new ProviderException( ProviderException::SERVER, esc_html__( 'The AI provider had a temporary problem. Please try again.', 'weargraph' ), true );
		}

		return new ProviderException( ProviderException::SERVER, esc_html__( 'Unexpected response from the AI provider.', 'weargraph' ) );
	}

	/**
	 * Throw if a 200 response carries no usable content.
	 *
	 * @param array<string,mixed> $body     Decoded body.
	 * @param bool                $need_img Whether an image part is required.
	 * @throws ProviderException When blocked or empty.
	 */
	public static function assert_content( array $body, bool $need_img ): void {
		$block = (string) ( $body['promptFeedback']['blockReason'] ?? '' );
		if ( '' !== $block ) {
			throw self::safety();
		}

		$candidate = $body['candidates'][0] ?? null;
		if ( ! is_array( $candidate ) ) {
			throw self::no_output();
		}

		if ( in_array( (string) ( $candidate['finishReason'] ?? '' ), self::SAFETY_REASONS, true ) ) {
			throw self::safety();
		}

		$has_image = false;
		$has_text  = false;
		foreach ( (array) ( $candidate['content']['parts'] ?? array() ) as $part ) {
			$has_image = $has_image || ! empty( $part['inlineData']['data'] ) || ! empty( $part['inline_data']['data'] );
			$has_text  = $has_text || ( isset( $part['text'] ) && '' !== trim( (string) $part['text'] ) );
		}

		if ( $need_img ? ! $has_image : ! $has_text ) {
			throw self::no_output();
		}
	}

	/**
	 * Safety refusal.
	 */
	private static function safety(): ProviderException {
		return new ProviderException( ProviderException::SAFETY_BLOCKED, esc_html__( 'The AI provider declined this image. Please use a different photo or product image.', 'weargraph' ) );
	}

	/**
	 * Empty result.
	 */
	private static function no_output(): ProviderException {
		return new ProviderException( ProviderException::NO_OUTPUT, esc_html__( 'The AI provider returned no image. Please try again.', 'weargraph' ), true );
	}
}

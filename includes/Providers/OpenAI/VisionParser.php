<?php
/**
 * Chat Completions answers → vision output (OpenAI and OpenRouter share the format).
 *
 * @package WearGraph
 */

namespace WearGraph\Providers\OpenAI;

defined( 'ABSPATH' ) || exit;

use WearGraph\Providers\ProviderException;
use WearGraph\Providers\ProviderOutput;

/**
 * Pure. Takes the first choice's text; decodes JSON (tolerating a code fence) when asked.
 */
final class VisionParser {

	/**
	 * Fill a vision output.
	 *
	 * @param ProviderOutput      $output Output with provider fields set.
	 * @param array<string,mixed> $body   Decoded response.
	 * @param bool                $json   Whether JSON was asked for.
	 * @param float|null          $cost   USD.
	 * @throws ProviderException When the answer is empty or unreadable.
	 */
	public static function output( ProviderOutput $output, array $body, bool $json, ?float $cost ): ProviderOutput {
		$message = $body['choices'][0]['message'] ?? array();
		$text    = is_array( $message ) ? (string) ( $message['content'] ?? '' ) : '';
		if ( is_array( $message['content'] ?? null ) ) {
			$text = '';
			foreach ( $message['content'] as $part ) {
				$text .= (string) ( $part['text'] ?? '' );
			}
		}
		if ( '' === trim( $text ) ) {
			if ( ! empty( $message['refusal'] ) || 'content_filter' === ( $body['choices'][0]['finish_reason'] ?? '' ) ) {
				$error = new ProviderException( ProviderException::SAFETY_BLOCKED, esc_html__( 'The AI provider declined to describe this image.', 'weargraph' ) );
				throw $error;
			}
			throw ProviderException::no_output( esc_html__( 'The AI provider returned an empty answer. Please try again.', 'weargraph' ) );
		}

		$usage              = (array) ( $body['usage'] ?? array() );
		$output->tokens_in  = isset( $usage['prompt_tokens'] ) ? (int) $usage['prompt_tokens'] : null;
		$output->tokens_out = isset( $usage['completion_tokens'] ) ? (int) $usage['completion_tokens'] : null;
		$output->cost_usd   = isset( $usage['cost'] ) && is_numeric( $usage['cost'] ) ? (float) $usage['cost'] : (float) ( $cost ?? 0.0 );

		if ( $json ) {
			$clean = trim( $text );
			if ( preg_match( '/^```(?:json)?\s*(.*?)\s*```$/s', $clean, $m ) ) {
				$clean = $m[1];
			}
			$decoded = json_decode( $clean, true );
			if ( ! is_array( $decoded ) ) {
				throw ProviderException::no_output( esc_html__( 'The AI provider returned an unreadable answer. Please try again.', 'weargraph' ) );
			}
			$output->data = $decoded;
		} else {
			$output->data = array( 'text' => trim( $text ) );
		}

		return $output;
	}
}

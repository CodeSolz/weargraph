<?php
/**
 * Normalised provider result (blueprint §51).
 *
 * @package WearGraph
 */

namespace WearGraph\Providers;

defined( 'ABSPATH' ) || exit;

/**
 * Every provider maps its response into this shape. Images are returned as bytes
 * in memory; the storage layer decides where they are written (private store or
 * Media Library), so providers never touch the filesystem or return remote URLs.
 */
final class ProviderOutput {

	/**
	 * Generated images: list of array( 'mime' => string, 'bytes' => string ).
	 *
	 * @var array<int,array{mime:string,bytes:string}>
	 */
	public $images = array();

	/**
	 * Generated videos: list of array( 'mime' => string, 'bytes' => string ).
	 *
	 * @var array<int,array{mime:string,bytes:string}>
	 */
	public $videos = array();

	/**
	 * Structured answer for vision requests.
	 *
	 * @var array<string,mixed>
	 */
	public $data = array();

	/**
	 * Provider id.
	 *
	 * @var string
	 */
	public $provider = '';

	/**
	 * Model actually used.
	 *
	 * @var string
	 */
	public $model = '';

	/**
	 * Provider-side request id, when one is returned.
	 *
	 * @var string
	 */
	public $provider_request_id = '';

	/**
	 * Actual cost in USD.
	 *
	 * @var float
	 */
	public $cost_usd = 0.0;

	/**
	 * Wall-clock latency.
	 *
	 * @var int
	 */
	public $latency_ms = 0;

	/**
	 * Input tokens, when reported.
	 *
	 * @var int|null
	 */
	public $tokens_in = null;

	/**
	 * Output tokens, when reported.
	 *
	 * @var int|null
	 */
	public $tokens_out = null;

	/**
	 * Extra non-sensitive metadata (finish reason, output size…).
	 *
	 * @var array<string,mixed>
	 */
	public $metadata = array();

	/**
	 * Array form without image bytes (safe to store or log).
	 *
	 * @return array<string,mixed>
	 */
	public function summary(): array {
		return array(
			'provider'            => $this->provider,
			'model'               => $this->model,
			'provider_request_id' => $this->provider_request_id,
			'images'              => count( $this->images ),
			'videos'              => count( $this->videos ),
			'cost_usd'            => $this->cost_usd,
			'latency_ms'          => $this->latency_ms,
			'tokens_in'           => $this->tokens_in,
			'tokens_out'          => $this->tokens_out,
			'metadata'            => $this->metadata,
		);
	}
}

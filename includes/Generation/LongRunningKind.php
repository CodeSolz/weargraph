<?php
/**
 * A kind whose provider works asynchronously (e.g. Pro's video).
 *
 * @package WearGraph
 */

namespace WearGraph\Generation;

defined( 'ABSPATH' ) || exit;

use WearGraph\Providers\ProviderInterface;
use WearGraph\Providers\ProviderOutput;

/**
 * GenerationJob calls start() instead of run(), then polls in separate background
 * runs (GenerationJob::POLL_HOOK) until poll() returns a result, the wait runs out or
 * the generation is cancelled. The generation stays GENERATING meanwhile.
 */
interface LongRunningKind extends KindInterface {

	/**
	 * Start the work.
	 *
	 * @param ProviderInterface $provider Provider.
	 * @param object            $input    DTO from build().
	 * @return string Provider operation id.
	 * @throws \WearGraph\Providers\ProviderException On provider failure.
	 */
	public function start( ProviderInterface $provider, object $input ): string;

	/**
	 * Check the work. Null while still running.
	 *
	 * @param ProviderInterface $provider  Provider.
	 * @param object            $input     DTO from build().
	 * @param string            $operation Id from start().
	 * @throws \WearGraph\Providers\ProviderException On provider failure.
	 */
	public function poll( ProviderInterface $provider, object $input, string $operation ): ?ProviderOutput;

	/**
	 * Seconds between polls.
	 */
	public function poll_interval(): int;

	/**
	 * Seconds to wait in total before giving up.
	 */
	public function max_wait(): int;
}

<?php
/**
 * A kind of generation (studio image, try-on, and Pro's edit/video/look).
 *
 * @package WearGraph
 */

namespace WearGraph\Generation;

defined( 'ABSPATH' ) || exit;

use WearGraph\Providers\ProviderInterface;
use WearGraph\Providers\ProviderOutput;
use WearGraph\Storage\ImageRefResolver;

/**
 * A kind turns a stored, JSON-safe "spec" into a provider input DTO and runs it. Specs
 * hold image *references* (see ImageRefResolver), never paths or bytes, so a job can be
 * rebuilt from the database at any time.
 */
interface KindInterface {

	/**
	 * Kind id stored in generations.kind.
	 */
	public function id(): string;

	/**
	 * Provider capability needed (ProviderInterface::CAP_*).
	 */
	public function capability(): string;

	/**
	 * Validate and normalise a raw spec.
	 *
	 * @param array<string,mixed> $raw Raw spec from a controller.
	 * @return array<string,mixed>
	 * @throws \InvalidArgumentException On invalid input (message is developer-facing).
	 */
	public function normalize_spec( array $raw ): array;

	/**
	 * Build the provider input DTO.
	 *
	 * @param array<string,mixed> $spec     Normalised spec.
	 * @param ImageRefResolver    $resolver Image resolver.
	 * @return object
	 * @throws \InvalidArgumentException When a referenced image is missing.
	 */
	public function build( array $spec, ImageRefResolver $resolver ): object;

	/**
	 * Spec with image references replaced by content fingerprints (idempotency).
	 *
	 * @param array<string,mixed> $spec     Normalised spec.
	 * @param ImageRefResolver    $resolver Image resolver.
	 * @return array<string,mixed>
	 */
	public function cache_settings( array $spec, ImageRefResolver $resolver ): array;

	/**
	 * Run the input on a provider.
	 *
	 * @param ProviderInterface $provider Provider (supports capability()).
	 * @param object            $input    DTO from build().
	 * @throws \WearGraph\Providers\ProviderException On provider failure.
	 */
	public function run( ProviderInterface $provider, object $input ): ProviderOutput;

	/**
	 * Asset type for outputs (AssetsRepository::TYPE_*).
	 */
	public function output_asset_type(): string;
}

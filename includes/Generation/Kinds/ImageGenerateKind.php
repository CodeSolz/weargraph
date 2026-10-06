<?php
/**
 * Studio product image generation.
 *
 * @package WearGraph
 */

namespace WearGraph\Generation\Kinds;

defined( 'ABSPATH' ) || exit;

use WearGraph\Database\Repositories\AssetsRepository;
use WearGraph\Domain\ImageGenerationInput;
use WearGraph\Domain\ImageOptions;
use WearGraph\Domain\ImageRef;
use WearGraph\Generation\KindInterface;
use WearGraph\Providers\ImageGenerationCapable;
use WearGraph\Providers\ProviderInterface;
use WearGraph\Providers\ProviderOutput;
use WearGraph\Storage\ImageRefResolver;

/**
 * Spec: prompt, negative_prompt, references (image refs), options {aspect_ratio, size}.
 */
final class ImageGenerateKind implements KindInterface {

	public const ID = 'image_generate';

	private const MAX_REFERENCES = 14;
	private const MAX_PROMPT     = 4000;

	/**
	 * Kind id.
	 */
	public function id(): string {
		return self::ID;
	}

	/**
	 * Capability.
	 */
	public function capability(): string {
		return ProviderInterface::CAP_IMAGE_GENERATE;
	}

	/**
	 * Validate the spec.
	 *
	 * @param array<string,mixed> $raw Raw spec.
	 * @return array<string,mixed>
	 * @throws \InvalidArgumentException On invalid input.
	 */
	public function normalize_spec( array $raw ): array {
		$prompt = trim( (string) ( $raw['prompt'] ?? '' ) );
		if ( '' === $prompt ) {
			throw new \InvalidArgumentException( 'Prompt is required.' );
		}

		$refs = array();
		foreach ( array_slice( (array) ( $raw['references'] ?? array() ), 0, self::MAX_REFERENCES ) as $ref ) {
			$ref = ImageRefResolver::normalize( $ref );
			if ( null === $ref ) {
				throw new \InvalidArgumentException( 'Invalid reference image.' );
			}
			$refs[] = $ref;
		}

		$options = new ImageOptions( (string) ( $raw['options']['aspect_ratio'] ?? '4:5' ), (string) ( $raw['options']['size'] ?? '1K' ) );

		// Optional, display-only labels (e.g. the studio choices behind the prompt).
		$meta = array();
		foreach ( array_slice( (array) ( $raw['meta'] ?? array() ), 0, 12, true ) as $k => $v ) {
			if ( is_scalar( $v ) ) {
				$meta[ sanitize_key( (string) $k ) ] = mb_substr( wp_strip_all_tags( (string) $v ), 0, 200 );
			}
		}

		return array(
			'meta'            => $meta,
			'prompt'          => mb_substr( $prompt, 0, self::MAX_PROMPT ),
			'negative_prompt' => mb_substr( trim( (string) ( $raw['negative_prompt'] ?? '' ) ), 0, 1000 ),
			'references'      => $refs,
			'options'         => $options->to_array(),
		);
	}

	/**
	 * Build the DTO.
	 *
	 * @param array<string,mixed> $spec     Spec.
	 * @param ImageRefResolver    $resolver Resolver.
	 * @throws \InvalidArgumentException When an image is missing.
	 */
	public function build( array $spec, ImageRefResolver $resolver ): object {
		$refs = array();
		foreach ( (array) $spec['references'] as $ref ) {
			$refs[] = $resolver->resolve( $ref, ImageRef::ROLE_REFERENCE );
		}

		return new ImageGenerationInput(
			(string) $spec['prompt'],
			$refs,
			new ImageOptions( (string) $spec['options']['aspect_ratio'], (string) $spec['options']['size'] ),
			(string) $spec['negative_prompt']
		);
	}

	/**
	 * Settings for the idempotency key.
	 *
	 * @param array<string,mixed> $spec     Spec.
	 * @param ImageRefResolver    $resolver Resolver.
	 * @return array<string,mixed>
	 */
	public function cache_settings( array $spec, ImageRefResolver $resolver ): array {
		$spec['references'] = array_map( array( $resolver, 'fingerprint' ), (array) $spec['references'] );

		return $spec;
	}

	/**
	 * Run.
	 *
	 * @param ProviderInterface $provider Provider.
	 * @param object            $input    ImageGenerationInput.
	 */
	public function run( ProviderInterface $provider, object $input ): ProviderOutput {
		if ( ! $provider instanceof ImageGenerationCapable || ! $input instanceof ImageGenerationInput ) {
			throw new \LogicException( 'Provider/input mismatch for image_generate.' );
		}

		return $provider->generate_image( $input );
	}

	/**
	 * Output asset type.
	 */
	public function output_asset_type(): string {
		return AssetsRepository::TYPE_STUDIO_OUTPUT;
	}
}

<?php
/**
 * Virtual try-on.
 *
 * @package WearGraph
 */

namespace WearGraph\Generation\Kinds;

defined( 'ABSPATH' ) || exit;

use WearGraph\Database\Repositories\AssetsRepository;
use WearGraph\Domain\ImageOptions;
use WearGraph\Domain\ImageRef;
use WearGraph\Domain\TryOnInput;
use WearGraph\Generation\KindInterface;
use WearGraph\Providers\ProviderInterface;
use WearGraph\Providers\ProviderOutput;
use WearGraph\Providers\TryOnCapable;
use WearGraph\Storage\ImageRefResolver;

/**
 * Spec: person (image ref), garments (image refs, layer order), region, category,
 * options {aspect_ratio, size}.
 */
final class TryOnKind implements KindInterface {

	public const ID = 'tryon';

	private const MAX_GARMENT_IMAGES = 4;

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
		return ProviderInterface::CAP_TRYON;
	}

	/**
	 * Validate the spec.
	 *
	 * @param array<string,mixed> $raw Raw spec.
	 * @return array<string,mixed>
	 * @throws \InvalidArgumentException On invalid input.
	 */
	public function normalize_spec( array $raw ): array {
		$person = ImageRefResolver::normalize( $raw['person'] ?? null );
		if ( null === $person ) {
			throw new \InvalidArgumentException( 'A person photo is required.' );
		}

		$garments = array();
		foreach ( array_slice( (array) ( $raw['garments'] ?? array() ), 0, self::MAX_GARMENT_IMAGES ) as $ref ) {
			$ref = ImageRefResolver::normalize( $ref );
			if ( null === $ref ) {
				throw new \InvalidArgumentException( 'Invalid garment image.' );
			}
			$garments[] = $ref;
		}
		if ( ! $garments ) {
			throw new \InvalidArgumentException( 'A garment image is required.' );
		}

		$region = (string) ( $raw['region'] ?? '' );
		if ( ! in_array( $region, TryOnInput::REGIONS, true ) ) {
			throw new \InvalidArgumentException( 'Unknown garment region.' );
		}

		$options = new ImageOptions( (string) ( $raw['options']['aspect_ratio'] ?? '3:4' ), (string) ( $raw['options']['size'] ?? '1K' ) );

		return array(
			'person'   => $person,
			'garments' => $garments,
			'region'   => $region,
			'category' => mb_substr( trim( wp_strip_all_tags( (string) ( $raw['category'] ?? '' ) ) ), 0, 80 ),
			'options'  => $options->to_array(),
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
		$garments = array();
		foreach ( (array) $spec['garments'] as $ref ) {
			$garments[] = $resolver->resolve( $ref, ImageRef::ROLE_GARMENT );
		}

		return new TryOnInput(
			$resolver->resolve( (array) $spec['person'], ImageRef::ROLE_PERSON ),
			$garments,
			(string) $spec['region'],
			(string) $spec['category'],
			new ImageOptions( (string) $spec['options']['aspect_ratio'], (string) $spec['options']['size'] )
		);
	}

	/**
	 * Settings for the idempotency key: the person photo and garments by content.
	 *
	 * @param array<string,mixed> $spec     Spec.
	 * @param ImageRefResolver    $resolver Resolver.
	 * @return array<string,mixed>
	 */
	public function cache_settings( array $spec, ImageRefResolver $resolver ): array {
		$spec['person']   = $resolver->fingerprint( (array) $spec['person'] );
		$spec['garments'] = array_map( array( $resolver, 'fingerprint' ), (array) $spec['garments'] );

		return $spec;
	}

	/**
	 * Run.
	 *
	 * @param ProviderInterface $provider Provider.
	 * @param object            $input    TryOnInput.
	 */
	public function run( ProviderInterface $provider, object $input ): ProviderOutput {
		if ( ! $provider instanceof TryOnCapable || ! $input instanceof TryOnInput ) {
			throw new \LogicException( 'Provider/input mismatch for tryon.' );
		}

		return $provider->try_on( $input );
	}

	/**
	 * Output asset type.
	 */
	public function output_asset_type(): string {
		return AssetsRepository::TYPE_TRYON_OUTPUT;
	}
}

<?php
/**
 * AI alt text for an image.
 *
 * @package WearGraph
 */

namespace WearGraph\Generation\Kinds;

defined( 'ABSPATH' ) || exit;

use WearGraph\Database\Repositories\AssetsRepository;
use WearGraph\Domain\ImageRef;
use WearGraph\Domain\VisionInput;
use WearGraph\Generation\KindInterface;
use WearGraph\Providers\ProviderInterface;
use WearGraph\Providers\ProviderOutput;
use WearGraph\Providers\VisionCapable;
use WearGraph\Storage\ImageRefResolver;

/**
 * Spec: image (image ref), product (title, for context). Output data: { alt: string }.
 * The merchant reviews and applies the suggestion; nothing is written automatically.
 */
final class AltTextKind implements KindInterface {

	public const ID = 'alt_text';

	public const MAX_LENGTH = 125;

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
		return ProviderInterface::CAP_VISION;
	}

	/**
	 * Validate the spec.
	 *
	 * @param array<string,mixed> $raw Raw spec.
	 * @return array<string,mixed>
	 * @throws \InvalidArgumentException On invalid input.
	 */
	public function normalize_spec( array $raw ): array {
		$image = ImageRefResolver::normalize( $raw['image'] ?? null );
		if ( null === $image ) {
			throw new \InvalidArgumentException( 'An image is required.' );
		}

		return array(
			'image'   => $image,
			'product' => mb_substr( trim( wp_strip_all_tags( (string) ( $raw['product'] ?? '' ) ) ), 0, 200 ),
		);
	}

	/**
	 * Build the vision request.
	 *
	 * @param array<string,mixed> $spec     Spec.
	 * @param ImageRefResolver    $resolver Resolver.
	 */
	public function build( array $spec, ImageRefResolver $resolver ): object {
		$context = '' !== $spec['product'] ? ' The product is: "' . $spec['product'] . '".' : '';

		return new VisionInput(
			array( $resolver->resolve( (array) $spec['image'], ImageRef::ROLE_SOURCE ) ),
			'Write alt text for this online-store product image, for shoppers who use screen readers.' . $context
			. ' Describe what is visible: the product, its colour, material or pattern if clear, and the setting or model if present.'
			. ' Be factual; no marketing language; do not start with "Image of" or "Picture of".'
			. ' At most ' . self::MAX_LENGTH . ' characters.'
			. ' Reply as JSON: {"alt": "..."}',
			true
		);
	}

	/**
	 * Idempotency settings.
	 *
	 * @param array<string,mixed> $spec     Spec.
	 * @param ImageRefResolver    $resolver Resolver.
	 * @return array<string,mixed>
	 */
	public function cache_settings( array $spec, ImageRefResolver $resolver ): array {
		$spec['image'] = $resolver->fingerprint( (array) $spec['image'] );

		return $spec;
	}

	/**
	 * Run and normalise to { alt }.
	 *
	 * @param ProviderInterface $provider Provider.
	 * @param object            $input    VisionInput.
	 */
	public function run( ProviderInterface $provider, object $input ): ProviderOutput {
		if ( ! $provider instanceof VisionCapable || ! $input instanceof VisionInput ) {
			throw new \LogicException( 'Provider/input mismatch for alt_text.' );
		}

		$output       = $provider->evaluate( $input );
		$alt          = self::clean( (string) ( $output->data['alt'] ?? ( $output->data['text'] ?? '' ) ) );
		$output->data = '' !== $alt ? array( 'alt' => $alt ) : array();

		return $output;
	}

	/**
	 * Tidy a model's alt text.
	 *
	 * @param string $alt Raw alt text.
	 */
	public static function clean( string $alt ): string {
		$alt = trim( wp_strip_all_tags( $alt ), " \t\n\r\0\x0B\"'" );
		$alt = (string) preg_replace( '/^(an?\s+)?(image|picture|photo)\s+of\s+/i', '', $alt );
		$alt = (string) preg_replace( '/\s+/', ' ', $alt );
		if ( mb_strlen( $alt ) > self::MAX_LENGTH ) {
			$alt = rtrim( mb_substr( $alt, 0, self::MAX_LENGTH - 1 ) ) . '…';
		}

		return '' !== $alt ? mb_strtoupper( mb_substr( $alt, 0, 1 ) ) . mb_substr( $alt, 1 ) : '';
	}

	/**
	 * No image outputs.
	 */
	public function output_asset_type(): string {
		return AssetsRepository::TYPE_REFERENCE;
	}
}

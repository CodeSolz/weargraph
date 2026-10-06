<?php
/**
 * Base contract every AI provider implements.
 *
 * @package WearGraph
 */

namespace WearGraph\Providers;

defined( 'ABSPATH' ) || exit;

/**
 * Identity, configuration state and health. What a provider can actually *do* is
 * expressed by the capability interfaces it also implements (ImageGenerationCapable,
 * TryOnCapable, …). Business logic depends only on these interfaces and on
 * ProviderOutput — never on a provider's response shape.
 */
interface ProviderInterface {

	public const CAP_IMAGE_GENERATE = 'image_generate';
	public const CAP_IMAGE_EDIT     = 'image_edit';
	public const CAP_TRYON          = 'tryon';
	public const CAP_VIDEO          = 'video';
	public const CAP_VISION         = 'vision';

	/**
	 * Stable machine id, e.g. "gemini".
	 */
	public function id(): string;

	/**
	 * Human-readable name.
	 */
	public function label(): string;

	/**
	 * Capability ids (CAP_* constants) this provider supports.
	 *
	 * @return string[]
	 */
	public function capabilities(): array;

	/**
	 * Whether credentials are present. Never returns or exposes the credential.
	 */
	public function is_configured(): bool;

	/**
	 * Cheap live check that the credential works (no generation cost).
	 */
	public function health_check(): ProviderHealth;
}

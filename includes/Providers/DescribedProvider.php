<?php
/**
 * A provider that describes itself for the Settings screen.
 *
 * @package WearGraph
 */

namespace WearGraph\Providers;

defined( 'ABSPATH' ) || exit;

/**
 * Providers implementing this appear as cards in Settings → AI Provider, with their
 * own configuration panel. Values are saved by the provider itself, so each keeps its
 * storage (ProviderOptions by default; older options where they already exist).
 */
interface DescribedProvider {

	/**
	 * Catalog entry.
	 */
	public function info(): ProviderInfo;

	/**
	 * Current values of the fields in info()->fields.
	 *
	 * @return array<string,mixed>
	 */
	public function setting_values(): array;

	/**
	 * Save posted values (only the fields declared in info()->fields, already sanitised as
	 * text). Invalid values are ignored, keeping the current ones.
	 *
	 * @param array<string,string> $values Field name => value.
	 */
	public function save_settings( array $values ): void;
}

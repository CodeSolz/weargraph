<?php
/**
 * A provider that can list its current models.
 *
 * @package WearGraph
 */

namespace WearGraph\Providers;

defined( 'ABSPATH' ) || exit;

/**
 * Powers "Refresh models" in Settings. Results are cached by ProviderOptions so a saved
 * model can be one the static catalog does not know yet.
 */
interface ModelListing {

	/**
	 * Models for a "live" field, fetched from the provider (no generation cost).
	 *
	 * @param string $field Field name from info()->fields.
	 * @return array<string,string> Model id => label.
	 * @throws ProviderException When the list cannot be fetched.
	 */
	public function list_models( string $field ): array;
}

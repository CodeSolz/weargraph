<?php
/**
 * Registered generation kinds.
 *
 * @package WearGraph
 */

namespace WearGraph\Generation;

defined( 'ABSPATH' ) || exit;

use WearGraph\Generation\Kinds\AltTextKind;
use WearGraph\Generation\Kinds\ImageGenerateKind;
use WearGraph\Generation\Kinds\TryOnKind;

/**
 * Free ships image_generate and tryon; Pro adds image_edit, ghost_mannequin, video
 * and look through the weargraph_generation_kinds filter.
 */
final class KindRegistry {

	/**
	 * Built kinds.
	 *
	 * @var array<string,KindInterface>|null
	 */
	private $kinds = null;

	/**
	 * All kinds.
	 *
	 * @return array<string,KindInterface>
	 */
	public function all(): array {
		if ( null !== $this->kinds ) {
			return $this->kinds;
		}

		/**
		 * Register generation kinds.
		 *
		 * @param array<string,string|KindInterface> $kinds id => class name or instance.
		 */
		$registered = (array) apply_filters(
			'weargraph_generation_kinds',
			array(
				ImageGenerateKind::ID => ImageGenerateKind::class,
				TryOnKind::ID         => TryOnKind::class,
				AltTextKind::ID       => AltTextKind::class,
			)
		);

		$this->kinds = array();
		foreach ( $registered as $id => $kind ) {
			if ( is_string( $kind ) && class_exists( $kind ) ) {
				$kind = new $kind();
			}
			if ( $kind instanceof KindInterface && $kind->id() === $id ) {
				$this->kinds[ $id ] = $kind;
			}
		}

		return $this->kinds;
	}

	/**
	 * One kind.
	 *
	 * @param string $id Kind id.
	 */
	public function get( string $id ): ?KindInterface {
		return $this->all()[ $id ] ?? null;
	}
}

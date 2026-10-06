<?php
/**
 * Image edit request (clean-up, recolour, background replace…).
 *
 * @package WearGraph
 */

namespace WearGraph\Domain;

defined( 'ABSPATH' ) || exit;

/**
 * Edit one source image according to an instruction, optionally within a mask.
 */
final class ImageEditInput {

	/**
	 * Image to edit.
	 *
	 * @var ImageRef
	 */
	public $source;

	/**
	 * What to change.
	 *
	 * @var string
	 */
	public $instruction;

	/**
	 * Optional mask; white marks the editable area.
	 *
	 * @var ImageRef|null
	 */
	public $mask;

	/**
	 * Extra references (e.g. a new background).
	 *
	 * @var ImageRef[]
	 */
	public $references;

	/**
	 * Output shape.
	 *
	 * @var ImageOptions
	 */
	public $options;

	/**
	 * Constructor.
	 *
	 * @param ImageRef      $source      Image to edit.
	 * @param string        $instruction What to change.
	 * @param ImageOptions  $options     Output shape.
	 * @param ImageRef|null $mask        Optional mask.
	 * @param ImageRef[]    $references  Extra references.
	 * @throws \InvalidArgumentException On an empty instruction.
	 */
	public function __construct( ImageRef $source, string $instruction, ImageOptions $options, ?ImageRef $mask = null, array $references = array() ) {
		if ( '' === trim( $instruction ) ) {
			throw new \InvalidArgumentException( 'Edit instruction is empty.' );
		}

		$this->source      = $source;
		$this->instruction = trim( $instruction );
		$this->options     = $options;
		$this->mask        = $mask;
		$this->references  = array_values( $references );
	}
}

<?php
/**
 * Instruction wording shared by every image provider.
 *
 * @package WearGraph
 */

namespace WearGraph\Providers;

defined( 'ABSPATH' ) || exit;

use WearGraph\Domain\ImageEditInput;
use WearGraph\Domain\ImageGenerationInput;
use WearGraph\Domain\TryOnInput;

/**
 * Pure. All wording is WearGraph's own (clean-room rule, README rule 18). Images are
 * referred to by their order, which every provider keeps.
 */
final class Prompts {

	/**
	 * Studio prompt with the negative prompt folded in (providers without a native field).
	 *
	 * @param ImageGenerationInput $input Request.
	 */
	public static function generation( ImageGenerationInput $input ): string {
		$text = $input->prompt;
		if ( '' !== $input->negative_prompt ) {
			$text .= "\n\nDo not include: " . $input->negative_prompt . '.';
		}

		return $text;
	}

	/**
	 * Edit instruction for the source (first) image, mask (second, when present) and references.
	 *
	 * @param ImageEditInput $input Request.
	 */
	public static function edit( ImageEditInput $input ): string {
		$text = "Edit the first image. Instruction: {$input->instruction}\n"
			. 'Change only what the instruction asks for. Keep the product\'s shape, colour, texture, logos and text exactly as they are unless the instruction says otherwise.';

		if ( $input->mask ) {
			$text .= "\nThe second image is a mask: only the white area may change; keep everything in the black area untouched.";
		}
		if ( $input->references ) {
			$text .= "\nThe remaining images are references to use for the edit.";
		}

		return $text;
	}

	/**
	 * Try-on instruction: person first, then the garment images.
	 *
	 * @param TryOnInput $input         Request.
	 * @param int        $garment_count Garment images actually sent.
	 */
	public static function tryon( TryOnInput $input, int $garment_count ): string {
		$regions = self::regions();
		$item    = '' !== $input->category ? $input->category : $regions[ $input->region ];

		return implode(
			"\n",
			array(
				'Create a realistic photo of the person in the first image wearing the product shown in the '
					. ( $garment_count > 1 ? 'following ' . $garment_count . ' images (different views of the same item)' : 'second image' ) . '.',
				'The product is a ' . $item . '. Dress the person in it as their ' . $regions[ $input->region ] . '.',
				'Keep the person exactly the same: face, identity, skin tone, hair, body shape, pose, and the background and lighting of the first image.',
				'Keep the product exactly the same: colour, pattern, fabric texture, logos, text, buttons and details. Make it fit and drape naturally on the body.',
				'Only change the clothing that the product replaces. Do not add other items. Do not show the product on its own or add any text or watermark.',
			)
		);
	}

	/**
	 * Short try-on prompt for dedicated try-on models (person = image 1, garment = image 2).
	 *
	 * @param TryOnInput $input Request.
	 */
	public static function tryon_short( TryOnInput $input ): string {
		$item = '' !== $input->category ? $input->category : self::regions()[ $input->region ];

		return 'The person of image 1, keeping exactly their face, body and pose, wearing the ' . $item . ' of image 2.';
	}

	/**
	 * Region words.
	 *
	 * @return array<string,string>
	 */
	private static function regions(): array {
		return array(
			'upper'     => 'top',
			'lower'     => 'bottoms',
			'one_piece' => 'one-piece outfit (dress or jumpsuit), replacing both top and bottoms',
			'outer'     => 'outer layer (jacket or coat), worn over their current top',
			'footwear'  => 'shoes',
			'accessory' => 'accessory',
		);
	}
}

<?php
/**
 * Built-in studio presets: scenes, backgrounds, styles, model types.
 *
 * @package WearGraph
 */

namespace WearGraph\Services;

defined( 'ABSPATH' ) || exit;

/**
 * Written from scratch for WearGraph (clean-room rule). Each preset has a translated
 * label for the UI and an English prompt fragment for the model. Pro adds custom
 * presets and Brand Kit looks through weargraph_studio_presets.
 */
final class PresetLibrary {

	/**
	 * All presets by group: scene, background, style, model.
	 *
	 * @return array<string,array<string,array{label:string,prompt:string}>>
	 */
	public static function all(): array {
		$presets = array(
			'scene'      => array(
				'none'        => array(
					'label'  => __( 'No scene (studio)', 'weargraph' ),
					'prompt' => '',
				),
				'city_street' => array(
					'label'  => __( 'City street', 'weargraph' ),
					'prompt' => 'on a quiet city street with soft daylight and softly blurred buildings behind',
				),
				'cafe'        => array(
					'label'  => __( 'Café', 'weargraph' ),
					'prompt' => 'in a bright café with warm wood tones, softly out of focus',
				),
				'beach'       => array(
					'label'  => __( 'Beach', 'weargraph' ),
					'prompt' => 'on a sandy beach in late-afternoon sun with the sea softly blurred behind',
				),
				'park'        => array(
					'label'  => __( 'Park', 'weargraph' ),
					'prompt' => 'in a green park with dappled sunlight through trees',
				),
				'living_room' => array(
					'label'  => __( 'Living room', 'weargraph' ),
					'prompt' => 'in a calm, modern living room with natural window light',
				),
				'office'      => array(
					'label'  => __( 'Office', 'weargraph' ),
					'prompt' => 'in a bright, minimal office with plants and daylight',
				),
				'gym'         => array(
					'label'  => __( 'Gym', 'weargraph' ),
					'prompt' => 'in a clean, modern gym with even lighting',
				),
				'mountain'    => array(
					'label'  => __( 'Outdoors / mountains', 'weargraph' ),
					'prompt' => 'outdoors on a mountain trail with a wide, hazy landscape behind',
				),
				'rooftop'     => array(
					'label'  => __( 'Rooftop at golden hour', 'weargraph' ),
					'prompt' => 'on a city rooftop at golden hour with warm, low sunlight',
				),
				'snow'        => array(
					'label'  => __( 'Winter snow', 'weargraph' ),
					'prompt' => 'in a snowy winter setting with soft, cool light',
				),
				'boutique'    => array(
					'label'  => __( 'Boutique interior', 'weargraph' ),
					'prompt' => 'inside an elegant boutique with soft, warm spotlights',
				),
			),
			'background' => array(
				'white'      => array(
					'label'  => __( 'Pure white', 'weargraph' ),
					'prompt' => 'on a seamless pure white background',
				),
				'light_grey' => array(
					'label'  => __( 'Light grey', 'weargraph' ),
					'prompt' => 'on a seamless light grey background',
				),
				'beige'      => array(
					'label'  => __( 'Warm beige', 'weargraph' ),
					'prompt' => 'on a seamless warm beige background',
				),
				'black'      => array(
					'label'  => __( 'Black', 'weargraph' ),
					'prompt' => 'on a seamless deep black background',
				),
				'pastel'     => array(
					'label'  => __( 'Soft pastel', 'weargraph' ),
					'prompt' => 'on a soft pastel-coloured background',
				),
				'gradient'   => array(
					'label'  => __( 'Subtle gradient', 'weargraph' ),
					'prompt' => 'on a subtle, smooth colour gradient background',
				),
				'concrete'   => array(
					'label'  => __( 'Concrete', 'weargraph' ),
					'prompt' => 'against a smooth concrete wall',
				),
				'wood'       => array(
					'label'  => __( 'Wood', 'weargraph' ),
					'prompt' => 'on a natural light wood surface',
				),
				'marble'     => array(
					'label'  => __( 'Marble', 'weargraph' ),
					'prompt' => 'on a white marble surface',
				),
				'linen'      => array(
					'label'  => __( 'Linen fabric', 'weargraph' ),
					'prompt' => 'on a neutral linen fabric backdrop',
				),
			),
			'style'      => array(
				'catalog'   => array(
					'label'  => __( 'Clean catalog', 'weargraph' ),
					'prompt' => 'clean e-commerce catalog style, even soft lighting, sharp focus, true-to-life colour',
				),
				'editorial' => array(
					'label'  => __( 'Fashion editorial', 'weargraph' ),
					'prompt' => 'fashion editorial style, confident pose, directional light, magazine quality',
				),
				'lifestyle' => array(
					'label'  => __( 'Lifestyle', 'weargraph' ),
					'prompt' => 'natural lifestyle photography, candid feel, soft natural light',
				),
				'luxury'    => array(
					'label'  => __( 'Luxury', 'weargraph' ),
					'prompt' => 'premium luxury look, refined lighting, rich but accurate colour',
				),
				'minimal'   => array(
					'label'  => __( 'Minimal', 'weargraph' ),
					'prompt' => 'minimal composition, lots of negative space, calm and uncluttered',
				),
				'street'    => array(
					'label'  => __( 'Streetwear', 'weargraph' ),
					'prompt' => 'streetwear photography, urban energy, crisp contrast',
				),
				'flat_lay'  => array(
					'label'  => __( 'Flat lay', 'weargraph' ),
					'prompt' => 'top-down flat lay, neatly arranged, soft shadowless light',
				),
				'sporty'    => array(
					'label'  => __( 'Sporty / active', 'weargraph' ),
					'prompt' => 'active sportswear photography, dynamic but clear, bright light',
				),
			),
			'model'      => array(
				'any'   => array(
					'label'  => __( 'Any model', 'weargraph' ),
					'prompt' => 'an adult model',
				),
				'woman' => array(
					'label'  => __( 'Woman', 'weargraph' ),
					'prompt' => 'an adult woman model',
				),
				'man'   => array(
					'label'  => __( 'Man', 'weargraph' ),
					'prompt' => 'an adult man model',
				),
			),
		);

		/**
		 * Studio presets. Groups: scene, background, style, model. Each preset:
		 * array( 'label' => string (translated), 'prompt' => string (English fragment) ).
		 *
		 * @param array<string,array<string,array{label:string,prompt:string}>> $presets Presets.
		 */
		return (array) apply_filters( 'weargraph_studio_presets', $presets );
	}

	/**
	 * One group as id => label (for selects).
	 *
	 * @param string $group Group.
	 * @return array<string,string>
	 */
	public static function options( string $group ): array {
		$out = array();
		foreach ( (array) ( self::all()[ $group ] ?? array() ) as $id => $preset ) {
			$out[ (string) $id ] = (string) ( $preset['label'] ?? $id );
		}

		return $out;
	}

	/**
	 * Prompt fragment for a preset; '' when unknown.
	 *
	 * @param string $group Group.
	 * @param string $id    Preset id.
	 */
	public static function prompt( string $group, string $id ): string {
		return (string) ( self::all()[ $group ][ $id ]['prompt'] ?? '' );
	}
}

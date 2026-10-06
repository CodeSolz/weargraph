<?php
/**
 * Translated strings for the storefront fitting room.
 *
 * @package WearGraph
 */

namespace WearGraph\Storefront;

defined( 'ABSPATH' ) || exit;

/**
 * Static strings only (no user data), so they can be cached with the page.
 */
final class Strings {

	/**
	 * All strings used by button.js and fitting-room.js.
	 *
	 * @return array<string,string>
	 */
	public static function all(): array {
		return array(
			'title'         => __( 'Virtual fitting room', 'weargraph' ),
			'close'         => __( 'Close', 'weargraph' ),
			'loading'       => __( 'Loading…', 'weargraph' ),
			'unavailable'   => __( 'Try-on is not available for this product right now.', 'weargraph' ),
			'stepPhoto'     => __( 'Add a photo of yourself', 'weargraph' ),
			'photoHelp'     => __( 'Stand facing the camera, arms relaxed, in good light. Head to hips (or head to toe) in the frame works best.', 'weargraph' ),
			'upload'        => __( 'Upload a photo', 'weargraph' ),
			'camera'        => __( 'Take a photo', 'weargraph' ),
			'capture'       => __( 'Capture', 'weargraph' ),
			'cancel'        => __( 'Cancel', 'weargraph' ),
			'noCamera'      => __( 'The camera is not available. Please upload a photo instead.', 'weargraph' ),
			'usePhoto'      => __( 'Use this photo', 'weargraph' ),
			'choose'        => __( 'Choose another', 'weargraph' ),
			'tooSmall'      => __( 'This photo is too small. Please use a sharper photo.', 'weargraph' ),
			'tooDark'       => __( 'This photo looks very dark. A brighter photo gives a better result.', 'weargraph' ),
			'tooBright'     => __( 'This photo looks overexposed. Try a photo with softer light.', 'weargraph' ),
			'landscape'     => __( 'A portrait (upright) photo works best.', 'weargraph' ),
			'notImage'      => __( 'Please choose a JPG or PNG photo.', 'weargraph' ),
			'privacyLink'   => __( 'Privacy policy', 'weargraph' ),
			'retentionSess' => __( 'Your photo is deleted automatically after this visit.', 'weargraph' ),
			'retentionDay'  => __( 'Your photo is deleted automatically within 24 hours.', 'weargraph' ),
			'uploading'     => __( 'Uploading your photo…', 'weargraph' ),
			'chooseOption'  => __( 'Choose an option', 'weargraph' ),
			'cannotTry'     => __( 'not available for try-on', 'weargraph' ),
			'tryOn'         => __( 'Try it on', 'weargraph' ),
			'generating'    => __( 'Creating your preview… this usually takes 10–30 seconds.', 'weargraph' ),
			'stillWorking'  => __( 'Still working, almost there…', 'weargraph' ),
			'caption'       => __( 'AI appearance preview. It shows how the item may look, not how it will fit. Please check the size guide.', 'weargraph' ),
			'addToCart'     => __( 'Add to cart', 'weargraph' ),
			'adding'        => __( 'Adding…', 'weargraph' ),
			'added'         => __( 'Added to your cart.', 'weargraph' ),
			'viewCart'      => __( 'View cart', 'weargraph' ),
			'chooseOnPage'  => __( 'Please choose your size on the product page.', 'weargraph' ),
			'regenerate'    => __( 'Try again', 'weargraph' ),
			'changePhoto'   => __( 'Use a different photo', 'weargraph' ),
			'compare'       => __( 'Compare with my photo', 'weargraph' ),
			'showResult'    => __( 'Show the preview', 'weargraph' ),
			'deletePhotos'  => __( 'Delete my photos', 'weargraph' ),
			'deleted'       => __( 'Your photos were deleted.', 'weargraph' ),
			'confirmDelete' => __( 'Delete your photo and all previews now?', 'weargraph' ),
			'colour'        => __( 'Option', 'weargraph' ),
			'failed'        => __( 'Sorry, the preview could not be created.', 'weargraph' ),
			'network'       => __( 'Connection problem. Please try again.', 'weargraph' ),
			/* translators: %d: number of previews left */
			'triesLeft'     => __( 'Previews left: %d', 'weargraph' ),
			'yourPhoto'     => __( 'Your photo', 'weargraph' ),
			'preview'       => __( 'Preview', 'weargraph' ),
		);
	}
}

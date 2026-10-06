<?php
/**
 * Storefront rules: eligibility, product data for the fitting room, try-on requests.
 *
 * @package WearGraph
 */

namespace WearGraph\Storefront;

defined( 'ABSPATH' ) || exit;

use WearGraph\Database\Repositories\AssetsRepository;
use WearGraph\Database\Repositories\CatalogRepository;
use WearGraph\Database\Repositories\SessionsRepository;
use WearGraph\Generation\GenerationException;
use WearGraph\Generation\Kinds\TryOnKind;
use WearGraph\Providers\ProviderRegistry;
use WearGraph\Services\CatalogService;
use WearGraph\Services\GenerationService;
use WearGraph\Catalog\CatalogMessages;
use WearGraph\Privacy\Retention;
use WearGraph\Storage\PrivateStore;
use WearGraph\Storage\SignedUrl;
use WearGraph\Support\RateLimiter;
use WearGraph\Support\Capabilities;
use WearGraph\Support\Settings;

/**
 * Everything a shopper can trigger goes through here, so the store switch, the
 * per-product readiness, consent, quotas and the cost guard are always applied.
 */
final class StorefrontService {

	public const CONSENT_VERSION = '1';

	/**
	 * Catalog rows.
	 *
	 * @var CatalogRepository
	 */
	private $catalog;

	/**
	 * Providers.
	 *
	 * @var ProviderRegistry
	 */
	private $providers;

	/**
	 * Generations.
	 *
	 * @var GenerationService
	 */
	private $generation;

	/**
	 * Sessions.
	 *
	 * @var SessionsRepository
	 */
	private $sessions;

	/**
	 * Assets.
	 *
	 * @var AssetsRepository
	 */
	private $assets;

	/**
	 * Constructor.
	 *
	 * @param CatalogRepository  $catalog    Catalog rows.
	 * @param ProviderRegistry   $providers  Providers.
	 * @param GenerationService  $generation Generations.
	 * @param SessionsRepository $sessions   Sessions.
	 * @param AssetsRepository   $assets     Assets.
	 */
	public function __construct( CatalogRepository $catalog, ProviderRegistry $providers, GenerationService $generation, SessionsRepository $sessions, AssetsRepository $assets ) {
		$this->catalog    = $catalog;
		$this->providers  = $providers;
		$this->generation = $generation;
		$this->sessions   = $sessions;
		$this->assets     = $assets;
	}

	/**
	 * Preview mode is on and the visitor is a store manager. Storefront REST requests
	 * carry no WP nonce (WordPress treats them as logged out), so the logged-in cookie
	 * is read directly, as SessionManager::start() does.
	 */
	public static function is_preview_viewer(): bool {
		if ( ! Settings::get( 'preview_mode' ) ) {
			return false;
		}
		$user_id = (int) wp_validate_auth_cookie( '', 'logged_in' );

		return $user_id > 0 && user_can( $user_id, Capabilities::MANAGE );
	}

	/**
	 * Whether the Try On button should show for a product (product level).
	 *
	 * @param int $product_id Product.
	 */
	public function product_enabled( int $product_id ): bool {
		if ( ! Settings::get( 'tryon_enabled' ) && ! self::is_preview_viewer() ) {
			return false;
		}
		$provider = $this->providers->configured();
		if ( ! $provider || ! $provider->is_configured() ) {
			return false;
		}

		/**
		 * Final say on whether a product shows the Try On button.
		 *
		 * @param bool $enabled    From the catalog readiness and overrides.
		 * @param int  $product_id Product.
		 */
		return (bool) apply_filters( 'weargraph_product_tryon_enabled', CatalogService::is_enabled( $this->catalog->get( $product_id ) ), $product_id );
	}

	/**
	 * Garment source image and metadata for a product / variation, or null.
	 *
	 * @param int $product_id   Product.
	 * @param int $variation_id Variation (0 = product).
	 * @return array{attachment_id:int,region:string,category:string}|null
	 */
	public function garment( int $product_id, int $variation_id = 0 ): ?array {
		$row = $this->catalog->get( $product_id );
		if ( ! $row || ! CatalogService::is_enabled( $row ) ) {
			return null;
		}

		$image = (int) $row['best_image_id'];
		if ( $variation_id ) {
			$variant = $this->catalog->get( $product_id, $variation_id );
			if ( $variant && (int) $variant['best_image_id'] ) {
				$image = (int) $variant['best_image_id'];
			}
		}
		if ( ! $image || ! in_array( get_post_mime_type( $image ), array( 'image/jpeg', 'image/png', 'image/webp' ), true ) ) {
			return null;
		}

		$labels = CatalogMessages::categories();

		return array(
			'attachment_id' => $image,
			'region'        => (string) $row['garment_region'],
			'category'      => strtolower( (string) ( $labels[ (string) $row['category'] ] ?? '' ) ),
		);
	}

	/**
	 * Product data for the fitting room.
	 *
	 * @param int $product_id Product.
	 * @return array<string,mixed>|null
	 */
	public function product_payload( int $product_id ): ?array {
		$product = wc_get_product( $product_id );
		if ( ! $product || ! $this->product_enabled( $product_id ) ) {
			return null;
		}

		$variations = array();
		if ( $product->is_type( 'variable' ) ) {
			foreach ( $product->get_children() as $variation_id ) {
				$variation = wc_get_product( $variation_id );
				if ( ! $variation || ! $variation->variation_is_visible() ) {
					continue;
				}
				$attributes = array();
				$any        = false;
				foreach ( $variation->get_variation_attributes( false ) as $taxonomy => $value ) {
					$label = wc_attribute_label( $taxonomy, $product );
					if ( '' === $value ) {
						$any = true;
						continue;
					}
					$term                 = taxonomy_exists( $taxonomy ) ? get_term_by( 'slug', $value, $taxonomy ) : null;
					$attributes[ $label ] = $term ? $term->name : $value;
				}
				$variations[] = array(
					'id'         => (int) $variation_id,
					'label'      => implode( ' / ', $attributes ),
					'in_stock'   => $variation->is_in_stock(),
					'price'      => html_entity_decode( wp_strip_all_tags( wc_price( (float) $variation->get_price() ) ), ENT_QUOTES, 'UTF-8' ),
					'thumbnail'  => (string) wp_get_attachment_image_url( (int) ( $variation->get_image_id() ? $variation->get_image_id() : $product->get_image_id() ), 'thumbnail' ),
					'can_try'    => null !== $this->garment( $product_id, (int) $variation_id ),
					'needs_form' => $any, // "Any size"-style attributes must be chosen on the product page.
				);
			}
		}

		return array(
			'id'         => $product_id,
			'name'       => wp_strip_all_tags( $product->get_name() ),
			'url'        => (string) get_permalink( $product_id ),
			'type'       => $product->get_type(),
			'in_stock'   => $product->is_in_stock(),
			'price'      => html_entity_decode( wp_strip_all_tags( wc_price( (float) $product->get_price() ) ), ENT_QUOTES, 'UTF-8' ),
			'thumbnail'  => (string) wp_get_attachment_image_url( (int) $product->get_image_id(), 'thumbnail' ),
			'variations' => $variations,
		);
	}

	/**
	 * Session state for the fitting room.
	 *
	 * @param array<string,mixed> $session Session.
	 * @return array<string,mixed>
	 */
	public function session_payload( array $session ): array {
		$photo = $session['photo_asset_id'] ? $this->assets->find( (int) $session['photo_asset_id'] ) : null;

		return array(
			'csrf'       => SessionManager::csrf( $session ),
			'has_photo'  => (bool) $photo,
			'photo_url'  => $photo ? SignedUrl::for_asset( (string) $photo['public_id'], (string) $session['public_id'] ) : '',
			'consent'    => ! empty( $session['consent_at'] ),
			'tryons'     => max( 0, (int) Settings::get( 'session_tryon_quota', 10 ) - (int) $session['tryon_count'] ),
			'regens'     => max( 0, (int) Settings::get( 'session_regen_quota', 3 ) - (int) $session['regen_count'] ),
			'consent_md' => self::consent_text(),
			'privacy'    => (string) get_privacy_policy_url(),
			'retention'  => Retention::keeps_beyond_session() ? 'day' : 'session',
			'keep_note'  => Retention::notice(),
		);
	}

	/**
	 * Merchant consent text (plain text), with a default.
	 */
	public static function consent_text(): string {
		$text = trim( (string) Settings::get( 'consent_text', '' ) );

		if ( '' !== $text ) {
			return $text;
		}

		/**
		 * Default consent text, used when the merchant has not written their own. Pro
		 * changes it when photos are kept longer (it must stay true for the active mode).
		 *
		 * @param string $text Default text.
		 */
		return (string) apply_filters( 'weargraph_default_consent_text', __( 'I agree that my photo is sent to our AI provider to create this preview. My photo is deleted automatically and is not used for anything else.', 'weargraph' ) );
	}

	/**
	 * Store a sanitized photo for the session (replacing any previous one).
	 *
	 * @param array<string,mixed>                               $session Session.
	 * @param array{bytes:string,mime:string,width:int,height:int} $image   Sanitized image.
	 * @param bool                                              $consent Consent checkbox sent with the upload.
	 * @return array<string,mixed> Fresh session payload.
	 * @throws GenerationException On missing consent or storage failure.
	 */
	public function save_photo( array $session, array $image, bool $consent ): array {
		if ( empty( $session['consent_at'] ) && ! $consent ) {
			throw new GenerationException( 'consent_required', esc_html__( 'Please agree to the photo terms first.', 'weargraph' ), 400 );
		}

		$file = PrivateStore::write( $image['bytes'], $image['mime'] );
		if ( ! $file ) {
			throw new GenerationException( 'storage', esc_html__( 'Your photo could not be saved. Please try again.', 'weargraph' ), 500 );
		}

		$expires = Retention::expires_at( (int) strtotime( (string) $session['expires_at'] . ' UTC' ) );

		$asset = $this->assets->create(
			array(
				'type'       => AssetsRepository::TYPE_SHOPPER_NORMALIZED,
				'path'       => $file['path'],
				'session_id' => (string) $session['public_id'],
				'sha256'     => $file['sha256'],
				'width'      => $image['width'],
				'height'     => $image['height'],
				'mime'       => $image['mime'],
				'bytes'      => $file['bytes'],
				'expires_at' => gmdate( 'Y-m-d H:i:s', $expires ),
			)
		);

		if ( $session['photo_asset_id'] ) {
			$this->delete_asset( (int) $session['photo_asset_id'] );
		}

		$update = array( 'photo_asset_id' => (int) $asset['id'] );
		if ( empty( $session['consent_at'] ) ) {
			$update['consent_at']      = current_time( 'mysql', true );
			$update['consent_version'] = self::CONSENT_VERSION;
		}
		$this->sessions->update( (int) $session['id'], $update );

		/**
		 * A shopper uploaded a fitting-room photo (no image data passed).
		 *
		 * @param string $session_id Session public id.
		 */
		do_action( 'weargraph_storefront_photo_saved', (string) $session['public_id'] );

		return $this->session_payload( $this->sessions->find( (int) $session['id'] ) );
	}

	/**
	 * Delete every photo and result of a session ("Delete my photos").
	 *
	 * @param array<string,mixed> $session Session.
	 * @return int Files removed.
	 */
	public function delete_session_media( array $session ): int {
		global $wpdb;

		$table = \WearGraph\Database\Schema::table( 'assets' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table.
		$ids = array_map( 'intval', $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM %i WHERE session_id = %s AND deleted_at IS NULL', $table, (string) $session['public_id'] ) ) );

		// The photo shown may come from an earlier visit (Pro keeps a customer's photo in their account).
		if ( ! empty( $session['photo_asset_id'] ) && ! in_array( (int) $session['photo_asset_id'], $ids, true ) ) {
			$ids[] = (int) $session['photo_asset_id'];
		}

		foreach ( $ids as $id ) {
			$this->delete_asset( (int) $id );
		}
		$this->sessions->update( (int) $session['id'], array( 'photo_asset_id' => null ) );

		return count( $ids );
	}

	/**
	 * Request a try-on for the session's photo.
	 *
	 * @param array<string,mixed> $session      Session.
	 * @param int                 $product_id   Product.
	 * @param int                 $variation_id Variation (0 = product).
	 * @param bool                $regenerate   New attempt for the same garment.
	 * @param string              $person_asset Optional: an earlier try-on result of this
	 *                                          session (asset public id) to dress instead of
	 *                                          the shopper's photo, for layered looks (Pro).
	 *                                          Such a continuation skips the CAPTCHA and the
	 *                                          connection limit (checked on the first layer);
	 *                                          the visit's try-on quota still counts it.
	 * @return array{id:string,status:string,cached:bool}
	 * @throws GenerationException When refused.
	 */
	public function request_tryon( array $session, int $product_id, int $variation_id, bool $regenerate, string $person_asset = '' ): array {
		if ( ! $this->product_enabled( $product_id ) ) {
			throw new GenerationException( 'unavailable', esc_html__( 'Try-on is not available for this product.', 'weargraph' ), 403 );
		}
		if ( $variation_id && (int) wp_get_post_parent_id( $variation_id ) !== $product_id ) {
			throw new GenerationException( 'bad_input', esc_html__( 'Please choose an option of this product.', 'weargraph' ), 400 );
		}
		$garment = $this->garment( $product_id, $variation_id );
		if ( ! $garment ) {
			throw new GenerationException( 'unavailable', esc_html__( 'This option cannot be tried on yet.', 'weargraph' ), 403 );
		}

		$photo = $session['photo_asset_id'] ? $this->assets->find( (int) $session['photo_asset_id'] ) : null;
		if ( ! $photo || empty( $session['consent_at'] ) ) {
			throw new GenerationException( 'photo_required', esc_html__( 'Please add your photo first.', 'weargraph' ), 400 );
		}
		if ( '' !== $person_asset ) {
			// Only this visit's own try-on results can stand in for the photo.
			$layer = $this->assets->find_public( $person_asset );
			if ( ! $layer || AssetsRepository::TYPE_TRYON_OUTPUT !== $layer['type'] || (string) $layer['session_id'] !== (string) $session['public_id'] || ! empty( $layer['deleted_at'] ) ) {
				throw new GenerationException( 'bad_input', esc_html__( 'That earlier result is no longer available. Please start the look again.', 'weargraph' ), 400 );
			}
			$photo = $layer;
		}

		/**
		 * Require a human check (CAPTCHA) before this try-on. Return true to refuse with
		 * code "challenge_required"; a CAPTCHA add-on then verifies and lets it through.
		 *
		 * @param bool                $required Default false.
		 * @param array<string,mixed> $session  Session.
		 */
		if ( '' === $person_asset && apply_filters( 'weargraph_require_challenge', false, $session ) ) {
			throw new GenerationException( 'challenge_required', esc_html__( 'Please confirm you are not a robot.', 'weargraph' ), 403 );
		}

		$counter = $regenerate ? 'regen_count' : 'tryon_count';
		$limit   = $regenerate ? (int) Settings::get( 'session_regen_quota', 3 ) : (int) Settings::get( 'session_tryon_quota', 10 );
		if ( ! $this->sessions->increment_below( (int) $session['id'], $counter, $limit ) ) {
			throw new GenerationException( 'quota', $regenerate ? esc_html__( 'You have used all regenerations for now.', 'weargraph' ) : esc_html__( 'You have reached the try-on limit for now. Please come back later.', 'weargraph' ), 429 );
		}
		// A later layer of a look runs in the background (no shopper connection): the look's
		// first try-on was already checked against the connection's limit.
		if ( '' === $person_asset && ! RateLimiter::hit( 'tryon-ip', RateLimiter::ip_hash(), (int) Settings::get( 'ip_daily_limit', 30 ), DAY_IN_SECONDS ) ) {
			$this->sessions->decrement( (int) $session['id'], $counter );
			throw new GenerationException( 'quota', esc_html__( 'Too many try-ons from this connection today. Please come back tomorrow.', 'weargraph' ), 429 );
		}

		try {
			$result = $this->generation->create(
				TryOnKind::ID,
				array(
					'person'   => array(
						'type' => 'asset',
						'id'   => (string) $photo['public_id'],
					),
					'garments' => array(
						array(
							'type' => 'attachment',
							'id'   => $garment['attachment_id'],
						),
					),
					'region'   => $garment['region'],
					'category' => $garment['category'],
					'options'  => array(
						'aspect_ratio' => '3:4',
						'size'         => '1K',
					),
				),
				array(
					'context'      => 'storefront',
					'product_id'   => $product_id,
					'variant_id'   => $variation_id,
					'session_id'   => (string) $session['public_id'],
					'bypass_cache' => $regenerate,
				)
			);
		} catch ( GenerationException $e ) {
			$this->sessions->decrement( (int) $session['id'], $counter );
			throw $e;
		}

		if ( $result['cached'] ) {
			$this->sessions->decrement( (int) $session['id'], $counter ); // Reusing a result costs nothing.
		}

		/**
		 * A shopper requested a try-on.
		 *
		 * @param array{id:string,status:string,cached:bool} $result       Result.
		 * @param int                                        $product_id   Product.
		 * @param int                                        $variation_id Variation.
		 * @param bool                                       $regenerate   Whether this was a regeneration.
		 * @param string                                     $session_id   Session public id.
		 */
		do_action( 'weargraph_storefront_tryon_requested', $result, $product_id, $variation_id, $regenerate, (string) $session['public_id'] );

		return $result;
	}

	/**
	 * Delete one asset file and mark it deleted.
	 *
	 * @param int $asset_id Asset id.
	 */
	private function delete_asset( int $asset_id ): void {
		$asset = $this->assets->find( $asset_id );
		if ( $asset ) {
			PrivateStore::delete( (string) $asset['path'] );
			$this->assets->mark_deleted( $asset_id );
		}
	}
}

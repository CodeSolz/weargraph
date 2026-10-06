<?php
/**
 * WooCommerce My account → "My fitting room": the customer's try-on images.
 *
 * @package WearGraph
 */

namespace WearGraph\Storefront;

defined( 'ABSPATH' ) || exit;

use WearGraph\Admin\AdminMenu;
use WearGraph\Database\Repositories\AssetsRepository;
use WearGraph\Database\Repositories\SessionsRepository;
use WearGraph\Database\Schema;
use WearGraph\Domain\GenerationState;
use WearGraph\Storage\PrivateStore;
use WearGraph\Storage\SignedUrl;
use WearGraph\Support\Settings;

/**
 * A menu item in the customer's account and a page with every try-on image made in their
 * fitting-room visits while logged in, newest first, each with its own Delete button, plus
 * one button that deletes all their fitting-room photos and images. Images are served by
 * the signed media route; this class lets a logged-in customer open the media of their own
 * visits even without a fitting-room session right now.
 *
 * Extensions add sections with the weargraph_account_fitting_room_sections action and
 * clear their own data on weargraph_account_fitting_room_deleted.
 */
final class AccountPage {

	public const ENDPOINT = 'fitting-room';

	public const GALLERY_LIMIT = 60;

	private const ACTION_ALL = 'weargraph_fitting_room_delete_all';
	private const ACTION_ONE = 'weargraph_fitting_room_delete_one';

	/**
	 * Bump when the endpoint changes, so rewrite rules are flushed once.
	 */
	private const REWRITE_VERSION = '1';

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
	 * Storefront (session media deletion).
	 *
	 * @var StorefrontService
	 */
	private $storefront;

	/**
	 * Constructor.
	 *
	 * @param SessionsRepository $sessions   Sessions.
	 * @param AssetsRepository   $assets     Assets.
	 * @param StorefrontService  $storefront Storefront.
	 */
	public function __construct( SessionsRepository $sessions, AssetsRepository $assets, StorefrontService $storefront ) {
		$this->sessions   = $sessions;
		$this->assets     = $assets;
		$this->storefront = $storefront;
	}

	/**
	 * Register hooks.
	 */
	public function boot(): void {
		add_filter( 'woocommerce_get_query_vars', array( $this, 'query_vars' ) );
		add_filter( 'woocommerce_account_menu_items', array( $this, 'menu' ), 20 );
		add_action( 'woocommerce_account_' . self::ENDPOINT . '_endpoint', array( $this, 'render' ) );
		add_filter( 'woocommerce_endpoint_' . self::ENDPOINT . '_title', array( $this, 'title' ) );
		add_filter( 'weargraph_media_session_ok', array( $this, 'own_media' ), 20, 2 );
		add_action( 'template_redirect', array( $this, 'handle_delete' ) );
		add_action( 'init', array( $this, 'maybe_flush' ), 99 );
		add_action( 'wp_enqueue_scripts', array( $this, 'styles' ) );
	}

	/**
	 * Whether the menu item shows: the fitting room is on (or previewed by a store manager),
	 * or the customer already has images to see or delete.
	 */
	public function enabled(): bool {
		$user_id = get_current_user_id();
		$on      = (bool) Settings::get( 'tryon_enabled', false ) || StorefrontService::is_preview_viewer();

		/**
		 * Whether My account shows "My fitting room".
		 *
		 * @param bool $enabled Default decision.
		 * @param int  $user_id Current user.
		 */
		return (bool) apply_filters( 'weargraph_account_fitting_room_enabled', $on || ( $user_id && $this->has_media( $user_id ) ), $user_id );
	}

	/**
	 * Register the endpoint with WooCommerce.
	 *
	 * @param array<string,string> $vars Query vars.
	 * @return array<string,string>
	 */
	public function query_vars( $vars ): array {
		$vars                   = (array) $vars;
		$vars[ self::ENDPOINT ] = self::ENDPOINT;

		return $vars;
	}

	/**
	 * Flush rewrite rules once per endpoint version (pretty permalinks only).
	 */
	public function maybe_flush(): void {
		if ( self::REWRITE_VERSION === get_option( 'weargraph_account_rewrite_version' ) ) {
			return;
		}
		if ( get_option( 'permalink_structure' ) ) {
			flush_rewrite_rules( false );
		}
		update_option( 'weargraph_account_rewrite_version', self::REWRITE_VERSION, false );
	}

	/**
	 * Menu item before "Log out".
	 *
	 * @param array<string,string> $items Items.
	 * @return array<string,string>
	 */
	public function menu( $items ): array {
		$items = (array) $items;
		if ( ! is_user_logged_in() || ! $this->enabled() ) {
			return $items;
		}
		$label = __( 'My fitting room', 'weargraph' );
		$out   = array();
		foreach ( $items as $key => $text ) {
			if ( 'customer-logout' === $key ) {
				$out[ self::ENDPOINT ] = $label;
			}
			$out[ $key ] = $text;
		}
		if ( ! isset( $out[ self::ENDPOINT ] ) ) {
			$out[ self::ENDPOINT ] = $label;
		}

		return $out;
	}

	/**
	 * Page title.
	 */
	public function title(): string {
		return __( 'My fitting room', 'weargraph' );
	}

	/**
	 * Styles on this page only.
	 */
	public function styles(): void {
		if ( ! function_exists( 'is_wc_endpoint_url' ) || ! is_wc_endpoint_url( self::ENDPOINT ) ) {
			return;
		}
		wp_enqueue_style( 'weargraph-account', WEARGRAPH_URL . 'assets/storefront/account.css', array(), AdminMenu::asset_version( 'assets/storefront/account.css' ) );
	}

	/**
	 * Signed media for a logged-in customer's own visits.
	 *
	 * @param bool   $ok         Previous decision.
	 * @param string $session_id Session the token is bound to.
	 */
	public function own_media( $ok, $session_id ): bool {
		if ( $ok ) {
			return true;
		}
		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			// The media route is reached without a REST nonce; read the login cookie directly.
			$user_id = (int) wp_validate_auth_cookie( '', 'logged_in' );
		}
		if ( ! $user_id || '' === (string) $session_id ) {
			return false;
		}
		$session = $this->sessions->find_public( (string) $session_id );

		return $session && (int) $session['user_id'] === $user_id;
	}

	/**
	 * Every try-on image made in a customer's visits, newest first.
	 *
	 * @param int $user_id User.
	 * @return array<int,array{public_id:string,image:string,name:string,variant:string,url:string,date:string}>
	 */
	public function gallery( int $user_id ): array {
		global $wpdb;

		if ( $user_id <= 0 ) {
			return array();
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom tables.
		$rows = (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT g.product_id, g.variant_id, g.output_asset_ids, g.created_at FROM %i g INNER JOIN %i s ON s.public_id = g.session_id WHERE s.user_id = %d AND g.context = 'storefront' AND g.kind = 'tryon' AND g.state = %s ORDER BY g.id DESC LIMIT %d",
				Schema::table( 'generations' ),
				Schema::table( 'sessions' ),
				$user_id,
				GenerationState::READY,
				self::GALLERY_LIMIT
			),
			ARRAY_A
		);

		$items = array();
		foreach ( $rows as $row ) {
			$asset_ids = json_decode( (string) $row['output_asset_ids'], true );
			$asset     = is_array( $asset_ids ) && $asset_ids ? $this->assets->find( (int) $asset_ids[0] ) : null;
			if ( ! $asset ) {
				continue; // Deleted or expired (retention clean-up).
			}
			$product   = (int) $row['product_id'] ? wc_get_product( (int) $row['product_id'] ) : null;
			$variation = (int) $row['variant_id'] ? wc_get_product( (int) $row['variant_id'] ) : null;
			$live      = $product && 'publish' === $product->get_status();
			$items[]   = array(
				'public_id' => (string) $asset['public_id'],
				'image'     => SignedUrl::for_asset( (string) $asset['public_id'], (string) $asset['session_id'] ),
				'name'      => $product ? wp_strip_all_tags( $product->get_name() ) : __( 'A product no longer in the store', 'weargraph' ),
				'variant'   => $variation instanceof \WC_Product_Variation ? wp_strip_all_tags( wc_get_formatted_variation( $variation, true, false ) ) : '',
				'url'       => $live ? (string) ( $variation ? $variation->get_permalink() : get_permalink( (int) $row['product_id'] ) ) : '',
				'date'      => wp_date( (string) get_option( 'date_format' ), (int) strtotime( $row['created_at'] . ' UTC' ) ),
			);
		}

		return $items;
	}

	/**
	 * Page content.
	 */
	public function render(): void {
		$user_id = get_current_user_id();
		$items   = $user_id ? $this->gallery( $user_id ) : array();

		echo '<div class="weargraph-account-fitting-room">';
		echo '<p class="weargraph-account-intro">' . esc_html__( 'The try-on images made from your photo in our fitting room. Only you can see them, and you can delete them at any time.', 'weargraph' ) . '</p>';

		/**
		 * Sections above the gallery (Pro: the photo kept in the account).
		 *
		 * @param int $user_id Customer.
		 */
		do_action( 'weargraph_account_fitting_room_sections', $user_id );

		if ( $items ) {
			echo '<section class="weargraph-account-section"><h3>';
			/* translators: %d: number of images */
			echo esc_html( sprintf( _n( 'Your try-on image (%d)', 'Your try-on images (%d)', count( $items ), 'weargraph' ), count( $items ) ) );
			echo '</h3><ul class="weargraph-account-gallery">';
			foreach ( $items as $item ) {
				$this->render_item( $item );
			}
			echo '</ul></section>';
		}

		if ( ! $items && ! $this->has_media( $user_id ) ) {
			echo '<p class="weargraph-account-empty">' . esc_html__( 'You have no try-on images yet. Look for the Try On button on product pages.', 'weargraph' ) . '</p></div>';
			return;
		}
		?>
		<form method="post" class="weargraph-account-delete">
			<?php wp_nonce_field( self::ACTION_ALL ); ?>
			<input type="hidden" name="<?php echo esc_attr( self::ACTION_ALL ); ?>" value="1">
			<p><button type="submit" class="button"><?php esc_html_e( 'Delete my photos and all try-on images', 'weargraph' ); ?></button></p>
			<p class="description"><?php esc_html_e( 'Deletes them from this store straight away. Your orders are not affected.', 'weargraph' ); ?></p>
		</form>
		</div>
		<?php
	}

	/**
	 * One gallery card.
	 *
	 * @param array<string,string> $item Item.
	 */
	private function render_item( array $item ): void {
		?>
		<li class="weargraph-account-item">
			<a class="weargraph-account-item__image" href="<?php echo esc_url( $item['image'] ); ?>" target="_blank" rel="noopener">
				<?php /* translators: %s: product name */ ?>
				<img src="<?php echo esc_url( $item['image'] ); ?>" alt="<?php echo esc_attr( sprintf( __( 'You wearing %s (AI preview)', 'weargraph' ), $item['name'] ) ); ?>" loading="lazy"><?php // phpcs:ignore PluginCheck.CodeAnalysis.ImageFunctions.NonEnqueuedImage -- a private, signed try-on image, never a Media Library attachment. ?>
			</a>
			<div class="weargraph-account-item__body">
				<?php if ( '' !== $item['url'] ) : ?>
					<a class="weargraph-account-item__name" href="<?php echo esc_url( $item['url'] ); ?>"><?php echo esc_html( $item['name'] ); ?></a>
				<?php else : ?>
					<span class="weargraph-account-item__name"><?php echo esc_html( $item['name'] ); ?></span>
				<?php endif; ?>
				<?php if ( '' !== $item['variant'] ) : ?>
					<span class="weargraph-account-item__meta"><?php echo esc_html( $item['variant'] ); ?></span>
				<?php endif; ?>
				<span class="weargraph-account-item__meta"><?php echo esc_html( $item['date'] ); ?></span>
				<form method="post" class="weargraph-account-item__delete">
					<?php wp_nonce_field( self::ACTION_ONE ); ?>
					<input type="hidden" name="<?php echo esc_attr( self::ACTION_ONE ); ?>" value="<?php echo esc_attr( $item['public_id'] ); ?>">
					<button type="submit" class="button">
						<?php esc_html_e( 'Delete', 'weargraph' ); ?><span class="screen-reader-text"> <?php echo esc_html( $item['name'] ); ?></span>
					</button>
				</form>
			</div>
		</li>
		<?php
	}

	/**
	 * Whether any of a customer's visits still has stored media.
	 *
	 * @param int $user_id User.
	 */
	public function has_media( int $user_id ): bool {
		global $wpdb;

		if ( $user_id <= 0 ) {
			return false;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom tables.
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM %i a INNER JOIN %i s ON s.public_id = a.session_id WHERE s.user_id = %d AND a.deleted_at IS NULL',
				Schema::table( 'assets' ),
				Schema::table( 'sessions' ),
				$user_id
			)
		) > 0;
	}

	/**
	 * Delete one image, or everything (POST to the page, nonce, logged in).
	 */
	public function handle_delete(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified before acting.
		if ( ! is_user_logged_in() || ( ! isset( $_POST[ self::ACTION_ONE ] ) && ! isset( $_POST[ self::ACTION_ALL ] ) ) ) {
			return;
		}
		$user_id = get_current_user_id();
		if ( isset( $_POST[ self::ACTION_ONE ] ) ) {
			check_admin_referer( self::ACTION_ONE );
			$done = $this->delete_one( $user_id, sanitize_text_field( wp_unslash( $_POST[ self::ACTION_ONE ] ) ) );
			$this->notice( $done ? __( 'The try-on image was deleted.', 'weargraph' ) : __( 'That image could not be found. It may already be deleted.', 'weargraph' ), $done );
		} else {
			check_admin_referer( self::ACTION_ALL );
			$this->delete_all( $user_id );
			$this->notice( __( 'Your fitting-room photos and try-on images were deleted.', 'weargraph' ), true );
		}
		// phpcs:enable
		wp_safe_redirect( wc_get_account_endpoint_url( self::ENDPOINT ) );
		exit;
	}

	/**
	 * Delete one try-on image: every output of the try-on it belongs to, only when that
	 * try-on was made in one of the customer's own visits, never the shopper's photo.
	 *
	 * @param int    $user_id   User.
	 * @param string $public_id Asset public id.
	 * @return bool Deleted.
	 */
	public function delete_one( int $user_id, string $public_id ): bool {
		global $wpdb;

		$asset   = $user_id ? $this->assets->find_public( $public_id ) : null;
		$session = $asset ? $this->sessions->find_public( (string) $asset['session_id'] ) : null;
		if ( ! $session || (int) $session['user_id'] !== $user_id ) {
			return false;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table.
		$rows = (array) $wpdb->get_col( $wpdb->prepare( "SELECT output_asset_ids FROM %i WHERE context = 'storefront' AND kind = 'tryon' AND session_id = %s", Schema::table( 'generations' ), (string) $asset['session_id'] ) );
		$ids  = array();
		foreach ( $rows as $outputs ) {
			$candidate = array_map( 'intval', (array) json_decode( (string) $outputs, true ) );
			if ( in_array( (int) $asset['id'], $candidate, true ) ) {
				$ids = $candidate;
				break;
			}
		}
		if ( ! $ids ) {
			return false; // Not a try-on result (for example the shopper's photo).
		}
		foreach ( $ids as $id ) {
			$row = $this->assets->find( $id );
			if ( $row && (string) $row['session_id'] === (string) $asset['session_id'] ) {
				PrivateStore::delete( (string) $row['path'] );
				$this->assets->mark_deleted( $id );
			}
		}

		return true;
	}

	/**
	 * Delete the photos and images of every visit linked to a customer.
	 *
	 * @param int $user_id User.
	 * @return int Files removed.
	 */
	public function delete_all( int $user_id ): int {
		$removed = 0;
		$offset  = 0;
		do {
			$visits  = $user_id > 0 ? $this->sessions->for_user( $user_id, 50, $offset ) : array();
			$fetched = count( $visits );
			foreach ( $visits as $visit ) {
				$removed += $this->storefront->delete_session_media( $visit );
			}
			$offset += 50;
		} while ( 50 === $fetched );

		/**
		 * After a customer deleted all their fitting-room data (extensions clear theirs).
		 *
		 * @param int $user_id Customer.
		 */
		do_action( 'weargraph_account_fitting_room_deleted', $user_id );

		return $removed;
	}

	/**
	 * WooCommerce notice.
	 *
	 * @param string $message Message.
	 * @param bool   $ok      Success.
	 */
	private function notice( string $message, bool $ok ): void {
		if ( function_exists( 'wc_add_notice' ) ) {
			wc_add_notice( $message, $ok ? 'success' : 'error' );
		}
	}
}

<?php
/**
 * Settings → Privacy tab: retention, what happens to photos, purge tool.
 *
 * @package WearGraph
 */

namespace WearGraph\Admin;

defined( 'ABSPATH' ) || exit;

use WearGraph\Privacy\PersonalData;
use WearGraph\Privacy\Retention;
use WearGraph\Support\Capabilities;
use WearGraph\Support\Settings;

/**
 * The purge tool posts to its own admin-post action with its own nonce, so it can
 * never be triggered by the tab's normal "Save changes".
 */
final class PrivacySettingsTab {

	private const PURGE_ACTION = 'weargraph_purge_shopper_data';

	/**
	 * Personal data service.
	 *
	 * @var PersonalData
	 */
	private $personal;

	/**
	 * Constructor.
	 *
	 * @param PersonalData $personal Personal data service.
	 */
	public function __construct( PersonalData $personal ) {
		$this->personal = $personal;
	}

	/**
	 * Register hooks.
	 */
	public function boot(): void {
		add_filter( 'weargraph_settings_tabs', array( $this, 'register' ) );
		add_action( 'admin_post_' . self::PURGE_ACTION, array( $this, 'handle_purge' ) );
		add_filter( 'weargraph_admin_notices', array( $this, 'notices' ) );
		add_action( 'weargraph_settings_after_form_privacy', array( self::class, 'purge_form' ) );
	}

	/**
	 * Add the tab.
	 *
	 * @param array<string,array> $tabs Tabs.
	 * @return array<string,array>
	 */
	public function register( array $tabs ): array {
		$tabs['privacy'] = array(
			'label'  => __( 'Privacy', 'weargraph' ),
			'render' => array( $this, 'render' ),
			'save'   => array( $this, 'save' ),
			'fields' => array(
				'retention_mode' => 'key',
			),
		);

		return $tabs;
	}

	/**
	 * Render (inside the settings form).
	 */
	public function render(): void {
		$current = Retention::mode();
		?>
		<h3><?php esc_html_e( 'How long shopper photos are kept', 'weargraph' ); ?></h3>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'Photos and previews', 'weargraph' ); ?></th>
				<td>
					<fieldset>
						<?php foreach ( Retention::modes() as $key => $mode ) : ?>
							<label style="display:block;margin:4px 0">
								<input type="radio" name="retention_mode" value="<?php echo esc_attr( (string) $key ); ?>" <?php checked( (string) $key, $current ); ?>>
								<?php echo esc_html( (string) $mode['label'] ); ?>
							</label>
						<?php endforeach; ?>
					</fieldset>
					<p class="description"><?php esc_html_e( 'A clean-up runs every hour and deletes expired photos and previews, from the database and from disk. Shoppers can always delete their own photos straight away from the fitting room.', 'weargraph' ); ?></p>
				</td>
			</tr>
		</table>

		<div class="weargraph-disclosure">
			<h3><?php esc_html_e( 'What WearGraph does with shopper photos', 'weargraph' ); ?></h3>
			<ul style="list-style:disc;padding-left:18px">
				<li><?php esc_html_e( 'A photo is uploaded only after the shopper ticks the consent box.', 'weargraph' ); ?></li>
				<li><?php esc_html_e( 'Location data and other hidden information are removed before the photo is stored.', 'weargraph' ); ?></li>
				<li><?php esc_html_e( 'The photo is sent only to your AI provider, only to create the preview the shopper asked for. It is never used for anything else, never shared, and never used to train AI by WearGraph.', 'weargraph' ); ?></li>
				<li><?php esc_html_e( 'Photos and previews are private files that only the shopper\'s own browser can open, through links that expire after 10 minutes.', 'weargraph' ); ?></li>
				<li>
					<?php
					printf(
						/* translators: %s: link to the WordPress privacy policy guide */
						esc_html__( 'Suggested text for your privacy policy is available in %s. Logged-in customers\' fitting-room data is included in WordPress\'s Export and Erase Personal Data tools.', 'weargraph' ),
						'<a href="' . esc_url( admin_url( 'options-privacy.php?tab=policyguide' ) ) . '">' . esc_html__( 'Settings → Privacy → Policy guide', 'weargraph' ) . '</a>'
					);
					?>
				</li>
			</ul>
		</div>
		<?php
	}

	/**
	 * Save.
	 *
	 * @param array<string,mixed> $post Declared fields, already sanitized by type.
	 */
	public function save( array $post ): void {
		$mode = sanitize_key( (string) ( $post['retention_mode'] ?? '' ) );
		Settings::set( 'retention_mode', Retention::policy()->normalize( $mode ) );
	}

	/**
	 * Purge tool, printed after (outside) the settings form. The confirmation dialog is
	 * attached by assets/admin/settings.js (no inline script).
	 */
	public static function purge_form(): void {
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="weargraph-purge" data-confirm="<?php echo esc_attr__( 'Delete every shopper photo and preview now? This cannot be undone.', 'weargraph' ); ?>">
			<input type="hidden" name="action" value="<?php echo esc_attr( self::PURGE_ACTION ); ?>">
			<?php wp_nonce_field( self::PURGE_ACTION ); ?>
			<h3><?php esc_html_e( 'Delete all shopper photos now', 'weargraph' ); ?></h3>
			<p class="description"><?php esc_html_e( 'Removes every shopper photo, preview and fitting-room session immediately. Product images and your studio images are not affected.', 'weargraph' ); ?></p>
			<?php submit_button( __( 'Delete all shopper photos', 'weargraph' ), 'delete', 'submit', false ); ?>
		</form>
		<?php
	}

	/**
	 * Handle the purge.
	 */
	public function handle_purge(): void {
		if ( ! current_user_can( Capabilities::MANAGE ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'weargraph' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( self::PURGE_ACTION );

		$removed = $this->personal->purge_all_shopper_data();

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'      => SettingsPage::SLUG,
					'tab'       => 'privacy',
					'wg_purged' => $removed,
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Result notice.
	 *
	 * @param array<int,array> $notices Notices.
	 * @return array<int,array>
	 */
	public function notices( array $notices ): array {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display flag only.
		if ( isset( $_GET['page'], $_GET['wg_purged'] ) && SettingsPage::SLUG === $_GET['page'] ) {
			$notices[] = array(
				'type'    => 'success',
				'message' => sprintf(
					/* translators: %d: number of files */
					_n( '%d shopper file deleted.', '%d shopper files deleted.', absint( $_GET['wg_purged'] ), 'weargraph' ), // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only.
					absint( $_GET['wg_purged'] ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only.
				),
			);
		}

		return $notices;
	}
}

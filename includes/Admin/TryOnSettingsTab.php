<?php
/**
 * Settings → Try-On tab (store switch and storefront behaviour).
 *
 * @package WearGraph
 */

namespace WearGraph\Admin;

defined( 'ABSPATH' ) || exit;

use WearGraph\Storefront\ButtonRenderer;
use WearGraph\Storefront\StorefrontService;
use WearGraph\Support\Settings;

/**
 * Every control here is wired to working storefront code (no placeholders).
 */
final class TryOnSettingsTab {

	/**
	 * Register hooks.
	 */
	public function boot(): void {
		add_filter( 'weargraph_settings_tabs', array( $this, 'register' ) );
	}

	/**
	 * Add the tab after AI Provider.
	 *
	 * @param array<string,array> $tabs Tabs.
	 * @return array<string,array>
	 */
	public function register( array $tabs ): array {
		$tabs['tryon'] = array(
			'label'  => __( 'Try-On', 'weargraph' ),
			'render' => array( $this, 'render' ),
			'save'   => array( $this, 'save' ),
			'fields' => array(
				'tryon_enabled'       => 'bool',
				'preview_mode'        => 'bool',
				'button_label'        => 'key',
				'button_position'     => 'key',
				'archive_button'      => 'bool',
				'selector_fallback'   => 'bool',
				'fallback_selector'   => 'text',
				'consent_text'        => 'textarea',
				'session_tryon_quota' => 'int',
				'session_regen_quota' => 'int',
				'ip_daily_limit'      => 'int',
			),
		);

		return $tabs;
	}

	/**
	 * Render.
	 */
	public function render(): void {
		$positions = array(
			'after_add_to_cart'  => __( 'After the Add to cart button', 'weargraph' ),
			'before_add_to_cart' => __( 'Before the Add to cart area', 'weargraph' ),
			'after_meta'         => __( 'After the product meta (SKU, categories)', 'weargraph' ),
			'after_summary'      => __( 'Below the product summary', 'weargraph' ),
			'none'               => __( 'Nowhere automatically (I use the block or shortcode)', 'weargraph' ),
		);
		?>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'Virtual try-on', 'weargraph' ); ?></th>
				<td>
					<label><input type="checkbox" name="tryon_enabled" value="1" <?php checked( (bool) Settings::get( 'tryon_enabled' ) ); ?>>
					<?php esc_html_e( 'Show the Try On button to shoppers', 'weargraph' ); ?></label>
					<br><label><input type="checkbox" name="preview_mode" value="1" <?php checked( (bool) Settings::get( 'preview_mode' ) ); ?>>
					<?php esc_html_e( 'Preview mode: show it to store managers only (you must be logged in)', 'weargraph' ); ?></label>
					<p class="description">
						<?php
						printf(
							/* translators: %s: link to the Catalog screen */
							esc_html__( 'It appears only on products that are switched on in %s (Try-On Ready products are on automatically).', 'weargraph' ),
							'<a href="' . esc_url( admin_url( 'admin.php?page=' . CatalogPage::SLUG ) ) . '">' . esc_html__( 'WearGraph → Catalog', 'weargraph' ) . '</a>'
						);
						?>
					</p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="wg-label"><?php esc_html_e( 'Button text', 'weargraph' ); ?></label></th>
				<td>
					<select id="wg-label" name="button_label">
						<?php foreach ( ButtonRenderer::labels() as $key => $label ) : ?>
							<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $key, Settings::get( 'button_label' ) ); ?>><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="wg-position"><?php esc_html_e( 'Button position', 'weargraph' ); ?></label></th>
				<td>
					<select id="wg-position" name="button_position">
						<?php foreach ( $positions as $key => $label ) : ?>
							<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $key, Settings::get( 'button_position' ) ); ?>><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
					<p class="description"><?php esc_html_e( 'Block themes can also place the "Try On button" block in the product template, or use the shortcode [weargraph_try_on].', 'weargraph' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Shop pages', 'weargraph' ); ?></th>
				<td><label><input type="checkbox" name="archive_button" value="1" <?php checked( (bool) Settings::get( 'archive_button' ) ); ?>>
				<?php esc_html_e( 'Also show a small Try On button on shop and category pages', 'weargraph' ); ?></label></td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Theme fallback', 'weargraph' ); ?></th>
				<td>
					<label><input type="checkbox" name="selector_fallback" value="1" <?php checked( (bool) Settings::get( 'selector_fallback' ) ); ?>>
					<?php esc_html_e( 'If my theme hides the button, place it after this element:', 'weargraph' ); ?></label>
					<input type="text" name="fallback_selector" class="regular-text code" value="<?php echo esc_attr( (string) Settings::get( 'fallback_selector' ) ); ?>">
					<p class="description"><?php esc_html_e( 'Only for themes that do not use the standard WooCommerce product hooks.', 'weargraph' ); ?></p>
				</td>
			</tr>
		</table>

		<h3><?php esc_html_e( 'Shopper consent', 'weargraph' ); ?></h3>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="wg-consent"><?php esc_html_e( 'Consent text', 'weargraph' ); ?></label></th>
				<td>
					<textarea id="wg-consent" name="consent_text" rows="3" class="large-text" placeholder="<?php echo esc_attr( StorefrontService::consent_text() ); ?>"><?php echo esc_textarea( (string) Settings::get( 'consent_text' ) ); ?></textarea>
					<p class="description"><?php esc_html_e( 'Shoppers must tick this before their photo is uploaded. Leave empty to use the default shown. A link to your privacy policy is added automatically.', 'weargraph' ); ?></p>
				</td>
			</tr>
		</table>

		<h3><?php esc_html_e( 'Limits per shopper', 'weargraph' ); ?></h3>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="wg-quota"><?php esc_html_e( 'Previews per visit', 'weargraph' ); ?></label></th>
				<td><input type="number" id="wg-quota" name="session_tryon_quota" min="1" max="100" class="small-text" value="<?php echo esc_attr( (string) Settings::get( 'session_tryon_quota' ) ); ?>"></td>
			</tr>
			<tr>
				<th scope="row"><label for="wg-regen"><?php esc_html_e( '"Try again" per visit', 'weargraph' ); ?></label></th>
				<td><input type="number" id="wg-regen" name="session_regen_quota" min="0" max="50" class="small-text" value="<?php echo esc_attr( (string) Settings::get( 'session_regen_quota' ) ); ?>"></td>
			</tr>
			<tr>
				<th scope="row"><label for="wg-ip"><?php esc_html_e( 'Previews per connection per day', 'weargraph' ); ?></label></th>
				<td>
					<input type="number" id="wg-ip" name="ip_daily_limit" min="1" max="1000" class="small-text" value="<?php echo esc_attr( (string) Settings::get( 'ip_daily_limit' ) ); ?>">
					<p class="description"><?php esc_html_e( 'Stops one visitor or bot from using up your AI budget. The daily spending cap in General applies as well.', 'weargraph' ); ?></p>
				</td>
			</tr>
		</table>
		<?php
	}

	/**
	 * Save.
	 *
	 * @param array<string,mixed> $post Declared fields, already sanitized by type.
	 */
	public function save( array $post ): void {
		$label    = sanitize_key( (string) ( $post['button_label'] ?? '' ) );
		$position = sanitize_key( (string) ( $post['button_position'] ?? '' ) );

		Settings::update(
			array(
				'tryon_enabled'       => ! empty( $post['tryon_enabled'] ),
				'preview_mode'        => ! empty( $post['preview_mode'] ),
				'button_label'        => isset( ButtonRenderer::labels()[ $label ] ) ? $label : 'try_on',
				'button_position'     => isset( ButtonRenderer::POSITIONS[ $position ] ) ? $position : 'after_add_to_cart',
				'archive_button'      => ! empty( $post['archive_button'] ),
				'selector_fallback'   => ! empty( $post['selector_fallback'] ),
				'fallback_selector'   => self::selector( (string) ( $post['fallback_selector'] ?? '' ) ),
				'consent_text'        => mb_substr( sanitize_textarea_field( (string) ( $post['consent_text'] ?? '' ) ), 0, 600 ),
				'session_tryon_quota' => max( 1, min( 100, absint( $post['session_tryon_quota'] ?? 10 ) ) ),
				'session_regen_quota' => min( 50, absint( $post['session_regen_quota'] ?? 3 ) ),
				'ip_daily_limit'      => max( 1, min( 1000, absint( $post['ip_daily_limit'] ?? 30 ) ) ),
			)
		);
	}

	/**
	 * Keep a CSS selector to a safe character set.
	 *
	 * @param string $selector Raw selector.
	 */
	private static function selector( string $selector ): string {
		$selector = trim( (string) preg_replace( '/[^a-zA-Z0-9_\-\.\#\s\>\[\]="\':]/', '', $selector ) );

		return '' !== $selector ? mb_substr( $selector, 0, 200 ) : '.single_add_to_cart_button';
	}
}

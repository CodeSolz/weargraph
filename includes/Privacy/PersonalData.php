<?php
/**
 * WordPress personal-data exporter and eraser, and the privacy-policy suggestion.
 *
 * @package WearGraph
 */

namespace WearGraph\Privacy;

defined( 'ABSPATH' ) || exit;

use WearGraph\Database\Repositories\GenerationsRepository;
use WearGraph\Database\Repositories\SessionsRepository;
use WearGraph\Database\Schema;
use WearGraph\Domain\GenerationState;
use WearGraph\Providers\DescribedProvider;
use WearGraph\Providers\ProviderInfo;
use WearGraph\Providers\ProviderRegistry;
use WearGraph\Storage\PrivateStore;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom tables; one-off privacy requests.

/**
 * Tools → Export / Erase Personal Data. Only fitting-room sessions of logged-in
 * customers can be tied to an email address; guests' photos are anonymous and are
 * removed by retention (or by the shopper with "Delete my photos").
 */
final class PersonalData {

	private const PAGE = 20;

	/**
	 * Sessions.
	 *
	 * @var SessionsRepository
	 */
	private $sessions;

	/**
	 * Generations.
	 *
	 * @var GenerationsRepository
	 */
	private $generations;

	/**
	 * AI providers (names the try-on provider in the policy text).
	 *
	 * @var ProviderRegistry|null
	 */
	private $providers;

	/**
	 * Constructor.
	 *
	 * @param SessionsRepository    $sessions    Sessions.
	 * @param GenerationsRepository $generations Generations.
	 * @param ProviderRegistry|null $providers   AI providers.
	 */
	public function __construct( SessionsRepository $sessions, GenerationsRepository $generations, ?ProviderRegistry $providers = null ) {
		$this->sessions    = $sessions;
		$this->generations = $generations;
		$this->providers   = $providers;
	}

	/**
	 * Register hooks.
	 */
	public function boot(): void {
		add_filter( 'wp_privacy_personal_data_exporters', array( $this, 'register_exporter' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( $this, 'register_eraser' ) );
		add_action( 'admin_init', array( $this, 'policy_text' ) );
	}

	/**
	 * Register the exporter.
	 *
	 * @param array<string,array> $exporters Exporters.
	 * @return array<string,array>
	 */
	public function register_exporter( $exporters ): array {
		$exporters['weargraph'] = array(
			'exporter_friendly_name' => __( 'WearGraph virtual try-on', 'weargraph' ),
			'callback'               => array( $this, 'export' ),
		);

		return (array) $exporters;
	}

	/**
	 * Register the eraser.
	 *
	 * @param array<string,array> $erasers Erasers.
	 * @return array<string,array>
	 */
	public function register_eraser( $erasers ): array {
		$erasers['weargraph'] = array(
			'eraser_friendly_name' => __( 'WearGraph virtual try-on', 'weargraph' ),
			'callback'             => array( $this, 'erase' ),
		);

		return (array) $erasers;
	}

	/**
	 * Export a customer's fitting-room data.
	 *
	 * @param string $email Email address.
	 * @param int    $page  Page (1-based).
	 * @return array{data:array<int,array>,done:bool}
	 */
	public function export( $email, $page = 1 ): array {
		$user = get_user_by( 'email', (string) $email );
		if ( ! $user ) {
			return array(
				'data' => array(),
				'done' => true,
			);
		}

		$page     = max( 1, (int) $page );
		$sessions = $this->sessions->for_user( (int) $user->ID, self::PAGE, ( $page - 1 ) * self::PAGE );
		$items    = array();

		foreach ( $sessions as $session ) {
			$photos = $this->count_session_files( (string) $session['public_id'] );
			$data   = array(
				array(
					'name'  => __( 'Visit started', 'weargraph' ),
					'value' => (string) $session['created_at'] . ' UTC',
				),
				array(
					'name'  => __( 'Photo consent given', 'weargraph' ),
					'value' => $session['consent_at'] ? $session['consent_at'] . ' UTC (' . __( 'terms version', 'weargraph' ) . ' ' . $session['consent_version'] . ')' : __( 'No', 'weargraph' ),
				),
				array(
					'name'  => __( 'Previews created', 'weargraph' ),
					'value' => (string) (int) $session['tryon_count'],
				),
				array(
					'name'  => __( 'Photos and previews still stored', 'weargraph' ),
					'value' => sprintf(
						/* translators: %d: number of files */
						__( '%d (deleted automatically; they can also be erased on request)', 'weargraph' ),
						$photos
					),
				),
			);

			foreach ( $this->generations->for_session( (string) $session['public_id'] ) as $gen ) {
				$data[] = array(
					'name'  => __( 'Preview requested', 'weargraph' ),
					'value' => sprintf( '%1$s UTC: %2$s (%3$s)', $gen['created_at'], get_the_title( (int) $gen['product_id'] ), GenerationState::public_status( (string) $gen['state'] ) ),
				);
			}

			$items[] = array(
				'group_id'          => 'weargraph-fitting-room',
				'group_label'       => __( 'Virtual fitting room', 'weargraph' ),
				'group_description' => __( 'Visits to the virtual fitting room while logged in.', 'weargraph' ),
				'item_id'           => 'weargraph-session-' . $session['public_id'],
				'data'              => $data,
			);
		}

		return array(
			'data' => $items,
			'done' => count( $sessions ) < self::PAGE,
		);
	}

	/**
	 * Erase a customer's photos, previews, sessions and try-on records.
	 *
	 * @param string $email Email address.
	 * @param int    $page  Page (always processes the first page; rows are deleted as it goes).
	 * @return array{items_removed:bool,items_retained:bool,messages:string[],done:bool}
	 */
	public function erase( $email, $page = 1 ): array {
		unset( $page );
		$user = get_user_by( 'email', (string) $email );
		if ( ! $user ) {
			return array(
				'items_removed'  => false,
				'items_retained' => false,
				'messages'       => array(),
				'done'           => true,
			);
		}

		$sessions = $this->sessions->for_user( (int) $user->ID, self::PAGE, 0 );
		foreach ( $sessions as $session ) {
			$this->erase_session( $session );
		}

		/**
		 * Erase a customer's data held by add-ons (Pro: fitting profiles, looks, attribution).
		 *
		 * @param int    $user_id User.
		 * @param string $email   Email.
		 */
		do_action( 'weargraph_privacy_erase', (int) $user->ID, (string) $email );

		return array(
			'items_removed'  => (bool) $sessions,
			'items_retained' => false,
			'messages'       => array(),
			'done'           => count( $sessions ) < self::PAGE,
		);
	}

	/**
	 * Remove everything of one session: files, asset rows, generations, the session.
	 * Usage-ledger rows stay (no personal data, needed for spending history).
	 *
	 * @param array<string,mixed> $session Session row.
	 */
	public function erase_session( array $session ): void {
		global $wpdb;

		$sid    = (string) $session['public_id'];
		$assets = Schema::table( 'assets' );

		foreach ( $wpdb->get_results( $wpdb->prepare( 'SELECT id, path FROM %i WHERE session_id = %s', $assets, $sid ), ARRAY_A ) as $asset ) {
			PrivateStore::delete( (string) $asset['path'] );
			$wpdb->delete( $assets, array( 'id' => (int) $asset['id'] ) );
		}
		foreach ( $this->generations->for_session( $sid ) as $gen ) {
			$this->generations->delete( (int) $gen['id'] );
		}
		$this->sessions->delete( (int) $session['id'] );

		/**
		 * A fitting-room session was erased for privacy (events unlink it; Pro erases its data).
		 *
		 * @param string $session_id Session public id.
		 */
		do_action( 'weargraph_session_erased', $sid );
	}

	/**
	 * Delete every shopper photo, preview and session on the site (admin tool).
	 *
	 * @return int Files removed.
	 */
	public function purge_all_shopper_data(): int {
		global $wpdb;

		$assets = Schema::table( 'assets' );
		$rows   = $wpdb->get_results( $wpdb->prepare( "SELECT id, path FROM %i WHERE session_id IS NOT NULL OR type IN ('shopper_original','shopper_normalized','tryon_output')", $assets ), ARRAY_A );
		foreach ( $rows as $row ) {
			PrivateStore::delete( (string) $row['path'] );
			$wpdb->delete( $assets, array( 'id' => (int) $row['id'] ) );
		}

		$generations = Schema::table( 'generations' );
		$states      = Schema::table( 'generation_states' );
		$wpdb->query( $wpdb->prepare( "DELETE st FROM %i st INNER JOIN %i g ON g.id = st.generation_id WHERE g.context = 'storefront'", $states, $generations ) );
		$wpdb->query( $wpdb->prepare( "DELETE FROM %i WHERE context = 'storefront'", $generations ) );
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i', Schema::table( 'sessions' ) ) );

		return count( $rows );
	}

	/**
	 * Suggested privacy-policy text (Settings → Privacy).
	 */
	public function policy_text(): void {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}

		$keeps = Retention::keeps_beyond_session();
		$info  = $this->tryon_provider_info();
		$html  = '<p class="privacy-policy-tutorial">' . esc_html__( 'Suggested text for your privacy policy if you use the WearGraph virtual fitting room. Adjust it to your store.', 'weargraph' ) . '</p>'
			. '<h3>' . esc_html__( 'Virtual fitting room', 'weargraph' ) . '</h3>'
			. '<p><strong class="privacy-policy-tutorial">' . esc_html__( 'Suggested text:', 'weargraph' ) . '</strong> '
			. esc_html__( 'If you use our virtual fitting room, you upload a photo of yourself so we can show you a preview of how a product might look on you. We ask for your consent before the photo is uploaded.', 'weargraph' ) . '</p>'
			. '<p>' . esc_html(
				sprintf(
					/* translators: %s: AI provider name, e.g. Google Gemini */
					__( 'Your photo is sent securely to our AI service provider, %s, only to create the preview you asked for. We do not use your photo for anything else, and we do not share it with anyone else.', 'weargraph' ),
					$info ? $info->label : __( '[name of your AI provider]', 'weargraph' )
				)
			) . '</p>'
			. '<p>' . ( $keeps
				? esc_html__( 'Your photo and previews are deleted automatically within 24 hours. You can delete them yourself at any time with "Delete my photos" in the fitting room.', 'weargraph' )
				: esc_html__( 'Your photo and previews are deleted automatically when your visit ends. You can delete them yourself at any time with "Delete my photos" in the fitting room.', 'weargraph' ) ) . '</p>'
			. '<p>' . esc_html__( 'The fitting room uses a technical cookie (weargraph_session) to keep your photo linked to your visit. It is removed when the visit ends and is not used for tracking.', 'weargraph' ) . '</p>'
			. ( $info && $info->terms_url && $info->privacy_url
				? '<p>' . sprintf(
					/* translators: 1: AI provider name, 2: link to its terms, 3: link to its privacy policy */
					esc_html__( '%1$s processes the photo under its %2$s and %3$s.', 'weargraph' ),
					esc_html( $info->label ),
					'<a href="' . esc_url( $info->terms_url ) . '">' . esc_html__( 'terms of service', 'weargraph' ) . '</a>',
					'<a href="' . esc_url( $info->privacy_url ) . '">' . esc_html__( 'privacy policy', 'weargraph' ) . '</a>'
				) . '</p>'
				: '' );

		wp_add_privacy_policy_content( 'WearGraph', wp_kses_post( $html ) );
	}

	/**
	 * The provider that makes try-on previews, when it describes itself.
	 */
	private function tryon_provider_info(): ?ProviderInfo {
		$provider = $this->providers ? $this->providers->for_task( ProviderInfo::TASK_TRYON ) : null;

		return $provider instanceof DescribedProvider ? $provider->info() : null;
	}

	/**
	 * Files stored for a session.
	 *
	 * @param string $session_id Session public id.
	 */
	private function count_session_files( string $session_id ): int {
		global $wpdb;

		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE session_id = %s AND deleted_at IS NULL', Schema::table( 'assets' ), $session_id ) );
	}
}

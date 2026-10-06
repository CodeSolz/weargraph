<?php
/**
 * Non-blocking loopback that starts a queued generation immediately.
 *
 * @package WearGraph
 */

namespace WearGraph\Jobs;

defined( 'ABSPATH' ) || exit;

use WearGraph\Support\Crypto;

/**
 * The web request that created a generation fires a fire-and-forget POST to
 * admin-ajax.php; that separate request runs the job. The payload is HMAC-signed
 * with a short expiry, so outsiders cannot trigger jobs, and the job's atomic claim
 * makes duplicate kicks harmless. If loopbacks are blocked on a host, Action
 * Scheduler still runs the job on the next cron tick.
 */
final class AsyncKick {

	public const ACTION = 'weargraph_run_generation';

	private const TTL = 120;

	/**
	 * Fire the loopback.
	 *
	 * @param int $generation_id Generation id.
	 */
	public static function dispatch( int $generation_id ): void {
		/**
		 * Whether to start jobs immediately via a loopback request.
		 *
		 * @param bool $enabled Default true.
		 */
		if ( ! apply_filters( 'weargraph_async_kick', true ) ) {
			return;
		}

		$expires = time() + self::TTL;

		wp_remote_post(
			admin_url( 'admin-ajax.php' ),
			array(
				'blocking'  => false,
				'timeout'   => 0.5,
				// Same policy core uses for its own loopbacks (cron, site health).
				'sslverify' => apply_filters( 'https_local_ssl_verify', false ), // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core hook.
				'body'      => array(
					'action' => self::ACTION,
					'id'     => $generation_id,
					'exp'    => $expires,
					'sig'    => self::signature( $generation_id, $expires ),
				),
			)
		);
	}

	/**
	 * Handle the loopback (registered for logged-in and logged-out requests; the
	 * signature is the authorisation).
	 *
	 * @param GenerationJob $job Job runner.
	 */
	public static function handle( GenerationJob $job ): void {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- authorised by the HMAC signature below instead of a nonce (no user session in a loopback).
		$id  = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		$exp = isset( $_POST['exp'] ) ? absint( $_POST['exp'] ) : 0;
		$sig = isset( $_POST['sig'] ) ? sanitize_text_field( wp_unslash( $_POST['sig'] ) ) : '';
		// phpcs:enable

		if ( ! $id || $exp < time() || ! hash_equals( self::signature( $id, $exp ), $sig ) ) {
			wp_die( '', '', array( 'response' => 403 ) );
		}

		ignore_user_abort( true );
		if ( function_exists( 'set_time_limit' ) ) {
			set_time_limit( 300 ); // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged -- long provider call in a background request.
		}

		$job->run( $id );
		wp_die( '', '', array( 'response' => 200 ) );
	}

	/**
	 * Payload signature.
	 *
	 * @param int $id      Generation id.
	 * @param int $expires Expiry timestamp.
	 */
	private static function signature( int $id, int $expires ): string {
		return Crypto::sign( $id . '|' . $expires, 'job' );
	}
}

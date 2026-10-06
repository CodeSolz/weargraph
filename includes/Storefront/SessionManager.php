<?php
/**
 * Shopper session: cookie, CSRF token, ownership checks.
 *
 * @package WearGraph
 */

namespace WearGraph\Storefront;

defined( 'ABSPATH' ) || exit;

use WearGraph\Database\Repositories\SessionsRepository;
use WearGraph\Support\Crypto;
use WearGraph\Support\RateLimiter;

/**
 * A random token in an HttpOnly, SameSite=Lax cookie identifies the fitting-room
 * session; only its hash is stored. Mutating storefront requests must also send a
 * CSRF token (returned by GET /storefront/session) in the X-WearGraph-CSRF header,
 * which a cross-site page cannot read. Nothing session-specific is ever rendered
 * into page HTML, so full-page caches stay safe.
 */
final class SessionManager {

	public const COOKIE = 'weargraph_session';

	/**
	 * Sessions.
	 *
	 * @var SessionsRepository
	 */
	private $sessions;

	/**
	 * Session resolved for this request.
	 *
	 * @var array<string,mixed>|null|false False = not resolved yet.
	 */
	private $current = false;

	/**
	 * Constructor.
	 *
	 * @param SessionsRepository $sessions Sessions.
	 */
	public function __construct( SessionsRepository $sessions ) {
		$this->sessions = $sessions;
	}

	/**
	 * Register ownership filters used by the generations and media controllers.
	 */
	public function boot(): void {
		add_filter( 'weargraph_generation_access', array( $this, 'owns_generation' ), 10, 2 );
		add_filter( 'weargraph_media_session_ok', array( $this, 'owns_session' ), 10, 2 );
	}

	/**
	 * Session lifetime in seconds.
	 */
	public static function ttl(): int {
		/**
		 * Fitting-room session lifetime (Pro's saved fitting room extends it).
		 *
		 * @param int $seconds Default 2 hours.
		 */
		return max( 300, (int) apply_filters( 'weargraph_session_ttl', 2 * HOUR_IN_SECONDS ) );
	}

	/**
	 * The live session from the cookie, or null.
	 *
	 * @return array<string,mixed>|null
	 */
	public function current(): ?array {
		if ( false !== $this->current ) {
			return $this->current;
		}

		$token         = isset( $_COOKIE[ self::COOKIE ] ) ? sanitize_text_field( wp_unslash( $_COOKIE[ self::COOKIE ] ) ) : '';
		$this->current = preg_match( '/^[a-f0-9]{64}$/', $token ) ? $this->sessions->find_live( hash( 'sha256', $token ) ) : null;

		return $this->current;
	}

	/**
	 * Current session or a new one (sets the cookie).
	 *
	 * @return array<string,mixed>
	 */
	public function start(): array {
		$existing = $this->current();
		if ( $existing ) {
			return $existing;
		}

		$token = bin2hex( random_bytes( 32 ) );
		$ua    = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';
		// Storefront REST calls carry no WP nonce, so WordPress treats everyone as a guest
		// there; read the logged-in cookie directly so a customer's photos stay linked to
		// their account (needed for the privacy exporter and eraser).
		$user_id = (int) wp_validate_auth_cookie( '', 'logged_in' );
		$session = $this->sessions->create( hash( 'sha256', $token ), self::ttl(), RateLimiter::ip_hash(), Crypto::sign( $ua, 'ua' ), $user_id );

		if ( ! headers_sent() ) {
			setcookie(
				self::COOKIE,
				$token,
				array(
					'expires'  => time() + self::ttl(),
					'path'     => COOKIEPATH ? COOKIEPATH : '/',
					'domain'   => COOKIE_DOMAIN ? COOKIE_DOMAIN : '',
					'secure'   => is_ssl(),
					'httponly' => true,
					'samesite' => 'Lax',
				)
			);
		}
		$_COOKIE[ self::COOKIE ] = $token;
		$this->current           = $session;

		return $session;
	}

	/**
	 * Forget the cached session (after updates).
	 */
	public function refresh(): ?array {
		$this->current = false;

		return $this->current();
	}

	/**
	 * CSRF token for a session.
	 *
	 * @param array<string,mixed> $session Session.
	 */
	public static function csrf( array $session ): string {
		return Crypto::sign( (string) $session['public_id'], 'csrf' );
	}

	/**
	 * Whether a request carries the right CSRF token for the current session.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function verify_csrf( \WP_REST_Request $request ): bool {
		$session = $this->current();
		$token   = (string) $request->get_header( 'X-WearGraph-CSRF' );

		return $session && '' !== $token && hash_equals( self::csrf( $session ), $token );
	}

	/**
	 * Filter: the visitor owns a storefront generation.
	 *
	 * @param bool                $allowed Previous decision.
	 * @param array<string,mixed> $row     Generation row.
	 */
	public function owns_generation( $allowed, $row ): bool {
		if ( $allowed ) {
			return true;
		}
		$session = $this->current();

		return $session && is_array( $row ) && 'storefront' === ( $row['context'] ?? '' ) && hash_equals( (string) $session['public_id'], (string) ( $row['session_id'] ?? '' ) );
	}

	/**
	 * Filter: the visitor owns the session a media token is bound to.
	 *
	 * @param bool   $ok         Previous decision.
	 * @param string $session_id Session public id from the token.
	 */
	public function owns_session( $ok, $session_id ): bool {
		if ( $ok ) {
			return true;
		}
		$session = $this->current();

		return $session && hash_equals( (string) $session['public_id'], (string) $session_id );
	}
}

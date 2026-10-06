<?php
/**
 * Private file store for shopper photos and try-on outputs.
 *
 * @package WearGraph
 */

namespace WearGraph\Storage;

defined( 'ABSPATH' ) || exit;

/**
 * Files live under uploads/weargraph-private/ with random names, and are only ever
 * served through a signed, short-lived REST URL (see to-do/01-ARCHITECTURE.md §8).
 * The deny files cover Apache and IIS; on Nginx the random names are what protect them.
 */
final class PrivateStore {

	public const DIR_NAME = 'weargraph-private';

	/**
	 * Absolute base directory (no trailing slash).
	 */
	public static function base_dir(): string {
		$uploads = wp_upload_dir( null, false );

		return untrailingslashit( $uploads['basedir'] ) . '/' . self::DIR_NAME;
	}

	/**
	 * Create the directory and its deny files if missing.
	 */
	public static function ensure_dir(): bool {
		$dir = self::base_dir();

		if ( ! wp_mkdir_p( $dir ) ) {
			return false;
		}

		$files = array(
			'.htaccess'  => "# WearGraph: private shopper images. Served only via signed URLs.\nRequire all denied\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n",
			'index.php'  => "<?php\n// Silence is golden.\n",
			'web.config' => "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<configuration><system.webServer><authorization><deny users=\"*\" /></authorization></system.webServer></configuration>\n",
		);

		$fs = self::filesystem();
		if ( ! $fs ) {
			return false;
		}

		foreach ( $files as $name => $contents ) {
			$path = $dir . '/' . $name;
			if ( ! $fs->exists( $path ) ) {
				$fs->put_contents( $path, $contents, FS_CHMOD_FILE );
			}
		}

		return true;
	}

	/**
	 * Write bytes to a new randomly named file.
	 *
	 * @param string $bytes File contents.
	 * @param string $mime  image/png, image/jpeg or image/webp.
	 * @return array{path:string,sha256:string,bytes:int,width:int,height:int}|null Relative path and metadata, null on failure.
	 */
	public static function write( string $bytes, string $mime ): ?array {
		$ext = array(
			'image/png'  => 'png',
			'image/jpeg' => 'jpg',
			'image/webp' => 'webp',
			'video/mp4'  => 'mp4',
		);
		if ( '' === $bytes || ! isset( $ext[ $mime ] ) || ! self::ensure_dir() ) {
			return null;
		}

		$fs = self::filesystem();
		if ( ! $fs ) {
			return null;
		}

		// Year/month shard, 64 hex chars of randomness: unguessable even where deny files are ignored (Nginx).
		$relative = gmdate( 'Y/m' ) . '/' . bin2hex( random_bytes( 32 ) ) . '.' . $ext[ $mime ];
		$absolute = self::base_dir() . '/' . $relative;

		if ( ! wp_mkdir_p( dirname( $absolute ) ) || ! $fs->put_contents( $absolute, $bytes, FS_CHMOD_FILE ) ) {
			return null;
		}

		$size = 'video/mp4' === $mime ? false : getimagesizefromstring( $bytes );

		return array(
			'path'   => $relative,
			'sha256' => hash( 'sha256', $bytes ),
			'bytes'  => strlen( $bytes ),
			'width'  => $size ? (int) $size[0] : 0,
			'height' => $size ? (int) $size[1] : 0,
		);
	}

	/**
	 * Absolute path for a stored relative path; '' if it escapes the store.
	 *
	 * @param string $relative Relative path from write().
	 */
	public static function absolute( string $relative ): string {
		if ( '' === $relative || false !== strpos( $relative, '..' ) || ! preg_match( '#^[0-9]{4}/[0-9]{2}/[a-f0-9]{64}\.(png|jpg|webp|mp4)$#', $relative ) ) {
			return '';
		}

		return self::base_dir() . '/' . $relative;
	}

	/**
	 * Delete one stored file.
	 *
	 * @param string $relative Relative path.
	 */
	public static function delete( string $relative ): bool {
		$path = self::absolute( $relative );
		$fs   = self::filesystem();

		return '' !== $path && $fs && $fs->exists( $path ) ? $fs->delete( $path ) : false;
	}

	/**
	 * Delete the whole directory tree (uninstall).
	 */
	public static function delete_all(): bool {
		$fs = self::filesystem();

		return $fs ? $fs->delete( self::base_dir(), true ) : false;
	}

	/**
	 * Direct-access filesystem instance.
	 *
	 * @return \WP_Filesystem_Base|null
	 */
	private static function filesystem() {
		global $wp_filesystem;

		if ( ! function_exists( 'WP_Filesystem' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		if ( ! $wp_filesystem && ! WP_Filesystem( false, false, true ) ) {
			return null;
		}

		return $wp_filesystem;
	}
}

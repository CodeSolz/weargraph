<?php
/**
 * Local file helpers.
 *
 * @package WearGraph
 */

namespace WearGraph\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Reads files the plugin itself owns (private store, Media Library attachments).
 * Never used with URLs.
 */
final class Files {

	/**
	 * Read a local file. Returns '' when missing or unreadable.
	 *
	 * @param string $path Absolute local path.
	 */
	public static function read( string $path ): string {
		if ( '' === $path || false !== strpos( $path, '://' ) || ! is_readable( $path ) ) {
			return '';
		}

		$bytes = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local file owned by the plugin, never a URL.

		return false === $bytes ? '' : $bytes;
	}
}

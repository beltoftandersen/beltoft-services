<?php
/**
 * The one place Hosting knows which plugin it lives in.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Hosting;

defined( 'ABSPATH' ) || exit;

/**
 * Paths, URLs and asset versions for files of the plugin that owns Hosting.
 */
final class Paths {

	/**
	 * Main file of the plugin that owns Hosting. The only line to change when Hosting moves.
	 */
	private static function root_file(): string {
		return BELTOFT_SERVICES_FILE;
	}

	/**
	 * Filesystem path inside the plugin, with a trailing slash when no relative part is given.
	 *
	 * @param string $relative Path such as templates/account/hosting/x.php.
	 */
	public static function dir( string $relative = '' ): string {
		return plugin_dir_path( self::root_file() ) . $relative;
	}

	/**
	 * URL of a file inside the plugin.
	 *
	 * @param string $relative Path such as assets/hosting.css.
	 */
	public static function url( string $relative ): string {
		return plugins_url( $relative, self::root_file() );
	}

	/**
	 * Cache-busting value: the file's modification time, else the plugin version.
	 *
	 * @param string $relative Path such as assets/hosting.css.
	 */
	public static function version( string $relative ): string {
		$time = @filemtime( self::dir( $relative ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a missing file falls back to the plugin version.

		return $time ? (string) $time : BELTOFT_SERVICES_VERSION;
	}
}

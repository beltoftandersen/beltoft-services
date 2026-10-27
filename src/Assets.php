<?php
/**
 * Version strings for the plugin's own scripts and stylesheets.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices;

defined( 'ABSPATH' ) || exit;

/**
 * The server lets browsers cache static files for years, so an asset URL must change whenever
 * the file does: the version is the file's modification time, and the plugin version when the
 * file cannot be read.
 */
final class Assets {

	/**
	 * Version for a file under the plugin directory.
	 *
	 * @param string $relative Path such as assets/account.css.
	 */
	public static function version( string $relative ): string {
		$time = @filemtime( plugin_dir_path( BELTOFT_SERVICES_FILE ) . $relative ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a missing file falls back to the plugin version.

		return $time ? (string) $time : BELTOFT_SERVICES_VERSION;
	}
}

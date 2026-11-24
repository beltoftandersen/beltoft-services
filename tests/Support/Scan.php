<?php
/**
 * The one place that says which plugin folder the guard tests scan (src/, assets/, templates/).
 *
 * It is this plugin's root. Another plugin that runs the same guard tests over its own code (hosting) defines
 * BELTOFT_TESTS_SCAN_ROOT (and, where its boot file has another name, BELTOFT_TESTS_BOOT_FILE) in its bootstrap before it loads this one.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Support;

/**
 * Root of the code under guard.
 */
final class Scan {

	/**
	 * The plugin folder, without a trailing slash.
	 *
	 * @return string
	 */
	public static function root(): string {
		return defined( 'BELTOFT_TESTS_SCAN_ROOT' ) ? rtrim( (string) constant( 'BELTOFT_TESTS_SCAN_ROOT' ), '/' ) : dirname( __DIR__, 2 );
	}

	/**
	 * Folders of src/ that are not booted in this run (BELTOFT_TEST_WITHOUT_HOSTING proves the rest does not need hosting).
	 * Tests that compare the source with what registered at boot leave them out.
	 *
	 * @return string[]
	 */
	public static function unbooted(): array {
		return getenv( 'BELTOFT_TEST_WITHOUT_HOSTING' ) ? array( 'Hosting' ) : array();
	}

	/**
	 * The file whose boot method calls every register() (Plugin::boot()).
	 *
	 * @return string
	 */
	public static function boot_file(): string {
		return defined( 'BELTOFT_TESTS_BOOT_FILE' ) ? (string) constant( 'BELTOFT_TESTS_BOOT_FILE' ) : self::root() . '/src/Plugin.php';
	}
}

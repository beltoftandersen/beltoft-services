<?php
/**
 * The DNS label rule lives in one place.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Structure;

use BeltoftServices\Tests\Support\Scan;

use BeltoftServices\Tests\TestCase;

/**
 * No second copy of the host-name label regex.
 */
final class HostNameRegexTest extends TestCase {

	public function test_the_label_regex_lives_in_host_name_only(): void {
		$root    = Scan::root() . '/src/';
		$allowed = array( 'Domains/HostName.php', 'Domains/Records.php', 'Hosting/FakeAgent.php' );
		// A listed file of a feature that is not installed cannot hold the regex.
		$allowed = array_values( array_filter( $allowed, static fn( $file ) => file_exists( $root . $file ) ) );
		$found   = array();

		$iterator = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $root, \FilesystemIterator::SKIP_DOTS ) );
		foreach ( $iterator as $file ) {
			if ( 'php' !== $file->getExtension() ) {
				continue;
			}
			if ( false !== strpos( (string) file_get_contents( $file->getPathname() ), '{0,61}' ) ) {
				$found[] = substr( $file->getPathname(), strlen( $root ) );
			}
		}
		sort( $found );
		sort( $allowed );

		$this->assertSame( $allowed, $found );
	}
}

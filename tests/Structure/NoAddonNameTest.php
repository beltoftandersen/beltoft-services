<?php
/**
 * The plugin code names no other plugin's currency add-on.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Structure;

use PHPUnit\Framework\TestCase;

/**
 * Currency support reaches a site's add-on through this plugin's own filters only. A site's bridge file is the one
 * place that names both plugins, so nothing under src/, templates/ or assets/ may name the add-on.
 */
class NoAddonNameTest extends TestCase {

	public function test_no_file_names_the_currency_add_on(): void {
		$root  = dirname( __DIR__, 2 );
		$found = array();

		foreach ( array( 'src', 'templates', 'assets', 'apps' ) as $dir ) {
			if ( ! is_dir( $root . '/' . $dir ) ) {
				continue;
			}

			$it = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $root . '/' . $dir, \FilesystemIterator::SKIP_DOTS ) );

			foreach ( $it as $file ) {
				if ( ! $file->isFile() || ! preg_match( '/\.(php|js|css|json)$/', $file->getFilename() ) ) {
					continue;
				}

				if ( false !== stripos( (string) file_get_contents( $file->getPathname() ), 'zerolang' ) ) {
					$found[] = substr( $file->getPathname(), strlen( $root ) + 1 );
				}
			}
		}

		$this->assertSame( array(), $found, 'Use a filter of this plugin, never the name of another plugin.' );
	}
}

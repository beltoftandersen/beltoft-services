<?php
/**
 * The shared REST helper (assets/api.js): registered once, and a dependency of every script that calls it.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests;

use BeltoftServices\Status;

/**
 * Api script tests.
 */
final class ApiScriptTest extends TestCase {

	public function test_the_helper_is_registered_with_status(): void {
		wp_deregister_script( Status::API_HANDLE );
		Status::assets();

		$this->assertTrue( wp_script_is( Status::API_HANDLE, 'registered' ) );
		$this->assertStringEndsWith( 'assets/api.js', wp_scripts()->registered[ Status::API_HANDLE ]->src );
	}

	public function test_every_script_that_calls_the_helper_depends_on_it(): void {
		$checked = 0;

		foreach ( glob( BELTOFT_SERVICES_PATH . 'assets/*.js' ) as $file ) {
			$name = basename( $file );

			if ( 'api.js' === $name || false === strpos( (string) file_get_contents( $file ), 'beltoftApi' ) ) {
				continue;
			}

			$lines = array();
			foreach ( new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( BELTOFT_SERVICES_PATH . 'src', \FilesystemIterator::SKIP_DOTS ) ) as $php ) {
				if ( 'php' !== $php->getExtension() ) {
					continue;
				}
				foreach ( file( $php->getPathname() ) as $line ) {
					if ( false !== strpos( $line, 'wp_enqueue_script' ) && false !== strpos( $line, "'assets/" . $name . "'" ) ) {
						$lines[] = $line;
					}
				}
			}

			$this->assertNotEmpty( $lines, $name . ' is enqueued somewhere.' );

			foreach ( $lines as $line ) {
				$this->assertTrue( false !== strpos( $line, 'Status::API_HANDLE' ) || false !== strpos( $line, 'beltoft-services-api' ), $name . ' must list the api handle as a dependency: ' . trim( $line ) );
			}

			++$checked;
		}

		$this->assertGreaterThanOrEqual( 14, $checked );
	}
}

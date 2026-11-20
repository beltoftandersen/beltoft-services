<?php
/**
 * The usage engine (src/Usage) does not name a product: drivers declare their own hourly items.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Structure;

use PHPUnit\Framework\TestCase;

/**
 * Pay as you go is an engine that drivers hook into (Drivers\UsageItems). A driver id or an activation kind written as a literal in
 * src/Usage is a product named by the engine, and so is a driver class of a feature (Proxmox\Disk\DiskDriver and the like). Nothing is allowed.
 */
class UsageEngineNeutralTest extends TestCase {

	private const LITERALS = array( 'proxmox', 'proxmox-db', 'proxmox-disk', 'proxmox-ipv4', 'vm', 'db', 'disk', 'ipv4' );

	/**
	 * @return array<string, string[]>
	 */
	private function found(): array {
		$root = dirname( __DIR__, 2 );
		$out  = array();
		$it   = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $root . '/src/Usage', \FilesystemIterator::SKIP_DOTS ) );

		foreach ( $it as $file ) {
			if ( 'php' !== $file->getExtension() ) {
				continue;
			}

			$rel  = substr( $file->getPathname(), strlen( $root ) + 1 );
			$code = (string) file_get_contents( $file->getPathname() );

			foreach ( self::LITERALS as $literal ) {
				if ( preg_match( '/[\'"]' . preg_quote( $literal, '/' ) . '[\'"]/', $code ) ) {
					$out[ $rel ][] = $literal;
				}
			}
		}

		ksort( $out );

		return $out;
	}

	public function test_the_engine_names_no_driver_or_kind(): void {
		$found = $this->found();

		$this->assertSame( array(), $found, 'The usage engine names a driver id or a kind: let the driver declare it (Drivers\UsageItems).' );
	}

	public function test_the_engine_uses_no_driver_class_of_a_feature(): void {
		$root = dirname( __DIR__, 2 );
		$bad  = array();
		$it   = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $root . '/src/Usage', \FilesystemIterator::SKIP_DOTS ) );

		foreach ( $it as $file ) {
			if ( 'php' === $file->getExtension() && preg_match( '/\\\\(?:Proxmox|Apps|ManualServer|Hosting|Domains|Mail|Ssl|Storage|Licences|Support)\\\\(?:\w+\\\\)*\w*Driver\b/', (string) file_get_contents( $file->getPathname() ) ) ) {
				$bad[] = substr( $file->getPathname(), strlen( $root ) + 1 );
			}
		}

		$this->assertSame( array(), $bad, 'A driver class of a feature is named in src/Usage: ask the driver through Drivers\UsageItems instead.' );
	}
}

<?php
/**
 * Hosting reaches into Proxmox only through the Pve platform layer (moved here from Structure\NamespaceMoveTest).
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Hosting;

use PHPUnit\Framework\TestCase;

/**
 * Scans src/Hosting.
 */
class ProxmoxReachTest extends TestCase {

	public function test_hosting_reaches_into_proxmox_only_through_pve(): void {
		$bad = array();
		$it  = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( dirname( __DIR__, 2 ) . '/src/Hosting', \FilesystemIterator::SKIP_DOTS ) );

		foreach ( $it as $file ) {
			if ( 'php' !== $file->getExtension() ) {
				continue;
			}

			foreach ( token_get_all( (string) file_get_contents( $file->getPathname() ) ) as $token ) {
				if ( is_array( $token ) && in_array( $token[0], array( T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED ), true ) ) {
					$name = ltrim( $token[1], '\\' );

					if ( 0 === strpos( $name, 'BeltoftServices\\Proxmox\\' ) && 0 !== strpos( $name, 'BeltoftServices\\Proxmox\\Pve\\' ) ) {
						$bad[] = $file->getFilename() . ' -> ' . $name;
					}
				}
			}
		}

		$this->assertSame( array(), $bad );
	}
}

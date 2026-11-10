<?php
/**
 * The Proxmox driver id is written once, as ProxmoxDriver::ID.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Structure;

use PHPUnit\Framework\TestCase;

/**
 * A bare 'proxmox' next to a driver check is a second copy of the id. This scans src/ and fails on one outside ProxmoxDriver.
 */
class DriverIdLiteralTest extends TestCase {

	/**
	 * Files that may hold the literal, each with a reason.
	 *
	 * @var array<string, string>
	 */
	private const ALLOWED = array(
		'src/Proxmox/Vm/ProxmoxDriver.php'   => 'defines the id',
		'src/Proxmox/Pve/ProxmoxSettings.php' => 'the settings tab slug, not the driver id',
		'src/Drivers/Driver.php'          => 'a docblock example',
	);

	public function test_the_literal_proxmox_is_not_used_as_a_driver_id(): void {
		$root = dirname( __DIR__, 2 );
		$bad  = array();
		$it   = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $root . '/src', \FilesystemIterator::SKIP_DOTS ) );

		foreach ( $it as $file ) {
			$rel = substr( $file->getPathname(), strlen( $root ) + 1 );

			if ( 'php' !== $file->getExtension() || isset( self::ALLOWED[ $rel ] ) ) {
				continue;
			}

			if ( preg_match( "/'proxmox'/", (string) file_get_contents( $file->getPathname() ) ) ) {
				$bad[] = $rel;
			}
		}

		$this->assertSame( array(), $bad, "Use ProxmoxDriver::ID instead of the literal 'proxmox'." );
		$this->assertSame( 'proxmox', \BeltoftServices\Proxmox\Vm\ProxmoxDriver::ID );
	}
}

<?php
/**
 * The shared monitoring folder names no driver.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Structure;

use PHPUnit\Framework\TestCase;

/**
 * src/Monitoring is the code a server and a hosting account share; each driver plugs in through the Guest adapter on the
 * filter beltoft_services_monitoring_guests. So nothing in the folder may name a driver namespace, and both drivers have
 * an adapter that implements Guest.
 */
class MonitoringNeutralTest extends TestCase {

	public function test_the_monitoring_folder_names_no_driver_namespace(): void {
		$bad = array();
		$it  = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( dirname( __DIR__, 2 ) . '/src/Monitoring', \FilesystemIterator::SKIP_DOTS ) );

		foreach ( $it as $file ) {
			if ( 'php' !== $file->getExtension() ) {
				continue;
			}

			foreach ( token_get_all( (string) file_get_contents( $file->getPathname() ) ) as $token ) {
				if ( is_array( $token ) && in_array( $token[0], array( T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED ), true ) ) {
					$name = ltrim( $token[1], '\\' );

					if ( 1 === preg_match( '/^BeltoftServices\\\\(Proxmox|Hosting|Mcp|Account|Apps|Domains|Mail|Ssl)\\\\/', $name ) ) {
						$bad[] = $file->getFilename() . ' -> ' . $name;
					}
				}
			}
		}

		$this->assertSame( array(), $bad );
	}

	public function test_each_driver_has_an_adapter_that_implements_guest(): void {
		$this->assertInstanceOf( \BeltoftServices\Monitoring\Guest::class, new \BeltoftServices\Proxmox\Network\VmGuest() );
		$this->assertInstanceOf( \BeltoftServices\Monitoring\Guest::class, new \BeltoftServices\Hosting\HostingGuest() );
	}
}

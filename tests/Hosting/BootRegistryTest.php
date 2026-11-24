<?php
/**
 * The hosting Boot class names the registers of its stages (moved here from Structure\BootRegistryTest).
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Hosting;

use BeltoftServices\Tests\Support\Scan;
use PHPUnit\Framework\TestCase;

/**
 * A Boot class is read like Plugin::boot(): by the register() calls in its file.
 */
class BootRegistryTest extends TestCase {

	public function test_hosting_boot_names_its_registers_and_the_boot_file_registers_it(): void {
		$boot = (string) file_get_contents( Scan::root() . '/src/Hosting/Boot.php' );

		$this->assertStringContainsString( 'HostingSettings::register()', $boot );
		$this->assertStringContainsString( 'Actions\\Steps::register()', $boot );
		$this->assertStringContainsString( 'Hosting\\Boot::register()', (string) file_get_contents( Scan::boot_file() ) );
	}
}

<?php
/**
 * The hosting driver sits where it sat in the registry (tests/RegistryTest.php pins the rest of the order).
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Hosting;

use BeltoftServices\Drivers\Registry;
use BeltoftServices\Hosting\HostingDriver;
use BeltoftServices\Tests\TestCase;

/**
 * A driver missing from the registry strands a paying customer's service.
 */
final class DriverOrderTest extends TestCase {

	public function test_the_hosting_driver_comes_right_after_the_licence_key_driver(): void {
		$ids = array_keys( Registry::all() );

		$this->assertSame( 'hosting', $ids[ array_search( 'licence-key', $ids, true ) + 1 ] );
		$this->assertInstanceOf( HostingDriver::class, Registry::get( 'hosting' ) );
		$this->assertSame( 'hosting', HostingDriver::ID );

		$kinds = Registry::kinds();
		$this->assertSame( 'hosting', $kinds[ array_search( 'lickey', $kinds, true ) + 1 ] );
	}
}

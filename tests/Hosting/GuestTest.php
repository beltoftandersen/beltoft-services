<?php
/**
 * The hosting adapter of the shared monitoring code (moved here from Structure\MonitoringNeutralTest).
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Hosting;

use PHPUnit\Framework\TestCase;

/**
 * Each driver plugs in through the Guest adapter.
 */
class GuestTest extends TestCase {

	public function test_hosting_has_an_adapter_that_implements_guest(): void {
		$this->assertInstanceOf( \BeltoftServices\Monitoring\Guest::class, new \BeltoftServices\Hosting\HostingGuest() );
	}
}

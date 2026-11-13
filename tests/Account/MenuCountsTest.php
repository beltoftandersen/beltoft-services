<?php
/**
 * The number of services on each My Account menu item.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Account;

use BeltoftServices\Account\MenuCounts;
use BeltoftServices\Tests\TestCase;
use BeltoftLicenses\Licenses;

final class MenuCountsTest extends TestCase {

	public function test_active_and_paused_services_count_and_ended_ones_do_not(): void {
		$user    = self::factory()->user->create();
		$product = $this->service_product();
		$ids     = array();

		foreach ( array( 'active', 'suspended', 'expired', 'revoked' ) as $status ) {
			$row   = $this->service_license( $product, array( 'fire' => false, 'user_id' => $user ) );
			$ids[] = (int) $row['id'];
			Licenses::update( (int) $row['id'], array( 'status' => $status ) );
		}

		MenuCounts::reset();
		$counts = MenuCounts::of( $user );

		$this->assertSame( array( 2 ), array_values( $counts ), 'Active and suspended count, expired and revoked do not.' );
		$this->assertSame( array(), MenuCounts::of( 0 ) );
	}
}

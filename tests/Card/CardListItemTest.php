<?php
/**
 * A saved method without an expiry date shows no expiry text.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Card;

use BeltoftServices\Card\Card;
use BeltoftServices\Hosting\Config;
use BeltoftServices\Tests\TestCase;

/**
 * Tests.
 */
class CardListItemTest extends TestCase {

	public function test_zero_expiry_is_emptied(): void {
		$this->assertSame( '', Card::list_item( array( 'expires' => '00 / ' ) )['expires'] );
		$this->assertSame( '', Card::list_item( array( 'expires' => '0 / 0' ) )['expires'] );
	}

	public function test_real_expiry_is_kept(): void {
		$this->assertSame( '08 / 2028', Card::list_item( array( 'expires' => '08 / 2028' ) )['expires'] );
		$this->assertSame( array( 'method' => 'x' ), Card::list_item( array( 'method' => 'x' ) ) );
	}

	public function test_hosting_label_has_a_filter(): void {
		$this->settings( array( 'hosting_label' => 'Webhotel' ) );
		add_filter( 'beltoft_services_hosting_label', static fn() => 'Web hosting' );
		$this->assertSame( 'Web hosting', Config::label() );
	}
}

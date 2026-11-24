<?php
/**
 * A saved method without an expiry date shows no expiry text.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Card;

use BeltoftServices\Card\Card;
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

	public function test_expires_cell_marks_an_empty_one(): void {
		ob_start();
		Card::expires_cell( array( 'expires' => '' ) );
		$this->assertStringContainsString( 'beltoft-services-noexpiry', (string) ob_get_clean() );
		ob_start();
		Card::expires_cell( array( 'expires' => '08 / 2028' ) );
		$this->assertSame( '08 / 2028', ob_get_clean() );
	}
}

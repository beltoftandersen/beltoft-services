<?php
/**
 * The action that tells the site a customer's credit currency is fixed by the first credit row.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Credit;

use BeltoftServices\Credit\Balance;
use BeltoftServices\Credit\Grant;

require_once __DIR__ . '/CreditTestCase.php';

class CreditCurrencySetTest extends CreditTestCase {

	/** @var array<int, array{0: int, 1: string}> */
	private array $fired = array();

	public function set_up(): void {
		parent::set_up();
		$this->fired = array();
		add_action( 'beltoft_services_credit_currency_set', array( $this, 'catch_it' ), 10, 2 );
	}

	public function tear_down(): void {
		remove_action( 'beltoft_services_credit_currency_set', array( $this, 'catch_it' ), 10 );
		parent::tear_down();
	}

	public function catch_it( $user, $currency ): void {
		$this->fired[] = array( (int) $user, (string) $currency );
	}

	public function test_a_top_up_fires_it_once_with_the_currency_and_not_on_the_second_row(): void {
		$user = self::factory()->user->create();

		$this->assertTrue( Balance::credit( $user, 1000, Balance::TOPUP, 'order', '1' ) );
		$this->assertSame( array( array( $user, 'EUR' ) ), $this->fired );

		$this->assertTrue( Balance::credit( $user, 500, Balance::TOPUP, 'order', '2' ) );
		$this->assertCount( 1, $this->fired );
	}

	public function test_a_repeated_reference_and_a_refused_currency_do_not_fire_it(): void {
		$user = self::factory()->user->create();
		Balance::credit( $user, 1000, Balance::TOPUP, 'order', '1' );
		$this->fired = array();

		$this->assertFalse( Balance::credit( $user, 1000, Balance::TOPUP, 'order', '1' ) );
		$this->assertFalse( Balance::credit( $user, 1000, Balance::TOPUP, 'order', '3', '', 'DKK' ) );
		$this->assertSame( array(), $this->fired );

		$other = self::factory()->user->create();
		$this->assertFalse( Balance::credit( $other, 1000, Balance::TOPUP, 'order', '4', '', 'xx' ) );
		$this->assertSame( array(), $this->fired );
	}

	public function test_an_admin_grant_fires_it(): void {
		$user  = self::factory()->user->create();
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );

		$this->assertTrue( Grant::add( $user, 2500, 'Goodwill', $admin, 'req-1' ) );
		$this->assertSame( array( array( $user, 'EUR' ) ), $this->fired );

		Grant::add( $user, 100, 'More', $admin, 'req-2' );
		$this->assertCount( 1, $this->fired );
	}
}

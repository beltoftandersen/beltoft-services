<?php
/**
 * Top-up limits in a second currency (through the plugin's filters, with no add-on).
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Credit;

use BeltoftServices\Credit\Page;
use BeltoftServices\Credit\Topup;
use BeltoftServices\Tests\Support\FakeCurrency;

require_once __DIR__ . '/CreditTestCase.php';

/**
 * No amount for a currency means no top-ups in it; the euro limits are never reused.
 */
class TopupCurrencyTest extends CreditTestCase {

	use FakeCurrency;

	public function set_up(): void {
		parent::set_up();
		update_option( 'woocommerce_currency', 'EUR' );
	}

	public function tear_down(): void {
		$this->fake_currency_down();
		remove_all_filters( 'beltoft_services_credit_amounts' );
		parent::tear_down();
	}

	public function test_the_base_currency_is_unchanged(): void {
		$this->settings( array( 'credit_min' => 20, 'credit_max' => 60 ) );

		$this->assertTrue( Topup::available() );
		$this->assertSame( 20, Topup::min() );
		$this->assertSame( 60, Topup::max() );
		$this->assertSame( array( 25, 50 ), Topup::amounts() );
		$this->assertSame( array( 25, 50 ), Topup::amounts( 'EUR' ) );
		$this->assertSame( 6000, Topup::parse( '60', 'EUR' ) );
	}

	public function test_a_currency_with_no_limits_has_no_top_ups(): void {
		$this->assertFalse( Topup::available( 'DKK' ) );
		$this->assertSame( 0, Topup::min( 'DKK' ) );
		$this->assertSame( 0, Topup::max( 'DKK' ) );
		$this->assertSame( array(), Topup::amounts( 'DKK' ) );
		$this->assertNull( Topup::parse( '50', 'DKK' ), 'The euro limits are not reused.' );
	}

	public function test_a_currency_with_limits_starts_with_no_fixed_amounts_and_takes_a_list_from_the_filter(): void {
		$this->fake_amounts( array( 'DKK' => array( 'credit_min' => 100, 'credit_max' => 2000 ) ) );

		$this->assertTrue( Topup::available( 'DKK' ) );
		$this->assertSame( array(), Topup::amounts( 'DKK' ) );
		$this->assertSame( 25000, Topup::parse( '250', 'DKK' ) );
		$this->assertNull( Topup::parse( '99', 'DKK' ) );
		$this->assertNull( Topup::parse( '2001', 'DKK' ) );

		$seen = array();
		add_filter(
			'beltoft_services_credit_amounts',
			function ( $amounts, $currency ) use ( &$seen ) {
				$seen[] = $currency;
				return 'DKK' === $currency ? array( 100, 500, 5000 ) : $amounts;
			},
			10,
			2
		);

		$this->assertSame( array( 100, 500 ), Topup::amounts( 'DKK' ) );
		$this->assertSame( array( 10, 25, 50, 100, 250 ), Topup::amounts() );
		$this->assertSame( array( 'DKK', 'EUR' ), $seen );
	}

	public function test_the_page_reports_whether_top_ups_are_sold_in_the_current_currency(): void {
		$this->fake_currency_up( 'DKK' );

		$this->assertFalse( Page::data()['available'] );

		$this->fake_set_currency( 'EUR' );
		$this->assertTrue( Page::data()['available'] );
	}
}

<?php
/**
 * The cost guards in a second currency: converted at the guard rate for the comparison only, refused without one.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Billing;

use BeltoftServices\Currency;
use BeltoftServices\Domains\Prices;
use BeltoftServices\Domains\SpendGuard;
use BeltoftServices\Licences\KeyOrder;
use BeltoftServices\Ssl\SslCatalog;
use BeltoftServices\Ssl\SslGuard;
use BeltoftServices\Tests\Support\FakeCurrency;
use BeltoftServices\Tests\TestCase;

/**
 * Money invariant: never spend at a supplier for less than was paid.
 */
class CostGuardCurrencyTest extends TestCase {

	use FakeCurrency;

	/** @var string[] Subjects of admin mails. */
	private array $sent = array();

	public function set_up(): void {
		parent::set_up();
		update_option( 'woocommerce_currency', 'EUR' );
		$this->settings( array( 'admin_email' => 'christian.beltoft@gmail.com', 'currency_guard_rates' => '' ) );
		delete_transient( 'beltoft_services_guard_rate_dkk' );
		delete_transient( 'beltoft_services_guard_rate_usd' );
		$this->sent = array();
		add_filter( 'pre_wp_mail', array( $this, 'catch_mail' ), 10, 2 );
		update_option( Prices::OPTION, array( 'com' => array( 'create' => 8.0, 'renew' => 8.0, 'transfer' => 8.0, 'restricted' => false, 'price' => 12 ) ) );
		update_option( SslCatalog::OPTION, array( 41 => array( 'cost_1y' => 8.68 ) ) );
	}

	public function tear_down(): void {
		remove_filter( 'pre_wp_mail', array( $this, 'catch_mail' ), 10 );
		$this->fake_currency_down();
		parent::tear_down();
	}

	/**
	 * Records and stops a mail.
	 *
	 * @param mixed $pre  Short circuit.
	 * @param array $atts Mail.
	 */
	public function catch_mail( $pre, $atts ) {
		$this->sent[] = (string) $atts['subject'];

		return true;
	}

	/** A paid order line of an amount in a currency. */
	private function line( float $total, string $currency ): \WC_Order_Item_Product {
		$product = new \WC_Product_Simple();
		$product->set_name( 'Paid' );
		$product->set_regular_price( '1' );
		$product->save();
		$order = wc_create_order();
		$order->set_currency( $currency );
		$order->add_product( $product, 1, array( 'subtotal' => $total, 'total' => $total ) );
		$order->save();

		return array_values( $order->get_items() )[0];
	}

	public function test_a_euro_order_is_compared_as_it_always_was(): void {
		$this->assertNull( SpendGuard::problem( 'a.com', 'create', $this->line( 8.0, 'EUR' ) ) );
		$this->assertNull( SpendGuard::problem( 'a.com', 'create', $this->line( 7.995, 'EUR' ) ) );
		$this->assertSame( SpendGuard::MESSAGE, SpendGuard::problem( 'a.com', 'create', $this->line( 7.5, 'EUR' ) ) );
	}

	public function test_a_dkk_order_with_no_guard_rate_is_refused_with_the_old_message_and_one_alert_per_currency(): void {
		$msg = 'The order is not in EUR; the registrar cost cannot be compared.';

		$this->assertSame( $msg, SpendGuard::problem( 'a.com', 'create', $this->line( 5000.0, 'DKK' ) ), 'Never assumed 1 to 1.' );
		$this->assertSame( $msg, SpendGuard::problem( 'a.com', 'create', $this->line( 5000.0, 'DKK' ) ) );
		$this->assertSame( 1, count( array_filter( $this->sent, static fn( $s ) => false !== strpos( $s, 'No guard rate for DKK' ) ) ) );
		$this->assertSame( $msg, SpendGuard::problem( 'a.com', 'create', $this->line( 5000.0, 'USD' ) ), 'A USD order still fails as today.' );
	}

	public function test_a_dkk_order_is_converted_at_the_guard_rate_for_the_comparison(): void {
		$this->settings( array( 'currency_guard_rates' => "DKK=7.60\nSEK = 11,5" ) );
		$cost_dkk = 8.0 * 7.6; // 60.80.

		$this->assertSame( SpendGuard::MESSAGE, SpendGuard::problem( 'a.com', 'create', $this->line( $cost_dkk - 0.5, 'DKK' ) ), 'Under the cost at the guard rate: refused, no matter what it looks like in kroner.' );
		$this->assertNull( SpendGuard::problem( 'a.com', 'create', $this->line( $cost_dkk, 'DKK' ) ) );
		$this->assertNull( SpendGuard::problem( 'a.com', 'create', $this->line( $cost_dkk + 20, 'DKK' ) ) );
		$this->assertSame( 7.6, Currency::guard_rate( 'dkk' ) );
		$this->assertSame( 11.5, Currency::guard_rate( 'SEK' ), 'A comma is accepted.' );
		$this->assertNull( Currency::guard_rate( 'NOK' ) );
		$this->assertEqualsWithDelta( 10.0, Currency::to_cost( 76.0, 'DKK' ), 0.0001 );
		$this->assertSame( 8.0, Currency::to_cost( 8.0, 'EUR' ) );
	}

	public function test_the_guard_rate_can_come_from_a_filter(): void {
		$f = static fn( $rate, $code ) => 'DKK' === $code ? 7.5 : $rate;
		add_filter( 'beltoft_services_guard_rate', $f, 10, 2 );

		$this->assertNull( SpendGuard::problem( 'a.com', 'create', $this->line( 60.0, 'DKK' ) ) );

		remove_filter( 'beltoft_services_guard_rate', $f, 10 );
	}

	public function test_the_certificate_guard_follows_the_same_rule(): void {
		$this->assertNull( SslGuard::problem( 41, $this->line( 8.68, 'EUR' ) ) );
		$this->assertStringContainsString( 'not in EUR', (string) SslGuard::problem( 41, $this->line( 700.0, 'DKK' ) ) );

		$this->settings( array( 'currency_guard_rates' => 'DKK=7.60' ) );
		$cost = 8.68 * 7.6;

		$this->assertSame( SslGuard::MESSAGE, SslGuard::problem( 41, $this->line( $cost - 1, 'DKK' ) ) );
		$this->assertNull( SslGuard::problem( 41, $this->line( $cost, 'DKK' ) ) );
	}

	public function test_a_licence_switch_price_in_another_currency_needs_a_guard_rate(): void {
		$this->settings( array( 'licence_costs' => 'pro=10' ) );
		$switch = new \ReflectionMethod( KeyOrder::class, 'switch_problem' );
		$switch->setAccessible( true );
		$v = new \WC_Product_Simple();
		$v->set_regular_price( '90' );
		$v->save();
		$this->fake_currency_up( 'EUR' );
		$this->fake_set_currency( 'DKK' );
		update_post_meta( $v->get_id(), '_test_price_DKK', '90' );

		$this->assertNotNull( $switch->invoke( null, 'pro', wc_get_product( $v->get_id() ) ), 'No guard rate for DKK: refused.' );

		$this->settings( array( 'currency_guard_rates' => 'DKK=7.60' ) );
		$this->assertNull( $switch->invoke( null, 'pro', wc_get_product( $v->get_id() ) ), '90 DKK is 11.84 euro at the guard rate, over the cost 10.' );

		update_post_meta( $v->get_id(), '_test_price_DKK', '70' );
		$this->assertNotNull( $switch->invoke( null, 'pro', wc_get_product( $v->get_id() ) ), '70 DKK is 9.21 euro: under.' );

		$this->fake_set_currency( 'EUR' );
		$this->assertNull( $switch->invoke( null, 'pro', wc_get_product( $v->get_id() ) ), 'In the shop currency the price is compared as it is (90 is over 10).' );
	}
}

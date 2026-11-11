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
		$this->settings( array( 'currency_guard_rates' => "DKK=7.60\nSEK = 11.5" ) );
		$cost_dkk = 8.0 * 7.6; // 60.80.

		$this->assertSame( SpendGuard::MESSAGE, SpendGuard::problem( 'a.com', 'create', $this->line( $cost_dkk - 0.5, 'DKK' ) ), 'Under the cost at the guard rate: refused, no matter what it looks like in kroner.' );
		$this->assertNull( SpendGuard::problem( 'a.com', 'create', $this->line( $cost_dkk, 'DKK' ) ) );
		$this->assertNull( SpendGuard::problem( 'a.com', 'create', $this->line( $cost_dkk + 20, 'DKK' ) ) );
		$this->assertSame( 7.6, Currency::guard_rate( 'dkk' ) );
		$this->assertSame( 11.5, Currency::guard_rate( 'SEK' ), 'Spaces around the equals sign are accepted.' );
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

	public function test_a_comma_is_not_a_decimal_point_and_a_bad_line_is_no_rate(): void {
		foreach ( array( 'DKK=1,000', 'DKK=7,60', 'DKK = 7.6.1', 'DKK=abc', 'DKK=7.60 EUR' ) as $text ) {
			$this->settings( array( 'currency_guard_rates' => $text ) );

			$this->assertNull( Currency::guard_rate( 'DKK' ), $text );
			$this->assertSame( array( $text ), Currency::parse_guard_rates( $text )['bad'], $text );
			$this->assertNull( Currency::to_cost( 119.0, 'DKK' ), $text );
		}

		$this->settings( array( 'currency_guard_rates' => " dkk = 7.60 \n\nSEK=11.5" ) );
		$this->assertSame( 7.6, Currency::guard_rate( 'DKK' ) );
		$this->assertSame( 11.5, Currency::guard_rate( 'SEK' ) );
	}

	public function test_a_rate_outside_the_bounds_needs_the_filter_to_allow_it(): void {
		$this->settings( array( 'currency_guard_rates' => "GBP=0.87\nIDR=17500\nDKK=7.60" ) );

		$this->assertNull( Currency::guard_rate( 'GBP' ), 'Below 1.' );
		$this->assertNull( Currency::guard_rate( 'IDR' ), 'Above 1000.' );
		$this->assertSame( 7.6, Currency::guard_rate( 'DKK' ) );

		add_filter( 'beltoft_services_guard_rate_bounds', static fn( $b, $code ) => 'GBP' === $code ? array( 0.5, 1.0 ) : $b, 10, 2 );
		$this->assertSame( 0.87, Currency::guard_rate( 'GBP' ) );
		$this->assertNull( Currency::guard_rate( 'IDR' ) );
		remove_all_filters( 'beltoft_services_guard_rate_bounds' );
	}

	public function test_saving_a_bad_guard_rate_line_keeps_the_old_value_and_says_so_on_the_screen(): void {
		$this->settings( array( 'currency_guard_rates' => 'DKK=7.60' ) );
		global $wp_settings_errors;
		$wp_settings_errors = array();

		$out = \BeltoftServices\Settings::sanitize( array( 'currency_guard_rates' => "DKK=1,000\nSEK=11.5" ) );

		$this->assertSame( 'DKK=7.60', $out['currency_guard_rates'] );
		$codes = array_column( (array) $wp_settings_errors, 'code' );
		$this->assertContains( 'beltoft_services_guard_rates', $codes );

		$wp_settings_errors = array();
		$out                = \BeltoftServices\Settings::sanitize( array( 'currency_guard_rates' => "DKK=7.55\nSEK=11.5" ) );
		$this->assertSame( "DKK=7.55\nSEK=11.5", $out['currency_guard_rates'] );
		$this->assertSame( array(), (array) $wp_settings_errors );
	}

	/**
	 * Puts a product into the cart without the add to cart checks (the checks of a field belong to other tests).
	 */
	private function cart_with( \WC_Product $product ): void {
		if ( null === WC()->cart ) {
			wc_load_cart();
		}

		WC()->cart->empty_cart();
		WC()->cart->cart_contents['k' . $product->get_id()] = array( 'product_id' => $product->get_id(), 'variation_id' => 0, 'quantity' => 1, 'data' => $product );
	}

	/** @return array<string, array{0: string}> */
	public function supplier_drivers(): array {
		return array( 'domain' => array( 'domain' ), 'ssl' => array( 'ssl' ), 'licence key' => array( 'licence-key' ) );
	}

	/**
	 * @dataProvider supplier_drivers
	 */
	public function test_a_cart_in_a_currency_with_no_guard_rate_is_refused_before_payment_with_one_alert_per_currency( string $driver ): void {
		$this->fake_currency_up( 'EUR' );
		$this->cart_with( $this->service_product( $driver, array(), array( 'slug' => 'guard-' . $driver ) ) );
		$this->fake_set_currency( 'DKK' );
		wc_clear_notices();

		\BeltoftServices\Billing\CostGuardCart::cart();
		\BeltoftServices\Billing\CostGuardCart::cart();

		$this->assertCount( 2, wc_get_notices( 'error' ) );
		$this->assertStringContainsString( 'DKK', wc_get_notices( 'error' )[0]['notice'] );
		$errors = new \WP_Error();
		\BeltoftServices\Billing\CostGuardCart::checkout( array(), $errors );
		$this->assertSame( array( 'beltoft_services_cost_guard' ), $errors->get_error_codes() );
		$this->assertCount( 1, array_filter( $this->sent, static fn( $s ) => false !== strpos( $s, 'No guard rate for DKK' ) ), 'One alert per currency.' );
	}

	/**
	 * @dataProvider supplier_drivers
	 */
	public function test_a_cart_with_a_guard_rate_or_in_the_base_currency_or_without_a_supplier_driver_is_untouched( string $driver ): void {
		$this->fake_currency_up( 'EUR' );
		$product = $this->service_product( $driver, array(), array( 'slug' => 'guard-ok-' . $driver ) );
		$this->cart_with( $product );

		// Base currency.
		$this->assertSame( '', \BeltoftServices\Billing\CostGuardCart::problem() );

		// A guard rate.
		$this->fake_set_currency( 'DKK' );
		$this->settings( array( 'currency_guard_rates' => 'DKK=7.60' ) );
		$this->assertSame( '', \BeltoftServices\Billing\CostGuardCart::problem() );

		// A product with no supplier cost, in a currency with no rate.
		$this->settings( array( 'currency_guard_rates' => '' ) );
		$this->cart_with( $this->service_product( 'fake', array(), array( 'slug' => 'guard-fake-' . $driver ) ) );
		$this->assertSame( '', \BeltoftServices\Billing\CostGuardCart::problem() );
		$this->assertSame( array(), $this->sent );
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

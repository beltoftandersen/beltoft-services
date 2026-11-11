<?php
/** A subscription is switched in its own currency. @package BeltoftServices */
namespace BeltoftServices\Tests\Billing;

use BeltoftServices\Billing\SwitchCurrency;
use BeltoftServices\Tests\Support\FakeCurrency;
use BeltoftServices\Tests\Usage\UsageTestCase;
use BeltoftServices\Usage\Rates;

require_once dirname( __DIR__ ) . '/Usage/UsageTestCase.php';

class SwitchCurrencyTest extends UsageTestCase {

	use FakeCurrency;

	public function tear_down(): void {
		$this->fake_currency_down();
		wc_clear_notices();
		parent::tear_down();
	}

	public function test_a_switch_of_a_subscription_in_another_currency_than_the_cart_is_refused_once(): void {
		update_option( 'woocommerce_currency', 'EUR' );
		$sub = $this->usage_account( $this->new_user() );
		$sub->set_currency( 'DKK' );
		$sub->save();
		$this->fake_currency_up( 'EUR' );
		$flag = new \ReflectionProperty( SwitchCurrency::class, 'noticed' );
		$flag->setAccessible( true );
		$flag->setValue( null, false );
		wc_clear_notices();

		// A view of the subscription: refused, no notice.
		$this->assertFalse( SwitchCurrency::can_switch( true, null, $sub ) );
		$this->assertCount( 0, wc_get_notices( 'notice' ) );

		// A switch being added to the cart: refused, one notice.
		$_GET['switch-subscription'] = (string) $sub->get_id();
		$this->assertFalse( SwitchCurrency::can_switch( true, null, $sub ) );
		$this->assertFalse( SwitchCurrency::can_switch( true, null, $sub ) );
		unset( $_GET['switch-subscription'] );
		$this->assertCount( 1, wc_get_notices( 'notice' ) );

		$this->fake_set_currency( 'DKK' );
		$this->assertTrue( SwitchCurrency::can_switch( true, null, $sub ), 'In its own currency it is as it was.' );
		$this->assertFalse( SwitchCurrency::can_switch( false, null, $sub ), 'Never turns a refusal into a yes.' );
		$this->assertNotFalse( has_filter( 'woocommerce_subscriptions_can_item_be_switched', array( SwitchCurrency::class, 'can_switch' ) ) );
	}

	public function test_the_theme_function_gives_the_rate_and_the_cap_in_the_cart_display(): void {
		update_option( 'woocommerce_currency', 'EUR' );
		update_option( 'woocommerce_calc_taxes', 'yes' );
		update_option( 'woocommerce_tax_display_cart', 'incl' );
		update_option( 'woocommerce_tax_display_shop', 'excl' );
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- test fixture.
		$wpdb->query( "DELETE FROM {$wpdb->prefix}woocommerce_tax_rates" );
		\WC_Tax::_insert_tax_rate( array( 'tax_rate_country' => '', 'tax_rate_state' => '', 'tax_rate' => '25.0000', 'tax_rate_name' => 'VAT', 'tax_rate_priority' => 1, 'tax_rate_compound' => 0, 'tax_rate_shipping' => 0, 'tax_rate_order' => 1, 'tax_rate_class' => '' ) );
		\WC_Cache_Helper::invalidate_cache_group( 'taxes' );
		$p = $this->usage_product()['u-None'];

		$this->assertSame( '€0.0283', Rates::in_cart( 0.02260274, 'hourly', $p ) );
		$this->assertSame( '€20.63', Rates::in_cart( 16.5, 'cap', $p ) );
		$this->assertSame( '€16.50', Rates::in_cart( 16.5, 'cap', null ), 'No product: as given.' );
		$this->assertSame( '€20.63', Rates::in_cart( 16.5, 'cap', $p, true ) );

		update_option( 'woocommerce_tax_display_cart', 'excl' );
		$this->assertSame( '€16.50', Rates::in_cart( 16.5, 'cap', $p ), 'The cart display, not the shop display.' );

		update_option( 'woocommerce_tax_display_cart', 'incl' );
		$f = static fn( $html, $a, $c, $context, $render ) => $render( 'excl' ) . ' | ' . $render( 'incl' );
		add_filter( 'beltoft_services_price_html', $f, 10, 5 );
		$this->assertSame( '€16.50 | €20.63', Rates::in_cart( 16.5, 'cap', $p, true ) );
		remove_filter( 'beltoft_services_price_html', $f, 10 );
	}
}

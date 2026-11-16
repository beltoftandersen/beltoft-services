<?php
/**
 * The checkout rows and forecast, the credit page hooks and the top-up limits of gift credit: one test per public method.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\GiftCredit;

use Bgcw\GiftCard\Source;
use BeltoftServices\Billing\CartFlow;
use BeltoftServices\GiftCredit\Checkout;
use BeltoftServices\GiftCredit\Page;
use BeltoftServices\GiftCredit\Topup;

require_once __DIR__ . '/GiftCreditTestCase.php';

class CheckoutPageTopupTest extends GiftCreditTestCase {

	public function set_up(): void {
		parent::set_up();
		Checkout::reset();
		add_filter( 'bgca_auto_apply', '__return_true', 99 );
	}

	public function tear_down(): void {
		remove_filter( 'bgca_auto_apply', '__return_true', 99 );
		remove_all_filters( 'bgca_topup_limits' );
		Checkout::reset();

		if ( function_exists( 'WC' ) && WC()->cart ) {
			WC()->cart->empty_cart();
		}

		parent::tear_down();
	}

	/**
	 * A cart of one product of 10 and a customer with a card.
	 */
	private function cart_with_card( int $cents, string $source = Source::ORDER ): int {
		$user = $this->new_user();
		wp_set_current_user( $user );
		wc_load_cart();
		WC()->cart->empty_cart();
		$this->card( $user, $cents, $source );
		WC()->cart->add_to_cart( $this->product( false, '10' )->get_id() );
		WC()->cart->calculate_totals();

		return $user;
	}

	// Topup.

	public function test_topup_limits_follow_the_add_on_and_are_zero_when_not_sold(): void {
		$this->assertFalse( Topup::is_product( 0 ) );

		update_option( 'bgca_options', array( 'topup_enabled' => 1, 'topup_min' => 5, 'topup_max' => 500 ) );
		$this->assertTrue( Topup::available() );
		$this->assertSame( 5, Topup::min() );
		$this->assertSame( 500, Topup::max() );

		add_filter( 'bgca_topup_limits', static fn( $l, $currency ) => 'DKK' === $currency ? array( 'min' => 50, 'max' => 4000 ) : $l, 10, 2 );
		$this->assertSame( 50, Topup::min( 'DKK' ) );
		$this->assertSame( 4000, Topup::max( 'DKK' ) );

		remove_all_filters( 'bgca_topup_limits' );
		add_filter( 'bgca_topup_limits', '__return_null' );
		$this->assertFalse( Topup::available( 'USD' ) );
		$this->assertSame( 0, Topup::min( 'USD' ) );
		$this->assertSame( 0, Topup::max( 'USD' ) );

		remove_all_filters( 'bgca_topup_limits' );
		update_option( 'bgca_options', array( 'topup_enabled' => 0 ) );
		$this->assertFalse( Topup::available() );
		$this->assertSame( 0, Topup::min() );

		update_option( 'bgca_options', array( 'topup_enabled' => 1 ) );
		$this->settings( array( 'gift_credit' => 0 ) );
		$this->assertFalse( Topup::available(), 'Not while gift credit is off.' );
	}

	// Checkout.

	public function test_the_forecast_splits_a_partly_covered_cart_and_a_covered_one(): void {
		$this->cart_with_card( 400 );
		$p = Checkout::preview();
		$this->assertSame( array( 400, 0, 400, 600, 1000, 'EUR' ), array( $p['credit'], $p['free'], $p['paid'], $p['due'], $p['total'], $p['currency'] ) );
		$this->assertSame( $p, Checkout::preview(), 'The same forecast again in one request.' );

	}

	public function test_cards_that_cover_the_cart_leave_nothing_to_pay(): void {
		$this->cart_with_card( 5000 );
		$p = Checkout::preview();
		$this->assertSame( 0, $p['due'] );
		$this->assertSame( 1000, $p['credit'] );
	}

	public function test_there_is_no_forecast_for_a_guest_an_empty_cart_a_renewal_a_top_up_or_no_card(): void {
		$user = $this->cart_with_card( 400 );
		wp_set_current_user( 0 );
		$this->assertNull( Checkout::preview(), 'A guest.' );
		wp_set_current_user( $user );

		$this->assertNotNull( Checkout::preview() );
		WC()->cart->empty_cart();
		$this->assertNull( Checkout::preview(), 'An empty cart.' );

		WC()->cart->add_to_cart( $this->product( false, '10' )->get_id() );
		foreach ( WC()->cart->get_cart() as $key => $item ) {
			WC()->cart->cart_contents[ $key ][ CartFlow::RENEWAL ] = 1;
		}
		Checkout::reset();
		$this->assertNull( Checkout::preview(), 'A renewal is paid by its own rules.' );

		WC()->cart->empty_cart();
		$other = $this->new_user();
		wp_set_current_user( $other );
		WC()->cart->add_to_cart( $this->product( false, '10' )->get_id() );
		WC()->cart->calculate_totals();
		Checkout::reset();
		$this->assertNull( Checkout::preview(), 'A customer without cards.' );

		add_filter( 'bgca_auto_apply', '__return_false', 100 );
		$this->card( $other, 400 );
		Checkout::reset();
		$this->assertNull( Checkout::preview(), 'Cards that the add-on does not apply.' );
		remove_filter( 'bgca_auto_apply', '__return_false', 100 );
	}

	/**
	 * The checkout defines WOOCOMMERCE_CHECKOUT for good: a process of its own keeps it from the other tests.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_the_checkout_page_shows_the_credit_row_and_the_total_after_the_cards(): void {
		$this->cart_with_card( 400 );
		$this->assertSame( '<strong>x</strong>', Checkout::total_html( '<strong>x</strong>' ), 'Not on the checkout page: unchanged.' );

		ob_start();
		Checkout::review_row();
		$this->assertSame( '', ob_get_clean(), 'No row off the checkout page.' );

		define_if_missing( 'WOOCOMMERCE_CHECKOUT' );
		ob_start();
		Checkout::review_row();
		$row = (string) ob_get_clean();
		$this->assertStringContainsString( 'beltoft-services-credit-row', $row );
		$this->assertStringContainsString( '4.00', $row );

		$html = (string) Checkout::total_html( '<strong>10.00</strong> <small>incl. tax</small>' );
		$this->assertStringContainsString( '6.00', $html );
		$this->assertStringContainsString( '<small>incl. tax</small>', $html, 'The tax note stays.' );
		$this->assertSame( 5, Checkout::total_html( 5 ), 'Not markup: unchanged.' );
	}

	public function test_the_stripe_cart_data_carries_the_total_after_the_cards(): void {
		$this->cart_with_card( 400 );
		$data = Checkout::stripe_cart_data( array( 'total' => 10.0, 'totalCents' => 1000, 'lineItems' => array() ), WC()->cart );
		$this->assertSame( 6.0, $data['total'] );
		$this->assertSame( 600, $data['totalCents'] );
		$this->assertSame( -400, $data['lineItems'][0]['amountCents'] );
		$this->assertSame( 'x', Checkout::stripe_cart_data( 'x', WC()->cart ), 'Not an array: unchanged.' );
	}

	public function test_a_cart_that_saves_a_card_still_asks_the_gateway_after_the_cards_covered_it(): void {
		$this->cart_with_card( 5000 );
		$this->assertFalse( Checkout::gateway_saves_card( false ) );
		$this->assertTrue( Checkout::gateway_saves_card( true ) );

		Checkout::applied( new \WC_Order(), 1000, 'renewal' );
		$this->assertFalse( Checkout::gateway_saves_card( false ), 'Only a checkout asks.' );
		Checkout::applied( new \WC_Order(), 0, 'checkout' );
		$this->assertFalse( Checkout::gateway_saves_card( false ), 'No cards used: nothing to keep.' );
	}

	// Page.

	public function test_the_page_hooks_follow_the_switch_and_the_billing_grouping(): void {
		$this->assertSame( 'Credit', Page::title() );
		$this->assertStringContainsString( 'credit', Page::url() );
		$this->assertSame( Page::url(), Page::url_filter( 'other' ) );
		$this->assertSame( array( 'credit' => 'credit' ), Page::query_vars( array() ) );
		$this->assertSame( 'x', Page::query_vars( 'x' ) );

		$menu = Page::menu( array( 'orders' => 'Orders', 'customer-logout' => 'Log out' ) );
		$this->assertSame( array( 'orders', 'credit', 'customer-logout' ), array_keys( $menu ), 'Credit comes before Log out.' );
		$this->assertSame( array( 'credit' => array( 'credit' ) ), Page::billing_set( array() ) );
		$this->assertSame( array( 'credit' => 'Credit' ), Page::billing_label( array() ) );
		$this->assertSame( array( 'gift-cards' => 'x' ), array_diff_key( array( 'gift-cards' => 'x' ), array( 'credit' => 1 ) ) );
		$this->assertArrayNotHasKey( 'gift-cards', Page::no_plugin_page( array( 'gift-cards' => 'Gift cards', 'orders' => 'Orders' ) ) );

		$this->settings( array( 'gift_credit' => 0 ) );
		$this->assertSame( 'other', Page::url_filter( 'other' ) );
		$this->assertSame( array( 'orders' => 'O' ), Page::menu( array( 'orders' => 'O' ) ) );
		$this->assertSame( array(), Page::billing_set( array() ) );
		$this->assertSame( array(), Page::billing_label( array() ) );
		$this->assertArrayHasKey( 'gift-cards', Page::no_plugin_page( array( 'gift-cards' => 'Gift cards' ) ), 'The plugin keeps its own page while gift credit is off.' );
		$this->assertNull( Page::plugin_page_target() );
		$this->assertSame( '', Page::html() );
	}

	public function test_the_page_data_the_flush_and_the_order_names(): void {
		$user = $this->new_user();
		wp_set_current_user( $user );
		$this->card( $user, 1500, Source::ORDER );
		$data = Page::data( $user );
		$this->assertSame( 1500, $data['totals']['total'] );
		$this->assertNull( $data['reserve'], 'No usage: nothing reserved.' );

		delete_option( Page::OPTION_FLUSH );
		Page::maybe_flush();
		$this->assertSame( 'yes', get_option( Page::OPTION_FLUSH ) );

		$names = static function ( array $titles ): string {
			$order = new \WC_Order();
			foreach ( $titles as $t ) {
				$item = new \WC_Order_Item_Product();
				$item->set_name( $t );
				$order->add_item( $item );
			}
			return Page::order_names( $order );
		};
		$this->assertSame( 'A, B', $names( array( 'A', 'B', 'A' ) ) );
		$this->assertSame( 'A, B and 2 more', $names( array( 'A', 'B', 'C', 'D' ) ) );
		$this->assertSame( '', $names( array() ) );
	}
}

/**
 * Defines a constant for the rest of the process unless it exists.
 *
 * @param string $name Constant name.
 */
function define_if_missing( string $name ): void {
	if ( ! defined( $name ) ) {
		define( $name, true ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals -- test helper.
	}
}

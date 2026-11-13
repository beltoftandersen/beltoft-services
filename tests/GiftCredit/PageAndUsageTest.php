<?php
/**
 * The credit page, usage invoices paid with cards, and the checkout forecast.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\GiftCredit;

use Bgca\Account;
use Bgca\TopUp;
use Bgcw\GiftCard\Source;
use BeltoftServices\GiftCredit\Checkout;
use BeltoftServices\GiftCredit\GiftCredit;
use BeltoftServices\GiftCredit\Page;
use BeltoftServices\Usage\Minimum;

require_once __DIR__ . '/GiftCreditTestCase.php';

class PageAndUsageTest extends GiftCreditTestCase {

	public function set_up(): void {
		parent::set_up();
		Checkout::reset();
	}

	public function tear_down(): void {
		Checkout::reset();
		if ( function_exists( 'WC' ) && WC()->cart ) {
			WC()->cart->empty_cart();
		}
		parent::tear_down();
	}

	// The page.

	public function test_the_page_keeps_its_endpoint_and_shows_the_wallet_with_paid_and_free_apart(): void {
		$this->assertSame( 'credit', Page::ENDPOINT );
		$user = $this->new_user();
		wp_set_current_user( $user );
		$this->card( $user, 1500, Source::ORDER );
		$this->card( $user, 400, Source::PROMOTION );

		$items = apply_filters( 'woocommerce_account_menu_items', array( 'dashboard' => 'Dashboard', 'customer-logout' => 'Log out' ) );
		$this->assertArrayHasKey( 'credit', $items );
		$this->assertArrayNotHasKey( 'gift-credit', $items, 'The add-on draws no page of its own.' );
		$this->assertArrayHasKey( 'credit', apply_filters( 'woocommerce_get_query_vars', array() ) );
		$this->assertArrayNotHasKey( 'gift-credit', apply_filters( 'woocommerce_get_query_vars', array() ) );

		$html = Page::html();

		$this->assertStringContainsString( 'beltoft-services-credit', $html, 'The section class.' );
		$this->assertStringContainsString( '19.00', $html );
		$this->assertStringContainsString( '15.00', $html, 'Paid credit.' );
		$this->assertStringContainsString( '4.00', $html, 'Free credit.' );
		$this->assertStringContainsString( 'name="bgca_amount"', $html, 'The top-up form of the add-on.' );
		$this->assertStringNotContainsString( 'name="bgca_code"', $html, 'Codes are typed in the coupon field at checkout.' );
		$this->assertStringContainsString( TopUp::ACTION, $html );
		$this->assertStringContainsString( Page::url(), $html );
	}

	public function test_the_forms_of_the_add_on_come_back_to_the_page(): void {
		$this->assertSame( Page::url(), Account::url() );
		$this->settings( array( 'gift_credit' => 0 ) );
		$this->assertNotSame( Page::url(), Account::url() );
	}

	public function test_codes_in_the_coupon_field_go_to_credit_only_while_gift_credit_is_on(): void {
		$this->assertTrue( apply_filters( 'bgca_coupon_field_attaches', true, 1, 'X' ) );
		$this->settings( array( 'gift_credit' => 0 ) );
		$this->assertFalse( apply_filters( 'bgca_coupon_field_attaches', true, 1, 'X' ) );
	}

	public function test_a_customer_sees_nothing_while_gift_credit_is_off(): void {
		$this->settings( array( 'gift_credit' => 0 ) );
		wp_set_current_user( $this->new_user() );

		$this->assertSame( '', Page::html() );
		$this->assertArrayNotHasKey( 'credit', apply_filters( 'woocommerce_account_menu_items', array( 'customer-logout' => 'Log out' ) ) );
	}

	public function test_the_history_shows_the_cards_transactions(): void {
		$user = $this->new_user();
		wp_set_current_user( $user );
		$this->card( $user, 1000, Source::ORDER, 'EUR', 100 );

		$data = Page::data( $user );

		$this->assertSame( 'EUR', $data['currency'] );
		$this->assertCount( 1, $data['history'] );
		$this->assertSame( 1000, $data['history'][0]['cents'] );
		$this->assertNull( $data['reserve'] );
	}

	// Usage invoices.

	public function test_a_usage_invoice_is_paid_by_cards_the_vat_stays_and_a_covered_one_completes(): void {
		$this->vat( true );
		$user = $this->new_user();
		$sub  = $this->usage_account( $user );
		$this->hours( 501, $user, 100 ); // 2.00 net, 0.50 VAT, 2.50 due.
		$card = $this->card( $user, 1000, Source::ORDER );

		$order = wcs_create_renewal_order( $sub );

		$this->assertSame( array( strtoupper( $card->code ) => 250 ), $this->coupon_lines( $order ) );
		$this->assertEqualsWithDelta( 0.0, (float) $order->get_total(), 0.001 );
		$this->assertEqualsWithDelta( 0.5, (float) $order->get_total_tax(), 0.001, 'The VAT on the usage is unchanged.' );

		$this->assertTrue( \Bgca\Pay::settle( $order ) );
		$order = wc_get_order( $order->get_id() );
		$this->assertTrue( $order->has_status( array( 'processing', 'completed' ) ), 'Got ' . $order->get_status() );
		$this->assertSame( 750, $this->left( $card ), 'Taken once.' );
	}

	public function test_free_cards_pay_a_usage_invoice_without_the_product_box(): void {
		$user = $this->new_user();
		$sub  = $this->usage_account( $user );
		$this->hours( 502, $user, 100 ); // 2.00 due.
		$card = $this->card( $user, 1000, Source::PROMOTION );

		$order = wcs_create_renewal_order( $sub );

		$this->assertSame( array( strtoupper( $card->code ) => 200 ), $this->coupon_lines( $order ) );
		$this->assertEqualsWithDelta( 0.0, (float) $order->get_total(), 0.001 );
		$this->assertEqualsWithDelta( 0.0, (float) $order->get_total_tax(), 0.001, 'Free money on a usage invoice carries no VAT.' );
	}

	public function test_paid_cards_pay_a_usage_invoice_and_its_vat_stays(): void {
		$user = $this->new_user();
		$sub  = $this->usage_account( $user );
		$this->hours( 502, $user, 100 ); // 2.00 due.
		$card = $this->card( $user, 1000, Source::ORDER );

		$order = wcs_create_renewal_order( $sub );

		$this->assertSame( array( strtoupper( $card->code ) => 200 ), $this->coupon_lines( $order ) );
		$this->assertEqualsWithDelta( 0.0, (float) $order->get_total(), 0.001 );
		$this->assertSame( wc_format_decimal( $order->get_total_tax(), 2 ), wc_format_decimal( (float) $order->get_cart_tax(), 2 ), 'A paid card is a payment: the VAT lines are as before.' );
	}

	public function test_the_usage_minimum_counts_the_cards_when_it_decides_to_carry_a_month(): void {
		$this->settings( array( 'usage_minimum' => 5 ) );
		$user = $this->new_user();

		$this->assertTrue( Minimum::carries( 300, $user, 'EUR' ), '3.00 for the card is under the 5.00 minimum.' );

		$this->card( $user, 300, Source::ORDER );
		$this->assertFalse( Minimum::carries( 300, $user, 'EUR' ), 'The cards cover it: nothing is left for the card.' );
		$this->assertTrue( Minimum::carries( 600, $user, 'EUR' ), '3.00 would be left.' );
		$this->assertFalse( Minimum::carries( 900, $user, 'EUR' ), '6.00 would be left, above the minimum.' );
	}

	// The checkout forecast.

	public function test_the_checkout_forecast_uses_the_same_plan_and_the_promotional_box(): void {
		$user = $this->new_user();
		wp_set_current_user( $user );
		wc_load_cart();
		WC()->cart->empty_cart();
		$this->card( $user, 1500, Source::PROMOTION );
		$this->card( $user, 200, Source::ORDER );

		WC()->cart->add_to_cart( $this->product( true, '10' )->get_id() );
		WC()->cart->add_to_cart( $this->product( false, '10' )->get_id() );
		WC()->cart->calculate_totals();

		$this->assertNull( Checkout::preview( WC()->cart ), 'No saved card and nothing in the cart saves one.' );

		add_filter( 'bgca_auto_apply', '__return_true', 99 );
		Checkout::reset();
		$p = Checkout::preview( WC()->cart );
		remove_filter( 'bgca_auto_apply', '__return_true', 99 );

		$this->assertNotNull( $p );
		$this->assertSame( 1000, $p['free'], 'Free credit pays the marked line only.' );
		$this->assertSame( 200, $p['paid'] );
		$this->assertSame( 800, $p['due'] );
		$this->assertSame( 2000, $p['total'] );
	}

	public function test_the_setting_off_gives_no_forecast(): void {
		$user = $this->new_user();
		wp_set_current_user( $user );
		wc_load_cart();
		$this->card( $user, 1500, Source::ORDER );
		WC()->cart->add_to_cart( $this->product( false, '10' )->get_id() );
		WC()->cart->calculate_totals();
		$this->settings( array( 'gift_credit' => 0 ) );

		$this->assertFalse( GiftCredit::active() );
		$this->assertNull( Checkout::preview( WC()->cart ) );
	}
}

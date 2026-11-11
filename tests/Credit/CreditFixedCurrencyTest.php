<?php
/**
 * The first credit of a customer is checked against the currency fixed for them; a blocked top-up is handled once.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Credit;

use BeltoftServices\Credit\Balance;
use BeltoftServices\Credit\Code;
use BeltoftServices\Credit\Page;
use BeltoftServices\Credit\Topup;
use BeltoftServices\Currency;
use BeltoftServices\Locks;
use BeltoftServices\Tests\Support\FakeCurrency;

require_once __DIR__ . '/CreditTestCase.php';

/**
 * Review of slice 6.
 */
class CreditFixedCurrencyTest extends CreditTestCase {

	use FakeCurrency;

	/** @var string[] Subjects of admin mails. */
	private array $sent = array();

	/** @var array<int, int> Whether the balance lock was free when a mail went. */
	private array $lock_free = array();

	private int $watch = 0;

	public function set_up(): void {
		parent::set_up();
		$this->sent      = array();
		$this->lock_free = array();
		add_filter( 'pre_wp_mail', array( $this, 'catch_mail' ), 10, 2 );
	}

	public function tear_down(): void {
		remove_filter( 'pre_wp_mail', array( $this, 'catch_mail' ), 10 );
		$this->fake_currency_down();
		WC()->cart->empty_cart();
		parent::tear_down();
	}

	/**
	 * Records and stops a mail.
	 *
	 * @param mixed $pre  Short circuit.
	 * @param array $atts Mail.
	 */
	public function catch_mail( $pre, $atts ) {
		global $wpdb;
		$this->sent[] = (string) $atts['subject'];

		if ( $this->watch > 0 ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- test read.
			$this->lock_free[] = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT IS_FREE_LOCK( %s )', Locks::balance( $this->watch ) ) );
		}

		return true;
	}

	private function mails( string $text ): int {
		return count( array_filter( $this->sent, static fn( $s ) => false !== strpos( $s, $text ) ) );
	}

	private function dkk_user(): int {
		$u       = $this->new_user();
		$account = $this->usage_account( $u );
		$account->set_currency( 'DKK' );
		$account->save();

		return $u;
	}

	public function test_a_customer_with_a_dkk_usage_account_cannot_get_a_first_credit_in_euro(): void {
		$u = $this->dkk_user();
		$this->watch = $u;

		$this->assertSame( 'DKK', Balance::fixed_currency( $u ) );
		$this->assertFalse( Balance::credit( $u, 500, Balance::PROMO, 'code', 'x1' ), 'The shop currency is not the fixed one.' );
		$this->assertSame( '', Balance::currency( $u ), 'Nothing was written, so the user currency does not flip to EUR.' );
		$this->assertSame( 'DKK', Currency::of_user( $u ) );
		$this->assertSame( 1, $this->mails( 'two currencies' ) );
		$this->assertSame( array( 1 ), $this->lock_free, 'The mail went after the balance lock was released.' );

		$this->assertTrue( Balance::credit( $u, 500, Balance::PROMO, 'code', 'x2', '', 'DKK' ) );
		$this->assertSame( 500, Balance::get_in( $u, 'DKK' ) );
		$this->assertSame( 'DKK', Currency::of_user( $u ) );
	}

	public function test_a_euro_top_up_cannot_be_put_in_the_cart_or_stay_there(): void {
		$u = $this->dkk_user();
		wp_set_current_user( $u );

		$this->assertFalse( Balance::allows( $u, 'EUR' ) );
		$this->assertFalse( Topup::add_to_cart( 2500 ), 'EUR is not the currency of this customer.' );

		// A line that was in the cart before the account got its currency goes at pricing.
		$this->fake_currency_up( 'EUR' );
		WC()->cart->add_to_cart( Topup::product_id(), 1, 0, array(), array( Topup::CART_KEY => 2500 ) );
		Topup::price_lines( WC()->cart );
		$this->assertCount( 0, WC()->cart->get_cart() );
		wc_clear_notices();
	}

	public function test_a_code_in_another_currency_than_the_account_is_refused_with_a_message(): void {
		$u = $this->dkk_user();
		$redeem = new \ReflectionMethod( Code::class, 'redeem_locked' );
		$redeem->setAccessible( true );
		$coupon = new \WC_Coupon();
		$coupon->set_code( 'EURCODE' );
		$coupon->set_discount_type( Code::TYPE );
		$coupon->set_amount( 10 );
		$coupon->save();

		$out = $redeem->invoke( null, $u, new \WC_Coupon( $coupon->get_id() ) );

		$this->assertFalse( $out['ok'] );
		$this->assertStringContainsString( 'another currency', $out['message'] );
	}

	public function test_credit_then_a_usage_account_takes_the_credits_currency(): void {
		$u = $this->new_user();
		Balance::credit( $u, 500, Balance::TOPUP, 'order_item', 'k1' );
		$this->assertSame( 'EUR', Currency::of_user( $u ) );
		$build = new \ReflectionMethod( \BeltoftServices\Card\Card::class, 'build_account' );
		$build->setAccessible( true );
		$this->fake_currency_up( 'EUR' );
		$this->fake_set_currency( 'DKK' );
		$made = null;
		add_action( 'beltoft_services_usage_account_created', static function ( $user, $sub ) use ( &$made ) {
			$made = $sub->get_currency();
		}, 10, 2 );

		$build->invoke( null, $u, static function ( $sub ) {}, 'test' );

		$this->assertSame( 'EUR', $made, 'The account is made in the currency the credit is in, not the page\'s.' );
	}

	public function test_rows_with_an_empty_currency_are_the_shop_currency(): void {
		global $wpdb;
		$u = $this->new_user();
		Balance::credit( $u, 500, Balance::TOPUP, 'order_item', 'e1' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- test write.
		$wpdb->query( 'UPDATE ' . Balance::table() . " SET currency = '' WHERE user_id = " . (int) $u );
		delete_user_meta( $u, Balance::META_CURRENCY );

		$this->assertSame( 'EUR', Balance::currency( $u ), 'An empty value is the shop currency.' );
		$this->assertSame( 500, Balance::get_in( $u, 'EUR' ) );
		$this->assertTrue( Balance::credit( $u, 100, Balance::TOPUP, 'order_item', 'e2', '', 'EUR' ) );
		$this->assertFalse( Balance::credit( $u, 100, Balance::TOPUP, 'order_item', 'e3', '', 'DKK' ) );
	}

	public function test_a_new_customer_on_a_page_in_another_currency_is_offered_a_top_up_there(): void {
		$u = $this->new_user();
		wp_set_current_user( $u );
		$this->fake_currency_up( 'EUR' );
		$this->fake_set_currency( 'DKK' );
		$this->fake_amounts( array( 'DKK' => array( 'credit_min' => 100, 'credit_max' => 2000 ) ) );

		$data = Page::data();

		$this->assertSame( 'DKK', $data['currency'] );
		$this->assertTrue( $data['available'] );
	}

	public function test_a_legacy_top_up_line_with_no_currency_is_the_shop_currency_and_goes_when_the_cart_is_loaded_in_another(): void {
		$u = $this->new_user();
		wp_set_current_user( $u );
		$this->fake_currency_up( 'EUR' );
		$this->fake_amounts( array( 'DKK' => array( 'credit_min' => 10, 'credit_max' => 5000 ) ) );
		WC()->cart->add_to_cart( Topup::product_id(), 1, 0, array(), array( Topup::CART_KEY => 2500 ) );
		$this->assertCount( 1, WC()->cart->get_cart() );

		$this->fake_set_currency( 'DKK' );
		do_action( 'woocommerce_cart_loaded_from_session', WC()->cart );

		$this->assertCount( 0, WC()->cart->get_cart(), 'Gone on load, before any recalculation.' );
		$this->assertNotFalse( has_action( 'woocommerce_cart_loaded_from_session', array( Topup::class, 'price_lines' ) ) );
		wc_clear_notices();
	}

	public function test_a_paid_top_up_that_cannot_be_credited_is_held_once_with_one_note_and_one_alert(): void {
		$u = $this->new_user();
		Balance::credit( $u, 100, Balance::TOPUP, 'order_item', 'h1' );
		$order = $this->topup_order( $u, 2500 );
		$order->set_currency( 'DKK' );
		$order->save();

		$order->payment_complete();
		for ( $i = 0; $i < 3; $i++ ) {
			$o = wc_get_order( $order->get_id() );
			$o->update_status( 'processing' );
			$o->update_status( 'completed' );
			do_action( 'woocommerce_payment_complete', $order->get_id() );
		}

		$order = wc_get_order( $order->get_id() );
		$notes = array_column( wc_get_order_notes( array( 'order_id' => $order->get_id() ) ), 'content' );
		$not_added = array_filter( $notes, static fn( $n ) => false !== strpos( $n, 'not added' ) );

		$this->assertSame( 100, Balance::get( $u ), 'Nothing credited.' );
		$this->assertCount( 1, $not_added, 'One note, however many times the status is saved.' );
		$this->assertSame( 1, $this->mails( 'could not be credited' ), 'One alert for the order.' );
		$this->assertSame( 'yes', $order->get_meta( Topup::META_BLOCKED ) );
	}

	public function test_the_blocked_top_up_is_put_on_hold(): void {
		$u = $this->new_user();
		Balance::credit( $u, 100, Balance::TOPUP, 'order_item', 'h2' );
		$order = $this->topup_order( $u, 2500 );
		$order->set_currency( 'DKK' );
		$order->save();

		$order->payment_complete();

		$this->assertTrue( wc_get_order( $order->get_id() )->has_status( 'on-hold' ) );
	}
}

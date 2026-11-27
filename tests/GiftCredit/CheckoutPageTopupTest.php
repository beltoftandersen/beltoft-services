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

/**
 * Stands in for the Stripe wallet tokens (type Stripe_GooglePay / Stripe_ApplePay, which Stripe for WooCommerce does not load in the test
 * site): a card token that needs no expiry date and no card number.
 */
class TestWalletToken extends \WC_Payment_Token_CC {
	protected $type = 'Stripe_GooglePay';

	public function validate() {
		return true;
	}
}

/**
 * The Apple Pay type.
 */
class TestAppleWalletToken extends TestWalletToken {
	protected $type = 'Stripe_ApplePay';
}

class CheckoutPageTopupTest extends GiftCreditTestCase {

	public function set_up(): void {
		parent::set_up();
		Checkout::reset();
		add_filter( 'bgca_auto_apply', '__return_true', 99 );
	}

	public function tear_down(): void {
		remove_all_filters( 'woocommerce_payment_token_class' );
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

	// A saved card instead of the gateway.

	/**
	 * The gateway the test checkout posts.
	 *
	 * @var string
	 */
	private string $posted_gateway = 'stripe_cc';

	/**
	 * A customer with credit and a cart of one monthly subscription of 10; the gateway "stripe_cc" is a test gateway that can be charged later.
	 */
	private function subscription_cart( int $cents, ?string $year = '2099', string $price = '10', string $gateway_id = 'stripe_cc', string $class = '' ): int {
		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery -- test fixture: tokens are not rolled back and user ids are reused.
		$wpdb->query( "DELETE FROM {$wpdb->prefix}woocommerce_payment_tokenmeta" );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}woocommerce_payment_tokens" );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery
		wp_cache_flush();

		$gateway           = new \BeltoftServices\Tests\Usage\UsageCardGateway( $gateway_id );
		$gateway->supports = array_merge( $gateway->supports, array( 'add_payment_method', 'tokenization' ) );
		WC()->payment_gateways()->payment_gateways[ $gateway_id ] = $gateway;
		$this->posted_gateway = $gateway_id;

		$user = $this->new_user();
		wp_set_current_user( $user );
		wc_load_cart();
		WC()->cart->empty_cart();
		$this->card( $user, $cents, Source::ORDER );

		$product = new \WC_Product_Subscription();
		$product->set_name( 'Box ' . wp_generate_password( 5, false ) );
		$product->set_status( 'publish' );
		$product->set_regular_price( $price );
		$product->update_meta_data( '_subscription_price', $price );
		$product->update_meta_data( '_subscription_period', 'month' );
		$product->update_meta_data( '_subscription_period_interval', '1' );
		$product->update_meta_data( '_subscription_length', '0' );
		$product->save();

		if ( null !== $year && '' !== $class ) {
			// A wallet token of Stripe for WooCommerce, as the customer saved it: no expiry date and no card number are required.
			add_filter(
				'woocommerce_payment_token_class',
				static fn( $name, $type ) => 'Stripe_GooglePay' === $type ? TestWalletToken::class : ( 'Stripe_ApplePay' === $type ? TestAppleWalletToken::class : $name ),
				10,
				2
			);
			$token = new $class();
			$token->set_gateway_id( $gateway_id );
			$token->set_token( 'pm_wallet_' . wp_generate_password( 6, false ) );
			$token->set_user_id( $user );
			$token->set_default( true );

			if ( '' !== $year ) {
				$token->set_card_type( 'visa' );
				$token->set_last4( '4242' );
				$token->set_expiry_month( '08' );
				$token->set_expiry_year( $year );
			}

			$token->save();
		} elseif ( null !== $year ) {
			$token = new \WC_Payment_Token_CC();
			$token->set_gateway_id( $gateway_id );
			$token->set_token( 'pm_saved_' . wp_generate_password( 6, false ) );
			$token->set_user_id( $user );
			$token->set_card_type( 'visa' );
			$token->set_last4( '4242' );
			$token->set_expiry_month( '08' );
			$token->set_expiry_year( $year );
			$token->set_default( true );
			$token->save();
		}

		WC()->cart->add_to_cart( $product->get_id() );
		WC()->cart->calculate_totals();
		Checkout::reset();

		return $user;
	}

	/**
	 * What the checkout does: the order from the cart, then the hooks in their order. Returns the order, the subscriptions of it
	 * and whether the gateway is asked.
	 *
	 * @return array{0: \WC_Order, 1: \WC_Subscription[], 2: bool}
	 */
	private function check_out( int $user, ?callable $between = null ): array {
		$id = WC()->checkout()->create_order(
			array(
				'payment_method'   => $this->posted_gateway,
				'billing_email'    => self::MAIL,
				'billing_country'  => 'DK',
				'billing_first_name' => 'A',
				'billing_last_name'  => 'B',
				'customer_id'      => $user,
			)
		);
		$this->assertIsInt( $id );
		$order = wc_get_order( $id );

		if ( $between ) {
			$between();
		}

		do_action( 'woocommerce_checkout_order_processed', $id, array( 'payment_method' => $this->posted_gateway ), $order );

		$order = wc_get_order( $id );
		$subs  = array_values( wcs_get_subscriptions_for_order( $order, array( 'order_type' => 'parent' ) ) );

		return array( $order, $subs, (bool) apply_filters( 'woocommerce_cart_needs_payment', $order->needs_payment(), WC()->cart ) );
	}

	public function test_a_saved_card_and_credit_for_all_means_no_card_is_asked_and_the_subscription_gets_the_card(): void {
		$user = $this->subscription_cart( 5000 );
		list( $order, $subs, $asked ) = $this->check_out( $user );

		$this->assertSame( '0.00', wc_format_decimal( $order->get_total(), 2 ), 'The cards paid all.' );
		$this->assertFalse( $asked );
		$this->assertCount( 1, $subs );
		$token = \WC_Payment_Tokens::get_customer_default_token( $user );
		$this->assertSame( 'stripe_cc', $subs[0]->get_payment_method() );
		$this->assertSame( $token->get_token(), $subs[0]->get_meta( '_payment_method_token', true ) );
		$this->assertFalse( $subs[0]->is_manual() );
	}

	/**
	 * @dataProvider wallets
	 */
	public function test_a_wallet_only_customer_is_not_asked_and_the_subscription_gets_the_wallet( string $gateway, string $class, string $year ): void {
		$user = $this->subscription_cart( 5000, $year, '10', $gateway, $class );
		$token = \WC_Payment_Tokens::get_customer_default_token( $user );
		$this->assertInstanceOf( $class, $token );
		$this->assertTrue( \BeltoftServices\Card\Card::usable( \BeltoftServices\Card\Card::summarize_token( $token ) ), 'A wallet token is usable, with or without an expiry date.' );

		list( $order, $subs, $asked ) = $this->check_out( $user );

		$this->assertSame( '0.00', wc_format_decimal( $order->get_total(), 2 ) );
		$this->assertFalse( $asked );
		$this->assertCount( 1, $subs );
		$this->assertSame( $gateway, $subs[0]->get_payment_method(), 'The wallet\'s own gateway.' );
		$this->assertSame( $token->get_token(), $subs[0]->get_meta( '_payment_method_token', true ) );
		$this->assertFalse( $subs[0]->is_manual() );
	}

	/**
	 * @return array<string, array{0: string, 1: string, 2: string}>
	 */
	public function wallets(): array {
		return array(
			'Google Pay'                 => array( 'stripe_googlepay', TestWalletToken::class, '2099' ),
			'Apple Pay'                  => array( 'stripe_applepay', TestAppleWalletToken::class, '2099' ),
			'Google Pay without expiry'  => array( 'stripe_googlepay', TestWalletToken::class, '' ),
			'Apple Pay without expiry'   => array( 'stripe_applepay', TestAppleWalletToken::class, '' ),
		);
	}

	public function test_an_expired_wallet_token_is_not_used(): void {
		$user = $this->subscription_cart( 5000, '2001', '10', 'stripe_googlepay', TestWalletToken::class );
		list( , , $asked ) = $this->check_out( $user );
		$this->assertTrue( $asked );
	}

	/**
	 * The checkout page defines WOOCOMMERCE_CHECKOUT for good: a process of its own.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_the_checkout_page_shows_no_payment_methods_when_credit_and_a_saved_method_cover_it(): void {
		$user = $this->subscription_cart( 5000 );
		define_if_missing( 'WOOCOMMERCE_CHECKOUT' );
		Checkout::reset();

		$this->assertFalse( WC()->cart->needs_payment(), 'No payment methods, no card form.' );
		ob_start();
		Checkout::saved_method_line();
		$line = (string) ob_get_clean();
		$this->assertStringContainsString( 'Paid from your account credit. Renewals are paid from your account credit first, then from your saved payment method (', $line );
		$this->assertStringContainsString( 'ending in 4242', $line );
		$this->assertStringNotContainsString( "\u{2014}", $line );
		$this->assertNotNull( Checkout::saved_card( $user ) );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_the_checkout_page_is_unchanged_without_a_saved_method_or_with_partial_credit(): void {
		$this->subscription_cart( 5000, null );
		define_if_missing( 'WOOCOMMERCE_CHECKOUT' );
		Checkout::reset();
		$this->assertTrue( WC()->cart->needs_payment(), 'No saved method: the payment box stays.' );
		ob_start();
		Checkout::saved_method_line();
		$this->assertSame( '', ob_get_clean() );

		$this->subscription_cart( 400 );
		Checkout::reset();
		$this->assertTrue( WC()->cart->needs_payment(), 'Partial credit: the payment box stays.' );
		ob_start();
		Checkout::saved_method_line();
		$this->assertSame( '', ob_get_clean() );
	}

	/**
	 * The forecast says "covered", but the add-on applies nothing when the order is made: the order decides, the gateway is asked and the
	 * order is never completed unpaid.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_the_submit_follows_the_order_not_the_forecast(): void {
		$user = $this->subscription_cart( 5000 );
		define_if_missing( 'WOOCOMMERCE_CHECKOUT' );
		Checkout::reset();
		$this->assertFalse( WC()->cart->needs_payment(), 'The forecast says covered.' );

		list( $order, $subs, $asked ) = $this->check_out(
			$user,
			static function (): void {
				add_filter( 'bgca_auto_apply', '__return_false', 100 );
			}
		);

		$this->assertGreaterThan( 0, (float) $order->get_total(), 'The cards were not applied.' );
		$this->assertTrue( $asked, 'An order with a total above 0 never goes without payment.' );
		$this->assertSame( '', (string) $subs[0]->get_meta( '_payment_method_token', true ) );
	}

	/**
	 * The customer came back to the checkout after a submit that did not finish (a declined card, a closed 3-D Secure window): the order of
	 * that submit is pending, it holds the credit and WooCommerce resumes it. The page shows the credit again (the resumed order's own hold
	 * does not count against the forecast), and the resumed submit pays from the credit again: found on production 2026-11-27, the page
	 * showed no credit row and the card was charged the full total while the credit was taken as well.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_pending_order_of_this_checkout_does_not_hide_the_credit_and_the_resumed_submit_uses_it_again(): void {
		$user = $this->subscription_cart( 400 );
		define_if_missing( 'WOOCOMMERCE_CHECKOUT' );
		Checkout::reset();

		list( $first ) = $this->check_out( $user );
		$this->assertSame( 600, \Bgca\Wallet::cents( $first->get_total() ), 'The first submit paid 4 from the credit.' );
		$this->assertTrue( $first->has_status( 'pending' ) );

		// The browser comes back: the session still names the pending order, and the credit is held for it.
		WC()->session->set( 'order_awaiting_payment', $first->get_id() );
		Checkout::reset();
		$p = Checkout::preview();
		$this->assertNotNull( $p, 'The credit held for the order this checkout resumes is shown.' );
		$this->assertSame( array( 400, 600 ), array( $p['credit'], $p['due'] ) );

		list( $second ) = $this->check_out( $user );
		$this->assertSame( 600, \Bgca\Wallet::cents( $second->get_total() ), 'The resumed submit is not charged the credit again.' );
		$this->assertSame( 400, \Bgca\Pay::applied( $second ) );
		$this->assertCount( 1, $second->get_items( 'coupon' ) );

		if ( (int) $second->get_id() !== (int) $first->get_id() ) {
			$this->assertTrue( wc_get_order( $first->get_id() )->has_status( 'cancelled' ), 'The order that is not used any more is cancelled.' );
		}

		// Another open order of the customer (not this checkout's) still holds its credit.
		WC()->session->set( 'order_awaiting_payment', 0 );
		Checkout::reset();
		$this->assertNull( Checkout::preview(), 'Credit held by another order is not forecast.' );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_the_submit_after_a_forecast_of_covered_puts_the_saved_method_on_the_subscription(): void {
		$user = $this->subscription_cart( 5000 );
		define_if_missing( 'WOOCOMMERCE_CHECKOUT' );
		Checkout::reset();
		$this->assertFalse( WC()->cart->needs_payment() );

		list( $order, $subs, $asked ) = $this->check_out( $user );

		$this->assertSame( '0.00', wc_format_decimal( $order->get_total(), 2 ) );
		$this->assertFalse( $asked );
		$this->assertNotSame( '', (string) $subs[0]->get_meta( '_payment_method_token', true ) );
		$this->assertFalse( $subs[0]->is_manual() );
	}

	public function test_without_a_saved_card_the_gateway_is_asked_as_before(): void {
		$user = $this->subscription_cart( 5000, null );
		list( , $subs, $asked ) = $this->check_out( $user );

		$this->assertTrue( $asked );
		$this->assertSame( '', (string) $subs[0]->get_meta( '_payment_method_token', true ) );
	}

	public function test_an_expired_saved_card_is_not_used_and_the_gateway_is_asked(): void {
		$user = $this->subscription_cart( 5000, '2001' );
		list( , $subs, $asked ) = $this->check_out( $user );

		$this->assertTrue( $asked );
		$this->assertSame( '', (string) $subs[0]->get_meta( '_payment_method_token', true ) );
	}

	public function test_a_card_that_is_deleted_after_the_cards_were_applied_falls_back_to_asking(): void {
		$user = $this->subscription_cart( 5000 );
		$token = \WC_Payment_Tokens::get_customer_default_token( $user );
		// The cards are applied at 200; the card goes at 220.
		add_action(
			'woocommerce_checkout_order_processed',
			static function () use ( $token ): void {
				$token->delete();
			},
			220
		);
		list( , $subs, $asked ) = $this->check_out( $user );
		remove_all_actions( 'woocommerce_checkout_order_processed', 220 );

		$this->assertTrue( $asked, 'No silent manual renewal: the gateway is asked.' );
		$this->assertSame( '', (string) $subs[0]->get_meta( '_payment_method_token', true ) );
	}

	public function test_partial_credit_asks_the_gateway_and_leaves_the_subscription_to_it(): void {
		$user = $this->subscription_cart( 400 );
		list( $order, $subs, $asked ) = $this->check_out( $user );

		$this->assertGreaterThan( 0, (float) $order->get_total() );
		$this->assertTrue( $asked );
		$this->assertSame( '', (string) $subs[0]->get_meta( '_payment_method_token', true ), 'The gateway saves the card of a part payment.' );
	}

	public function test_a_cart_that_saves_no_card_is_not_touched_by_the_saved_card_rule(): void {
		$user = $this->cart_with_card( 5000 );
		Checkout::applied( new \WC_Order(), 1000, 'checkout' );
		$this->assertFalse( Checkout::gateway_saves_card( false ) );
		Checkout::saved_card_on_subscriptions( 0 );
		Checkout::saved_card_on_subscriptions( 999999 );
		$this->assertNull( Checkout::saved_card( $user ), 'No saved card.' );
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

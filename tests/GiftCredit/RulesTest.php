<?php
/**
 * The rules this plugin gives the add-on: the promotional box, the minimum, the saved card, the currency, the switch.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\GiftCredit;

use Bgca\Wallet;
use Bgcw\GiftCard\Source;
use BeltoftServices\Currency;
use BeltoftServices\GiftCredit\GiftCredit;
use BeltoftServices\GiftCredit\PromoFlag;
use BeltoftServices\GiftCredit\Rules;

require_once __DIR__ . '/GiftCreditTestCase.php';

class RulesTest extends GiftCreditTestCase {

	public function test_free_cards_pay_only_the_marked_lines_and_paid_cards_any_line(): void {
		$user   = $this->new_user();
		$free   = $this->card( $user, 5000, Source::PROMOTION );
		$paid   = $this->card( $user, 300, Source::ORDER );
		$marked = $this->product( true, '10' );
		$plain  = $this->product( false, '10' );
		$sub    = $this->subscription( $user, array( $marked, $plain ) );

		$order = $this->renew( $sub );

		$this->assertSame( array( strtoupper( $free->code ) => 1000, strtoupper( $paid->code ) => 300 ), $this->coupon_lines( $order ), 'Free credit pays the marked line (10.00), paid credit 3.00 of the other.' );
		$this->assertEqualsWithDelta( 7.0, (float) $order->get_total(), 0.001 );
		$this->assertSame( 5000, $this->left( $free ), 'Nothing is taken before the order is paid.' );
	}

	public function test_a_free_card_alone_pays_nothing_of_an_unmarked_product(): void {
		$user  = $this->new_user();
		$free  = $this->card( $user, 5000, Source::PROMOTION );
		$plain = $this->product( false, '10' );
		$order = $this->renew( $this->subscription( $user, array( $plain ) ) );

		$this->assertSame( array(), $this->coupon_lines( $order ) );
		$this->assertEqualsWithDelta( 10.0, (float) $order->get_total(), 0.001 );
		$this->assertSame( 5000, $this->left( $free ) );
	}

	public function test_the_filter_of_the_older_credit_still_decides_a_line(): void {
		$user  = $this->new_user();
		$free  = $this->card( $user, 5000, Source::PROMOTION );
		$plain = $this->product( false, '10' );
		add_filter( 'beltoft_services_promo_credit_line', '__return_true' );

		$order = $this->renew( $this->subscription( $user, array( $plain ) ) );
		remove_filter( 'beltoft_services_promo_credit_line', '__return_true' );

		$this->assertSame( array( strtoupper( $free->code ) => 1000 ), $this->coupon_lines( $order ) );
	}

	public function test_a_fully_covered_renewal_completes_and_the_plugin_takes_the_balance_once(): void {
		$user = $this->new_user();
		$card = $this->card( $user, 3000, Source::ORDER );
		$sub  = $this->subscription( $user, array( $this->product( false, '10' ) ) );

		$order = $this->renew( $sub );

		$this->assertEqualsWithDelta( 0.0, (float) $order->get_total(), 0.001 );
		$this->assertTrue( $order->has_status( array( 'processing', 'completed' ) ), 'Got ' . $order->get_status() );
		$this->assertSame( 2000, $this->left( $card ) );

		$order->update_status( 'cancelled' );
		$this->assertSame( 3000, $this->left( $card ), 'A cancelled order gives the balance back.' );
	}

	public function test_the_minimum_rule_refuses_a_rest_under_the_minimum_but_not_a_full_cover(): void {
		$this->settings( array( 'usage_minimum' => 5 ) );
		$user = $this->new_user();
		$card = $this->card( $user, 800, Source::ORDER );
		$sub  = $this->subscription( $user, array( $this->product( false, '10' ) ) );

		$order = $this->renew( $sub );
		$this->assertSame( array(), $this->coupon_lines( $order ), 'A rest of 2.00 is under the minimum of 5.00: no card is used.' );
		$this->assertEqualsWithDelta( 10.0, (float) $order->get_total(), 0.001 );

		$this->card( $user, 1200, Source::ORDER );
		$again = $this->renew( $this->subscription( $user, array( $this->product( false, '10' ) ) ) );
		$this->assertCount( 2, $this->coupon_lines( $again ), 'Two cards cover it in full.' );
		$this->assertEqualsWithDelta( 0.0, (float) $again->get_total(), 0.001 );
		$this->assertSame( 0, $this->left( $card ) );

		$this->assertTrue( Rules::rest_allowed( 0, 'EUR' ) );
		$this->assertTrue( Rules::rest_allowed( 500, 'EUR' ) );
		$this->assertFalse( Rules::rest_allowed( 499, 'EUR' ) );
	}

	public function test_a_card_in_another_currency_than_the_subscription_is_not_applied(): void {
		$user = $this->new_user();
		$usd  = $this->card( $user, 5000, Source::ORDER, 'USD' );
		$sub  = $this->subscription( $user, array( $this->product( false, '10' ) ) );

		$order = $this->renew( $sub );

		$this->assertSame( array(), $this->coupon_lines( $order ) );
		$this->assertSame( 5000, $this->left( $usd ) );
	}

	public function test_off_the_cards_pay_nothing_and_nothing_else_changes(): void {
		$user = $this->new_user();
		$card = $this->card( $user, 5000, Source::ORDER );
		$this->settings( array( 'gift_credit' => 0 ) );

		$this->assertFalse( GiftCredit::active() );
		$order = $this->renew( $this->subscription( $user, array( $this->product( false, '10' ) ) ) );

		$this->assertSame( array(), $this->coupon_lines( $order ) );
		$this->assertEqualsWithDelta( 10.0, (float) $order->get_total(), 0.001 );
		$this->assertSame( 0, GiftCredit::balance( $user, 'EUR' ) );
		$this->assertSame( 5000, $this->left( $card ) );
	}

	public function test_the_early_renewal_of_the_modal_is_left_alone(): void {
		$user = $this->new_user();
		$this->card( $user, 5000, Source::ORDER );
		$_GET['process_early_renewal'] = '1';

		$order = $this->renew( $this->subscription( $user, array( $this->product( false, '10' ) ) ) );
		unset( $_GET['process_early_renewal'] );

		$this->assertSame( array(), $this->coupon_lines( $order ) );
	}

	public function test_a_checkout_order_needs_a_saved_card_unless_the_cart_saves_one(): void {
		$user = $this->new_user();
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- test fixture: tokens of an earlier run that reused this user id.
		$wpdb->delete( $wpdb->prefix . 'woocommerce_payment_tokens', array( 'user_id' => $user ) );
		$order = new \WC_Order();
		$order->set_created_via( 'checkout' );
		$order->set_customer_id( $user );

		$this->assertFalse( Rules::card_rule_met( $user, null ), 'No saved card, no cart that saves one.' );
		$this->assertFalse( Rules::checkout_order( $order, $user, new \WC_Cart() ) );

		$token = new \WC_Payment_Token_CC();
		$token->set_token( 'tok_' . wp_generate_password( 10, false ) );
		$token->set_gateway_id( 'fakecard' );
		$token->set_card_type( 'visa' );
		$token->set_last4( '4242' );
		$token->set_expiry_month( '12' );
		$token->set_expiry_year( (string) ( (int) gmdate( 'Y' ) + 2 ) );
		$token->set_user_id( $user );
		$token->set_default( true );
		$token->save();

		$this->assertTrue( Rules::card_rule_met( $user, null ) );
		$this->assertTrue( Rules::checkout_order( $order, $user, new \WC_Cart() ) );

		$other = new \WC_Order();
		$other->set_created_via( 'subscription' );
		$this->assertFalse( Rules::checkout_order( $other, $user, new \WC_Cart() ), 'Only the order a checkout makes.' );
	}

	public function test_the_account_currency_follows_the_cards_then_the_shop(): void {
		$user = $this->new_user();
		$this->assertSame( 'EUR', Wallet::account_currency( $user ) );

		$this->card( $user, 500, Source::ORDER, 'USD' );
		$this->assertSame( 'USD', Currency::of_user( $user ) );
		$this->assertSame( 'USD', Wallet::account_currency( $user ) );
	}

	public function test_the_product_box_keeps_its_meta_key(): void {
		$this->assertSame( '_beltoft_services_promo_credit', PromoFlag::META_PROMO );
		$this->assertTrue( PromoFlag::promo_allowed( $this->product( true )->get_id() ) );
		$this->assertFalse( PromoFlag::promo_allowed( $this->product( false )->get_id() ) );
		$this->assertFalse( PromoFlag::promo_allowed( 0 ) );
	}
}

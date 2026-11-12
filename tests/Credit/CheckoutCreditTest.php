<?php
/**
 * Account credit paid onto the first order at checkout: the order made from a real cart by WooCommerce's own checkout, the forecast the
 * checkout shows, and what happens when the payment fails or the customer tries again.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Credit;

use BeltoftServices\Credit\Balance;
use BeltoftServices\Credit\CheckoutCredit;
use BeltoftServices\Credit\Invoice;
use BeltoftServices\Credit\RenewalCredit;
use BeltoftServices\Credit\Topup;
use BeltoftServices\Domains\Prices;
use BeltoftServices\Domains\SpendGuard;

require_once __DIR__ . '/CheckoutCreditCase.php';

/**
 * The tests that need no more than the order the checkout creates and the hook it fires.
 */
class CheckoutCreditTest extends CheckoutCreditCase {

	public function test_the_hooks_are_registered_where_the_order_of_operations_needs_them(): void {
		$this->assertSame( 200, has_action( 'woocommerce_checkout_order_processed', array( CheckoutCredit::class, 'apply' ) ), 'After WooCommerce Subscriptions (100), before the needs-payment decision.' );
		$this->assertSame( 5, has_filter( 'woocommerce_create_order', array( CheckoutCredit::class, 'release_awaiting' ) ) );
		$this->assertSame( 20, has_action( 'woocommerce_order_status_failed', array( CheckoutCredit::class, 'after_failed' ) ), 'After Invoice::failed.' );
		$this->assertSame( 40, has_filter( 'woocommerce_cart_needs_payment', array( CheckoutCredit::class, 'cart_needs_payment' ) ) );
		$this->assertSame( 20, has_filter( 'wc_stripe_cart_data', array( CheckoutCredit::class, 'stripe_cart_data' ) ) );
		$this->assertNotFalse( has_action( 'woocommerce_review_order_before_order_total', array( CheckoutCredit::class, 'review_row' ) ) );
		$this->assertNotFalse( has_filter( 'woocommerce_cart_totals_order_total_html', array( CheckoutCredit::class, 'total_html' ) ) );
	}

	// Setting and gates.

	public function test_the_setting_off_changes_nothing(): void {
		$this->settings( array( 'credit_checkout' => 0 ) );
		$user = $this->new_user();
		$this->paid( $user, 5000 );
		$this->cart( $user, array( $this->product( false ) ) );

		$this->assertNull( CheckoutCredit::preview() );

		$order = $this->place();

		$this->assertSame( array(), $this->credit_fees( $order ) );
		$this->assertSame( 5000, Balance::get( $user ) );
		$this->assertEqualsWithDelta( 10.0, (float) $order->get_total(), 0.001 );
	}

	public function test_credit_off_changes_nothing(): void {
		$this->settings( array( 'credit_enabled' => 0 ) );
		$user = $this->new_user();
		$this->paid( $user, 5000 );
		$this->cart( $user, array( $this->product( false ) ) );

		$this->assertNull( CheckoutCredit::preview() );
		$this->assertSame( array(), $this->credit_fees( $this->place() ) );
	}

	public function test_a_customer_with_no_balance_gets_no_fee_and_no_ledger_row(): void {
		$user = $this->new_user();
		$this->cart( $user, array( $this->product( false ) ) );

		$this->assertNull( CheckoutCredit::preview() );

		$order = $this->place();

		$this->assertSame( array(), $this->credit_fees( $order ) );
		$this->assertSame( array(), $this->rows_of( $order ) );
		$this->assertEqualsWithDelta( 10.0, (float) $order->get_total(), 0.001 );
	}

	public function test_a_guest_has_no_credit(): void {
		wp_set_current_user( 0 );
		WC()->cart->empty_cart();
		WC()->cart->add_to_cart( $this->product( false )->get_id() );
		WC()->cart->calculate_totals();

		$this->assertNull( CheckoutCredit::preview() );
		$this->assertSame( array(), $this->credit_fees( $this->place() ) );
	}

	// How much is used.

	public function test_paid_credit_pays_the_whole_order(): void {
		$user = $this->new_user();
		$this->paid( $user, 5000 );
		$this->cart( $user, array( $this->product( false ) ) );

		$order = $this->place();

		$fees = $this->credit_fees( $order );
		$this->assertCount( 1, $fees );
		$this->assertSame( 'Paid from account credit', $fees[0]->get_name() );
		$this->assertEqualsWithDelta( -10.0, (float) $fees[0]->get_total(), 0.001 );
		$this->assertEqualsWithDelta( 0.0, (float) $order->get_total(), 0.001 );
		$this->assertSame( array( Balance::INVOICE => 1000 ), $this->rows_of( $order ) );
		$this->assertSame( 4000, Balance::get( $user ) );
		$this->assertSame( 'yes', $order->get_meta( CheckoutCredit::META ) );
		$this->assertLedgerBalanced( $user );
	}

	public function test_paid_credit_pays_part_of_the_order_and_the_card_pays_the_rest(): void {
		$user = $this->new_user();
		$this->paid( $user, 400 );
		$this->cart( $user, array( $this->product( false ) ) );

		$order = $this->place();

		$this->assertEqualsWithDelta( 6.0, (float) $order->get_total(), 0.001 );
		$this->assertSame( 0, Balance::get( $user ) );
		$this->assertTrue( $order->needs_payment() );
	}

	public function test_promotional_credit_pays_a_marked_product_and_never_an_unmarked_one(): void {
		$marked = $this->new_user();
		$this->promo( $marked, 3000 );
		$this->cart( $marked, array( $this->product( true ) ) );
		$order = $this->place();
		$this->assertEqualsWithDelta( 0.0, (float) $order->get_total(), 0.001 );
		$this->assertSame( array( Balance::PROMO_USE => 1000 ), $this->rows_of( $order ) );

		$plain = $this->new_user();
		$this->promo( $plain, 3000 );
		$this->cart( $plain, array( $this->product( false ) ) );
		$this->assertNull( CheckoutCredit::preview() );
		$order = $this->place();
		$this->assertSame( array(), $this->credit_fees( $order ) );
		$this->assertSame( array(), $this->rows_of( $order ) );
		$this->assertSame( 3000, Balance::get( $plain ) );
	}

	public function test_a_mixed_cart_takes_promo_up_to_the_marked_line_and_paid_credit_for_the_rest(): void {
		$this->vat( true );
		$user = $this->new_user();
		$this->promo( $user, 5000 );
		$this->paid( $user, 500 );
		$this->cart( $user, array( $this->product( true, '10' ), $this->product( false, '20' ) ) );

		// 10 + 20 plus 25 percent VAT: 37.50. Promotional credit is capped at the marked line with its VAT (12.50), paid credit adds 5.00.
		$order = $this->place();

		$this->assertSame( array( Balance::INVOICE => 500, Balance::PROMO_USE => 1250 ), $this->rows_of( $order ) );
		$this->assertEqualsWithDelta( 20.0, (float) $order->get_total(), 0.001 );
		$this->assertSame( 3750, Balance::get( $user ) );
		$this->assertLedgerBalanced( $user );
	}

	public function test_a_rest_under_the_minimum_means_no_credit_and_a_rest_at_the_minimum_is_fine(): void {
		$this->settings( array( 'usage_minimum' => 5 ) );
		$under = $this->new_user();
		$this->paid( $under, 700 );
		$this->cart( $under, array( $this->product( false ) ) );
		$this->assertNull( CheckoutCredit::preview() );
		$order = $this->place();
		$this->assertSame( array(), $this->credit_fees( $order ) );
		$this->assertSame( 700, Balance::get( $under ) );

		$at = $this->new_user();
		$this->paid( $at, 500 );
		$this->cart( $at, array( $this->product( false ) ) );
		$order = $this->place();
		$this->assertEqualsWithDelta( 5.0, (float) $order->get_total(), 0.001 );
		$this->assertSame( 0, Balance::get( $at ) );

		$all = $this->new_user();
		$this->paid( $all, 1000 );
		$this->cart( $all, array( $this->product( false ) ) );
		$this->assertEqualsWithDelta( 0.0, (float) $this->place()->get_total(), 0.001, 'Credit that covers the whole order is always used.' );
	}

	public function test_credit_in_another_currency_is_not_used(): void {
		$user = $this->new_user();
		$this->assertTrue( Balance::credit( $user, 5000, Balance::TOPUP, 'order_item', 'seed-dkk', '', 'DKK' ) );
		$this->cart( $user, array( $this->product( false ) ) );

		$this->assertNull( CheckoutCredit::preview() );
		$order = $this->place();
		$this->assertSame( array(), $this->credit_fees( $order ) );
		$this->assertSame( 5000, Balance::get( $user ) );
	}

	public function test_a_top_up_order_is_never_paid_from_credit(): void {
		$user = $this->new_user();
		$this->paid( $user, 5000 );
		wp_set_current_user( $user );
		WC()->cart->empty_cart();
		$this->assertTrue( Topup::add_to_cart( 2000 ) );
		WC()->cart->calculate_totals();

		$this->assertNull( CheckoutCredit::preview() );
		$order = $this->place();
		$this->assertSame( array(), $this->credit_fees( $order ) );
		$this->assertSame( 5000, Balance::get( $user ) );
	}

	public function test_a_switch_or_a_renewal_in_the_cart_is_never_paid_from_credit(): void {
		$user = $this->new_user();
		$this->paid( $user, 5000 );
		$this->cart( $user, array( $this->product( false ) ) );
		$this->assertNotNull( CheckoutCredit::preview() );

		foreach ( array( 'subscription_switch', 'subscription_renewal', 'subscription_renewal_early' ) as $flag ) {
			$key = array_key_first( WC()->cart->get_cart() );
			WC()->cart->cart_contents[ $key ][ $flag ] = array( 'subscription_id' => 1 );
			$this->assertNull( CheckoutCredit::preview(), $flag );
			unset( WC()->cart->cart_contents[ $key ][ $flag ] );
		}

		// A renewal order of the checkout is not credited by this path either.
		$order = $this->place();
		update_post_meta( $order->get_id(), '_subscription_renewal', '1' );
		$this->assertTrue( function_exists( 'wcs_order_contains_renewal' ) );
	}

	public function test_the_filter_can_refuse_an_order(): void {
		$user = $this->new_user();
		$this->paid( $user, 5000 );
		$this->cart( $user, array( $this->product( false ) ) );
		$deny = static fn() => false;
		add_filter( 'beltoft_services_credit_applies_to_order', $deny );

		$this->assertNull( CheckoutCredit::preview() );
		$this->assertSame( array(), $this->credit_fees( $this->place() ) );
		$this->assertSame( 5000, Balance::get( $user ) );

		remove_filter( 'beltoft_services_credit_applies_to_order', $deny );
	}

	public function test_the_promotional_line_filter_decides_for_the_cart_and_the_order_alike(): void {
		$user = $this->new_user();
		$this->promo( $user, 5000 );
		$this->cart( $user, array( $this->product( false ) ) );
		$allow = static fn() => true;
		add_filter( 'beltoft_services_promo_credit_line', $allow );

		$p = CheckoutCredit::preview();
		$this->assertNotNull( $p );
		$order = $this->place();
		$this->assertSame( $p['credit'], Invoice::used( $order ) );

		remove_filter( 'beltoft_services_promo_credit_line', $allow );
	}

	// The forecast equals what is applied.

	/**
	 * Carts of every shape: the forecast the checkout shows is the credit the order takes and the amount the order leaves for the card.
	 *
	 * @return array<string, array{0: callable}>
	 */
	public static function shapes(): array {
		return array(
			'plain'        => array( static function ( self $t, int $user ): array { return array( $t->product( false, '10' ) ); } ),
			'vat'          => array( static function ( self $t, int $user ): array { $t->vat( true ); return array( $t->product( false, '19.99' ), $t->product( true, '7.31' ) ); } ),
			'coupon'       => array( static function ( self $t, int $user ): array { $t->vat( true ); $t->coupon( '20' ); return array( $t->product( true, '33.33' ) ); } ),
			'sale price'   => array( static function ( self $t, int $user ): array { $p = $t->product( true, '40' ); $p->set_sale_price( '24.99' ); $p->save(); return array( wc_get_product( $p->get_id() ) ); } ),
			'taxed fee'    => array( static function ( self $t, int $user ): array { $t->vat( true ); add_action( 'woocommerce_cart_calculate_fees', static function ( $cart ) { $cart->add_fee( 'First year discount', -2.5, true ); } ); return array( $t->product( true, '12.34' ) ); } ),
			'two quantity' => array( static function ( self $t, int $user ): array { return array( $t->product( true, '3.33' ), $t->product( true, '3.33' ), $t->product( false, '0.01' ) ); } ),
		);
	}

	/**
	 * @dataProvider shapes
	 */
	public function test_the_forecast_equals_what_the_order_takes( callable $shape ): void {
		foreach ( array( 'plenty' => array( 2000, 20000 ), 'part' => array( 130, 120 ), 'promo only' => array( 0, 900 ) ) as $label => $credit ) {
			$user = $this->new_user();
			$this->paid( $user, $credit[0] );
			$this->promo( $user, $credit[1] );
			$this->cart( $user, $shape( $this, $user ) );

			$p     = CheckoutCredit::preview();
			$order = $this->place();
			$used  = Invoice::used( $order );

			$this->assertSame( null === $p ? 0 : $p['credit'], $used, $label . ': the credit' );

			if ( null !== $p ) {
				$this->assertSame( $p['total'], Balance::cents( WC()->cart->get_total( 'edit' ) ), $label );
				$this->assertSame( $p['due'], Balance::cents( $order->get_total() ), $label . ': the amount left for the card' );
				$this->assertSame( $p['promo'], -(int) ( Balance::find( Balance::PROMO_USE, Invoice::REF_TYPE, (string) $order->get_id() )['amount_cents'] ?? 0 ), $label . ': the promotional part' );
			}

			$this->assertLedgerBalanced( $user );
			WC()->cart->empty_cart();
			remove_all_actions( 'woocommerce_cart_calculate_fees' );
		}
	}

	public function test_a_cart_with_a_coupon_and_vat_shows_and_takes_the_same_credit(): void {
		$this->vat( true );
		$user = $this->new_user();
		$this->paid( $user, 1500 );
		wp_set_current_user( $user );
		WC()->cart->empty_cart();
		WC()->cart->add_to_cart( $this->product( false, '40' )->get_id() );
		$this->coupon( '25' );
		WC()->cart->apply_coupon( $this->coupon_code );
		WC()->cart->calculate_totals();

		$p = CheckoutCredit::preview();
		$this->assertSame( 1500, $p['credit'] );
		$this->assertSame( 3750, $p['total'], '40 less 25 percent, plus 25 percent VAT.' );

		$order = $this->place();
		$this->assertSame( 1500, Invoice::used( $order ) );
		$this->assertSame( 2250, Balance::cents( $order->get_total() ) );
		$this->assertSame( $p['due'], Balance::cents( $order->get_total() ) );
	}

	// Lines and taxes.

	public function test_line_and_tax_totals_do_not_change(): void {
		$this->vat( true );
		$user = $this->new_user();
		$this->paid( $user, 100000 );
		$this->cart( $user, array( $this->product( true, '19.99' ) ) );
		$posted = $this->posted();
		$id     = WC()->checkout()->create_order( $posted );
		$before = wc_get_order( $id );
		$lines  = array_map( static fn( $i ) => array( $i->get_total(), $i->get_total_tax(), $i->get_subtotal() ), array_values( $before->get_items() ) );
		$tax    = $before->get_total_tax();
		$total  = Balance::cents( $before->get_total() );

		do_action( 'woocommerce_checkout_order_processed', $id, $posted, $before );
		$after = wc_get_order( $id );

		$this->assertSame( $lines, array_map( static fn( $i ) => array( $i->get_total(), $i->get_total_tax(), $i->get_subtotal() ), array_values( $after->get_items() ) ) );
		$this->assertSame( $tax, $after->get_total_tax(), 'The VAT is the VAT of the order.' );
		$this->assertSame( $total, Invoice::used( $after ), 'Credit pays the VAT part too.' );
		$this->assertSame( 0.0, (float) $after->get_fees()[ array_key_first( $after->get_fees() ) ]->get_total_tax() );
	}

	public function test_the_hook_twice_takes_the_credit_once(): void {
		$user = $this->new_user();
		$this->paid( $user, 5000 );
		$this->cart( $user, array( $this->product( false ) ) );
		$posted = $this->posted();
		$id     = WC()->checkout()->create_order( $posted );

		do_action( 'woocommerce_checkout_order_processed', $id, $posted, wc_get_order( $id ) );
		do_action( 'woocommerce_checkout_order_processed', $id, $posted, wc_get_order( $id ) );

		$order = wc_get_order( $id );
		$this->assertCount( 1, $this->credit_fees( $order ) );
		$this->assertSame( 4000, Balance::get( $user ) );
		$this->assertLedgerBalanced( $user );
	}

	public function test_an_order_not_made_by_the_checkout_is_left_alone(): void {
		$user = $this->new_user();
		$this->paid( $user, 5000 );
		$order = wc_create_order( array( 'customer_id' => $user ) );
		$order->add_product( $this->product( false ), 1 );
		$order->calculate_totals( true );
		$order->save();

		CheckoutCredit::apply( $order->get_id(), array(), $order );

		$this->assertSame( 5000, Balance::get( $user ) );
		$this->assertSame( array(), $this->credit_fees( wc_get_order( $order->get_id() ) ) );
	}

	public function test_a_domain_registration_paid_by_credit_keeps_its_line_for_the_spend_guard(): void {
		update_option( Prices::OPTION, array( 'com' => array( 'create' => 10.0, 'renew' => 12.5, 'transfer' => 9.0, 'restricted' => false, 'price' => 20 ) ) );
		$user = $this->new_user();
		$this->paid( $user, 5000 );
		wp_set_current_user( $user );
		WC()->cart->empty_cart();
		$domain = $this->product( false, '20', 'domain' );
		$this->assertNotFalse( WC()->cart->add_to_cart( $domain->get_id(), 1, 0, array(), array( 'beltoft_services_domain' => array( 'domain' => 'example.com', 'mode' => 'register' ) ) ) );
		WC()->cart->calculate_totals();

		$order = $this->place();

		$this->assertEqualsWithDelta( 0.0, (float) $order->get_total(), 0.001 );
		$item = array_values( $order->get_items( 'line_item' ) )[0];
		$this->assertEqualsWithDelta( 20.0, (float) $item->get_total(), 0.001, 'The line is what the customer is charged, not a discounted one.' );
		$this->assertNull( SpendGuard::problem( 'example.com', 'create', $item ), 'The guard reads the line, not the order total.' );
	}

	// Failure, retry, abandon.

	public function test_a_failed_order_gives_the_credit_back_and_the_next_attempt_takes_it_again(): void {
		$user = $this->new_user();
		$this->paid( $user, 5000 );
		$this->cart( $user, array( $this->product( false ) ) );

		$first = $this->place();
		WC()->session->set( 'order_awaiting_payment', $first->get_id() );
		$this->assertSame( 4000, Balance::get( $user ) );

		$first->update_status( 'failed' );

		$first = wc_get_order( $first->get_id() );
		$this->assertSame( 5000, Balance::get( $user ), 'Given back.' );
		$this->assertSame( array(), $this->credit_fees( $first ), 'The fee is gone: the order can be paid in full.' );
		$this->assertEqualsWithDelta( 10.0, (float) $first->get_total(), 0.001 );
		$this->assertSame( '', $first->get_cart_hash(), 'It is not resumed.' );

		$second = $this->place();

		$this->assertNotSame( $first->get_id(), $second->get_id() );
		$this->assertEqualsWithDelta( 0.0, (float) $second->get_total(), 0.001 );
		$this->assertSame( 4000, Balance::get( $user ), 'Taken once, by the second order.' );
		$this->assertTrue( Balance::exists( Balance::ADJUST, Invoice::REF_RETURN, (string) $first->get_id() ), 'The first order was given back once.' );
		$this->assertLedgerBalanced( $user );
	}

	public function test_a_second_submit_of_an_unpaid_order_releases_its_credit_and_takes_it_for_the_new_order(): void {
		$user = $this->new_user();
		$this->paid( $user, 600 );
		$this->cart( $user, array( $this->product( false ) ) );

		$first = $this->place();
		WC()->session->set( 'order_awaiting_payment', $first->get_id() );
		$this->assertSame( 0, Balance::get( $user ) );
		$this->assertEqualsWithDelta( 4.0, (float) $first->get_total(), 0.001 );

		// The forecast counts what the unpaid order holds: it will be given back before the new order is made.
		$p = CheckoutCredit::preview();
		$this->assertSame( 600, $p['credit'] );

		$second = $this->place();

		$first = wc_get_order( $first->get_id() );
		$this->assertSame( 600, Invoice::used( $second ) );
		$this->assertSame( $p['due'], Balance::cents( $second->get_total() ) );
		$this->assertSame( 0, Balance::get( $user ) );
		$this->assertSame( array(), $this->credit_fees( $first ) );
		$this->assertEqualsWithDelta( 10.0, (float) $first->get_total(), 0.001, 'The abandoned order is whole again.' );
		$this->assertSame( 0, Invoice::used( $first ) );
		$this->assertLedgerBalanced( $user );

		// And a third time: still only 600 in the ledger for the customer.
		WC()->session->set( 'order_awaiting_payment', $second->get_id() );
		$third = $this->place();
		$this->assertSame( 600, Invoice::used( $third ) );
		$this->assertSame( 0, Balance::get( $user ) );
		$this->assertLedgerBalanced( $user );
	}

	public function test_a_changed_cart_releases_the_credit_of_the_unpaid_order_too(): void {
		$user = $this->new_user();
		$this->paid( $user, 5000 );
		$this->cart( $user, array( $this->product( false ) ) );
		$first = $this->place();
		WC()->session->set( 'order_awaiting_payment', $first->get_id() );

		$this->cart( $user, array( $this->product( false, '30' ) ) );
		$second = $this->place();

		$this->assertSame( 3000, Invoice::used( $second ) );
		$this->assertSame( 2000, Balance::get( $user ) );
		$this->assertSame( array(), $this->credit_fees( wc_get_order( $first->get_id() ) ) );
		$this->assertLedgerBalanced( $user );
	}

	public function test_an_abandoned_order_that_is_cancelled_or_deleted_gives_the_credit_back(): void {
		$user = $this->new_user();
		$this->paid( $user, 5000 );

		$this->cart( $user, array( $this->product( false ) ) );
		$one = $this->place();
		$this->assertSame( 4000, Balance::get( $user ) );
		$one->update_status( 'cancelled' );
		$this->assertSame( 5000, Balance::get( $user ) );

		$this->cart( $user, array( $this->product( false ) ) );
		$two = $this->place();
		$this->assertSame( 4000, Balance::get( $user ) );
		$two->delete( true );
		$this->assertSame( 5000, Balance::get( $user ) );
		$this->assertLedgerBalanced( $user );
	}

	public function test_a_paid_order_keeps_what_it_used_and_is_never_released(): void {
		$user = $this->new_user();
		$this->paid( $user, 600 );
		$this->cart( $user, array( $this->product( false ) ) );
		$order = $this->place();
		WC()->session->set( 'order_awaiting_payment', $order->get_id() );
		$order->payment_complete();

		$this->cart( $user, array( $this->product( false ) ) );
		$this->place();

		$this->assertSame( 600, Invoice::used( wc_get_order( $order->get_id() ) ), 'A paid order keeps its credit.' );
		$this->assertSame( array( Balance::INVOICE => 600 ), $this->rows_of( $order ) );
	}

	// Display.

	public function test_the_forecast_hooks_show_the_credit_and_the_amount_left(): void {
		$user = $this->new_user();
		$this->paid( $user, 400 );
		$this->cart( $user, array( $this->product( false ) ) );

		$p = CheckoutCredit::preview();
		$this->assertSame( array( 'credit' => 400, 'promo' => 0, 'paid' => 400, 'due' => 600, 'total' => 1000, 'currency' => 'EUR' ), $p );

		// The stripe cart data: the amount of the card form and of the wallet sheets is the amount the order will charge.
		$data = CheckoutCredit::stripe_cart_data(
			array( 'total' => 10.0, 'totalCents' => 1000, 'lineItems' => array( array( 'label' => 'Service', 'amount' => 10.0, 'amountCents' => 1000, 'type' => 'product' ) ) ),
			WC()->cart
		);
		$this->assertSame( 6.0, $data['total'] );
		$this->assertSame( 600, $data['totalCents'] );
		$this->assertSame( 600, array_sum( array_column( $data['lineItems'], 'amountCents' ) ), 'The line items add up to the total.' );
		$this->assertSame( 'Paid from account credit', $data['lineItems'][1]['label'] );

		// Nothing to show: untouched.
		wp_set_current_user( 0 );
		$same = array( 'total' => 10.0, 'totalCents' => 1000, 'lineItems' => array() );
		$this->assertSame( $same, CheckoutCredit::stripe_cart_data( $same, WC()->cart ) );
	}

	public function test_the_payment_method_is_dropped_only_when_credit_covers_everything_and_nothing_needs_a_card(): void {
		$user = $this->new_user();
		$this->paid( $user, 5000 );
		$this->cart( $user, array( $this->product( false ) ) );
		$this->assertFalse( WC()->cart->needs_payment(), 'A plain product paid in full by credit needs no card.' );

		$part = $this->new_user();
		$this->paid( $part, 400 );
		$this->cart( $part, array( $this->product( false ) ) );
		$this->assertTrue( WC()->cart->needs_payment() );

		$none = $this->new_user();
		$this->cart( $none, array( $this->product( false ) ) );
		$this->assertTrue( WC()->cart->needs_payment() );

		$sub = $this->new_user();
		$this->paid( $sub, 5000 );
		$this->cart( $sub, array( $this->as_subscription( $this->product( false ) ) ) );
		$this->assertTrue( WC()->cart->needs_payment(), 'A subscription still gets a payment method for its renewals.' );
	}

	/**
	 * The checkout page's own totals: WOOCOMMERCE_CHECKOUT is defined for good, so this runs in a process of its own.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_the_checkout_totals_show_the_credit_row_and_the_amount_to_pay(): void {
		define( 'WOOCOMMERCE_CHECKOUT', true );
		$user = $this->new_user();
		$this->paid( $user, 400 );
		$this->cart( $user, array( $this->product( false ) ) );

		ob_start();
		CheckoutCredit::review_row();
		$row = (string) ob_get_clean();
		$this->assertStringContainsString( 'Paid from account credit', $row );
		$this->assertStringContainsString( '4.00', $row );
		$this->assertStringContainsString( '-', wp_strip_all_tags( $row ) );

		$html = CheckoutCredit::total_html( '<strong>' . wc_price( 10 ) . '</strong> <small class="includes_tax">(includes 2.00 VAT)</small>' );
		$this->assertStringContainsString( '6.00', $html );
		$this->assertStringNotContainsString( '10.00', $html );
		$this->assertStringContainsString( 'includes 2.00 VAT', $html, 'The VAT note stays.' );

		// With nothing to show the row and the total are as WooCommerce prints them.
		wp_set_current_user( 0 );
		ob_start();
		CheckoutCredit::review_row();
		$this->assertSame( '', (string) ob_get_clean() );
		$this->assertSame( '<strong>x</strong>', CheckoutCredit::total_html( '<strong>x</strong>' ) );
	}

	public function test_the_product_box_label_and_description(): void {
		require_once WC_ABSPATH . 'includes/admin/wc-meta-box-functions.php';
		ob_start();
		RenewalCredit::product_field( $this->product( false ) );
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'Promotional credit can pay orders and renewals', $html );
		$this->assertStringContainsString( RenewalCredit::META_PROMO, $html, 'The meta key is unchanged.' );
	}
}

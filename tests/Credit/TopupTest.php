<?php
/**
 * Top-ups: product, cart, crediting, refunds, chargebacks and the page.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Credit;

use BeltoftServices\Credit\Balance;
use BeltoftServices\Credit\Config;
use BeltoftServices\Credit\Page;
use BeltoftServices\Credit\Topup;

require_once __DIR__ . '/CreditTestCase.php';

/**
 * A top-up is a taxable sale credited net.
 */
class TopupTest extends CreditTestCase {

	public function tear_down(): void {
		WC()->cart->empty_cart();
		remove_all_filters( 'beltoft_services_credit_amount_from_order' );
		parent::tear_down();
	}

	public function test_the_product_is_hidden_virtual_and_made_once(): void {
		$id = Topup::product_id();
		$p  = wc_get_product( $id );

		$this->assertSame( $id, Topup::product_id() );
		$this->assertSame( $id, (int) get_option( Topup::OPTION_PRODUCT ) );
		$this->assertSame( 'hidden', $p->get_catalog_visibility() );
		$this->assertTrue( $p->is_virtual() );
		$this->assertSame( 'taxable', $p->get_tax_status() );
		$this->assertTrue( $p->is_sold_individually() );
		$this->assertTrue( Topup::is_product( $id ) );
		$this->assertFalse( Topup::is_product( $id + 1 ) );
	}

	public function test_a_deleted_product_is_made_again(): void {
		$id = Topup::product_id();
		wp_delete_post( $id, true );

		$this->assertNotSame( $id, Topup::product_id() );
	}

	/**
	 * @dataProvider amounts_provider
	 */
	public function test_typed_amounts_are_validated( $typed, ?int $expected ): void {
		$this->assertSame( $expected, Topup::parse( $typed ) );
	}

	/** @return array<string, array{0: mixed, 1: int|null}> */
	public function amounts_provider(): array {
		return array(
			'min'            => array( '10', 1000 ),
			'below min'      => array( '9.99', null ),
			'max'            => array( '1000', 100000 ),
			'above max'      => array( '1000.01', null ),
			'decimals'       => array( '12.5', 1250 ),
			'comma'          => array( '12,50', 1250 ),
			'three decimals' => array( '12.345', null ),
			'negative'       => array( '-20', null ),
			'text'           => array( 'abc', null ),
			'empty'          => array( '', null ),
			'exponent'       => array( '1e3', null ),
			'spaces'         => array( ' 20 ', 2000 ),
		);
	}

	public function test_limits_follow_the_settings(): void {
		$this->settings( array( 'credit_min' => 20, 'credit_max' => 60 ) );

		$this->assertSame( array( 25, 50 ), Topup::amounts() );
		$this->assertNull( Topup::parse( '19' ) );
		$this->assertSame( 6000, Topup::parse( '60' ) );
		$this->assertNull( Topup::parse( '61' ) );
	}

	public function test_form_input_is_checked_against_the_list_and_the_limits(): void {
		$this->assertSame( 5000, Topup::amount_from_post( array( 'beltoft_credit_amount' => '50' ) ) );
		$this->assertNull( Topup::amount_from_post( array( 'beltoft_credit_amount' => '30' ) ), 'A tile value that is not offered.' );
		$this->assertNull( Topup::amount_from_post( array( 'beltoft_credit_amount' => '12.5' ) ) );
		$this->assertSame( 3000, Topup::amount_from_post( array( 'beltoft_credit_amount' => 'custom', 'beltoft_credit_custom' => '30' ) ) );
		$this->assertNull( Topup::amount_from_post( array( 'beltoft_credit_amount' => 'custom', 'beltoft_credit_custom' => '5' ) ) );
		$this->assertNull( Topup::amount_from_post( array( 'beltoft_credit_amount' => 'custom' ) ) );
		$this->assertNull( Topup::amount_from_post( array() ) );
	}

	public function test_the_cart_line_is_priced_from_the_validated_amount_only(): void {
		wp_set_current_user( $this->new_user() );

		$this->assertTrue( Topup::add_to_cart( 2500 ) );
		WC()->cart->calculate_totals();

		$items = array_values( WC()->cart->get_cart() );
		$this->assertCount( 1, $items );
		$this->assertSame( 1, $items[0]['quantity'] );
		$this->assertEqualsWithDelta( 25.0, (float) WC()->cart->get_cart_contents_total(), 0.001 );
		$this->assertSame( 'Account credit, 25.00 EUR', wp_strip_all_tags( apply_filters( 'woocommerce_cart_item_name', 'x', $items[0], '' ) ) );

		// A second top-up replaces the first.
		$this->assertTrue( Topup::add_to_cart( 5000 ) );
		WC()->cart->calculate_totals();
		$this->assertCount( 1, WC()->cart->get_cart() );
		$this->assertEqualsWithDelta( 50.0, (float) WC()->cart->get_cart_contents_total(), 0.001 );
	}

	public function test_the_product_cannot_be_added_any_other_way(): void {
		wp_set_current_user( $this->new_user() );

		$pid = Topup::product_id();
		$this->assertFalse( apply_filters( 'woocommerce_add_to_cart_validation', true, $pid, 1 ), 'The add-to-cart form and URL.' );

		// A direct cart call (a block, a custom script) has no validated amount, so totals drop the line.
		WC()->cart->add_to_cart( $pid, 1 );
		WC()->cart->calculate_totals();
		$this->assertCount( 0, WC()->cart->get_cart() );

		wp_set_current_user( 0 );
		$this->assertFalse( Topup::add_to_cart( 2500 ), 'A visitor has no balance to top up.' );

		$this->settings( array( 'prepaid_enabled' => 0 ) );
		wp_set_current_user( $this->new_user() );
		$this->assertFalse( Topup::add_to_cart( 2500 ), 'Switched off.' );
	}

	public function test_a_line_without_a_valid_amount_is_dropped_at_totals(): void {
		wp_set_current_user( $this->new_user() );
		WC()->cart->add_to_cart( Topup::product_id(), 1, 0, array(), array( Topup::CART_KEY => 100 ) );
		WC()->cart->calculate_totals();
		$this->assertCount( 0, WC()->cart->get_cart(), 'Below the smallest top-up.' );

		Topup::add_to_cart( 2500 );
		$this->settings( array( 'prepaid_enabled' => 0 ) );
		WC()->cart->calculate_totals();
		$this->assertCount( 0, WC()->cart->get_cart(), 'The switch went off.' );
	}

	public function test_coupons_do_not_apply(): void {
		$this->assertFalse( apply_filters( 'woocommerce_coupon_is_valid_for_product', true, wc_get_product( Topup::product_id() ), new \WC_Coupon(), array() ) );
	}

	public function test_a_paid_order_credits_the_full_amount_paid_once(): void {
		$this->vat( true );
		$u     = $this->new_user();
		$order = $this->topup_order( $u, 2500 );

		$this->assertEqualsWithDelta( 25.0, (float) $order->get_total(), 0.001, 'The customer pays the chosen amount, VAT inside.' );
		$this->assertEqualsWithDelta( 5.0, (float) $order->get_total_tax(), 0.001 );
		$this->assertSame( 0, Balance::get( $u ), 'Nothing before payment.' );

		$order->payment_complete();
		$this->assertSame( 2500, Balance::get( $u ), 'The whole amount, VAT included.' );

		// Webhook retries and status flips do not credit again.
		$order->payment_complete();
		wc_get_order( $order->get_id() )->update_status( 'completed' );
		do_action( 'woocommerce_order_status_processing', $order->get_id() );
		do_action( 'woocommerce_payment_complete', $order->get_id() );
		$this->assertSame( 2500, Balance::get( $u ) );

		$rows = Balance::history( $u );
		$this->assertCount( 1, $rows );
		$this->assertSame( 'topup', $rows[0]['kind'] );
		$this->assertSame( $order->get_id() . '-' . $this->line_id( $order ), $rows[0]['ref_id'] );
	}

	public function test_an_odd_rate_still_credits_exactly_what_was_paid(): void {
		$this->vat( true );
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- test fixture.
		$wpdb->query( "UPDATE {$wpdb->prefix}woocommerce_tax_rates SET tax_rate = '21.0000'" );
		\WC_Cache_Helper::invalidate_cache_group( 'taxes' );
		$u = $this->new_user();
		wp_set_current_user( $u );

		foreach ( array( 1000, 3333, 12345 ) as $cents ) {
			WC()->cart->empty_cart();
			$this->assertTrue( Topup::add_to_cart( $cents ) );
			WC()->cart->calculate_totals();
			$this->assertSame( $cents, Balance::cents( WC()->cart->get_total( 'edit' ) ), "Paid {$cents}" );
		}
	}

	public function test_the_cart_price_has_the_vat_inside_whatever_the_store_setting(): void {
		$this->vat( true );
		wp_set_current_user( $this->new_user() );
		WC()->customer->set_billing_country( 'DK' );

		Topup::add_to_cart( 5000 );
		WC()->cart->calculate_totals();
		$this->assertEqualsWithDelta( 50.0, (float) WC()->cart->get_total( 'edit' ), 0.005 );
		$this->assertEqualsWithDelta( 10.0, (float) WC()->cart->get_total_tax(), 0.005 );

		update_option( 'woocommerce_prices_include_tax', 'yes' );
		WC()->cart->empty_cart();
		Topup::add_to_cart( 5000 );
		WC()->cart->calculate_totals();
		$this->assertEqualsWithDelta( 50.0, (float) WC()->cart->get_total( 'edit' ), 0.005 );
		update_option( 'woocommerce_prices_include_tax', 'no' );
	}

	public function test_an_exempt_customer_pays_the_amount_without_vat(): void {
		$this->vat( true );
		wp_set_current_user( $this->new_user() );
		WC()->customer->set_is_vat_exempt( true );

		Topup::add_to_cart( 5000 );
		WC()->cart->calculate_totals();
		$this->assertEqualsWithDelta( 50.0, (float) WC()->cart->get_total( 'edit' ), 0.005 );
		$this->assertEqualsWithDelta( 0.0, (float) WC()->cart->get_total_tax(), 0.005 );
		WC()->customer->set_is_vat_exempt( false );
	}

	public function test_a_reverse_charged_order_is_remembered_for_the_debit(): void {
		$u     = $this->new_user();
		$order = $this->topup_order( $u, 5000 );
		$order->update_meta_data( 'is_vat_exempt', 'yes' );
		$order->save();
		$order->update_status( 'processing' );

		$this->assertSame( 5000, Balance::get( $u ) );
		$this->assertSame( 'yes', get_user_meta( $u, Config::META_EXEMPT, true ) );
		$this->assertSame( 0.0, Config::vat_rate( $u ) );
	}

	public function test_reverse_charge_without_vat_credits_the_same(): void {
		$u     = $this->new_user();
		$order = $this->topup_order( $u, 5000 );

		$this->assertEqualsWithDelta( 50.0, (float) $order->get_total(), 0.001 );
		$order->update_status( 'processing' );
		$this->assertSame( 5000, Balance::get( $u ) );
	}

	public function test_an_unpaid_order_credits_nothing(): void {
		$u = $this->new_user();

		foreach ( array( 'pending', 'on-hold', 'failed', 'cancelled' ) as $status ) {
			$this->topup_order( $u, 1000, $status );
		}

		$this->assertSame( 0, Balance::get( $u ) );
	}

	public function test_an_order_of_other_products_is_ignored(): void {
		$u     = $this->new_user();
		$order = wc_create_order( array( 'customer_id' => $u ) );
		$order->update_status( 'processing' );

		$this->assertSame( 0, Balance::get( $u ) );
		$this->assertSame( array(), Balance::history( $u ) );
	}

	public function test_the_amount_can_be_changed_by_a_filter(): void {
		$this->vat( true );
		add_filter(
			'beltoft_services_credit_amount_from_order',
			static function ( int $cents, $item ) {
				return Balance::cents( (float) $item->get_total() );
			},
			10,
			2
		);
		$u = $this->new_user();
		$this->topup_order( $u, 2500, 'processing' );

		$this->assertSame( 2000, Balance::get( $u ), 'The net choice.' );
	}

	public function test_a_guest_order_cannot_be_credited(): void {
		$order = $this->topup_order( 0, 2500, 'processing' );

		$notes = array_map( static fn( $n ) => $n->content, wc_get_order_notes( array( 'order_id' => $order->get_id() ) ) );
		$this->assertNotEmpty( array_filter( $notes, static fn( $n ) => str_contains( $n, 'no customer account' ) ) );
	}

	public function test_a_line_item_refund_takes_the_gross_amount_back(): void {
		$this->vat( true );
		$u     = $this->new_user();
		$order = $this->topup_order( $u, 5000, 'processing' ); // 40 net, 10 VAT.
		$item  = $this->line_id( $order );
		$tax   = (int) array_key_first( $order->get_taxes() );

		$refund = wc_create_refund(
			array(
				'order_id'   => $order->get_id(),
				'amount'     => '10.00',
				'line_items' => array( $item => array( 'qty' => 0, 'refund_total' => 8, 'refund_tax' => array( $tax => 2 ) ) ),
			)
		);
		$this->assertNotWPError( $refund );

		$this->assertSame( 4000, Balance::get( $u ) );
		$rows = Balance::history( $u );
		$this->assertSame( 'refund', $rows[0]['kind'] );
		$this->assertSame( '-1000', $rows[0]['amount_cents'] );

		// The hook again for the same refund changes nothing.
		do_action( 'woocommerce_order_refunded', $order->get_id(), $refund->get_id() );
		$this->assertSame( 4000, Balance::get( $u ) );
	}

	public function test_an_amount_refund_takes_the_same_share(): void {
		$this->vat( true );
		$u     = $this->new_user();
		$order = $this->topup_order( $u, 5000, 'processing' ); // 50.00 gross.

		$refund = wc_create_refund( array( 'order_id' => $order->get_id(), 'amount' => '25.00' ) );
		$this->assertNotWPError( $refund );

		$this->assertSame( 2500, Balance::get( $u ), 'Half of the order, half of the credit.' );
	}

	public function test_a_full_refund_is_one_debit_and_the_status_change_adds_none(): void {
		$u     = $this->new_user();
		$order = $this->topup_order( $u, 2500, 'completed' );
		$item  = $this->line_id( $order );

		wc_create_refund(
			array(
				'order_id'   => $order->get_id(),
				'amount'     => '25.00',
				'line_items' => array( $item => array( 'qty' => 1, 'refund_total' => 25, 'refund_tax' => array() ) ),
			)
		);

		$this->assertSame( 0, Balance::get( $u ) );
		$kinds = array_column( Balance::history( $u ), 'kind' );
		$this->assertSame( array( 'refund', 'topup' ), $kinds );
	}

	public function test_a_refund_after_the_credit_was_spent_makes_the_balance_negative_and_says_so(): void {
		$u     = $this->new_user();
		$order = $this->topup_order( $u, 2500, 'processing' );
		Balance::debit( $u, 2000, Balance::USAGE, 'usage_day', 'x' );

		wc_create_refund( array( 'order_id' => $order->get_id(), 'amount' => '25.00' ) );

		$this->assertSame( -2000, Balance::get( $u ) );
		$notes = array_map( static fn( $n ) => $n->content, wc_get_order_notes( array( 'order_id' => $order->get_id() ) ) );
		$this->assertNotEmpty( array_filter( $notes, static fn( $n ) => str_contains( $n, 'now negative' ) ) );
	}

	public function test_a_chargeback_takes_the_credit_back_once(): void {
		$u     = $this->new_user();
		$order = $this->topup_order( $u, 2500, 'processing' );
		$this->assertSame( 2500, Balance::get( $u ) );

		$order->update_status( 'on-hold' );
		$this->assertSame( 0, Balance::get( $u ) );
		$this->assertSame( 'chargeback', Balance::history( $u )[0]['kind'] );

		// Failed after on-hold is not paid before, so nothing more; a repeated hook neither.
		wc_get_order( $order->get_id() )->update_status( 'failed' );
		do_action( 'woocommerce_order_status_changed', $order->get_id(), 'processing', 'on-hold', $order );
		$this->assertSame( 0, Balance::get( $u ) );
		$this->assertCount( 2, Balance::history( $u ) );
	}

	/**
	 * @dataProvider chargeback_states
	 */
	public function test_each_unpaid_state_is_a_chargeback( string $from, string $to ): void {
		$u     = $this->new_user();
		$order = $this->topup_order( $u, 1000, $from );
		$order->update_status( $to );

		$this->assertSame( 0, Balance::get( $u ) );
	}

	/** @return array<string, array{0: string, 1: string}> */
	public function chargeback_states(): array {
		return array(
			'processing to on-hold'  => array( 'processing', 'on-hold' ),
			'completed to on-hold'   => array( 'completed', 'on-hold' ),
			'completed to failed'    => array( 'completed', 'failed' ),
			'processing to refunded' => array( 'processing', 'refunded' ),
			'completed to cancelled' => array( 'completed', 'cancelled' ),
		);
	}

	public function test_back_to_completed_returns_the_credit_once_and_never_doubles(): void {
		$u     = $this->new_user();
		$order = $this->topup_order( $u, 2500, 'processing' );

		$order->update_status( 'on-hold' );
		$this->assertSame( 0, Balance::get( $u ) );

		wc_get_order( $order->get_id() )->update_status( 'completed' );
		$this->assertSame( 2500, Balance::get( $u ), 'Paid again: the credit is back, once.' );

		wc_get_order( $order->get_id() )->update_status( 'processing' );
		do_action( 'woocommerce_payment_complete', $order->get_id() );
		$this->assertSame( 2500, Balance::get( $u ) );

		// A second chargeback after that is again one debit, and a second return one credit.
		wc_get_order( $order->get_id() )->update_status( 'on-hold' );
		$this->assertSame( 0, Balance::get( $u ) );
		wc_get_order( $order->get_id() )->update_status( 'completed' );
		$this->assertSame( 2500, Balance::get( $u ) );
	}

	public function test_a_refunded_part_stays_refunded_after_a_chargeback_round(): void {
		$u     = $this->new_user();
		$order = $this->topup_order( $u, 5000, 'processing' );
		wc_create_refund( array( 'order_id' => $order->get_id(), 'amount' => '10.00' ) ); // 20 percent: 1000.
		$this->assertSame( 4000, Balance::get( $u ) );

		wc_get_order( $order->get_id() )->update_status( 'on-hold' );
		$this->assertSame( 0, Balance::get( $u ), 'Only what is left is taken back.' );

		wc_get_order( $order->get_id() )->update_status( 'completed' );
		$this->assertSame( 4000, Balance::get( $u ) );
	}

	public function test_the_product_page_redirects_to_the_credit_page(): void {
		$id = Topup::product_id();
		$this->go_to( get_permalink( $id ) );

		$redirected = null;
		add_filter(
			'wp_redirect',
			static function ( $location ) use ( &$redirected ) {
				$redirected = $location;
				throw new \RuntimeException( 'redirect' );
			}
		);

		try {
			Topup::no_product_page();
		} catch ( \RuntimeException $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement -- the redirect is the result.
		}

		$this->assertSame( Page::url(), $redirected );
	}

	public function test_the_line_reads_account_credit_with_the_amount(): void {
		$u     = $this->new_user();
		$order = $this->topup_order( $u, 5000 );
		$item  = Topup::items( $order )[ $this->line_id( $order ) ];

		$this->assertSame( 'Account credit, 50.00 EUR', $item->get_name() );
	}

	public function test_the_page_shows_balance_history_form_and_nothing_when_off(): void {
		$u = $this->new_user();
		wp_set_current_user( $u );
		$this->topup_order( $u, 2500, 'processing' );

		$html = Page::html();
		$this->assertStringContainsString( 'beltoft-services-credit', $html );
		$this->assertStringContainsString( '25.00', $html );
		$this->assertStringContainsString( 'Top-up, order #', $html );
		foreach ( array( 10, 25, 50, 100, 250 ) as $amount ) {
			$this->assertStringContainsString( 'name="beltoft_credit_amount" value="' . $amount . '"', $html );
		}
		$this->assertStringContainsString( 'value="custom"', $html );
		$this->assertStringContainsString( 'name="beltoft_credit_custom"', $html );
		$this->assertStringContainsString( Topup::NONCE, $html );
		$this->assertStringNotContainsString( "\u{2014}", $html );
		$this->assertStringNotContainsString( "\u{00B7}", $html );
		$this->assertStringNotContainsString( '&middot;', $html );

		$this->settings( array( 'prepaid_enabled' => 0 ) );
		$this->assertSame( '', Page::html() );
	}

	public function test_the_menu_has_the_credit_item_only_when_on(): void {
		$items = Page::menu( array( 'orders' => 'Orders', 'customer-logout' => 'Log out' ) );
		$this->assertSame( array( 'orders', 'credit', 'customer-logout' ), array_keys( $items ) );

		$this->settings( array( 'prepaid_enabled' => 0 ) );
		$this->assertSame( array( 'orders', 'customer-logout' ), array_keys( Page::menu( array( 'orders' => 'Orders', 'customer-logout' => 'Log out' ) ) ) );
		$this->assertArrayNotHasKey( 'credit', Page::billing_set( array() ) );
	}

	public function test_the_credit_page_is_a_billing_tab(): void {
		$this->assertSame( array( 'credit' => array( 'credit' ) ), Page::billing_set( array() ) );
		$this->assertSame( array( 'credit' => 'Credit' ), Page::billing_label( array() ) );
	}
}

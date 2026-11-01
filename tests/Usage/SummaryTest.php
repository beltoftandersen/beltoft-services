<?php
/**
 * How a pay-as-you-go server reads in the summary, cart and order views.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Usage;

use BeltoftServices\Usage\Account;
use BeltoftServices\Usage\BillingField;
use BeltoftServices\Usage\Summary;

require_once __DIR__ . '/UsageTestCase.php';

/**
 * Display only.
 */
class SummaryTest extends UsageTestCase {

	private function add( \WC_Product_Variation $v ): string {
		$key = WC()->cart->add_to_cart( $v->get_parent_id(), 1, $v->get_id(), array( 'attribute_billing' => $v->get_attributes()['billing'], 'attribute_backups' => $v->get_attributes()['backups'] ) );
		$this->assertNotFalse( $key );
		WC()->cart->calculate_totals();

		return (string) $key;
	}

	private function account_item(): array {
		$id = (int) get_option( Account::OPTION_PRODUCT );
		foreach ( WC()->cart->get_cart() as $item ) {
			if ( (int) $item['product_id'] === $id ) {
				return $item;
			}
		}

		return array();
	}

	private const HTML = '<div id="brxe-ob" class="x"><!--brx-loop-start-ob--></div><div id="bricks-woo-checkout-order-summary"><!--brx-loop-start-x--><div id="brxe-8c507f" class="brxe-text-basic">Total</div></div>';

	public function test_the_account_line_is_hidden_but_stays_in_the_cart(): void {
		$v = $this->usage_product( '0' );
		wp_set_current_user( $this->new_user() );
		$key  = $this->add( $v['u-None'] );
		$acct = $this->account_item();

		$this->assertNotSame( array(), $acct, 'The account line is in the cart.' );

		foreach ( array( 'woocommerce_cart_item_visible', 'woocommerce_checkout_cart_item_visible', 'woocommerce_widget_cart_item_visible' ) as $filter ) {
			$this->assertFalse( apply_filters( $filter, true, $acct, 'k' ), $filter );
			$this->assertTrue( apply_filters( $filter, true, WC()->cart->get_cart()[ $key ], $key ), $filter . ' keeps the server.' );
		}
	}

	public function test_the_server_line_shows_rate_and_cap_and_real_prices_are_untouched(): void {
		$v = $this->usage_product( '0' );
		wp_set_current_user( $this->new_user() );
		$key  = $this->add( $v['u-None'] );
		$item = WC()->cart->get_cart()[ $key ];

		$this->assertStringContainsString( '0.0205', apply_filters( 'woocommerce_cart_item_price', '€0.00', $item, $key ) );
		$this->assertStringContainsString( '/ hour', apply_filters( 'woocommerce_cart_item_price', '€0.00', $item, $key ) );
		$this->assertStringContainsString( 'max', apply_filters( 'woocommerce_cart_item_subtotal', '€0.00', $item, $key ) );
		$this->assertStringContainsString( '15.00', apply_filters( 'woocommerce_cart_item_subtotal', '€0.00', $item, $key ) );
		$this->assertStringContainsString( '/ month', apply_filters( 'woocommerce_cart_item_subtotal', '€0.00', $item, $key ) );
		$this->assertEquals( 0, WC()->cart->get_total( 'edit' ) );
		$this->assertEquals( 0, $item['line_subtotal'] );
	}

	public function test_a_monthly_line_keeps_its_price(): void {
		$v = $this->usage_product( '0' );
		wp_set_current_user( $this->new_user() );
		$key  = $this->add( $v['m-None'] );
		$item = WC()->cart->get_cart()[ $key ];

		$this->assertSame( '15.00', apply_filters( 'woocommerce_cart_item_price', '15.00', $item, $key ) );
		$this->assertSame( '15.00', apply_filters( 'woocommerce_cart_item_subtotal', '15.00', $item, $key ) );
	}

	public function test_the_note_is_said_once_at_checkout_and_total_reads_due_today(): void {
		$v = $this->usage_product( '0' );
		wp_set_current_user( $this->new_user() );
		$this->add( $v['u-None'] );

		$out = Summary::checkout_summary( self::HTML );

		$this->assertSame( 1, substr_count( $out, 'Billed monthly for the hours used, excl. VAT.' ) );
		$this->assertGreaterThan( strpos( $out, 'bricks-woo-checkout-order-summary' ), strpos( $out, 'payg-note' ), 'The note is in the summary, not in an order bar.' );
		$this->assertStringContainsString( 'Your card is saved now and charged after each month.', $out );
		$this->assertStringContainsString( '>Due today<', $out );
		$this->assertStringNotContainsString( '>Total<', $out );
	}

	public function test_a_cart_without_a_usage_server_is_untouched(): void {
		$v = $this->usage_product( '0' );
		wp_set_current_user( $this->new_user() );
		$this->add( $v['m-None'] );

		$this->assertSame( self::HTML, Summary::checkout_summary( self::HTML ) );
		$this->assertSame( '<p>Total</p>', Summary::checkout_summary( '<p>Total</p>' ) );
	}

	public function test_a_mixed_cart_keeps_the_monthly_price_and_total_and_has_the_note(): void {
		$v = $this->usage_product( '0' );
		wp_set_current_user( $this->new_user() );
		$this->add( $v['u-None'] );
		$simple = new \WC_Product_Simple();
		$simple->set_name( 'Mail' );
		$simple->set_regular_price( '10' );
		$simple->save();
		$key = WC()->cart->add_to_cart( $simple->get_id() );
		WC()->cart->calculate_totals();
		$item = WC()->cart->get_cart()[ $key ];

		$this->assertSame( '€10.00', apply_filters( 'woocommerce_cart_item_price', '€10.00', $item, $key ) );
		$this->assertSame( '€10.00', apply_filters( 'woocommerce_cart_item_subtotal', '€10.00', $item, $key ) );
		$this->assertGreaterThan( 0, (float) WC()->cart->get_total( "edit" ) );

		$out = Summary::checkout_summary( self::HTML );

		$this->assertStringContainsString( 'Billed monthly for the hours used', $out );
		$this->assertStringContainsString( '>Total<', $out, 'Something is due, so it stays Total.' );
	}

	public function test_the_billing_row_is_left_to_the_note_at_checkout_only(): void {
		$v = $this->usage_product( '0' );
		wp_set_current_user( $this->new_user() );
		$key  = $this->add( $v['u-None'] );
		$item = WC()->cart->get_cart()[ $key ];
		$rows = static fn() => wp_list_pluck( (array) apply_filters( 'woocommerce_get_item_data', array(), $item ), 'key' );

		$this->assertContains( 'Billing', $rows(), 'Cart and mini cart keep the note on the server line.' );

		add_filter( 'woocommerce_is_checkout', '__return_true' );
		$this->assertNotContains( 'Billing', $rows() );
		remove_filter( 'woocommerce_is_checkout', '__return_true' );
	}

	public function test_order_views_hide_the_account_line_and_show_rate_and_cap(): void {
		$v    = $this->usage_product( '0' );
		$user = $this->new_user();
		wp_set_current_user( $user );
		$this->add( $v['u-None'] );

		$order = wc_create_order( array( 'customer_id' => $user ) );
		$order->add_product( $v['u-None'], 1 );
		$order->add_product( wc_get_product( Account::product_id() ), 1 );
		$order->save();

		foreach ( $order->get_items() as $item ) {
			if ( (int) $item->get_product_id() === Account::product_id() ) {
				$this->assertFalse( apply_filters( 'woocommerce_order_item_visible', true, $item ) );
			} else {
				$this->assertTrue( apply_filters( 'woocommerce_order_item_visible', true, $item ) );
				$text = $order->get_formatted_line_subtotal( $item );
				$this->assertStringContainsString( 'hour', $text );
				$this->assertStringContainsString( 'max', $text );
			}
		}
	}

	public function test_the_tiles_say_what_each_choice_means(): void {
		$v     = $this->usage_product( '0' );
		$tiles = BillingField::tiles( $v['m-None'] );

		$this->assertStringContainsString( '15.00', $tiles['monthly'][0] );
		$this->assertStringContainsString( 'per month, paid in advance', $tiles['monthly'][0] );
		$this->assertStringContainsString( '18.00', $tiles['monthly'][1] );
		$this->assertStringContainsString( '0.0205 per hour, max', $tiles['usage'][0] );
		$this->assertStringContainsString( '18.00 per month', str_replace( "\u{a0}", ' ', $tiles['usage'][1] ) );
		$this->assertSame( 'Billed monthly for the hours used', BillingField::tile_note() );
	}
}

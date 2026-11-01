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

	private const HTML = '<div id="brxe-ob" class="x"><!--brx-loop-start-ob--></div><div id="bricks-woo-checkout-order-summary"><div id="brxe-i1" class="brxe-div">item</div><div id="brxe-7e1d8f" class="brxe-divider horizontal"><div class="line"></div></div><div id="brxe-8c507f" class="brxe-text-basic">Total</div></div>';

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
		$this->assertStringContainsString( '15 /', apply_filters( 'woocommerce_cart_item_subtotal', '€0.00', $item, $key ) );
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
		$this->assertStringContainsString( '18 per month', str_replace( "\u{a0}", ' ', $tiles['usage'][1] ) );
		$this->assertSame( 'Billed monthly for the hours used', \BeltoftServices\Usage\Rates::billed_note() );
	}

	public function test_monthly_not_offered_swaps_a_new_monthly_server_to_pay_as_you_go(): void {
		$this->settings( array( 'offer_monthly' => 0 ) );
		$v = $this->usage_product( '0' );
		wp_set_current_user( $this->new_user() );
		$key = WC()->cart->add_to_cart( $v['m-None']->get_parent_id(), 1, $v['m-None']->get_id(), array( 'attribute_billing' => 'Monthly', 'attribute_backups' => 'None' ) );
		WC()->cart->calculate_totals();

		$ids = array_map( static fn( $i ) => (int) $i['variation_id'], WC()->cart->get_cart() );

		$this->assertContains( $v['u-None']->get_id(), $ids );
		$this->assertNotContains( $v['m-None']->get_id(), $ids );
		$this->assertNotFalse( $key );
		$this->assertSame( 1, \BeltoftServices\Usage\Cart::usage_units() );
	}

	public function test_monthly_not_offered_keeps_backups_in_the_swap(): void {
		$this->settings( array( 'offer_monthly' => 0 ) );
		$v = $this->usage_product( '0' );
		wp_set_current_user( $this->new_user() );
		WC()->cart->add_to_cart( $v['m-Daily']->get_parent_id(), 1, $v['m-Daily']->get_id(), array( 'attribute_billing' => 'Monthly', 'attribute_backups' => 'Daily' ) );

		$ids = array_map( static fn( $i ) => (int) $i['variation_id'], WC()->cart->get_cart() );

		$this->assertContains( $v['u-Daily']->get_id(), $ids );
	}

	public function test_monthly_offered_leaves_a_monthly_server_alone(): void {
		$this->settings( array( 'offer_monthly' => 1 ) );
		$v = $this->usage_product( '0' );
		wp_set_current_user( $this->new_user() );
		WC()->cart->add_to_cart( $v['m-None']->get_parent_id(), 1, $v['m-None']->get_id(), array( 'attribute_billing' => 'Monthly', 'attribute_backups' => 'None' ) );

		$ids = array_map( static fn( $i ) => (int) $i['variation_id'], WC()->cart->get_cart() );

		$this->assertContains( $v['m-None']->get_id(), $ids );
	}

	public function test_monthly_not_offered_never_swaps_a_renewal_or_switch(): void {
		$this->settings( array( 'offer_monthly' => 0 ) );
		$v = $this->usage_product( '0' );
		wp_set_current_user( $this->new_user() );

		foreach ( array( 'subscription_renewal', 'subscription_switch', 'subscription_resubscribe' ) as $flag ) {
			$this->settings( array( 'offer_monthly' => 1 ) );
			WC()->cart->empty_cart();
			$key = WC()->cart->add_to_cart( $v['m-None']->get_parent_id(), 1, $v['m-None']->get_id(), array( 'attribute_billing' => 'Monthly', 'attribute_backups' => 'None' ) );
			$contents                       = WC()->cart->get_cart_contents();
			$contents[ $key ][ $flag ]      = array( 'subscription_id' => 1 );
			WC()->cart->set_cart_contents( $contents );
			$this->settings( array( 'offer_monthly' => 0 ) );
			BillingField::enforce();
			$ids = array_map( static fn( $i ) => (int) $i['variation_id'], WC()->cart->get_cart() );

			$this->assertContains( $v['m-None']->get_id(), $ids, $flag );
		}
	}

	public function test_monthly_not_offered_hides_the_checkout_box_and_leaves_admin_orders_alone(): void {
		$this->settings( array( 'offer_monthly' => 0 ) );
		$v = $this->usage_product( '0' );
		wp_set_current_user( $this->new_user() );
		$order = wc_create_order();
		$order->add_product( $v['m-None'], 1 );
		$order->save();
		$items = array_values( $order->get_items() );

		$this->assertSame( $v['m-None']->get_id(), (int) $items[0]->get_variation_id(), 'An order made outside the cart keeps its monthly plan.' );

		$this->add( $v['u-None'] );
		ob_start();
		BillingField::render();

		$this->assertSame( '', (string) ob_get_clean(), 'No billing box.' );
		$this->assertFalse( \BeltoftServices\Usage\Rates::monthly_offered() );
	}

	public function test_a_whole_euro_cap_has_no_decimals_and_other_amounts_keep_two(): void {
		$this->assertSame( "\u{20AC}15", str_replace( '$', "\u{20AC}", \BeltoftServices\Usage\Rates::cap_money( 15.0 ) ) );
		$this->assertSame( "\u{20AC}55", str_replace( '$', "\u{20AC}", \BeltoftServices\Usage\Rates::cap_money( 55.004 ) ) );
		$this->assertSame( "\u{20AC}16.50", str_replace( '$', "\u{20AC}", \BeltoftServices\Usage\Rates::cap_money( 16.5 ) ) );
		$this->assertStringContainsString( 'max ', \BeltoftServices\Usage\Rates::max_per_month( 15.0 ) );
		$this->assertStringContainsString( "\u{20AC}15 ", str_replace( '$', "\u{20AC}", str_replace( "\u{a0}", ' ', \BeltoftServices\Usage\Rates::max_per_month( 15.0 ) ) ) );
		$this->assertStringContainsString( '0.0226', \BeltoftServices\Usage\Rates::money( 0.0226, 4 ) );
	}

	public function test_monthly_not_offered_never_touches_a_mail_plan_or_a_plugin_licence_in_the_cart(): void {
		$this->settings( array( 'offer_monthly' => 0 ) );
		$vps  = $this->usage_product( '0' );
		$mail = $this->usage_product( '0' );
		$mail['m-None']->get_parent_id();
		update_post_meta( $mail['m-None']->get_parent_id(), \BeltoftServices\Product::META_DRIVER, 'http' );
		wp_set_current_user( $this->new_user() );

		$plugin = new \WC_Product_Simple();
		$plugin->set_name( 'Plugin' );
		$plugin->set_regular_price( '49' );
		$plugin->save();

		WC()->cart->add_to_cart( $mail['m-None']->get_parent_id(), 1, $mail['m-None']->get_id(), array( 'attribute_billing' => 'Monthly', 'attribute_backups' => 'None' ) );
		WC()->cart->add_to_cart( $plugin->get_id() );
		WC()->cart->add_to_cart( $vps['m-None']->get_parent_id(), 1, $vps['m-None']->get_id(), array( 'attribute_billing' => 'Monthly', 'attribute_backups' => 'None' ) );

		$ids = array_map( static fn( $i ) => (int) ( $i['variation_id'] ?: $i['product_id'] ), WC()->cart->get_cart() );

		$this->assertContains( $mail['m-None']->get_id(), $ids, 'The mail plan stays monthly.' );
		$this->assertNotContains( $mail['u-None']->get_id(), $ids );
		$this->assertContains( $plugin->get_id(), $ids, 'The plugin licence is untouched.' );
		$this->assertContains( $vps['u-None']->get_id(), $ids, 'The server is swapped.' );
		$this->assertNotContains( $vps['m-None']->get_id(), $ids );

		foreach ( WC()->cart->get_cart() as $key => $item ) {
			if ( (int) $item['product_id'] === $plugin->get_id() || (int) $item['variation_id'] === $mail['m-None']->get_id() ) {
				$this->assertSame( $item['data']->get_price(), apply_filters( 'woocommerce_cart_item_price', $item['data']->get_price(), $item, $key ) );
			}
		}
	}
}

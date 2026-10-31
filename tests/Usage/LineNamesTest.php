<?php
/**
 * One readable name for the plugin's product lines.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Usage;

use BeltoftServices\LineNames;
use BeltoftServices\Usage\Account;

require_once __DIR__ . '/UsageTestCase.php';

/**
 * Names in orders and the cart.
 */
class LineNamesTest extends UsageTestCase {

	/**
	 * An order with one line for a variation, and the line.
	 *
	 * @param \WC_Product_Variation $variation Variation.
	 */
	private function line( \WC_Product_Variation $variation ): \WC_Order_Item_Product {
		$order = wc_create_order();
		$order->add_product( $variation, 1 );
		$order->save();
		$items = array_values( $order->get_items() );

		return $items[0];
	}

	public function test_a_monthly_line_with_backups_reads_plan_and_backups(): void {
		$v    = $this->usage_product();
		$name = wc_get_product( $v['m-Daily']->get_parent_id() )->get_name();
		$item = $this->line( $v['m-Daily'] );

		$this->assertSame( $name . ', billed monthly, daily backups', LineNames::label( $v['m-Daily'] ) );
		$this->assertSame( $name . ', billed monthly, daily backups', apply_filters( 'woocommerce_order_item_name', $item->get_name(), $item, false ) );
		$this->assertStringNotContainsString( ' - ', LineNames::label( $v['m-Daily'] ) );
	}

	public function test_none_backups_are_not_mentioned_and_usage_says_pay_as_you_go(): void {
		$v    = $this->usage_product();
		$name = wc_get_product( $v['u-None']->get_parent_id() )->get_name();

		$this->assertSame( $name . ', billed monthly', LineNames::label( $v['m-None'] ) );
		$this->assertSame( $name . ', pay as you go', LineNames::label( $v['u-None'] ) );
	}

	public function test_a_linked_name_keeps_its_link(): void {
		$v    = $this->usage_product();
		$item = $this->line( $v['u-None'] );
		$out  = apply_filters( 'woocommerce_order_item_name', '<a href="https://example.com/p">Old - name</a>', $item, true );

		$this->assertStringStartsWith( '<a href="https://example.com/p">', $out );
		$this->assertStringContainsString( 'pay as you go</a>', $out );
	}

	public function test_the_meta_list_leaves_out_what_the_name_says_and_adds_the_usage_note(): void {
		$v    = $this->usage_product();
		$item = $this->line( $v['u-None'] );
		$item->add_meta_data( \BeltoftServices\Proxmox\ServerName::ITEM_META, 'web1', true );
		$item->save();
		$item = new \WC_Order_Item_Product( $item->get_id() );

		$html = wc_display_item_meta( $item, array( 'echo' => false ) );

		$this->assertStringNotContainsString( 'Backups', $html );
		$this->assertStringNotContainsString( 'Pay as you go</p>', $html );
		$this->assertStringContainsString( 'web1', $html );
		$this->assertStringContainsString( 'Billed monthly for the hours used, excl. VAT', $html );
	}

	public function test_the_usage_account_line_is_called_pay_as_you_go_account_with_the_note(): void {
		$user = $this->new_user();
		$sub  = $this->usage_account( $user );
		$items = array_values( $sub->get_items() );
		$item  = $items[0];

		$this->assertSame( 'Pay as you go account', apply_filters( 'woocommerce_order_item_name', $item->get_name(), $item, false ) );
		$this->assertSame( 'Usage account', $item->get_name(), 'The stored name is untouched.' );
		$this->assertStringContainsString( 'excl. VAT', wc_display_item_meta( $item, array( 'echo' => false ) ) );
		$this->assertSame( Account::product_id(), (int) $item->get_product_id() );
	}

	public function test_the_cart_line_gets_the_same_name_and_no_repeated_attribute_rows(): void {
		$v = $this->usage_product();
		WC()->cart->empty_cart();
		$attrs = array();

		foreach ( $v['u-None']->get_attributes() as $key => $value ) {
			$attrs[ 'attribute_' . $key ] = $value;
		}

		$key  = WC()->cart->add_to_cart( $v['u-None']->get_parent_id(), 1, $v['u-None']->get_id(), $attrs );
		$this->assertNotFalse( $key );
		$cart = WC()->cart->get_cart()[ $key ];

		$this->assertStringContainsString( 'pay as you go', apply_filters( 'woocommerce_cart_item_name', 'x', $cart, $key ) );

		$rows = wp_list_pluck( apply_filters( 'woocommerce_get_item_data', array( array( 'key' => 'Billing', 'value' => 'Pay as you go' ), array( 'key' => 'Backups', 'value' => 'None' ) ), $cart ), 'value', 'key' );

		$this->assertSame( array( 'Billing' => 'Billed monthly for the hours used, excl. VAT' ), $rows );
		WC()->cart->empty_cart();
	}

	public function test_other_products_keep_their_name(): void {
		$product = new \WC_Product_Simple();
		$product->set_name( 'T-shirt' );
		$product->save();

		$this->assertSame( '', LineNames::label( $product ) );
	}
}

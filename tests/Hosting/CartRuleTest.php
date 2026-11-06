<?php
/**
 * Hosting and Pay as you go items do not share a cart, and the customer is told what was removed.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Hosting;

require_once dirname( __DIR__ ) . '/Usage/UsageTestCase.php';

use BeltoftServices\Tests\Usage\UsageTestCase;

/**
 * Hosting\CartRule.
 */
class CartRuleTest extends UsageTestCase {

	public function set_up(): void {
		parent::set_up();
		// No node is set up here: the plan counts as sold out, which is not what these tests are about.
		add_filter( 'woocommerce_is_purchasable', '__return_true', 99 );
		add_filter( 'woocommerce_product_is_in_stock', '__return_true', 99 );
		WC()->cart->empty_cart();
		wc_clear_notices();
	}

	public function tear_down(): void {
		WC()->cart->empty_cart();
		wc_clear_notices();
		remove_filter( 'woocommerce_is_purchasable', '__return_true', 99 );
		remove_filter( 'woocommerce_product_is_in_stock', '__return_true', 99 );

		parent::tear_down();
	}

	/**
	 * What the add-to-cart form does: the validation filter first, then the cart.
	 */
	private function add_product( int $product_id, int $variation_id = 0, array $attributes = array() ) {
		apply_filters( 'woocommerce_add_to_cart_validation', true, $product_id, 1, $variation_id, $attributes, array() );

		return WC()->cart->add_to_cart( $product_id, 1, $variation_id, $attributes );
	}

	private function add_variation( \WC_Product_Variation $v ) {
		return $this->add_product( $v->get_parent_id(), $v->get_id(), array( 'attribute_billing' => $v->get_attributes()['billing'], 'attribute_backups' => $v->get_attributes()['backups'] ) );
	}

	private function hosting(): \WC_Product {
		return $this->service_product( 'hosting', array( 'node_group' => 'g1', 'sites' => 3, 'disk_gb' => 10, 'cores' => 2, 'memory_mb' => 2048 ), array( 'slug' => 'wp-' . wp_generate_password( 4, false ) ) );
	}

	/**
	 * @return string[] The notices, as text.
	 */
	private function notices(): array {
		return array_map( static fn( $n ) => is_array( $n ) ? (string) $n['notice'] : (string) $n, wc_get_notices( 'notice' ) );
	}

	public function test_hosting_added_to_pay_as_you_go_items_removes_them_and_says_which(): void {
		$payg = $this->usage_product()['u-None'];
		$this->assertNotFalse( $this->add_variation( $payg ) );
		wc_clear_notices();

		$host = $this->hosting();
		$key  = $this->add_product( $host->get_id() );
		$this->assertNotFalse( $key );

		$products = array_map( static fn( $i ) => (int) $i['product_id'], WC()->cart->get_cart() );
		$this->assertSame( array( $host->get_id() ), array_values( $products ), 'Only the hosting plan is left.' );

		$text = implode( ' ', $this->notices() );
		$this->assertStringContainsString( 'Your cart had Pay as you go items (', $text );
		$this->assertStringContainsString( 'They were removed, because hosting is ordered on its own.', $text );
		$this->assertStringContainsString( 'VPS', $text, 'The removed item is named.' );
		$this->assertStringNotContainsString( '—', $text );
		$this->assertStringNotContainsString( '·', $text );
	}

	public function test_a_pay_as_you_go_item_added_to_hosting_removes_the_hosting_and_says_which(): void {
		$host = $this->hosting();
		$this->assertNotFalse( $this->add_product( $host->get_id() ) );
		wc_clear_notices();

		$payg = $this->usage_product()['u-None'];
		$this->assertNotFalse( $this->add_variation( $payg ) );

		$products = array_map( static fn( $i ) => (int) $i['product_id'], WC()->cart->get_cart() );
		$this->assertNotContains( $host->get_id(), $products );
		$this->assertContains( $payg->get_parent_id(), $products );

		$text = implode( ' ', $this->notices() );
		$this->assertStringContainsString( 'Your cart had hosting (' . $host->get_name() . ')', $text );
		$this->assertStringContainsString( 'It was removed, because hosting is ordered on its own.', $text );
	}

	public function test_nothing_is_said_when_nothing_is_removed(): void {
		$host = $this->hosting();
		$this->assertNotFalse( $this->add_product( $host->get_id() ) );
		wc_clear_notices();

		$other = $this->hosting();
		$this->add_product( $other->get_id() );

		$this->assertSame( array(), preg_grep( '/Pay as you go|hosting \(/', $this->notices() ) );

		// A monthly (not pay as you go) variation does not trigger it either.
		WC()->cart->empty_cart();
		wc_clear_notices();
		$monthly = $this->usage_product()['m-None'];
		$this->add_variation( $monthly );
		$this->assertSame( array(), preg_grep( '/Your cart had/', $this->notices() ) );
	}
}

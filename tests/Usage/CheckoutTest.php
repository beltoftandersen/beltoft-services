<?php
/**
 * Pay-as-you-go checkout: the Billing choice, the usage account, the card rule, the limits.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Usage;

use BeltoftLicenses\Licenses;
use BeltoftServices\Resource;
use BeltoftServices\Usage\Account;
use BeltoftServices\Usage\Cart;
use BeltoftServices\Usage\Rates;

require_once __DIR__ . '/UsageTestCase.php';

/**
 * Checkout rules.
 */
class CheckoutTest extends UsageTestCase {

	public function set_up(): void {
		parent::set_up();
		update_option( 'woocommerce_subscriptions_zero_initial_payment_requires_payment', 'no' );
	}

	public function tear_down(): void {
		parent::tear_down();
	}

	private function add( \WC_Product_Variation $v ): string {
		$key = WC()->cart->add_to_cart( $v->get_parent_id(), 1, $v->get_id(), array( 'attribute_billing' => $v->get_attributes()['billing'], 'attribute_backups' => $v->get_attributes()['backups'] ) );
		$this->assertNotFalse( $key, 'The variation is in the cart.' );
		WC()->cart->calculate_totals();

		return (string) $key;
	}

	private function account_lines(): int {
		$id = (int) get_option( Account::OPTION_PRODUCT );
		$n  = 0;
		foreach ( WC()->cart->get_cart() as $item ) {
			$n += (int) ( $id > 0 && (int) $item['product_id'] === $id );
		}

		return $n;
	}

	public function test_a_usage_variation_of_a_subscription_product_is_not_a_subscription(): void {
		$parent = new \WC_Product_Variable_Subscription();
		$parent->set_name( 'Sub VPS' );
		$parent->set_status( 'publish' );
		$attr = new \WC_Product_Attribute();
		$attr->set_name( 'Billing' );
		$attr->set_options( array( 'Monthly', 'Pay as you go' ) );
		$attr->set_visible( true );
		$attr->set_variation( true );
		$parent->set_attributes( array( $attr ) );
		$parent->save();

		$made = array();
		foreach ( array( 'Monthly' => '15', 'Pay as you go' => '0' ) as $value => $price ) {
			$v = new \WC_Product_Subscription_Variation();
			$v->set_parent_id( $parent->get_id() );
			$v->set_attributes( array( 'billing' => $value ) );
			$v->set_regular_price( $price );
			$v->set_status( 'publish' );
			$v->update_meta_data( '_subscription_price', $price );
			$v->update_meta_data( '_subscription_period', 'month' );
			$v->update_meta_data( '_subscription_period_interval', '1' );
			if ( '0' === $price ) {
				$v->update_meta_data( Rates::META_BILLING, 'usage' );
			}
			$v->save();
			$made[ $value ] = wc_get_product( $v->get_id() );
		}

		$this->assertTrue( \WC_Subscriptions_Product::is_subscription( $made['Monthly'] ) );
		$this->assertFalse( \WC_Subscriptions_Product::is_subscription( $made['Pay as you go'] ) );
		$this->assertTrue( \BeltoftLicenses\Product::is_subscription( $made['Monthly'] ) );
		$this->assertFalse( \BeltoftLicenses\Product::is_subscription( $made['Pay as you go'] ) );
	}

	public function test_the_cart_gets_the_hidden_account_line_with_the_first_usage_server_and_loses_it_again(): void {
		$v = $this->usage_product();
		wp_set_current_user( $this->new_user() );

		$key = $this->add( $v['u-None'] );
		$this->assertSame( 1, $this->account_lines() );
		$this->assertTrue( WC()->cart->needs_payment(), 'A 0 cart with a usage server still needs a payment method.' );
		$this->assertEquals( 0, WC()->cart->get_total( 'edit' ) );

		WC()->cart->remove_cart_item( $key );
		$this->assertSame( 0, $this->account_lines() );
		$this->assertFalse( WC()->cart->needs_payment() );

		$product = wc_get_product( Account::product_id() );
		$this->assertSame( 'hidden', $product->get_catalog_visibility() );
		$this->assertTrue( \WC_Subscriptions_Product::is_subscription( $product ) );
		$this->assertSame( 'month', \WC_Subscriptions_Product::get_period( $product ) );
		$this->assertEquals( 0, \WC_Subscriptions_Product::get_price( $product ) );
	}

	public function test_a_monthly_server_does_not_get_an_account_line(): void {
		$v = $this->usage_product();
		wp_set_current_user( $this->new_user() );
		$this->add( $v['m-None'] );

		$this->assertSame( 0, $this->account_lines() );
		$this->assertSame( 0, Cart::usage_units() );
	}

	public function test_a_customer_with_an_account_gets_no_second_one(): void {
		$v    = $this->usage_product();
		$user = $this->new_user();
		$this->usage_account( $user );
		wp_set_current_user( $user );

		$this->add( $v['u-None'] );
		$this->assertSame( 0, $this->account_lines() );
		$this->assertFalse( WC()->cart->needs_payment(), 'The saved card is used.' );
	}

	public function test_the_order_is_refused_without_a_card_that_can_be_charged_later(): void {
		$this->assertSame( array(), Cart::problems( 0, 'bacs', 0 ), 'No usage server, no rule.' );

		$no = Cart::problems( 0, 'bacs', 1 );
		$this->assertCount( 1, $no );
		$this->assertStringContainsString( 'card', $no[0] );
		$this->assertCount( 1, Cart::problems( 0, '', 1 ) );
		$this->assertSame( array(), Cart::problems( 0, 'fakecard', 1 ) );
	}

	public function test_classic_validation_adds_the_error(): void {
		$v = $this->usage_product();
		wp_set_current_user( $this->new_user() );
		$this->add( $v['u-None'] );

		$errors = new \WP_Error();
		Cart::validate( array( 'payment_method' => 'bacs' ), $errors );
		$this->assertSame( array( 'beltoft_services_usage' ), $errors->get_error_codes() );

		$errors = new \WP_Error();
		Cart::validate( array( 'payment_method' => 'fakecard' ), $errors );
		$this->assertSame( array(), $errors->get_error_codes() );
	}

	public function test_an_account_on_hold_or_without_a_card_takes_no_new_service(): void {
		$user = $this->new_user();
		$this->usage_account( $user, 'on-hold' );
		$this->assertStringContainsString( 'unpaid invoice', Cart::problems( $user, 'fakecard', 1 )[0] );

		$other = $this->new_user();
		$ok    = $this->usage_account( $other );
		$this->assertSame( array(), Cart::problems( $other, 'fakecard', 1 ) );

		$ok->set_payment_method( '' );
		$ok->save();
		$this->assertStringContainsString( 'card saved', Cart::problems( $other, 'fakecard', 1 )[0] );
	}

	public function test_a_new_account_is_limited_until_its_first_paid_usage_invoice(): void {
		$v    = $this->usage_product();
		$user = $this->new_user();
		$sub  = $this->usage_account( $user );
		$this->usage_vm( $v['u-None'], $user, 2, $sub->get_id() );
		$this->usage_vm( $v['u-None'], $user, 2, $sub->get_id() );

		$this->assertSame( 2, count( Account::resources( $user ) ) );
		$problems = Cart::problems( $user, 'fakecard', 1 );
		$this->assertCount( 1, $problems );
		$this->assertStringContainsString( '2 pay as you go services', $problems[0] );

		$this->settings( array( 'usage_new_limit' => 3 ) );
		$this->assertSame( array(), Cart::problems( $user, 'fakecard', 1 ), 'The limit is a setting.' );
		$this->assertCount( 1, Cart::problems( $user, 'fakecard', 2 ) );

		$this->settings( array( 'usage_new_limit' => 2 ) );
		$sub->update_meta_data( Account::META_CHARGED, 'yes' );
		$sub->save();
		$this->assertSame( array(), Cart::problems( $user, 'fakecard', 5 ), 'After the first paid usage invoice the limit is gone.' );
	}

	public function test_a_paid_order_makes_a_usage_license_with_no_expiry_on_the_account(): void {
		$v    = $this->usage_product();
		$user = $this->new_user();
		$sub  = $this->usage_account( $user, 'pending' );

		$order = wc_create_order( array( 'customer_id' => $user ) );
		$order->set_billing_email( self::MAIL );
		$order->add_product( $v['u-None'], 1 );
		$order->calculate_totals();
		$order->save();
		$order->update_status( 'processing' );

		$licenses = Licenses::where( 'order_id', $order->get_id() );
		$this->assertCount( 1, $licenses );
		$this->assertSame( 'usage', $licenses[0]['source'] );
		$this->assertNull( $licenses[0]['expires_at'] );
		$this->assertSame( $sub->get_id(), (int) $licenses[0]['subscription_id'] );
		$this->assertSame( (string) $v['u-None']->get_id(), (string) $licenses[0]['variation_id'] );
		$this->assertInstanceOf( Resource::class, Resource::for_license( (int) $licenses[0]['id'] ), 'The server is made like any other.' );
	}

	public function test_a_later_order_attaches_to_the_established_account(): void {
		$v    = $this->usage_product();
		$user = $this->new_user();
		$sub  = $this->usage_account( $user );

		$license = Licenses::create(
			array(
				'product_id'   => $v['u-None']->get_parent_id(),
				'product_slug' => (string) wc_get_product( $v['u-None']->get_parent_id() )->get_meta( \BeltoftLicenses\Software::META_SLUG ),
				'variation_id' => $v['u-None']->get_id(),
				'user_id'      => $user,
				'email'        => self::MAIL,
				'source'       => 'order',
			)
		);

		$this->assertSame( 'usage', Licenses::find( (int) $license['id'] )['source'] );
		$this->assertSame( $sub->get_id(), (int) Licenses::find( (int) $license['id'] )['subscription_id'] );
		$this->assertSame( $sub->get_id(), Account::for_user( $user )->get_id() );
	}

	public function test_a_monthly_license_is_not_touched(): void {
		$v    = $this->usage_product();
		$user = $this->new_user();
		$this->usage_account( $user );

		$license = Licenses::create(
			array(
				'product_id'   => $v['m-None']->get_parent_id(),
				'product_slug' => (string) wc_get_product( $v['m-None']->get_parent_id() )->get_meta( \BeltoftLicenses\Software::META_SLUG ),
				'variation_id' => $v['m-None']->get_id(),
				'user_id'      => $user,
				'email'        => self::MAIL,
				'source'       => 'order',
			)
		);

		$this->assertSame( 'order', Licenses::find( (int) $license['id'] )['source'] );
		$this->assertNull( Licenses::find( (int) $license['id'] )['subscription_id'] );
	}
}

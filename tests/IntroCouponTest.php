<?php
/**
 * The intro coupon of a service product.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests;

use BeltoftServices\IntroCoupon;
use BeltoftServices\Product;

/**
 * IntroCoupon: first purchase only.
 */
class IntroCouponTest extends TestCase {

	public function set_up(): void {
		parent::set_up();
		WC()->cart->empty_cart();
		wc_clear_notices();
		as_unschedule_all_actions( \BeltoftServices\Jobs::SWEEP, array(), \BeltoftServices\Jobs::GROUP );
	}

	public function tear_down(): void {
		WC()->cart->empty_cart();
		wc_clear_notices();
		wp_set_current_user( 0 );

		parent::tear_down();
	}

	/**
	 * A coupon: 50 percent.
	 *
	 * @param string $code Code.
	 */
	private function coupon( string $code ): void {
		$c = new \WC_Coupon();
		$c->set_code( $code );
		$c->set_discount_type( 'percent' );
		$c->set_amount( 50 );
		$c->save();
	}

	/**
	 * A service product with an intro coupon.
	 *
	 * @param string $code Coupon code.
	 */
	private function product( string $code = 'intro-half' ): \WC_Product {
		$product = $this->service_product( 'fake', array(), array( 'slug' => 'intro-' . wp_generate_password( 5, false ) ) );
		$product->update_meta_data( IntroCoupon::META, $code );
		$product->save();

		return wc_get_product( $product->get_id() );
	}

	private function add( \WC_Product $product ): string {
		$key = WC()->cart->add_to_cart( $product->get_id() );

		if ( ! $key ) {
			$this->markTestSkipped( 'The fixture product cannot be put in the cart here.' );
		}

		return (string) $key;
	}

	public function test_a_first_purchase_gets_the_coupon_automatically(): void {
		$this->coupon( 'intro-half' );
		wp_set_current_user( self::factory()->user->create() );

		$this->add( $this->product() );

		$this->assertTrue( WC()->cart->has_discount( 'intro-half' ) );
		$this->assertSame( array(), wc_get_notices( 'error' ) );
		$this->assertSame( array(), wc_get_notices( 'notice' ), 'A new customer is told nothing.' );
	}

	public function test_a_product_without_the_setting_or_a_missing_coupon_changes_nothing(): void {
		$this->coupon( 'intro-half' );
		$plain = $this->service_product( 'fake', array(), array( 'slug' => 'plain-' . wp_generate_password( 5, false ) ) );
		$this->add( $plain );
		$this->assertFalse( WC()->cart->has_discount( 'intro-half' ) );

		WC()->cart->empty_cart();
		$this->add( $this->product( 'no-such-coupon' ) );
		$this->assertSame( array(), WC()->cart->get_applied_coupons() );
		$this->assertSame( array(), wc_get_notices( 'error' ), 'A missing coupon is not an error for the customer.' );
	}

	public function test_a_customer_who_held_the_product_gets_no_coupon_by_user_or_by_email(): void {
		$this->coupon( 'intro-half' );
		$product = $this->product();
		$user    = self::factory()->user->create();
		$this->service_license( $product, array( 'fire' => false, 'user_id' => $user, 'email' => 'old@example.com' ) );

		wp_set_current_user( $user );
		$this->add( $product );
		$this->assertFalse( WC()->cart->has_discount( 'intro-half' ), 'Held by the user.' );
		$notices = wc_get_notices( 'notice' );
		$this->assertCount( 1, $notices, 'The customer is told why the regular price applies.' );
		$this->assertStringContainsString( 'is for your first order, so the regular price applies', (string) ( $notices[0]['notice'] ?? '' ) );
		$this->assertSame( array(), wc_get_notices( 'error' ) );
		$this->add( $product );
		$this->assertCount( 1, wc_get_notices( 'notice' ), 'Told once, not on every add.' );

		WC()->cart->empty_cart();
		wp_set_current_user( 0 );
		WC()->customer->set_billing_email( 'old@example.com' );
		$this->add( $product );
		$this->assertFalse( WC()->cart->has_discount( 'intro-half' ), 'Held by the billing email, so a guest counts.' );
		WC()->customer->set_billing_email( '' );
	}

	public function test_emails_are_compared_without_case_plus_tags_and_gmail_dots(): void {
		$this->assertSame( 'a.b@example.com', IntroCoupon::normalise_email( ' A.B+shop@Example.com ' ) );
		$this->assertSame( 'ab@gmail.com', IntroCoupon::normalise_email( 'A.b+x@Gmail.com' ) );
		$this->assertSame( 'ab@gmail.com', IntroCoupon::normalise_email( 'a.b@googlemail.com' ) );
		$this->assertSame( 'not-an-email', IntroCoupon::normalise_email( 'Not-An-Email' ) );

		$product = $this->product();
		$this->service_license( $product, array( 'fire' => false, 'email' => 'First.Last@gmail.com' ) );

		foreach ( array( 'firstlast@gmail.com', 'first.last+intro2@gmail.com', 'f.i.r.s.t.l.a.s.t@googlemail.com', 'FIRST.LAST@GMAIL.COM' ) as $variant ) {
			$this->assertTrue( IntroCoupon::held( $product->get_id(), 0, $variant ), $variant );
		}

		$this->assertFalse( IntroCoupon::held( $product->get_id(), 0, 'other.person@gmail.com' ) );
		$this->assertFalse( IntroCoupon::held( $product->get_id(), 0, 'first.last+tag@example.com' ), 'Dots only count at Gmail.' );
	}

	public function test_any_plan_of_the_product_counts_as_held(): void {
		$product   = $this->product();
		$variation = $this->service_license( $product, array( 'fire' => false, 'email' => 'plan@example.com' ) );
		unset( $variation );

		// Another plan of the same product: the same product slug, so the same history.
		$this->assertTrue( IntroCoupon::held( $product->get_id(), 0, 'Plan+other@example.com' ) );
	}

	public function test_the_same_offer_on_two_lines_is_refused_with_the_coupon_taken_off(): void {
		$this->coupon( 'intro-half' );
		$product = $this->product();
		$this->add( $product );
		WC()->cart->add_to_cart( $product->get_id() );
		$this->assertCount( 1, IntroCoupon::lines() );

		$ids = array_values( IntroCoupon::lines() )[0];
		$this->assertCount( 2, $ids, 'The cart merged the two lines into a quantity of two: two accounts.' );

		$errors = new \WP_Error();
		IntroCoupon::validate( array( 'billing_email' => 'twice@example.com' ), $errors );
		$this->assertCount( 1, $errors->get_error_messages() );
		$this->assertFalse( WC()->cart->has_discount( 'intro-half' ) );
	}

	public function test_another_product_held_does_not_count(): void {
		$this->coupon( 'intro-half' );
		$user = self::factory()->user->create();
		$this->service_license( $this->product(), array( 'fire' => false, 'user_id' => $user ) );

		wp_set_current_user( $user );
		$this->add( $this->product() );

		$this->assertTrue( WC()->cart->has_discount( 'intro-half' ) );
	}

	public function test_a_renewal_switch_or_resubscribe_line_never_gets_it(): void {
		$this->coupon( 'intro-half' );
		wp_set_current_user( self::factory()->user->create() );
		$product = $this->product();

		foreach ( array( 'subscription_renewal', 'subscription_switch', 'subscription_resubscribe', 'subscription_renewal_early' ) as $flag ) {
			WC()->cart->empty_cart();
			$key = $this->add( $product );
			WC()->cart->remove_coupons();
			WC()->cart->cart_contents[ $key ][ $flag ] = array( 'subscription_id' => 1 );

			IntroCoupon::on_add( $key, $product->get_id() );

			$this->assertFalse( WC()->cart->has_discount( 'intro-half' ), $flag );
			$this->assertSame( array(), IntroCoupon::lines(), $flag );
		}
	}

	public function test_checkout_refuses_and_removes_the_coupon_for_a_customer_who_had_the_product(): void {
		$this->coupon( 'intro-half' );
		$product = $this->product();
		$this->add( $product );
		$this->assertTrue( WC()->cart->has_discount( 'intro-half' ), 'A guest is not known yet, so the coupon went on.' );

		$errors = new \WP_Error();
		IntroCoupon::validate( array( 'billing_email' => 'new@example.com' ), $errors );
		$this->assertSame( array(), $errors->get_error_messages(), 'A new customer keeps it.' );
		$this->assertTrue( WC()->cart->has_discount( 'intro-half' ) );

		$this->service_license( $product, array( 'fire' => false, 'email' => 'returning@example.com' ) );
		IntroCoupon::validate( array( 'billing_email' => 'Returning@Example.com' ), $errors );

		$this->assertCount( 1, $errors->get_error_messages() );
		$this->assertStringContainsString( 'for new customers', $errors->get_error_messages()[0] );
		$this->assertFalse( WC()->cart->has_discount( 'intro-half' ), 'The coupon is off the cart, so the next try is at full price.' );

		$again = new \WP_Error();
		IntroCoupon::validate( array( 'billing_email' => 'returning@example.com' ), $again );
		$this->assertSame( array(), $again->get_error_messages() );
	}

	public function test_the_store_api_path_checks_the_same_rule(): void {
		$this->coupon( 'intro-half' );
		$product = $this->product();
		$this->add( $product );
		WC()->customer->set_billing_email( 'blocks@example.com' );
		$this->service_license( $product, array( 'fire' => false, 'email' => 'blocks@example.com' ) );

		$errors = new \WP_Error();
		IntroCoupon::store_api_errors( $errors, WC()->cart );
		WC()->customer->set_billing_email( '' );

		$this->assertCount( 1, $errors->get_error_messages() );
		$this->assertFalse( WC()->cart->has_discount( 'intro-half' ) );
		IntroCoupon::validate( array(), 'not an error object' );
	}

	public function test_the_code_is_read_through_the_parent_and_cleaned_on_save(): void {
		$this->assertSame( 'intro-half', IntroCoupon::clean_code( ' Intro-Half! ' ) );
		$this->assertSame( '', IntroCoupon::clean_code( '!!!' ) );
		$this->assertSame( 'intro_x-1', IntroCoupon::clean_code( 'INTRO_x-1' ) );

		$product = $this->service_product(
			'fake',
			array(),
			array(
				'slug'       => 'var-' . wp_generate_password( 5, false ),
				'variations' => array( array(), array() ),
			)
		);
		$product->update_meta_data( IntroCoupon::META, 'Yearly-Intro' );
		$product->save();

		$this->assertSame( 'yearly-intro', IntroCoupon::code( $product->get_id() ) );
		$this->assertSame( 'yearly-intro', IntroCoupon::code( (int) $product->get_children()[0] ), 'A variation uses its parent.' );
		$this->assertSame( '', IntroCoupon::code( 0 ) );
		$this->assertSame( '_beltoft_services_intro_coupon', IntroCoupon::META, 'The key the theme reads.' );
		$this->assertTrue( Product::is_service( $product->get_id() ) );
	}

	public function test_the_service_tab_saves_and_prints_the_setting(): void {
		$product = $this->product( 'old-code' );

		$_POST = array(
			Product::META_DRIVER => 'fake',
			Product::FIELD_MARKER => 'fake',
			IntroCoupon::META    => ' Hosting-Intro ',
		);
		Product::save( $product );
		$product->save();
		$this->assertSame( 'hosting-intro', get_post_meta( $product->get_id(), IntroCoupon::META, true ) );

		$_POST = array(
			Product::META_DRIVER  => 'fake',
			Product::FIELD_MARKER => 'fake',
		);
		$again = wc_get_product( $product->get_id() );
		Product::save( $again );
		$again->save();
		$this->assertSame( '', get_post_meta( $product->get_id(), IntroCoupon::META, true ), 'An empty box clears it.' );
		$_POST = array();

		include_once WC_ABSPATH . 'includes/admin/wc-meta-box-functions.php';
		$GLOBALS['product_object'] = wc_get_product( $product->get_id() );
		ob_start();
		Product::panel();
		$html = (string) ob_get_clean();
		unset( $GLOBALS['product_object'] );
		$this->assertStringContainsString( 'name="_beltoft_services_intro_coupon"', $html );
		$this->assertStringContainsString( 'Intro coupon', $html );
	}
}

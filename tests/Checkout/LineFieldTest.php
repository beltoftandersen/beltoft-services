<?php
/**
 * The cart lines, Store API refusal and post reading shared by the line fields.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Checkout;

use BeltoftServices\Checkout\LineField;
use BeltoftServices\Tests\TestCase;

/**
 * LineField.
 */
class LineFieldTest extends TestCase {

	public function tear_down(): void {
		unset( $_POST['beltoft_test_lines'] );

		if ( function_exists( 'WC' ) && WC()->cart ) {
			WC()->cart->empty_cart();
		}

		parent::tear_down();
	}

	private function simple_product(): \WC_Product_Simple {
		$product = new \WC_Product_Simple();
		$product->set_name( 'Line field test' );
		$product->set_regular_price( '10' );
		$product->set_status( 'publish' );
		$product->save();

		return $product;
	}

	public function test_cart_lines_skip_renewals_and_switches_and_keep_the_products_lines(): void {
		$mine   = $this->simple_product();
		$other  = $this->simple_product();
		$is_one = static fn( int $id ): bool => $id === $mine->get_id();

		WC()->cart->empty_cart();
		$first  = WC()->cart->add_to_cart( $mine->get_id() );
		$second = WC()->cart->add_to_cart( $other->get_id() );

		if ( ! $first || ! $second ) {
			$this->markTestSkipped( 'The fixture products cannot be put in the cart here.' );
		}

		$this->assertSame( array( (string) $first ), array_keys( LineField::cart_lines( $is_one ) ) );

		WC()->cart->cart_contents[ $first ]['subscription_renewal'] = array( 'subscription_id' => 1 );
		$this->assertSame( array(), LineField::cart_lines( $is_one ) );

		unset( WC()->cart->cart_contents[ $first ]['subscription_renewal'] );
		WC()->cart->cart_contents[ $first ]['subscription_switch'] = array( 'subscription_id' => 1 );
		$this->assertSame( array(), LineField::cart_lines( $is_one ) );

		$this->expectException( \Automattic\WooCommerce\StoreApi\Exceptions\RouteException::class );
		LineField::refuse_store_api( static fn( int $id ): bool => $id === $other->get_id(), 'beltoft_test_code', 'Use the website checkout.' );
	}

	public function test_read_post_trims_strings_and_ignores_everything_else(): void {
		$_POST['beltoft_test_lines'] = wp_slash(
			array(
				'k1' => array(
					'a' => '  one ',
					'b' => array( 'x' ),
					'd' => "line\nbreak ",
				),
			)
		);

		$this->assertSame(
			array( 'a' => 'one', 'b' => '', 'c' => '', 'd' => "line\nbreak" ),
			LineField::read_post( 'beltoft_test_lines', 'k1', array( 'a', 'b', 'c', 'd' ) )
		);
		$this->assertSame( array( 'a' => '' ), LineField::read_post( 'beltoft_test_lines', 'missing', array( 'a' ) ) );
		$this->assertSame( array( 'a' => '' ), LineField::read_post( 'beltoft_test_absent', 'k1', array( 'a' ) ) );
	}
}

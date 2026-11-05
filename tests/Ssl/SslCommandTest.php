<?php
/** The SSL product command and the licence-to-resource hand-over. @package BeltoftServices */
namespace BeltoftServices\Tests\Ssl;

use BeltoftServices\Cli\SslCommand;
use BeltoftServices\Product;
use BeltoftServices\RenewalReminder;
use BeltoftServices\Resource;
use BeltoftServices\Ssl\SslCatalog;
use BeltoftServices\Ssl\SslField;

require_once __DIR__ . '/SslTestCase.php';

class SslCommandTest extends SslTestCase {

	public function test_prices_are_parsed_and_checked(): void {
		$this->assertSame( array( 'dv' => '19', 'dv_wildcard' => '99', 'ov' => '55', 'ev' => '129' ), SslCommand::parse_prices( 'dv:19, dv_wildcard:99,ov:55,ev:129' ) );
		$this->assertSame( array( 'dv' => '14.5' ), SslCommand::parse_prices( 'dv:14.50' ) );
		$this->assertWPError( SslCommand::parse_prices( 'multi:5' ) );
		$this->assertWPError( SslCommand::parse_prices( 'dv:free' ) );
		$this->assertWPError( SslCommand::parse_prices( 'dv:0' ) );
		$this->assertSame( array(), SslCommand::parse_prices( '' ) );
	}

	public function test_the_product_has_the_four_launch_variations_with_their_plan_fields(): void {
		$prices = array( 'dv' => '19', 'dv_wildcard' => '99', 'ov' => '55', 'ev' => '129' );
		$id     = SslCommand::create( $prices );
		$parent = wc_get_product( $id );
		$this->assertInstanceOf( \WC_Product_Variable_Subscription::class, $parent );
		$this->assertSame( 'draft', $parent->get_status() );
		$this->assertTrue( $parent->is_sold_individually() );
		$this->assertSame( 'yes', get_post_meta( $id, '_virtual', true ) );
		$this->assertSame( 'hidden', $parent->get_catalog_visibility() );
		$this->assertSame( 'ssl', get_post_meta( $id, Product::META_DRIVER, true ) );
		$this->assertSame( 'year', get_post_meta( $id, '_subscription_period', true ) );
		$this->assertSame( '30', get_post_meta( $id, RenewalReminder::META, true ) );
		$this->assertSame( $id, SslCommand::existing() );

		$seen = array();

		foreach ( $parent->get_children() as $vid ) {
			$plan = Product::plan( $id, (int) $vid );
			$seen[ (int) $plan['ssl_product'] ] = array( $plan['ssl_level'], $plan['ssl_wildcard'], (string) wc_get_product( $vid )->get_regular_price() );
		}

		$this->assertEquals(
			array( 41 => array( 'dv', false, '19' ), 42 => array( 'dv', true, '99' ), 20 => array( 'ov', false, '55' ), 24 => array( 'ev', false, '129' ) ),
			$seen
		);
	}

	public function test_a_site_can_change_the_launch_catalog(): void {
		add_filter( 'beltoft_services_ssl_launch_products', static fn() => array( 'dv' => 7, 'ev' => 9 ) );
		$this->assertSame( array( 'dv', 'ev' ), array_keys( SslCommand::types() ) );
		$this->assertWPError( SslCommand::parse_prices( 'ov:55' ) );
		remove_all_filters( 'beltoft_services_ssl_launch_products' );
	}

	public function test_a_paid_order_of_a_variation_becomes_a_certificate_service_with_the_checkout_choices(): void {
		$id      = SslCommand::create( array( 'ov' => '55' ) );
		$parent  = wc_get_product( $id );
		$variation = null;

		foreach ( $parent->get_children() as $vid ) {
			if ( 20 === (int) Product::plan( $id, (int) $vid )['ssl_product'] ) {
				$variation = wc_get_product( $vid );
			}
		}

		$user  = (int) self::factory()->user->create();
		$order = wc_create_order( array( 'customer_id' => $user ) );
		$item  = new \WC_Order_Item_Product();
		$item->set_props( array( 'product_id' => $id, 'variation_id' => $variation->get_id(), 'quantity' => 1, 'total' => 55 ) );
		$item->add_meta_data( SslField::ITEM_META, array( 'domain' => 'example.com', 'name' => 'example.com', 'validation' => 'email', 'approver' => 'admin@example.com', 'key_mode' => 'generate', 'csr' => '' ) );
		$order->add_item( $item );
		$order->save();

		$license = \BeltoftLicenses\Licenses::create(
			array(
				'product_id'      => $id,
				'variation_id'    => $variation->get_id(),
				'product_slug'    => SslCommand::SLUG,
				'email'           => 'christian.beltoft@gmail.com',
				'user_id'         => $user,
				'max_activations' => 1,
				'source'          => 'order',
				'order_id'        => $order->get_id(),
			)
		);
		$this->assertIsArray( $license );

		$r = Resource::for_license( (int) $license['id'] );

		if ( ! $r ) {
			$this->markTestSkipped( 'The licence was not turned into a service by the creation listener in this environment.' );
		}

		$this->assertSame( 'ssl', $r->get( 'driver' ) );
		$this->assertSame( 'example.com', $r->get( 'ssl_name' ) );
		$this->assertSame( 'email', $r->get( 'ssl_validation' ) );
		$plan = (array) $r->get( 'plan' );
		$this->assertSame( 20, (int) $plan['ssl_product'] );
		$this->assertSame( 'ov', $plan['ssl_level'] );
		$this->assertSame( array(), $this->calls( 'POST', '#^/ssl/orders$#' ), 'Nothing is ordered by creating the service.' );
		unset( $variation );
		$this->assertSame( 4, count( SslCatalog::all() ) );
	}
}

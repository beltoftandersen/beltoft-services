<?php
/**
 * The WooCommerce Subscriptions switch link: one URL builder for the server, backups, email and disk links.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Billing;

use BeltoftServices\Drivers\Registry;
use BeltoftServices\Product;
use BeltoftServices\Proxmox\Pve\DiskCapacity;
use BeltoftServices\Proxmox\Disk\DiskCart;
use BeltoftServices\Resource;
use BeltoftServices\SwitchLink;
use BeltoftServices\Tests\Proxmox\BackupTestCase;

require_once dirname( __DIR__ ) . '/Proxmox/BackupTestCase.php';

class SwitchLinkTest extends BackupTestCase {

	/**
	 * @var array<int, \WC_Product_Variation>
	 */
	private array $variations = array();

	public function set_up(): void {
		parent::set_up();
		DiskCapacity::refresh( 'lab', 'node-a', 'disk-store', $this->fake );
		add_filter( 'woocommerce_subscriptions_can_item_be_switched_by_user', '__return_true' );
	}

	public function tear_down(): void {
		remove_all_filters( 'woocommerce_subscriptions_can_item_be_switched_by_user' );
		remove_all_filters( 'woocommerce_subscriptions_switch_url' );
		parent::tear_down();
	}

	/**
	 * A disk product with a 50 GB and a 100 GB variation.
	 */
	private function disk_product(): \WC_Product_Variable {
		$product = new \WC_Product_Variable_Subscription();
		$product->set_name( 'Extra disk ' . wp_generate_password( 5, false ) );
		$product->set_status( 'publish' );
		$attribute = new \WC_Product_Attribute();
		$attribute->set_name( 'Size' );
		$attribute->set_options( array( '50 GB', '100 GB' ) );
		$attribute->set_variation( true );
		$product->set_attributes( array( $attribute ) );
		$product->update_meta_data( Product::META_DRIVER, 'proxmox-disk' );
		$product->update_meta_data( \BeltoftLicenses\Software::META_LICENSED, 'yes' );
		$product->update_meta_data( \BeltoftLicenses\Software::META_SLUG, 'disk-' . wp_generate_password( 5, false ) );
		$product->save();

		foreach ( array( 50, 100 ) as $gb ) {
			$variation = new \WC_Product_Subscription_Variation();
			$variation->set_parent_id( $product->get_id() );
			$variation->set_attributes( array( 'size' => $gb . ' GB' ) );
			$variation->set_regular_price( (string) ( $gb / 10 ) );
			$variation->update_meta_data( '_subscription_price', (string) ( $gb / 10 ) );
			$variation->update_meta_data( '_subscription_period', 'month' );
			$variation->update_meta_data( '_subscription_period_interval', '1' );
			$variation->update_meta_data( '_subscription_length', '0' );
			$variation->set_status( 'publish' );
			$variation->update_meta_data( Product::META_PLAN . 'disk_gb', (string) $gb );
			$variation->save();
			$this->variations[ $gb ] = $variation;
		}

		\WC_Product_Variable::sync( $product->get_id() );

		return $product;
	}

	/**
	 * A 50 GB disk on a subscription whose item is the 50 GB variation.
	 *
	 * @return array{0: Resource, 1: \WC_Subscription, 2: int, 3: \WC_Order_Item_Product}
	 */
	private function disk_on_subscription(): array {
		$product = $this->disk_product();
		$owner   = (int) self::factory()->user->create( array( 'user_email' => 'christian.beltoft@gmail.com' ) );
		$sub     = wcs_create_subscription( array( 'customer_id' => $owner, 'billing_period' => 'month', 'billing_interval' => 1 ) );
		$this->assertInstanceOf( \WC_Subscription::class, $sub );
		$sub->add_product( wc_get_product( $this->variations[50]->get_id() ), 1 );
		$sub->save();

		$license = $this->service_license(
			$product,
			array(
				'product_slug'    => (string) $product->get_meta( \BeltoftLicenses\Software::META_SLUG ),
				'variation_id'    => $this->variations[50]->get_id(),
				'user_id'         => $owner,
				'subscription_id' => $sub->get_id(),
				'fire'            => false,
				'email'           => 'christian.beltoft@gmail.com',
			)
		);
		$disk    = Resource::create( $license, Registry::get( 'proxmox-disk' ), Product::plan( $product->get_id(), $this->variations[50]->get_id() ) );
		$this->assertInstanceOf( Resource::class, $disk );
		$this->assertTrue( $disk->set( array( 'size_gb' => 50 ) ) );

		foreach ( wcs_get_subscription( $sub->get_id() )->get_items() as $item_id => $item ) {
			return array( Resource::find( $disk->id() ), wcs_get_subscription( $sub->get_id() ), (int) $item_id, $item );
		}

		$this->fail( 'The subscription has no item.' );
	}

	public function test_grow_url_and_to_build_the_same_url_when_the_item_matches_both_ways(): void {
		list( $disk, $sub, $item_id, $item ) = $this->disk_on_subscription();
		$next = wc_get_product( $this->variations[100]->get_id() );

		$url = DiskCart::grow_url( $disk );
		$this->assertNotSame( '', $url );
		$this->assertSame( $url, SwitchLink::to( $disk, $next ) );
		$this->assertSame( $url, SwitchLink::for_item( $item_id, $item, $sub, $next ) );

		$args = array();
		wp_parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $args );
		$this->assertSame( (string) $sub->get_id(), (string) $args['switch-subscription'] );
		$this->assertSame( (string) $item_id, (string) $args['item'] );
		$this->assertSame( (string) $next->get_id(), (string) $args['variation_id'] );
		$this->assertSame( (string) $next->get_parent_id(), (string) $args['add-to-cart'] );
		$this->assertSame( '100 GB', $args['attribute_size'] );
	}

	public function test_for_item_returns_empty_without_the_three_switch_arguments(): void {
		list( , $sub, $item_id, $item ) = $this->disk_on_subscription();
		$next = wc_get_product( $this->variations[100]->get_id() );

		add_filter( 'woocommerce_subscriptions_switch_url', static fn( $url ) => remove_query_arg( '_wcsnonce', $url ) );

		$this->assertSame( '', SwitchLink::for_item( $item_id, $item, $sub, $next ) );
	}
}

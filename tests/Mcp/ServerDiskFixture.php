<?php
/**
 * The extra disk product of the tests: 50 and 100 GB, each monthly and pay as you go, and a reading of the node's storage.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Mcp;

use BeltoftServices\Product;
use BeltoftServices\Proxmox\Placement;
use BeltoftServices\Usage\Rates;

/**
 * For a UsageTestCase.
 */
trait ServerDiskFixture {

	/**
	 * A fresh reading of the node (memory and storage), with this many GB of storage free.
	 *
	 * @param int $free_gb Free storage.
	 */
	protected function capacity( int $free_gb ): void {
		set_transient( Placement::CAPACITY_PREFIX . 'lab', array( array( 'node' => 'node-a', 'status' => 'online', 'maxmem' => 64 * 1073741824, 'mem' => 0 ) ), HOUR_IN_SECONDS );
		$key = new \ReflectionMethod( \BeltoftServices\Proxmox\DiskCapacity::class, 'cache_key' );
		$key->setAccessible( true );
		set_transient( $key->invoke( null, 'lab', 'node-a', 'disk-store' ), array( 'total' => 5000 * 1073741824, 'avail' => $free_gb * 1073741824, 'read_at' => time() ), HOUR_IN_SECONDS );
	}

	/**
	 * The extra disk product: 50 and 100 GB, each monthly (4 and 8) and pay as you go (the usage twin, price 0).
	 *
	 * @return array<string, \WC_Product_Variation>
	 */
	protected function disk_product(): array {
		$product = new \WC_Product_Variable_Subscription();
		$product->set_name( 'Extra disk ' . wp_generate_password( 5, false ) );
		$product->set_status( 'publish' );

		foreach ( array( 'Size' => array( '50 GB', '100 GB' ), 'Billing' => array( 'Monthly', 'Pay as you go' ) ) as $name => $options ) {
			$a = new \WC_Product_Attribute();
			$a->set_name( $name );
			$a->set_options( $options );
			$a->set_visible( true );
			$a->set_variation( true );
			$attrs[] = $a;
		}

		$product->set_attributes( $attrs );
		$product->update_meta_data( Product::META_DRIVER, 'proxmox-disk' );
		$product->update_meta_data( Rates::META_PREMIUM, '10' );
		$product->update_meta_data( \BeltoftLicenses\Software::META_LICENSED, 'yes' );
		$product->update_meta_data( \BeltoftLicenses\Software::META_SLUG, 'disk-' . wp_generate_password( 5, false ) );
		$product->save();
		Product::remember_slug( (string) $product->get_meta( \BeltoftLicenses\Software::META_SLUG ) );
		$out = array();

		foreach ( array( 'm' => 'Monthly', 'u' => 'Pay as you go' ) as $short => $billing ) {
			foreach ( array( 50 => 4, 100 => 8 ) as $gb => $price ) {
				$v = new \WC_Product_Subscription_Variation();
				$v->set_parent_id( $product->get_id() );
				$v->set_attributes( array( 'size' => $gb . ' GB', 'billing' => $billing ) );
				$shown = 'm' === $short ? (string) $price : '0';
				$v->set_regular_price( $shown );
				$v->update_meta_data( '_subscription_price', $shown );
				$v->update_meta_data( '_subscription_period', 'month' );
				$v->update_meta_data( '_subscription_period_interval', '1' );
				$v->update_meta_data( '_subscription_length', '0' );
				$v->set_status( 'publish' );
				$v->set_stock_status( 'instock' );
				$v->update_meta_data( Product::META_PLAN . 'disk_gb', (string) $gb );

				if ( 'u' === $short ) {
					$v->update_meta_data( Rates::META_BILLING, 'usage' );
				}

				$v->save();
				$out[ $short . '-' . $gb ] = wc_get_product( $v->get_id() );
			}
		}

		\WC_Product_Variable::sync( $product->get_id() );

		return $out;
	}
}

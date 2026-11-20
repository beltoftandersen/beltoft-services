<?php
/**
 * The IPv4 address product as the site script makes it: Billing = Monthly | Pay as you go, premium 0.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Support;

use BeltoftServices\Product;
use BeltoftServices\Proxmox\Ipv4\Ipv4Driver;
use BeltoftServices\Usage\Rates;

trait Ipv4Product {

	/** The product the script makes: Billing = Monthly | Pay as you go, premium 0 (the cap is the monthly price). */
	protected function ipv4_product(): array {
		$product = new \WC_Product_Variable_Subscription();
		$product->set_name( 'IPv4 address ' . wp_generate_password( 5, false ) );
		$product->set_status( 'publish' );
		$attribute = new \WC_Product_Attribute();
		$attribute->set_name( 'Billing' );
		$attribute->set_options( array( 'Monthly', 'Pay as you go' ) );
		$attribute->set_variation( true );
		$product->set_attributes( array( $attribute ) );
		$product->update_meta_data( Product::META_DRIVER, Ipv4Driver::ID );
		$product->update_meta_data( Rates::META_PREMIUM, '0' );
		$product->update_meta_data( \BeltoftLicenses\Software::META_LICENSED, 'yes' );
		$product->update_meta_data( \BeltoftLicenses\Software::META_SLUG, 'ipv4-' . wp_generate_password( 5, false ) );
		$product->save();
		Product::remember_slug( (string) $product->get_meta( \BeltoftLicenses\Software::META_SLUG ) );
		$out = array();

		foreach ( array( 'm' => 'Monthly', 'u' => 'Pay as you go' ) as $short => $billing ) {
			$v = new \WC_Product_Subscription_Variation();
			$v->set_parent_id( $product->get_id() );
			$v->set_attributes( array( 'billing' => $billing ) );
			$v->set_regular_price( 'm' === $short ? '2.04' : '0' );
			$v->update_meta_data( '_subscription_price', 'm' === $short ? '2.04' : '0' );
			$v->update_meta_data( '_subscription_period', 'month' );
			$v->update_meta_data( '_subscription_period_interval', '1' );
			$v->update_meta_data( '_subscription_length', '0' );
			$v->set_status( 'publish' );
			$v->set_stock_status( 'instock' );

			if ( 'u' === $short ) {
				$v->update_meta_data( Rates::META_BILLING, 'usage' );
				$v->update_meta_data( Rates::META_MONTHLY, '2.04' );
			}

			$v->save();
			$out[ $short ] = $v;
		}

		\WC_Product_Variable::sync( $product->get_id() );
		\BeltoftServices\Proxmox\Vm\Stock::sync();

		return array_map( static fn( $v ) => wc_get_product( $v->get_id() ), $out );
	}
}

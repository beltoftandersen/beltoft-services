<?php
/**
 * A managed database product with a plan, a public IP twin and extra storage, each as Monthly and Pay as you go.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Mcp;

use BeltoftServices\Product;
use BeltoftServices\Usage\Rates;

/**
 * For a UsageTestCase.
 */
trait DatabaseFixture {

	/**
	 * A database "Standard": 45 a month, +5 with the public IP, +4 for 50 GB extra, Monthly and Pay as you go.
	 *
	 * @return array<string, \WC_Product_Variation> Keys such as m-0-0, u-50-1.
	 */
	protected function database_product(): array {
		$product = new \WC_Product_Variable();
		$product->set_name( 'Managed PostgreSQL' );
		$product->set_status( 'publish' );
		$attrs = array();

		foreach ( array( 'Plan' => array( 'Standard' ), 'Billing' => array( 'Monthly', 'Pay as you go' ), 'Extra storage' => array( 'None', '50 GB' ), 'Public IP' => array( 'No', 'Yes' ) ) as $name => $options ) {
			$a = new \WC_Product_Attribute();
			$a->set_name( $name );
			$a->set_options( $options );
			$a->set_visible( true );
			$a->set_variation( true );
			$attrs[] = $a;
		}

		$product->set_attributes( $attrs );
		$product->update_meta_data( Product::META_DRIVER, 'proxmox-db' );
		$product->update_meta_data( Rates::META_PREMIUM, '0' );
		$product->update_meta_data( \BeltoftLicenses\Software::META_LICENSED, 'yes' );
		$product->update_meta_data( \BeltoftLicenses\Software::META_SLUG, 'db-' . wp_generate_password( 5, false ) );

		foreach ( array( 'cluster' => 'lab', 'template' => '9100', 'engine' => 'postgres', 'major' => '18', 'cores' => '2', 'memory_mb' => '4096', 'disk_gb' => '40' ) as $key => $value ) {
			$product->update_meta_data( Product::META_PLAN . $key, $value );
		}

		$product->save();
		Product::remember_slug( (string) $product->get_meta( \BeltoftLicenses\Software::META_SLUG ) );
		$out = array();

		foreach ( array( 'm' => 'Monthly', 'u' => 'Pay as you go' ) as $short => $billing ) {
			foreach ( array( 0 => array( 'None', 45 ), 50 => array( '50 GB', 49 ) ) as $extra => $set ) {
				foreach ( array( 0, 1 ) as $with ) {
					$v = new \WC_Product_Variation();
					$v->set_parent_id( $product->get_id() );
					$v->set_attributes( array( 'plan' => 'Standard', 'billing' => $billing, 'extra-storage' => $set[0], 'public-ip' => $with ? 'Yes' : 'No' ) );
					$v->set_regular_price( 'm' === $short ? (string) ( $set[1] + ( $with ? 5 : 0 ) ) : '0' );
					$v->set_status( 'publish' );
					$v->update_meta_data( Product::META_PLAN . 'public_ip', (string) $with );
					$v->update_meta_data( Product::META_PLAN . 'extra_disk_gb', (string) $extra );

					if ( 'u' === $short ) {
						$v->update_meta_data( Rates::META_BILLING, 'usage' );
					}

					$v->save();
					$out[ $short . '-' . $extra . '-' . $with ] = $v;
				}
			}
		}

		\WC_Product_Variable::sync( $product->get_id() );

		return $out;
	}
}

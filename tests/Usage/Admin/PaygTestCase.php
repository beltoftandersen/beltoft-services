<?php
/**
 * Shared set-up of the pay as you go editor tests: monthly products, a per unit driver, posted forms.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Usage\Admin;

use BeltoftServices\Drivers\CountsUsage;
use BeltoftServices\Product;
use BeltoftServices\Tests\Support\FakeDriver;
use BeltoftServices\Tests\Usage\UsageTestCase;

require_once dirname( __DIR__ ) . '/UsageTestCase.php';

/**
 * A driver that reports counted units, like the HTTP driver (the marker is all the editor asks).
 */
class CountingDriver extends FakeDriver implements CountsUsage {
}

/**
 * Base class.
 */
abstract class PaygTestCase extends UsageTestCase {

	public function set_up(): void {
		parent::set_up();
		$this->fake_driver( 'counting' );
		add_filter(
			'beltoft_services_drivers',
			static function ( $drivers ) {
				$drivers['counting'] = new CountingDriver( 'counting' );

				return $drivers;
			}
		);
		unset( $GLOBALS['product_object'] );
		\WC_Admin_Meta_Boxes::$meta_box_errors = array();
		\BeltoftServices\Usage\Admin\PaygVariation::$log = array();
		update_option( 'woocommerce_currency', 'EUR' );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		// The hidden usage account product, so the account check passes unless a test removes it.
		update_option( \BeltoftServices\Usage\Account::OPTION_PRODUCT, self::factory()->post->create( array( 'post_type' => 'product', 'post_status' => 'publish' ) ) );
	}

	public function tear_down(): void {
		$_POST = array();
		unset( $GLOBALS['product_object'] );
		\WC_Admin_Meta_Boxes::$meta_box_errors = array();
		parent::tear_down();
	}

	/**
	 * A variable product with one attribute, Plan, and a monthly variation per plan (price in the second column).
	 *
	 * @param string                $driver Driver id.
	 * @param array<string, string> $plans  Plan => monthly price.
	 * @param string                $sku    SKU prefix ('' for none).
	 */
	protected function monthly_product( string $driver = 'proxmox', array $plans = array( 'Small' => '15', 'Large' => '30' ), string $sku = '' ): \WC_Product_Variable {
		$product = new \WC_Product_Variable();
		$product->set_name( 'Plan product ' . wp_generate_password( 5, false ) );
		$product->set_status( 'publish' );

		$attribute = new \WC_Product_Attribute();
		$attribute->set_name( 'Plan' );
		$attribute->set_options( array_keys( $plans ) );
		$attribute->set_visible( true );
		$attribute->set_variation( true );
		$product->set_attributes( array( $attribute ) );
		$slug = 'payg-' . wp_generate_password( 5, false );
		$product->update_meta_data( Product::META_DRIVER, $driver );
		$product->update_meta_data( \BeltoftLicenses\Software::META_LICENSED, 'yes' );
		$product->update_meta_data( \BeltoftLicenses\Software::META_SLUG, $slug );
		$product->save();
		Product::remember_slug( $slug );

		foreach ( $plans as $name => $price ) {
			$v = new \WC_Product_Variation();
			$v->set_parent_id( $product->get_id() );
			$v->set_attributes( array( 'plan' => $name ) );
			$v->set_regular_price( $price );
			$v->set_status( 'publish' );
			$v->set_virtual( true );

			if ( '' !== $sku ) {
				$v->set_sku( $sku . '-' . strtoupper( $name ) . '-' . wp_generate_password( 3, false ) );
			}

			$v->update_meta_data( Product::META_PLAN . 'cores', 'Small' === $name ? '2' : '4' );
			$v->save();
		}

		\WC_Product_Variable::sync( $product->get_id() );

		return wc_get_product( $product->get_id() );
	}

	/**
	 * A pay as you go product: a Variable product with the kind on the product and a monthly price (shop currency) on each plan.
	 *
	 * @param string                $driver Driver id.
	 * @param string                $kind   usage or counted.
	 * @param array<string, string> $plans  Plan => monthly price for the rate.
	 */
	protected function payg_product( string $driver = 'proxmox', string $kind = 'usage', array $plans = array( 'Small' => '15', 'Large' => '30' ) ): \WC_Product_Variable {
		$product = $this->monthly_product( $driver, array_map( static fn() => '0', $plans ) );
		$product->update_meta_data( \BeltoftServices\Usage\Rates::META_BILLING, $kind );
		$product->save();

		foreach ( $plans as $name => $monthly ) {
			$v = $this->variation_with( $product, 'plan', $name );

			if ( 'usage' === $kind ) {
				$v->update_meta_data( \BeltoftServices\Usage\Rates::META_MONTHLY, $monthly );
				$v->save();
			}
		}

		return wc_get_product( $product->get_id() );
	}

	/**
	 * A variation of a product by attribute value.
	 *
	 * @param \WC_Product $product Product.
	 * @param string      $key     Attribute key.
	 * @param string      $value   Attribute value.
	 */
	protected function variation_with( \WC_Product $product, string $key, string $value ): ?\WC_Product_Variation {
		foreach ( \BeltoftServices\Usage\Admin\PaygProduct::variations( wc_get_product( $product->get_id() ) ) as $v ) {
			if ( (string) ( $v->get_attributes()[ $key ] ?? '' ) === $value ) {
				return $v;
			}
		}

		return null;
	}

	/**
	 * The Service panel's driver id for a product.
	 *
	 * @param \WC_Product $product Product.
	 */
	protected function driver_id( \WC_Product $product ): string {
		return (string) $product->get_meta( Product::META_DRIVER );
	}

	/**
	 * Messages WooCommerce kept to show on the next screen.
	 *
	 * @return string[]
	 */
	protected function refusals(): array {
		return array_values( array_unique( array_merge( array_map( 'strval', \WC_Admin_Meta_Boxes::$meta_box_errors ), \BeltoftServices\Usage\Admin\PaygVariation::$log ) ) );
	}

	/**
	 * A live resource on a variation (a licence and an open activation), so a service runs on it.
	 *
	 * @param \WC_Product_Variation $variation Variation.
	 */
	protected function running_on( \WC_Product_Variation $variation ): void {
		$parent  = wc_get_product( $variation->get_parent_id() );
		$license = $this->service_license(
			$parent,
			array(
				'product_id'   => $parent->get_id(),
				'product_slug' => (string) $parent->get_meta( \BeltoftLicenses\Software::META_SLUG ),
				'variation_id' => $variation->get_id(),
				'user_id'      => $this->new_user(),
				'email'        => self::MAIL,
				'source'       => 'usage',
				'fire'         => false,
			)
		);

		$this->assertInstanceOf( \BeltoftServices\Resource::class, \BeltoftServices\Resource::create( $license, \BeltoftServices\Drivers\Registry::get( 'proxmox' ), Product::plan( $parent->get_id(), $variation->get_id() ), array() ) );
	}
}

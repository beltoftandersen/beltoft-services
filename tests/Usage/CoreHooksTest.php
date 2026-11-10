<?php
/**
 * The pay as you go premium field of the product form.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Usage;

use BeltoftServices\Product;
use BeltoftServices\Tests\TestCase;
use BeltoftServices\Usage\Rates;

/**
 * Rendered and saved through the Service panel and Product::save(), as the product form does.
 */
class CoreHooksTest extends TestCase {

	public function tear_down(): void {
		$_POST = array();
		unset( $GLOBALS['product_object'] );
		parent::tear_down();
	}

	private function panel_html( \WC_Product $product ): string {
		include_once WC_ABSPATH . 'includes/admin/wc-meta-box-functions.php';
		$GLOBALS['product_object'] = $product;
		ob_start();
		Product::panel();

		return (string) ob_get_clean();
	}

	/**
	 * Saves a posted premium through the product form path and reads it back.
	 *
	 * @param array<string, mixed> $post Posted fields besides the premium.
	 * @param mixed                $premium Posted premium.
	 */
	private function saved( array $post, $premium ): string {
		$this->fake_driver();
		$product = $this->service_product();
		$product->update_meta_data( Rates::META_PREMIUM, 'untouched' );
		$product->save();
		$product = wc_get_product( $product->get_id() );

		$_POST = array_merge( $post, array( Rates::META_PREMIUM => $premium ) );
		Product::save( $product );

		return (string) $product->get_meta( Rates::META_PREMIUM );
	}

	public function test_the_premium_field_renders_with_a_driver_only(): void {
		$this->fake_driver();

		$this->assertStringNotContainsString( 'id="' . Rates::META_PREMIUM . '"', $this->panel_html( new \WC_Product_Simple() ) );
		$this->assertStringContainsString( 'id="' . Rates::META_PREMIUM . '"', $this->panel_html( wc_get_product( $this->service_product()->get_id() ) ) );
	}

	public function test_a_posted_premium_is_saved_clean(): void {
		$with_driver = array( Product::META_DRIVER => 'fake' );

		$this->assertSame( '12.5', $this->saved( $with_driver, '12.5' ) );
		$this->assertSame( '0', $this->saved( $with_driver, '-3' ) );
		$this->assertSame( '', $this->saved( $with_driver, 'abc' ) );
	}

	public function test_nothing_is_saved_when_no_driver_is_posted(): void {
		$this->fake_driver();
		$product = new \WC_Product_Simple();
		$product->save();

		$_POST = array( Rates::META_PREMIUM => '12.5' );
		Product::save( $product );

		$this->assertSame( '', (string) $product->get_meta( Rates::META_PREMIUM ) );
	}
}

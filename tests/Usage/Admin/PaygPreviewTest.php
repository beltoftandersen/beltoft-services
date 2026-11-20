<?php
/**
 * The preview: one row per plan of the product, per currency.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Usage\Admin;

use BeltoftServices\Usage\Admin\PaygPreview;
use BeltoftServices\Usage\Rates;

require_once __DIR__ . '/PaygTestCase.php';

/**
 * Preview.
 */
class PaygPreviewTest extends PaygTestCase {

	public function test_an_hourly_plan_shows_its_monthly_price_rate_and_cap_per_currency(): void {
		add_filter( 'beltoft_services_currencies', static fn( $codes ) => array_merge( $codes, array( 'DKK' ) ) );
		$product = $this->payg_product( 'proxmox', 'usage', array( 'Small' => '15' ) );
		$v       = $this->variation_with( $product, 'plan', 'Small' );
		$v->update_meta_data( Rates::META_MONTHLY . '_DKK', '110' );
		$v->save();

		$rows = PaygPreview::rows( wc_get_product( $product->get_id() ) );

		$this->assertCount( 1, $rows );
		$this->assertSame( 'Small', $rows[0]['name'] );
		$this->assertSame( 15.0, $rows[0]['prices']['EUR']['monthly'] );
		$this->assertSame( 0.0226, round( $rows[0]['prices']['EUR']['hourly'], 4 ) );
		$this->assertSame( 16.5, $rows[0]['prices']['EUR']['cap'] );
		$this->assertSame( 121.0, $rows[0]['prices']['DKK']['cap'] );
	}

	public function test_the_premium_of_the_product_is_used_and_a_missing_price_is_no_figure(): void {
		$product = $this->payg_product( 'proxmox', 'usage', array( 'Small' => '15', 'Large' => '30' ) );
		$product->update_meta_data( Rates::META_PREMIUM, '0' );
		$product->save();
		$v = $this->variation_with( $product, 'plan', 'Large' );
		$v->delete_meta_data( Rates::META_MONTHLY );
		$v->save();

		$rows = PaygPreview::rows( wc_get_product( $product->get_id() ) );

		$this->assertSame( 15.0, $rows[0]['prices']['EUR']['cap'] );
		$this->assertNull( $rows[1]['prices']['EUR']['monthly'] );
		$this->assertNull( $rows[1]['prices']['EUR']['cap'] );
	}

	public function test_a_per_unit_plan_shows_what_a_thousand_units_cost_and_a_plan_off_sale_is_left_out(): void {
		$product = $this->payg_product( 'counting', 'counted', array( 'Per order' => '0', 'Other' => '0' ) );
		$v       = $this->variation_with( $product, 'plan', 'Per order' );
		$v->update_meta_data( Rates::META_UNIT_STEPS, '500:0.025,2000:0.02,0:0.015' );
		$v->save();
		$off = $this->variation_with( $product, 'plan', 'Other' );
		$off->set_status( 'private' );
		$off->save();

		$rows = PaygPreview::rows( wc_get_product( $product->get_id() ) );

		$this->assertCount( 1, $rows );
		$this->assertEqualsWithDelta( 22.5, $rows[0]['prices']['EUR']['example'], 0.0001 );
		$this->assertNull( $rows[0]['prices']['EUR']['monthly'] );
	}

	public function test_a_monthly_product_has_a_row_without_figures(): void {
		$rows = PaygPreview::rows( $this->monthly_product() );

		$this->assertNull( $rows[0]['prices']['EUR']['monthly'] );
		$this->assertNull( $rows[0]['prices']['EUR']['example'] );
	}
}

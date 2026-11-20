<?php
/**
 * The pay as you go lines of a variation row: shown, saved in the stored format, refused when bad.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Usage\Admin;

use BeltoftServices\Usage\Admin\PaygVariation;
use BeltoftServices\Usage\Rates;

require_once __DIR__ . '/PaygTestCase.php';

/**
 * Variation row.
 */
class PaygVariationTest extends PaygTestCase {

	public function set_up(): void {
		parent::set_up();
		add_filter( 'beltoft_services_currencies', static fn( $codes ) => array_merge( $codes, array( 'DKK' ) ) );
	}

	private function row_html( \WC_Product_Variation $v ): string {
		include_once WC_ABSPATH . 'includes/admin/wc-meta-box-functions.php';
		ob_start();
		PaygVariation::fields( 0, array(), get_post( $v->get_id() ) );

		return (string) ob_get_clean();
	}

	private function save( \WC_Product_Variation $v ): \WC_Product_Variation {
		PaygVariation::save( $v, 0 );
		$v->save();

		return wc_get_product( $v->get_id() );
	}

	private function hourly(): \WC_Product_Variation {
		return $this->variation_with( $this->payg_product( 'proxmox', 'usage', array( 'Small' => '15' ) ), 'plan', 'Small' );
	}

	private function counted(): \WC_Product_Variation {
		$product = $this->payg_product( 'counting', 'counted', array( 'Per order' => '0' ) );
		$v       = $this->variation_with( $product, 'plan', 'Per order' );
		$v->update_meta_data( Rates::META_UNIT_STEPS, '500:0.025,2000:0.02,0:0.015' );
		$v->save();

		return wc_get_product( $v->get_id() );
	}

	public function test_a_monthly_product_and_a_driver_that_cannot_be_billed_by_use_have_no_lines(): void {
		$this->assertSame( '', $this->row_html( $this->variation_with( $this->monthly_product( 'proxmox' ), 'plan', 'Small' ) ) );
		$this->assertSame( '', $this->row_html( $this->variation_with( $this->payg_product( 'fake' ), 'plan', 'Small' ) ) );
	}

	public function test_an_hourly_plan_has_a_monthly_price_field_per_currency_with_the_rate_next_to_it(): void {
		$v = $this->hourly();
		$v->update_meta_data( Rates::META_MONTHLY . '_DKK', '110' );
		$v->save();
		$html = $this->row_html( wc_get_product( $v->get_id() ) );

		$this->assertStringContainsString( 'Monthly price this rate is taken from, excl. VAT (EUR)', $html );
		$this->assertStringContainsString( 'Monthly price this rate is taken from, excl. VAT (DKK)', $html );
		$this->assertStringContainsString( 'name="_beltoft_services_payg_monthly[0][EUR]" value="15"', $html );
		$this->assertStringContainsString( 'name="_beltoft_services_payg_monthly[0][DKK]" value="110"', $html );
		$this->assertStringContainsString( '0.0226 per hour, max 16.50 per month', $html );
		$this->assertStringContainsString( 'class="description bs-payg-help"', $html, 'the help is grey text under the fields, not a tip' );
		$this->assertStringNotContainsString( 'twin', $html );
	}

	public function test_monthly_prices_are_validated_for_every_currency_before_any_is_written(): void {
		$v = $this->hourly();

		$_POST = array( PaygVariation::FIELD_MONTHLY => array( 0 => array( 'EUR' => '20', 'DKK' => 'abc' ) ) );
		$v     = $this->save( $v );

		$this->assertSame( '15', (string) $v->get_meta( Rates::META_MONTHLY ), 'nothing written' );
		$this->assertSame( '', (string) $v->get_meta( Rates::META_MONTHLY . '_DKK' ) );
		$this->assertStringContainsString( 'DKK: the monthly price must be a number above 0. Nothing in this plan was saved.', implode( ' ', $this->refusals() ) );
		$this->assertStringContainsString( 'bs-payg-refusal', $this->row_html( $v ), 'the refusal shows red under the plan' );

		$_POST = array( PaygVariation::FIELD_MONTHLY => array( 0 => array( 'EUR' => '20', 'DKK' => '150' ) ) );
		$v     = $this->save( $v );

		$this->assertSame( '20', (string) $v->get_meta( Rates::META_MONTHLY ) );
		$this->assertSame( '150', (string) $v->get_meta( Rates::META_MONTHLY . '_DKK' ) );
	}

	public function test_the_shop_currency_cannot_be_emptied_but_another_can(): void {
		$v = $this->hourly();
		$v->update_meta_data( Rates::META_MONTHLY . '_DKK', '110' );
		$v->save();

		$_POST = array( PaygVariation::FIELD_MONTHLY => array( 0 => array( 'EUR' => '', 'DKK' => '' ) ) );
		$v     = $this->save( wc_get_product( $v->get_id() ) );

		$this->assertSame( '15', (string) $v->get_meta( Rates::META_MONTHLY ) );
		$this->assertSame( '110', (string) $v->get_meta( Rates::META_MONTHLY . '_DKK' ), 'refused as a whole' );

		$_POST = array( PaygVariation::FIELD_MONTHLY => array( 0 => array( 'EUR' => '15', 'DKK' => '' ) ) );
		$v     = $this->save( $v );

		$this->assertSame( '', (string) $v->get_meta( Rates::META_MONTHLY . '_DKK' ) );
	}

	public function test_a_price_typed_another_way_is_not_a_change(): void {
		$v     = $this->hourly();
		$_POST = array( PaygVariation::FIELD_MONTHLY => array( 0 => array( 'EUR' => '15.00' ) ) );

		PaygVariation::save( $v, 0 );

		$this->assertSame( '15', (string) $v->get_meta( Rates::META_MONTHLY ) );
		$this->assertSame( array(), $this->refusals() );
	}

	public function test_a_per_unit_plan_shows_its_steps_as_rows_and_a_worked_example_per_currency(): void {
		$v = $this->counted();
		$v->update_meta_data( Rates::META_UNIT_STEPS . '_DKK', '100:1,0:0.5' );
		$v->save();
		$html = $this->row_html( wc_get_product( $v->get_id() ) );

		$this->assertStringContainsString( 'name="_beltoft_services_payg_rows[0][EUR][0][upto]" value="500"', $html );
		$this->assertStringContainsString( 'name="_beltoft_services_payg_rows[0][EUR][2][price]" value="0.015"', $html );
		$this->assertStringContainsString( 'name="_beltoft_services_payg_unit_price[0][DKK]"', $html );
		$this->assertStringContainsString( '1,000 units in a month = 22.50 EUR, excl. VAT.', $html );
		$this->assertStringContainsString( '1,000 units in a month = 550.00 DKK, excl. VAT.', $html );
	}

	public function test_steps_rows_are_saved_in_the_format_rates_reads(): void {
		$v = $this->counted();

		$_POST = array(
			PaygVariation::FIELD_ROWS => array( 0 => array( 'EUR' => array(
				array( 'upto' => '100', 'price' => '0.10' ),
				array( 'upto' => '1000', 'price' => '0.05' ),
				array( 'upto' => '0', 'price' => '0.02' ),
				array( 'upto' => '', 'price' => '' ),
			) ) ),
		);
		$v     = $this->save( $v );

		$this->assertSame( '100:0.10,1000:0.05,0:0.02', (string) $v->get_meta( Rates::META_UNIT_STEPS ) );
		$this->assertCount( 3, Rates::unit_steps( $v ) );
	}

	public function test_bad_steps_in_any_currency_save_nothing_and_say_so(): void {
		$v = $this->counted();

		$_POST = array(
			PaygVariation::FIELD_PRICE => array( 0 => array( 'EUR' => '0.5' ) ),
			PaygVariation::FIELD_ROWS  => array( 0 => array( 'DKK' => array( array( 'upto' => '500', 'price' => '1' ), array( 'upto' => '100', 'price' => '2' ) ) ) ),
		);
		$v     = $this->save( $v );

		$this->assertSame( '', (string) $v->get_meta( Rates::META_UNIT_PRICE ), 'the valid EUR price is not written either' );
		$this->assertStringContainsString( 'DKK price steps: each step needs more units than the one before', implode( ' ', $this->refusals() ) );
	}

	public function test_a_per_unit_plan_needs_a_price_or_steps_in_the_shop_currency(): void {
		$v = $this->counted();
		$v->delete_meta_data( Rates::META_UNIT_STEPS );
		$v->save();

		$_POST = array( PaygVariation::FIELD_PRICE => array( 0 => array( 'EUR' => '' ) ) );
		$v     = $this->save( wc_get_product( $v->get_id() ) );

		$this->assertStringContainsString( 'a per unit plan needs a unit price or price steps', implode( ' ', $this->refusals() ) );
		$this->assertSame( '', (string) $v->get_meta( Rates::META_UNIT_STEPS ) );
	}

	public function test_the_browser_checks_the_steps_before_the_save(): void {
		$js = (string) file_get_contents( dirname( __DIR__, 3 ) . '/assets/payg-admin.js' );

		$this->assertStringContainsString( 'validateSteps', $js );
		$this->assertStringContainsString( "button.save-variation-changes", $js );
		$this->assertStringContainsString( 'cfg.steps', $js );
	}
}

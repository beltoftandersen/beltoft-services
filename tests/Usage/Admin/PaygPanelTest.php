<?php
/**
 * The Billing block of the Service panel and what saving it does.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Usage\Admin;

use BeltoftServices\Product;
use BeltoftServices\Usage\Admin\PaygPanel;
use BeltoftServices\Usage\Rates;

require_once __DIR__ . '/PaygTestCase.php';

/**
 * Panel.
 */
class PaygPanelTest extends PaygTestCase {

	private function panel_html( \WC_Product $product ): string {
		include_once WC_ABSPATH . 'includes/admin/wc-meta-box-functions.php';
		$GLOBALS['product_object'] = wc_get_product( $product->get_id() );
		ob_start();
		Product::panel();

		return (string) ob_get_clean();
	}

	private function post( string $kind, ?string $premium = null ): void {
		$_POST = array( Rates::META_BILLING => $kind );

		if ( null !== $premium ) {
			$_POST[ Rates::META_PREMIUM ] = $premium;
		}
	}

	private function saved( \WC_Product $product, string $driver = 'proxmox' ): \WC_Product {
		$product = wc_get_product( $product->get_id() );
		PaygPanel::save( $product, $driver );
		$product->save();

		return wc_get_product( $product->get_id() );
	}

	public function test_a_driver_that_cannot_be_billed_by_use_gets_a_grey_line_and_no_select(): void {
		$html = $this->panel_html( $this->monthly_product( 'fake' ) );

		$this->assertStringContainsString( 'This service cannot be billed by use.', $html );
		$this->assertStringNotContainsString( 'id="' . Rates::META_BILLING . '"', $html );
		$this->assertStringContainsString( '<h3 class="bs-payg-heading">Billing</h3>', $this->panel_html( new \WC_Product_Simple() ), 'the heading is always there' );
	}

	public function test_the_select_offers_only_what_the_driver_supports(): void {
		$hourly = PaygPanel::options( 'proxmox' );
		$this->assertSame( array( '', 'usage' ), array_keys( $hourly ) );
		$this->assertSame( 'Monthly subscription', $hourly[''] );
		$this->assertSame( 'Pay as you go by the hour', $hourly['usage'] );

		$this->assertSame( array( '', 'counted' ), array_keys( PaygPanel::options( 'counting' ) ) );
		$this->assertSame( 'Pay per unit', PaygPanel::options( 'counting' )['counted'] );
		$this->assertSame( array( '' ), array_keys( PaygPanel::options( 'fake' ) ) );
		$this->assertSame( array( '', 'usage' ), array_keys( PaygPanel::options( 'fake', 'usage' ) ), 'a stored kind is never dropped silently' );

		$html = $this->panel_html( $this->monthly_product( 'proxmox' ) );
		$this->assertStringContainsString( 'id="' . Rates::META_BILLING . '"', $html );
		$this->assertStringContainsString( 'value="usage"', $html );
		$this->assertStringNotContainsString( 'value="counted"', $html );
		$this->assertStringNotContainsString( 'bs-payg-preview', $html, 'a monthly product has no preview' );
		$this->assertStringNotContainsString( 'All checks pass', $html );
	}

	public function test_an_hourly_product_shows_premium_preview_running_note_and_checks(): void {
		$product = $this->payg_product();
		$product->update_meta_data( Rates::META_PREMIUM, '10' );
		$product->save();
		$this->running_on( $this->variation_with( $product, 'plan', 'Small' ) );

		$html = $this->panel_html( $product );

		$this->assertMatchesRegularExpression( '/value="usage"[^>]*\sselected/s', $html );
		$this->assertStringContainsString( 'id="' . Rates::META_PREMIUM . '"', $html );
		$this->assertStringContainsString( 'Added to the monthly price for the hourly rate and the monthly cap. Empty means 10.', $html );
		$this->assertStringContainsString( 'placeholder="10"', $html );
		$this->assertStringContainsString( '<td>15.00</td><td class="bs-payg-hourly" data-monthly="15">0.0226</td><td class="bs-payg-cap" data-monthly="15">16.50</td>', $html );
		$this->assertStringContainsString( 'Services billed from these prices: 1. A change applies from the next hour.', $html );
		$this->assertStringContainsString( 'bs-payg-allpass', $html );
		$this->assertStringContainsString( 'All checks pass', $html );
		$this->assertSame( 1, substr_count( $html, 'id="' . Rates::META_PREMIUM . '"' ), 'the premium field of the core hooks is not printed twice' );
	}

	public function test_the_premium_is_hidden_for_a_per_unit_product_and_a_counted_preview_shows_units(): void {
		$product = $this->payg_product( 'counting', 'counted', array( 'Per order' => '0' ) );
		$v       = $this->variation_with( $product, 'plan', 'Per order' );
		$v->update_meta_data( Rates::META_UNIT_STEPS, '500:0.025,0:0.015' );
		$v->save();

		$html = $this->panel_html( $product );

		$this->assertMatchesRegularExpression( '/<div class="bs-payg-premium" style="display:none">/', $html );
		$this->assertStringContainsString( '<th>1,000 units</th>', $html );
		$this->assertStringContainsString( '<td>20.00</td>', $html );
	}

	public function test_problems_are_prefixed_not_only_coloured(): void {
		$product = $this->payg_product();
		$product->update_meta_data( Rates::META_PREMIUM, '-4' );
		$product->save();
		$html = $this->panel_html( $product );

		$this->assertStringContainsString( '<strong>Problem:</strong> The premium is not a number of 0 or more.', $html );
		$this->assertStringNotContainsString( 'All checks pass', $html );

		$product->update_meta_data( Rates::META_PREMIUM, '250' );
		$product->save();
		$this->assertStringContainsString( '<strong>Note:</strong> The premium is above 100 percent', $this->panel_html( $product ) );
	}

	public function test_the_kind_is_written_only_when_it_changed(): void {
		$product = $this->monthly_product();

		$this->post( '' );
		$product = $this->saved( $product );
		$this->assertSame( array(), get_post_meta( $product->get_id(), Rates::META_BILLING ), 'blank on a monthly product writes nothing' );

		$this->post( 'usage' );
		$product = $this->saved( $product );
		$this->assertSame( 'usage', $product->get_meta( Rates::META_BILLING ) );

		$this->post( '' );
		$product = $this->saved( $product );
		$this->assertSame( array(), get_post_meta( $product->get_id(), Rates::META_BILLING ), 'back to monthly removes the meta' );
	}

	public function test_a_kind_the_driver_cannot_bill_is_ignored(): void {
		$product = $this->monthly_product( 'proxmox' );

		$this->post( 'counted' );
		$this->assertSame( '', $this->saved( $product )->get_meta( Rates::META_BILLING ) );

		$this->post( 'nonsense' );
		$this->assertSame( '', $this->saved( $product )->get_meta( Rates::META_BILLING ) );
	}

	public function test_a_subscription_product_type_is_refused(): void {
		$sub = new \WC_Product_Variable_Subscription();
		$sub->set_name( 'Sub' );
		$sub->update_meta_data( Product::META_DRIVER, 'proxmox' );
		$sub->save();

		$this->post( 'usage' );
		$sub = $this->saved( $sub );

		$this->assertSame( '', $sub->get_meta( Rates::META_BILLING ) );
		$this->assertStringContainsString( 'Create it as a Variable product', implode( ' ', $this->refusals() ) );
	}

	public function test_billing_cannot_change_while_a_service_runs_on_the_product(): void {
		$product = $this->payg_product();
		$this->running_on( $this->variation_with( $product, 'plan', 'Small' ) );

		$this->post( '' );
		$product = $this->saved( $product );

		$this->assertSame( 'usage', $product->get_meta( Rates::META_BILLING ) );
		$this->assertStringContainsString( 'A service runs on this product', implode( ' ', $this->refusals() ) );
	}

	public function test_the_premium_is_validated_and_written_only_when_it_changed(): void {
		$product = $this->payg_product();

		$this->post( 'usage', '' );
		$this->assertSame( array(), get_post_meta( $this->saved( $product )->get_id(), Rates::META_PREMIUM ), 'empty on none writes nothing' );

		$this->post( 'usage', '12.5' );
		$product = $this->saved( $product );
		$this->assertSame( '12.5', $product->get_meta( Rates::META_PREMIUM ) );

		$this->post( 'usage', '-3' );
		$product = $this->saved( $product );
		$this->assertSame( '12.5', $product->get_meta( Rates::META_PREMIUM ), 'refused: the saved value stays' );
		$this->assertStringContainsString( 'The premium must be a number of 0 or more', implode( ' ', $this->refusals() ) );

		$this->post( 'usage', '12.50' );
		$product = $this->saved( $product );
		$this->assertSame( '12.5', $product->get_meta( Rates::META_PREMIUM ), 'the same number typed another way is no change' );
	}

	public function test_the_checks_answer_by_ajax_only_with_the_nonce_and_the_right(): void {
		$product = $this->payg_product();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		$this->assertStringContainsString( 'bs-payg-allpass', PaygPanel::problems_html( $product ) );
		$this->assertTrue( has_action( 'wp_ajax_' . PaygPanel::AJAX ) !== false );
		$this->assertFalse( current_user_can( 'edit_product', $product->get_id() ) );
	}
}

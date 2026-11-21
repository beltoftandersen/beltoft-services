<?php
/**
 * The checks of the product editor: each one red and green.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Usage\Admin;

use BeltoftServices\Usage\Account;
use BeltoftServices\Usage\Admin\PaygChecks;
use BeltoftServices\Usage\Rates;

require_once __DIR__ . '/PaygTestCase.php';

/**
 * Checks.
 */
class PaygChecksTest extends PaygTestCase {

	/**
	 * The level of one check.
	 *
	 * @param \WC_Product $product Product.
	 * @param string      $code    Check.
	 */
	private function level( \WC_Product $product, string $code ): string {
		foreach ( PaygChecks::run( wc_get_product( $product->get_id() ) ) as $check ) {
			if ( $code === $check['code'] ) {
				return $check['level'];
			}
		}

		return 'missing';
	}

	public function test_a_monthly_product_has_nothing_to_check(): void {
		$this->assertSame( array(), PaygChecks::run( $this->monthly_product() ) );
	}

	public function test_five_checks_and_the_type_run_for_a_pay_as_you_go_product_and_all_pass_when_set_up(): void {
		$account = $this->make_simple_account_product();
		$product = $this->payg_product();
		$checks  = PaygChecks::run( $product );

		$this->assertSame( array( 'type', 'rate', 'units', 'premium', 'currency', 'account' ), array_column( $checks, 'code' ) );
		$this->assertSame( array( 'ok' ), array_values( array_unique( array_column( $checks, 'level' ) ) ), $account );
	}

	private function make_simple_account_product(): string {
		$id = $this->factory()->post->create( array( 'post_type' => 'product', 'post_status' => 'publish' ) );
		update_option( Account::OPTION_PRODUCT, $id );

		return (string) $id;
	}

	public function test_a_billing_kind_on_a_product_without_variations_is_an_error(): void {
		$sub = new \WC_Product_Subscription();
		$sub->set_name( 'Sub' );
		$sub->update_meta_data( Rates::META_BILLING, 'usage' );
		$sub->save();

		$check = PaygChecks::run( wc_get_product( $sub->get_id() ) )[0];

		$this->assertSame( 'error', $check['level'] );
		$this->assertStringContainsString( 'Create it as a Variable product', $check['text'] );
		$this->assertSame( 'ok', $this->level( $this->payg_product(), 'type' ) );
	}

	public function test_a_converted_variable_subscription_passes_the_type_check(): void {
		$sub = new \WC_Product_Variable_Subscription();
		$sub->set_name( 'Converted' );
		$sub->update_meta_data( Rates::META_BILLING, 'usage' );
		$sub->save();

		$this->assertSame( 'ok', $this->level( wc_get_product( $sub->get_id() ), 'type' ) );
	}

	public function test_rate_is_an_error_for_an_hourly_plan_without_a_monthly_price(): void {
		$product = $this->payg_product();
		$v       = $this->variation_with( $product, 'plan', 'Small' );
		$v->delete_meta_data( Rates::META_MONTHLY );
		$v->save();

		$this->assertSame( 'error', $this->level( $product, 'rate' ) );
		$this->assertSame( array( $v->get_id() => 'Small' ), PaygChecks::run( wc_get_product( $product->get_id() ) )[1]['plans'] );

		$v->update_meta_data( Rates::META_MONTHLY, '0' );
		$v->save();
		$this->assertSame( 'error', $this->level( $product, 'rate' ), 'a price of 0 is no price' );
	}

	public function test_a_plan_that_is_not_on_sale_is_not_checked(): void {
		$product = $this->payg_product();
		$v       = $this->variation_with( $product, 'plan', 'Small' );
		$v->delete_meta_data( Rates::META_MONTHLY );
		$v->set_status( 'private' );
		$v->save();

		$this->assertSame( 'ok', $this->level( $product, 'rate' ) );
	}

	public function test_units_needs_a_unit_price_in_the_shop_currency_and_readable_steps(): void {
		$product = $this->payg_product( 'counting', 'counted', array( 'Per order' => '0' ) );
		$v       = $this->variation_with( $product, 'plan', 'Per order' );

		$this->assertSame( 'error', $this->level( $product, 'units' ) );

		$v->update_meta_data( Rates::META_UNIT_STEPS, '500:0.025,0:0.015' );
		$v->save();
		$this->assertSame( 'ok', $this->level( $product, 'units' ) );

		$v->update_meta_data( Rates::META_UNIT_STEPS, '500:x' );
		$v->save();
		$this->assertSame( 'error', $this->level( $product, 'units' ) );
		$this->assertSame( 'ok', $this->level( $this->payg_product(), 'units' ), 'an hourly product has no unit check to fail' );
	}

	public function test_premium_is_an_error_below_zero_and_a_note_above_a_hundred(): void {
		$product = $this->payg_product();

		foreach ( array( '-4' => 'error', 'abc' => 'error', '250' => 'warn', '' => 'ok', '10' => 'ok' ) as $value => $level ) {
			$product->update_meta_data( Rates::META_PREMIUM, (string) $value );
			$product->save();
			$this->assertSame( $level, $this->level( $product, 'premium' ), 'premium ' . $value );
		}
	}

	public function test_currency_notes_a_currency_with_no_rate(): void {
		$product = $this->payg_product();
		add_filter( 'beltoft_services_currencies', static fn( $codes ) => array_merge( $codes, array( 'DKK' ) ) );

		$this->assertSame( 'warn', $this->level( $product, 'currency' ) );

		foreach ( array( 'Small', 'Large' ) as $plan ) {
			$v = $this->variation_with( $product, 'plan', $plan );
			$v->update_meta_data( Rates::META_MONTHLY . '_DKK', '100' );
			$v->save();
		}

		$this->assertSame( 'ok', $this->level( $product, 'currency' ) );
	}

	public function test_account_is_a_note_while_the_usage_account_product_is_missing(): void {
		$product = $this->payg_product();
		update_option( Account::OPTION_PRODUCT, 0 );

		$this->assertSame( 'warn', $this->level( $product, 'account' ) );

		$this->make_simple_account_product();
		$this->assertSame( 'ok', $this->level( $product, 'account' ) );
	}
}

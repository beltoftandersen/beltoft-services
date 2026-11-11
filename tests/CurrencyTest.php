<?php
/**
 * The currency helpers of the core.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests;

use BeltoftServices\Currency;
use BeltoftServices\Settings;
use BeltoftServices\Tests\Support\FakeCurrency;

/**
 * Currency tests.
 */
final class CurrencyTest extends TestCase {

	use FakeCurrency;

	public function tear_down(): void {
		$this->fake_currency_down();
		parent::tear_down();
	}

	public function test_base_is_the_option_not_the_filtered_getter(): void {
		update_option( 'woocommerce_currency', 'EUR' );
		add_filter( 'woocommerce_currency', array( $this, 'usd' ) );

		$this->assertSame( 'EUR', Currency::base() );
		$this->assertSame( 'USD', Currency::current() );

		remove_filter( 'woocommerce_currency', array( $this, 'usd' ) );
	}

	/** Filter callback. */
	public function usd(): string {
		return 'USD';
	}

	public function test_with_the_current_currency_runs_without_a_runner(): void {
		update_option( 'woocommerce_currency', 'EUR' );

		$this->assertSame( array( true, 7 ), Currency::with( 'EUR', fn() => 7 ) );
		$this->assertSame( array( true, 8 ), Currency::with( '', fn() => 8 ) );
	}

	public function test_with_another_currency_and_no_runner_fails_closed(): void {
		update_option( 'woocommerce_currency', 'EUR' );
		$ran = false;

		$out = Currency::with(
			'DKK',
			function () use ( &$ran ) {
				$ran = true;
				return 1;
			}
		);

		$this->assertSame( array( false, null ), $out );
		$this->assertFalse( $ran );
	}

	public function test_with_runs_the_callback_in_the_forced_currency_and_restores_it(): void {
		update_option( 'woocommerce_currency', 'EUR' );
		$this->fake_currency_up( 'EUR' );
		$this->fake_runner();

		$out = Currency::with( 'DKK', fn() => Currency::current() );

		$this->assertSame( array( true, 'DKK' ), $out );
		$this->assertSame( 'EUR', Currency::current() );
	}

	public function test_with_refuses_when_the_runner_forces_nothing(): void {
		update_option( 'woocommerce_currency', 'EUR' );
		$this->fake_currency_up( 'EUR' );
		$this->fake_runner( false );
		$ran = false;

		$out = Currency::with(
			'DKK',
			function () use ( &$ran ) {
				$ran = true;
				return 1;
			}
		);

		$this->assertSame( array( false, null ), $out );
		$this->assertFalse( $ran, 'A price is never read under a currency we did not get.' );
	}

	public function test_with_is_case_insensitive_on_the_code(): void {
		update_option( 'woocommerce_currency', 'EUR' );
		$this->fake_currency_up( 'EUR' );
		$this->fake_runner();

		$this->assertSame( array( true, 'DKK' ), Currency::with( 'dkk', fn() => Currency::current() ) );
	}

	public function test_of_user_falls_back_to_base_without_a_listener(): void {
		update_option( 'woocommerce_currency', 'EUR' );

		$this->assertSame( 'EUR', Currency::of_user( 5 ) );
	}

	public function test_of_user_takes_a_valid_answer_and_ignores_junk(): void {
		update_option( 'woocommerce_currency', 'EUR' );
		$this->fake_user_currencies( array( 5 => 'DKK', 6 => 'dk', 7 => 'danish kroner' ) );

		$this->assertSame( 'DKK', Currency::of_user( 5 ) );
		$this->assertSame( 'EUR', Currency::of_user( 6 ) );
		$this->assertSame( 'EUR', Currency::of_user( 7 ) );
		$this->assertSame( 'EUR', Currency::of_user( 8 ) );
	}

	public function test_of_user_runs_the_listeners_in_priority_order_and_the_first_answer_stays(): void {
		update_option( 'woocommerce_currency', 'EUR' );
		$late = static function ( $code ) {
			return '' === $code ? 'SEK' : $code;
		};
		$soon = static function ( $code ) {
			return '' === $code ? 'DKK' : $code;
		};
		add_filter( 'beltoft_services_user_currency', $late, 20 );
		add_filter( 'beltoft_services_user_currency', $soon, 5 );

		$this->assertSame( 'DKK', Currency::of_user( 1 ) );

		remove_filter( 'beltoft_services_user_currency', $late, 20 );
		remove_filter( 'beltoft_services_user_currency', $soon, 5 );
	}

	public function test_amount_in_the_base_currency_is_the_setting_with_no_filter(): void {
		update_option( 'woocommerce_currency', 'EUR' );
		$this->settings( array( 'credit_min' => 25 ) );

		$this->assertSame( Settings::get( 'credit_min' ), Currency::amount( 'credit_min' ) );
		$this->assertSame( Settings::get( 'credit_min' ), Currency::amount( 'credit_min', 'EUR' ) );
	}

	public function test_amount_in_the_base_currency_can_be_changed_by_the_filter(): void {
		update_option( 'woocommerce_currency', 'EUR' );
		$this->settings( array( 'credit_min' => 25 ) );
		$this->fake_amounts( array( 'EUR' => array( 'credit_min' => 30 ) ) );

		$this->assertSame( 30, Currency::amount( 'credit_min', 'EUR' ) );
	}

	public function test_amount_in_another_currency_is_null_unless_the_filter_answers(): void {
		update_option( 'woocommerce_currency', 'EUR' );
		$this->settings( array( 'credit_min' => 25 ) );

		$this->assertNull( Currency::amount( 'credit_min', 'DKK' ), 'The euro number is never reused.' );

		$this->fake_amounts( array( 'DKK' => array( 'credit_min' => 200 ) ) );

		$this->assertSame( 200, Currency::amount( 'credit_min', 'DKK' ) );
		$this->assertNull( Currency::amount( 'credit_max', 'DKK' ) );
	}

	public function test_amount_with_no_currency_means_the_current_one(): void {
		update_option( 'woocommerce_currency', 'EUR' );
		$this->fake_currency_up( 'DKK' );
		$this->fake_amounts( array( 'DKK' => array( 'credit_min' => 200 ) ) );

		$this->assertSame( 200, Currency::amount( 'credit_min' ) );
	}
}

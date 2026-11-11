<?php
/**
 * The admin screens show each credit amount with its currency; credit codes have a currency field on a shop with more than one.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Credit;

use BeltoftServices\Credit\Balance;
use BeltoftServices\Credit\Code;
use BeltoftServices\Credit\Grant;
use BeltoftServices\Currency;
use BeltoftServices\Settings;

require_once __DIR__ . '/CreditTestCase.php';

/**
 * W14.
 */
class CreditAdminCurrencyTest extends CreditTestCase {

	public function test_the_profile_box_names_the_currency_of_the_balance_and_of_each_row(): void {
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin );
		$user = $this->new_user();
		Balance::credit( $user, 2500, Balance::TOPUP, 'order_item', 'a1', '', 'DKK' );

		ob_start();
		Grant::render( get_userdata( $user ) );
		$html = html_entity_decode( (string) ob_get_clean() );

		$this->assertStringContainsString( '(DKK)', $html );
		$this->assertStringContainsString( 'DKK', substr( $html, (int) strpos( $html, 'Latest rows' ) ) );
	}

	public function test_the_coupon_currency_field_is_hidden_on_a_one_currency_shop_and_offers_the_shops_currencies_otherwise(): void {
		update_option( 'woocommerce_currency', 'EUR' );
		$this->settings( array( 'currency_guard_rates' => '' ) );

		ob_start();
		Code::currency_field( 0 );
		$this->assertSame( '', (string) ob_get_clean() );

		if ( ! function_exists( 'woocommerce_wp_select' ) ) {
			require_once WC_ABSPATH . 'includes/admin/wc-meta-box-functions.php';
		}

		$this->settings( array( 'currency_guard_rates' => 'DKK=7.60' ) );
		$this->assertSame( array( 'EUR', 'DKK' ), Currency::known_codes() );

		ob_start();
		Code::currency_field( 0 );
		$html = (string) ob_get_clean();
		$this->assertStringContainsString( 'value="DKK"', $html );
		$this->assertStringContainsString( 'value="EUR"', $html );

		add_filter( 'beltoft_services_currencies', static fn( $codes ) => array_merge( $codes, array( 'sek' ) ) );
		$this->assertContains( 'SEK', Currency::known_codes() );
		remove_all_filters( 'beltoft_services_currencies' );
	}

	public function test_the_coupon_currency_is_saved_only_for_a_known_currency(): void {
		update_option( 'woocommerce_currency', 'EUR' );
		$this->settings( array( 'currency_guard_rates' => 'DKK=7.60' ) );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$coupon = new \WC_Coupon();
		$coupon->set_code( 'KR' );
		$coupon->save();

		$_POST[ Code::META_CURRENCY ] = 'DKK';
		Code::save_currency( $coupon->get_id() );
		$this->assertSame( 'DKK', get_post_meta( $coupon->get_id(), Code::META_CURRENCY, true ) );

		$_POST[ Code::META_CURRENCY ] = 'NOK';
		Code::save_currency( $coupon->get_id() );
		$this->assertSame( 'DKK', get_post_meta( $coupon->get_id(), Code::META_CURRENCY, true ), 'An unknown currency is ignored.' );

		$_POST[ Code::META_CURRENCY ] = 'EUR';
		Code::save_currency( $coupon->get_id() );
		$this->assertSame( '', get_post_meta( $coupon->get_id(), Code::META_CURRENCY, true ), 'The shop currency is stored as empty.' );
		unset( $_POST[ Code::META_CURRENCY ] );
	}

	public function test_the_currencies_section_says_nothing_is_converted_and_the_amount_settings_say_how_other_currencies_get_theirs(): void {
		ob_start();
		Settings::currency_intro();
		$this->assertStringContainsString( 'Nothing here converts a price for a customer', (string) ob_get_clean() );

		foreach ( array( 'credit_min', 'credit_max', 'usage_threshold_new', 'usage_minimum' ) as $key ) {
			$this->assertStringContainsString( 'beltoft_services_amount_setting', (string) Settings::fields()[ $key ]['desc'], $key );
			$this->assertStringNotContainsString( 'store currency', (string) Settings::fields()[ $key ]['desc'], $key );
		}

		$this->assertNotEmpty( Settings::sections()['currency'] );
	}
}

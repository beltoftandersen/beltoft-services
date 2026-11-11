<?php
/**
 * The tax note and the catalogue price helper.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Billing;

use BeltoftServices\Billing\TaxNote;
use BeltoftServices\Tests\TestCase;

/**
 * TaxNote tests.
 */
final class TaxNoteTest extends TestCase {

	/** @var array<int, array{0: string, 1: callable, 2: int}> Filters a test added. */
	private array $added = array();

	public function tear_down(): void {
		foreach ( $this->added as $f ) {
			remove_filter( $f[0], $f[1], $f[2] );
		}

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Test fixture reset.
		$wpdb->query( "DELETE FROM {$wpdb->prefix}woocommerce_tax_rates" );
		parent::tear_down();
	}

	private function listen( callable $fn, int $args = 5 ): void {
		add_filter( 'beltoft_services_price_html', $fn, 10, $args );
		$this->added[] = array( 'beltoft_services_price_html', $fn, 10 );
	}

	private function taxes( string $on, string $shop = 'excl', string $cart = 'excl' ): void {
		update_option( 'woocommerce_calc_taxes', $on );
		update_option( 'woocommerce_tax_display_shop', $shop );
		update_option( 'woocommerce_tax_display_cart', $cart );
	}

	private function rate_25(): void {
		\WC_Tax::_insert_tax_rate(
			array(
				'tax_rate_country'  => '',
				'tax_rate_state'    => '',
				'tax_rate'          => '25.0000',
				'tax_rate_name'     => 'VAT',
				'tax_rate_priority' => 1,
				'tax_rate_compound' => 0,
				'tax_rate_shipping' => 1,
				'tax_rate_order'    => 1,
				'tax_rate_class'    => '',
			)
		);
	}

	private function product( string $price ): \WC_Product_Simple {
		$product = new \WC_Product_Simple();
		$product->set_regular_price( $price );
		$product->set_tax_status( 'taxable' );
		$product->save();

		return $product;
	}

	public function test_mode_is_empty_when_tax_is_off(): void {
		$this->taxes( 'no', 'incl', 'incl' );

		$this->assertSame( '', TaxNote::mode() );
		$this->assertSame( '', TaxNote::note() );
	}

	public function test_mode_follows_the_shop_and_cart_options(): void {
		$this->taxes( 'yes', 'incl', 'excl' );

		$this->assertSame( 'incl', TaxNote::mode() );
		$this->assertSame( 'incl', TaxNote::mode( 'shop' ) );
		$this->assertSame( 'excl', TaxNote::mode( 'cart' ) );
	}

	public function test_note_has_the_wording(): void {
		$this->taxes( 'yes', 'excl', 'incl' );

		$this->assertSame( 'excl. VAT', TaxNote::note() );
		$this->assertSame( 'incl. VAT', TaxNote::note( 'cart' ) );
	}

	public function test_price_honours_the_display_option(): void {
		$this->rate_25();
		$product = $this->product( '10' );

		$this->taxes( 'yes', 'excl' );
		$this->assertSame( 10.0, TaxNote::price( $product ) );
		$this->assertSame( 8.0, TaxNote::price( $product, 8.0 ) );

		$this->taxes( 'yes', 'incl' );
		$this->assertSame( 12.5, TaxNote::price( $product ) );
		$this->assertSame( 10.0, TaxNote::price( $product, 8.0 ) );
	}

	public function test_html_without_a_listener_is_exactly_wc_price(): void {
		$this->taxes( 'yes', 'incl' );

		$this->assertSame( wc_price( 12.5 ), TaxNote::html( 12.5, 'test' ) );
		$this->assertSame( wc_price( 0.0226, array( 'decimals' => 4 ) ), TaxNote::html( 0.0226, 'test', array( 'decimals' => 4 ) ) );
		$this->assertSame( wc_price( 5, array( 'currency' => 'DKK' ) ), TaxNote::html( 5.0, 'test', array( 'currency' => 'DKK' ) ) );
	}

	public function test_html_with_a_callable_returns_its_output_untouched_without_a_listener(): void {
		$this->assertSame( '<b>x</b>', TaxNote::html( fn( string $display = '' ) => '<b>x</b>', 'test' ) );
	}

	public function test_the_filter_gets_html_amount_currency_context_and_a_renderer(): void {
		update_option( 'woocommerce_currency', 'EUR' );
		$seen = array();

		$this->listen(
			function ( $html, $amount, $currency, $context, $render ) use ( &$seen ) {
				$seen = array( $html, $amount, $currency, $context, is_callable( $render ) );
				return $html;
			}
		);

		TaxNote::html( 12.5, 'server-picker' );

		$this->assertSame( array( wc_price( 12.5 ), 12.5, 'EUR', 'server-picker', true ), $seen );
	}

	public function test_the_currency_is_the_one_in_the_arguments_when_given(): void {
		$seen = '';

		$this->listen(
			function ( $html, $amount, $currency ) use ( &$seen ) {
				$seen = $currency;
				return $html;
			},
			3
		);

		TaxNote::html( 5.0, 'test', array( 'currency' => 'DKK' ) );

		$this->assertSame( 'DKK', $seen );
	}

	public function test_a_listener_can_render_the_other_display_through_the_renderer(): void {
		$this->taxes( 'yes', 'excl' );
		$this->rate_25();
		$product = $this->product( '10' );

		$this->listen(
			function ( $html, $amount, $currency, $context, $render ) {
				return $render( 'excl' ) . ' | ' . $render( 'incl' );
			}
		);

		$out = TaxNote::html( 10.0, 'test', array( 'product' => $product ) );

		$this->assertSame( wc_price( 10 ) . ' | ' . wc_price( 12.5 ), $out );
	}

	public function test_the_renderer_with_a_tax_class_adds_that_class_rate(): void {
		$this->taxes( 'yes', 'excl' );
		$this->rate_25();

		$this->listen(
			function ( $html, $amount, $currency, $context, $render ) {
				return $render( 'incl' );
			}
		);

		$this->assertSame( wc_price( 12.5 ), TaxNote::html( 10.0, 'test', array( 'tax_class' => '' ) ) );
	}

	public function test_the_renderer_without_tax_context_leaves_the_amount_alone(): void {
		$this->taxes( 'no' );

		$this->listen(
			function ( $html, $amount, $currency, $context, $render ) {
				return $render( 'incl' );
			}
		);

		$this->assertSame( wc_price( 10 ), TaxNote::html( 10.0, 'test' ) );
	}

	public function test_a_callable_price_passes_a_null_amount_and_its_display_argument(): void {
		$seen = array( 'amount' => 'unset' );

		$this->listen(
			function ( $html, $amount, $currency, $context, $render ) use ( &$seen ) {
				$seen = array( 'amount' => $amount, 'html' => $render( 'incl' ) );
				return $html;
			}
		);

		TaxNote::html( fn( string $display = '' ) => 'shown:' . $display, 'test' );

		$this->assertNull( $seen['amount'] );
		$this->assertSame( 'shown:incl', $seen['html'] );
	}
}

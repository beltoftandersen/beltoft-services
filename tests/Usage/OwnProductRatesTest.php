<?php
/**
 * Pay as you go as the product's own kind: the flag on the parent, the monthly figure on the variation, and a live monthly
 * subscription that keeps living under a flagged product.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Usage;

use BeltoftServices\Currency;
use BeltoftServices\Drivers\Registry;
use BeltoftServices\Product;
use BeltoftServices\Resource;
use BeltoftServices\Tests\Support\FakeCurrency;
use BeltoftServices\Usage\Ledger;
use BeltoftServices\Usage\Meter;
use BeltoftServices\Usage\Rates;

require_once __DIR__ . '/UsageTestCase.php';

/**
 * Reading side of the pay as you go product (plan 2026-11-20-payg-own-product.md, part A).
 */
class OwnProductRatesTest extends UsageTestCase {

	use FakeCurrency;

	public function tear_down(): void {
		$this->fake_currency_down();
		parent::tear_down();
	}

	/**
	 * A subscription-type product flagged pay as you go on the parent: a pay as you go variation (price 0, own monthly figure,
	 * no flag of its own) and a monthly subscription variation marked monthly.
	 *
	 * @return array{parent: \WC_Product_Variable, payg: \WC_Product_Variation, monthly: \WC_Product_Variation}
	 */
	private function flagged_product(): array {
		$product = new \WC_Product_Variable_Subscription();
		$product->set_name( 'VPS ' . wp_generate_password( 5, false ) );
		$product->set_status( 'publish' );
		$plan = new \WC_Product_Attribute();
		$plan->set_name( 'Plan' );
		$plan->set_options( array( 'Pay', 'Old' ) );
		$plan->set_visible( true );
		$plan->set_variation( true );
		$product->set_attributes( array( $plan ) );
		$product->update_meta_data( Product::META_DRIVER, 'proxmox' );
		$product->update_meta_data( Rates::META_BILLING, 'usage' );
		$product->update_meta_data( \BeltoftLicenses\Software::META_LICENSED, 'yes' );
		$product->update_meta_data( \BeltoftLicenses\Software::META_SLUG, 'vps-' . wp_generate_password( 5, false ) );

		foreach ( array( 'cluster' => 'lab', 'template' => 9000, 'cores' => 2, 'memory_mb' => 4096, 'disk_gb' => 30 ) as $key => $value ) {
			$product->update_meta_data( Product::META_PLAN . $key, (string) $value );
		}

		$product->save();
		Product::remember_slug( (string) $product->get_meta( \BeltoftLicenses\Software::META_SLUG ) );
		$out = array( 'parent' => $product );

		foreach ( array( 'payg' => array( 'Pay', '0' ), 'monthly' => array( 'Old', '15' ) ) as $key => $def ) {
			$v = new \WC_Product_Subscription_Variation();
			$v->set_parent_id( $product->get_id() );
			$v->set_attributes( array( 'plan' => $def[0] ) );
			$v->set_regular_price( $def[1] );
			$v->update_meta_data( '_subscription_price', $def[1] );
			$v->update_meta_data( '_subscription_period', 'month' );
			$v->update_meta_data( '_subscription_period_interval', '1' );
			$v->set_status( 'publish' );

			if ( 'payg' === $key ) {
				$v->update_meta_data( Rates::META_MONTHLY, '15' );
			} else {
				$v->update_meta_data( Rates::META_BILLING, Rates::KIND_MONTHLY );
			}

			$v->save();
			$out[ $key ] = wc_get_product( $v->get_id() );
		}

		\WC_Product_Variable::sync( $product->get_id() );

		return $out;
	}

	public function test_the_parent_flag_makes_the_variations_pay_as_you_go_except_a_monthly_one(): void {
		$f = $this->flagged_product();

		$this->assertTrue( Rates::is_usage( $f['payg'] ) );
		$this->assertTrue( Rates::is_usage( $f['payg']->get_id() ) );
		$this->assertTrue( Rates::is_usage( $f['parent'] ) );
		$this->assertFalse( Rates::is_counted( $f['payg'] ) );
		$this->assertFalse( Rates::is_usage( $f['monthly'] ), 'A variation marked monthly is never pay as you go.' );
		$this->assertFalse( Rates::is_usage( $f['monthly']->get_id() ) );
		$this->assertSame( 15.0, Rates::monthly( $f['payg'] ) );
		$this->assertNull( Rates::monthly( $f['monthly'] ), 'No pay as you go figure for the monthly variation.' );
		$this->assertNull( Rates::for_usage( $f['monthly'] ) );
	}

	public function test_counted_is_read_from_the_parent_too(): void {
		$f = $this->flagged_product();
		update_post_meta( $f['parent']->get_id(), Rates::META_BILLING, Rates::KIND_COUNTED );

		$this->assertTrue( Rates::is_counted( $f['payg'] ) );
		$this->assertTrue( Rates::is_usage( $f['payg'] ) );
		$this->assertFalse( Rates::is_counted( $f['monthly'] ) );
	}

	public function test_a_product_without_the_flag_is_monthly_and_a_missing_figure_is_no_price(): void {
		$f = $this->flagged_product();
		delete_post_meta( $f['parent']->get_id(), Rates::META_BILLING );

		$this->assertFalse( Rates::is_usage( wc_get_product( $f['payg']->get_id() ) ) );
		$this->assertNull( Rates::monthly( wc_get_product( $f['payg']->get_id() ) ) );

		update_post_meta( $f['parent']->get_id(), Rates::META_BILLING, 'usage' );
		update_post_meta( $f['payg']->get_id(), Rates::META_MONTHLY, '' );
		$this->assertNull( Rates::monthly( wc_get_product( $f['payg']->get_id() ) ) );
	}

	public function test_a_live_monthly_subscription_under_a_flagged_product_keeps_renewing_and_is_never_metered(): void {
		$f    = $this->flagged_product();
		$user = $this->new_user();

		// Still a subscription line for WooCommerce Subscriptions and the licences; the pay as you go variation is not.
		$this->assertTrue( \WC_Subscriptions_Product::is_subscription( $f['monthly'] ) );
		$this->assertTrue( \BeltoftLicenses\Product::is_subscription( $f['monthly'] ) );
		$this->assertFalse( \BeltoftLicenses\Product::is_subscription( $f['payg'] ) );

		$sub = wcs_create_subscription( array( 'customer_id' => $user, 'billing_period' => 'month', 'billing_interval' => 1, 'status' => 'active' ) );
		$this->assertInstanceOf( \WC_Subscription::class, $sub );
		$sub->set_requires_manual_renewal( true );
		$sub->add_product( $f['monthly'], 1, array( 'subtotal' => 15, 'total' => 15 ) );
		$sub->calculate_totals();
		$sub->save();

		$renewal = wcs_create_renewal_order( wcs_get_subscription( $sub->get_id() ) );
		$this->assertInstanceOf( \WC_Order::class, $renewal );
		$this->assertSame( 15.0, (float) $renewal->get_total(), 'The renewal keeps its price.' );

		// Its service: a licence from an order on the monthly variation, never metered.
		$parent  = $f['parent'];
		$license = $this->service_license(
			$parent,
			array(
				'product_id'      => $parent->get_id(),
				'product_slug'    => (string) $parent->get_meta( \BeltoftLicenses\Software::META_SLUG ),
				'variation_id'    => $f['monthly']->get_id(),
				'user_id'         => $user,
				'email'           => self::MAIL,
				'source'          => 'order',
				'subscription_id' => $sub->get_id(),
				'fire'            => false,
			)
		);
		$r = Resource::create( $license, Registry::get( 'proxmox' ), Product::plan( $parent->get_id(), $f['monthly']->get_id() ), array() );
		$this->assertInstanceOf( Resource::class, $r );
		\BeltoftServices\Jobs::run( $r->id() );
		$this->activated_ago( $r->id(), 10 );

		$this->assertSame( array(), Rates::items( Resource::find( $r->id() ) ), 'No hourly items for a monthly server.' );
		Meter::run( time() );

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- test read.
		$this->assertSame( 0, (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . Ledger::table() . ' WHERE resource_id = %d', $r->id() ) ), 'Never metered.' );
	}

	/**
	 * The figure the old twin model gave: the twin's price excluding tax in the current currency.
	 */
	private function old_figure( ?\WC_Product_Variation $twin ): ?float {
		return $twin && '' !== (string) $twin->get_price() ? (float) wc_get_price_excluding_tax( $twin ) : null;
	}

	public function test_no_bill_changes_the_own_figure_equals_the_old_twin_price(): void {
		$this->vat( true );
		update_option( 'woocommerce_tax_display_shop', 'incl' );
		$v = $this->usage_product() + $this->monthly_vps();
		$v['m-None']->set_sale_price( '12' );
		$v['m-None']->save();
		\WC_Product_Variable::sync( $v['m-None']->get_parent_id() );

		foreach ( array( 'u-None', 'u-Daily' ) as $key ) {
			$before = $this->old_figure( wc_get_product( $v[ 'u-None' === $key ? 'm-None' : 'm-Daily' ]->get_id() ) );
			// What the migration copies.
			update_post_meta( $v[ $key ]->get_id(), Rates::META_MONTHLY, (string) $before );
			$u = wc_get_product( $v[ $key ]->get_id() );

			$this->assertSame( $before, Rates::monthly( $u ) );
			$this->assertSame(
				array( 'hourly' => Rates::hourly( $before, 10.0 ), 'cap' => Rates::cap( $before, 10.0 ) ),
				Rates::for_usage( $u )
			);
		}

		$this->assertSame( 12.0, Rates::monthly( wc_get_product( $v['u-None']->get_id() ) ), 'The sale price counts, excluding tax.' );
		update_option( 'woocommerce_tax_display_shop', 'excl' );
		$this->vat( false );
	}

	public function test_no_bill_changes_in_a_second_currency(): void {
		update_option( 'woocommerce_currency', 'EUR' );
		$this->fake_currency_up( 'EUR' );
		$this->fake_runner();
		$v = $this->usage_product() + $this->monthly_vps();
		$this->fake_price( $v['m-None']->get_id(), 'DKK', '112.5' ); // The old twin's price in DKK.

		$u = wc_get_product( $v['u-None']->get_id() );
		list( $ok, $before ) = Currency::with( 'DKK', fn() => $this->old_figure( wc_get_product( $v['m-None']->get_id() ) ) );
		$this->assertTrue( $ok );
		$this->assertSame( 112.5, $before );

		update_post_meta( $u->get_id(), Rates::META_MONTHLY . '_DKK', (string) $before );
		$u = wc_get_product( $u->get_id() );

		list( , $after ) = Currency::with( 'DKK', static fn() => Rates::monthly( $u ) );
		$this->assertSame( $before, $after );
		$this->assertSame( 15.0, Rates::monthly( $u ), 'The shop currency keeps its own figure.' );

		list( , $none ) = Currency::with( 'SEK', static fn() => Rates::monthly( $u ) );
		$this->assertNull( $none, 'A currency without a figure has no price, never a relabelled one.' );
	}
}

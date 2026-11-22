<?php
/**
 * The split command: twin products become pay as you go products without a changed figure, and it can be undone exactly.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Usage;

use BeltoftServices\Usage\PaygSplit;
use BeltoftServices\Currency;
use BeltoftServices\Product;
use BeltoftServices\Resource;
use BeltoftServices\Tests\Support\FakeCurrency;
use BeltoftServices\Usage\Rates;

require_once __DIR__ . '/UsageTestCase.php';

/**
 * Plan 2026-11-20-payg-own-product.md, part D.
 */
class PaygSplitTest extends UsageTestCase {

	use FakeCurrency;

	public function set_up(): void {
		parent::set_up();
		delete_option( PaygSplit::OPTION );
	}

	public function tear_down(): void {
		delete_option( PaygSplit::OPTION );
		$this->fake_currency_down();
		parent::tear_down();
	}

	/**
	 * The OLD shape the split converts: a VPS with a Billing attribute (Monthly, Pay as you go) and a Backups attribute (None, Daily).
	 * Monthly: 15 without and 18 with backups. Pay as you go: price 0, meta billing = usage.
	 *
	 * @param string $premium Premium percent on the product ('' for the default).
	 * @return array<string, \WC_Product_Variation> Keys: m-None, m-Daily, u-None, u-Daily.
	 */
	private function old_twin_product( string $premium = '' ): array {
		$product = new \WC_Product_Variable();
		$product->set_name( 'VPS ' . wp_generate_password( 5, false ) );
		$product->set_status( 'publish' );

		$billing = new \WC_Product_Attribute();
		$billing->set_name( 'Billing' );
		$billing->set_options( array( 'Monthly', 'Pay as you go' ) );
		$billing->set_visible( true );
		$billing->set_variation( true );

		$backups = new \WC_Product_Attribute();
		$backups->set_name( 'Backups' );
		$backups->set_options( array( 'None', 'Daily' ) );
		$backups->set_visible( true );
		$backups->set_variation( true );

		$product->set_attributes( array( $billing, $backups ) );
		$product->update_meta_data( Product::META_DRIVER, 'proxmox' );
		$product->update_meta_data( \BeltoftLicenses\Software::META_LICENSED, 'yes' );
		$product->update_meta_data( \BeltoftLicenses\Software::META_SLUG, 'vps-' . wp_generate_password( 5, false ) );

		if ( '' !== $premium ) {
			$product->update_meta_data( Rates::META_PREMIUM, $premium );
		}

		foreach ( array( 'cluster' => 'lab', 'template' => 9000, 'cores' => 2, 'memory_mb' => 4096, 'disk_gb' => 30 ) as $key => $value ) {
			$product->update_meta_data( Product::META_PLAN . $key, (string) $value );
		}

		$product->save();
		Product::remember_slug( (string) $product->get_meta( \BeltoftLicenses\Software::META_SLUG ) );

		$out = array();

		foreach ( array( 'm' => 'Monthly', 'u' => 'Pay as you go' ) as $short => $billing_value ) {
			foreach ( array( 'None' => '15', 'Daily' => '18' ) as $b => $price ) {
				$v = new \WC_Product_Variation();
				$v->set_parent_id( $product->get_id() );
				$v->set_attributes( array( 'billing' => $billing_value, 'backups' => $b ) );
				$v->set_regular_price( 'm' === $short ? $price : '0' );
				$v->set_status( 'publish' );
				$v->update_meta_data( Product::META_PLAN . 'backup_days', 'Daily' === $b ? '7' : '0' );

				if ( 'u' === $short ) {
					$v->update_meta_data( Rates::META_BILLING, 'usage' );
				}

				$v->save();
				$out[ $short . '-' . $b ] = $v;
			}
		}

		\WC_Product_Variable::sync( $product->get_id() );

		return $out;
	}

	/**
	 * A twin-shaped product: hourly with a Backups attribute, a sale price on one twin, DKK prices, a counted variation and a live
	 * monthly subscription on a monthly variation, plus a live pay as you go server.
	 *
	 * @return array<string, mixed>
	 */
	private function twin_shop(): array {
		update_option( 'woocommerce_currency', 'EUR' );
		$this->fake_currency_up( 'EUR' );
		$this->fake_runner();
		add_filter( 'beltoft_services_currencies', static fn( $codes ) => array_merge( (array) $codes, array( 'DKK' ) ) );

		$v = $this->old_twin_product();
		$v['m-None']->set_sale_price( '12' );
		$v['m-None']->save();
		$this->fake_price( $v['m-None']->get_id(), 'DKK', '112.5' );
		$this->fake_price( $v['m-Daily']->get_id(), 'DKK', '135' );
		// The Daily twin has a DKK price only; u-None keeps one in the shop currency typed by hand (the twin wins in the old code).
		update_post_meta( $v['u-None']->get_id(), Rates::META_MONTHLY, '99' );

		$counted = new \WC_Product_Variation();
		$counted->set_parent_id( $v['u-None']->get_parent_id() );
		$counted->set_attributes( array( 'billing' => 'Pay as you go', 'backups' => 'Daily' ) );
		$counted->set_regular_price( '0' );
		$counted->set_status( 'publish' );
		$counted->update_meta_data( Rates::META_BILLING, Rates::KIND_COUNTED );
		$counted->save();
		$v['counted'] = $counted;

		\WC_Product_Variable::sync( $counted->get_parent_id() );

		$user = $this->new_user();
		$v['server']  = $this->usage_vm( wc_get_product( $v['u-None']->get_id() ), $user, 5, $this->usage_account( $user )->get_id() );
		$v['server2'] = $this->usage_vm( wc_get_product( $v['u-Daily']->get_id() ), $user, 7, $this->usage_account( $user )->get_id() );

		$sub = wcs_create_subscription( array( 'customer_id' => $user, 'billing_period' => 'month', 'billing_interval' => 1, 'status' => 'active' ) );
		$sub->set_requires_manual_renewal( true );
		$sub->add_product( $v['m-None'], 1, array( 'subtotal' => 12, 'total' => 12 ) );
		$sub->calculate_totals();
		$sub->save();
		$parent  = wc_get_product( $v['m-None']->get_parent_id() );
		$license = $this->service_license(
			$parent,
			array(
				'product_id'      => $parent->get_id(),
				'product_slug'    => (string) $parent->get_meta( \BeltoftLicenses\Software::META_SLUG ),
				'variation_id'    => $v['m-None']->get_id(),
				'user_id'         => $user,
				'email'           => self::MAIL,
				'source'          => 'order',
				'subscription_id' => $sub->get_id(),
				'fire'            => false,
			)
		);
		$v['sub']     = $sub->get_id();
		$v['license'] = $license;
		$v['parent']  = $parent->get_id();

		return $v;
	}

	/**
	 * Every meta value and status of the posts of a product (the whole state a run may change).
	 *
	 * @param array<string, mixed> $v Fixture.
	 * @return array<int, mixed>
	 */
	private function state( array $v ): array {
		$ids   = array( $v['parent'] );
		$out   = array();

		foreach ( array( 'm-None', 'm-Daily', 'u-None', 'u-Daily', 'counted' ) as $k ) {
			$ids[] = $v[ $k ]->get_id();
		}

		foreach ( $ids as $id ) {
			clean_post_cache( $id );
			$meta = get_post_meta( $id );
			unset( $meta['_price'], $meta['_min_variation_price'], $meta['_max_variation_price'], $meta['_min_variation_regular_price'], $meta['_max_variation_regular_price'], $meta['_min_variation_sale_price'], $meta['_max_variation_sale_price'] );
			ksort( $meta );
			$out[ $id ] = array( get_post_status( $id ), $meta );
		}

		return $out;
	}

	public function test_dry_run_writes_nothing(): void {
		$v      = $this->twin_shop();
		$before = $this->state( $v );

		$r = PaygSplit::run( false );

		$this->assertTrue( $r['ok'] );
		$this->assertSame( $before, $this->state( $v ) );
		$this->assertFalse( get_option( PaygSplit::OPTION ) );
		$this->assertNotEmpty( array_filter( $r['lines'], static fn( $l ) => str_contains( $l, 'Dry run' ) ) );
	}

	public function test_apply_keeps_every_figure_and_takes_the_monthly_variations_off_sale(): void {
		$v = $this->twin_shop();

		$r = PaygSplit::run( true );
		$this->assertTrue( $r['ok'], implode( "\n", $r['lines'] ) );

		$this->assertSame( 'usage', get_post_meta( $v['parent'], Rates::META_BILLING, true ) );

		// The twin's price wins over a hand typed figure: 12 (the sale price), not 99.
		$this->assertSame( 12.0, Rates::monthly( wc_get_product( $v['u-None']->get_id() ) ) );
		$this->assertSame( 18.0, Rates::monthly( wc_get_product( $v['u-Daily']->get_id() ) ) );
		list( , $dkk ) = Currency::with( 'DKK', static fn() => Rates::monthly( wc_get_product( $v['u-None']->get_id() ) ) );
		$this->assertSame( 112.5, $dkk );
		list( , $dkk2 ) = Currency::with( 'DKK', static fn() => Rates::monthly( wc_get_product( $v['u-Daily']->get_id() ) ) );
		$this->assertSame( 135.0, $dkk2 );

		// What the old code billed (the twin's price x 1.10 / 730; the backups part is the difference to the plan without backups).
		$expect = array(
			'EUR' => array(
				'server'  => array( array( 'item' => 'server', 'quantity' => 1, 'unit' => 'hour', 'rate' => 0.01808219 ) ),
				'server2' => array(
					array( 'item' => 'server', 'quantity' => 1, 'unit' => 'hour', 'rate' => 0.01808219 ),
					array( 'item' => 'backups', 'quantity' => 1, 'unit' => 'hour', 'rate' => 0.0090411 ),
				),
			),
			'DKK' => array(
				'server'  => array( array( 'item' => 'server', 'quantity' => 1, 'unit' => 'hour', 'rate' => 0.16952055 ) ),
				'server2' => array( array( 'item' => 'server', 'quantity' => 1, 'unit' => 'hour', 'rate' => 0.20342466 ) ),
			),
		);

		foreach ( $expect as $code => $per ) {
			foreach ( $per as $k => $items ) {
				list( , $now ) = Currency::with( $code, static fn() => Rates::items( Resource::find( $v[ $k ]->id() ) ) );
				$this->assertEquals( $items, $now, "$k in $code" );
			}
		}

		$this->assertTrue( Rates::is_counted( $v['counted']->get_id() ) );

		foreach ( array( 'm-None', 'm-Daily' ) as $k ) {
			$id = $v[ $k ]->get_id();
			clean_post_cache( $id );
			$this->assertSame( 'private', get_post_status( $id ) );
			$this->assertSame( 'monthly', get_post_meta( $id, Rates::META_BILLING, true ) );
			$this->assertFalse( Rates::is_usage( $id ) );
		}

		foreach ( array( 'u-None', 'u-Daily', 'counted' ) as $k ) {
			$this->assertSame( 'publish', get_post_status( $v[ $k ]->get_id() ) );
		}
	}

	public function test_a_kept_product_keeps_its_monthly_plans_on_sale_and_every_figure(): void {
		$v = $this->twin_shop();

		$r = PaygSplit::run( true, false, array( $v['parent'] ) );
		$this->assertTrue( $r['ok'], implode( "\n", $r['lines'] ) );

		$this->assertSame( '', get_post_meta( $v['parent'], Rates::META_BILLING, true ) );
		$this->assertSame( 12.0, Rates::monthly( wc_get_product( $v['u-None']->get_id() ) ) );

		foreach ( array( 'm-None', 'm-Daily' ) as $k ) {
			$id = $v[ $k ]->get_id();
			clean_post_cache( $id );
			$this->assertSame( 'publish', get_post_status( $id ) );
			$this->assertFalse( Rates::is_usage( $id ) );
		}

		foreach ( array( 'u-None', 'u-Daily' ) as $k ) {
			$this->assertTrue( Rates::is_usage( $v[ $k ]->get_id() ) );
		}

		$this->assertTrue( Rates::is_counted( $v['counted']->get_id() ) );
	}

	public function test_a_trashed_monthly_variation_gets_the_marker_and_stays_monthly(): void {
		$v  = $this->twin_shop();
		$id = $v['m-Daily']->get_id();
		wp_trash_post( $id );

		$this->assertTrue( PaygSplit::run( true )['ok'] );

		$this->assertSame( 'monthly', get_post_meta( $id, Rates::META_BILLING, true ) );
		$this->assertFalse( Rates::is_usage( $id ) );
		$this->assertTrue( PaygSplit::run( false, true )['ok'] );
		$this->assertSame( '', get_post_meta( $id, Rates::META_BILLING, true ) );
	}

	public function test_a_live_monthly_subscription_is_left_alone(): void {
		$v = $this->twin_shop();

		$this->assertTrue( PaygSplit::run( true )['ok'] );

		$sub = wcs_get_subscription( $v['sub'] );
		$this->assertSame( 'active', $sub->get_status() );
		$items = array_values( $sub->get_items() );
		$this->assertSame( $v['m-None']->get_id(), (int) $items[0]->get_variation_id() );
		$lic = \BeltoftLicenses\Licenses::find( (int) $v['license']['id'] );
		$this->assertSame( $v['m-None']->get_id(), (int) $lic['variation_id'] );
		$this->assertSame( 'private', get_post_status( $v['m-None']->get_id() ) );
	}

	public function test_applying_twice_changes_nothing(): void {
		$v = $this->twin_shop();

		$this->assertTrue( PaygSplit::run( true )['ok'] );
		$state    = $this->state( $v );
		$manifest = get_option( PaygSplit::OPTION );

		$second = PaygSplit::run( true );

		$this->assertTrue( $second['ok'], implode( "\n", $second['lines'] ) );
		$this->assertSame( $state, $this->state( $v ) );
		$this->assertSame( $manifest, get_option( PaygSplit::OPTION ) );
		$this->assertStringContainsString( 'Already applied', $second['lines'][0] );
	}

	public function test_rollback_restores_every_meta_and_status_exactly(): void {
		$v      = $this->twin_shop();
		$before = $this->state( $v );

		$this->assertTrue( PaygSplit::run( true )['ok'] );
		$this->assertNotSame( $before, $this->state( $v ) );

		$r = PaygSplit::run( false, true );

		$this->assertTrue( $r['ok'] );
		$this->assertSame( $before, $this->state( $v ) );
		$this->assertFalse( get_option( PaygSplit::OPTION ) );
		$this->assertSame( 99.0, (float) get_post_meta( $v['u-None']->get_id(), Rates::META_MONTHLY, true ) );

		$again = PaygSplit::run( false, true );
		$this->assertFalse( $again['ok'], 'Nothing left to roll back.' );
	}

	public function test_a_figure_that_differs_undoes_the_run(): void {
		$v      = $this->twin_shop();
		$before = $this->state( $v );

		// Something changes a figure once the parent is flagged (step B): the check must refuse and undo.
		$id = $v['u-None']->get_id();
		add_action(
			'added_post_meta',
			static function ( $meta_id, $post_id, $key, $value ) use ( $id ): void {
				if ( Rates::META_BILLING === $key && 'usage' === $value ) {
					update_post_meta( $id, Rates::META_MONTHLY, '50' );
				}
			},
			10,
			4
		);

		$r = PaygSplit::run( true );

		$this->assertFalse( $r['ok'] );
		$this->assertFalse( get_option( PaygSplit::OPTION ) );
		$this->assertSame( $before, $this->state( $v ) );
	}
}

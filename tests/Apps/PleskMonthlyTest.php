<?php
/** A paid app licence on a pay-as-you-go server is a whole month in advance, each month: never the hourly line. FakeClient only. @package BeltoftServices */

namespace BeltoftServices\Tests\Apps;

use BeltoftLicenses\Licenses;
use BeltoftServices\Apps\AppField;
use BeltoftServices\Apps\AppLicence;
use BeltoftServices\Apps\AppLicenceCart;
use BeltoftServices\Product;
use BeltoftServices\Resource;
use BeltoftServices\Usage\Account;
use BeltoftServices\Usage\Ledger;
use BeltoftServices\Usage\Meter;
use BeltoftServices\Usage\Rates;

require_once __DIR__ . '/PaidAppCase.php';

/**
 * Hand figures: the tier Web Admin costs 14.29 a month excluding VAT, VAT is 25 percent, so a month is 14.29 + 3.57 = 17.86.
 * The pay-as-you-go server itself costs nothing at checkout; it is billed by the hour from the usage ledger.
 */
final class PleskMonthlyTest extends PaidAppCase {

	/** @var array<string, \WC_Product_Variation> */
	private array $tiers = array();

	/** @var array<string, \WC_Product_Variation> */
	private array $usage = array();

	public function set_up(): void {
		parent::set_up();
		// wcs_create_renewal_order() commits the test transaction: a customer and a server record it left would block this run.
		global $wpdb;
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->users} WHERE user_email = %s", 'christian.beltoft@gmail.com' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- test fixture reset.
		$wpdb->query( 'DELETE FROM ' . \BeltoftServices\Proxmox\Vm\Schema::tables()['vms'] ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- test fixture reset.
		update_option( 'woocommerce_subscriptions_zero_initial_payment_requires_payment', 'no' );
		update_option( 'woocommerce_calc_taxes', 'yes' );
		update_option( 'woocommerce_prices_include_tax', 'no' );
		global $wpdb;
		$wpdb->query( "DELETE FROM {$wpdb->prefix}woocommerce_tax_rates" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- test fixture reset.
		\WC_Tax::_insert_tax_rate( array( 'tax_rate_country' => '', 'tax_rate_state' => '', 'tax_rate' => '25.0000', 'tax_rate_name' => 'VAT', 'tax_rate_priority' => 1, 'tax_rate_compound' => 0, 'tax_rate_shipping' => 0, 'tax_rate_order' => 1, 'tax_rate_class' => '' ) );
		\WC_Cache_Helper::invalidate_cache_group( 'taxes' );
		$wpdb->query( 'DELETE FROM ' . Ledger::table() ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- test fixture reset.
		\BeltoftServices\Apps\AppReinstallPaid::register();
		$this->tiers = $this->tier_product( array( 'webadmin' => '14.29', 'webpro' => '21.43' ) );
		$this->usage = $this->usage_siblings( $this->tiers );
	}

	public function tear_down(): void {
		WC()->cart->empty_cart();
		global $wpdb;
		$wpdb->query( "DELETE FROM {$wpdb->prefix}woocommerce_tax_rates" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- test fixture reset.
		update_option( 'woocommerce_calc_taxes', 'no' );
		parent::tear_down();
		// The renewal test commits (wcs_create_renewal_order): what it left is removed AFTER the rollback, or the rollback brings
		// it back and the next test file finds VMID 5000 taken.
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->users} WHERE user_email = %s", 'christian.beltoft@gmail.com' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- the renewal test commits.
		$wpdb->query( 'DELETE FROM ' . \BeltoftServices\Proxmox\Vm\Schema::tables()['vms'] ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the renewal test commits.
	}

	/**
	 * A server product with a monthly and a pay-as-you-go variation of one plan (price 10 and 0).
	 *
	 * @return array{m: \WC_Product_Variation, u: \WC_Product_Variation}
	 */
	private function server_variations(): array {
		$product = new \WC_Product_Variable();
		$product->set_name( 'VPS ' . wp_generate_password( 5, false ) );
		$product->set_status( 'publish' );
		$billing = new \WC_Product_Attribute();
		$billing->set_name( 'Billing' );
		$billing->set_options( array( 'Monthly', 'Pay as you go' ) );
		$billing->set_visible( true );
		$billing->set_variation( true );
		$product->set_attributes( array( $billing ) );
		$product->update_meta_data( Product::META_DRIVER, 'proxmox' );
		$product->update_meta_data( \BeltoftLicenses\Software::META_LICENSED, 'yes' );
		$product->update_meta_data( \BeltoftLicenses\Software::META_SLUG, 'vps-' . wp_generate_password( 5, false ) );

		foreach ( array( 'cluster' => 'lab', 'template' => 9000, 'cores' => 4, 'memory_mb' => 8192, 'disk_gb' => 100 ) as $key => $value ) {
			$product->update_meta_data( Product::META_PLAN . $key, (string) $value );
		}

		$product->save();
		Product::remember_slug( (string) $product->get_meta( \BeltoftLicenses\Software::META_SLUG ) );
		$out = array();

		foreach ( array( 'm' => array( 'Monthly', '10' ), 'u' => array( 'Pay as you go', '0' ) ) as $short => $def ) {
			$v = new \WC_Product_Variation();
			$v->set_parent_id( $product->get_id() );
			$v->set_attributes( array( 'billing' => $def[0] ) );
			$v->set_regular_price( $def[1] );
			$v->set_status( 'publish' );

			if ( 'u' === $short ) {
				$v->update_meta_data( Rates::META_BILLING, 'usage' );
				$v->update_meta_data( Rates::META_MONTHLY, '10' );
			}

			$v->save();
			$out[ $short ] = wc_get_product( $v->get_id() );
		}

		\WC_Product_Variable::sync( $product->get_id() );

		return $out;
	}

	private function put_server_in_cart( \WC_Product_Variation $variation ): void {
		wp_set_current_user( self::factory()->user->create() );
		$this->assertNotFalse( WC()->cart->add_to_cart( $variation->get_parent_id(), 1, $variation->get_id(), \BeltoftServices\Proxmox\Backup\BackupField::attributes( $variation ) ) );
	}

	public function test_a_usage_server_is_sold_the_monthly_tier_never_the_hourly_sibling(): void {
		$tier = AppLicence::tier( 'docker', 'webadmin' );

		$this->assertSame( $this->tiers['webadmin']->get_id(), AppLicence::line_variation( $tier, true )->get_id() );
		$this->assertSame( $this->tiers['webadmin']->get_id(), AppLicence::line_variation( $tier, false )->get_id() );

		$data = array( AppLicence::CART_FOR => 'cart_server' );
		$m    = $this->tiers['webadmin'];
		$u    = $this->usage['webadmin'];
		$server = $this->server_variations();
		$this->put_server_in_cart( $server['u'] );
		$this->assertTrue( AppLicenceCart::server_usage() );
		$this->assertTrue( AppLicenceCart::validate( true, $m->get_parent_id(), 1, $m->get_id(), array(), $data ) );
		$this->assertFalse( AppLicenceCart::validate( true, $u->get_parent_id(), 1, $u->get_id(), array(), $data ), 'The hourly line is no longer sold.' );
	}

	public function test_a_pay_as_you_go_server_with_web_admin_owes_the_licence_month_today_and_the_server_nothing(): void {
		$server = $this->server_variations();
		$this->put_server_in_cart( $server['u'] );
		$this->assertTrue( AppLicenceCart::choose( 'docker', 'webadmin' ) );
		WC()->cart->calculate_totals();

		$line = AppLicenceCart::line();
		$this->assertSame( $this->tiers['webadmin']->get_id(), (int) $line['item']['variation_id'] );
		$this->assertEqualsWithDelta( 14.29, (float) WC()->cart->get_subtotal(), 0.001, 'The licence month excluding VAT; the server and the account line are 0.' );
		$this->assertEqualsWithDelta( 3.57, (float) WC()->cart->get_total_tax(), 0.001, '14.29 x 25 percent.' );
		$this->assertEqualsWithDelta( 17.86, (float) WC()->cart->get_total( 'edit' ), 0.001, 'Due today including VAT.' );
		$this->assertTrue( WC()->cart->needs_payment() );

		foreach ( WC()->cart->get_cart() as $item ) {
			if ( (int) $item['variation_id'] === $server['u']->get_id() ) {
				$this->assertEqualsWithDelta( 0.0, (float) $item['line_subtotal'], 0.001, 'The server shows no charge today.' );
			}
		}

		$account = (int) get_option( Account::OPTION_PRODUCT );
		$keys    = array();

		foreach ( WC()->cart->get_cart() as $item ) {
			$keys[ (int) $item['product_id'] ] = \WC_Subscriptions_Cart::get_recurring_cart_key( $item );
		}

		$this->assertArrayHasKey( $account, $keys, 'A new customer still gets the usage account line.' );
		$this->assertNotSame( $keys[ $account ], $keys[ $this->tiers['webadmin']->get_parent_id() ], 'The licence is a subscription of its own, not a line of the usage account.' );
	}

	public function test_the_form_says_per_month_in_advance_and_never_per_hour_for_the_licence(): void {
		$server = $this->server_variations();
		$this->put_server_in_cart( $server['u'] );
		ob_start();
		AppField::render_classic();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'month', $html );
		$this->assertStringContainsString( 'charged in advance', $html );
		$this->assertStringNotContainsString( 'per hour', $html );
		$this->assertStringNotContainsString( 'Not available with pay as you go', $html );
	}

	/**
	 * A monthly licence of a pay-as-you-go server, ready, with its own subscription.
	 *
	 * @return array{server: Resource, licence: Resource, user: int, sub: \WC_Subscription}
	 */
	private function monthly_pair(): array {
		$o = $this->ordered( $this->tiers['webadmin'], 'webadmin' );
		global $wpdb;
		$wpdb->update( \BeltoftLicenses\Install::tables()['licenses'], array( 'source' => 'usage' ), array( 'id' => (int) $o['server']->license()['id'] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Test fixture.
		$server = Resource::find( $o['server']->id() );
		$sub    = wcs_get_subscription( (int) $o['licence']->license()['subscription_id'] );
		$licence = $o['licence'];
		$this->assertTrue( $licence->set( array( 'server_license' => (int) $server->license()['id'] ) ) );

		return array( 'server' => $server, 'licence' => Resource::find( $licence->id() ), 'user' => $o['user'], 'sub' => $sub );
	}

	public function test_deleting_the_server_on_day_three_ends_the_licence_charges_nothing_more_and_refunds_nothing(): void {
		$p     = $this->monthly_pair();
		$order = (int) $p['licence']->license()['order_id'];
		Meter::run( time() + 3 * DAY_IN_SECONDS );

		do_action( 'beltoft_services_state_changed', Resource::find( $p['server']->id() ), 'ready', 'deleted' );

		$this->assertSame( 'cancelled', wcs_get_subscription( $p['sub']->get_id() )->get_status(), 'No renewal follows.' );
		$this->assertSame( array(), wc_get_orders( array( 'type' => 'shop_order_refund', 'parent' => $order, 'limit' => -1 ) ), 'The started month is not refunded.' );
		global $wpdb;
		$rows = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . Ledger::table() . ' WHERE resource_id = %d', $p['licence']->id() ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- test read.
		$this->assertSame( 0, $rows, 'The licence is never metered by the hour.' );
	}

	public function test_the_app_tab_of_a_monthly_licence_on_a_usage_server_offers_the_normal_tier_switch(): void {
		$p = $this->monthly_pair();
		wp_set_current_user( $p['user'] );
		$view = AppLicence::view( $p['server'] );

		$this->assertSame( 'active', $view['state'] );
		$this->assertSame( '', $view['usage'], 'No hourly terms for a monthly licence.' );
	}

	public function test_the_renewal_a_month_later_charges_one_more_whole_month(): void {
		$p       = $this->monthly_pair();
		$renewal = wcs_create_renewal_order( $p['sub'] );

		$this->assertInstanceOf( \WC_Order::class, $renewal );
		$this->assertEqualsWithDelta( 14.29, (float) $renewal->get_subtotal(), 0.001, 'The tier price again, excluding VAT.' );
		$this->assertSame( 'usage', (string) $p['server']->license()['source'], 'The server itself stays pay as you go.' );

	}

	// -- Texts and the deploy cart ------------------------------------------------------------------

	public function test_the_order_form_says_a_whole_month_is_charged_when_ordered_and_the_server_stays_hourly(): void {
		$server = $this->server_variations();
		$this->put_server_in_cart( $server['u'] );
		ob_start();
		AppField::render_classic();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'A whole month of the licence is charged when you order and again each month you keep it. The server itself stays billed by the hour.', $html );
	}

	public function test_the_app_tab_shows_the_monthly_price_and_the_next_payment_date(): void {
		$p = $this->monthly_pair();
		wp_set_current_user( $p['user'] );
		$view = AppLicence::view( $p['server'] );

		$this->assertStringContainsString( '14.29', $view['price'] );
		$this->assertStringContainsString( 'month', $view['price'] );
		$this->assertNotSame( '', $view['next'] );
		$this->assertSame( wp_date( get_option( 'date_format' ), (int) $p['sub']->get_time( 'next_payment' ) ), $view['next'] );

	}

	public function test_the_delete_text_of_a_server_with_a_monthly_licence_says_it_stays_until_the_paid_date_and_can_be_used_again(): void {
		$p = $this->monthly_pair();
		$this->assertTrue( $p['licence']->set( array( 'state' => 'ready' ) ), 'Delivered.' );
		$note = \BeltoftServices\Usage\CustomerDelete::licence_note( $p['server'] );
		$date = wp_date( get_option( 'date_format' ), (int) $p['sub']->get_time( 'next_payment' ) );

		$this->assertSame( 'The app licence stays on your account until ' . $date . ', the end of the month you paid for, and can be used on a new server until then. It is not renewed unless it is used.', $note );
	}

	public function test_the_delete_text_without_paid_time_left_says_the_licence_ends_with_the_server(): void {
		$p = $this->monthly_pair();
		$this->assertTrue( $p['licence']->set( array( 'state' => 'ready' ) ), 'Delivered.' );
		$p['sub']->update_dates( array( 'next_payment' => gmdate( 'Y-m-d H:i:s', time() + HOUR_IN_SECONDS ) ) );

		$this->assertSame( 'The app licence ends with the server.', \BeltoftServices\Usage\CustomerDelete::licence_note( $p['server'] ) );
	}

	public function test_a_stale_hourly_licence_line_becomes_the_monthly_line_and_the_server_stays(): void {
		$server = $this->server_variations();
		$this->put_server_in_cart( $server['u'] );
		$this->assertTrue( AppLicenceCart::choose( 'docker', 'webadmin' ) );
		$cart = WC()->cart;
		$line = AppLicenceCart::line();
		// What a cart saved before this release holds: the hourly sibling as the tier line.
		$old = $this->usage['webadmin'];
		$cart->cart_contents[ $line['key'] ]['variation_id'] = $old->get_id();
		$cart->cart_contents[ $line['key'] ]['data']         = $old;

		AppLicenceCart::normalise();

		$this->assertNotNull( \BeltoftServices\Proxmox\Disk\DiskField::cart_server(), 'The server stays.' );
		$this->assertSame( 1, AppLicenceCart::lines() );
		$this->assertSame( $this->tiers['webadmin']->get_id(), (int) AppLicenceCart::line()['item']['variation_id'] );
	}

	public function test_a_stale_licence_line_whose_tier_is_gone_is_removed_with_a_notice_and_the_server_stays(): void {
		$server = $this->server_variations();
		$this->put_server_in_cart( $server['u'] );
		$this->assertTrue( AppLicenceCart::choose( 'docker', 'webadmin' ) );
		$line = AppLicenceCart::line();
		wc_clear_notices();
		$cart = WC()->cart;
		$cart->cart_contents[ $line['key'] ]['variation_id'] = 999999991;

		AppLicenceCart::normalise();

		$this->assertSame( 0, AppLicenceCart::lines() );
		$this->assertNotNull( \BeltoftServices\Proxmox\Disk\DiskField::cart_server() );
		$this->assertNotEmpty( wc_get_notices( 'notice' ) );
	}
}

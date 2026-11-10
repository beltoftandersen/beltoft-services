<?php
/**
 * An extra disk with a server ordered through Claude: catalog and list_server_plans, price_quote, order_server. FakeClient only;
 * no card is ever charged.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Mcp;

use BeltoftLicenses\Licenses;
use BeltoftServices\Account\ServerPicker;
use BeltoftServices\Api\Tokens;
use BeltoftServices\Mcp\Purchase;
use BeltoftServices\Product;
use BeltoftServices\Proxmox\Disk\DiskField;
use BeltoftServices\Proxmox\Vm\Offers;
use BeltoftServices\Proxmox\Pve\Placement;
use BeltoftServices\Usage\Breakdown;
use BeltoftServices\Usage\Rates;
use BeltoftServices\Tests\Usage\UsageTestCase;

require_once dirname( __DIR__ ) . '/Usage/UsageTestCase.php';
require_once __DIR__ . '/CallsTools.php';
require_once __DIR__ . '/ServerDiskFixture.php';

/**
 * Purchase tool tests for the extra disk of a server.
 */
final class PurchaseServerDiskTest extends UsageTestCase {

	use CallsTools;
	use ServerDiskFixture;

	/**
	 * @var array<string, \WC_Product_Variation>
	 */
	private $v = array();

	/**
	 * @var array<string, \WC_Product_Variation> Disk variations: m-50, u-50, m-100, u-100.
	 */
	private $disk = array();

	/**
	 * @var \WC_Subscription
	 */
	private $account;

	/**
	 * @var int
	 */
	private $user = 0;

	public function set_up(): void {
		parent::set_up();

		$this->user    = $this->new_user();
		$this->account = $this->usage_account( $this->user );
		$this->save_cluster( array( 'templates' => "9000 = Debian 12\n9001 = Ubuntu 24.04", 'default_template' => '9000' ) );
		$this->v    = $this->usage_product();
		$this->settings( array( 'offer_monthly' => 0, 'usage_new_limit' => 0, 'disk_max_extra' => 3, 'disk_reserve_gb' => 0, 'disk_promise_percent' => 100 ) );
		// The reading of the node first: a disk size is in stock only when it fits (Stock).
		$this->capacity( 4000 );
		$this->disk = $this->disk_product();
		$this->mcp_set_up();
		add_filter( 'beltoft_services_mcp_rate_limit', '__return_zero' );
		update_option( 'woocommerce_currency', 'EUR' );
		Offers::reset();

		foreach ( array( 'billing_first_name' => 'Test', 'billing_last_name' => 'Customer', 'billing_email' => self::MAIL, 'billing_phone' => '+4512345678', 'billing_address_1' => 'Teststreet 1', 'billing_city' => 'Aarhus', 'billing_postcode' => '8000', 'billing_country' => 'DK' ) as $key => $value ) {
			update_user_meta( $this->user, $key, $value );
		}
	}

	public function tear_down(): void {
		foreach ( wc_get_orders( array( 'type' => 'shop_order', 'limit' => -1, 'status' => array_keys( wc_get_order_statuses() ) ) ) as $order ) {
			$order->delete( true );
		}

		if ( function_exists( 'WC' ) && WC()->cart ) {
			WC()->cart->empty_cart();
		}

		$this->mcp_tear_down();
		Offers::reset();
		update_option( 'woocommerce_currency', 'USD' );
		parent::tear_down();
	}

	private function plan_id(): int {
		return (int) $this->v['m-None']->get_id();
	}

	/**
	 * @param array<string, mixed> $over Arguments.
	 * @return array<string, mixed>
	 */
	private function args( array $over = array() ): array {
		return array_merge( array( 'plan' => (string) $this->plan_id(), 'os' => '9001', 'server_name' => 'web-one' ), $over );
	}

	/**
	 * @return \WC_Order[]
	 */
	private function orders( int $user ): array {
		return wc_get_orders( array( 'customer_id' => $user, 'type' => 'shop_order', 'limit' => -1 ) );
	}

	/**
	 * The first server plan of a catalog or list_server_plans answer.
	 *
	 * @param array<string, mixed> $out Decoded answer.
	 * @return array<string, mixed>
	 */
	private function first_plan( array $out ): array {
		$this->assertNotEmpty( $out['plans'] );

		return $out['plans'][0];
	}

	// -- catalog and list_server_plans -------------------------------------------------------------

	public function test_catalog_and_list_server_plans_list_the_extra_disk_sizes(): void {
		$read = $this->oauth( $this->user, Tokens::READ );

		foreach ( array( $this->first_plan( $this->json( $this->tool( 'catalog', array( 'kind' => 'server_plans' ), $read ) ) ), $this->first_plan( $this->json( $this->tool( 'list_server_plans', array(), $read ) ) ) ) as $plan ) {
			$options = $plan['options'];
			$this->assertSame( array( 50, 100 ), array_column( $options['extra_disk'], 'extra_gb' ), 'Only the pay as you go sizes, smallest first.' );
			$this->assertSame( $this->disk['u-50']->get_id(), $options['extra_disk'][0]['variation_id'] );
			$adds = $options['extra_disk'][0]['adds'];
			$this->assertSame( 4.4, $adds['max_per_month'], '4 a month with the 10 percent premium.' );
			$this->assertEqualsWithDelta( 4.4 / 730, $adds['hourly'], 0.0001 );
			$this->assertStringContainsString( 'billed with the service and capped', $adds['text'] );
			$this->assertSame( 1, $options['max_extra_disks_at_order'] );
			$this->assertSame( 3, $options['max_extra_disks_per_server'] );
			$this->assertStringContainsString( 'next size up, never smaller', $options['disk_note'] );
			$this->assertStringContainsString( 'up to 3 extra disks per server', $options['disk_note'] );
			$this->assertStringNotContainsString( "\u{2014}", wp_json_encode( $options ) );
			$this->assertStringNotContainsString( "\u{00B7}", wp_json_encode( $options ) );
		}
	}

	public function test_every_size_is_listed_whatever_the_room(): void {
		// Changed on purpose (stock policy 2026-11-11): 30 GB for the server and the disk, and the room is 120: the server with 100 does not fit, and is listed all the same.
		$this->capacity( 120 );
		$plan = $this->first_plan( $this->json( $this->tool( 'catalog', array( 'kind' => 'server_plans' ), $this->oauth( $this->user, Tokens::READ ) ) ) );
		$this->assertSame( array( 50, 100 ), array_column( $plan['options']['extra_disk'], 'extra_gb' ) );
	}
	public function test_a_site_with_only_monthly_disks_lists_none(): void {
		foreach ( array( 'u-50', 'u-100' ) as $key ) {
			$this->disk[ $key ]->set_status( 'private' );
			$this->disk[ $key ]->save();
		}

		$plan = $this->first_plan( $this->json( $this->tool( 'list_server_plans', array(), $this->oauth( $this->user, Tokens::READ ) ) ) );
		$this->assertSame( array(), $plan['options']['extra_disk'], 'A monthly disk is paid at checkout, so it is not ordered through Claude.' );
	}

	// -- price_quote -----------------------------------------------------------------------------

	public function test_a_quote_with_a_disk_adds_its_line_and_equals_the_checkout_breakdown(): void {
		$read  = $this->oauth( $this->user, Tokens::READ );
		$bare  = $this->json( $this->tool( 'price_quote', array( 'plan' => $this->plan_id() ), $read ) );
		$quote = $this->json( $this->tool( 'price_quote', array( 'plan' => $this->plan_id(), 'extra_disk_gb' => 50 ), $read ) );

		$this->assertSame( array( 'Plan', 'Extra disk, 50 GB' ), array_map( static fn( $l ) => preg_replace( '/^.* plan$/', 'Plan', $l['label'] ), $quote['lines'] ) );
		$this->assertSame( 4.4, $quote['lines'][1]['per_month'] );
		$this->assertSame( round( $bare['max_per_month'] + 4.4, 2 ), $quote['max_per_month'] );
		$this->assertEqualsWithDelta( $bare['hourly'] + 4.4 / 730, $quote['hourly'], 0.0002 );

		// What the checkout holds for the same choices: the server line and the disk line, each with its own rates.
		wp_set_current_user( $this->user );
		$this->fill_cart( 50 );
		$cap = 0.0;
		$rows = array();

		foreach ( WC()->cart->get_cart() as $item ) {
			$usage = $item['data'];
			$this->assertInstanceOf( \WC_Product_Variation::class, $usage );
			$rates = Rates::for_usage( $usage );
			$this->assertNotNull( $rates );
			$cap  += $rates['cap'];

			if ( 'proxmox-disk' === ( Product::driver_for( $usage->get_parent_id() )->id() ) ) {
				$rows = Breakdown::rows( array( 'plan' => 0, 'ip' => 0, 'storage' => 0, 'backups' => 0, 'disk' => $rates['cap'], 'disk_gb' => (int) Product::plan( $usage->get_parent_id(), $usage->get_id() )['disk_gb'] ) );
			}
		}

		$this->assertSame( round( $cap, 2 ), $quote['max_per_month'], 'The quote is the sum of the checkout lines.' );
		$this->assertSame( $quote['lines'][1]['label'], $rows[1][0] );
		$this->assertSame( $quote['lines'][1]['text'], $rows[1][1] );
	}

	/**
	 * The cart of the website: the server with the picker's code and the disk with the extra disk box's code.
	 *
	 * @param int $gb Extra disk.
	 */
	private function fill_cart( int $gb ): void {
		WC()->cart->empty_cart();
		$plan    = Offers::find( $this->plan_id() );
		$checked = ServerPicker::check( array( 'plan' => (string) $plan['variation_id'], 'backups' => '', 'billing' => 'usage', 'os' => '9001', 'app' => '', 'version' => '', 'tier' => '', 'domain_on' => '', 'own_on' => '', 'own' => '', 'zone' => '', 'sub' => '', 'name' => 'web-one', 'ssh_key' => '' ), $this->user );
		$this->assertSame( array(), $checked['errors'] );
		$this->assertNull( ServerPicker::add_to_cart( $checked ) );

		if ( $gb > 0 ) {
			$this->assertTrue( DiskField::choose( $this->disk[ 'u-' . $gb ]->get_id() ) );
		}
	}

	public function test_a_quote_with_a_size_that_is_not_offered_lists_the_valid_steps(): void {
		$out = $this->tool( 'price_quote', array( 'plan' => $this->plan_id(), 'extra_disk_gb' => 70 ), $this->oauth( $this->user, Tokens::READ ) );
		$this->assertTrue( $out['isError'] );
		$this->assertStringContainsString( 'The sizes are, in GB: 50, 100.', wp_json_encode( $out ) );
	}

	public function test_a_quote_with_no_room_is_not_refused(): void {
		// Changed on purpose (stock policy 2026-11-11): capacity never stops a quote.
		$this->capacity( 70 );
		$out = $this->tool( 'price_quote', array( 'plan' => $this->plan_id(), 'extra_disk_gb' => 50 ), $this->oauth( $this->user, Tokens::READ ) );
		$this->assertFalse( $out['isError'] ?? false, wp_json_encode( $out ) );
	}
	public function test_a_quote_without_a_disk_is_unchanged(): void {
		$quote = $this->json( $this->tool( 'price_quote', array( 'plan' => $this->plan_id() ), $this->oauth( $this->user, Tokens::READ ) ) );
		$this->assertCount( 1, $quote['lines'] );
		$this->assertSame( 16.5, $quote['max_per_month'] );
	}

	// -- order_server ----------------------------------------------------------------------------

	public function test_the_summary_shows_the_disk_line_and_orders_nothing(): void {
		$token   = $this->oauth( $this->user, Tokens::FULL );
		$summary = $this->json( $this->tool( 'order_server', $this->args( array( 'extra_disk_gb' => 50 ) ), $token ) );

		$this->assertTrue( $summary['requires_confirmation'] );
		$text = $summary['summary'];
		$this->assertStringContainsString( 'Extra disk: 50 GB, billed by the hour with the server', $text );
		$this->assertStringContainsString( '- Extra disk, 50 GB: ', $text );
		$this->assertStringContainsString( "\u{20AC}20.90 per month", $text, '16.50 for the server and 4.40 for the disk.' );
		$this->assertSame( array(), $this->orders( $this->user ) );

		$none = $this->json( $this->tool( 'order_server', $this->args(), $token ) );
		$this->assertStringNotContainsString( 'Extra disk', $none['summary'], 'Without a disk the summary is as before.' );
		$this->assertNotSame( $none['confirm'], $summary['confirm'] );
	}

	public function test_an_invalid_size_is_refused_with_the_valid_steps(): void {
		$out = $this->tool( 'order_server', $this->args( array( 'extra_disk_gb' => 70 ) ), $this->oauth( $this->user, Tokens::FULL ) );
		$this->assertTrue( $out['isError'] );
		$this->assertStringContainsString( 'The sizes are, in GB: 50, 100.', wp_json_encode( $out ) );
		$this->assertSame( array(), $this->orders( $this->user ) );
	}

	public function test_no_capacity_does_not_refuse_the_order(): void {
		// Changed on purpose (stock policy 2026-11-11): capacity never stops an order.
		$this->capacity( 70 );
		$out = $this->tool( 'order_server', $this->args( array( 'extra_disk_gb' => 50 ) ), $this->oauth( $this->user, Tokens::FULL ) );
		$this->assertFalse( $out['isError'] ?? false, wp_json_encode( $out ) );
		$this->assertStringNotContainsString( 'does not fit', wp_json_encode( $out ) );
	}
	/**
	 * WooCommerce's checkout defines WOOCOMMERCE_CHECKOUT for good: a process of its own keeps it from the other tests.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_an_order_with_a_disk_has_the_lines_and_meta_of_a_checkout_with_the_disk(): void {
		$token = $this->oauth( $this->user, Tokens::FULL );
		$args  = $this->args( array( 'extra_disk_gb' => 50 ) );

		// What a checkout would hold: the lines and the item data of the website's cart.
		wp_set_current_user( $this->user );
		$this->fill_cart( 50 );
		$expect = array();

		foreach ( WC()->cart->get_cart() as $item ) {
			$expect[] = array( (int) $item['variation_id'], isset( $item['beltoft_services_disk_for'] ) ? (string) $item['beltoft_services_disk_for'] : '' );
		}

		WC()->cart->empty_cart();
		wp_set_current_user( 0 );

		$first = $this->tool( 'order_server', $args, $token );
		$this->assertFalse( $first['isError'], wp_json_encode( $first ) );
		$sum  = $this->json( $first );
		$done = $this->json( $this->tool( 'order_server', $args + array( 'confirm' => $sum['confirm'] ), $token ) );

		$this->assertTrue( $done['ordered'], wp_json_encode( $done ) );
		$orders = $this->orders( $this->user );
		$this->assertCount( 1, $orders );
		$order = $orders[0];
		$this->assertEquals( 0, $order->get_total() );
		$this->assertNotSame( '', (string) $order->get_meta( Purchase::ORDER_META ) );
		$this->assertCount( 2, $order->get_items(), 'The server and the disk.' );

		$lines = array();

		foreach ( $order->get_items() as $item ) {
			$lines[] = array( (int) $item->get_variation_id(), (string) $item->get_meta( '_beltoft_services_disk_server' ) );
		}

		$this->assertEqualsCanonicalizing( $expect, $lines, 'The same variations, and the disk is tied to the new server.' );

		foreach ( $order->get_items() as $item ) {
			if ( (int) $item->get_variation_id() === $this->disk['u-50']->get_id() ) {
				$this->assertNotSame( '', (string) $item->get_meta( '_beltoft_services_disk_uid' ) );
			} else {
				$this->assertSame( '9001', (string) $item->get_meta( \BeltoftServices\Proxmox\Vm\OsField::ITEM_META ) );
			}
		}

		// The answer is about the server, not the disk.
		$server = \BeltoftServices\Resource::find( (int) $done['service_id'] );
		$this->assertSame( 'proxmox', $server->get( 'driver' ) );
		$licenses = Licenses::where( 'order_id', $order->get_id() );
		$this->assertCount( 2, $licenses );

		foreach ( $licenses as $license ) {
			$this->assertSame( 'usage', $license['source'], 'Both are pay as you go licences.' );
			$this->assertSame( $this->account->get_id(), (int) $license['subscription_id'], 'Both sit on the usage account.' );
		}

		$disk = null;

		foreach ( $licenses as $license ) {
			$resource = \BeltoftServices\Resource::for_license( (int) $license['id'] );
			$disk     = $resource && 'proxmox-disk' === $resource->get( 'driver' ) ? $resource : $disk;
		}

		$this->assertNotNull( $disk, 'The disk has its own service.' );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_an_order_without_a_disk_has_one_line(): void {
		$token = $this->oauth( $this->user, Tokens::FULL );
		wp_set_current_user( 0 );
		$args = $this->args();
		$sum  = $this->json( $this->tool( 'order_server', $args, $token ) );
		$done = $this->json( $this->tool( 'order_server', $args + array( 'confirm' => $sum['confirm'] ), $token ) );

		$this->assertTrue( $done['ordered'], wp_json_encode( $done ) );
		$order = $this->orders( $this->user )[0];
		$this->assertCount( 1, $order->get_items() );
		$this->assertSame( (string) $this->v['u-None']->get_id(), (string) array_values( $order->get_items() )[0]->get_variation_id() );
	}
}

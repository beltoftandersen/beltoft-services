<?php
/**
 * The pay as you go extra disk of a server: what the dashboard offers, adding it, making it larger and removing it, and that
 * the usage account's subscription is never touched. FakeClient only; no card is ever charged.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Usage;

use BeltoftLicenses\Licenses;
use BeltoftServices\Api\Tokens;
use BeltoftServices\Drivers\Registry;
use BeltoftServices\Jobs;
use BeltoftServices\Product;
use BeltoftServices\Proxmox\Disk\DiskCart;
use BeltoftServices\Proxmox\Disk\DiskField;
use BeltoftServices\Proxmox\Disk\DiskPanels;
use BeltoftServices\Proxmox\Vm\Offers;
use BeltoftServices\Resource;
use BeltoftServices\Tests\Mcp\CallsTools;
use BeltoftServices\Tests\Mcp\ServerDiskFixture;
use BeltoftServices\Usage\Account;
use BeltoftServices\Usage\CustomerDelete;
use BeltoftServices\Usage\Ledger;
use BeltoftServices\Usage\Meter;
use BeltoftServices\Usage\Rates;
use BeltoftServices\Usage\Renewal;
use BeltoftServices\Usage\UsageDisk;

require_once __DIR__ . '/UsageTestCase.php';
require_once dirname( __DIR__ ) . '/Mcp/CallsTools.php';
require_once dirname( __DIR__ ) . '/Mcp/ServerDiskFixture.php';

/**
 * Add, grow and remove of a pay as you go extra disk.
 */
final class UsageDiskTest extends UsageTestCase {

	use \BeltoftServices\Tests\Support\FakeCurrency;

	use CallsTools;
	use ServerDiskFixture;

	/**
	 * @var array<string, \WC_Product_Variation>
	 */
	private $v = array();

	/**
	 * @var array<string, \WC_Product_Variation>
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
		// The shop currency first: the usage account is made in it.
		update_option( 'woocommerce_currency', 'EUR' );

		$this->user    = $this->new_user();
		$this->account = $this->usage_account( $this->user );
		$this->settings( array( 'offer_monthly' => 0, 'usage_new_limit' => 0, 'disk_max_extra' => 3, 'disk_reserve_gb' => 0, 'mcp_enabled' => 1 ) );
		$this->v = $this->usage_product();
		$this->capacity( 4000 );
		$this->disk = $this->disk_product();
		$this->mcp_set_up();
		add_filter( 'beltoft_services_mcp_rate_limit', '__return_zero' );
		update_option( 'woocommerce_currency', 'EUR' );
		Offers::reset();
		wp_set_current_user( $this->user );
	}

	public function tear_down(): void {
		$this->fake_currency_down();
		if ( function_exists( 'WC' ) && WC()->cart ) {
			WC()->cart->empty_cart();
		}

		unset( $_REQUEST['beltoft_services_disk_for'], $_REQUEST['beltoft_services_disk_nonce'] );
		$this->mcp_tear_down();
		Offers::reset();
		update_option( 'woocommerce_currency', 'USD' );
		parent::tear_down();
	}

	/**
	 * A ready pay as you go server of the customer, named web1.
	 */
	private function server(): Resource {
		$server = $this->usage_vm( $this->v['u-None'], $this->user, 5, $this->account->get_id() );
		$server->set( array( 'server_name' => 'web1' ) );

		return Resource::find( $server->id() );
	}

	/**
	 * A ready pay as you go disk of that server.
	 *
	 * @param Resource $server Server.
	 * @param string   $key    u-50 or u-100.
	 */
	private function usage_disk( Resource $server, string $key = 'u-50' ): Resource {
		$variation = $this->disk[ $key ];
		$parent    = wc_get_product( $variation->get_parent_id() );
		$license   = $this->service_license(
			$parent,
			array(
				'fire'            => false,
				'user_id'         => $this->user,
				'email'           => self::MAIL,
				'source'          => 'usage',
				'variation_id'    => $variation->get_id(),
				'subscription_id' => $this->account->get_id(),
			)
		);
		$disk      = Resource::create( $license, Registry::get( 'proxmox-disk' ), Product::plan( $parent->get_id(), $variation->get_id() ), array( 'server_license' => $server->license()['id'] ) );
		$this->assertInstanceOf( Resource::class, $disk );
		Jobs::run( $disk->id() );
		$this->assertSame( 'ready', Resource::find( $disk->id() )->get( 'state' ), (string) Resource::find( $disk->id() )->get( 'last_error' ) );
		$this->activated_ago( $disk->id(), 5 );

		return Resource::find( $disk->id() );
	}

	/**
	 * The usage account as it is: active, with its one line, and still the customer's account.
	 */
	private function assert_account_untouched(): void {
		$fresh = wcs_get_subscription( $this->account->get_id() );
		$this->assertSame( 'active', $fresh->get_status(), 'The usage account stays active.' );
		$this->assertCount( 1, $fresh->get_items(), 'Its line is as it was.' );
		$this->assertSame( $this->account->get_id(), Account::for_user( $this->user )->get_id() );
	}

	// -- The dashboard card ---------------------------------------------------------------------

	public function test_a_usage_disk_card_never_offers_a_subscription_link_but_its_own_remove_and_grow(): void {
		$server = $this->server();
		$disk   = $this->usage_disk( $server );
		$panel  = DiskPanels::server( $server );
		$item   = $panel['items'][0];

		$this->assertTrue( $item['usage'] );
		$this->assertSame( '', $item['remove_url'], 'No WooCommerce Subscriptions cancel link.' );
		$this->assertSame( '', $item['grow_url'], 'No subscription switch link.' );
		$this->assertSame( '', $item['manage_url'] );
		$this->assertNull( $item['ending'] );
		$this->assertTrue( $item['delete'] );
		$this->assertSame( 100, $item['grow_next']['size'] );
		$this->assertStringContainsString( '+', $item['grow_next']['price'] );
		$this->assertStringContainsString( 'billed by the hour', $item['usage_terms'] );
		$this->assertSame( '', DiskCart::grow_url( $disk ), 'The subscription switch is never built for it.' );

		ob_start();
		\BeltoftServices\Proxmox\Vm\Panels::account( Resource::find( $server->id() ) );
		$html = (string) ob_get_clean();
		$this->assertStringContainsString( 'data-delete-disk="' . $disk->id() . '"', $html );
		$this->assertStringContainsString( 'data-grow-disk="' . $disk->id() . '"', $html );
		$this->assertStringNotContainsString( 'data-cancel-url', $html );
	}

	public function test_the_add_tiles_follow_the_servers_billing(): void {
		$usage_server = $this->server();
		$tiles        = DiskPanels::server( $usage_server )['add'];
		$this->assertSame( array( 50, 100 ), array_column( $tiles, 'size' ) );
		$this->assertStringContainsString( 'billed by the hour', $tiles[0]['price'] );
		$this->assertStringContainsString( '+', $tiles[0]['price'] );
		$query = array();
		wp_parse_str( (string) wp_parse_url( $tiles[0]['url'], PHP_URL_QUERY ), $query );
		$this->assertSame( $this->disk['u-50']->get_id(), (int) $query['variation_id'], 'The pay as you go size, never the monthly one.' );

		// A monthly server (an ordinary licence) is offered the monthly sizes.
		$monthly = $this->ready_vm();
		wp_set_current_user( (int) $monthly->license()['user_id'] );
		$tiles = DiskPanels::server( $monthly )['add'];
		$this->assertNotEmpty( $tiles );
		wp_parse_str( (string) wp_parse_url( $tiles[0]['url'], PHP_URL_QUERY ), $query );
		$this->assertSame( $this->disk['m-50']->get_id(), (int) $query['variation_id'] );
	}

	// -- The cart --------------------------------------------------------------------------------

	private function usage_server_cart(): void {
		WC()->cart->empty_cart();
		$plan    = Offers::find( $this->v['m-None']->get_id() );
		$checked = \BeltoftServices\Account\ServerPicker::check( array( 'plan' => (string) $plan['variation_id'], 'backups' => '', 'billing' => 'usage', 'os' => '9000', 'app' => '', 'version' => '', 'tier' => '', 'domain_on' => '', 'own_on' => '', 'own' => '', 'zone' => '', 'sub' => '', 'name' => 'web1', 'ssh_key' => '' ), $this->user );
		$this->assertSame( array(), $checked['errors'] );
		$this->assertNull( \BeltoftServices\Account\ServerPicker::add_to_cart( $checked ) );
	}

	public function test_a_new_pay_as_you_go_server_gets_the_pay_as_you_go_disk_only(): void {
		$this->usage_server_cart();
		$this->assertTrue( DiskField::cart_server_usage() );
		$this->assertSame( array( $this->disk['u-50']->get_id(), $this->disk['u-100']->get_id() ), array_keys( DiskField::sizes_for( true ) ) );
		$this->assertFalse( DiskField::choose( $this->disk['m-50']->get_id() ), 'A monthly size is not chosen for a pay as you go server.' );
		$this->assertTrue( DiskField::choose( $this->disk['u-50']->get_id() ) );

		$lines = array_map( static fn( $i ) => (int) $i['variation_id'], WC()->cart->get_cart() );
		$this->assertContains( $this->disk['u-50']->get_id(), $lines );

		ob_start();
		DiskField::render();
		$html = (string) ob_get_clean();
		$this->assertStringContainsString( '50 GB: +', $html );
		$this->assertStringContainsString( 'billed by the hour', $html );
		$this->assertStringNotContainsString( '/ month', $html, 'The monthly price is not the one shown.' );

		// The checkout accepts it (billing follows the server) and refuses the monthly size.
		wc_clear_notices();
		$this->assertFalse( DiskCart::validate( true, $this->disk['m-50']->get_parent_id(), 1, $this->disk['m-50']->get_id(), array(), array( 'beltoft_services_disk_for' => 'cart_server' ) ) );
		$this->assertNotEmpty( wc_get_notices( 'error' ) );
	}

	public function test_switching_the_servers_billing_moves_the_disk_to_its_twin(): void {
		$this->settings( array( 'offer_monthly' => 1 ) );
		Offers::reset();
		$this->usage_server_cart();
		$this->assertTrue( DiskField::choose( $this->disk['u-50']->get_id() ) );
		$this->assertTrue( \BeltoftServices\Usage\BillingField::swap( false )['ok'] );
		$this->assertFalse( DiskField::cart_server_usage() );
		$ids = array_map( static fn( $i ) => (int) $i['variation_id'], WC()->cart->get_cart() );
		$this->assertContains( $this->disk['m-50']->get_id(), $ids, 'The monthly twin follows a monthly server.' );
		$this->assertNotContains( $this->disk['u-50']->get_id(), $ids );
		$this->assertTrue( \BeltoftServices\Usage\BillingField::swap( true )['ok'] );
		$ids = array_map( static fn( $i ) => (int) $i['variation_id'], WC()->cart->get_cart() );
		$this->assertContains( $this->disk['u-50']->get_id(), $ids );
	}

	public function test_adding_a_disk_to_an_existing_usage_server_needs_the_pay_as_you_go_size(): void {
		$server  = $this->server();
		$license = $server->license();
		$_REQUEST['beltoft_services_disk_for']   = (string) $license['id'];
		$_REQUEST['beltoft_services_disk_nonce'] = wp_create_nonce( 'beltoft-services-disk:' . $license['id'] );
		WC()->cart->empty_cart();
		wc_clear_notices();

		$usage = $this->disk['u-50'];
		$this->assertTrue( DiskCart::validate( true, $usage->get_parent_id(), 1, $usage->get_id(), array(), array() ) );

		$monthly = $this->disk['m-50'];
		$this->assertFalse( DiskCart::validate( true, $monthly->get_parent_id(), 1, $monthly->get_id(), array(), array() ), 'A pay as you go server gets no monthly subscription disk.' );

		// The cart takes it, free of payment: the total is 0 and the usage account line is added when the customer has none.
		$added = WC()->cart->add_to_cart( $usage->get_parent_id(), 1, $usage->get_id(), array( 'attribute_size' => '50 GB', 'attribute_billing' => 'Pay as you go' ) );
		$this->assertNotFalse( $added );
		WC()->cart->calculate_totals();
		$this->assertEquals( 0, WC()->cart->get_total( 'edit' ) );
		$this->assertSame( 1, \BeltoftServices\Usage\Cart::usage_units(), 'It counts as a pay as you go unit: the account rules (card, on hold) apply.' );
		$this->assertSame( 0, \BeltoftServices\Usage\Cart::service_units(), 'It is not a service of its own for the new-account limit.' );
	}

	public function test_a_usage_disk_for_a_monthly_server_is_refused(): void {
		$monthly = $this->ready_vm();
		$license = $monthly->license();
		wp_set_current_user( (int) $license['user_id'] );
		$_REQUEST['beltoft_services_disk_for']   = (string) $license['id'];
		$_REQUEST['beltoft_services_disk_nonce'] = wp_create_nonce( 'beltoft-services-disk:' . $license['id'] );
		wc_clear_notices();
		$usage = $this->disk['u-50'];
		$this->assertFalse( DiskCart::validate( true, $usage->get_parent_id(), 1, $usage->get_id(), array(), array() ) );
	}

	// -- Remove -----------------------------------------------------------------------------------

	public function test_remove_deletes_the_disk_stops_the_meter_and_leaves_the_usage_account_active(): void {
		$server = $this->server();
		$disk   = $this->usage_disk( $server );
		$this->assertSame( 'disk', CustomerDelete::kind( $disk ) );
		$subscription_notes = count( wc_get_order_notes( array( 'order_id' => $this->account->get_id() ) ) );

		$done = CustomerDelete::run( $disk, $this->user, 'DELETE' );
		$this->assertTrue( $done, is_wp_error( $done ) ? $done->get_error_message() : '' );
		$this->assertSame( Licenses::STATUS_REVOKED, Licenses::find( (int) $disk->license()['id'] )['status'], 'The disk licence is ended.' );

		$this->run_jobs();
		$fresh = Resource::find( $disk->id() );
		$this->assertSame( 'deleted', $fresh->get( 'state' ), (string) $fresh->get( 'last_error' ) );
		$this->assertFalse( $fresh->is_live(), 'The activation is closed: metering stops.' );
		$this->assertCount( 0, DiskPanels::server( Resource::find( $server->id() ) )['items'] ?? array() );

		Meter::run( time() + 3 * HOUR_IN_SECONDS );
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- test read.
		$last = $wpdb->get_var( $wpdb->prepare( 'SELECT MAX(hour_bucket) FROM ' . Ledger::table() . ' WHERE resource_id = %d', $disk->id() ) );
		$this->assertLessThanOrEqual( gmdate( 'Y-m-d H:00:00' ), $last, 'Nothing is metered after the hour it was removed in.' );

		// The server and the account are as they were.
		$this->assertSame( 'ready', Resource::find( $server->id() )->get( 'state' ) );
		$this->assertSame( 'ready', Resource::find( $server->id() )->get( 'desired' ) );
		$this->assert_account_untouched();
		$this->assertSame( Licenses::STATUS_ACTIVE, Licenses::find( (int) $server->license()['id'] )['status'] );
		$this->assertSame( $this->account->get_id(), (int) Licenses::find( (int) $server->license()['id'] )['subscription_id'] );
		$this->assertSame( $subscription_notes, count( wc_get_order_notes( array( 'order_id' => $this->account->get_id() ) ) ), 'No note, no status change on the account.' );
	}

	public function test_only_the_owner_can_remove_and_a_server_with_a_disk_is_still_blocked_until_it_is_gone(): void {
		$server = $this->server();
		$disk   = $this->usage_disk( $server );
		$other  = $this->new_user();
		$this->assertTrue( is_wp_error( CustomerDelete::run( $disk, $other, 'DELETE' ) ) );
		$this->assertSame( 'ready', Resource::find( $disk->id() )->get( 'desired' ) );
		$this->assertStringContainsString( 'extra disk', (string) CustomerDelete::blocker( $server ) );
	}

	public function test_the_remove_tool_deletes_a_usage_disk_and_never_cancels_the_account(): void {
		$server = $this->server();
		$disk   = $this->usage_disk( $server );
		$token  = $this->oauth( $this->user, Tokens::FULL );
		wp_set_current_user( 0 );

		$first = $this->tool( 'remove_addon', array( 'id' => $disk->id() ), $token );
		$this->assertFalse( $first['isError'], wp_json_encode( $first ) );
		$sum = $this->json( $first );
		$this->assertTrue( $sum['requires_confirmation'] );
		$this->assertStringContainsString( 'Billing stops in the hour it is removed', $sum['summary'] );
		$this->assertStringNotContainsString( 'subscription', strtolower( $sum['summary'] ) );

		$done = $this->tool( 'remove_addon', array( 'id' => $disk->id(), 'confirm' => $sum['confirm'] ), $token );
		$this->assertFalse( $done['isError'], wp_json_encode( $done ) );
		$this->assertSame( 'deleted', Resource::find( $disk->id() )->get( 'desired' ) );
		$this->assert_account_untouched();
	}

	// -- Grow -------------------------------------------------------------------------------------

	public function test_grow_makes_the_disk_the_next_size_bills_the_new_rate_and_leaves_the_account_alone(): void {
		$server = $this->server();
		$disk   = $this->usage_disk( $server );
		$before = Rates::items( $disk );
		$this->assertSame( 50.0, $before[0]['quantity'] );

		Meter::run( time() );
		$done = UsageDisk::grow( $disk, $this->user );
		$this->assertTrue( $done, is_wp_error( $done ) ? $done->get_error_message() : '' );
		$this->assertSame( $this->disk['u-100']->get_id(), (int) Licenses::find( (int) $disk->license()['id'] )['variation_id'] );
		$this->assertSame( $this->account->get_id(), (int) Licenses::find( (int) $disk->license()['id'] )['subscription_id'], 'The licence stays on the usage account.' );

		$this->run_jobs();
		$fresh = Resource::find( $disk->id() );
		$this->assertSame( 'ready', $fresh->get( 'state' ), (string) $fresh->get( 'last_error' ) );
		$this->assertSame( 100, (int) $fresh->get( 'size_gb' ), 'The disk was resized through the driver.' );

		$after = Rates::items( $fresh );
		$this->assertSame( 100.0, $after[0]['quantity'] );
		$this->assertEqualsWithDelta( $before[0]['rate'], $after[0]['rate'], 0.0000001, 'The same price per GB: the monthly 8 for 100 GB is 4 for 50.' );
		$this->assertEqualsWithDelta( 2 * $before[0]['rate'] * 50, $after[0]['rate'] * 100, 0.01 );

		// The hours metered from now on are 100 GB.
		Meter::run( time() + 3 * HOUR_IN_SECONDS );
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- test read.
		$sizes = $wpdb->get_col( $wpdb->prepare( "SELECT quantity FROM " . Ledger::table() . " WHERE resource_id = %d AND item = 'disk' ORDER BY hour_bucket", $disk->id() ) );
		$this->assertContains( '100.0000', $sizes );
		$this->assertSame( '100.0000', end( $sizes ) );

		$this->assert_account_untouched();
		$this->assertNull( UsageDisk::next( $fresh ), 'No larger size is left.' );
		$this->assertTrue( is_wp_error( UsageDisk::grow( $fresh, $this->user ) ) );
	}

	public function test_a_disk_waiting_to_grow_is_metered_at_the_size_it_really_has_and_the_larger_rate_starts_when_it_is_larger(): void {
		$server = $this->server();
		$disk   = $this->usage_disk( $server );
		$small  = Rates::items( $disk );

		// The storage has no room: the licence moves at once, the disk cannot grow.
		$this->settings( array( 'disk_reserve_gb' => 100000 ) );
		$this->assertTrue( UsageDisk::grow( $disk, $this->user ) );
		$this->run_jobs();
		$waiting = Resource::find( $disk->id() );
		$this->assertSame( 50, (int) $waiting->get( 'size_gb' ), 'The disk did not grow.' );
		$this->assertSame( 100, (int) ( (array) $waiting->get( 'plan' ) )['disk_gb'], 'The plan asks for 100 GB.' );
		$this->assertSame( $this->disk['u-100']->get_id(), (int) Licenses::find( (int) $disk->license()['id'] )['variation_id'] );

		$items = Rates::items( $waiting );
		$this->assertSame( 50.0, $items[0]['quantity'], 'Metered by the size it really has.' );
		$this->assertEqualsWithDelta( $small[0]['rate'], $items[0]['rate'], 0.0000001, 'At the price of the 50 GB size.' );
		Meter::run( time() + 2 * HOUR_IN_SECONDS );
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- test read.
		$sizes = $wpdb->get_col( $wpdb->prepare( 'SELECT quantity FROM ' . Ledger::table() . " WHERE resource_id = %d AND item = 'disk'", $disk->id() ) );
		$this->assertNotContains( '100.0000', $sizes, 'No hour is metered at the larger size while it is pending.' );

		// Room comes back and the grow finishes: the larger size is billed from then on.
		$this->settings( array( 'disk_reserve_gb' => 0 ) );
		$result = Registry::get( 'proxmox-disk' )->update_plan( Resource::find( $disk->id() ) ); // The job's retry, an hour later.
		$this->assertTrue( $result->is_done(), $result->error() );
		$fresh = Resource::find( $disk->id() );
		$this->assertSame( 100, (int) $fresh->get( 'size_gb' ) );
		$this->assertSame( 100.0, Rates::items( $fresh )[0]['quantity'], 'The larger size is billed once the disk has it.' );
	}

	public function test_a_grow_that_fails_for_good_puts_the_licence_back_on_the_size_the_disk_has_and_mails_the_admin(): void {
		$server = $this->server();
		$disk   = $this->usage_disk( $server );
		$mails  = array();
		add_filter(
			'pre_wp_mail',
			static function ( $pre, $args ) use ( &$mails ) {
				$mails[] = $args;

				return true;
			},
			10,
			2
		);
		$this->settings( array( 'admin_email' => 'christian.beltoft@gmail.com', 'disk_reserve_gb' => 100000 ) );
		$this->assertTrue( UsageDisk::grow( $disk, $this->user ) );
		$this->run_jobs();
		$failing = Resource::find( $disk->id() );
		$this->assertSame( $this->disk['u-100']->get_id(), (int) Licenses::find( (int) $disk->license()['id'] )['variation_id'] );
		$mails = array();

		// What the reconciler announces when the last retry of the update has failed.
		$failing->set( array( 'failed_op' => 'update_plan' ) );
		do_action( 'beltoft_services_state_changed', Resource::find( $failing->id() ), 'ready', 'failed' );

		$this->assertSame( $this->disk['u-50']->get_id(), (int) Licenses::find( (int) $disk->license()['id'] )['variation_id'], 'Back on the 50 GB size.' );
		$this->assertSame( 50, (int) ( (array) Resource::find( $disk->id() )->get( 'plan' ) )['disk_gb'] );
		$this->assertCount( 1, $mails );
		$this->assertSame( 'christian.beltoft@gmail.com', $mails[0]['to'] );
		$this->assertStringContainsString( '50 GB', $mails[0]['message'] );
		$this->assertSame( 50.0, Rates::items( Resource::find( $disk->id() ) )[0]['quantity'] );

		// A failed disk that was never asked for more is left alone.
		$mails = array();
		do_action( 'beltoft_services_state_changed', Resource::find( $disk->id() ), 'ready', 'failed' );
		$this->assertSame( array(), $mails );
	}

	/**
	 * A pay as you go disk asked to grow while the storage has no room, with its mails captured.
	 *
	 * @param array<int, array<string, mixed>> $mails Captured mails.
	 * @return Resource The disk waiting to grow.
	 */
	private function waiting_disk( array &$mails ): Resource {
		$server = $this->server();
		$disk   = $this->usage_disk( $server );
		add_filter(
			'pre_wp_mail',
			static function ( $pre, $args ) use ( &$mails ) {
				$mails[] = $args;

				return true;
			},
			10,
			2
		);
		$this->settings( array( 'admin_email' => 'christian.beltoft@gmail.com', 'disk_reserve_gb' => 100000 ) );
		$this->assertTrue( UsageDisk::grow( $disk, $this->user ) );
		$this->run_jobs();

		return Resource::find( $disk->id() );
	}

	public function test_a_grow_that_fails_through_the_reconciler_is_undone_and_the_disk_returns_to_ready_and_can_grow_again(): void {
		$mails   = array();
		$waiting = $this->waiting_disk( $mails );
		$mails   = array();

		// Every retry fails for lack of room; the last one is the failure.
		for ( $i = 0; $i < 8 && 'failed' !== Resource::find( $waiting->id() )->get( 'state' ) && $this->disk['u-50']->get_id() !== (int) Licenses::find( (int) $waiting->license()['id'] )['variation_id']; $i++ ) {
			Resource::find( $waiting->id() )->set( array( 'next_attempt_at' => null ) );
			Jobs::run( $waiting->id() );
		}

		$this->assertSame( $this->disk['u-50']->get_id(), (int) Licenses::find( (int) $waiting->license()['id'] )['variation_id'], 'The licence is back on the size the disk has.' );
		$this->settings( array( 'disk_reserve_gb' => 0 ) );
		$this->run_jobs();
		Jobs::run( $waiting->id() );
		$fresh = Resource::find( $waiting->id() );
		$this->assertSame( 'ready', $fresh->get( 'state' ), 'The disk is not left failed: ' . (string) $fresh->get( 'last_error' ) );
		$this->assertSame( 0, (int) $fresh->get( 'attempts' ) );
		$this->assertTrue( UsageDisk::grow( $fresh, $this->user ), 'The customer can ask again once there is room.' );
		$this->assertNotEmpty( array_filter( $mails, static fn( $m ) => false !== strpos( (string) $m['subject'], 'could not be made larger' ) ) );
	}

	public function test_the_undo_is_only_for_a_failed_grow_of_a_pay_as_you_go_disk(): void {
		$mails   = array();
		$waiting = $this->waiting_disk( $mails );
		$mails   = array();
		$license = (int) $waiting->license()['id'];
		$variation = static fn() => (int) Licenses::find( $license )['variation_id'];
		$larger    = $this->disk['u-100']->get_id();

		// Not the failed state.
		$waiting->set( array( 'failed_op' => 'update_plan' ) );
		do_action( 'beltoft_services_state_changed', Resource::find( $waiting->id() ), 'ready', 'ready' );
		$this->assertSame( $larger, $variation(), 'Only a failure undoes it.' );

		// A failure of another operation (a pause while the grow waits).
		$waiting->set( array( 'failed_op' => 'pause' ) );
		do_action( 'beltoft_services_state_changed', Resource::find( $waiting->id() ), 'ready', 'failed' );
		$this->assertSame( $larger, $variation(), 'A failed pause is not a failed grow.' );
		$this->assertSame( array(), $mails );

		// A disk that is not pay as you go: its licence is a monthly one.
		$monthly_var = $this->disk['m-50'];
		$parent      = wc_get_product( $monthly_var->get_parent_id() );
		$m_license   = $this->service_license( $parent, array( 'fire' => false, 'user_id' => $this->user, 'email' => self::MAIL, 'variation_id' => $monthly_var->get_id() ) );
		$monthly     = Resource::create( $m_license, Registry::get( 'proxmox-disk' ), Product::plan( $parent->get_id(), $monthly_var->get_id() ), array( 'server_license' => $waiting->get( 'server_license' ) ) );
		$this->assertInstanceOf( Resource::class, $monthly );
		$plan            = (array) $monthly->get( 'plan' );
		$plan['disk_gb'] = 100;
		$monthly->set( array( 'plan' => $plan, 'size_gb' => 50, 'failed_op' => 'update_plan' ) );
		do_action( 'beltoft_services_state_changed', Resource::find( $monthly->id() ), 'ready', 'failed' );
		$this->assertSame( $monthly_var->get_id(), (int) Licenses::find( (int) $m_license['id'] )['variation_id'], 'A monthly disk is paid at checkout: left alone.' );
		$this->assertSame( array(), $mails );
	}

	public function test_the_undo_mail_says_so_when_no_size_matches_the_disk_exactly(): void {
		$mails   = array();
		$waiting = $this->waiting_disk( $mails );
		$mails   = array();
		$waiting->set( array( 'size_gb' => 70, 'failed_op' => 'update_plan' ) ); // A size with no variation of its own.

		do_action( 'beltoft_services_state_changed', Resource::find( $waiting->id() ), 'ready', 'failed' );

		$this->assertSame( $this->disk['u-50']->get_id(), (int) Licenses::find( (int) $waiting->license()['id'] )['variation_id'] );
		$this->assertCount( 1, $mails );
		$this->assertStringContainsString( '70 GB', $mails[0]['message'], 'What the disk has.' );
		$this->assertStringContainsString( '50 GB', $mails[0]['message'], 'What the licence was put on.' );
		$this->assertStringContainsString( 'next smaller size', $mails[0]['message'] );
	}

	public function test_a_pending_grow_with_no_variation_of_the_real_size_is_metered_at_the_same_price_per_gb(): void {
		$server = $this->server();
		$disk   = $this->usage_disk( $server, 'u-100' );
		$full   = Rates::items( $disk );
		$this->assertSame( 100.0, $full[0]['quantity'] );

		$disk->set( array( 'size_gb' => 70 ) ); // The disk has 70 of the 100 GB the plan asks for; no 70 GB variation exists.
		$part = Rates::items( Resource::find( $disk->id() ) );

		$this->assertSame( 70.0, $part[0]['quantity'] );
		$this->assertEqualsWithDelta( $full[0]['rate'], $part[0]['rate'], 0.0000001, 'The same price per GB, for the GB it really has.' );
	}

	public function test_grow_is_refused_for_another_customer_and_not_for_room(): void {
		$server = $this->server();
		$disk   = $this->usage_disk( $server );
		$this->assertTrue( is_wp_error( UsageDisk::grow( $disk, $this->new_user() ) ) );
		$this->assertSame( $this->disk['u-50']->get_id(), (int) Licenses::find( (int) $disk->license()['id'] )['variation_id'] );
		$this->assertSame( 50.0, Rates::items( Resource::find( $disk->id() ) )[0]['quantity'], 'A refused grow bills nothing more.' );

		// Changed on purpose (stock policy 2026-11-11): the room on the node is not asked, a larger size is offered all the same.
		$this->capacity( 40 );
		$this->assertNotNull( UsageDisk::next( Resource::find( $disk->id() ) ) );
		$this->assertTrue( UsageDisk::grow( Resource::find( $disk->id() ), $this->user ), 'The owner can grow it whatever the room.' );
		$this->assertSame( $this->disk['u-100']->get_id(), (int) Licenses::find( (int) $disk->license()['id'] )['variation_id'] );
		$this->assertSame( 50.0, Rates::items( Resource::find( $disk->id() ) )[0]['quantity'], 'The larger size is billed only once the disk has it.' );
	}

	// -- The invoice and the usage box --------------------------------------------------------------

	public function test_the_disk_is_metered_and_named_with_its_server(): void {
		$server = $this->server();
		$disk   = $this->usage_disk( $server );
		Meter::run( time() );

		$this->assertSame( 'web1', Renewal::label( $disk->id() ) );
		$this->assertSame( 'Extra disk for web1', Renewal::line_subject( 'disk', Renewal::label( $disk->id() ) ) );

		\BeltoftServices\Usage\Estimate::reset();
		$estimate = \BeltoftServices\Usage\Estimate::for_user( $this->user );
		$this->assertNotNull( $estimate );
		$subjects = array();

		foreach ( $estimate['resources'] as $resource ) {
			foreach ( $resource['lines'] as $line ) {
				$subjects[] = $line['subject'];
			}
		}

		$this->assertContains( 'Extra disk for web1', $subjects );
		$this->assertContains( 'web1', $subjects, 'The server line is its name.' );
	}

	/** Tax on at 25 percent in the standard class, the display set to the given mode. */
	private function tax( string $display ): void {
		update_option( 'woocommerce_calc_taxes', 'yes' );
		update_option( 'woocommerce_prices_include_tax', 'no' );
		update_option( 'woocommerce_tax_display_shop', $display );
		update_option( 'woocommerce_tax_display_cart', $display );
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- test fixture.
		$wpdb->query( "DELETE FROM {$wpdb->prefix}woocommerce_tax_rates" );
		\WC_Tax::_insert_tax_rate( array( 'tax_rate_country' => '', 'tax_rate_state' => '', 'tax_rate' => '25.0000', 'tax_rate_name' => 'VAT', 'tax_rate_priority' => 1, 'tax_rate_compound' => 0, 'tax_rate_shipping' => 0, 'tax_rate_order' => 1, 'tax_rate_class' => '' ) );
		\WC_Cache_Helper::invalidate_cache_group( 'taxes' );
	}

	public function test_the_grow_button_difference_follows_the_tax_display(): void {
		$disk = $this->usage_disk( $this->server() );

		$this->tax( 'excl' );
		$this->assertSame( '+€4.40 per month', UsageDisk::price_difference( $disk ) );

		$this->tax( 'incl' );
		$this->assertSame( '+€5.50 per month', UsageDisk::price_difference( $disk ), 'The cap difference 4.40 at 25 percent.' );
	}

	public function test_the_grow_button_difference_is_in_the_usage_accounts_currency_or_not_shown(): void {
		$server = $this->server();
		$disk   = $this->usage_disk( $server );
		$this->account->set_currency( 'DKK' );
		$this->account->save();
		$this->fake_currency_up( 'EUR' );
		$this->fake_runner();
		$this->tax( 'excl' );

		$this->assertSame( '', UsageDisk::price_difference( $disk ), 'No DKK prices on the sizes: no figure, never the euro one.' );

		// The window is opened for the subscription's currency, whatever the page is in (the sizes themselves need prices in that currency).
		$asked = array();
		add_filter(
			'beltoft_services_currency_runner',
			static function ( $runner ) use ( &$asked ) {
				return static function ( string $code, callable $fn ) use ( &$asked ) {
					$asked[] = $code;
					return $fn();
				};
			},
			20
		);
		UsageDisk::price_difference( $disk );

		$this->assertContains( 'DKK', $asked );
		$this->assertSame( 'EUR', \BeltoftServices\Currency::current() );
	}
}

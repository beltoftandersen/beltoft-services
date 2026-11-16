<?php
/** A paid app licence is kept when its server goes and can be used on another server until its paid month is over. FakeClient only. @package BeltoftServices */

namespace BeltoftServices\Tests\Apps;

use BeltoftLicenses\Licenses;
use BeltoftServices\Apps\AppField;
use BeltoftServices\Apps\AppInstall;
use BeltoftServices\Apps\AppLicence;
use BeltoftServices\Apps\AppLicenceCart;
use BeltoftServices\Apps\AppLicenceKeep;
use BeltoftServices\Apps\AppReinstall;
use BeltoftServices\Apps\AppReinstallPaid;
use BeltoftServices\Apps\Apps;
use BeltoftServices\Jobs;
use BeltoftServices\Proxmox\Backup\Backups;
use BeltoftServices\Resource;

require_once __DIR__ . '/PaidAppCase.php';

/**
 * The fixture paid app is docker (see PaidAppCase); the licence is bought with a server, the server is deleted, and a second
 * server of the same customer uses the kept licence. WooCommerce Subscriptions is the real one.
 */
final class AppLicenceKeepTest extends PaidAppCase {

	/** @var array<string, \WC_Product_Variation> */
	private array $tiers = array();

	public function set_up(): void {
		parent::set_up();
		$this->reset_rows();
		AppReinstallPaid::register();
		$this->tiers = $this->tier_product( array( 'basic' => '5', 'pro' => '12' ) );
	}

	public function tear_down(): void {
		$this->reset_rows();
		parent::tear_down();
	}

	/**
	 * A WooCommerce Subscriptions status change can commit the test transaction: the customer and the server records it left must not block the next test.
	 */
	private function reset_rows(): void {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->users} WHERE user_email = %s", 'christian.beltoft@gmail.com' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- test fixture reset.
		$wpdb->query( 'DELETE FROM ' . \BeltoftServices\Proxmox\Vm\Schema::tables()['vms'] ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- test fixture reset.
	}

	private function fresh( Resource $r ): Resource {
		return Resource::find( $r->id() );
	}

	/**
	 * A server with a paid docker licence (provisioned), then the server deleted: the licence is kept.
	 *
	 * @return array{server: Resource, licence: Resource, user: int, sub: int, until: int}
	 */
	private function kept( string $tier = 'basic' ): array {
		$o = $this->ordered( $this->tiers[ $tier ], $tier );
		Jobs::run( $o['licence']->id() );
		$sub   = (int) $o['licence']->license()['subscription_id'];
		$until = (int) wcs_get_subscription( $sub )->get_time( 'next_payment' );
		$this->events = array();

		do_action( 'beltoft_services_state_changed', $this->fresh( $o['server'] ), 'ready', 'deleted' );

		return array( 'server' => $this->fresh( $o['server'] ), 'licence' => $this->fresh( $o['licence'] ), 'user' => $o['user'], 'sub' => $sub, 'until' => $until );
	}

	/**
	 * A second ready server of the same customer, built with docker and the kept licence chosen.
	 *
	 * @param int  $user User.
	 * @param int  $use  Licence resource id chosen, 0 none.
	 * @param int  $vmid VM id.
	 * @param string $app App id of the server.
	 */
	private function second_server( int $user, int $use, int $vmid = 5001, string $app = 'docker' ): Resource {
		$vm      = $this->vm_product();
		$order   = wc_create_order( array( 'customer_id' => $user ) );
		$order->add_product( $vm, 1 );
		$order->save();
		$license = $this->service_license( $vm, array( 'fire' => false, 'user_id' => $user, 'order_id' => $order->get_id(), 'email' => 'christian.beltoft@gmail.com' ) );
		$driver  = \BeltoftServices\Drivers\Registry::get( 'proxmox' );
		$server  = Resource::create( $license, $driver, \BeltoftServices\Product::plan( $vm->get_id() ), \BeltoftServices\Lifecycle::initial_meta( $license, $driver ) );
		$this->assertInstanceOf( Resource::class, $server );
		$this->assertSame( $vmid, \BeltoftServices\Proxmox\Vm\VmRecords::allocate( $server->id(), 'lab', $vmid, $vmid, array(), static fn() => true ) );
		$ip = '192.0.2.' . ( 10 + $vmid - 5000 );
		$this->assertTrue( $server->set( array( 'state' => 'ready', 'desired' => 'ready', 'ip' => $ip, 'app_id' => $app, 'app_version' => '29' ) + ( $use > 0 ? array( AppLicenceKeep::USE => $use ) : array() ) ) );
		\BeltoftServices\Proxmox\Network\IpPool::add( $ip );
		$this->assertNotNull( \BeltoftServices\Proxmox\Network\IpPool::hold( $server->id() ) );
		$this->assertTrue( \BeltoftServices\Proxmox\Vm\VmRecords::move( $server->id(), 'node-a' ) );
		$this->fake->add_vm( array(
			'vmid' => $vmid, 'node' => 'node-a', 'status' => 'running',
			'config' => array( 'description' => \BeltoftServices\Proxmox\Pve\Ownership::tag( $server ), 'memory' => 4096, 'cores' => 2, 'agent' => '1' ),
		) );

		return $this->fresh( $server );
	}

	// -- Keep -------------------------------------------------------------------------------------

	public function test_a_kept_licence_is_unlinked_alive_paid_until_the_end_and_charges_nothing(): void {
		$k = $this->kept();

		$this->assertTrue( AppLicenceKeep::kept( $k['licence'] ) );
		$this->assertSame( 'ready', $k['licence']->get( 'state' ) );
		$this->assertSame( 0, (int) $k['licence']->get( 'server_license' ) );
		$this->assertSame( array(), AppLicence::for_server( $k['server'] ), 'Not linked to the deleted server.' );
		$this->assertSame( 'pending-cancel', wcs_get_subscription( $k['sub'] )->get_status() );
		$this->assertSame( $k['until'], AppLicenceKeep::paid_until( $k['licence'] ) );
		$this->assertTrue( AppLicenceKeep::reusable( $k['licence'] ) );
		$this->assertSame( array(), $this->events, 'The vendor driver is told nothing: its licence stays.' );
		$this->assertFalse( AppLicence::release_due( $k['licence'] ), 'The vendor licence is never released while kept.' );
	}

	public function test_a_kept_licence_whose_subscription_the_customer_made_active_again_can_still_be_used(): void {
		$k = $this->kept();
		wcs_get_subscription( $k['sub'] )->update_status( 'active' );
		$licence = $this->fresh( $k['licence'] );

		$this->assertTrue( AppLicenceKeep::kept( $licence ) );
		$this->assertTrue( AppLicenceKeep::reusable( $licence ), 'An active subscription does not hide the licence.' );
		$this->assertStringNotContainsString( 'not renewed', AppLicenceKeep::kept_text( $licence ) );

		$server = $this->second_server( $k['user'], $licence->id(), 5002 );
		$this->assertNull( AppLicenceKeep::attach( $licence, $server ) );
		$this->assertSame( 'active', wcs_get_subscription( $k['sub'] )->get_status() );
		$this->assertSame( (int) $server->license()['id'], (int) $this->fresh( $licence )->get( 'server_license' ) );
	}

	public function test_a_licence_billed_by_the_hour_is_not_kept(): void {
		$o = $this->ordered( $this->tiers['basic'], 'basic' );
		Jobs::run( $o['licence']->id() );
		$this->assertTrue( Licenses::update( (int) $o['licence']->license()['id'], array( 'source' => 'usage' ) ) );

		$this->assertFalse( AppLicenceKeep::keepable( $this->fresh( $o['licence'] ) ) );
	}

	public function test_a_licence_that_was_not_delivered_is_not_kept(): void {
		$o = $this->ordered( $this->tiers['basic'], 'basic' );
		Jobs::run( $o['licence']->id() );
		$this->assertTrue( $this->fresh( $o['licence'] )->set( array( 'state' => 'provisioning' ) ) );
		$o['licence'] = $this->fresh( $o['licence'] );

		$this->assertFalse( AppLicenceKeep::keepable( $o['licence'] ), 'Not delivered (not ready).' );

		do_action( 'beltoft_services_state_changed', $this->fresh( $o['server'] ), 'ready', 'deleted' );

		$this->assertSame( 'deleted', $this->fresh( $o['licence'] )->get( 'state' ), 'An undelivered licence ends as before.' );
	}

	public function test_a_subscription_with_another_line_is_never_put_in_pending_cancel(): void {
		$o   = $this->ordered( $this->tiers['basic'], 'basic' );
		Jobs::run( $o['licence']->id() );
		$sub = wcs_get_subscription( (int) $o['licence']->license()['subscription_id'] );
		$sub->add_product( $this->vm_product(), 1 );
		$sub->save();

		$this->assertFalse( AppLicenceKeep::keepable( $this->fresh( $o['licence'] ) ) );
		$this->assertSame( 'active', wcs_get_subscription( $sub->get_id() )->get_status() );
	}

	public function test_the_server_delete_never_cancels_the_subscription_early(): void {
		$k = $this->kept();

		$this->assertNotContains( wcs_get_subscription( $k['sub'] )->get_status(), array( 'cancelled', 'expired' ) );
		$this->assertSame( '', (string) $k['licence']->get( AppLicence::ENDED ) );
		$this->assertSame( 'active', AppLicence::state( $k['licence'] ) );
	}

	// -- Use on a new server ------------------------------------------------------------------------

	public function test_the_licence_is_linked_to_the_new_server_and_the_subscription_runs_on_to_the_same_date_without_a_charge(): void {
		$k       = $this->kept( 'pro' );
		$server2 = $this->second_server( $k['user'], $k['licence']->id() );
		$subs    = count( wcs_get_subscriptions( array( 'customer_id' => $k['user'], 'subscriptions_per_page' => -1 ) ) );
		$orders  = count( wc_get_orders( array( 'customer_id' => $k['user'], 'limit' => -1 ) ) );

		$this->assertNull( AppLicenceKeep::attach( $k['licence'], $server2 ) );

		$licence = $this->fresh( $k['licence'] );
		$sub     = wcs_get_subscription( $k['sub'] );
		$this->assertSame( (int) $server2->license()['id'], (int) $licence->get( 'server_license' ) );
		$this->assertFalse( AppLicenceKeep::kept( $licence ) );
		$this->assertSame( 'active', $sub->get_status() );
		$this->assertSame( $k['until'], (int) $sub->get_time( 'next_payment' ), 'The next payment stays the end of the paid period.' );
		$this->assertSame( 0, (int) $sub->get_time( 'end' ) );
		$this->assertSame( $subs, count( wcs_get_subscriptions( array( 'customer_id' => $k['user'], 'subscriptions_per_page' => -1 ) ) ), 'No second subscription.' );
		$this->assertSame( $orders, count( wc_get_orders( array( 'customer_id' => $k['user'], 'limit' => -1 ) ) ), 'No order and no charge.' );
		$this->assertSame( $licence->id(), AppLicence::current( $server2 )->id() );
		$this->assertSame( 'active', AppLicence::state( $licence ) );
	}

	public function test_a_licence_is_attached_at_most_once(): void {
		$k       = $this->kept();
		$first   = $this->second_server( $k['user'], $k['licence']->id(), 5001 );
		$second  = $this->second_server( $k['user'], $k['licence']->id(), 5002 );

		$this->assertNull( AppLicenceKeep::attach( $k['licence'], $first ) );
		$this->assertNotNull( AppLicenceKeep::attach( $k['licence'], $second ), 'The second server finds it no longer kept.' );
		$this->assertSame( (int) $first->license()['id'], (int) $this->fresh( $k['licence'] )->get( 'server_license' ) );
		$this->assertNull( AppLicence::current( $second ) );
	}

	public function test_only_the_owners_own_licence_of_the_same_app_that_is_kept_can_be_used(): void {
		$k     = $this->kept();
		$other = self::factory()->user->create();

		$this->assertNull( AppLicenceKeep::offered( 'docker', $k['licence']->id(), $other ), 'Not another customer\'s.' );
		$this->assertNotNull( AppLicenceKeep::offered( 'docker', $k['licence']->id(), $k['user'] ) );
		$this->assertNull( AppLicenceKeep::offered( 'wordpress', $k['licence']->id(), $k['user'] ), 'Not another app.' );

		$stranger = $this->second_server( $other, 0, 5001 );
		$this->assertStringContainsString( 'another customer', (string) AppLicenceKeep::attach( $k['licence'], $stranger ) );

		$free = $this->second_server( $k['user'], 0, 5002, 'wordpress' );
		$this->assertNotNull( AppLicenceKeep::attach( $k['licence'], $free ), 'The server runs another app.' );
		$this->assertTrue( AppLicenceKeep::kept( $this->fresh( $k['licence'] ) ), 'Every refusal leaves it kept.' );
		$this->assertSame( array(), AppLicenceKeep::usable( $other, 'docker' ) );
		$this->assertCount( 1, AppLicenceKeep::usable( $k['user'], 'docker' ) );
	}

	public function test_a_licence_that_is_linked_or_ended_cannot_be_attached_and_the_install_fails_with_the_ended_path(): void {
		$k       = $this->kept();
		$server2 = $this->second_server( $k['user'], $k['licence']->id() );
		// The subscription ended between the choice and the build.
		wcs_get_subscription( $k['sub'] )->update_status( 'cancelled' );
		$this->assertTrue( Licenses::update( (int) $k['licence']->license()['id'], array( 'status' => Licenses::STATUS_EXPIRED ) ) );

		$result = AppInstall::queue( $server2, $this->fake );

		$this->assertTrue( $result->is_failed() );
		$this->assertStringContainsString( 'can no longer be used', $result->error() );
		$this->assertSame( 0, (int) $this->fresh( $k['licence'] )->get( 'server_license' ), 'Nothing is linked.' );
		$this->assertSame( $k['licence']->id(), (int) $this->fresh( $server2 )->get( AppLicenceKeep::USE ), 'The choice stays: Try again repeats it.' );
	}

	public function test_the_install_attaches_the_chosen_licence_first_and_hands_its_tier_to_the_job(): void {
		$k       = $this->kept( 'pro' );
		$server2 = $this->second_server( $k['user'], $k['licence']->id() );
		$this->assertTrue( $server2->set( array( 'app_state' => 'pending' ) ) );

		$result = AppInstall::queue( $this->fresh( $server2 ), $this->fake );

		$this->assertTrue( $result->is_done(), $result->error() );
		$job = json_decode( (string) $this->fake->guest_file( 5001, Apps::JOB ), true );
		$this->assertSame( 'pro', $job['tier'] );
		$this->assertSame( (int) $server2->license()['id'], (int) $this->fresh( $k['licence'] )->get( 'server_license' ) );
		$this->assertSame( 0, (int) $this->fresh( $server2 )->get( AppLicenceKeep::USE ), 'Cleared once linked.' );
	}

	public function test_an_abandoned_order_leaves_the_licence_kept(): void {
		$k = $this->kept();
		// Choosing it in a cart and abandoning it writes nothing: the cart holds only the value.
		wp_set_current_user( $k['user'] );
		$this->assertNotFalse( WC()->cart->add_to_cart( $this->vm_product()->get_id() ) );
		$this->assertTrue( AppLicenceCart::choose( 'docker', AppLicenceKeep::value_of( $k['licence'] ) ) );
		WC()->cart->empty_cart();

		$licence = $this->fresh( $k['licence'] );
		$this->assertTrue( AppLicenceKeep::kept( $licence ) );
		$this->assertSame( 'pending-cancel', wcs_get_subscription( $k['sub'] )->get_status() );
	}

	public function test_a_tier_switch_still_works_after_the_licence_is_used_again(): void {
		$k       = $this->kept( 'basic' );
		$server2 = $this->second_server( $k['user'], $k['licence']->id() );
		$this->assertNull( AppLicenceKeep::attach( $k['licence'], $server2 ) );
		add_filter( 'woocommerce_subscriptions_can_item_be_switched_by_user', '__return_true' );
		wp_set_current_user( $k['user'] );
		$licence = $this->fresh( $k['licence'] );

		$view = AppLicence::view( $this->fresh( $server2 ) );
		$this->assertSame( 'active', $view['state'] );
		$this->assertCount( 1, $view['tiers'], 'The other tier can be switched to.' );
		$this->assertNotSame( '', Backups::switch_to( $licence, $this->tiers['pro'] ) );
	}

	// -- The end -------------------------------------------------------------------------------------

	public function test_a_kept_licence_whose_subscription_ends_ends_and_a_vendor_driver_hears_of_it(): void {
		$k = $this->kept();
		$heard = array();
		add_action( AppLicence::LOOSE_EVENT, static function ( $licence, $app, $tier, $event ) use ( &$heard ) {
			$heard[] = array( $app, $tier, $event );
		}, 10, 4 );
		wcs_get_subscription( $k['sub'] )->update_status( 'expired' );
		$this->assertTrue( Licenses::update( (int) $k['licence']->license()['id'], array( 'status' => Licenses::STATUS_EXPIRED ) ) );

		Jobs::run( $k['licence']->id() );

		$licence = $this->fresh( $k['licence'] );
		$this->assertSame( 'ended', AppLicence::state( $licence ) );
		$this->assertNotSame( '', (string) $licence->get( AppLicence::ENDED ) );
		$this->assertTrue( AppLicence::release_due( $licence ) );
		$this->assertSame( array( array( 'docker', 'basic', 'cancelled' ) ), $heard );
		$this->assertFalse( AppLicenceKeep::reusable( $licence ) );
		remove_all_actions( AppLicence::LOOSE_EVENT );
	}

	// -- Checkout and the picker -----------------------------------------------------------------------

	public function test_the_order_form_offers_the_kept_licence_beside_the_tiers_without_a_price(): void {
		$k = $this->kept( 'pro' );
		wp_set_current_user( $k['user'] );
		$this->assertNotFalse( WC()->cart->add_to_cart( $this->vm_product()->get_id() ) );

		ob_start();
		AppField::render_classic();
		$html = (string) ob_get_clean();

		$date = wp_date( get_option( 'date_format' ), $k['until'] );
		$this->assertStringContainsString( 'value="use-' . $k['licence']->id() . '"', $html );
		$this->assertStringContainsString( 'Use your licence (Pro), paid until ' . $date, $html );
		$this->assertStringContainsString( 'value="basic"', $html, 'The tiers are still there.' );

		wp_set_current_user( self::factory()->user->create() );
		ob_start();
		AppField::render_classic();
		$this->assertStringNotContainsString( 'Use your licence', (string) ob_get_clean(), 'Another customer sees nothing of it.' );
	}

	public function test_choosing_the_kept_licence_adds_no_licence_line_and_the_server_line_carries_the_choice(): void {
		$k = $this->kept( 'pro' );
		wp_set_current_user( $k['user'] );
		$this->assertNotFalse( WC()->cart->add_to_cart( $this->vm_product()->get_id() ) );
		$this->assertTrue( AppLicenceCart::choose( 'docker', 'basic' ) );
		$this->assertNotNull( AppLicenceCart::line() );

		$this->assertTrue( AppLicenceCart::choose( 'docker', 'use-' . $k['licence']->id() ), 'Choosing the licence takes the tier line out.' );
		$this->assertNull( AppLicenceCart::line() );
		$this->assertSame( 0, AppLicenceCart::lines() );
		$this->assertFalse( AppLicenceCart::choose( 'docker', 'use-' . ( $k['licence']->id() + 999 ) ), 'Not a licence of this customer.' );

		$offer  = AppField::offer();
		$choice = AppField::resolve( $offer, array( 'app' => 'docker', 'version' => '', 'tier' => 'use-' . $k['licence']->id() ), $k['user'] );
		$this->assertIsArray( $choice );
		$this->assertSame( $k['licence']->id(), $choice['use'] );
		$this->assertSame( 'pro', $choice['tier'] );
		$this->assertIsString( AppField::resolve( $offer, array( 'app' => 'docker', 'version' => '', 'tier' => 'use-' . $k['licence']->id() ), self::factory()->user->create() ) );

		$item = new \WC_Order_Item_Product();
		$item->set_product( $this->vm_product() );
		$_POST[ AppField::POST_APP ]  = 'docker';
		$_POST[ AppField::POST_TIER ] = 'use-' . $k['licence']->id();
		do_action( 'woocommerce_checkout_process' );
		$cart_server = \BeltoftServices\Proxmox\Disk\DiskField::cart_server();
		$item->set_product_id( (int) $cart_server['item']['product_id'] );
		AppField::save_on_item( $item );
		$this->assertSame( $k['licence']->id(), (int) $item->get_meta( AppLicenceKeep::ITEM_USE ) );
		$this->assertSame( 'pro', $item->get_meta( AppField::ITEM_TIER ) );
		unset( $_POST[ AppField::POST_APP ], $_POST[ AppField::POST_TIER ] );
	}

	public function test_checkout_refuses_a_use_choice_with_a_licence_line_still_in_the_cart(): void {
		$k = $this->kept();
		wp_set_current_user( $k['user'] );
		$this->assertNotFalse( WC()->cart->add_to_cart( $this->vm_product()->get_id() ) );
		$this->assertTrue( AppLicenceCart::choose( 'docker', 'basic' ) );
		do_action( 'woocommerce_checkout_process' );
		$_POST[ AppField::POST_APP ]  = 'docker';
		$_POST[ AppField::POST_TIER ] = 'use-' . $k['licence']->id();
		$errors = new \WP_Error();

		AppField::validate_classic( array(), $errors );

		$this->assertNotSame( array(), $errors->get_error_messages() );
		unset( $_POST[ AppField::POST_APP ], $_POST[ AppField::POST_TIER ] );
	}

	public function test_the_server_resource_gets_the_choice_from_its_order_line(): void {
		$k       = $this->kept( 'pro' );
		$server2 = $this->second_server( $k['user'], 0, 5001 );
		$license = $server2->license();
		$order   = wc_get_order( (int) $license['order_id'] );

		foreach ( $order->get_items() as $item ) {
			$item->update_meta_data( AppField::ITEM_APP, 'docker' );
			$item->update_meta_data( AppField::ITEM_VERSION, '29' );
			$item->update_meta_data( AppField::ITEM_TIER, 'pro' );
			$item->update_meta_data( AppLicenceKeep::ITEM_USE, $k['licence']->id() );
			$item->save();
		}

		$meta = AppField::initial_meta( array(), $license, \BeltoftServices\Drivers\Registry::get( 'proxmox' ) );

		$this->assertSame( $k['licence']->id(), $meta[ AppLicenceKeep::USE ] );
		$this->assertSame( 'pro', $meta['app_tier'] );

		// Someone else's licence in the line: the server is built without the app.
		foreach ( $order->get_items() as $item ) {
			$item->update_meta_data( AppLicenceKeep::ITEM_USE, $k['licence']->id() + 999 );
			$item->save();
		}

		$this->assertSame( array(), AppField::initial_meta( array(), $license, \BeltoftServices\Drivers\Registry::get( 'proxmox' ) ) );
	}

	// -- The reinstall form -----------------------------------------------------------------------------

	public function test_the_reinstall_with_the_kept_licence_needs_no_checkout_and_changes_nothing_until_the_install(): void {
		$k = $this->kept( 'pro' );
		$s = $this->second_server( $k['user'], 0, 5001, '' );
		$this->assertTrue( $s->set( array( 'app_id' => '', 'app_version' => '', 'plan' => array( 'cluster' => 'lab', 'template' => 9001, 'cores' => 4, 'memory_mb' => 8192, 'disk_gb' => 100 ) ) ) );
		$s    = $this->fresh( $s );
		$args = array( 'confirm' => 'REINSTALL', 'app_version' => 'docker/29', 'app_tier' => 'use-' . $k['licence']->id() );
		wp_set_current_user( $k['user'] );

		$this->assertSame( '', AppReinstallPaid::redirect( '', $s, 'reinstall', $args ), 'No checkout.' );
		$this->assertNull( AppReinstall::check( null, $s, $args ) );

		$meta = AppReinstall::meta( array(), $s, $args );
		$this->assertSame( $k['licence']->id(), $meta[ AppLicenceKeep::USE ] );
		$this->assertSame( 'pro', $meta['app_tier'] );
		$this->assertTrue( AppLicenceKeep::kept( $this->fresh( $k['licence'] ) ), 'Nothing is linked before the install.' );

		$other = $args;
		$other['app_tier'] = 'use-' . ( $k['licence']->id() + 999 );
		$this->assertIsString( AppReinstall::check( null, $s, $other ) );

		$stranger = $this->second_server( self::factory()->user->create(), 0, 5002, '' );
		$this->assertTrue( $stranger->set( array( 'app_id' => '', 'app_version' => '', 'plan' => array( 'cluster' => 'lab', 'template' => 9001, 'cores' => 4, 'memory_mb' => 8192, 'disk_gb' => 100 ) ) ) );
		$this->assertIsString( AppReinstall::check( null, $this->fresh( $stranger ), $args ), 'A server of another customer cannot use it.' );
	}

	public function test_the_reinstall_form_lists_the_kept_licence_first_with_the_new_note(): void {
		$k = $this->kept( 'pro' );
		$s = $this->second_server( $k['user'], 0, 5001, '' );
		$this->assertTrue( $s->set( array( 'app_id' => '', 'app_version' => '', 'plan' => array( 'cluster' => 'lab', 'template' => 9001, 'cores' => 4, 'memory_mb' => 8192, 'disk_gb' => 100 ) ) ) );
		wp_set_current_user( $k['user'] );

		ob_start();
		AppReinstall::fields( $s->id() );
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'value="use-' . $k['licence']->id() . '"', $html );
		$this->assertStringContainsString( 'Use your licence (Pro), paid until', $html );
		$this->assertStringContainsString( 'stays on your account until the end of the month you paid for and can be used on another server', $html );
	}

	// -- The account -----------------------------------------------------------------------------------

	public function test_the_account_shows_what_it_is_until_when_and_the_way_to_a_new_server(): void {
		$k = $this->kept( 'pro' );
		wp_set_current_user( $k['user'] );
		$date = wp_date( get_option( 'date_format' ), $k['until'] );

		ob_start();
		\BeltoftServices\Drivers\Registry::get( 'proxmox-app' )->render_account( $k['licence'] );
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'App licence: Pro', $html );
		$this->assertStringContainsString( 'Paid until ' . $date . '. You can use it on a new server until then. It is not renewed.', $html );
		$this->assertStringContainsString( 'Create a server', $html );
	}
}

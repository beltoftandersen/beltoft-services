<?php
/** Standalone licence keys: catalogue, command, checkout choices, ordering on the fake registrar, delivery, account. @package BeltoftServices */

namespace BeltoftServices\Tests\Licences;

use BeltoftServices\Account\Licences;
use BeltoftServices\Account\ServiceArea;
use BeltoftServices\Licences\LicenceProductCommand;
use BeltoftServices\Crypto;
use BeltoftServices\Domains\FakeClient as Registrar;
use BeltoftServices\Drivers\Registry;
use BeltoftServices\Licences\KeyCatalog;
use BeltoftServices\Licences\KeyDriver;
use BeltoftServices\Licences\KeyField;
use BeltoftServices\Licences\KeyOrder;
use BeltoftServices\Licences\KeyPanel;
use BeltoftServices\Product;
use BeltoftServices\Proxmox\Network\IpPool;
use BeltoftServices\Proxmox\Vm\ProxmoxDriver;
use BeltoftServices\Resource;
use BeltoftServices\Tests\TestCase;

/**
 * No network: the registrar is the fake client with made-up replies.
 */
final class KeyTest extends TestCase {

	private Registrar $op;

	/** @var array<int, array<string, mixed>> Mails sent. */
	private array $mails = array();

	public function set_up(): void {
		parent::set_up();
		$this->mails = array();
		delete_option( Registrar::OPTION );
		$this->op = new Registrar( true );
		add_filter( 'beltoft_services_domains_client', fn() => $this->op );
		add_filter(
			'pre_wp_mail',
			function ( $pre, $args ) {
				$this->mails[] = $args;
				return true;
			},
			10,
			2
		);
		$key = base64_encode( str_repeat( 'k', 32 ) );
		add_filter( 'beltoft_services_key', fn() => $key );
		$this->settings( array( 'admin_email' => 'admin@example.com' ) );
		KeyOrder::register();
	}

	public function tear_down(): void {
		remove_all_filters( 'beltoft_services_domains_client' );
		remove_all_filters( 'pre_wp_mail' );
		remove_all_filters( 'beltoft_services_key' );
		delete_option( Registrar::OPTION );
		parent::tear_down();
	}

	/**
	 * A licence resource, not yet provisioned, with a paid order.
	 *
	 * @param array<string, mixed> $o tier, server (vps|physical), kind, ip, total, user, attach, meta.
	 */
	private function licence( array $o = array() ): Resource {
		$o      += array( 'tier' => 'pro', 'server' => 'vps', 'kind' => 'edition', 'ip' => '203.0.113.10', 'total' => 30.0, 'user' => 0, 'attach' => 0, 'meta' => array() );
		$plan    = array( 'lic_kind' => $o['kind'], 'lic_tier' => $o['tier'], 'lic_server' => 'edition' === $o['kind'] ? $o['server'] : '', 'lic_label' => '' );
		$product = $this->service_product( KeyDriver::ID, $plan, array( 'slug' => 'lic-' . wp_generate_password( 6, false ) ) );
		$user    = $o['user'] > 0 ? (int) $o['user'] : (int) self::factory()->user->create();
		$order   = wc_create_order( array( 'customer_id' => $user ) );
		$order->set_currency( 'EUR' );
		$line = new \WC_Order_Item_Product();
		$line->set_props( array( 'name' => 'Licence', 'quantity' => 1, 'subtotal' => $o['total'], 'total' => $o['total'] ) );
		$order->add_item( $line );
		$order->save();
		$license = $this->service_license( $product, array( 'fire' => false, 'user_id' => $user, 'order_id' => $order->get_id() ) );
		$r       = Resource::create( $license, new KeyDriver(), $plan, $o['meta'] + array( 'lic_ip' => $o['ip'], 'lic_attach' => $o['attach'], 'order_item_id' => (int) $line->get_id() ) );
		$this->assertInstanceOf( Resource::class, $r );

		return $r;
	}

	/**
	 * Runs the provision step and stores its meta as the job runner does.
	 *
	 * @param Resource $r Resource.
	 */
	private function build( Resource $r ) {
		$result = ( new KeyDriver() )->provision( Resource::find( $r->id() ) );

		if ( array() !== $result->meta() ) {
			$r->set( $result->meta() );
		}

		return $result;
	}

	/**
	 * Calls the fake registrar saw.
	 *
	 * @param string $method Method.
	 * @param string $path   Path.
	 * @return array<int, array<string, mixed>>
	 */
	private function calls( string $method, string $path = '' ): array {
		return array_values( array_filter( $this->op->calls(), static fn( $c ) => $c['method'] === $method && ( '' === $path || $c['path'] === $path ) ) );
	}

	// -- Catalogue -------------------------------------------------------------------------------

	public function test_defaults_name_only_the_known_items_and_settings_override_them(): void {
		$items = KeyCatalog::items();
		$this->assertSame( 'PLESK-12-VPS-WEB-HOST-1M', $items['host'] );
		$this->assertSame( 'PLESK-12-WEB-HOST-1M', $items['host-physical'] );
		$this->assertSame( 'CLNX-SOL-1M', $items['cloudlinux-solo'] );
		$this->assertSame( 'CLNX-SH-PRO-1M', $items['cloudlinux-shared-pro'] );
		$this->assertSame( 'ADD-KERNELCARE-1M', $items['kernelcare'], 'The live catalogue item is the default.' );

		$this->settings( array( 'licence_items' => "kernelcare=KC-ITEM-1M\nhost=OTHER-1M\nbad key=x", 'licence_costs' => "kernelcare=2.5\npro-physical=x\nhost=20" ) );
		$items = KeyCatalog::items();
		$this->assertSame( 'KC-ITEM-1M', $items['kernelcare'] );
		$this->assertSame( 'OTHER-1M', $items['host'] );
		$this->assertSame( array( 'kernelcare' => 2.5, 'host' => 20.0 ), KeyCatalog::costs() );
	}

	public function test_every_addon_of_the_price_list_is_in_a_family(): void {
		$this->assertCount( 4, KeyCatalog::keys_of( 'cloudlinux' ) );
		$this->assertCount( 9, KeyCatalog::keys_of( 'addons' ) );
		$this->assertCount( 6, KeyCatalog::keys_of( 'plesk' ) );
		$this->assertTrue( KeyCatalog::is_edition( 'host-physical' ) );
		$this->assertTrue( KeyCatalog::is_physical( 'host-physical' ) );
		$this->assertFalse( KeyCatalog::is_physical( 'host' ) );
		$this->assertFalse( KeyCatalog::is_edition( 'kernelcare' ) );
	}

	public function test_the_driver_is_registered_with_its_kind(): void {
		$this->assertArrayHasKey( 'licence-key', Registry::all() );
		$this->assertContains( 'lickey', Registry::kinds() );
	}

	// -- The command -----------------------------------------------------------------------------

	public function test_prices_are_parsed_per_family(): void {
		$this->assertSame( array( 'pro' => '30', 'pro-physical' => '35.5' ), LicenceProductCommand::parse_prices( 'pro:30, pro-physical:35.50', 'plesk' ) );
		$this->assertWPError( LicenceProductCommand::parse_prices( 'kernelcare:4', 'plesk' ) );
		$this->assertWPError( LicenceProductCommand::parse_prices( 'pro:free', 'plesk' ) );
		$this->assertWPError( LicenceProductCommand::parse_prices( 'pro:0', 'plesk' ) );
	}

	public function test_each_family_makes_a_draft_product_with_one_variation_per_licence(): void {
		$expected = array( 'plesk' => 6, 'cloudlinux' => 4, 'addons' => 9 );

		foreach ( $expected as $family => $count ) {
			$this->assertSame( 0, LicenceProductCommand::existing( $family ) );
			$id      = LicenceProductCommand::create( $family, array( 'kernelcare' => '3.9', 'cloudlinux-solo' => '6.9', 'host' => '60' ) );
			$product = wc_get_product( $id );
			$this->assertInstanceOf( \WC_Product_Variable_Subscription::class, $product );
			$this->assertSame( 'draft', $product->get_status() );
			$this->assertSame( KeyDriver::ID, get_post_meta( $id, Product::META_DRIVER, true ) );
			$this->assertSame( $id, LicenceProductCommand::existing( $family ) );
			$this->assertCount( $count, $product->get_children() );
		}

		$plesk = wc_get_product( LicenceProductCommand::existing( 'plesk' ) );
		$seen  = array();

		foreach ( $plesk->get_children() as $vid ) {
			$plan = Product::plan( $plesk->get_id(), (int) $vid );
			$seen[ $plan['lic_tier'] . '/' . $plan['lic_server'] ] = (string) get_post_meta( $vid, '_subscription_price', true );
			$this->assertSame( 'edition', $plan['lic_kind'] );
		}

		$this->assertSame( '60', $seen['host/vps'] );
		$this->assertArrayHasKey( 'host/physical', $seen );
		$this->assertArrayHasKey( 'admin/physical', $seen );
	}

	// -- Checkout choices ------------------------------------------------------------------------

	public function test_an_edition_needs_a_public_address_and_the_server_type_confirmed(): void {
		$user = (int) self::factory()->user->create();
		$this->assertWPError( KeyField::resolve( array( 'ip' => 'not an ip', 'confirm' => '1' ), $user, 'pro' ) );
		$this->assertWPError( KeyField::resolve( array( 'ip' => '10.0.0.5', 'confirm' => '1' ), $user, 'pro' ), 'A private address is refused.' );
		$this->assertWPError( KeyField::resolve( array( 'ip' => '2001:db8::1', 'confirm' => '1' ), $user, 'pro' ), 'IPv6 is refused.' );
		$this->assertWPError( KeyField::resolve( array( 'ip' => '203.0.113.10' ), $user, 'pro' ), 'The server type must be confirmed.' );
		$this->assertWPError( KeyField::resolve( array( 'ip' => '203.0.113.10', 'confirm' => '1' ), $user, '' ) );

		$ok = KeyField::resolve( array( 'ip' => ' 203.0.113.10 ', 'confirm' => '1' ), $user, 'pro' );
		$this->assertSame( array( 'ip' => '203.0.113.10', 'attach' => 0, 'key' => 'pro', 'server' => 'vps' ), $ok );
		$ok = KeyField::resolve( array( 'ip' => '203.0.113.10', 'confirm' => '1' ), $user, 'pro-physical' );
		$this->assertSame( 'physical', $ok['server'] );
	}

	public function test_a_second_licence_for_an_address_is_refused(): void {
		$user = (int) self::factory()->user->create();
		$r    = $this->licence( array( 'user' => $user ) );
		$this->assertWPError( KeyField::resolve( array( 'ip' => '203.0.113.10', 'confirm' => '1' ), $user, 'host' ) );
		$this->assertIsArray( KeyField::resolve( array( 'ip' => '203.0.113.11', 'confirm' => '1' ), $user, 'host' ) );
		// An add-on is another thing, once; the same add-on twice is refused.
		$this->assertIsArray( KeyField::resolve( array( 'ip' => '203.0.113.10' ), $user, 'kernelcare' ) );
		$this->licence( array( 'user' => $user, 'kind' => 'addon', 'tier' => 'kernelcare' ) );
		$this->assertWPError( KeyField::resolve( array( 'ip' => '203.0.113.10' ), $user, 'kernelcare' ) );
		$this->assertInstanceOf( Resource::class, $r );
	}

	public function test_an_address_of_ours_is_only_for_its_owners_addons(): void {
		IpPool::add( '198.51.100.20' );
		$owner = (int) self::factory()->user->create();
		$this->assertWPError( KeyField::resolve( array( 'ip' => '198.51.100.20', 'confirm' => '1' ), $owner, 'pro' ), 'A Plesk edition for our servers is bought with the server.' );
		$this->assertWPError( KeyField::resolve( array( 'ip' => '198.51.100.20' ), $owner, 'kernelcare' ), 'Not the customer\'s server.' );
	}

	public function test_cloudlinux_on_our_server_needs_the_almalinux_version(): void {
		$user    = (int) self::factory()->user->create();
		$product = $this->service_product( 'proxmox', array(), array( 'slug' => 'srv-' . wp_generate_password( 5, false ) ) );
		$license = $this->service_license( $product, array( 'fire' => false, 'user_id' => $user ) );
		$server  = Resource::create( $license, new ProxmoxDriver(), array(), array( 'app_id' => 'plesk', 'app_version' => '18', 'state' => 'ready' ) );
		$this->assertInstanceOf( Resource::class, $server );
		IpPool::add( '198.51.100.30' );
		$this->assertTrue( IpPool::take( '198.51.100.30', $server->id() ) );

		$source = 'own:' . (int) $license['id'];
		$this->assertArrayHasKey( (int) $license['id'], KeyField::own_servers( $user ) );
		$this->assertFalse( KeyField::server_is_alma( Resource::find( $server->id() ) ) );
		$refused = KeyField::resolve( array( 'source' => $source ), $user, 'cloudlinux-solo' );
		$this->assertWPError( $refused );
		$this->assertSame( 'lickey_alma', $refused->get_error_code() );
		$this->assertIsArray( KeyField::resolve( array( 'source' => $source ), $user, 'kernelcare' ), 'Other add-ons do not need AlmaLinux.' );

		$server->set( array( 'app_version' => '18-alma' ) );
		$this->assertTrue( KeyField::server_is_alma( Resource::find( $server->id() ) ) );
		$ok = KeyField::resolve( array( 'source' => $source ), $user, 'cloudlinux-solo' );
		$this->assertSame( '198.51.100.30', $ok['ip'] );
		$this->assertSame( (int) $license['id'], $ok['attach'] );
		$this->assertWPError( KeyField::resolve( array( 'source' => $source ), (int) self::factory()->user->create(), 'cloudlinux-solo' ), 'Another customer cannot pick the server.' );
	}

	// -- Ordering --------------------------------------------------------------------------------

	public function test_an_edition_is_ordered_once_bound_to_the_address_and_its_key_is_kept_encrypted(): void {
		$r      = $this->licence();
		$result = $this->build( $r );
		$this->assertTrue( $result->is_done(), $result->error() );

		$r     = Resource::find( $r->id() );
		$posts = $this->calls( 'POST', '/licenses/plesk' );
		$this->assertCount( 1, $posts );
		$this->assertSame( array( 'PLESK-12-VPS-WEB-PRO-1M' ), $posts[0]['body']['items'] );
		$this->assertSame( '203.0.113.10', $posts[0]['body']['ip_address_binding'] );
		$this->assertTrue( $posts[0]['body']['restrict_ip_binding'] );
		$this->assertSame( 1, $posts[0]['body']['period'] );
		$this->assertArrayNotHasKey( 'parent_key_id', $posts[0]['body'] );
		$this->assertSame( 'licence ' . (int) $r->license()['id'] . ' 203.0.113.10', $posts[0]['body']['title'] );
		$this->assertSame( 'ready', $r->get( 'lic_state' ) );
		$this->assertSame( 'file', $r->get( 'lic_key_kind' ) );
		$stored = (string) $r->get( 'lic_key' );
		$this->assertNotSame( '', $stored );
		$this->assertStringNotContainsString( '<key', $stored, 'Stored encrypted.' );
		$this->assertStringContainsString( '<key', KeyOrder::key_text( $r ) );
		$this->assertStringContainsString( 'key', (string) $r->get( 'lic_shape' ) );

		$this->build( $r );
		$this->assertCount( 1, $this->calls( 'POST', '/licenses/plesk' ), 'Run again, no second licence.' );
	}

	public function test_a_physical_server_gets_the_physical_item(): void {
		$r = $this->licence( array( 'tier' => 'host', 'server' => 'physical', 'total' => 60.0 ) );
		$this->assertSame( 'host-physical', KeyOrder::key_of( $r ) );
		$this->assertTrue( $this->build( $r )->is_done() );
		$this->assertSame( array( 'PLESK-12-WEB-HOST-1M' ), $this->calls( 'POST', '/licenses/plesk' )[0]['body']['items'] );
	}

	public function test_a_crash_after_the_order_finds_the_licence_by_its_title(): void {
		$r     = $this->licence();
		$title = 'licence ' . (int) $r->license()['id'] . ' 203.0.113.10';
		$this->op->request( 'POST', '/licenses/plesk', array( 'title' => $title, 'items' => array( 'PLESK-12-VPS-WEB-PRO-1M' ), 'period' => 1, 'ip_address_binding' => '203.0.113.10' ) );
		$before = count( $this->calls( 'POST', '/licenses/plesk' ) );
		$this->assertTrue( $this->build( $r )->is_done() );
		$this->assertCount( $before, $this->calls( 'POST', '/licenses/plesk' ), 'The order that went through is found, not repeated.' );
		$this->assertSame( 1001, (int) Resource::find( $r->id() )->get( 'lic_key_id' ) );
	}

	public function test_a_licence_paid_below_its_cost_is_not_ordered(): void {
		$this->settings( array( 'licence_costs' => 'pro=30' ) );
		$r      = $this->licence( array( 'total' => 20.0 ) );
		$result = $this->build( $r );
		$this->assertTrue( $result->is_failed() );
		$this->assertSame( array(), $this->calls( 'POST', '/licenses/plesk' ) );
		$this->assertNotSame( array(), $this->mails, 'An admin is told.' );

		$ok = $this->licence( array( 'total' => 30.0 ) );
		$this->assertTrue( $this->build( $ok )->is_done() );
		$this->assertCount( 1, $this->calls( 'POST', '/licenses/plesk' ) );
	}

	public function test_an_addon_without_an_item_waits_and_tells_the_admin_and_orders_nothing(): void {
		$drop = static function ( $items ) { unset( $items['imunify360-1'] ); return $items; };
		add_filter( 'beltoft_services_licence_items', $drop );
		$r      = $this->licence( array( 'kind' => 'addon', 'tier' => 'imunify360-1', 'total' => 12.0 ) );
		$result = $this->build( $r );
		$this->assertTrue( $result->is_retry() );
		$this->assertSame( array(), $this->calls( 'POST', '/licenses/plesk' ) );
		$this->assertNotSame( array(), $this->mails );

		remove_filter( 'beltoft_services_licence_items', $drop );
		$this->settings( array( 'licence_items' => 'imunify360-1=IMUNIFY-ITEM-1M' ) );
		$this->assertTrue( $this->build( $r )->is_done() );
		$this->assertSame( array( 'IMUNIFY-ITEM-1M' ), $this->calls( 'POST', '/licenses/plesk' )[0]['body']['items'] );
	}

	public function test_an_addon_is_attached_to_the_plesk_licence_of_the_same_customer_and_address(): void {
		$user    = (int) self::factory()->user->create();
		$edition = $this->licence( array( 'user' => $user ) );
		$this->assertTrue( $this->build( $edition )->is_done() );
		$parent = (int) Resource::find( $edition->id() )->get( 'lic_key_id' );

		$addon = $this->licence( array( 'user' => $user, 'kind' => 'addon', 'tier' => 'cloudlinux-admin', 'total' => 14.0 ) );
		$this->assertTrue( $this->build( $addon )->is_done() );
		$posts = $this->calls( 'POST', '/licenses/plesk' );
		$this->assertSame( array( 'CLNX-ADM-1M' ), $posts[1]['body']['items'] );
		$this->assertSame( $parent, $posts[1]['body']['parent_key_id'] );
		$this->assertSame( $parent, (int) Resource::find( $addon->id() )->get( 'lic_parent' ) );
	}

	public function test_an_addon_for_an_address_without_plesk_is_ordered_on_its_own(): void {
		$addon = $this->licence( array( 'kind' => 'addon', 'tier' => 'cloudlinux-solo', 'total' => 7.0 ) );
		$this->assertTrue( $this->build( $addon )->is_done() );
		$body = $this->calls( 'POST', '/licenses/plesk' )[0]['body'];
		$this->assertArrayNotHasKey( 'parent_key_id', $body );
		$this->assertSame( '203.0.113.10', $body['ip_address_binding'] );
	}

	public function test_an_addon_waits_for_a_plesk_licence_that_is_still_being_ordered(): void {
		$user    = (int) self::factory()->user->create();
		$edition = $this->licence( array( 'user' => $user ) );
		$edition->set( array( 'lic_state' => 'requested' ) );
		$addon  = $this->licence( array( 'user' => $user, 'kind' => 'addon', 'tier' => 'cloudlinux-solo', 'total' => 7.0 ) );
		$result = $this->build( $addon );
		$this->assertFalse( $result->is_done() );
		$this->assertSame( array(), $this->calls( 'POST', '/licenses/plesk' ) );
	}

	// -- Delivery --------------------------------------------------------------------------------

	public function test_a_key_that_is_not_delivered_leaves_the_licence_waiting_and_staff_are_told_with_the_field_names(): void {
		for ( $i = 0; $i < 14; $i++ ) {
			$this->op->reply( 'GET', '/licenses/plesk/key/1001', array() );
		}

		$r      = $this->licence( array( 'kind' => 'addon', 'tier' => 'cloudlinux-solo', 'total' => 7.0 ) );
		$result = $this->build( $r );
		$this->assertTrue( $result->is_done(), 'A missing key never holds the licence back.' );
		$r = Resource::find( $r->id() );
		$this->assertSame( 'waiting', $r->get( 'lic_state' ) );
		$this->assertSame( '', (string) $r->get( 'lic_key' ) );
		$this->assertStringContainsString( 'licence:', (string) $r->get( 'lic_shape' ) );
		$this->assertSame( 'Being prepared', KeyPanel::status_text( $r ) );

		for ( $i = 0; $i < 11; $i++ ) {
			KeyOrder::run_key( $r->id() );
		}

		$this->assertSame( array(), $this->mails );
		KeyOrder::run_key( $r->id() );
		$this->assertCount( 1, $this->mails, 'Staff are told once after twelve asks.' );
		$this->assertStringContainsString( 'Fields answered', (string) $this->mails[0]['message'] );
		KeyOrder::run_key( $r->id() );
		$this->assertCount( 1, $this->mails );
	}

	public function test_a_late_key_is_picked_up_by_the_job_and_an_activation_code_is_kept_as_a_code(): void {
		$this->op->reply( 'GET', '/licenses/plesk/key/1001', array() );
		$r = $this->licence( array( 'kind' => 'addon', 'tier' => 'cloudlinux-solo', 'total' => 7.0 ) );
		$this->build( $r );
		$this->assertSame( 'waiting', Resource::find( $r->id() )->get( 'lic_state' ) );

		$this->op->reply( 'GET', '/licenses/plesk/key/1001', array() );
		$this->op->reply( 'GET', '/licenses/plesk/1001', array( 'key_id' => 1001, 'activation_code' => '1231-2b48feedabcdef' ) );
		KeyOrder::run_key( $r->id() );
		$r = Resource::find( $r->id() );
		$this->assertSame( 'ready', $r->get( 'lic_state' ) );
		$this->assertSame( 'code', $r->get( 'lic_key_kind' ) );
		$this->assertSame( '1231-2b48feedabcdef', KeyOrder::key_text( $r ) );
	}

	public function test_a_licence_that_waits_for_its_key_is_found_by_the_watchdog_until_the_asks_run_out(): void {
		$this->op->reply( 'GET', '/licenses/plesk/key/1001', array() );
		$r = $this->licence( array( 'kind' => 'addon', 'tier' => 'cloudlinux-solo', 'total' => 7.0 ) );
		$this->build( $r );
		as_unschedule_all_actions( KeyOrder::KEY_HOOK );

		$this->assertSame( array( array( $r->id() ) ), KeyOrder::key_due() );
		$this->assertSame( 1, \BeltoftServices\Background::watchdog() );
		$this->assertSame( 0, \BeltoftServices\Background::watchdog(), 'Never queued twice.' );

		// The business limit (three times the asks) stays the owner of giving up.
		Resource::find( $r->id() )->set( array( 'lic_tries' => 36 ) );
		$this->assertSame( array(), KeyOrder::key_due() );
	}

	// -- Change and end --------------------------------------------------------------------------

	public function test_a_plan_change_switches_the_item_and_fetches_the_key_again(): void {
		$r = $this->licence( array( 'total' => 60.0 ) );
		$this->build( $r );
		$r    = Resource::find( $r->id() );
		$plan = (array) $r->get( 'plan' );
		$r->set( array( 'plan' => array_merge( $plan, array( 'lic_tier' => 'host' ) ) ) );
		$this->assertTrue( ( new KeyDriver() )->update_plan( Resource::find( $r->id() ) )->is_done() );
		$puts = $this->calls( 'PUT' );
		$this->assertCount( 1, $puts );
		$this->assertSame( array( 'PLESK-12-VPS-WEB-HOST-1M' ), $puts[0]['body']['items'] );
		$this->assertSame( 'PLESK-12-VPS-WEB-HOST-1M', Resource::find( $r->id() )->get( 'lic_item' ) );
		$this->assertNotSame( '', (string) Resource::find( $r->id() )->get( 'lic_key' ) );
	}

	public function test_pause_keeps_the_licence_and_delete_removes_it_once(): void {
		$r = $this->licence();
		$this->build( $r );
		$r = Resource::find( $r->id() );
		$this->assertTrue( ( new KeyDriver() )->pause( $r )->is_done() );
		$this->assertSame( array(), $this->calls( 'DELETE' ) );

		$this->assertTrue( ( new KeyDriver() )->delete( $r )->is_done() );
		$this->assertCount( 1, $this->calls( 'DELETE' ) );
		$r = Resource::find( $r->id() );
		$this->assertSame( '', (string) $r->get( 'lic_key' ) );
		$this->assertGreaterThan( 0, (int) $r->get( 'lic_deleted' ) );
		$this->assertTrue( ( new KeyDriver() )->delete( $r )->is_done() );
		$this->assertCount( 1, $this->calls( 'DELETE' ), 'Nothing left to delete.' );
	}

	public function test_a_licence_that_is_gone_at_the_vendor_counts_as_deleted_and_a_failure_is_repeated(): void {
		$r = $this->licence();
		$this->build( $r );
		$r = Resource::find( $r->id() );
		$this->op->reply( 'DELETE', '/licenses/plesk/' . (int) $r->get( 'lic_key_id' ), new \WP_Error( 'op_3108', 'gone', array( 'status' => 404 ) ) );
		$this->assertTrue( KeyOrder::release( $r ) );

		$r2 = $this->licence( array( 'ip' => '203.0.113.11' ) );
		$this->build( $r2 );
		$r2 = Resource::find( $r2->id() );
		$this->op->reply( 'DELETE', '/licenses/plesk/' . (int) $r2->get( 'lic_key_id' ), new \WP_Error( 'http_500', 'down', array( 'status' => 500 ) ) );
		$this->assertFalse( KeyOrder::release( $r2 ) );
		$this->assertGreaterThan( 0, (int) Resource::find( $r2->id() )->get( 'lic_key_id' ), 'Kept until the delete worked.' );
		$this->assertTrue( KeyOrder::release( Resource::find( $r2->id() ) ) );
	}

	public function test_delete_is_not_done_while_the_vendor_licence_is_still_there(): void {
		$r = $this->licence();
		$this->build( $r );
		$r = Resource::find( $r->id() );
		$this->op->reply( 'DELETE', '/licenses/plesk/' . (int) $r->get( 'lic_key_id' ), new \WP_Error( 'http_500', 'down', array( 'status' => 500 ) ) );

		$result = ( new KeyDriver() )->delete( $r );

		$this->assertFalse( $result->is_done(), 'The resource is not closed while the vendor licence keeps billing.' );
		$this->assertTrue( $result->is_retry() );
	}

	public function test_used_up_key_asks_do_not_stop_the_deletion_of_an_ended_licence(): void {
		$r = $this->licence();
		$this->build( $r );
		$r = Resource::find( $r->id() );
		$r->set( array( 'lic_tries' => 36 ) );
		$this->op->reply( 'DELETE', '/licenses/plesk/' . (int) $r->get( 'lic_key_id' ), new \WP_Error( 'http_500', 'down', array( 'status' => 500 ) ) );
		\BeltoftLicenses\Licenses::update( (int) $r->license()['id'], array( 'status' => \BeltoftLicenses\Licenses::STATUS_REVOKED ) );

		$this->assertFalse( KeyOrder::release( Resource::find( $r->id() ) ) );
		$this->assertTrue( \BeltoftServices\Jobs::pending_action( KeyOrder::SYNC_HOOK, array( $r->id() ) ), 'The deletion is retried.' );
		$this->assertSame( array( array( $r->id() ) ), KeyOrder::sync_due() );
	}

	// -- Account ---------------------------------------------------------------------------------

	public function test_the_owner_sees_the_key_and_nobody_else_does(): void {
		$user = (int) self::factory()->user->create();
		$r    = $this->licence( array( 'user' => $user ) );
		$this->build( $r );
		$r = Resource::find( $r->id() );

		wp_set_current_user( $user );
		ob_start();
		( new KeyDriver() )->render_account( $r );
		$html = (string) ob_get_clean();
		$this->assertStringContainsString( '203.0.113.10', $html );
		$this->assertStringContainsString( 'A virtual server (VPS)', $html );
		$this->assertStringContainsString( htmlspecialchars( KeyOrder::key_text( $r ), ENT_QUOTES ), $html );

		wp_set_current_user( (int) self::factory()->user->create() );
		ob_start();
		( new KeyDriver() )->render_account( $r );
		$this->assertSame( '', (string) ob_get_clean() );

		wp_set_current_user( $user );
		ob_start();
		( new KeyDriver() )->render_admin( $r );
		$admin = (string) ob_get_clean();
		$this->assertStringContainsString( (string) $r->get( 'lic_key_id' ), $admin );
		$this->assertStringNotContainsString( 'FAKE-KEY', $admin, 'Staff never see the key.' );
	}

	public function test_a_licence_that_is_being_prepared_says_so_and_an_ended_one_shows_no_key(): void {
		$user = (int) self::factory()->user->create();
		$r    = $this->licence( array( 'user' => $user ) );
		wp_set_current_user( $user );
		ob_start();
		( new KeyDriver() )->render_account( $r );
		$this->assertStringContainsString( 'being prepared', (string) ob_get_clean() );

		$this->build( $r );
		$r = Resource::find( $r->id() );
		$r->set( array( 'state' => 'deleted' ) );
		ob_start();
		( new KeyDriver() )->render_account( Resource::find( $r->id() ) );
		$html = (string) ob_get_clean();
		$this->assertStringContainsString( 'has ended', $html );
		$this->assertStringNotContainsString( 'FAKE-KEY', $html );
	}

	public function test_the_licences_area_lists_the_driver_and_names_a_row(): void {
		$area = ServiceArea::get( Licences::class );
		$this->assertSame( 'licences', $area->slug() );
		$r = $this->licence( array( 'tier' => 'host', 'total' => 60.0 ) );
		$this->assertTrue( $area->is_member( $r->license() ) );
		$this->assertContains( $area, ServiceArea::all() );
		$row = $area->row( array( 'license' => $r->license(), 'product' => 'Plesk licence' ), $r );
		$this->assertSame( 'Plesk Web Host (virtual server)', $row['name'] );
		$this->assertSame( '203.0.113.10', $row['details'] );
	}

	public function test_two_lines_of_one_order_go_to_two_licences_one_each(): void {
		$product = $this->service_product( KeyDriver::ID, array( 'lic_kind' => 'edition', 'lic_tier' => 'pro', 'lic_server' => 'physical' ), array( 'slug' => 'lic-two' ) );
		$user    = (int) self::factory()->user->create();
		$order   = wc_create_order( array( 'customer_id' => $user ) );

		foreach ( array( '203.0.113.71', '203.0.113.72' ) as $ip ) {
			$line = new \WC_Order_Item_Product();
			$line->set_props( array( 'product_id' => $product->get_id(), 'name' => 'Licence', 'quantity' => 1, 'total' => 30 ) );
			$line->add_meta_data( KeyField::ITEM_META, array( 'ip' => $ip, 'attach' => 0, 'key' => 'pro-physical', 'server' => 'physical' ), true );
			$order->add_item( $line );
		}

		$order->save();
		$first  = $this->service_license( $product, array( 'fire' => false, 'user_id' => $user, 'order_id' => $order->get_id() ) );
		$second = $this->service_license( $product, array( 'fire' => false, 'user_id' => $user, 'order_id' => $order->get_id() ) );

		$one = KeyField::initial_meta( array(), $first, new KeyDriver() );
		$this->assertSame( '203.0.113.71', $one['lic_ip'] );
		Resource::create( $first, new KeyDriver(), array(), $one );
		$two = KeyField::initial_meta( array(), $second, new KeyDriver() );
		$this->assertSame( '203.0.113.72', $two['lic_ip'] );
		$this->assertNotSame( $one['order_item_id'], $two['order_item_id'] );
		Resource::create( $second, new KeyDriver(), array(), $two );

		$third = $this->service_license( $product, array( 'fire' => false, 'user_id' => $user, 'order_id' => $order->get_id() ) );
		$this->assertSame( array(), KeyField::initial_meta( array(), $third, new KeyDriver() ), 'No line is left for a third licence.' );
	}

	public function test_the_choices_on_the_order_line_become_the_resources_meta(): void {
		$product = $this->service_product( KeyDriver::ID, array( 'lic_kind' => 'edition', 'lic_tier' => 'pro', 'lic_server' => 'physical' ), array( 'slug' => 'lic-meta' ) );
		$user    = (int) self::factory()->user->create();
		$order   = wc_create_order( array( 'customer_id' => $user ) );
		$line    = new \WC_Order_Item_Product();
		$line->set_props( array( 'product_id' => $product->get_id(), 'name' => 'Licence', 'quantity' => 1, 'total' => 30 ) );
		$line->add_meta_data( KeyField::ITEM_META, array( 'ip' => '203.0.113.77', 'attach' => 0, 'key' => 'pro-physical', 'server' => 'physical' ), true );
		$order->add_item( $line );
		$order->save();
		$license = $this->service_license( $product, array( 'fire' => false, 'user_id' => $user, 'order_id' => $order->get_id() ) );

		$meta = KeyField::initial_meta( array(), $license, new KeyDriver() );
		$this->assertSame( '203.0.113.77', $meta['lic_ip'] );
		$this->assertSame( 'physical', $meta['lic_server'] );
		$this->assertSame( 0, $meta['lic_attach'] );
		$this->assertGreaterThan( 0, $meta['order_item_id'] );
		$this->assertSame( array( 'x' => 1 ), KeyField::initial_meta( array( 'x' => 1 ), $license, new ProxmoxDriver() ), 'Another driver is left alone.' );
	}

	public function test_crypto_is_available_in_the_tests(): void {
		$this->assertTrue( Crypto::available() );
	}

	public function test_the_checkout_step_stops_on_an_unticked_server_type_box_as_on_an_empty_address(): void {
		$plan    = array( 'lic_kind' => 'edition', 'lic_tier' => 'admin', 'lic_server' => 'vps', 'lic_label' => '' );
		$product = $this->service_product( KeyDriver::ID, $plan, array( 'slug' => 'lic-cart-' . wp_generate_password( 6, false ) ) );
		WC()->cart->empty_cart();
		if ( ! WC()->cart->add_to_cart( $product->get_id() ) ) {
			$this->markTestSkipped( 'The fixture product cannot be put in the cart here.' );
		}

		ob_start();
		\BeltoftServices\Licences\KeyField::render();
		$html = (string) ob_get_clean();
		WC()->cart->empty_cart();

		$this->assertMatchesRegularExpression( '#<p class="form-row form-row-wide validate-required"><label><input type="checkbox" class="input-checkbox" name="[^"]*\[confirm\]"#', $html );
		$this->assertMatchesRegularExpression( '#<p class="form-row form-row-wide validate-required"><label for="[^"]*_ip">#', $html );
	}

	/** A licence whose paid order is in DKK. */
	private function dkk_licence( float $total ): Resource {
		$r     = $this->licence( array( 'total' => $total ) );
		$order = wc_get_order( (int) $r->license()['order_id'] );
		$order->set_currency( 'DKK' );
		$order->save();

		return Resource::find( $r->id() );
	}

	public function test_a_licence_paid_in_another_currency_is_ordered_only_when_it_covers_the_cost_at_the_guard_rate(): void {
		add_filter( 'pre_wp_mail', '__return_true' );
		$this->settings( array( 'licence_costs' => 'pro=30', 'currency_guard_rates' => '', 'admin_email' => 'christian.beltoft@gmail.com' ) );
		$none = $this->dkk_licence( 300.0 );

		$this->assertStringContainsString( 'not in EUR', (string) \BeltoftServices\Licences\KeyOrder::spend_problem( $none, 'pro' ), 'No guard rate: refused, never 1 to 1.' );
		$this->assertTrue( $this->build( $none )->is_failed() );
		$this->assertSame( array(), $this->calls( 'POST', '/licenses/plesk' ) );

		$this->settings( array( 'currency_guard_rates' => 'DKK=8' ) );
		$under = $this->dkk_licence( 200.0 );
		$this->assertStringContainsString( 'below the Openprovider cost', (string) \BeltoftServices\Licences\KeyOrder::spend_problem( $under, 'pro' ), '200 DKK is 25 euro at the guard rate: under 30.' );
		$this->assertTrue( $this->build( $under )->is_failed() );
		$this->assertSame( array(), $this->calls( 'POST', '/licenses/plesk' ) );

		$ok = $this->dkk_licence( 260.0 );
		$this->assertNull( \BeltoftServices\Licences\KeyOrder::spend_problem( $ok, 'pro' ) );
		$this->assertTrue( $this->build( $ok )->is_done() );
		$this->assertCount( 1, $this->calls( 'POST', '/licenses/plesk' ) );
		remove_filter( 'pre_wp_mail', '__return_true' );
	}

	public function test_the_deletion_list_holds_only_ended_licences_that_still_have_a_vendor_key(): void {
		$live = $this->licence();
		$this->build( $live );
		$gone = $this->licence( array( 'ip' => '203.0.113.20' ) );
		$this->build( $gone );
		$this->assertSame( array(), KeyOrder::sync_due(), 'A live licence is not listed (and not read).' );

		$gone = Resource::find( $gone->id() );
		$gone->set( array( 'state' => 'deleting', 'desired' => 'deleted' ) );
		$this->assertSame( array( array( $gone->id() ) ), KeyOrder::sync_due() );

		// The vendor deleted it: the key id is cleared, the licence is finished and no longer listed.
		$this->assertTrue( KeyOrder::release( Resource::find( $gone->id() ) ) );
		$this->assertSame( array(), KeyOrder::sync_due() );
	}

	public function test_a_failed_plan_change_on_a_paid_licence_never_deletes_its_vendor_licence(): void {
		$r = $this->licence();
		$this->build( $r );
		$r = Resource::find( $r->id() );
		$r->set( array( 'state' => 'failed', 'failed_op' => 'update_plan' ) );

		$this->assertSame( array(), KeyOrder::sync_due() );
		KeyOrder::run_sync( $r->id() );
		$this->assertSame( array(), $this->calls( 'DELETE' ) );
		$this->assertGreaterThan( 0, (int) Resource::find( $r->id() )->get( 'lic_key_id' ) );
	}

	public function test_a_licence_whose_provision_failed_after_the_order_is_still_deleted(): void {
		$r = $this->licence();
		$this->build( $r );
		$r = Resource::find( $r->id() );
		$r->set( array( 'state' => 'failed', 'failed_op' => 'provision' ) );

		$this->assertSame( array( array( $r->id() ) ), KeyOrder::sync_due() );
		KeyOrder::run_sync( $r->id() );
		$this->assertCount( 1, $this->calls( 'DELETE' ) );
	}

	public function test_a_lapsed_licence_no_longer_holds_its_server_address(): void {
		$r = $this->licence( array( 'ip' => '203.0.113.41' ) );
		$this->build( $r );
		$this->assertCount( 1, KeyOrder::live( '203.0.113.41' ) );

		\BeltoftLicenses\Licenses::update( (int) Resource::find( $r->id() )->license()['id'], array( 'status' => \BeltoftLicenses\Licenses::STATUS_EXPIRED ) );
		( new KeyDriver() )->pause( Resource::find( $r->id() ) );

		$this->assertSame( array(), KeyOrder::live( '203.0.113.41' ), 'A paused, lapsed licence is no reason to refuse a new order for the address.' );
	}

	public function test_a_revoked_or_expired_licence_is_deleted_at_once_but_one_on_hold_is_kept(): void {
		$hold = $this->licence( array( 'ip' => '203.0.113.30' ) );
		$this->build( $hold );
		\BeltoftLicenses\Licenses::update( (int) Resource::find( $hold->id() )->license()['id'], array( 'status' => \BeltoftLicenses\Licenses::STATUS_SUSPENDED ) );
		$this->assertTrue( ( new KeyDriver() )->pause( Resource::find( $hold->id() ) )->is_done() );
		$this->assertSame( array(), $this->calls( 'DELETE' ), 'A licence on hold for a payment retry is kept.' );
		$this->assertSame( array(), KeyOrder::sync_due() );

		$expired = $this->licence();
		$this->build( $expired );
		$revoked = $this->licence( array( 'ip' => '203.0.113.20' ) );
		$this->build( $revoked );
		$expired = Resource::find( $expired->id() );
		$revoked = Resource::find( $revoked->id() );

		\BeltoftLicenses\Licenses::update( (int) $expired->license()['id'], array( 'status' => \BeltoftLicenses\Licenses::STATUS_EXPIRED ) );
		$this->assertSame( array( array( $expired->id() ) ), KeyOrder::sync_due(), 'The watchdog finds an expired licence.' );
		$this->assertTrue( ( new KeyDriver() )->pause( Resource::find( $expired->id() ) )->is_done() );
		$this->assertCount( 1, $this->calls( 'DELETE' ), 'A lapse deletes the vendor licence at once.' );
		$this->assertSame( 0, (int) Resource::find( $expired->id() )->get( 'lic_key_id' ) );

		\BeltoftLicenses\Licenses::update( (int) $revoked->license()['id'], array( 'status' => \BeltoftLicenses\Licenses::STATUS_REVOKED ) );
		$this->assertSame( array( array( $revoked->id() ) ), KeyOrder::sync_due(), 'The watchdog finds a revoked licence.' );
		$this->assertTrue( ( new KeyDriver() )->pause( Resource::find( $revoked->id() ) )->is_done() );
		$this->assertCount( 2, $this->calls( 'DELETE' ) );
		$this->assertSame( 0, (int) Resource::find( $revoked->id() )->get( 'lic_key_id' ) );
	}

	public function test_a_customer_who_pays_after_the_licence_was_deleted_gets_a_new_key(): void {
		$r = $this->licence();
		$this->build( $r );
		$old = (int) Resource::find( $r->id() )->get( 'lic_key_id' );
		\BeltoftLicenses\Licenses::update( (int) $r->license()['id'], array( 'status' => \BeltoftLicenses\Licenses::STATUS_EXPIRED ) );
		( new KeyDriver() )->pause( Resource::find( $r->id() ) );
		$this->assertCount( 1, $this->calls( 'DELETE' ) );

		\BeltoftLicenses\Licenses::update( (int) $r->license()['id'], array( 'status' => \BeltoftLicenses\Licenses::STATUS_ACTIVE ) );
		$this->assertTrue( ( new KeyDriver() )->resume( Resource::find( $r->id() ) )->is_done() );
		$again = Resource::find( $r->id() );
		$this->assertGreaterThan( 0, (int) $again->get( 'lic_key_id' ) );
		$this->assertNotSame( '', KeyOrder::key_text( $again ) );
		$this->assertCount( 2, $this->calls( 'POST', '/licenses/plesk' ), 'A second licence was ordered.' );
		unset( $old );
	}

	public function test_a_licence_key_product_has_no_default_retention(): void {
		$r = $this->licence();
		$this->assertSame( 0, Product::retention_days( (int) $r->license()['product_id'] ) );
	}

	// -- Characterisation of the vendor calls (licence vendor layer, step S0): pins today's behaviour -------------

	private function due_in( string $hook, int $id ): ?int {
		$at = as_next_scheduled_action( $hook, array( $id ), \BeltoftServices\Jobs::GROUP );

		return is_int( $at ) ? $at - time() : null;
	}

	private function clear_jobs(): void {
		as_unschedule_all_actions( KeyOrder::KEY_HOOK );
		as_unschedule_all_actions( KeyOrder::SYNC_HOOK );
	}

	public function test_s0_an_error_on_the_find_the_order_and_the_change_alerts_or_retries_by_kind(): void {
		$err = new \WP_Error( 'op_199', 'x', array( 'status' => 400, 'desc' => 'nope' ) );
		$r   = $this->licence( array( 'total' => 60.0 ) );

		$this->op->reply( 'GET', '/licenses/plesk', $err );
		$result = $this->build( $r );
		$this->assertTrue( $result->is_retry() );
		$this->assertSame( 3600, $result->delay() );
		$this->assertCount( 1, $this->mails );
		$this->assertStringContainsString( 'Openprovider could not be asked for an earlier licence. (op_199: nope)', (string) $this->mails[0]['message'] );

		$this->mails = array();
		$this->op->reply( 'GET', '/licenses/plesk', new \WP_Error( 'transport', 'down' ) );
		$this->assertSame( 300, $this->build( $r )->delay() );
		$this->assertSame( array(), $this->mails, 'A temporary error tells nobody.' );

		$this->op->reply( 'POST', '/licenses/plesk', $err );
		$result = $this->build( $r );
		$this->assertSame( 3600, $result->delay() );
		$this->assertCount( 1, $this->mails );
		$this->assertStringContainsString( 'Openprovider did not order the licence. (op_199: nope)', (string) $this->mails[0]['message'] );

		$this->mails = array();
		$this->op->reply( 'POST', '/licenses/plesk', new \WP_Error( 'http_503', 'down', array( 'status' => 503 ) ) );
		$this->assertSame( 300, $this->build( $r )->delay() );
		$this->assertSame( array(), $this->mails );
		$this->assertSame( 0, (int) Resource::find( $r->id() )->get( 'lic_key_id' ) );

		// The change: a failed PUT goes through the same failure mapping (retry), the key is kept.
		$this->assertTrue( $this->build( $r )->is_done() );
		$r    = Resource::find( $r->id() );
		$plan = (array) $r->get( 'plan' );
		$r->set( array( 'plan' => array_merge( $plan, array( 'lic_tier' => 'host' ) ) ) );
		$this->mails = array();
		$this->op->reply( 'PUT', '/licenses/plesk/' . (int) $r->get( 'lic_key_id' ), $err );
		$result = ( new KeyDriver() )->update_plan( Resource::find( $r->id() ) );
		$this->assertTrue( $result->is_retry() );
		$this->assertSame( 3600, $result->delay() );
		$this->assertCount( 1, $this->mails );
		$this->assertStringContainsString( 'Openprovider did not change the licence. (op_199: nope)', (string) $this->mails[0]['message'] );
		$this->assertSame( 'PLESK-12-VPS-WEB-PRO-1M', Resource::find( $r->id() )->get( 'lic_item' ) );
		$this->assertNotSame( '', (string) Resource::find( $r->id() )->get( 'lic_key' ) );

		$this->mails = array();
		$this->op->reply( 'PUT', '/licenses/plesk/' . (int) $r->get( 'lic_key_id' ), new \WP_Error( 'transport', 'down' ) );
		$this->assertSame( 300, ( new KeyDriver() )->update_plan( Resource::find( $r->id() ) )->delay() );
		$this->assertSame( array(), $this->mails );
	}

	public function test_s0_a_refused_switch_alerts_and_retries_in_an_hour_without_a_counter(): void {
		$r = $this->licence( array( 'total' => 60.0 ) );
		$this->assertTrue( $this->build( $r )->is_done() );
		$r    = Resource::find( $r->id() );
		$plan = (array) $r->get( 'plan' );
		$r->set( array( 'plan' => array_merge( $plan, array( 'lic_tier' => 'host' ) ) ) );
		$this->settings( array( 'licence_costs' => "pro=1\nhost=9999" ) );
		$this->mails = array();

		$result = ( new KeyDriver() )->update_plan( Resource::find( $r->id() ) );

		$this->assertTrue( $result->is_retry() );
		$this->assertSame( 3600, $result->delay() );
		$this->assertCount( 1, $this->mails );
		$this->assertStringContainsString( 'The licence was not changed.', (string) $this->mails[0]['message'] );
		$this->assertSame( array(), $this->calls( 'PUT' ) );
		( new KeyDriver() )->update_plan( Resource::find( $r->id() ) );
		$this->assertCount( 1, $this->mails, 'The same text is mailed once.' );
	}

	public function test_s0_the_key_asks_alert_at_twelve_and_stop_at_thirty_six_with_the_delays(): void {
		for ( $i = 0; $i < 8; $i++ ) {
			$this->op->reply( 'GET', '/licenses/plesk/key/1001', array() );
		}

		$r  = $this->licence( array( 'kind' => 'addon', 'tier' => 'cloudlinux-solo', 'total' => 7.0 ) );
		$id = $r->id();
		$this->build( $r );
		$this->assertSame( 'waiting', Resource::find( $id )->get( 'lic_state' ) );

		foreach ( array( array( 0, 120 ), array( 10, 1320 ), array( 11, 1440 ), array( 34, 4200 ), array( 35, null ) ) as list( $before, $delay ) ) {
			$this->clear_jobs();
			$this->mails = array();
			Resource::find( $id )->set( array( 'lic_tries' => $before ) );
			KeyOrder::run_key( $id );
			$this->assertSame( $before + 1, (int) Resource::find( $id )->get( 'lic_tries' ) );
			$this->assertSame( 'waiting', Resource::find( $id )->get( 'lic_state' ) );

			if ( null === $delay ) {
				$this->assertNull( $this->due_in( KeyOrder::KEY_HOOK, $id ), 'The thirty sixth ask is the last.' );
			} else {
				$this->assertEqualsWithDelta( $delay, (int) $this->due_in( KeyOrder::KEY_HOOK, $id ), 5, 'after ' . $before );
			}

			$this->assertCount( 11 === $before ? 1 : 0, $this->mails, 'try ' . ( $before + 1 ) );
		}
	}

	public function test_s0_the_delete_counter_alerts_at_twelve_and_waits_longer_each_time_and_stops_at_thirty_six(): void {
		$r      = $this->licence();
		$id     = $r->id();
		$this->build( $r );
		$key_id = (int) Resource::find( $id )->get( 'lic_key_id' );

		foreach ( array( array( 0, 300, 0 ), array( 11, 43200, 1 ), array( 34, 86400, 0 ), array( 35, null, 0 ) ) as list( $before, $delay, $mails ) ) {
			$this->clear_jobs();
			$this->mails = array();
			Resource::find( $id )->set( array( 'lic_key_id' => $key_id, 'lic_delete_tries' => $before ) );
			$this->op->reply( 'DELETE', '/licenses/plesk/' . $key_id, new \WP_Error( 'transport', 'down' ) );
			$this->assertFalse( KeyOrder::release( Resource::find( $id ) ) );
			$this->assertSame( $before + 1, (int) Resource::find( $id )->get( 'lic_delete_tries' ) );

			if ( null === $delay ) {
				$this->assertNull( $this->due_in( KeyOrder::SYNC_HOOK, $id ) );
			} else {
				$this->assertEqualsWithDelta( $delay, (int) $this->due_in( KeyOrder::SYNC_HOOK, $id ), 5, 'after ' . $before );
			}

			$this->assertCount( $mails, $this->mails, 'try ' . ( $before + 1 ) );

			if ( 1 === $mails ) {
				$this->assertStringContainsString( 'after 12 tries (transport). Delete the licence at Openprovider, or it keeps billing.', (string) $this->mails[0]['message'] );
			}
		}
	}

	public function test_s0_the_order_body_the_title_and_the_parent_field_are_exactly_these(): void {
		$user    = (int) self::factory()->user->create();
		$edition = $this->licence( array( 'user' => $user ) );
		$this->assertTrue( $this->build( $edition )->is_done() );
		$lic     = (int) Resource::find( $edition->id() )->license()['id'];
		$parent  = (int) Resource::find( $edition->id() )->get( 'lic_key_id' );
		$posts   = $this->calls( 'POST', '/licenses/plesk' );

		$this->assertEquals(
			array(
				'title'               => 'licence ' . $lic . ' 203.0.113.10',
				'comment'             => 'Beltoft Services licence ' . $lic,
				'items'               => array( 'PLESK-12-VPS-WEB-PRO-1M' ),
				'period'              => 1,
				'ip_address_binding'  => '203.0.113.10',
				'restrict_ip_binding' => true,
			),
			$posts[0]['body']
		);
		$find = $this->calls( 'GET', '/licenses/plesk' );
		$this->assertSame( array( 'title_pattern' => 'licence ' . $lic . ' 203.0.113.10', 'limit' => 10 ), $find[0]['query'] );
		$this->assertSame( 'licence ' . $lic . ' 203.0.113.10', Resource::find( $edition->id() )->get( 'lic_title' ) );

		$addon = $this->licence( array( 'user' => $user, 'kind' => 'addon', 'tier' => 'cloudlinux-admin', 'total' => 14.0 ) );
		$this->assertTrue( $this->build( $addon )->is_done() );
		$alic  = (int) Resource::find( $addon->id() )->license()['id'];
		$posts = $this->calls( 'POST', '/licenses/plesk' );
		$this->assertEquals(
			array(
				'title'               => 'licence ' . $alic . ' 203.0.113.10',
				'comment'             => 'Beltoft Services licence ' . $alic,
				'items'               => array( 'CLNX-ADM-1M' ),
				'period'              => 1,
				'ip_address_binding'  => '203.0.113.10',
				'restrict_ip_binding' => true,
				'parent_key_id'       => $parent,
			),
			$posts[1]['body']
		);
	}

	public function test_s0_a_missing_item_alerts_and_retries_in_an_hour_and_orders_nothing(): void {
		$drop = static function ( $items ) { unset( $items['imunify360-1'] ); return $items; };
		add_filter( 'beltoft_services_licence_items', $drop );
		$r      = $this->licence( array( 'kind' => 'addon', 'tier' => 'imunify360-1', 'total' => 12.0 ) );
		$result = $this->build( $r );

		$this->assertTrue( $result->is_retry() );
		$this->assertSame( 3600, $result->delay() );
		$this->assertSame( 'No Openprovider item is set for this licence.', $result->error() );
		$this->assertCount( 1, $this->mails );
		$this->assertStringContainsString( 'No Openprovider item is set for the licence "imunify360-1"', (string) $this->mails[0]['message'] );
		$this->assertSame( array(), $this->op->calls() );
		remove_filter( 'beltoft_services_licence_items', $drop );
	}

	public function test_s0_without_the_encryption_key_nothing_is_ordered_and_it_is_tried_again_in_an_hour(): void {
		$r = $this->licence();
		remove_all_filters( 'beltoft_services_key' );
		add_filter( 'beltoft_services_key', '__return_empty_string' );
		$this->assertFalse( \BeltoftServices\Crypto::available() );

		$result = $this->build( $r );

		$this->assertTrue( $result->is_retry() );
		$this->assertSame( 3600, $result->delay() );
		$this->assertSame( array(), $this->op->calls() );
	}

	public function test_s0_an_invalid_address_fails_at_once_and_an_addon_waits_an_hour_for_its_parent_then_orders_alone(): void {
		$bad = $this->licence( array( 'ip' => 'not-an-ip' ) );
		$this->assertTrue( $this->build( $bad )->is_failed() );
		$this->assertSame( array(), $this->op->calls() );

		$user    = (int) self::factory()->user->create();
		$edition = $this->licence( array( 'user' => $user ) );
		$edition->set( array( 'lic_state' => 'requested' ) );
		$addon   = $this->licence( array( 'user' => $user, 'kind' => 'addon', 'tier' => 'cloudlinux-solo', 'total' => 7.0 ) );

		$result = $this->build( $addon );
		$until  = (int) Resource::find( $addon->id() )->get( 'lic_wait_until' );
		$this->assertFalse( $result->is_failed() );
		$this->assertFalse( $result->is_done() );
		$this->assertSame( $until, $result->wait_until() );
		$this->assertSame( 120, $result->delay() );
		$this->assertEqualsWithDelta( time() + HOUR_IN_SECONDS, $until, 5 );
		$this->assertSame( array(), $this->calls( 'POST', '/licenses/plesk' ) );

		Resource::find( $addon->id() )->set( array( 'lic_wait_until' => time() + 60 ) );
		$this->assertFalse( $this->build( $addon )->is_done(), 'Still waiting a minute before the deadline.' );

		Resource::find( $addon->id() )->set( array( 'lic_wait_until' => time() - 1 ) );
		$this->assertTrue( $this->build( $addon )->is_done() );
		$posts = $this->calls( 'POST', '/licenses/plesk' );
		$this->assertCount( 1, $posts );
		$this->assertArrayNotHasKey( 'parent_key_id', $posts[0]['body'] );
		$this->assertSame( 0, (int) Resource::find( $addon->id() )->get( 'lic_parent' ) );
	}

	public function test_s0_a_successful_delete_clears_exactly_these_meta_values_and_sets_the_mark(): void {
		$r = $this->licence();
		$this->build( $r );
		Resource::find( $r->id() )->set( array( 'lic_tries' => 2, 'lic_delete_tries' => 3 ) );

		$this->assertTrue( KeyOrder::release( Resource::find( $r->id() ) ) );

		$r = Resource::find( $r->id() );

		foreach ( array( 'lic_key_id', 'lic_key', 'lic_state', 'lic_tries', 'lic_delete_tries' ) as $name ) {
			$this->assertSame( '', (string) $r->get( $name ), $name );
		}

		$this->assertGreaterThan( 0, (int) $r->get( 'lic_deleted' ) );
		$this->assertSame( 'PLESK-12-VPS-WEB-PRO-1M', $r->get( 'lic_item' ) );
		$this->assertSame( 'file', $r->get( 'lic_key_kind' ), 'The kind and the shape stay as a record.' );
		$this->assertNotSame( '', (string) $r->get( 'lic_shape' ) );
		$this->assertNotSame( '', (string) $r->get( 'lic_title' ) );
	}

	public function test_s0_the_key_is_fetched_with_two_requests_and_the_field_names_are_recorded(): void {
		$r = $this->licence();
		$this->build( $r );

		$this->assertCount( 1, $this->calls( 'GET', '/licenses/plesk/key/1001' ) );
		$this->assertCount( 1, $this->calls( 'GET', '/licenses/plesk/1001' ) );
		$shape = (string) Resource::find( $r->id() )->get( 'lic_shape' );
		$this->assertStringContainsString( 'key:key', $shape );
		$this->assertStringContainsString( 'licence:', $shape );
		$this->assertStringContainsString( 'key_id', $shape );
	}

	public function test_s0_release_due_for_every_resource_state_and_licence_status(): void {
		$r       = $this->licence();
		$this->build( $r );
		$license = (int) Resource::find( $r->id() )->license()['id'];
		$states  = array(
			'ready'              => array( 'ready', '' ),
			'deleting'           => array( 'deleting', '' ),
			'deleted'            => array( 'deleted', '' ),
			'failed provision'   => array( 'failed', 'provision' ),
			'failed delete'      => array( 'failed', 'delete' ),
			'failed update_plan' => array( 'failed', 'update_plan' ),
			'failed resume'      => array( 'failed', 'resume' ),
			'failed pause'       => array( 'failed', 'pause' ),
		);
		$gone    = array( 'deleting', 'deleted', 'failed provision', 'failed delete' );
		$L       = \BeltoftLicenses\Licenses::class;

		foreach ( array( $L::STATUS_ACTIVE, $L::STATUS_SUSPENDED, $L::STATUS_EXPIRED, $L::STATUS_REVOKED ) as $status ) {
			$this->assertTrue( $L::update( $license, array( 'status' => $status ) ) );

			foreach ( $states as $name => list( $state, $op ) ) {
				$this->assertTrue( Resource::find( $r->id() )->set( array( 'state' => $state, 'failed_op' => $op ) ) );
				$expected = in_array( $name, $gone, true ) || in_array( $status, array( $L::STATUS_EXPIRED, $L::STATUS_REVOKED ), true );
				$this->assertSame( $expected, KeyOrder::release_due( Resource::find( $r->id() ) ), $name . ' / ' . $status );
			}
		}
	}

	/**
	 * One active subscription holding the product line of each licence, recorded on each licence.
	 *
	 * @param Resource ...$licences Licence resources.
	 */
	private function subscription_for( Resource ...$licences ): \WC_Subscription {
		$user = (int) ( $licences[0]->license()['user_id'] ?? 0 );
		$sub  = wcs_create_subscription( array( 'customer_id' => $user, 'billing_period' => 'month', 'billing_interval' => 1, 'status' => 'active' ) );
		$this->assertInstanceOf( \WC_Subscription::class, $sub );

		foreach ( $licences as $r ) {
			$sub->add_product( wc_get_product( (int) $r->license()['product_id'] ), 1 );
		}

		$sub->save();

		foreach ( $licences as $r ) {
			$this->assertTrue( \BeltoftLicenses\Licenses::update( (int) $r->license()['id'], array( 'subscription_id' => $sub->get_id() ) ) );
		}

		return $sub;
	}

	private function sub_status( \WC_Subscription $sub ): string {
		return wcs_get_subscription( $sub->get_id() )->get_status();
	}

	private function sub_notes( \WC_Subscription $sub ): string {
		return implode( "\n", array_map( static fn( $n ) => (string) $n->content, wc_get_order_notes( array( 'order_id' => $sub->get_id() ) ) ) );
	}

	public function test_s9_a_key_that_was_refused_cancels_its_subscription_and_names_the_order(): void {
		$this->settings( array( 'licence_costs' => 'pro=30' ) );
		$r   = $this->licence( array( 'total' => 20.0 ) );
		$sub = $this->subscription_for( Resource::find( $r->id() ) );
		$this->mails = array();

		\BeltoftServices\Jobs::run( $r->id() );

		$r = Resource::find( $r->id() );
		$this->assertSame( 'failed', $r->get( 'state' ), (string) $r->get( 'last_error' ) );
		$this->assertSame( array(), $this->calls( 'POST', '/licenses/plesk' ) );
		$this->assertSame( 'cancelled', $this->sub_status( $sub ) );
		$this->assertStringContainsString( 'review and refund order #' . (int) $r->license()['order_id'], $this->sub_notes( $sub ) );
		$this->assertNotEmpty( array_filter( $this->mails, static fn( $m ) => false !== strpos( (string) $m['message'], 'review and refund order #' ) ), 'An admin is told to refund.' );
	}

	public function test_s9_a_key_whose_retries_ran_out_before_anything_was_ordered_cancels_its_subscription(): void {
		$r   = $this->licence();
		$sub = $this->subscription_for( Resource::find( $r->id() ) );

		for ( $i = 0; $i < 12; $i++ ) {
			$this->op->reply( 'GET', '/licenses/plesk', new \WP_Error( 'transport', 'down' ) );
		}

		for ( $i = 0; $i < 8 && 'failed' !== Resource::find( $r->id() )->get( 'state' ); $i++ ) {
			Resource::find( $r->id() )->set( array( 'next_attempt_at' => time() - 1 ) );
			\BeltoftServices\Jobs::run( $r->id() );
		}

		$this->assertSame( 'failed', Resource::find( $r->id() )->get( 'state' ) );
		$this->assertSame( 0, (int) Resource::find( $r->id() )->get( 'lic_key_id' ) );
		$this->assertSame( 'cancelled', $this->sub_status( $sub ) );
	}

	public function test_s9_one_failed_line_never_cancels_a_subscription_that_carries_a_delivered_licence(): void {
		$this->settings( array( 'licence_costs' => 'pro=30' ) );
		$user = (int) self::factory()->user->create();
		$good = $this->licence( array( 'user' => $user, 'ip' => '203.0.113.20', 'total' => 30.0 ) );
		$this->assertTrue( $this->build( $good )->is_done() );
		$this->assertTrue( Resource::find( $good->id() )->set( array( 'state' => 'ready' ) ) );
		$bad = $this->licence( array( 'user' => $user, 'ip' => '203.0.113.21', 'total' => 20.0 ) );
		$sub = $this->subscription_for( Resource::find( $good->id() ), Resource::find( $bad->id() ) );

		\BeltoftServices\Jobs::run( $bad->id() );

		$this->assertSame( 'failed', Resource::find( $bad->id() )->get( 'state' ) );
		$this->assertSame( 'active', $this->sub_status( $sub ), 'The delivered licence keeps its subscription.' );
		$this->assertStringContainsString( 'Another licence on the subscription is still live', $this->sub_notes( $sub ) );
		$this->assertStringContainsString( 'review and refund order #', $this->sub_notes( $sub ) );
	}

	public function test_s9_a_failed_resume_plan_change_or_pause_or_a_failure_after_the_order_never_cancels(): void {
		$r   = $this->licence();
		$sub = $this->subscription_for( Resource::find( $r->id() ) );

		foreach ( array( 'resume', 'update_plan', 'pause', 'delete' ) as $op ) {
			Resource::find( $r->id() )->set( array( 'state' => 'failed', 'failed_op' => $op, 'last_error' => 'Something failed.' ) );
			KeyOrder::state_changed( Resource::find( $r->id() ), 'ready', 'failed' );
			$this->assertSame( 'active', $this->sub_status( $sub ), $op );
		}

		// A provision that failed after the order (a vendor licence exists) is money spent: the licence is deleted, the subscription stays for the admin.
		Resource::find( $r->id() )->set( array( 'state' => 'failed', 'failed_op' => 'provision', 'lic_key_id' => 1001 ) );
		KeyOrder::state_changed( Resource::find( $r->id() ), 'provisioning', 'failed' );
		$this->assertSame( 'active', $this->sub_status( $sub ) );

		// Not a state change to failed.
		Resource::find( $r->id() )->set( array( 'lic_key_id' => null ) );
		KeyOrder::state_changed( Resource::find( $r->id() ), 'failed', 'ready' );
		$this->assertSame( 'active', $this->sub_status( $sub ) );
	}

	public function test_s9_a_pay_as_you_go_key_has_no_subscription_of_its_own_to_cancel(): void {
		$r   = $this->licence();
		$sub = $this->subscription_for( Resource::find( $r->id() ) );
		$this->assertTrue( \BeltoftLicenses\Licenses::update( (int) $r->license()['id'], array( 'source' => 'usage' ) ) );
		Resource::find( $r->id() )->set( array( 'state' => 'failed', 'failed_op' => 'provision', 'last_error' => 'Nothing was ordered.' ) );

		KeyOrder::state_changed( Resource::find( $r->id() ), 'provisioning', 'failed' );

		$this->assertSame( 'active', $this->sub_status( $sub ) );
	}
}

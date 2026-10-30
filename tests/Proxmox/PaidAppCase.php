<?php
/**
 * Shared set-up of the paid app tests: the docker app made paid by a test-only definition filter, a licence product
 * with two tiers, and servers. FakeClient only, FakeDns not needed, no network.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Proxmox;

use BeltoftServices\Drivers\Registry;
use BeltoftServices\Lifecycle;
use BeltoftServices\Product;
use BeltoftServices\Proxmox\AppField;
use BeltoftServices\Proxmox\Clusters;
use BeltoftServices\Proxmox\IpPool;
use BeltoftServices\Proxmox\OsField;
use BeltoftServices\Proxmox\Ownership;
use BeltoftServices\Proxmox\Schema;
use BeltoftServices\Proxmox\VmRecords;
use BeltoftServices\Resource;

require_once __DIR__ . '/MonitoringTestCase.php';
require_once __DIR__ . '/PaidAppFixtures.php';

/**
 * The fixture paid app is the shipped docker app with a `licence` key added by the filter beltoft_services_apps
 * (the same filter a site can use): nothing is added to any real app.json.
 */
abstract class PaidAppCase extends MonitoringTestCase {

	use PaidAppFixtures;

	/** @var array<int, array<string, mixed>> Mails sent. */
	protected array $mails = array();

	/** @var array<int, array<string, mixed>> Calls of the licence event: server id, app, tier, event. */
	protected array $events = array();

	public function set_up(): void {
		parent::set_up();

		$this->mails  = array();
		$this->events = array();
		add_filter( 'pre_wp_mail', function ( $pre, $args ) {
			$this->mails[] = $args;
			return true;
		}, 10, 2 );
		add_filter( 'woocommerce_product_is_in_stock', '__return_true', 99 );
		add_filter( 'woocommerce_is_purchasable', '__return_true', 99 );
		add_filter( 'beltoft_services_apps', array( $this, 'paid_docker' ), 10, 2 );
		add_action( 'beltoft_services_app_licence_changed', array( $this, 'record_event' ), 10, 5 );
		$this->settings( array( 'admin_email' => 'admin@example.com' ) );

		// Another test file removes these hooks when it ends; adding them again is harmless.
		AppField::register();
		\BeltoftServices\Proxmox\AppLicence::register();
		\BeltoftServices\Proxmox\AppLicenceCart::register();
		\BeltoftServices\Proxmox\DiskCascade::register();

		$this->clear_ips();
		$this->assertTrue( Clusters::save( 'lab', array(
			'label' => 'Lab', 'api_url' => 'https://pve.example.invalid:8006',
			'token_id' => 'svc@pve!beltoft', 'token_secret' => 'secret-secret-secret-1',
			'client' => 'fake', 'nodes' => 'node-a', 'storage' => 'disk-store',
			'cloudinit_storage' => 'ci-store', 'bridge' => 'vmbr9',
			'gateway' => '192.0.2.1', 'nameservers' => '192.0.2.53', 'prefix' => '24',
			'vmid_min' => '5000', 'vmid_max' => '5099',
			'templates' => "9000 = Debian 12\n9001 = Ubuntu 24.04", 'default_template' => '9000',
			'vendor_snippet' => 'local:snippets/vm-vendor-cloudinit-v2.yaml',
			'apps_ready' => 1, 'apps_enabled' => array( 'docker', 'wordpress' ),
			'template_families_text' => "9001 = ubuntu-24.04\n9000 = debian-12",
		) ) );
	}

	public function tear_down(): void {
		remove_filter( 'woocommerce_product_is_in_stock', '__return_true', 99 );
		remove_filter( 'woocommerce_is_purchasable', '__return_true', 99 );
		remove_filter( 'beltoft_services_apps', array( $this, 'paid_docker' ), 10 );
		remove_action( 'beltoft_services_app_licence_changed', array( $this, 'record_event' ), 10 );
		remove_all_filters( 'woocommerce_subscriptions_can_item_be_switched_by_user' );
		foreach ( array( AppField::POST_APP, AppField::POST_VERSION, AppField::POST_TIER, AppField::POST_DOMAIN, OsField::POST_KEY ) as $key ) {
			unset( $_POST[ $key ] );
		}
		if ( function_exists( 'WC' ) && WC()->cart ) {
			WC()->cart->empty_cart();
		}
		$this->clear_ips();
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	protected function clear_ips(): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- isolated test table reset.
		$wpdb->query( 'DELETE FROM ' . Schema::tables()['ips'] );
		delete_transient( IpPool::FREE_TRANSIENT );
	}

	/**
	 * Records the licence event.
	 *
	 * @param mixed $server  Server.
	 * @param mixed $app     App id.
	 * @param mixed $tier    Tier.
	 * @param mixed $event   Event.
	 * @param mixed $licence Licence resource.
	 */
	public function record_event( $server, $app, $tier, $event, $licence ): void {
		$this->events[] = array( 'server' => $server->id(), 'app' => $app, 'tier' => $tier, 'event' => $event, 'licence' => $licence->id() );
	}

	/**
	 * A server product (simple), as checkout sells it.
	 *
	 * @param array<string, mixed> $plan Plan values.
	 */
	protected function vm_product( array $plan = array() ): \WC_Product {
		return $this->service_product(
			'proxmox',
			array_merge( array( 'cluster' => 'lab', 'template' => 9000, 'cores' => 2, 'memory_mb' => 4096, 'disk_gb' => 50 ), $plan ),
			array( 'slug' => 'vm-' . wp_generate_password( 5, false ) )
		);
	}

	/**
	 * A server of an order that also holds a tier line: both licenses, the server resource ready with the app (its meta
	 * from the order line, as provisioning takes it) and the licence resource created from its order line.
	 *
	 * @param \WC_Product_Variation $tier Tier variation (null: a server with the app but no tier line).
	 * @return array{server: Resource, licence: Resource|null, user: int, order: \WC_Order}
	 */
	protected function ordered( ?\WC_Product_Variation $tier, string $app_tier = 'basic', int $vmid = 5000 ): array {
		$user    = self::factory()->user->create( array( 'user_email' => 'christian.beltoft@gmail.com' ) );
		$vm      = $this->vm_product();
		$order   = wc_create_order( array( 'customer_id' => $user ) );
		$item_id = $order->add_product( $vm, 1 );
		$item    = $order->get_item( $item_id );
		$item->add_meta_data( AppField::ITEM_APP, 'docker', true );
		$item->add_meta_data( AppField::ITEM_VERSION, '29', true );
		$item->add_meta_data( AppField::ITEM_TIER, $app_tier, true );
		$item->save();

		$subscription = null;

		if ( $tier ) {
			$line = $order->add_product( $tier, 1 );
			$tl   = $order->get_item( $line );
			$tl->add_meta_data( \BeltoftServices\Proxmox\AppLicence::ITEM_UID, wp_generate_uuid4(), true );
			$tl->add_meta_data( \BeltoftServices\Proxmox\AppLicence::ITEM_SERVER, 'cart_server', true );
			$tl->save();
			$subscription = wcs_create_subscription( array( 'customer_id' => $user, 'billing_period' => 'month', 'billing_interval' => 1, 'status' => 'active' ) );
			$this->assertInstanceOf( \WC_Subscription::class, $subscription );
			$subscription->add_product( $tier, 1 );
			// WooCommerce Subscriptions only reactivates a pending-cancel subscription with manual renewal and a next payment date.
			$subscription->set_requires_manual_renewal( true );
			$subscription->update_dates( array( 'next_payment' => gmdate( 'Y-m-d H:i:s', time() + 30 * DAY_IN_SECONDS ) ) );
			$subscription->save();
			global $wpdb;
			$wpdb->delete( \BeltoftLicenses\Install::tables()['licenses'], array( 'subscription_id' => $subscription->get_id() ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Test fixture reset.
		}

		$order->save();

		$license = $this->service_license( $vm, array( 'fire' => false, 'user_id' => $user, 'order_id' => $order->get_id(), 'email' => 'christian.beltoft@gmail.com' ) );
		$driver  = Registry::get( 'proxmox' );
		$server  = Resource::create( $license, $driver, Product::plan( $vm->get_id() ), Lifecycle::initial_meta( $license, $driver ) );
		$this->assertInstanceOf( Resource::class, $server );
		$this->assertSame( $vmid, VmRecords::allocate( $server->id(), 'lab', $vmid, $vmid, array(), static fn() => true ) );
		$ip = '192.0.2.' . ( 10 + $vmid - 5000 );
		$this->assertTrue( $server->set( array( 'state' => 'ready', 'desired' => 'ready', 'ip' => $ip ) ) );
		IpPool::add( $ip );
		$this->assertNotNull( IpPool::hold( $server->id() ) );
		$this->assertTrue( VmRecords::move( $server->id(), 'node-a' ) );
		$this->fake->add_vm( array(
			'vmid' => $vmid, 'node' => 'node-a', 'status' => 'running',
			'config' => array( 'description' => Ownership::tag( $server ), 'memory' => 4096, 'cores' => 2, 'agent' => '1' ),
		) );

		$licence = null;

		if ( $tier ) {
			$row     = $this->service_license( wc_get_product( $tier->get_parent_id() ), array(
				'fire' => false, 'user_id' => $user, 'order_id' => $order->get_id(), 'variation_id' => $tier->get_id(), 'subscription_id' => $subscription->get_id(), 'email' => 'christian.beltoft@gmail.com',
			) );
			$driver  = Registry::get( 'proxmox-app' );
			$licence = Resource::create( $row, $driver, Product::plan( $tier->get_parent_id(), $tier->get_id() ), Lifecycle::initial_meta( $row, $driver ) );
			$this->assertInstanceOf( Resource::class, $licence );
		}

		return array( 'server' => Resource::find( $server->id() ), 'licence' => $licence ? Resource::find( $licence->id() ) : null, 'user' => $user, 'order' => $order );
	}
}

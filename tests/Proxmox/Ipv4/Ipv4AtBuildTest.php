<?php
/**
 * An IPv4 address bought in the same order as a new VPS Basic or database is taken while that one is built: the VM starts
 * with it, and the add-on then finds it in use and is done without a restart.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Proxmox\Ipv4;

use BeltoftServices\Drivers\Registry;
use BeltoftServices\Jobs;
use BeltoftServices\Product;
use BeltoftServices\Proxmox\Db\DbIp;
use BeltoftServices\Proxmox\Ipv4\Ipv4Driver;
use BeltoftServices\Proxmox\Ipv4\Ipv4Meta;
use BeltoftServices\Proxmox\Network\IpPool;
use BeltoftServices\Proxmox\Vm\Profiles;
use BeltoftServices\Resource;

require_once __DIR__ . '/Ipv4TestCase.php';

class Ipv4AtBuildTest extends Ipv4TestCase {

	/**
	 * A parent and an IPv4 add-on of one paid order, neither run yet.
	 *
	 * @param string               $driver proxmox or proxmox-db.
	 * @param array<string, mixed> $plan   Plan of the parent.
	 * @return array{0: Resource, 1: Resource}
	 */
	private function order_of( string $driver, array $plan ): array {
		static $n = 0;
		++$n;
		$order   = 700000 + $n;
		$user    = self::factory()->user->create();
		$product = $this->service_product( $driver, $plan, array( 'max' => 3, 'slug' => 'parent-' . wp_generate_password( 5, false ) ) );
		$parent  = Resource::create( $this->service_license( $product, array( 'fire' => false, 'user_id' => $user, 'order_id' => $order ) ), Registry::get( $driver ), Product::plan( $product->get_id() ), array() );
		$aprod   = $this->service_product( Ipv4Driver::ID, array(), array( 'slug' => 'ipv4-order-' . $n ) );
		$addon   = Resource::create( $this->service_license( $aprod, array( 'fire' => false, 'user_id' => $user, 'order_id' => $order ) ), Registry::get( Ipv4Driver::ID ), Product::plan( $aprod->get_id() ), array( 'server_order_id' => $order ) );
		$this->assertInstanceOf( Resource::class, $parent );
		$this->assertInstanceOf( Resource::class, $addon );

		return array( $parent, $addon );
	}

	private function basic_plan(): array {
		return array( 'cluster' => 'lab', 'template' => 9000, 'cores' => 1, 'memory_mb' => 2048, 'disk_gb' => 30, 'public_ip' => 0 );
	}

	public function test_a_basic_server_bought_with_an_address_starts_with_it_and_the_addon_needs_no_restart(): void {
		list( $server, $addon ) = $this->order_of( 'proxmox', $this->basic_plan() );

		Jobs::run( $server->id() );
		$server = Resource::find( $server->id() );
		$this->assertSame( 'ready', $server->get( 'state' ), (string) $server->get( 'last_error' ) );

		$address = (string) $server->get( 'ip' );
		$this->assertNotSame( '', $address, 'The server was built with an IPv4 address.' );
		$this->assertSame( $addon->id(), (int) $server->get( Ipv4Meta::AT_BUILD ) );
		$this->assertStringStartsWith( 'ip=' . $address . '/', $this->config()['ipconfig0'], 'The VM started with it.' );
		$this->assertStringContainsString( 'ip6=', $this->config()['ipconfig0'], 'And keeps its IPv6 address.' );
		$this->assertTrue( Profiles::for_resource( $server )->uses_public_ip() );

		$this->manage( $server );
		$this->settle( $addon );
		$addon = Resource::find( $addon->id() );

		$this->assertSame( 'ready', $addon->get( 'state' ), (string) $addon->get( 'last_error' ) );
		$this->assertSame( 'main', $addon->get( Ipv4Meta::ROLE ) );
		$this->assertSame( $address, (string) $addon->get( Ipv4Meta::ADDRESS ) );
		$this->assertEmpty( $addon->get( 'ipv4_queued' ), 'Nothing was queued on the server: no restart.' );
		$this->assertEmpty( Resource::find( $server->id() )->get( 'stopped_by_customer' ), 'The server was not restarted.' );
		$this->assertSame( $address, IpPool::for_resource( $server->id() )['address'], 'The server holds its main address.' );
		$this->assertNull( IpPool::for_resource( $addon->id() ), 'No second address was taken.' );
	}

	public function test_a_database_bought_with_an_address_adds_the_ip_step_and_holds_it(): void {
		list( $db, $addon ) = $this->order_of( 'proxmox-db', array( 'cluster' => 'lab', 'template' => 9100, 'major' => '18', 'cores' => 1, 'memory_mb' => 2048, 'disk_gb' => 20, 'public_ip' => 0 ) );

		$this->assertFalse( DbIp::wants( $db ), 'Before the build nothing is claimed.' );
		Ipv4Meta::claim_at_build( $db );
		$db = Resource::find( $db->id() );

		$this->assertTrue( DbIp::wants( $db ) );
		$this->assertContains( 'ip', Profiles::for_resource( $db )->steps() );
		$this->assertNotNull( IpPool::for_resource( $db->id() ) );
		$this->assertSame( 'main', Resource::find( $addon->id() )->get( Ipv4Meta::ROLE ) );

		Ipv4Meta::claim_at_build( $db );
		$this->assertSame( 1, count( array_filter( IpPool::rows(), static fn( $row ) => (int) $row['resource_id'] === $db->id() ) ), 'A second run takes nothing more.' );

		Resource::find( $addon->id() )->set( array( Ipv4Meta::ENDED => true ) );
		$this->assertFalse( DbIp::wants( Resource::find( $db->id() ) ), 'An add-on given back no longer keeps the address wanted.' );
	}

	public function test_an_empty_pool_builds_the_server_without_ipv4_as_before(): void {
		foreach ( IpPool::rows() as $row ) {
			if ( 'free' === $row['state'] ) {
				IpPool::block( $row['address'] );
			}
		}

		list( $server ) = $this->order_of( 'proxmox', $this->basic_plan() );
		Jobs::run( $server->id() );
		$server = Resource::find( $server->id() );

		$this->assertSame( 'ready', $server->get( 'state' ), (string) $server->get( 'last_error' ) );
		$this->assertSame( '', (string) $server->get( 'ip' ) );
		$this->assertSame( '', (string) $server->get( Ipv4Meta::AT_BUILD ) );
	}

	public function test_a_server_with_its_own_address_or_one_already_built_claims_nothing(): void {
		list( $dedicated, $addon ) = $this->order_of( 'proxmox', array_merge( $this->basic_plan(), array( 'public_ip' => 1 ) ) );
		Ipv4Meta::claim_at_build( $dedicated );
		$this->assertSame( '', (string) Resource::find( $dedicated->id() )->get( Ipv4Meta::AT_BUILD ) );
		$this->assertSame( '', (string) Resource::find( $addon->id() )->get( Ipv4Meta::ROLE ), 'Its add-on stays an extra address, decided later.' );

		list( $basic ) = $this->order_of( 'proxmox', $this->basic_plan() );
		$basic->set( array( 'steps' => array( 'vmid', 'clone' ) ) );
		Ipv4Meta::claim_at_build( Resource::find( $basic->id() ) );
		$this->assertSame( '', (string) Resource::find( $basic->id() )->get( Ipv4Meta::AT_BUILD ), 'A VM already cloned takes nothing at build time.' );
	}
}

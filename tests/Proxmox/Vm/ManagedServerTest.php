<?php
/**
 * The Managed server add-on: linked to one server, counted while active, one per server, ended with its server.
 * Fake client only.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Proxmox\Vm;

use BeltoftServices\Drivers\Registry;
use BeltoftServices\Jobs;
use BeltoftServices\Product;
use BeltoftServices\Proxmox\Addon\Addon;
use BeltoftServices\Proxmox\Managed\ManagedCart;
use BeltoftServices\Proxmox\Managed\ManagedDriver;
use BeltoftServices\Proxmox\Managed\ManagedMeta;
use BeltoftServices\Proxmox\Vm\Panels;
use BeltoftServices\Resource;
use BeltoftServices\Tests\Proxmox\Network\FirewallTestCase;

require_once dirname( __DIR__ ) . '/Network/FirewallTestCase.php';

class ManagedServerTest extends FirewallTestCase {

	/** A managed service for a server, run to the end of its build. */
	private function managed( Resource $server, ?int $user = null ): Resource {
		$product = $this->service_product( ManagedDriver::ID, array(), array( 'slug' => 'managed-' . wp_generate_password( 6, false ) ) );
		$license = $this->service_license( $product, array( 'fire' => false, 'user_id' => $user ?? $server->license()['user_id'] ) );
		$managed = Resource::create( $license, Registry::get( ManagedDriver::ID ), Product::plan( $product->get_id() ), array( 'server_license' => (int) $server->license()['id'] ) );
		$this->assertInstanceOf( Resource::class, $managed );
		Jobs::run( $managed->id() );

		return Resource::find( $managed->id() );
	}

	public function test_it_is_a_kind_of_addon_with_its_own_driver(): void {
		$addon = Addon::for_driver( ManagedDriver::ID );

		$this->assertNotNull( $addon );
		$this->assertSame( 'managed', $addon->kind );
		$this->assertSame( 'managed', Registry::get( ManagedDriver::ID )->kind() );
	}

	public function test_a_managed_service_counts_for_its_server_once_ready(): void {
		list( $server ) = $this->ready();
		$this->assertFalse( ManagedMeta::is_managed( $server ) );

		$managed = $this->managed( $server );

		$this->assertSame( 'ready', $managed->get( 'state' ), (string) $managed->get( 'last_error' ) );
		$this->assertSame( $server->id(), ManagedMeta::server_of( $managed )->id() );
		$this->assertTrue( ManagedMeta::is_managed( Resource::find( $server->id() ) ) );
	}

	public function test_a_server_of_another_customer_is_refused(): void {
		list( $server ) = $this->ready();
		$other          = self::factory()->user->create();
		$managed        = $this->managed( $server, $other );

		$this->assertNotSame( 'ready', $managed->get( 'state' ) );
		$this->assertFalse( ManagedMeta::is_managed( Resource::find( $server->id() ) ) );
	}

	public function test_one_per_server_and_the_panel_follows(): void {
		list( $server ) = $this->ready();
		$this->assertTrue( ManagedCart::room( $server ) );
		$this->assertNull( Panels::managed_panel( $server ), 'Nothing to buy and nothing bought: no block.' );

		$this->managed( $server );
		$server = Resource::find( $server->id() );

		$this->assertFalse( ManagedCart::room( $server ) );
		$this->assertSame( '', ManagedCart::add_url( $server ) );
		$this->assertTrue( Panels::managed_panel( $server )['active'] );
	}

	public function test_an_adopted_server_gets_no_block(): void {
		list( $server ) = $this->ready();
		$server->set( array( 'linked' => true ) );

		$this->assertNull( Panels::managed_panel( Resource::find( $server->id() ) ) );
	}

	public function test_a_failed_or_deleted_service_gives_the_place_back(): void {
		list( $server ) = $this->ready();
		$managed        = $this->managed( $server );
		$this->assertCount( 1, ManagedMeta::reserved_for( Resource::find( $server->id() ) ) );

		$this->assertTrue( $managed->set( array( 'state' => 'deleted' ) ) );

		$this->assertSame( array(), ManagedMeta::reserved_for( Resource::find( $server->id() ) ) );
		$this->assertFalse( ManagedMeta::is_managed( Resource::find( $server->id() ) ) );
	}
}

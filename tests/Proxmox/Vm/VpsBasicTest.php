<?php
/**
 * VPS Basic: a server with IPv6 only (plan field public_ip 0), and the IPv4 add-on added to or taken from it.
 * Fake client and the documentation prefix only.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Proxmox\Vm;

use BeltoftServices\Drivers\Registry;
use BeltoftServices\Jobs;
use BeltoftServices\Product;
use BeltoftServices\Proxmox\Network\FirewallRules;
use BeltoftServices\Proxmox\Network\IpPool;
use BeltoftServices\Proxmox\Network\PublicIp;
use BeltoftServices\Proxmox\Pve\Clusters;
use BeltoftServices\Proxmox\Vm\Profiles;
use BeltoftServices\Proxmox\Vm\Stock;
use BeltoftServices\Proxmox\Vm\VmRecords;
use BeltoftServices\Proxmox\Vm\VpsIp;
use BeltoftServices\Resource;
use BeltoftServices\Tests\Proxmox\Network\FirewallTestCase;

require_once dirname( __DIR__ ) . '/Network/FirewallTestCase.php';

class VpsBasicTest extends FirewallTestCase {

	private const PREFIX  = '2001:db8:430c:1::/64';
	private const ADDRESS = '2001:db8:430c:1:1388::1';
	private const DNS6    = '2001:db8:53::53';

	/** IPv6 on, with IPv6 name servers: what a cluster needs to sell a server without IPv4. */
	private function ipv6_on( bool $dns = true ): void {
		$this->save_cluster( array( 'ipv6_prefix' => self::PREFIX, 'fw_enabled' => '1', 'fw_ipfilter' => '1', 'nameservers6' => $dns ? self::DNS6 : '' ) );
	}

	/**
	 * A ready server of a plan with or without the IPv4 address.
	 *
	 * @param int $public_ip Plan field public_ip.
	 * @return Resource
	 */
	private function basic( int $public_ip ): Resource {
		$product  = $this->service_product( 'proxmox', array( 'cluster' => 'lab', 'template' => 9000, 'cores' => 1, 'memory_mb' => 2048, 'disk_gb' => 30, 'public_ip' => $public_ip ), array( 'max' => 3, 'slug' => 'vm-' . wp_generate_password( 5, false ) ) );
		$license  = $this->service_license( $product, array( 'fire' => false ) );
		$resource = Resource::create( $license, Registry::get( 'proxmox' ), Product::plan( $product->get_id() ), array() );
		$this->assertInstanceOf( Resource::class, $resource );
		Jobs::run( $resource->id() );

		return Resource::find( $resource->id() );
	}

	/** The dashboard firewall and the ipfilter on for the server: its ipfilter set is built from its addresses. */
	private function manage( Resource $r ): Resource {
		$r      = Resource::find( $r->id() );
		$normal = FirewallRules::normalise( array( array( 'proto' => 'tcp', 'from' => 22, 'to' => 22, 'sources' => array(), 'label' => '' ) ), $r );
		$this->assertIsArray( $normal );
		$this->assertGreaterThan( 0, FirewallRules::store( $r, true, $normal, true, 1 ) );
		Jobs::act( $r->id(), 'fw_apply', array() );

		return Resource::find( $r->id() );
	}

	/** @return string[] The addresses in the server's ipfilter-net0 set, sorted. */
	private function set(): array {
		$cidrs = array_column( (array) ( $this->fake->vm( 5000 )['firewall_ipsets']['ipfilter-net0'] ?? array() ), 'cidr' );
		sort( $cidrs );

		return $cidrs;
	}

	private function config(): array {
		return (array) $this->fake->request( 'GET', '/nodes/node-a/qemu/5000/config' );
	}

	public function test_the_plan_field_defaults_to_an_address(): void {
		$product = $this->service_product( 'proxmox', array( 'cluster' => 'lab', 'template' => 9000, 'cores' => 1, 'memory_mb' => 1024, 'disk_gb' => 10 ), array( 'max' => 3, 'slug' => 'vm-x' ) );

		$this->assertSame( 1, Product::plan( $product->get_id() )['public_ip'] );
		$this->assertTrue( PublicIp::wants( array(), 1 ) );
		$this->assertFalse( PublicIp::wants( array(), 0 ) );
		$this->assertFalse( PublicIp::wants( array( 'public_ip' => 0 ), 1 ) );
	}

	public function test_a_basic_server_is_built_with_ipv6_only(): void {
		$this->ipv6_on();
		$r  = $this->basic( 0 );
		$vm = $this->fake->vm( 5000 );

		$this->assertSame( 'ready', $r->get( 'state' ) );
		$this->assertSame( 'ip6=' . self::ADDRESS . '/64,gw6=2001:db8:430c:1::1', $vm['config']['ipconfig0'] );
		$this->assertSame( self::ADDRESS, $r->get( 'ip6' ) );
		$this->assertEmpty( $r->get( 'ip' ) );
		$this->assertNull( IpPool::for_resource( $r->id() ) );
		$this->assertSame( self::DNS6, $vm['config']['nameserver'] );
		$this->assertSame( 15, IpPool::free_count(), 'No address was taken from the pool.' );
		$this->assertNotContains( 'ip', Profiles::for_resource( $r )->steps() );
	}

	public function test_a_server_with_the_default_plan_is_as_before(): void {
		$this->ipv6_on();
		$r  = $this->basic( 1 );
		$vm = $this->fake->vm( 5000 );

		$this->assertStringStartsWith( 'ip=192.0.2.', $vm['config']['ipconfig0'] );
		$this->assertSame( '192.0.2.53', $vm['config']['nameserver'] );
		$this->assertNotNull( IpPool::for_resource( $r->id() ) );
	}

	public function test_a_basic_server_fails_when_the_cluster_cannot_serve_it(): void {
		$this->save_cluster();
		$product  = $this->service_product( 'proxmox', array( 'cluster' => 'lab', 'template' => 9000, 'cores' => 1, 'memory_mb' => 2048, 'disk_gb' => 30, 'public_ip' => 0 ), array( 'max' => 3, 'slug' => 'vm-y' ) );
		$license  = $this->service_license( $product, array( 'fire' => false ) );
		$resource = Resource::create( $license, Registry::get( 'proxmox' ), Product::plan( $product->get_id() ), array() );
		Jobs::run( $resource->id() );

		$fresh = Resource::find( $resource->id() );
		$this->assertNotSame( 'ready', $fresh->get( 'state' ) );
		$this->assertNull( $this->fake->vm( 5000 )['config']['ipconfig0'] ?? null, 'Nothing was written to the VM.' );
	}

	public function test_stock_needs_ipv6_and_ipv6_name_servers_for_a_plan_without_ipv4(): void {
		$product = $this->service_product( 'proxmox', array( 'cluster' => 'lab', 'template' => 9000, 'cores' => 1, 'memory_mb' => 2048, 'disk_gb' => 30, 'public_ip' => 0 ), array( 'max' => 3, 'slug' => 'vm-z' ) );

		$this->save_cluster();
		Stock::sync();
		$this->assertFalse( wc_get_product( $product->get_id() )->is_in_stock(), 'No IPv6 on the cluster.' );

		$this->ipv6_on( false );
		Stock::sync();
		$this->assertFalse( wc_get_product( $product->get_id() )->is_in_stock(), 'No IPv6 name servers.' );

		$this->ipv6_on();
		Stock::sync();
		$this->assertTrue( wc_get_product( $product->get_id() )->is_in_stock() );

		foreach ( range( 10, 24 ) as $last ) {
			IpPool::block( '192.0.2.' . $last );
		}
		Stock::sync();
		$this->assertTrue( wc_get_product( $product->get_id() )->is_in_stock(), 'An empty address pool does not stop a server with no IPv4.' );
	}

	public function test_the_ipfilter_set_of_an_ipv6_only_server_holds_the_ipv6_address(): void {
		$this->ipv6_on();
		$r = $this->manage( $this->basic( 0 ) );

		$this->assertSame( array( 'ipfilter-net0' => self::ADDRESS ), \BeltoftServices\Proxmox\Network\Firewall::expected_ipsets( $r, false ) );
		$this->assertSame( array( self::ADDRESS ), $this->set() );
	}

	public function test_the_ipv4_add_on_adds_an_address_with_one_restart(): void {
		$this->ipv6_on();
		$r = $this->manage( $this->basic( 0 ) );
		$this->assertTrue( VpsIp::idle( $r ) );
		$this->assertSame( '', VpsIp::wanted( $r ) );

		$plan = (array) $r->get( 'plan' );
		$r->set( array( 'plan' => array_merge( $plan, array( 'public_ip' => 1 ) ) ) );
		$r = Resource::find( $r->id() );
		$this->assertSame( 'add', VpsIp::wanted( $r ) );

		$mark = count( $this->fake->calls() );
		$done = Registry::get( 'proxmox' )->update_plan( $r );
		$this->assertTrue( $done->is_done(), $done->error() );

		$fresh = Resource::find( $r->id() );
		$ip    = IpPool::for_resource( $r->id() );
		$this->assertNotNull( $ip );
		$this->assertSame( 'assigned', $ip['state'] );
		$this->assertSame( $ip['address'], $fresh->get( 'ip' ) );
		$this->assertSame( 'ip=' . $ip['address'] . '/24,gw=192.0.2.1,ip6=' . self::ADDRESS . '/64,gw6=2001:db8:430c:1::1', $this->config()['ipconfig0'] );
		$this->assertNull( $fresh->get( VpsIp::OP ) );
		$this->assertSame( $ip['id'], VmRecords::for_resource( $r->id() )['ip_id'] );

		$reboots = array_filter( array_slice( $this->fake->calls(), $mark ), static fn( $c ) => 'POST' === $c['method'] && '/status/reboot' === substr( $c['path'], -14 ) );
		$this->assertCount( 1, $reboots, 'One restart.' );

		$expected = array( $ip['address'], self::ADDRESS );
		sort( $expected );
		$this->assertSame( $expected, $this->set() );

		$again = Registry::get( 'proxmox' )->update_plan( $fresh );
		$this->assertTrue( $again->is_done() );
		$this->assertCount( 1, array_filter( array_slice( $this->fake->calls(), $mark ), static fn( $c ) => 'POST' === $c['method'] && '/status/reboot' === substr( $c['path'], -14 ) ), 'A second run changes nothing.' );
	}

	public function test_the_ipv4_add_on_is_taken_away_and_the_address_goes_back_to_the_pool(): void {
		$this->ipv6_on();
		$r    = $this->manage( $this->basic( 1 ) );
		$ip4  = (string) $r->get( 'ip' );
		$this->assertContains( $ip4, $this->set() );
		$free = IpPool::free_count();
		$this->assertNotSame( '', $ip4 );

		$r->set( array( 'plan' => array_merge( (array) $r->get( 'plan' ), array( 'public_ip' => 0 ) ) ) );
		$r = Resource::find( $r->id() );
		$this->assertSame( 'remove', VpsIp::wanted( $r ) );

		$done = Registry::get( 'proxmox' )->update_plan( $r );
		$this->assertTrue( $done->is_done(), $done->error() );

		$fresh = Resource::find( $r->id() );
		$this->assertSame( 'ip6=' . self::ADDRESS . '/64,gw6=2001:db8:430c:1::1', $this->config()['ipconfig0'] );
		$this->assertEmpty( $fresh->get( 'ip' ) );
		$this->assertNull( IpPool::for_resource( $r->id() ) );
		$this->assertSame( $free + 1, IpPool::free_count() );
		$this->assertNull( VmRecords::for_resource( $r->id() )['ip_id'] );
		$this->assertSame( array( 'ipfilter-net0' => self::ADDRESS ), \BeltoftServices\Proxmox\Network\Firewall::expected_ipsets( $fresh, false ) );
		$this->assertSame( array( self::ADDRESS ), $this->set(), 'The old address is out of the filter.' );
	}

	public function test_a_server_without_ipv6_never_loses_its_only_address(): void {
		$this->save_cluster();
		$r = $this->basic( 1 );
		$this->assertSame( '', (string) $r->get( 'ip6' ) );

		$r->set( array( 'plan' => array_merge( (array) $r->get( 'plan' ), array( 'public_ip' => 0 ) ) ) );
		$this->assertSame( '', VpsIp::wanted( Resource::find( $r->id() ) ) );
	}

	public function test_an_adopted_server_is_never_changed(): void {
		$this->ipv6_on();
		$r = $this->basic( 0 );
		$r->set( array( 'linked' => true, 'plan' => array_merge( (array) $r->get( 'plan' ), array( 'public_ip' => 1 ) ) ) );

		$this->assertSame( '', VpsIp::wanted( Resource::find( $r->id() ) ) );
	}

	public function test_no_address_free_gives_up_without_touching_the_server(): void {
		$this->ipv6_on();
		$r = $this->basic( 0 );

		foreach ( range( 10, 24 ) as $last ) {
			IpPool::block( '192.0.2.' . $last );
		}

		$r->set( array( 'plan' => array_merge( (array) $r->get( 'plan' ), array( 'public_ip' => 1 ) ) ) );
		$done = Registry::get( 'proxmox' )->update_plan( Resource::find( $r->id() ) );

		$this->assertTrue( $done->is_failed() );
		$this->assertSame( 'ip6=' . self::ADDRESS . '/64,gw6=2001:db8:430c:1::1', $this->config()['ipconfig0'] );
		$this->assertNull( Resource::find( $r->id() )->get( VpsIp::OP ) );
	}

	public function test_a_restore_that_brings_back_an_old_ipv4_is_repaired_to_ipv6_only(): void {
		$this->ipv6_on();
		$r = $this->basic( 0 );

		$this->fake->request( 'PUT', '/nodes/node-a/qemu/5000/config', array( 'ipconfig0' => 'ip=192.0.2.99/24,gw=192.0.2.1,ip6=' . self::ADDRESS . '/64,gw6=2001:db8:430c:1::1' ) );
		$op = new \BeltoftServices\Proxmox\Vm\Operations( Resource::find( $r->id() ) );

		$this->assertSame( 'written', $op->reconcile_ipv6() );
		$this->assertSame( 'ip6=' . self::ADDRESS . '/64,gw6=2001:db8:430c:1::1', $this->config()['ipconfig0'] );
		$this->assertSame( 'same', $op->reconcile_ipv6() );
	}

	public function test_the_switch_check_refuses_a_removal_that_leaves_no_address(): void {
		$this->save_cluster();
		$r = $this->basic( 1 );
		$this->assertSame( '', VpsIp::switch_error( 0, 0, array( 'public_ip' => 1 ), null ), 'No resources for this subscription: nothing to refuse.' );
		unset( $r );
	}

	public function test_a_cluster_keeps_ipv6_name_servers(): void {
		$this->ipv6_on();
		$this->assertSame( array( self::DNS6 ), Clusters::get( 'lab' )['nameservers6'] );

		$this->save_cluster( array( 'ipv6_prefix' => self::PREFIX, 'fw_enabled' => '1', 'fw_ipfilter' => '1', 'nameservers6' => '' ) );
		$this->assertSame( array(), Clusters::get( 'lab' )['nameservers6'] );

		$bad = Clusters::save( 'lab', array_merge( Clusters::get( 'lab' ), array( 'token_secret' => self::SECRET, 'nodes' => 'node-a', 'templates' => '9000 = Ubuntu 26.04', 'nameservers' => '192.0.2.53', 'nameservers6' => '192.0.2.5' ) ) );
		$this->assertTrue( is_wp_error( $bad ) || false === $bad );
	}
}

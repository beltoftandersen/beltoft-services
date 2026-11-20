<?php
/**
 * The IPv4 add-on on servers: an extra address on a dedicated server (no restart), the first address of a VPS Basic (one
 * restart), the pool and its mails, and taking an address away. Fake clients and the documentation prefixes only.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Proxmox\Ipv4;

use BeltoftServices\Drivers\Registry;
use BeltoftServices\Jobs;
use BeltoftServices\Product;
use BeltoftServices\Proxmox\Addon\Addon;
use BeltoftServices\Proxmox\Ipv4\Ipv4Driver;
use BeltoftServices\Proxmox\Ipv4\Ipv4Meta;
use BeltoftServices\Proxmox\Network\FirewallRules;
use BeltoftServices\Proxmox\Network\IpPool;
use BeltoftServices\Proxmox\Vm\Profiles;
use BeltoftServices\Resource;
use BeltoftServices\Tests\Proxmox\Network\FirewallTestCase;

require_once dirname( __DIR__ ) . '/Network/FirewallTestCase.php';

class Ipv4ServerTest extends FirewallTestCase {

	private const PREFIX = '2001:db8:430c:1::/64';

	/** @var string[] Subjects of the mails sent. */
	private $mails = array();

	public function set_up(): void {
		parent::set_up();

		$this->mails = array();
		add_filter(
			'pre_wp_mail',
			function ( $pre, $atts ) {
				$this->mails[] = array( 'to' => $atts['to'], 'subject' => $atts['subject'] );
				return true;
			},
			20,
			2
		);
		$this->save_cluster( array( 'ipv6_prefix' => self::PREFIX, 'fw_enabled' => '1', 'fw_ipfilter' => '1', 'nameservers6' => '2001:db8:53::53' ) );
	}

	/** A ready server of a plan with (1) or without (0) its own IPv4. */
	private function server( int $public_ip ): Resource {
		$product  = $this->service_product( 'proxmox', array( 'cluster' => 'lab', 'template' => 9000, 'cores' => 1, 'memory_mb' => 2048, 'disk_gb' => 30, 'public_ip' => $public_ip ), array( 'max' => 3, 'slug' => 'vm-' . wp_generate_password( 5, false ) ) );
		$license  = $this->service_license( $product, array( 'fire' => false ) );
		$resource = Resource::create( $license, Registry::get( 'proxmox' ), Product::plan( $product->get_id() ), array() );
		$this->assertInstanceOf( Resource::class, $resource );
		Jobs::run( $resource->id() );
		$resource = Resource::find( $resource->id() );
		$this->assertSame( 'ready', $resource->get( 'state' ), (string) $resource->get( 'last_error' ) );

		return $this->manage( $resource );
	}

	/** The dashboard firewall and the ipfilter on for the server. */
	private function manage( Resource $r ): Resource {
		$normal = FirewallRules::normalise( array( array( 'proto' => 'tcp', 'from' => 22, 'to' => 22, 'sources' => array(), 'label' => '' ) ), $r );
		$this->assertIsArray( $normal );
		$this->assertGreaterThan( 0, FirewallRules::store( $r, true, $normal, true, 1 ) );
		Jobs::act( $r->id(), 'fw_apply', array() );

		return Resource::find( $r->id() );
	}

	/** One IPv4 add-on for a server, run until it is ready or stops. */
	private function addon( Resource $server, ?int $user = null ): Resource {
		static $n = 0;
		$product = $this->service_product( Ipv4Driver::ID, array(), array( 'slug' => 'ipv4-' . ( ++$n ) ) );
		$license = $this->service_license( $product, array( 'fire' => false, 'user_id' => $user ?? $server->license()['user_id'] ) );
		$addon   = Resource::create( $license, Registry::get( Ipv4Driver::ID ), Product::plan( $product->get_id() ), array( 'server_license' => (int) $server->license()['id'] ) );
		$this->assertInstanceOf( Resource::class, $addon );
		$this->settle( $addon );

		return Resource::find( $addon->id() );
	}

	/** Runs the add-on and the jobs it queues on the server until nothing is left to do. */
	private function settle( Resource $addon ): void {
		for ( $i = 0; $i < 8; $i++ ) {
			Resource::find( $addon->id() )->set( array( 'next_attempt_at' => 0 ) );
			Jobs::run( $addon->id() );
			$this->run_jobs();

			if ( in_array( Resource::find( $addon->id() )->get( 'state' ), array( 'ready', 'failed' ), true ) ) {
				return;
			}
		}
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

	public function test_it_is_a_kind_of_addon_for_servers_and_databases(): void {
		$addon = Addon::for_driver( Ipv4Driver::ID );

		$this->assertNotNull( $addon );
		$this->assertSame( 'ipv4', $addon->kind );
		$this->assertContains( \BeltoftServices\Proxmox\Db\DbDriver::ID, $addon->parent_drivers() );
		$this->assertContains( Ipv4Driver::ID, \BeltoftServices\Proxmox\Vm\Stock::addon_drivers() );
	}

	public function test_an_extra_address_on_a_dedicated_server_needs_no_restart_and_is_allowed_in_the_firewall(): void {
		$server = $this->server( 1 );
		$main   = (string) $server->get( 'ip' );
		$this->assertNotSame( '', $main );

		$addon = $this->addon( $server );

		$this->assertSame( 'ready', $addon->get( 'state' ), (string) $addon->get( 'last_error' ) );
		$this->assertSame( 'extra', $addon->get( Ipv4Meta::ROLE ) );
		$address = (string) $addon->get( Ipv4Meta::ADDRESS );
		$this->assertNotSame( '', $address );
		$this->assertNotSame( $main, $address );

		$row = IpPool::find( $address );
		$this->assertSame( 'assigned', $row['state'] );
		$this->assertSame( $addon->id(), $row['resource_id'], 'An extra address is held by its own add-on.' );
		$this->assertSame( $main, IpPool::for_resource( $server->id() )['address'], 'The server keeps its own main address.' );

		$fresh = Resource::find( $server->id() );
		$this->assertSame( $main, (string) $fresh->get( 'ip' ), 'The main address did not change.' );
		$this->assertContains( $address, $this->set() );
		$this->assertContains( $main, $this->set() );
		$this->assertStringStartsWith( 'ip=' . $main . '/', $this->config()['ipconfig0'], 'The VM config still has the main address only.' );
		$this->assertSame( 'running', $this->fake->vm( 5000 )['status'] );
		$this->assertEmpty( $fresh->get( 'stopped_by_customer' ), 'An extra address restarts nothing.' );
	}

	public function test_the_first_address_of_a_basic_server_becomes_its_main_address_with_one_restart(): void {
		$basic = $this->server( 0 );
		$this->assertEmpty( $basic->get( 'ip' ) );
		$this->assertNull( IpPool::for_resource( $basic->id() ) );
		$before = null;

		$addon = $this->addon( $basic );

		$this->assertSame( 'ready', $addon->get( 'state' ), (string) $addon->get( 'last_error' ) );
		$this->assertSame( 'main', $addon->get( Ipv4Meta::ROLE ) );
		$address = (string) $addon->get( Ipv4Meta::ADDRESS );

		$server = Resource::find( $basic->id() );
		$this->assertSame( $address, (string) $server->get( 'ip' ) );
		$this->assertSame( $address, IpPool::for_resource( $server->id() )['address'], 'A main address is held by the server itself.' );
		$this->assertSame( 'assigned', IpPool::for_resource( $server->id() )['state'] );
		$this->assertStringStartsWith( 'ip=' . $address . '/', $this->config()['ipconfig0'] );
		$this->assertStringContainsString( 'ip6=', $this->config()['ipconfig0'], 'The IPv6 part stays.' );
		$this->assertTrue( Profiles::for_resource( $server )->uses_public_ip() );
		$this->assertContains( $address, $this->set() );
		$this->assertSame( 2, count( $this->set() ), 'The IPv4 and the IPv6 address.' );
		$this->assertSame( 'running', $this->fake->vm( 5000 )['status'] );
		$this->assertNotEmpty( $server->get( 'stopped_by_customer' ), 'The main address restarted the server once.' );
		unset( $before );
	}

	public function test_a_second_address_on_a_basic_server_is_an_extra_one(): void {
		$basic = $this->server( 0 );
		$one   = $this->addon( $basic );
		$two   = $this->addon( Resource::find( $basic->id() ) );

		$this->assertSame( 'main', $one->get( Ipv4Meta::ROLE ) );
		$this->assertSame( 'extra', $two->get( Ipv4Meta::ROLE ) );
		$this->assertSame( 'ready', $two->get( 'state' ), (string) $two->get( 'last_error' ) );
		$this->assertContains( (string) $two->get( Ipv4Meta::ADDRESS ), $this->set() );
		$this->assertSame( 3, count( $this->set() ) );
	}

	public function test_taking_away_an_extra_address_closes_the_firewall_first_and_gives_the_address_back(): void {
		$server = $this->server( 1 );
		$addon  = $this->addon( $server );
		$addr   = (string) $addon->get( Ipv4Meta::ADDRESS );
		$free   = IpPool::free_count();
		$this->assertContains( $addr, $this->set() );

		for ( $i = 0; $i < 8; $i++ ) {
			Registry::get( Ipv4Driver::ID )->delete( Resource::find( $addon->id() ) );
			$this->run_jobs();

			if ( null === IpPool::find( $addr )['resource_id'] ) {
				break;
			}
		}

		$this->assertNotContains( $addr, $this->set(), 'The firewall stopped allowing it.' );
		$this->assertNull( IpPool::find( $addr )['resource_id'] );
		$this->assertSame( 'free', IpPool::find( $addr )['state'] );
		$this->assertSame( $free + 1, IpPool::free_count() );
		$this->assertSame( array(), Ipv4Meta::extra_addresses( Resource::find( $server->id() ) ) );
	}

	public function test_taking_away_the_main_address_of_a_basic_server_makes_it_ipv6_only_again(): void {
		$basic = $this->server( 0 );
		$addon = $this->addon( $basic );
		$addr  = (string) $addon->get( Ipv4Meta::ADDRESS );

		for ( $i = 0; $i < 8; $i++ ) {
			$result = Registry::get( Ipv4Driver::ID )->delete( Resource::find( $addon->id() ) );
			$this->run_jobs();

			if ( $result->is_done() ) {
				break;
			}
		}

		$server = Resource::find( $basic->id() );
		$this->assertEmpty( $server->get( 'ip' ) );
		$this->assertNull( IpPool::for_resource( $server->id() ) );
		$this->assertSame( 'free', IpPool::find( $addr )['state'] );
		$this->assertStringStartsNotWith( 'ip=', $this->config()['ipconfig0'] );
		$this->assertStringContainsString( 'ip6=', $this->config()['ipconfig0'] );
		$this->assertFalse( Profiles::for_resource( $server )->uses_public_ip() );
		$this->assertCount( 1, $this->set(), 'Only the IPv6 address is left in the filter.' );
	}

	public function test_a_server_of_another_customer_is_refused_and_nothing_is_taken(): void {
		$server = $this->server( 1 );
		$free   = IpPool::free_count();
		$other  = self::factory()->user->create();

		$addon = $this->addon( $server, $other );

		$this->assertSame( 'failed', $addon->get( 'state' ) );
		$this->assertSame( $free, IpPool::free_count() );
		$this->assertSame( array(), Ipv4Meta::reserved_for( Resource::find( $server->id() ) ) );
	}

	public function test_an_empty_pool_is_sold_out_and_the_owner_is_told_once_a_day(): void {
		$server = $this->server( 1 );

		foreach ( IpPool::rows() as $row ) {
			if ( 'free' === $row['state'] ) {
				IpPool::block( $row['address'] );
			}
		}

		$this->assertSame( 0, IpPool::available_for_addon() );
		$this->mails = array();
		delete_transient( 'beltoft_services_ipv4_alert_sold_out' );

		$addon = $this->addon( $server );

		$this->assertSame( 'failed', $addon->get( 'state' ) );
		$subjects = array_column( $this->mails, 'subject' );
		$this->assertCount( 1, array_filter( $subjects, static fn( $s ) => false !== strpos( $s, 'no IPv4 address is free' ) ), 'The customer met sold out: one mail.' );

		$this->addon( Resource::find( $server->id() ) );
		$this->assertCount( 1, array_filter( array_column( $this->mails, 'subject' ), static fn( $s ) => false !== strpos( $s, 'no IPv4 address is free' ) ), 'A second time the same day: no second mail.' );
	}

	public function test_the_pool_mails_the_owner_when_it_runs_low_and_when_it_is_empty(): void {
		$this->settings( array( 'ipv4_alert_email' => 'owner@example.test', 'ipv4_low_mark' => 3 ) );
		delete_transient( 'beltoft_services_ipv4_alert_low' );
		delete_transient( 'beltoft_services_ipv4_alert_empty' );
		$this->mails = array();

		$free = array_values( array_filter( IpPool::rows(), static fn( $r ) => 'free' === $r['state'] ) );

		// Block down to four free: no mail yet; the fourth block leaves three: the low mail.
		for ( $i = 0; $i < count( $free ) - 4; $i++ ) {
			IpPool::block( $free[ $i ]['address'] );
		}

		$this->assertSame( array(), $this->mails );
		IpPool::block( $free[ count( $free ) - 4 ]['address'] );

		$this->assertCount( 1, $this->mails );
		$this->assertSame( 'owner@example.test', $this->mails[0]['to'] );
		$this->assertStringContainsString( 'free IPv4 addresses are left', $this->mails[0]['subject'] );

		// Further changes the same day: no second low mail.
		IpPool::block( $free[ count( $free ) - 3 ]['address'] );
		$this->assertCount( 1, $this->mails );

		IpPool::block( $free[ count( $free ) - 2 ]['address'] );
		IpPool::block( $free[ count( $free ) - 1 ]['address'] );
		$this->assertCount( 2, $this->mails );
		$this->assertStringContainsString( 'No free IPv4 address is left', $this->mails[1]['subject'] );
	}

	public function test_the_reserve_for_new_dedicated_servers_is_kept_but_a_paid_address_may_take_the_last_one(): void {
		$this->settings( array( 'ip_reserve_for_vps' => 1 ) );

		foreach ( array_slice( array_values( array_filter( IpPool::rows(), static fn( $r ) => 'free' === $r['state'] ) ), 1 ) as $row ) {
			IpPool::block( $row['address'] );
		}

		$this->assertSame( 1, IpPool::free_count() );
		$this->assertSame( 0, IpPool::available_for_addon(), 'The last address is kept for a new dedicated server.' );
		$this->assertFalse( \BeltoftServices\Proxmox\Ipv4\Ipv4Cart::in_stock() );
	}
}

<?php
/**
 * Shared set-up of the IPv4 add-on tests: a server on the fake Proxmox with its firewall, add-ons and the mails captured.
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

abstract class Ipv4TestCase extends FirewallTestCase {

	protected const PREFIX = '2001:db8:430c:1::/64';

	/** @var string[] Subjects of the mails sent. */
	protected $mails = array();

	public function set_up(): void {
		parent::set_up();

		$this->mails = array();
		remove_all_filters( 'beltoft_services_ipv4_alerts' );
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
	protected function server( int $public_ip ): Resource {
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
	protected function manage( Resource $r ): Resource {
		$normal = FirewallRules::normalise( array( array( 'proto' => 'tcp', 'from' => 22, 'to' => 22, 'sources' => array(), 'label' => '' ) ), $r );
		$this->assertIsArray( $normal );
		$this->assertGreaterThan( 0, FirewallRules::store( $r, true, $normal, true, 1 ) );
		Jobs::act( $r->id(), 'fw_apply', array() );

		return Resource::find( $r->id() );
	}

	/** One IPv4 add-on for a server, run until it is ready or stops. */
	protected function addon( Resource $server, ?int $user = null ): Resource {
		static $n = 0;
		$product = $this->service_product( Ipv4Driver::ID, array(), array( 'slug' => 'ipv4-' . ( ++$n ) ) );
		$license = $this->service_license( $product, array( 'fire' => false, 'user_id' => $user ?? $server->license()['user_id'] ) );
		$addon   = Resource::create( $license, Registry::get( Ipv4Driver::ID ), Product::plan( $product->get_id() ), array( 'server_license' => (int) $server->license()['id'] ) );
		$this->assertInstanceOf( Resource::class, $addon );
		$this->settle( $addon );

		return Resource::find( $addon->id() );
	}

	/** Runs the add-on and the jobs it queues on the server until nothing is left to do. */
	protected function settle( Resource $addon ): void {
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
	protected function set(): array {
		$cidrs = array_column( (array) ( $this->fake->vm( 5000 )['firewall_ipsets']['ipfilter-net0'] ?? array() ), 'cidr' );
		sort( $cidrs );

		return $cidrs;
	}

	protected function config(): array {
		return (array) $this->fake->request( 'GET', '/nodes/node-a/qemu/5000/config' );
	}
}

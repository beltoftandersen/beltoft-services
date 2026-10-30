<?php
/**
 * The build-profile seam of Provision and Operations::cleanup. Only the fake client is used.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Proxmox;

use BeltoftServices\Drivers\Registry;
use BeltoftServices\Drivers\StepResult;
use BeltoftServices\Product;
use BeltoftServices\Proxmox\BuildProfile;
use BeltoftServices\Proxmox\Clusters;
use BeltoftServices\Proxmox\FakeClient;
use BeltoftServices\Proxmox\IpPool;
use BeltoftServices\Proxmox\Profiles;
use BeltoftServices\Proxmox\ProxmoxDriver;
use BeltoftServices\Proxmox\Schema;
use BeltoftServices\Proxmox\VmRecords;
use BeltoftServices\Proxmox\VpsProfile;
use BeltoftServices\Resource;
use BeltoftServices\Tests\TestCase;

/**
 * A profile with no public IP, no `ip` step, a fixed cicustom and its own meta.
 */
class NoIpProfile implements BuildProfile {

	/**
	 * Address holds asked for.
	 *
	 * @var int
	 */
	public static $holds = 0;

	public function steps(): array {
		return array( 'vmid', 'clone', 'configure', 'start', 'ready' );
	}

	public function uses_public_ip(): bool {
		return false;
	}

	public function hold_address( int $resource_id ): ?array {
		++self::$holds;

		return null;
	}

	public function network_params( array $cluster, ?array $ip, string $net0 ): array {
		return array( 'net0' => $net0 );
	}

	public function cicustom( array $cluster ): ?string {
		return 'vendor=local:snippets/fake.yaml';
	}

	public function post_build_meta( array $record, ?array $ip ): array {
		return array(
			'node' => $record['node'],
			'vmid' => $record['vmid'],
			'task' => null,
			'fake' => 'yes',
		);
	}
}

/**
 * The seam: the default profile, a filtered one, and garbage from the filter.
 */
class BuildProfileTest extends TestCase {

	/**
	 * The fake cluster.
	 *
	 * @var FakeClient
	 */
	private $fake;

	/**
	 * The driver under test (no sleeping).
	 *
	 * @var QuickProxmoxDriver
	 */
	private $driver;

	public function set_up(): void {
		parent::set_up();

		$key = base64_encode( random_bytes( 32 ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Test key.
		add_filter( 'beltoft_services_key', fn() => $key );

		$this->wipe_tables();
		delete_option( Clusters::OPTION );
		$this->settings( array( 'task_wait' => 5, 'admin_email' => 'christian.beltoft@gmail.com', 'deletion_mode' => 'on' ) );
		NoIpProfile::$holds = 0;

		$this->fake = new FakeClient();
		add_filter( 'beltoft_services_proxmox_client', fn() => $this->fake );

		$this->driver = new QuickProxmoxDriver();
		add_filter(
			'beltoft_services_drivers',
			function ( $drivers ) {
				$drivers['proxmox'] = $this->driver;
				return $drivers;
			}
		);

		$this->fake->add_node( 'node-a' );
		$this->fake->add_vm(
			array(
				'vmid'     => 9000,
				'node'     => 'node-a',
				'name'     => 'debian-template',
				'template' => 1,
				'config'   => array(
					'scsi0'  => 'disk-store:vm-9000-disk-0,size=10G',
					'net0'   => 'virtio=AA:BB:CC:DD:EE:FF,bridge=vmbr0,tag=5',
					'agent'  => '1',
					'memory' => 512,
					'cores'  => 1,
				),
			)
		);
		$this->assertTrue(
			Clusters::save(
				'lab',
				array(
					'label'             => 'Lab cluster',
					'api_url'           => 'https://pve.example.invalid:8006',
					'token_id'          => 'svc@pve!beltoft',
					'token_secret'      => 'secret-secret-secret-1',
					'client'            => 'fake',
					'pool_api'          => 'pools',
					'nodes'             => 'node-a',
					'storage'           => 'disk-store',
					'cloudinit_storage' => 'ci-store',
					'bridge'            => 'vmbr9',
					'vlan'              => '',
					'pool'              => '',
					'boot_disk'         => 'scsi0',
					'gateway'           => '192.0.2.1',
					'nameservers'       => '192.0.2.53',
					'prefix'            => '24',
					'vmid_min'          => '5000',
					'vmid_max'          => '5099',
					'templates'         => '9000 = Debian 12',
					'vendor_snippet'    => '',
					'block_smtp'        => '1',
				)
			)
		);
		set_transient(
			\BeltoftServices\Proxmox\Placement::CAPACITY_PREFIX . 'lab',
			array(
				array(
					'node'   => 'node-a',
					'status' => 'online',
					'maxmem' => 68719476736,
					'mem'    => 0,
				),
			),
			HOUR_IN_SECONDS
		);
		IpPool::add( '192.0.2.10' );

		as_unschedule_all_actions( \BeltoftServices\Jobs::SWEEP, array(), \BeltoftServices\Jobs::GROUP );
	}

	public function tear_down(): void {
		remove_all_filters( 'beltoft_services_build_profile' );
		remove_all_filters( 'beltoft_services_key' );
		remove_all_filters( 'beltoft_services_proxmox_client' );
		delete_option( Clusters::OPTION );
		$this->wipe_tables();

		parent::tear_down();
	}

	private function wipe_tables(): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- test fixture reset.
		$wpdb->query( 'DELETE FROM ' . Schema::tables()['vms'] );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- test fixture reset.
		$wpdb->query( 'DELETE FROM ' . Schema::tables()['ips'] );
		delete_transient( IpPool::FREE_TRANSIENT );
	}

	private function resource(): Resource {
		$product  = $this->service_product(
			'proxmox',
			array(
				'cluster'   => 'lab',
				'template'  => 9000,
				'cores'     => 2,
				'memory_mb' => 4096,
				'disk_gb'   => 30,
			),
			array( 'max' => 3 )
		);
		$license  = $this->service_license( $product, array( 'fire' => false ) );
		$resource = Resource::create( $license, Registry::get( 'proxmox' ), Product::plan( $product->get_id() ), array() );

		$this->assertInstanceOf( Resource::class, $resource );

		return $resource;
	}

	private function run_provision( Resource $r ): StepResult {
		return $this->driver->provision( Resource::find( $r->id() ) );
	}

	private function use_no_ip_profile(): void {
		add_filter( 'beltoft_services_build_profile', fn() => new NoIpProfile() );
	}

	public function test_the_default_profile_is_the_vps_profile(): void {
		$profile = Profiles::for_resource( $this->resource() );

		$this->assertInstanceOf( VpsProfile::class, $profile );
		$this->assertSame( array( 'wipe', 'ip', 'vmid', 'clone', 'configure', 'start', 'ready' ), $profile->steps() );
		$this->assertTrue( $profile->uses_public_ip() );
	}

	public function test_the_filter_gets_the_default_and_the_resource(): void {
		$resource = $this->resource();
		$seen     = array();
		add_filter(
			'beltoft_services_build_profile',
			function ( $profile, $r ) use ( &$seen ) {
				$seen = array( $profile, $r );
				return $profile;
			},
			10,
			2
		);

		Profiles::for_resource( $resource );

		$this->assertInstanceOf( VpsProfile::class, $seen[0] );
		$this->assertSame( $resource->id(), $seen[1]->id() );
	}

	public function test_a_filtered_profile_sets_the_step_order_and_skips_the_address(): void {
		$this->use_no_ip_profile();
		$resource = $this->resource();

		$result = $this->run_provision( $resource );
		$this->assertTrue( $result->is_done(), $result->error() );

		$fresh = Resource::find( $resource->id() );
		$this->assertSame( array( 'vmid', 'clone', 'configure', 'start', 'ready' ), $fresh->get( 'steps' ) );
		$this->assertSame( 'yes', $fresh->get( 'fake' ) );
		$this->assertSame( 0, NoIpProfile::$holds );
		$this->assertNull( IpPool::for_resource( $resource->id() ), 'No public address was held.' );
		$this->assertSame( 'running', $this->fake->vm( 5000 )['status'] );

		$put = array_values(
			array_filter(
				$this->fake->calls(),
				static fn( $call ) => 'PUT' === $call['method'] && '/config' === substr( $call['path'], -7 )
			)
		);
		$this->assertNotEmpty( $put );
		$this->assertArrayNotHasKey( 'ipconfig0', $put[0]['params'] );
		$this->assertSame( 'vendor=local:snippets/fake.yaml', $put[0]['params']['cicustom'] );
	}

	public function test_cleanup_gives_the_address_back_for_the_vps_profile(): void {
		$resource = $this->resource();
		$this->assertTrue( $this->run_provision( $resource )->is_done() );
		$this->assertNotNull( IpPool::for_resource( $resource->id() ) );

		$result = Registry::get( 'proxmox' )->delete( Resource::find( $resource->id() ) );

		$this->assertTrue( $result->is_done(), $result->error() );
		$this->assertNull( IpPool::for_resource( $resource->id() ) );
		$this->assertNull( VmRecords::for_resource( $resource->id() ) );
	}

	public function test_cleanup_leaves_the_pool_alone_when_the_profile_holds_no_public_ip(): void {
		$resource = $this->resource();
		$this->use_no_ip_profile();
		$this->assertTrue( $this->run_provision( $resource )->is_done() );

		// An address held for the resource by something else: a profile without a public IP must not free it.
		$this->assertNotNull( IpPool::hold( $resource->id() ) );

		$result = Registry::get( 'proxmox' )->delete( Resource::find( $resource->id() ) );

		$this->assertTrue( $result->is_done(), $result->error() );
		$this->assertNotNull( IpPool::for_resource( $resource->id() ), 'IpPool::release was not called.' );
		$this->assertNull( VmRecords::for_resource( $resource->id() ) );
	}

	/**
	 * @dataProvider garbage
	 * @param mixed $value What the filter returns.
	 */
	public function test_a_filter_returning_garbage_falls_back_to_the_vps_profile( $value ): void {
		add_filter( 'beltoft_services_build_profile', fn() => $value );
		$resource = $this->resource();

		$this->assertInstanceOf( VpsProfile::class, Profiles::for_resource( $resource ) );

		$result = $this->run_provision( $resource );
		$this->assertTrue( $result->is_done(), $result->error() );
		$this->assertSame( array( 'ip', 'vmid', 'clone', 'configure', 'start', 'ready' ), Resource::find( $resource->id() )->get( 'steps' ) );
		$this->assertSame( 'assigned', IpPool::for_resource( $resource->id() )['state'] );
	}

	/**
	 * @return array<string, array<int, mixed>>
	 */
	public static function garbage(): array {
		return array(
			'null'   => array( null ),
			'string' => array( 'VpsProfile' ),
			'array'  => array( array( 'steps' ) ),
			'object' => array( new \stdClass() ),
		);
	}
}

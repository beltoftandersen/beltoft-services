<?php
/**
 * The live wiring with the allowance defined but no site tag. Only the fake client exists here:
 * the point is that a stored live cluster is refused, never faked, and nothing is sent.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Proxmox;

use BeltoftServices\Drivers\Registry;
use BeltoftServices\Drivers\StepResult;
use BeltoftServices\Product;
use BeltoftServices\Proxmox\Clusters;
use BeltoftServices\Proxmox\FakeClient;
use BeltoftServices\Proxmox\IpPool;
use BeltoftServices\Proxmox\LinkVm;
use BeltoftServices\Proxmox\ProxmoxDriver;
use BeltoftServices\Proxmox\ProxmoxJobs;
use BeltoftServices\Proxmox\Schema;
use BeltoftServices\Proxmox\VmRecords;
use BeltoftServices\Resource;
use BeltoftServices\Settings;
use BeltoftServices\Tests\TestCase;

/**
 * A ProxmoxDriver that does not sleep.
 */
class LiveWiringDriver extends ProxmoxDriver {

	public function nap( int $seconds ): void {
		unset( $seconds );
	}
}

/**
 * Runs in its own process: BELTOFT_SERVICES_PROXMOX_LIVE is a constant and cannot be undefined.
 * There is no seam in Clients::live_allowed(); the constant is really defined.
 */
class LiveWiringTest extends TestCase {

	private const SECRET = 'secret-secret-secret-1';

	/**
	 * The fake cluster that builds the VM before the cluster is turned live.
	 *
	 * @var FakeClient
	 */
	private $fake;

	/**
	 * Mails sent.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private $mails = array();

	/**
	 * Every outbound request, whatever the recorder answers.
	 *
	 * @var string[]
	 */
	private $outbound = array();

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_live_cluster_without_a_site_tag_retries_skips_or_refuses_everywhere(): void {
		define( 'BELTOFT_SERVICES_PROXMOX_LIVE', true );

		$key = base64_encode( random_bytes( 32 ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Test key.
		add_filter( 'beltoft_services_key', fn() => $key );
		add_filter(
			'pre_wp_mail',
			function ( $pre, $atts ) {
				$this->mails[] = $atts;
				return true;
			},
			10,
			2
		);
		add_filter(
			'pre_http_request',
			function ( $pre, $args, $url ) {
				unset( $args );
				$this->outbound[] = $url;
				return new \WP_Error( 'blocked', 'No outbound call is allowed in this test.' );
			},
			1,
			3
		);

		$this->wipe_tables();
		delete_option( Clusters::OPTION );
		$this->settings( array( 'admin_email' => 'ops@example.com', 'task_wait' => 5 ) );
		$this->assertTrue( \BeltoftServices\Proxmox\Clients::live_allowed() );

		// A fake cluster builds a running VM and has a license waiting for its service.
		$this->fake = new FakeClient();
		add_filter( 'beltoft_services_proxmox_client', fn() => $this->fake );
		$driver = new LiveWiringDriver();
		add_filter(
			'beltoft_services_drivers',
			static function ( $drivers ) use ( $driver ) {
				$drivers['proxmox'] = $driver;
				return $drivers;
			}
		);
		$this->fake->add_node( 'node-a' );
		$this->fake->add_vm(
			array(
				'vmid'     => 9000,
				'node'     => 'node-a',
				'name'     => 'template',
				'template' => 1,
				'config'   => array(
					'scsi0'  => 'disk-store:vm-9000-disk-0,size=10G',
					'net0'   => 'virtio=AA:BB:CC:DD:EE:FF,bridge=vmbr0',
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
					'token_secret'      => self::SECRET,
					'client'            => 'fake',
					'pool_api'          => 'pools',
					'nodes'             => 'node-a',
					'storage'           => 'disk-store',
					'cloudinit_storage' => 'ci-store',
					'bridge'            => 'vmbr9',
					'gateway'           => '192.0.2.1',
					'nameservers'       => '192.0.2.53',
					'prefix'            => '24',
					'vmid_min'          => '5000',
					'vmid_max'          => '5099',
					'templates'         => '9000 = Debian 12',
					'block_smtp'        => '1',
				)
			)
		);
		$this->seed_capacity();
		IpPool::add( '192.0.2.10' );
		IpPool::add( '192.0.2.11' );

		$ready = $this->resource( 'vm-a' );
		$this->assertTrue( $driver->provision( Resource::find( $ready->id() ) )->is_done() );
		$ready = Resource::find( $ready->id() );
		$this->assertSame( 5000, $ready->get( 'vmid' ) );

		$free    = '192.0.2.10' === $ready->get( 'ip' ) ? '192.0.2.11' : '192.0.2.10';
		$waiting = $this->resource( 'vm-b' );
		$linking = $this->service_license( $this->service_product( 'proxmox', array( 'cluster' => 'lab', 'template' => 9000, 'cores' => 1, 'memory_mb' => 1024, 'disk_gb' => 10 ), array( 'slug' => 'vm-c' ) ), array( 'fire' => false ) );

		// The cluster is stored as live, and the site tag was never set.
		$stored                  = get_option( Clusters::OPTION );
		$stored['lab']['client'] = 'live';
		update_option( Clusters::OPTION, $stored, false );
		$this->assertSame( '', Settings::get( 'site_tag' ) );
		$this->assertSame( 'live', Clusters::all()['lab']['client'], 'Stored live and allowed: it stays live, never fake.' );

		$calls_before = count( $this->fake->calls() );
		$this->mails  = array();

		// 1. Provisioning waits.
		$result = $driver->provision( Resource::find( $waiting->id() ) );
		$this->assert_waits( $result );
		$this->assertNull( VmRecords::for_resource( $waiting->id() ), 'No VM row.' );
		$this->assertSame( 'free', IpPool::find( $free )['state'] );
		$this->assertNull( IpPool::for_resource( $waiting->id() ) );

		// 2. A customer action waits.
		$this->assert_waits( $driver->act( Resource::find( $ready->id() ), 'shutdown', array() ) );
		$this->assert_waits( $driver->act( Resource::find( $ready->id() ), 'reboot', array() ) );

		// 3. Operations wait.
		$this->assert_waits( $driver->pause( Resource::find( $ready->id() ) ) );
		$this->assert_waits( $driver->resume( Resource::find( $ready->id() ) ) );
		$this->assert_waits( $driver->update_plan( Resource::find( $ready->id() ) ) );
		$this->assert_waits( $driver->delete( Resource::find( $ready->id() ) ) );

		// 4. The jobs skip the cluster.
		ProxmoxJobs::capacity();
		ProxmoxJobs::power();
		ProxmoxJobs::abuse();
		ProxmoxJobs::purge_passwords();

		// 5. The CLI refuses.
		$linked = LinkVm::run(
			(int) $linking['id'],
			array(
				'cluster' => 'lab',
				'node'    => 'node-a',
				'vmid'    => 5050,
				'ip'      => $free,
				'confirm' => 'LINK:5050',
			)
		);
		$this->assertWPError( $linked );
		$this->assertStringContainsString( 'Set the site tag for cluster lab', $linked->get_error_message() );
		$this->assertNull( Resource::for_license( (int) $linking['id'] ) );
		$this->assertArrayNotHasKey( 5050, VmRecords::for_cluster( 'lab' ) );

		// Nothing was sent, nothing reached the fake cluster, and the admin was told once.
		$this->assertSame( array(), $this->outbound, 'No outbound HTTP request.' );
		$this->assertCount( $calls_before, $this->fake->calls(), 'The fake cluster was not used as a stand-in either.' );
		$this->assertSame( 5000, VmRecords::for_resource( $ready->id() )['vmid'], 'The existing VM row is untouched.' );
		$this->assertCount( 1, $this->mails, 'One admin alert.' );
		$this->assertStringContainsString( 'Set the site tag for cluster lab', (string) $this->mails[0]['subject'] );
	}

	public function tear_down(): void {
		$this->wipe_tables();
		delete_option( Clusters::OPTION );

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

	private function assert_waits( StepResult $result ): void {
		$this->assertTrue( $result->is_retry(), 'Expected a retry, got: ' . $result->error() );
		$this->assertStringContainsString( 'Set the site tag for cluster lab', $result->error() );
	}

	/**
	 * A resource of a new license.
	 *
	 * @param string $slug Product slug.
	 */
	private function resource( string $slug ): Resource {
		$product  = $this->service_product( 'proxmox', array( 'cluster' => 'lab', 'template' => 9000, 'cores' => 2, 'memory_mb' => 4096, 'disk_gb' => 30 ), array( 'max' => 3, 'slug' => $slug ) );
		$license  = $this->service_license( $product, array( 'fire' => false ) );
		$resource = Resource::create( $license, Registry::get( 'proxmox' ), Product::plan( $product->get_id() ) );

		$this->assertInstanceOf( Resource::class, $resource );

		return $resource;
	}
}

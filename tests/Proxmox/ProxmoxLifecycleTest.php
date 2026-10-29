<?php
/**
 * Pause, resume, delete, plan changes and status of a Proxmox VM. Only the fake client is used: nothing here reaches a Proxmox host.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Proxmox;

use BeltoftServices\Drivers\Registry;
use BeltoftServices\Drivers\StepResult;
use BeltoftServices\Jobs;
use BeltoftServices\Product;
use BeltoftServices\Proxmox\Access;
use BeltoftServices\Proxmox\Clusters;
use BeltoftServices\Proxmox\FakeClient;
use BeltoftServices\Proxmox\IpPool;
use BeltoftServices\Proxmox\Operations;
use BeltoftServices\Proxmox\Ownership;
use BeltoftServices\Proxmox\PrivateNets;
use BeltoftServices\Proxmox\ProxmoxDriver;
use BeltoftServices\Proxmox\Schema;
use BeltoftServices\Proxmox\Switches;
use BeltoftServices\Proxmox\VmRecords;
use BeltoftServices\Resource;
use BeltoftServices\Retention;
use BeltoftServices\Tests\TestCase;

/**
 * A ProxmoxDriver that records its sleeps instead of sleeping.
 */
class QuickLifecycleDriver extends ProxmoxDriver {

	/**
	 * Sleeps asked for.
	 *
	 * @var int[]
	 */
	public $naps = array();

	public function nap( int $seconds ): void {
		$this->naps[] = $seconds;
	}
}

/**
 * A fake cluster that can be told to swallow config writes.
 */
class ScriptableLifecycleClient extends FakeClient {

	/**
	 * Accept PUT config without applying it.
	 *
	 * @var bool
	 */
	public $deaf_config = false;

	/**
	 * Undo the power change right after a task: 'stop' puts a stopped VM back to running (an HA
	 * group or a watchdog), 'start' puts a started VM back to stopped (a guest that crashes on boot).
	 *
	 * @var string
	 */
	public $bounce = '';

	/**
	 * Called after every task is created (a hook for the test to change the world mid-run).
	 *
	 * @var callable|null
	 */
	public $after_task = null;

	public function task( string $method, string $path, array $params = array() ) {
		$upid = parent::task( $method, $path, $params );

		if ( ! is_wp_error( $upid ) && 1 === preg_match( '#/qemu/(\d+)/status/(shutdown|stop|start)$#', $path, $m ) ) {
			$vm = $this->vm( (int) $m[1] );

			if ( $vm && 'stop' === $this->bounce && 'start' !== $m[2] ) {
				$this->add_vm( array_merge( $vm, array( 'status' => 'running' ) ) );
			} elseif ( $vm && 'start' === $this->bounce && 'start' === $m[2] ) {
				$this->add_vm( array_merge( $vm, array( 'status' => 'stopped' ) ) );
			}
		}

		if ( $this->after_task ) {
			( $this->after_task )( $method, $path );
		}

		return $upid;
	}

	public function request( string $method, string $path, array $params = array() ) {
		if ( $this->deaf_config && 'PUT' === strtoupper( $method ) && 1 === preg_match( '#/config$#', $path ) ) {
			return null;
		}

		return parent::request( $method, $path, $params );
	}
}

/**
 * The operations after provisioning, against the fake cluster.
 */
class ProxmoxLifecycleTest extends TestCase {

	private const SECRET = 'secret-secret-secret-1';

	/**
	 * The fake cluster.
	 *
	 * @var ScriptableLifecycleClient
	 */
	private $fake;

	/**
	 * The driver under test.
	 *
	 * @var QuickLifecycleDriver
	 */
	private $driver;

	/**
	 * Mails sent.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private $mails = array();

	public function set_up(): void {
		parent::set_up();

		$this->mails = array();
		add_filter(
			'pre_wp_mail',
			function ( $pre, $atts ) {
				$this->mails[] = $atts;
				return true;
			},
			10,
			2
		);

		$key = base64_encode( random_bytes( 32 ) );
		add_filter( 'beltoft_services_key', fn() => $key );

		$this->wipe_tables();
		delete_option( Clusters::OPTION );
		$this->settings( array( 'task_wait' => 5 ) );

		$this->fake = new ScriptableLifecycleClient();
		add_filter( 'beltoft_services_proxmox_client', fn() => $this->fake );

		$this->driver = new QuickLifecycleDriver();
		add_filter(
			'beltoft_services_drivers',
			function ( $drivers ) {
				$drivers['proxmox'] = $this->driver;
				return $drivers;
			}
		);

		$this->fake->add_node( 'node-a' );
		$this->template();
		$this->cluster();
		$this->seed_capacity();
		IpPool::add( '192.0.2.10' );
		IpPool::add( '192.0.2.11' );

		as_unschedule_all_actions( Jobs::SWEEP, array(), Jobs::GROUP );
	}

	public function tear_down(): void {
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

	/**
	 * The template: VMID 9000 on node-a with one 10G disk, a NIC with a MAC and a tag, and the agent.
	 *
	 * @param array<string, mixed> $over Overrides of the VM.
	 */
	private function template( array $over = array() ): void {
		$this->fake->add_vm(
			array_merge(
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
				),
				$over
			)
		);
	}

	/**
	 * Saves the cluster "lab".
	 *
	 * @param array<string, mixed> $over Form overrides.
	 */
	private function cluster( array $over = array() ): void {
		$saved = Clusters::save(
			'lab',
			array_merge(
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
					'vlan'              => '',
					'pool'              => '',
					'boot_disk'         => 'scsi0',
					'gateway'           => '192.0.2.1',
					'nameservers'       => '192.0.2.53, 192.0.2.54',
					'prefix'            => '24',
					'vmid_min'          => '5000',
					'vmid_max'          => '5099',
					'templates'         => "9000 = Debian 12\n9001 = Ubuntu 24.04",
					'vendor_snippet'    => '',
					'block_smtp'        => '1',
				),
				$over
			)
		);

		$this->assertTrue( $saved );
	}

	/**
	 * A resource of a new license, ready to be provisioned.
	 *
	 * @param array<string, mixed> $plan Plan overrides.
	 * @param array<string, mixed> $meta Initial meta.
	 * @param int|null             $user Owner.
	 */
	private function resource( array $plan = array(), array $meta = array(), ?int $user = null ): Resource {
		$product = $this->service_product(
			'proxmox',
			array_merge(
				array(
					'cluster'   => 'lab',
					'template'  => 9000,
					'cores'     => 2,
					'memory_mb' => 4096,
					'disk_gb'   => 30,
				),
				$plan
			),
			array( 'max' => 3 )
		);

		$data = array( 'fire' => false );

		if ( null !== $user ) {
			$data['user_id'] = $user;
		}

		$license  = $this->service_license( $product, $data );
		$resource = Resource::create( $license, Registry::get( 'proxmox' ), Product::plan( $product->get_id() ), $meta );

		$this->assertInstanceOf( Resource::class, $resource );

		return $resource;
	}

	/**
	 * One run of the driver, as the reconciler would make it (a fresh read each time).
	 *
	 * @param Resource $r Resource.
	 */
	private function run_provision( Resource $r ): StepResult {
		return $this->driver->provision( Resource::find( $r->id() ) );
	}

	/**
	 * Calls matching a method and a path ending.
	 *
	 * @param string $method Method.
	 * @param string $suffix Path ending.
	 * @return array<int, array<string, mixed>>
	 */
	private function calls( string $method, string $suffix ): array {
		return array_values(
			array_filter(
				$this->fake->calls(),
				static fn( $call ) => $call['method'] === $method && substr( $call['path'], -strlen( $suffix ) ) === $suffix
			)
		);
	}

	private function assert_done( StepResult $result ): void {
		$this->assertTrue( $result->is_done(), 'Expected done, got: ' . $result->error() );
	}

	private function ed25519(): string {
		$blob = pack( 'N', 11 ) . 'ssh-ed25519' . pack( 'N', 32 ) . random_bytes( 32 );

		return 'ssh-ed25519 ' . base64_encode( $blob ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Test key.
	}

	/**
	 * A provisioned, running VM, the resource read fresh.
	 */
	private function built( array $plan = array() ): Resource {
		$resource = $this->resource( $plan );
		$this->assert_done( $this->run_provision( $resource ) );

		return Resource::find( $resource->id() );
	}

	private function op( string $operation, Resource $r ): StepResult {
		return $this->driver->$operation( Resource::find( $r->id() ) );
	}

	/**
	 * Calls made after a mark, as "METHOD path".
	 *
	 * @param int $seen Calls to skip.
	 * @return string[]
	 */
	private function since( int $seen ): array {
		return array_map( static fn( $c ) => $c['method'] . ' ' . $c['path'], array_slice( $this->fake->calls(), $seen ) );
	}

	private function assert_only_reads( int $seen ): void {
		foreach ( array_slice( $this->fake->calls(), $seen ) as $call ) {
			$this->assertSame( 'GET', $call['method'], 'Read-only: ' . $call['method'] . ' ' . $call['path'] );
		}
	}

	/**
	 * Powers the fake VM off the way a customer would from inside the guest.
	 */
	private function power_off( int $vmid ): void {
		$upid = $this->fake->task( 'POST', '/nodes/node-a/qemu/' . $vmid . '/status/shutdown' );
		$this->assertTrue( true === $this->fake->wait_task( $upid, 10 ) );
	}

	public function test_medusa_pause_shuts_down_before_stopping(): void {
		$r    = $this->built();
		$seen = count( $this->fake->calls() );

		$this->assert_done( $this->op( 'pause', $r ) );

		$paths = $this->since( $seen );
		$this->assertContains( 'POST /nodes/node-a/qemu/5000/status/shutdown', $paths );
		$this->assertNotContains( 'POST /nodes/node-a/qemu/5000/status/stop', $paths, 'A clean shutdown needs no plug pull.' );
		$this->assertSame( 'stopped', $this->fake->vm( 5000 )['status'] );

		$shutdown = $this->calls( 'POST', '/status/shutdown' );
		$this->assertSame( 120, (int) $shutdown[0]['params']['timeout'] );
	}

	public function test_pause_stops_only_if_shutdown_task_leaves_it_running(): void {
		$r = $this->built();
		$this->fake->next_task( array( 'exitstatus' => 'command timed out - aborting', 'effect' => false ) );
		$seen = count( $this->fake->calls() );

		$this->assert_done( $this->op( 'pause', $r ) );

		$paths    = $this->since( $seen );
		$shutdown = array_search( 'POST /nodes/node-a/qemu/5000/status/shutdown', $paths, true );
		$stop     = array_search( 'POST /nodes/node-a/qemu/5000/status/stop', $paths, true );
		$this->assertNotFalse( $shutdown );
		$this->assertNotFalse( $stop );
		$this->assertLessThan( $stop, $shutdown );
		$this->assertSame( 'stopped', $this->fake->vm( 5000 )['status'] );
	}

	public function test_pause_refuses_untagged_vm(): void {
		$r = $this->built();
		$this->fake->request( 'PUT', '/nodes/node-a/qemu/5000/config', array( 'description' => 'someone else' ) );
		$seen = count( $this->fake->calls() );

		$result = $this->op( 'pause', $r );

		$this->assertTrue( $result->is_failed() );
		$this->assertStringContainsString( 'Ownership check failed', $result->error() );
		$this->assert_only_reads( $seen );
		$this->assertSame( 'running', $this->fake->vm( 5000 )['status'] );
	}

	public function test_pause_of_a_half_built_resource_makes_no_vm_call(): void {
		$resource = $this->resource();

		// Nothing recorded at all.
		$this->assert_done( $this->op( 'pause', $resource ) );

		// A VMID recorded, the clone never finished.
		$this->fake->fail_next( 'POST', '#/clone$#', array( 'status' => 500, 'message' => 'storage is busy' ) );
		$this->assertTrue( $this->run_provision( $resource )->is_retry() );
		$this->assertNotNull( VmRecords::for_resource( $resource->id() ) );
		$seen = count( $this->fake->calls() );

		$this->assert_done( $this->op( 'pause', $resource ) );
		$this->assertCount( $seen, $this->fake->calls() );
	}

	public function test_pause_waits_for_a_pending_clone(): void {
		$resource = $this->resource();
		$this->fake->next_task( array( 'polls' => 400 ) );
		$this->assertTrue( $this->run_provision( $resource )->is_retry() );
		$task = Resource::find( $resource->id() )->get( 'task' );
		$this->assertNotEmpty( $task['upid'] );
		$seen = count( $this->fake->calls() );

		$result = $this->op( 'pause', $resource );

		$this->assertTrue( $result->is_retry() );
		$this->assert_only_reads( $seen );
		$this->assertSame( $task['upid'], Resource::find( $resource->id() )->get( 'task' )['upid'], 'The task id is kept.' );
	}

	public function test_resume_starts(): void {
		$r = $this->built();
		$this->assert_done( $this->op( 'pause', $r ) );
		$this->assertSame( 'stopped', $this->fake->vm( 5000 )['status'] );

		$this->assert_done( $this->op( 'resume', $r ) );
		$this->assertSame( 'running', $this->fake->vm( 5000 )['status'] );

		// Already running: no start call.
		$seen = count( $this->fake->calls() );
		$this->assert_done( $this->op( 'resume', $r ) );
		$this->assertSame( array(), array_filter( $this->since( $seen ), static fn( $p ) => 0 === strpos( $p, 'POST' ) ) );
	}

	public function test_resume_after_a_pause_mid_provision_finishes_provisioning(): void {
		$resource = $this->resource();
		$this->fake->fail_next( 'POST', '#/clone$#', array( 'status' => 500, 'message' => 'storage is busy' ) );
		$this->assertTrue( $this->run_provision( $resource )->is_retry() );
		$this->assert_done( $this->op( 'pause', $resource ) );
		$this->assertNull( $this->fake->vm( 5000 ) );

		$this->assert_done( $this->op( 'resume', $resource ) );

		$fresh = Resource::find( $resource->id() );
		$this->assertSame( array( 'ip', 'vmid', 'clone', 'configure', 'start', 'ready' ), $fresh->get( 'steps' ) );
		$this->assertSame( 'running', $this->fake->vm( 5000 )['status'] );
	}

	public function test_delete_releases_ip_and_record(): void {
		$r     = $this->built();
		$owner = (int) $r->license()['user_id'];
		$this->assertNotNull( IpPool::for_resource( $r->id() ) );
		$this->assertNotEmpty( $r->get( Access::META_REVEAL ) );
		$free = IpPool::free_count();
		$seen = count( $this->fake->calls() );

		$this->assert_done( $this->op( 'delete', $r ) );

		$paths    = $this->since( $seen );
		$shutdown = array_search( 'POST /nodes/node-a/qemu/5000/status/shutdown', $paths, true );
		$delete   = array_search( 'DELETE /nodes/node-a/qemu/5000', $paths, true );
		$this->assertNotFalse( $shutdown );
		$this->assertNotFalse( $delete );
		$this->assertLessThan( $delete, $shutdown );

		$call = $this->calls( 'DELETE', '/qemu/5000' )[0];
		$this->assertSame( 1, (int) $call['params']['purge'] );
		$this->assertSame( 1, (int) $call['params']['destroy-unreferenced-disks'] );

		$this->assertNull( $this->fake->vm( 5000 ) );
		$this->assertNull( VmRecords::for_resource( $r->id() ) );
		$this->assertNull( IpPool::for_resource( $r->id() ) );
		$this->assertSame( $free + 1, IpPool::free_count() );
		$this->assertNull( Access::take_reveal( Resource::find( $r->id() ), $owner ), 'No unread password survives the VM.' );
		$this->assertEmpty( Resource::find( $r->id() )->get( Access::META_REVEAL ) );
	}

	public function test_delete_releases_private_address_and_quarantines_empty_network(): void {
		$r = $this->built();
		$owner = (int) $r->license()['user_id'];
		$this->assertTrue( PrivateNets::add( 1000, '10.64.0.0/28' ) );
		$this->assertNotNull( PrivateNets::hold( $owner ) );
		$this->assertSame( '10.64.0.2', PrivateNets::address( $owner, $r->id() ) );

		$this->assert_done( $this->op( 'delete', $r ) );
		$this->assertNull( PrivateNets::address_for_resource( $r->id() ) );
		$this->assertSame( 'quarantine', PrivateNets::for_user( $owner )['state'] );
	}

	public function test_delete_refuses_a_vm_that_is_not_ours(): void {
		$r = $this->built();
		$this->fake->request( 'PUT', '/nodes/node-a/qemu/5000/config', array( 'description' => 'someone else' ) );
		$seen = count( $this->fake->calls() );

		$result = $this->op( 'delete', $r );

		$this->assertTrue( $result->is_failed() );
		$this->assertStringContainsString( 'Ownership check failed', $result->error() );
		$this->assert_only_reads( $seen );
		$this->assertNotNull( VmRecords::for_resource( $r->id() ), 'The record stays.' );
		$this->assertNotNull( IpPool::for_resource( $r->id() ) );
		$this->assertNotNull( $this->fake->vm( 5000 ) );
	}

	public function test_delete_refuses_an_id_outside_the_customer_range(): void {
		$r = $this->built();
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- test fixture.
		$wpdb->update( Schema::tables()['vms'], array( 'vmid' => 4000 ), array( 'resource_id' => $r->id() ) );
		$seen = count( $this->fake->calls() );

		$this->assertTrue( $this->op( 'delete', $r )->is_failed() );
		$this->assert_only_reads( $seen );
	}

	public function test_delete_of_a_vm_already_gone_is_done_only_when_the_id_is_free(): void {
		$r = $this->built();
		$this->power_off( 5000 );
		$upid = $this->fake->task( 'DELETE', '/nodes/node-a/qemu/5000', array( 'purge' => 1 ) );
		$this->assertTrue( true === $this->fake->wait_task( $upid, 10 ) );
		$this->assertNull( $this->fake->vm( 5000 ) );

		// The id cannot be confirmed free: not done.
		$this->fake->fail_next( 'GET', '#/cluster/nextid$#', array( 'status' => 500, 'message' => 'busy' ) );
		$result = $this->op( 'delete', $r );
		$this->assertTrue( $result->is_retry() );
		$this->assertNotNull( VmRecords::for_resource( $r->id() ) );
		$this->assertNotNull( IpPool::for_resource( $r->id() ) );

		// The id is taken (not visible to us): not done either.
		$this->fake->fail_next( 'GET', '#/cluster/nextid$#', array( 'status' => 400, 'message' => 'VM 5000 already exists' ) );
		$this->assertTrue( $this->op( 'delete', $r )->is_retry() );
		$this->assertNotNull( VmRecords::for_resource( $r->id() ) );

		// Free: done, and no DELETE call of ours.
		$seen = count( $this->fake->calls() );
		$this->assert_done( $this->op( 'delete', $r ) );
		$this->assertSame( array(), array_filter( $this->since( $seen ), static fn( $p ) => 0 === strpos( $p, 'DELETE' ) ) );
		$this->assertNull( VmRecords::for_resource( $r->id() ) );
		$this->assertNull( IpPool::for_resource( $r->id() ) );
	}

	public function test_delete_of_a_half_built_resource_deletes_no_vm(): void {
		// A foreign VM appears on the id the allocation chose: the clone step refuses it.
		$resource = $this->resource();
		$this->fake->fail_next( 'POST', '#/clone$#', array( 'status' => 500, 'message' => 'storage is busy' ) );
		$this->assertTrue( $this->run_provision( $resource )->is_retry() );
		$this->fake->add_vm( array( 'vmid' => 5000, 'node' => 'node-a', 'status' => 'running', 'config' => array( 'description' => 'not ours' ) ) );
		$this->assertTrue( $this->run_provision( $resource )->is_failed() );
		$this->assertNotNull( VmRecords::for_resource( $resource->id() ) );
		$owner = (int) $resource->license()['user_id'];
		$this->assertTrue( PrivateNets::add( 1000, '10.64.0.0/28' ) );
		$this->assertSame( '10.64.0.2', PrivateNets::reserve( $owner, $resource->id() )['address'] );
		$seen = count( $this->fake->calls() );

		$this->assert_done( $this->op( 'delete', $resource ) );

		$this->assert_only_reads( $seen );
		$this->assertSame( 'running', $this->fake->vm( 5000 )['status'] );
		$this->assertNull( VmRecords::for_resource( $resource->id() ) );
		$this->assertNull( IpPool::for_resource( $resource->id() ) );
		$this->assertNull( PrivateNets::address_for_resource( $resource->id() ), 'The absent original VM no longer keeps its private address.' );
		$this->assertSame( 'quarantine', PrivateNets::for_user( $owner )['state'] );

		// A clone that never started: nothing at the id, the id is confirmed free.
		$other = $this->resource();
		$this->fake->fail_next( 'POST', '#/clone$#', array( 'status' => 500, 'message' => 'storage is busy' ) );
		$this->assertTrue( $this->run_provision( $other )->is_retry() );
		$seen = count( $this->fake->calls() );
		$this->assert_done( $this->op( 'delete', $other ) );
		$this->assert_only_reads( $seen );
		$this->assertNull( IpPool::for_resource( $other->id() ) );
	}

	public function test_delete_of_a_pending_clone_waits(): void {
		$resource = $this->resource();
		$this->fake->next_task( array( 'polls' => 400 ) );
		$this->assertTrue( $this->run_provision( $resource )->is_retry() );
		$seen = count( $this->fake->calls() );

		$this->assertTrue( $this->op( 'delete', $resource )->is_retry() );
		$this->assert_only_reads( $seen );
		$this->assertNotNull( IpPool::for_resource( $resource->id() ) );
	}

	public function test_mark_deleted_keeps_the_ip_held(): void {
		$r    = $this->built();
		$user = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user );
		get_user_by( 'id', $user )->add_cap( 'manage_woocommerce' );
		$seen = count( $this->fake->calls() );

		$result = \BeltoftServices\Admin\Panel::act( Resource::find( $r->id() ), 'mark_deleted', 'MARK DELETED' );

		$this->assertNotWPError( $result );
		$this->assertFalse( Resource::find( $r->id() )->is_live() );
		$this->assertCount( $seen, $this->fake->calls(), 'No driver call.' );
		$this->assertSame( 'assigned', IpPool::for_resource( $r->id() )['state'], 'The VM may still run on it.' );
		$this->assertNotNull( VmRecords::for_resource( $r->id() ) );
		$this->assertEmpty( Resource::find( $r->id() )->get( Access::META_REVEAL ), 'The unread password is wiped.' );
	}

	public function test_medusa_upgrade_is_applied_not_just_charged(): void {
		$resource = $this->resource();
		Jobs::run( $resource->id() );
		$this->assertSame( 'ready', Resource::find( $resource->id() )->get( 'state' ) );
		$seen = count( $this->fake->calls() );

		$plan = array_merge( (array) Resource::find( $resource->id() )->get( 'plan' ), array( 'cores' => 4, 'memory_mb' => 8192, 'disk_gb' => 50 ) );
		$this->assertTrue( Resource::find( $resource->id() )->change_plan( $plan, 'Plan changed' ) );
		Jobs::run( $resource->id() );

		$fresh = Resource::find( $resource->id() );
		$this->assertSame( 'ready', $fresh->get( 'state' ) );
		$this->assertEmpty( $fresh->get( 'plan_dirty' ) );

		$vm = $this->fake->vm( 5000 );
		$this->assertSame( 4, (int) $vm['config']['cores'] );
		$this->assertSame( 8192, (int) $vm['config']['memory'] );
		$this->assertSame( 'disk-store:vm-9000-disk-0,size=50G', $vm['config']['scsi0'] );
		$this->assertSame( 'running', $vm['status'], 'It ran before, it runs after.' );
		$this->assertSame( 50, $fresh->get( 'disk_gb' ) );
		$this->assertEmpty( $fresh->get( 'was_running' ) );

		$paths = $this->since( $seen );
		$this->assertLessThan( array_search( 'PUT /nodes/node-a/qemu/5000/config', $paths, true ), array_search( 'POST /nodes/node-a/qemu/5000/status/shutdown', $paths, true ) );
	}

	public function test_update_plan_noop_when_already_matching_makes_no_shutdown(): void {
		$r    = $this->built();
		$seen = count( $this->fake->calls() );

		$result = $this->op( 'update_plan', $r );

		$this->assert_done( $result );
		$this->assert_only_reads( $seen );
		$this->assertSame( 'running', $this->fake->vm( 5000 )['status'] );
		$this->assertSame( 30, $result->meta()['disk_gb'] );
	}

	public function test_vm_not_restarted_after_update_when_it_was_stopped(): void {
		$r = $this->built();
		$this->power_off( 5000 );
		$r->set( array( 'plan' => array_merge( (array) $r->get( 'plan' ), array( 'cores' => 4, 'memory_mb' => 8192, 'disk_gb' => 40 ) ) ) );

		$this->assert_done( $this->op( 'update_plan', $r ) );

		$vm = $this->fake->vm( 5000 );
		$this->assertSame( 'stopped', $vm['status'] );
		$this->assertSame( 4, (int) $vm['config']['cores'] );
		$this->assertSame( 'disk-store:vm-9000-disk-0,size=40G', $vm['config']['scsi0'] );
		$this->assertCount( 1, $this->calls( 'POST', '/status/start' ), 'Only the provision start: none after the plan change.' );
	}

	public function test_update_refuses_an_untagged_vm(): void {
		$r = $this->built();
		$this->fake->request( 'PUT', '/nodes/node-a/qemu/5000/config', array( 'description' => 'someone else' ) );
		$r->set( array( 'plan' => array_merge( (array) $r->get( 'plan' ), array( 'cores' => 8 ) ) ) );
		$seen = count( $this->fake->calls() );

		$this->assertTrue( $this->op( 'update_plan', $r )->is_failed() );
		$this->assert_only_reads( $seen );
	}

	private const NO_DOWNGRADE = "Servers can't be downgraded. Choose a plan at least as large as your current one.";

	public function test_a_switch_to_a_smaller_plan_in_any_dimension_is_refused(): void {
		Switches::register();
		$plan = static fn( int $cores, int $memory, int $disk ): array => array( 'cluster' => 'lab', 'template' => 9000, 'cores' => $cores, 'memory_mb' => $memory, 'disk_gb' => $disk );
		$now  = $this->service_product( 'proxmox', $plan( 2, 4096, 40 ), array( 'max' => 1, 'slug' => 'vm-now' ) );
		$cases = array(
			'less disk'    => array( $plan( 2, 4096, 20 ), true ),
			'fewer cores'  => array( $plan( 1, 4096, 40 ), true ),
			'less memory'  => array( $plan( 2, 2048, 40 ), true ),
			'equal'        => array( $plan( 2, 4096, 40 ), false ),
			'bigger'       => array( $plan( 4, 8192, 80 ), false ),
			'bigger disk only' => array( $plan( 2, 4096, 50 ), false ),
		);

		$license = $this->service_license( $now, array( 'fire' => false, 'subscription_id' => 6161 ) );
		$this->assertInstanceOf( Resource::class, Resource::create( $license, Registry::get( 'proxmox' ), Product::plan( $now->get_id() ) ) );
		$subscription = new class() {
			public function get_id() {
				return 6161;
			}
		};

		$n = 0;
		foreach ( $cases as $name => $case ) {
			$target = $this->service_product( 'proxmox', $case[0], array( 'max' => 1, 'slug' => 'vm-target-' . ( ++$n ) ) );
			$result = apply_filters( 'woocommerce_subscriptions_switch_error_message', '', $target->get_id(), 1, 0, $subscription, null );
			$this->assertSame( $case[1] ? self::NO_DOWNGRADE : '', $result, $name );
		}
	}

	public function test_medusa_disk_downgrade_is_refused_before_payment(): void {
		Switches::register();
		$big     = $this->service_product( 'proxmox', array( 'cluster' => 'lab', 'template' => 9000, 'cores' => 2, 'memory_mb' => 4096, 'disk_gb' => 60 ), array( 'max' => 1, 'slug' => 'vm-big' ) );
		$small   = $this->service_product( 'proxmox', array( 'cluster' => 'lab', 'template' => 9000, 'cores' => 1, 'memory_mb' => 2048, 'disk_gb' => 20 ), array( 'max' => 1, 'slug' => 'vm-small' ) );
		$larger  = $this->service_product( 'proxmox', array( 'cluster' => 'lab', 'template' => 9000, 'cores' => 4, 'memory_mb' => 8192, 'disk_gb' => 80 ), array( 'max' => 1, 'slug' => 'vm-larger' ) );
		$license = $this->service_license( $big, array( 'fire' => false, 'subscription_id' => 4242 ) );
		$this->assertInstanceOf( Resource::class, Resource::create( $license, Registry::get( 'proxmox' ), Product::plan( $big->get_id() ) ) );

		$subscription = new class() {
			public function get_id() {
				return 4242;
			}
		};

		$refused = apply_filters( 'woocommerce_subscriptions_switch_error_message', '', $small->get_id(), 1, 0, $subscription, null );
		$this->assertSame( self::NO_DOWNGRADE, $refused );

		$this->assertSame( '', apply_filters( 'woocommerce_subscriptions_switch_error_message', '', $larger->get_id(), 1, 0, $subscription, null ) );
		$this->assertSame( 'Another error.', apply_filters( 'woocommerce_subscriptions_switch_error_message', 'Another error.', $small->get_id(), 1, 0, $subscription, null ), 'WCS\'s own message is kept.' );

		$other = new class() {
			public function get_id() {
				return 9999;
			}
		};
		$this->assertSame( '', apply_filters( 'woocommerce_subscriptions_switch_error_message', '', $small->get_id(), 1, 0, $other, null ), 'A subscription with no VM.' );
	}

	public function test_cpu_ram_downgrade_applies_and_disk_is_kept_with_one_alert(): void {
		$r = $this->built();
		$r->set( array( 'plan' => array_merge( (array) $r->get( 'plan' ), array( 'cores' => 1, 'memory_mb' => 2048, 'disk_gb' => 20 ) ) ) );

		$seen  = count( $this->fake->calls() );
		$first = $this->op( 'update_plan', $r );
		$this->assert_done( $first );

		$vm = $this->fake->vm( 5000 );
		$this->assertSame( 1, (int) $vm['config']['cores'] );
		$this->assertSame( 2048, (int) $vm['config']['memory'] );
		$this->assertSame( 'disk-store:vm-9000-disk-0,size=30G', $vm['config']['scsi0'], 'The disk is never shrunk.' );
		$this->assertSame( 'running', $vm['status'] );
		$this->assertSame( 30, $first->meta()['disk_gb'] );
		$this->assertSame( array(), array_filter( $this->since( $seen ), static fn( $p ) => false !== strpos( $p, '/resize' ) ) );

		$this->assertCount( 1, $this->mails );
		$this->assertStringContainsString( 'Plan disk (20 GB) is smaller than the VM disk (30 GB); the disk was not shrunk', $this->mails[0]['message'] . $this->mails[0]['subject'] );

		// Asked again (the same dirty plan): still one alert.
		$this->assert_done( $this->op( 'update_plan', $r ) );
		$this->assertCount( 1, $this->mails );
	}

	public function test_a_failed_config_write_after_shutdown_starts_the_vm_again(): void {
		$r = $this->built();
		$r->set( array( 'plan' => array_merge( (array) $r->get( 'plan' ), array( 'cores' => 4 ) ) ) );
		$this->fake->fail_next( 'PUT', '#/config$#', array( 'status' => 400, 'message' => 'invalid' ) );

		$this->assertTrue( $this->op( 'update_plan', $r )->is_failed() );
		$this->assertSame( 'running', $this->fake->vm( 5000 )['status'], 'A customer VM is not left switched off.' );
	}

	public function test_status_is_cached_in_a_transient_not_meta(): void {
		$r      = $this->built();
		$before = (array) $r->get( 'steps' );

		$status = $this->driver->status( Resource::find( $r->id() ) );

		$this->assertSame( 'running', $status['power'] );
		$this->assertArrayHasKey( 'cpu', $status );
		$this->assertArrayHasKey( 'mem', $status );
		$this->assertSame( $status, get_transient( Operations::STATUS_PREFIX . $r->id() ) );

		$fresh = Resource::find( $r->id() );
		$this->assertNull( $fresh->get( 'power' ) );
		$this->assertNull( $fresh->get( 'status' ) );
		$this->assertSame( $before, $fresh->get( 'steps' ) );

		// Not ours: nothing.
		$this->fake->request( 'PUT', '/nodes/node-a/qemu/5000/config', array( 'description' => 'someone else' ) );
		$this->assertNull( $this->driver->status( Resource::find( $r->id() ) ) );
		$this->assertNull( $this->driver->status( Resource::find( $this->resource()->id() ) ) );
	}

	public function test_medusa_customer_shutdown_vm_still_follows_retention(): void {
		$resource = $this->resource();
		$id       = $resource->id();
		Jobs::run( $id );
		$this->assertSame( 'ready', Resource::find( $id )->get( 'state' ) );

		// The customer powers it off from inside the guest, then the license lapses.
		$this->power_off( 5000 );
		\BeltoftLicenses\Licenses::update( (int) $resource->license()['id'], array( 'status' => \BeltoftLicenses\Licenses::STATUS_EXPIRED ) );
		$this->run_jobs();

		$paused = Resource::find( $id );
		$this->assertSame( 'paused', $paused->get( 'state' ) );
		$this->assertEmpty( $this->calls( 'POST', '/status/stop' ) );

		// Retention off (the default): never deleted.
		$paused->set( array( 'paused_at' => time() - 10 * DAY_IN_SECONDS, 'warned_at' => time() - 2 * DAY_IN_SECONDS ) );
		Retention::run();
		$this->assertSame( 'paused', Resource::find( $id )->get( 'desired' ) );
		$this->assertNotNull( $this->fake->vm( 5000 ) );

		$this->settings( array( 'deletion_mode' => 'on' ) );
		Retention::run();
		$this->run_jobs();

		$this->assertSame( 'deleted', Resource::find( $id )->get( 'state' ) );
		$this->assertNull( $this->fake->vm( 5000 ) );
		$this->assertNull( IpPool::for_resource( $id ) );
	}

	public function test_a_missing_vm_record_after_the_clone_fails_for_an_admin_and_releases_nothing(): void {
		IpPool::add( '192.0.2.12' );
		IpPool::add( '192.0.2.13' );

		foreach ( array( 'delete', 'pause' ) as $operation ) {
			$r = $this->built();
			VmRecords::remove( $r->id() );
			$seen = count( $this->fake->calls() );

			$result = $this->op( $operation, $r );

			$this->assertTrue( $result->is_failed(), $operation );
			$this->assertStringContainsString( 'VM record missing; check Proxmox manually', $result->error() );
			$this->assertCount( $seen, $this->fake->calls() );
			$this->assertNotNull( IpPool::for_resource( $r->id() ), 'The address is not released.' );
			$this->assertNotNull( $this->fake->vm( 5000 ) );
		}

		// Through the reconciler the admin is alerted.
		$r = $this->built();
		VmRecords::remove( $r->id() );
		$this->mails = array();
		$r->set( array( 'state' => 'ready' ) );
		$this->assertTrue( $r->desire( 'deleted', 'test' ) );
		Jobs::run( $r->id() );
		$this->assertSame( 'failed', Resource::find( $r->id() )->get( 'state' ) );
		$this->assertNotEmpty( $this->mails );
	}

	public function test_the_vm_changing_hands_between_shutdown_and_delete_stops_the_delete(): void {
		$r = $this->built();
		$this->fake->next_task( array( 'polls' => 3 ) );

		$first = $this->op( 'delete', $r );
		$this->assertTrue( $first->is_retry(), 'The shutdown task is still running.' );

		// Somebody takes the VM over while the task runs.
		$this->fake->request( 'PUT', '/nodes/node-a/qemu/5000/config', array( 'description' => 'someone else' ) );
		$seen = count( $this->fake->calls() );

		$result = $this->op( 'delete', $r );

		$this->assertTrue( $result->is_failed() );
		$this->assertStringContainsString( 'Ownership check failed', $result->error() );
		$this->assertSame( array(), array_filter( $this->since( $seen ), static fn( $p ) => 0 === strpos( $p, 'DELETE' ) ) );
		$this->assertNotNull( $this->fake->vm( 5000 ) );
		$this->assertNotNull( VmRecords::for_resource( $r->id() ) );
	}

	public function test_each_finished_step_of_a_plan_change_counts_as_progress(): void {
		$r = $this->built();
		$r->set( array( 'plan' => array_merge( (array) $r->get( 'plan' ), array( 'cores' => 4 ) ) ) );
		$this->fake->fail_next( 'PUT', '#/config$#', array( 'status' => 500, 'message' => 'busy' ) );

		$first = $this->driver->update_plan( Resource::find( $r->id() ) );
		$this->assertTrue( $first->is_retry() );
		$this->assertTrue( $first->has_progress(), 'The shutdown finished.' );

		// Only waiting for a task that still runs is not progress.
		$r2 = $this->built();
		$this->fake->next_task( array( 'polls' => 400 ) );
		$r2->set( array( 'plan' => array_merge( (array) $r2->get( 'plan' ), array( 'cores' => 8 ) ) ) );
		$waiting = $this->driver->update_plan( Resource::find( $r2->id() ) );
		$this->assertTrue( $waiting->is_retry() );
		$this->assertFalse( $waiting->has_progress() );
	}

	public function test_a_plan_change_that_runs_out_of_attempts_leaves_a_running_vm_running(): void {
		$resource = $this->resource();
		$id       = $resource->id();
		Jobs::run( $id );
		$this->assertSame( 'running', $this->fake->vm( 5000 )['status'] );

		$plan = array_merge( (array) Resource::find( $id )->get( 'plan' ), array( 'cores' => 4 ) );
		$this->assertTrue( Resource::find( $id )->change_plan( $plan, 'Plan changed' ) );

		for ( $i = 0; $i < 12; $i++ ) {
			$this->fake->fail_next( 'PUT', '#/config$#', array( 'status' => 500, 'message' => 'busy' ) );
		}

		for ( $run = 0; $run < 12 && 'failed' !== Resource::find( $id )->get( 'state' ); $run++ ) {
			Resource::find( $id )->set( array( 'next_attempt_at' => time() - 1 ) );
			Jobs::run( $id );
		}

		$this->assertSame( 'failed', Resource::find( $id )->get( 'state' ) );
		$this->assertSame( 'running', $this->fake->vm( 5000 )['status'], 'The customer VM is switched on again.' );
		$this->assertEmpty( Resource::find( $id )->get( 'was_running' ) );
	}

	public function test_a_failed_update_does_not_restart_a_vm_when_a_pause_or_delete_is_wanted(): void {
		IpPool::add( '192.0.2.12' );

		foreach ( array( 'paused', 'deleted' ) as $desired ) {
			$r = $this->built();
			$this->fake->request( 'POST', '/nodes/node-a/qemu/' . $r->get( 'vmid' ) . '/status/stop' );
			$this->assertSame( 'stopped', $this->fake->vm( (int) $r->get( 'vmid' ) )['status'] );
			$r->set( array( 'was_running' => true, 'failed_op' => 'update_plan', 'desired' => $desired ) );
			$starts = count( $this->calls( 'POST', '/status/start' ) );

			Operations::on_state_changed( Resource::find( $r->id() ), 'ready', 'failed' );

			$this->assertSame( $starts, count( $this->calls( 'POST', '/status/start' ) ), 'No start while ' . $desired . ' is wanted.' );
			$this->assertSame( 'stopped', $this->fake->vm( (int) $r->get( 'vmid' ) )['status'] );
		}

		// Control: with nothing wanted, it is started again.
		$r = $this->built();
		$this->fake->request( 'POST', '/nodes/node-a/qemu/' . $r->get( 'vmid' ) . '/status/stop' );
		$r->set( array( 'was_running' => true, 'failed_op' => 'update_plan' ) );

		Operations::on_state_changed( Resource::find( $r->id() ), 'ready', 'failed' );

		$this->assertSame( 'running', $this->fake->vm( (int) $r->get( 'vmid' ) )['status'] );
	}

	public function test_a_pause_that_supersedes_an_interrupted_update_clears_was_running(): void {
		$r = $this->built();
		$this->fake->request( 'POST', '/nodes/node-a/qemu/' . $r->get( 'vmid' ) . '/status/stop' );
		$r->set( array( 'was_running' => true ) );

		$result = $this->op( 'pause', $r );

		$this->assertTrue( $result->is_done() );
		$this->assertArrayHasKey( 'was_running', $result->meta() );
		$this->assertNull( $result->meta()['was_running'], 'The result clears the marker.' );
	}

	public function test_a_config_write_that_changed_nothing_is_not_progress(): void {
		$r = $this->built();
		$this->fake->request( 'POST', '/nodes/node-a/qemu/' . $r->get( 'vmid' ) . '/status/stop' );
		$r->set( array( 'plan' => array_merge( (array) $r->get( 'plan' ), array( 'cores' => 4 ) ) ) );

		// A cluster that accepts the write but keeps its values: the re-read shows no change.
		$this->fake->deaf_config = true;
		$first                   = $this->driver->update_plan( Resource::find( $r->id() ) );

		$this->assertTrue( $first->is_retry() );
		$this->assertFalse( $first->has_progress(), 'A write that changed nothing is no progress.' );

		// The same write that does take effect counts.
		$this->fake->deaf_config = false;
		$second                  = $this->driver->update_plan( Resource::find( $r->id() ) );

		$this->assertTrue( $second->is_done() || $second->has_progress() );
		$this->assertSame( 4, (int) $this->fake->vm( (int) $r->get( 'vmid' ) )['config']['cores'] );
	}

	public function test_a_switch_between_variations_is_checked_by_the_variation_disk(): void {
		Switches::register();
		$product = $this->service_product(
			'proxmox',
			array( 'cluster' => 'lab', 'template' => 9000, 'cores' => 1, 'memory_mb' => 1024, 'disk_gb' => 10 ),
			array(
				'slug'       => 'vm-tiers',
				'variations' => array(
					array( 'disk_gb' => 20 ),
					array( 'disk_gb' => 40 ),
					array( 'disk_gb' => 80 ),
				),
			)
		);

		$variations = $product->get_children();
		$this->assertCount( 3, $variations );
		$this->assertSame( 40, Product::plan( $product->get_id(), (int) $variations[1] )['disk_gb'], 'Variation fields override the product.' );

		$license = $this->service_license( $product, array( 'fire' => false, 'subscription_id' => 5151, 'variation_id' => (int) $variations[1] ) );
		$this->assertInstanceOf( Resource::class, Resource::create( $license, Registry::get( 'proxmox' ), Product::plan( $product->get_id(), (int) $variations[1] ) ) );

		$subscription = new class() {
			public function get_id() {
				return 5151;
			}
		};

		$down = apply_filters( 'woocommerce_subscriptions_switch_error_message', '', $product->get_id(), 1, (int) $variations[0], $subscription, null );
		$this->assertSame( self::NO_DOWNGRADE, $down );
		$this->assertSame( '', apply_filters( 'woocommerce_subscriptions_switch_error_message', '', $product->get_id(), 1, (int) $variations[2], $subscription, null ) );
		$this->assertSame( '', apply_filters( 'woocommerce_subscriptions_switch_error_message', '', $product->get_id(), 1, (int) $variations[1], $subscription, null ) );
	}

	/**
	 * Runs the reconciler until the resource fails or the rounds are used.
	 */
	private function run_to_failure( int $id ): void {
		for ( $run = 0; $run < 40 && 'failed' !== Resource::find( $id )->get( 'state' ); $run++ ) {
			// Time passes: a wait that has lasted its budget counts as an error (nothing sleeps in tests).
			Resource::find( $id )->set( array( 'next_attempt_at' => time() - 1, 'wait_since' => time() - 5000 ) );
			Jobs::run( $id );
		}
	}

	private function fail_tasks( int $count ): void {
		for ( $i = 0; $i < $count; $i++ ) {
			$this->fake->next_task( array( 'exitstatus' => 'storage full', 'effect' => false ) );
		}
	}

	public function test_a_clone_task_that_fails_every_time_ends_in_fail_with_a_capped_number_of_posts(): void {
		$resource = $this->resource();
		$this->fail_tasks( 60 );

		$this->run_to_failure( $resource->id() );

		$this->assertSame( 'failed', Resource::find( $resource->id() )->get( 'state' ) );
		$this->assertSame( 6, count( $this->calls( 'POST', '/clone' ) ), 'A failing task is not progress: the budget ends it.' );
	}

	public function test_a_shutdown_that_fails_every_time_in_pause_and_update_ends_in_fail(): void {
		$resource = $this->resource();
		$other    = $this->resource();
		Jobs::run( $resource->id() );
		Jobs::run( $other->id() );
		$this->assertSame( 'ready', Resource::find( $resource->id() )->get( 'state' ) );
		$this->assertSame( 'ready', Resource::find( $other->id() )->get( 'state' ) );
		$this->fail_tasks( 80 );
		$before = count( $this->calls( 'POST', '/status/shutdown' ) );

		$this->assertTrue( Resource::find( $resource->id() )->desire( 'paused', 'test' ) );
		$this->run_to_failure( $resource->id() );

		$this->assertSame( 'failed', Resource::find( $resource->id() )->get( 'state' ) );
		$this->assertSame( 7, count( $this->calls( 'POST', '/status/shutdown' ) ) - $before, 'One graceful try per run (a stop that leaves the VM running waits instead of looping); the first run is a free wait, then the budget of five ends it.' );
		$this->assertSame( 'running', $this->fake->vm( 5000 )['status'] );

		// The same for a plan change.
		$plan = array_merge( (array) Resource::find( $other->id() )->get( 'plan' ), array( 'cores' => 4 ) );
		$this->assertTrue( Resource::find( $other->id() )->change_plan( $plan, 'Plan changed' ) );
		$before = count( $this->calls( 'POST', '/status/shutdown' ) );

		$this->run_to_failure( $other->id() );

		$this->assertSame( 'failed', Resource::find( $other->id() )->get( 'state' ) );
		$this->assertSame( 7, count( $this->calls( 'POST', '/status/shutdown' ) ) - $before, 'One graceful try per run (a stop that leaves the VM running waits instead of looping); the first run is a free wait, then the budget of five ends it.' );
		$this->assertSame( 'running', $this->fake->vm( 5001 )['status'] );
	}

	public function test_a_vm_that_restarts_after_every_stop_ends_in_fail_with_the_alert_and_bounded_calls(): void {
		$resource = $this->resource();
		Jobs::run( $resource->id() );
		$this->assertSame( 'ready', Resource::find( $resource->id() )->get( 'state' ) );
		$this->fake->bounce = 'stop';
		$this->mails        = array();
		$before             = count( $this->fake->calls() );

		$this->assertTrue( Resource::find( $resource->id() )->desire( 'paused', 'test' ) );
		$this->run_to_failure( $resource->id() );

		$r = Resource::find( $resource->id() );
		$this->assertSame( 'failed', $r->get( 'state' ), 'A power change that never sticks must end in a failure.' );
		$this->assertLessThanOrEqual( 6, $r->get( 'attempts' ) );
		$this->assertNotEmpty( $this->mails, 'The admin is alerted.' );
		$this->assertLessThanOrEqual( 250, count( $this->fake->calls() ) - $before, 'The number of Proxmox calls is bounded (a run settles for up to 20 seconds, one call every 2).' );
	}

	public function test_a_vm_that_stops_after_every_start_ends_in_fail_with_the_alert_and_bounded_calls(): void {
		$resource = $this->resource();
		Jobs::run( $resource->id() );
		$this->assertTrue( Resource::find( $resource->id() )->desire( 'paused', 'test' ) );
		Jobs::run( $resource->id() );
		$this->assertSame( 'paused', Resource::find( $resource->id() )->get( 'state' ) );
		$this->fake->bounce = 'start';
		$this->mails        = array();
		$before             = count( $this->fake->calls() );

		$this->assertTrue( Resource::find( $resource->id() )->desire( 'ready', 'test' ) );
		$this->run_to_failure( $resource->id() );

		$r = Resource::find( $resource->id() );
		$this->assertSame( 'failed', $r->get( 'state' ) );
		$this->assertNotEmpty( $this->mails );
		$this->assertLessThanOrEqual( 150, count( $this->fake->calls() ) - $before );
	}

	/**
	 * Ends the resource's license without any beltoft-licenses action (the clock passing the expiry).
	 *
	 * @param Resource $r Resource.
	 */
	private function end_license_silently( Resource $r ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- test fixture.
		$wpdb->update( \BeltoftLicenses\Install::tables()['licenses'], array( 'expires_at' => gmdate( 'Y-m-d H:i:s', time() - 1 ) ), array( 'id' => (int) $r->license()['id'] ) );
	}

	public function test_a_license_ended_before_the_job_runs_makes_no_clone_call(): void {
		$resource = $this->resource();
		$this->end_license_silently( $resource );

		\BeltoftServices\Reconciler::step( Resource::find( $resource->id() ) );

		\BeltoftServices\Reconciler::step( Resource::find( $resource->id() ) ); // The queued follow-up: the pause.

		$this->assertSame( array(), $this->fake->calls(), 'Proxmox was not called at all.' );
		$fresh = Resource::find( $resource->id() );
		$this->assertSame( 'paused', $fresh->get( 'desired' ) );
		$this->assertSame( 'paused', $fresh->get( 'state' ) );
		$this->assertNull( VmRecords::for_resource( $resource->id() ) );
	}

	public function test_a_license_with_a_future_expiry_still_provisions(): void {
		$resource = $this->resource();
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- test fixture.
		$wpdb->update( \BeltoftLicenses\Install::tables()['licenses'], array( 'expires_at' => gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS ) ), array( 'id' => (int) $resource->license()['id'] ) );

		$this->assert_done( $this->run_provision( $resource ) );
		$this->assertSame( 'running', $this->fake->vm( 5000 )['status'] );
	}

	public function test_a_license_revoked_after_the_clone_leaves_a_stopped_vm_and_a_paused_service(): void {
		$resource = $this->resource();

		$this->fake->after_task = function ( string $method, string $path ) use ( $resource ) {
			if ( str_ends_with( $path, '/clone' ) ) {
				$this->end_license_silently( $resource );
			}
		};

		$result = $this->run_provision( $resource );
		$this->assertTrue( $result->is_retry() );
		$this->assertSame( array(), $this->calls_matching( 'POST', '/status/start' ), 'The VM was never started.' );

		$this->fake->after_task = null;
		$id                     = $resource->id();

		// The reconciler's next steps: the pause that the guard asked for, with the VM off.
		\BeltoftServices\Reconciler::step( Resource::find( $id ) );

		$fresh = Resource::find( $id );
		$this->assertSame( 'paused', $fresh->get( 'desired' ) );
		$this->assertSame( 'paused', $fresh->get( 'state' ) );
		$this->assertSame( 'stopped', $this->fake->vm( 5000 )['status'] );
		$this->assertSame( array(), $this->calls_matching( 'POST', '/status/start' ) );
	}

	public function test_resume_of_a_paused_service_with_an_ended_license_stays_paused(): void {
		$r = $this->built();
		$this->assert_done( $this->op( 'pause', $r ) );
		$this->assertTrue( Resource::find( $r->id() )->desire( 'paused', 'license expired' ) );
		\BeltoftServices\Reconciler::step( Resource::find( $r->id() ) );

		$this->end_license_silently( $r );
		$this->assertTrue( Resource::find( $r->id() )->desire( 'ready', 'stale' ) );
		$seen = count( $this->fake->calls() );

		\BeltoftServices\Reconciler::step( Resource::find( $r->id() ) );

		$this->assertSame( array(), array_filter( $this->since( $seen ), static fn( $p ) => 0 === strpos( $p, 'POST' ) ), 'Nothing was started.' );
		$this->assertSame( 'stopped', $this->fake->vm( 5000 )['status'] );
		$this->assertSame( 'paused', Resource::find( $r->id() )->get( 'desired' ) );
		$this->assertSame( 'paused', Resource::find( $r->id() )->get( 'state' ) );
	}

	/**
	 * Recorded fake calls by method and path ending.
	 *
	 * @param string $method Method.
	 * @param string $suffix Path ending.
	 * @return array<int, array<string, mixed>>
	 */
	private function calls_matching( string $method, string $suffix ): array {
		return array_values( array_filter( $this->fake->calls(), static fn( $c ) => $c['method'] === $method && str_ends_with( $c['path'], $suffix ) ) );
	}
}

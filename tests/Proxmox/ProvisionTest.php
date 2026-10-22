<?php
/**
 * Provisioning a Proxmox VM. Only the fake client is used: nothing here reaches a Proxmox host.
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
use BeltoftServices\Proxmox\Ownership;
use BeltoftServices\Proxmox\ProxmoxDriver;
use BeltoftServices\Proxmox\Provision;
use BeltoftServices\Proxmox\Schema;
use BeltoftServices\Proxmox\VmRecords;
use BeltoftServices\Resource;
use BeltoftServices\Tests\TestCase;

/**
 * A ProxmoxDriver that records its sleeps instead of sleeping.
 */
class QuickProxmoxDriver extends ProxmoxDriver {

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
 * A fake cluster whose config writes go through a LiveClient on the recorded HTTP layer.
 */
class WireClient extends FakeClient {

	public function request( string $method, string $path, array $params = array() ) {
		if ( 'PUT' === $method && preg_match( '#/qemu/\d+/config$#', $path ) ) {
			$live = new \BeltoftServices\Proxmox\LiveClient(
				array(
					'api_url'      => 'https://pve.example.test:8006',
					'token_id'     => 'svc@pve!beltoft',
					'token_secret' => 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee',
				),
				true
			);

			return $live->request( $method, $path, $params );
		}

		return parent::request( $method, $path, $params );
	}
}

/**
 * Every provision step against the fake cluster.
 */
class ProvisionTest extends TestCase {

	private const SECRET = 'secret-secret-secret-1';

	/**
	 * The fake cluster.
	 *
	 * @var FakeClient
	 */
	private $fake;

	/**
	 * The driver under test.
	 *
	 * @var QuickProxmoxDriver
	 */
	private $driver;

	public function set_up(): void {
		parent::set_up();

		$key = base64_encode( random_bytes( 32 ) );
		add_filter( 'beltoft_services_key', fn() => $key );

		$this->wipe_tables();
		delete_option( Clusters::OPTION );
		$this->settings( array( 'task_wait' => 5 ) );

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
		$this->template();
		$this->cluster();
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

	public function test_provision_runs_all_steps_and_reveals_password(): void {
		$resource = $this->resource();

		$result = $this->run_provision( $resource );
		$this->assert_done( $result );

		$fresh = Resource::find( $resource->id() );
		$this->assertSame( array( 'ip', 'vmid', 'clone', 'configure', 'start', 'ready' ), $fresh->get( 'steps' ) );

		$vm = $this->fake->vm( 5000 );
		$this->assertSame( 'running', $vm['status'] );
		$this->assertSame( 2, (int) $vm['config']['cores'] );
		$this->assertSame( 4096, (int) $vm['config']['memory'] );
		$this->assertSame( 'ip=192.0.2.10/24,gw=192.0.2.1', $vm['config']['ipconfig0'] );
		$this->assertSame( '192.0.2.53 192.0.2.54', $vm['config']['nameserver'] );
		$this->assertSame( 'root', $vm['config']['ciuser'] );
		$this->assertSame( 'ci-store:cloudinit', $vm['config']['ide2'] );
		$this->assertSame( 'disk-store:vm-9000-disk-0,size=30G', $vm['config']['scsi0'] );
		$this->assertSame( 1, (int) $vm['firewall_options']['enable'] );
		$this->assertSame( Ownership::tag( $fresh ), $vm['config']['description'] );

		$this->assertSame( '192.0.2.10', $fresh->get( 'ip' ) );
		$this->assertSame( 5000, $fresh->get( 'vmid' ) );
		$this->assertSame( 30, $fresh->get( 'disk_gb' ) );
		$this->assertSame( 'ok', $fresh->get( 'agent' ) );
		$this->assertSame( 'assigned', IpPool::for_resource( $resource->id() )['state'] );

		// The password went to Proxmox, was stored encrypted first, and is shown once.
		$put = $this->calls( 'PUT', '/qemu/5000/config' );
		$this->assertCount( 1, $put );
		$this->assertSame( '***', $put[0]['params']['cipassword'] );
		$this->assertArrayNotHasKey( 'sshkeys', $put[0]['params'] );

		$owner    = (int) $fresh->license()['user_id'];
		$password = Access::take_reveal( $fresh, $owner );
		$this->assertSame( 24, strlen( (string) $password ) );
		$this->assertStringNotContainsString( (string) $password, (string) wp_json_encode( array( $this->fake->calls(), $this->fake->state(), $result->meta(), $result->error() ) ) );
		$this->assertNull( Access::take_reveal( Resource::find( $resource->id() ), $owner ), 'Shown once.' );
	}

	public function test_medusa_clone_failure_resumes_at_clone(): void {
		$resource = $this->resource();

		// Rejected before any VM exists.
		$this->fake->fail_next( 'POST', '#/clone$#', array( 'status' => 500, 'message' => 'storage is busy' ) );
		$first = $this->run_provision( $resource );

		$this->assertTrue( $first->is_retry() );
		$this->assertStringContainsString( 'clone', $first->error() );
		$this->assertSame( array( 'ip', 'vmid' ), Resource::find( $resource->id() )->get( 'steps' ) );
		$this->assertNull( $this->fake->vm( 5000 ) );

		$this->assert_done( $this->run_provision( $resource ) );
		$this->assertSame( 5000, VmRecords::for_resource( $resource->id() )['vmid'], 'The same VMID.' );
		$this->assertSame( '192.0.2.10', Resource::find( $resource->id() )->get( 'ip' ), 'The same address.' );

		// A task that ends in error before a VM exists is retried the same way.
		$other = $this->resource();
		$this->fake->next_task( array( 'exitstatus' => 'ERROR: clone failed', 'effect' => false ) );
		$this->assertTrue( $this->run_provision( $other )->is_retry() );
		$this->assert_done( $this->run_provision( $other ) );
		$this->assertSame( 5001, VmRecords::for_resource( $other->id() )['vmid'] );
	}

	public function test_clone_task_that_left_a_vm_fails_without_delete(): void {
		$resource = $this->resource();

		$this->fake->next_task( array( 'exitstatus' => 'ERROR: clone failed', 'effect' => true ) );
		$result = $this->run_provision( $resource );

		$this->assertTrue( $result->is_failed() );
		$this->assertStringContainsString( 'VMID 5000 still exists', $result->error() );
		$this->assertNotNull( $this->fake->vm( 5000 ), 'Nothing was deleted.' );
		$this->assertSame( array(), $this->calls( 'DELETE', '/qemu/5000' ) );
	}

	public function test_medusa_failure_never_deletes_untagged_vm(): void {
		$resource = $this->resource();

		$this->fake->fail_next( 'POST', '#/clone$#', array( 'status' => 500, 'message' => 'busy' ) );
		$this->assertTrue( $this->run_provision( $resource )->is_retry() );

		// Meanwhile somebody else's VM took the recorded id.
		$this->fake->add_vm(
			array(
				'vmid'   => 5000,
				'node'   => 'node-a',
				'name'   => 'not-ours',
				'config' => array( 'description' => 'beltoft-services:' . \BeltoftServices\Proxmox\ProxmoxSettings::site_tag() . ':another-uuid' ),
			)
		);

		$result = $this->run_provision( $resource );

		$this->assertTrue( $result->is_failed() );
		$this->assertStringContainsString( 'is not ours', $result->error() );
		$this->assertSame( array(), $this->calls( 'DELETE', '/qemu/5000' ) );
		$this->assertSame( array(), $this->calls( 'POST', '/status/stop' ) );
		$this->assertSame( array(), $this->calls( 'POST', '/status/shutdown' ) );
		$this->assertSame( 'not-ours', $this->fake->vm( 5000 )['name'] );
		$this->assertCount( 1, $this->calls( 'POST', '/clone' ), 'No second clone onto the id.' );
	}

	public function test_timed_out_clone_that_finished_is_adopted_not_recloned(): void {
		$resource = $this->resource();

		// The task takes longer than we wait, though the clone itself is done in Proxmox.
		$this->fake->next_task( array( 'polls' => 99 ) );
		$first = $this->run_provision( $resource );
		$this->assertTrue( $first->is_retry() );

		// The task id was lost (a crash between the call and the store).
		$resource->set( array( 'task' => null ) );

		$this->assert_done( $this->run_provision( $resource ) );
		$this->assertCount( 1, $this->calls( 'POST', '/clone' ), 'It did not clone a second time.' );
		$this->assertSame( 'running', $this->fake->vm( 5000 )['status'] );
	}

	public function test_long_task_stores_upid_and_resumes_by_polling(): void {
		$resource = $this->resource();

		$this->fake->next_task( array( 'polls' => 4 ) );
		$first = $this->run_provision( $resource );

		$this->assertTrue( $first->is_retry() );
		$task = Resource::find( $resource->id() )->get( 'task' );
		$this->assertIsArray( $task );
		$this->assertSame( 'clone', $task['step'] );
		$this->assertStringStartsWith( 'UPID:node-a:', $task['upid'] );

		$second = $this->run_provision( $resource );
		$this->assert_done( $second );
		$this->assertCount( 1, $this->calls( 'POST', '/clone' ), 'The second run polled; it did not post again.' );
		$this->assertNull( Resource::find( $resource->id() )->get( 'task' ) );
	}

	public function test_clone_sets_the_tag_in_the_clone_call(): void {
		$this->fake->add_pool( 'customers' );
		$this->cluster( array( 'pool' => 'customers' ) );
		$resource = $this->resource();

		$this->assert_done( $this->run_provision( $resource ) );

		$clone = $this->calls( 'POST', '/clone' );
		$this->assertCount( 1, $clone );
		$this->assertSame( '/nodes/node-a/qemu/9000/clone', $clone[0]['path'] );

		$fresh = Resource::find( $resource->id() );
		$this->assertSame(
			array(
				'newid'       => 5000,
				'name'        => 'vm-' . substr( $fresh->get( 'uuid' ), 0, 8 ),
				'full'        => 1,
				'storage'     => 'disk-store',
				'description' => 'beltoft-services:' . \BeltoftServices\Proxmox\ProxmoxSettings::site_tag() . ':' . $fresh->get( 'uuid' ),
				'pool'        => 'customers',
			),
			$clone[0]['params']
		);
	}

	public function test_clone_asks_for_another_node_only_when_placement_picks_one(): void {
		$this->fake->add_node( 'node-b', array( 'mem' => 0 ) );
		$this->fake->add_node( 'node-a', array( 'mem' => 60000000000 ) );
		$this->cluster( array( 'nodes' => 'node-a, node-b' ) );
		$resource = $this->resource();

		$this->assert_done( $this->run_provision( $resource ) );

		$clone = $this->calls( 'POST', '/clone' );
		$this->assertSame( 'node-b', $clone[0]['params']['target'] );
		$this->assertSame( 'node-b', VmRecords::for_resource( $resource->id() )['node'] );
		$this->assertSame( 'running', $this->fake->vm( 5000 )['status'] );
	}

	public function test_template_must_be_a_template_and_in_the_list(): void {
		// Not in the cluster's list: no call at all.
		$unlisted = $this->resource( array( 'template' => 9999 ) );
		$result   = $this->run_provision( $unlisted );
		$this->assertTrue( $result->is_failed() );
		$this->assertStringContainsString( 'template list', $result->error() );
		$this->assertSame( array(), $this->fake->calls() );

		// In the list but a normal VM on the cluster: refused before the clone call.
		$this->template( array( 'template' => 0 ) );
		$normal = $this->resource();
		$result = $this->run_provision( $normal );
		$this->assertTrue( $result->is_failed() );
		$this->assertStringContainsString( 'not a template', $result->error() );
		$this->assertSame( array(), $this->calls( 'POST', '/clone' ) );

		// Plan values must be positive.
		$bad    = $this->resource( array( 'cores' => 0 ) );
		$result = $this->run_provision( $bad );
		$this->assertTrue( $result->is_failed() );
		$this->assertStringContainsString( 'cores', $result->error() );

		// An unknown cluster.
		$none   = $this->resource( array( 'cluster' => 'nowhere' ) );
		$result = $this->run_provision( $none );
		$this->assertTrue( $result->is_failed() );
		$this->assertStringContainsString( 'Unknown Proxmox cluster', $result->error() );
	}

	public function test_disk_only_grows(): void {
		// The plan asks for less than the template has: no resize call, and the disk stays.
		$small = $this->resource( array( 'disk_gb' => 5 ) );
		$this->assert_done( $this->run_provision( $small ) );
		$this->assertSame( array(), $this->calls( 'PUT', '/resize' ) );
		$this->assertSame( 'disk-store:vm-9000-disk-0,size=10G', $this->fake->vm( 5000 )['config']['scsi0'] );
		$this->assertSame( 10, Resource::find( $small->id() )->get( 'disk_gb' ), 'The actual size is recorded.' );

		// A larger plan grows it, with the disk named and an absolute size.
		$big = $this->resource( array( 'disk_gb' => 40 ) );
		$this->assert_done( $this->run_provision( $big ) );
		$resize = $this->calls( 'PUT', '/qemu/5001/resize' );
		$this->assertCount( 1, $resize );
		$this->assertSame( array( 'disk' => 'scsi0', 'size' => '40G' ), $resize[0]['params'] );
		$this->assertSame( 40, Resource::find( $big->id() )->get( 'disk_gb' ) );
	}

	public function test_net0_keeps_mac_and_sets_firewall_bridge_and_tag(): void {
		$this->cluster( array( 'vlan' => '7' ) );
		$resource = $this->resource();

		$this->assert_done( $this->run_provision( $resource ) );

		$this->assertSame( 'virtio=AA:BB:CC:DD:EE:FF,bridge=vmbr9,tag=7,firewall=1', $this->fake->vm( 5000 )['config']['net0'] );

		// Without a VLAN the tag the template carries is not touched; bridge and firewall are still set.
		$this->cluster( array( 'vlan' => '' ) );
		$second = $this->resource();
		$this->assert_done( $this->run_provision( $second ) );
		$this->assertSame( 'virtio=AA:BB:CC:DD:EE:FF,bridge=vmbr9,tag=5,firewall=1', $this->fake->vm( 5001 )['config']['net0'] );
	}

	public function test_ssh_key_instead_of_password(): void {
		$key      = \BeltoftServices\Proxmox\SshKeyField::normalise( $this->ed25519() );
		$resource = $this->resource( array(), array( 'ssh_key' => $key ) );

		$this->assert_done( $this->run_provision( $resource ) );

		$put = $this->calls( 'PUT', '/qemu/5000/config' );
		$this->assertArrayNotHasKey( 'cipassword', $put[0]['params'] );
		$this->assertSame( '***', $put[0]['params']['sshkeys'] );
		$this->assertSame( $key . "\n", $this->fake->vm( 5000 )['config']['sshkeys'], 'The raw key: the client encodes it once.' );

		$fresh = Resource::find( $resource->id() );
		$this->assertEmpty( $fresh->get( 'reveal' ), 'No password was generated.' );
		$this->assertNull( Access::take_reveal( $fresh, (int) $fresh->license()['user_id'] ) );
	}

	public function test_smtp_block_rule_added_once_on_retry(): void {
		$resource = $this->resource();
		$this->assert_done( $this->run_provision( $resource ) );

		$rules = $this->fake->vm( 5000 )['firewall_rules'];
		$this->assertCount( 1, $rules );
		$this->assertSame( 'DROP', $rules[0]['action'] );
		$this->assertSame( 'out', $rules[0]['type'] );
		$this->assertSame( 'tcp', $rules[0]['proto'] );
		$this->assertSame( '25', (string) $rules[0]['dport'] );
		$this->assertSame( 'net0', $rules[0]['iface'] );
		$this->assertSame( Provision::SMTP_COMMENT, $rules[0]['comment'] );

		// The firewall part runs again (a crash before it was recorded): still one rule.
		$resource->set(
			array(
				'steps'      => array( 'ip', 'vmid', 'clone' ),
				'configured' => array( 'cloudinit', 'config', 'resize' ),
			)
		);
		$this->assert_done( $this->run_provision( $resource ) );
		$this->assertCount( 1, $this->fake->vm( 5000 )['firewall_rules'] );

		// With the option off no rule is made.
		$this->cluster( array( 'block_smtp' => '' ) );
		$second = $this->resource();
		$this->assert_done( $this->run_provision( $second ) );
		$this->assertSame( array(), $this->fake->vm( 5001 )['firewall_rules'] );
	}

	public function test_no_free_ip_fails_and_no_clone_call(): void {
		$this->wipe_tables(); // An empty pool.
		$resource = $this->resource();

		$result = $this->run_provision( $resource );

		$this->assertTrue( $result->is_failed() );
		$this->assertSame( 'No free IP address', $result->error() );
		$this->assertSame( array(), $this->fake->calls() );
		$this->assertNull( VmRecords::for_resource( $resource->id() ) );
	}

	public function test_missing_encryption_key_fails_before_any_call(): void {
		$resource = $this->resource();
		remove_all_filters( 'beltoft_services_key' );

		$result = $this->run_provision( $resource );

		$this->assertTrue( $result->is_failed() );
		$this->assertStringContainsString( 'BELTOFT_SERVICES_KEY', $result->error() );
		$this->assertSame( array(), $this->fake->calls() );
		$this->assertNull( IpPool::for_resource( $resource->id() ) );
	}

	public function test_medusa_vm_found_from_license(): void {
		$resource = $this->resource();
		$this->assert_done( $this->run_provision( $resource ) );

		$found = Resource::for_license( (int) $resource->license()['id'] );
		$this->assertNotNull( $found );
		$this->assertSame( $resource->id(), $found->id() );
		$this->assertSame( 5000, $found->get( 'vm' )['vmid'] );
		$this->assertSame( 'lab', $found->get( 'vm' )['cluster'] );
		$this->assertSame( 'node-a', $found->get( 'vm' )['node'] );

		$record = VmRecords::for_resource( $found->id() );
		$this->assertSame( 5000, $record['vmid'] );
		$this->assertSame( 'lab', $record['cluster'] );
		$this->assertSame( 'node-a', $record['node'] );
		$this->assertSame( IpPool::for_resource( $found->id() )['id'], $record['ip_id'] );
		$this->assertTrue( VmRecords::owned( 'lab', 5000, $found->id() ) );
	}

	public function test_medusa_new_license_never_reuses_another_resources_vm(): void {
		$first = $this->resource();
		$owner = (int) $first->license()['user_id'];
		$this->assert_done( $this->run_provision( $first ) );

		// The customer's first VM is paused (stopped); they buy again.
		$this->fake->request( 'POST', '/nodes/node-a/qemu/5000/status/stop' );
		$before = $this->fake->vm( 5000 );
		$seen   = count( $this->fake->calls() );

		$second = $this->resource( array(), array(), $owner );
		$this->assert_done( $this->run_provision( $second ) );

		$this->assertNotSame( $first->id(), $second->id() );
		$this->assertSame( 5001, VmRecords::for_resource( $second->id() )['vmid'] );
		$this->assertSame( '192.0.2.11', Resource::find( $second->id() )->get( 'ip' ) );
		$this->assertSame( $before, $this->fake->vm( 5000 ), 'The old VM is untouched.' );

		foreach ( array_slice( $this->fake->calls(), $seen ) as $call ) {
			$this->assertStringNotContainsString( '/qemu/5000', $call['path'] );
		}
	}

	public function test_provision_is_driven_through_the_reconciler(): void {
		$resource = $this->resource();
		$id       = $resource->id();

		$this->assertSame( 'requested', $resource->get( 'state' ) );

		$this->fake->fail_next( 'POST', '#/clone$#', array( 'status' => 503, 'message' => 'node is busy' ) );
		Jobs::run( $id );

		$after = Resource::find( $id );
		$this->assertSame( 'provisioning', $after->get( 'state' ) );
		$this->assertSame( 1, $after->get( 'attempts' ), 'The retry cost one attempt.' );
		$this->assertGreaterThanOrEqual( 59, (int) $after->get( 'next_attempt_at' ) - time(), 'At least 60 seconds.' );
		$this->assertStringContainsString( 'clone', $after->get( 'last_error' ) );

		$after->set( array( 'next_attempt_at' => time() - 1 ) );
		Jobs::run( $id );

		$ready = Resource::find( $id );
		$this->assertSame( 'ready', $ready->get( 'state' ) );
		$this->assertSame( 0, $ready->get( 'attempts' ) );
		$this->assertSame( 5000, $ready->get( 'vm' )['vmid'] );
		$this->assertSame( 'running', $this->fake->vm( 5000 )['status'] );
	}

	public function test_a_long_clone_uses_the_five_attempt_budget(): void {
		$resource = $this->resource();
		$id       = $resource->id();

		$this->fake->next_task( array( 'polls' => 400 ) );

		for ( $run = 1; $run <= 5; $run++ ) {
			Jobs::run( $id );
			$state = Resource::find( $id );
			$this->assertSame( 'provisioning', $state->get( 'state' ), 'Run ' . $run );
			$this->assertSame( $run, $state->get( 'attempts' ) );
			$state->set( array( 'next_attempt_at' => time() - 1 ) );
		}

		Jobs::run( $id );
		$this->assertSame( 'failed', Resource::find( $id )->get( 'state' ), 'The sixth waiting run is the end of the budget.' );
		$this->assertCount( 1, $this->calls( 'POST', '/clone' ) );
	}

	public function test_vm_product_defaults_to_three_days_retention(): void {
		$vm = $this->service_product( 'proxmox', array(), array( 'slug' => 'vm-a' ) );
		$this->assertSame( 3, Product::retention_days( $vm->get_id() ) );

		$explicit_never = $this->service_product( 'proxmox', array(), array( 'slug' => 'vm-b', 'retention' => '0' ) );
		$this->assertSame( 0, Product::retention_days( $explicit_never->get_id() ), 'An explicit 0 still means never.' );

		$ten = $this->service_product( 'proxmox', array(), array( 'slug' => 'vm-c', 'retention' => '10' ) );
		$this->assertSame( 10, Product::retention_days( $ten->get_id() ) );

		$this->fake_driver();
		$other = $this->service_product( 'fake', array(), array( 'slug' => 'other' ) );
		$this->assertSame( 0, Product::retention_days( $other->get_id() ), 'Other drivers keep never.' );
	}

	public function test_a_completed_clone_or_resize_with_warnings_is_not_a_failure(): void {
		// Task order: clone, cloud-init drive, resize. The clone and the resize end with warnings but did their work.
		$this->fake->next_task( array( 'exitstatus' => 'WARNINGS: 1', 'effect' => true ) );
		$this->fake->next_task( array() );
		$this->fake->next_task( array( 'exitstatus' => 'WARNINGS: 1', 'effect' => true ) );

		$resource = $this->resource();
		$result   = $this->run_provision( $resource );

		$this->assert_done( $result );
		$this->assertCount( 1, $this->calls( 'POST', '/clone' ) );
		$this->assertSame( 'disk-store:vm-9000-disk-0,size=30G', $this->fake->vm( 5000 )['config']['scsi0'] );
		$this->assertCount( 1, $this->calls( 'PUT', '/resize' ) );
	}

	public function test_a_failed_resize_that_did_not_work_is_retried(): void {
		$this->fake->next_task( array() );
		$this->fake->next_task( array() );
		$this->fake->next_task( array( 'exitstatus' => 'ERROR: resize failed', 'effect' => false ) );

		$resource = $this->resource();
		$first    = $this->run_provision( $resource );

		$this->assertTrue( $first->is_retry() );
		$this->assertStringContainsString( 'resize', $first->error() );
		$this->assertSame( array( 'ip', 'vmid', 'clone' ), Resource::find( $resource->id() )->get( 'steps' ) );

		$this->assert_done( $this->run_provision( $resource ) );
		$this->assertSame( 'disk-store:vm-9000-disk-0,size=30G', $this->fake->vm( 5000 )['config']['scsi0'] );
		$this->assertCount( 1, $this->calls( 'PUT', '/config' ), 'The config was written once: the retry resumed at the disk.' );
	}

	public function test_a_foreign_cloud_init_drive_is_never_overwritten(): void {
		$this->template(
			array(
				'config' => array(
					'scsi0' => 'disk-store:vm-9000-disk-0,size=10G',
					'net0'  => 'virtio=AA:BB:CC:DD:EE:FF,bridge=vmbr0',
					'ide2'  => 'local:iso/debian.iso,media=cdrom',
				),
			)
		);

		$result = $this->run_provision( $this->resource() );

		$this->assertTrue( $result->is_failed() );
		$this->assertStringContainsString( 'ide2', $result->error() );
		$this->assertSame( array(), $this->calls( 'POST', '/qemu/5000/config' ) );
		$this->assertSame( 'local:iso/debian.iso,media=cdrom', $this->fake->vm( 5000 )['config']['ide2'] );
	}

	public function test_errors_are_scrubbed_and_refusals_fail(): void {
		$resource = $this->resource();
		$password = Access::pending_password( $resource );

		$this->fake->fail_next( 'PUT', '#/config$#', array( 'status' => 500, 'message' => 'bad ' . $password . ' with ' . self::SECRET ) );
		$result = $this->run_provision( $resource );

		$this->assertTrue( $result->is_retry() );
		$this->assertStringNotContainsString( $password, $result->error() );
		$this->assertStringNotContainsString( self::SECRET, $result->error() );
		$this->assertStringContainsString( '***', $result->error() );

		// A permission error is a refusal, not worth retrying.
		$other = $this->resource();
		$this->fake->fail_next( 'POST', '#/clone$#', array( 'status' => 403, 'message' => 'Permission check failed (/vms/5001, VM.Allocate)' ) );
		$denied = $this->run_provision( $other );
		$this->assertTrue( $denied->is_failed() );
		$this->assertStringContainsString( 'VM.Allocate', $denied->error() );
	}

	public function test_vmid_range_used_up_fails(): void {
		$this->cluster( array( 'vmid_min' => '5000', 'vmid_max' => '5001' ) );

		foreach ( array( 5000, 5001 ) as $vmid ) {
			$this->fake->add_vm( array( 'vmid' => $vmid, 'node' => 'node-a', 'config' => array( 'description' => 'someone else' ) ) );
		}

		$result = $this->run_provision( $this->resource() );

		$this->assertTrue( $result->is_failed() );
		$this->assertStringContainsString( 'No free VMID', $result->error() );
		$this->assertSame( array(), $this->calls( 'POST', '/clone' ) );
	}

	public function test_a_recorded_vmid_outside_the_range_is_never_created(): void {
		// The range shrinks under an existing record: provisioning refuses to build on it.
		$other = $this->resource();
		VmRecords::allocate( $other->id(), 'lab', 5050, 5060, array(), fn() => true );
		$this->cluster( array( 'vmid_min' => '5000', 'vmid_max' => '5010' ) );

		$result = $this->run_provision( $other );
		$this->assertTrue( $result->is_failed() );
		$this->assertStringContainsString( 'outside the customer range', $result->error() );
		$this->assertNull( $this->fake->vm( 5050 ) );
	}

	/**
	 * Holds a named lock on a second connection.
	 *
	 * @param string $name Lock name.
	 */
	private function lock_elsewhere( string $name ): \mysqli {
		$other = new \mysqli( DB_HOST, DB_USER, DB_PASSWORD, DB_NAME );
		$res   = $other->query( "SELECT GET_LOCK('" . $other->real_escape_string( $name ) . "', 0)" );
		$this->assertSame( '1', (string) $res->fetch_row()[0] );

		return $other;
	}

	public function test_a_busy_allocation_lock_is_a_retry_not_a_failure(): void {
		add_filter( 'beltoft_services_alloc_lock_wait', fn() => 1 );

		// The IP pool is busy.
		$resource = $this->resource();
		$other    = $this->lock_elsewhere( \BeltoftServices\Locks::name( 'ippool', 0 ) );
		$result   = $this->run_provision( $resource );
		$this->assertTrue( $result->is_retry() );
		$this->assertSame( array(), $this->fake->calls() );
		$other->close();

		// The VMID allocation is busy.
		$other  = $this->lock_elsewhere( \BeltoftServices\Locks::name( 'vmid', 0 ) );
		$result = $this->run_provision( $resource );
		$this->assertTrue( $result->is_retry() );
		$this->assertNull( VmRecords::for_resource( $resource->id() ) );
		$other->close();

		$this->assert_done( $this->run_provision( $resource ) );
	}

	public function test_a_vmid_that_cannot_be_confirmed_free_is_kept_and_asked_again(): void {
		$resource = $this->resource();

		// The nextid check fails with a server error: nothing is created, the id stays recorded for the retry.
		$this->fake->fail_next( 'GET', '#/cluster/nextid$#', array( 'status' => 500, 'message' => 'no quorum' ) );
		$first = $this->run_provision( $resource );
		$this->assertTrue( $first->is_retry() );
		$this->assertSame( array(), $this->calls( 'POST', '/clone' ) );

		$this->assert_done( $this->run_provision( $resource ) );
		$this->assertSame( 5000, VmRecords::for_resource( $resource->id() )['vmid'] );
	}

	public function test_the_ssh_key_is_encoded_exactly_once_on_the_wire(): void {
		$wire = new WireClient();
		$wire->add_node( 'node-a' );
		$wire->add_vm( array( 'vmid' => 9000, 'node' => 'node-a', 'template' => 1, 'config' => array( 'scsi0' => 'disk-store:vm-9000-disk-0,size=10G', 'net0' => 'virtio=AA:BB:CC:DD:EE:FF,bridge=vmbr0' ) ) );
		$this->fake = $wire;

		$key      = (string) \BeltoftServices\Proxmox\SshKeyField::normalise( $this->ed25519() );
		$resource = $this->resource( array(), array( 'ssh_key' => $key ) );

		$this->respond_with(
			function () {
				return array(
					'headers'  => array(),
					'body'     => wp_json_encode( array( 'data' => null ) ),
					'response' => array( 'code' => 200, 'message' => 'OK' ),
					'cookies'  => array(),
					'filename' => null,
				);
			}
		);

		$this->assert_done( $this->run_provision( $resource ) );

		$sent = array_values( array_filter( $this->http_calls(), static fn( $c ) => false !== strpos( $c['url'], '/config' ) ) );
		$this->assertCount( 1, $sent );
		parse_str( (string) $sent[0]['args']['body'], $fields );
		// One rawurlencode by the client: after the form decoding the value is still encoded text, and decoding that once gives the key.
		$this->assertSame( rawurlencode( $key . "\n" ), $fields['sshkeys'] );
		$this->assertSame( $key . "\n", rawurldecode( $fields['sshkeys'] ) );
		$this->assertStringNotContainsString( '%2525', (string) $sent[0]['args']['body'], 'Not encoded twice.' );
	}

	public function test_a_pool_gateway_outside_the_network_fails_before_any_config_write(): void {
		$this->wipe_tables();
		IpPool::add( '192.0.2.10', '203.0.113.1' );

		$result = $this->run_provision( $this->resource() );

		$this->assertTrue( $result->is_failed() );
		$this->assertStringContainsString( 'gateway', $result->error() );
		$this->assertSame( array(), $this->calls( 'PUT', '/config' ) );
		$this->assertSame( array(), $this->calls( 'POST', '/config' ), 'Not even the cloud-init drive.' );

		// A gateway inside address/prefix is used.
		$this->wipe_tables();
		IpPool::add( '192.0.2.10', '192.0.2.254' );
		$ok = $this->resource();
		$this->assert_done( $this->run_provision( $ok ) );
		$this->assertSame( 'ip=192.0.2.10/24,gw=192.0.2.254', $this->fake->vm( 5001 )['config']['ipconfig0'] );
	}

	public function test_a_retry_that_advanced_carries_progress_and_polling_does_not(): void {
		$resource = $this->resource();
		$this->fake->fail_next( 'POST', '#/status/start$#', array( 'status' => 503, 'message' => 'busy' ) );
		$advanced = $this->run_provision( $resource );
		$this->assertTrue( $advanced->is_retry() );
		$this->assertTrue( $advanced->has_progress(), 'Four steps completed in that run.' );

		$again = $this->run_provision( $resource );
		$this->assert_done( $again );

		// A pure poll of a running task is no progress.
		$slow = $this->resource();
		$this->fake->next_task( array( 'polls' => 99 ) );
		$first = $this->run_provision( $slow );
		$this->assertTrue( $first->is_retry() );
		$poll = $this->run_provision( $slow );
		$this->assertTrue( $poll->is_retry() );
		$this->assertFalse( $poll->has_progress() );
	}

	public function test_a_failed_clone_marker_survives_a_crash(): void {
		$resource = $this->resource();
		$this->fake->next_task( array( 'exitstatus' => 'ERROR: clone failed', 'effect' => true ) );
		$this->assertTrue( $this->run_provision( $resource )->is_failed() );
		$this->assertSame( 5000, Resource::find( $resource->id() )->get( 'clone_failed' ) );

		// A later run (an admin retry, no task id left) must not adopt the leftover.
		$resource->set( array( 'task' => null ) );
		$again = $this->run_provision( $resource );
		$this->assertTrue( $again->is_failed() );
		$this->assertStringContainsString( 'still exists', $again->error() );
		$this->assertCount( 1, $this->calls( 'POST', '/clone' ) );

		// Once the admin removed the VM, the marker is cleared and the clone goes ahead.
		$this->fake->request( 'DELETE', '/nodes/node-a/qemu/5000' );
		$this->assert_done( $this->run_provision( $resource ) );
		$this->assertEmpty( Resource::find( $resource->id() )->get( 'clone_failed' ) );
	}

	public function test_a_digest_mismatch_is_retried_once_in_place(): void {
		$resource = $this->resource();
		$this->fake->fail_next( 'PUT', '#/config$#', array( 'status' => 400, 'message' => 'Parameter verification failed: digest: detected modified configuration' ) );
		$this->assert_done( $this->run_provision( $resource ) );
		$this->assertCount( 2, $this->calls( 'PUT', '/qemu/5000/config' ) );

		$stuck = $this->resource();
		$this->fake->fail_next( 'PUT', '#/config$#', array( 'status' => 400, 'message' => 'detected modified configuration' ) );
		$this->fake->fail_next( 'PUT', '#/config$#', array( 'status' => 400, 'message' => 'detected modified configuration' ) );
		$this->assertTrue( $this->run_provision( $stuck )->is_failed() );
	}

	public function test_the_agent_is_found_wherever_enabled_sits_in_the_string(): void {
		$this->template( array( 'config' => array( 'scsi0' => 'disk-store:vm-9000-disk-0,size=10G', 'net0' => 'virtio=AA:BB:CC:DD:EE:FF,bridge=vmbr0', 'agent' => 'fstrim_cloned_disks=1,enabled=1' ) ) );
		$resource = $this->resource();
		$this->assert_done( $this->run_provision( $resource ) );
		$this->assertSame( 'ok', Resource::find( $resource->id() )->get( 'agent' ) );
		$this->assertNotEmpty( $this->calls( 'POST', '/agent/ping' ) );

		$this->template( array( 'config' => array( 'scsi0' => 'disk-store:vm-9000-disk-0,size=10G', 'net0' => 'virtio=AA:BB:CC:DD:EE:FF,bridge=vmbr0', 'agent' => 'enabled=0,fstrim_cloned_disks=1' ) ) );
		$off = $this->resource();
		$seen = count( $this->calls( 'POST', '/agent/ping' ) );
		$this->assert_done( $this->run_provision( $off ) );
		$this->assertCount( $seen, $this->calls( 'POST', '/agent/ping' ) );
		$this->assertSame( 'unknown', Resource::find( $off->id() )->get( 'agent' ) );
	}

	public function test_ready_records_the_node_the_vm_runs_on_now(): void {
		$resource = $this->resource();
		$this->fake->fail_next( 'POST', '#/status/start$#', array( 'status' => 503, 'message' => 'busy' ) );
		$this->assertTrue( $this->run_provision( $resource )->is_retry() );

		// The VM moved to another node (HA) before the last step.
		$this->fake->add_node( 'node-b' );
		$vm         = $this->fake->vm( 5000 );
		$vm['node'] = 'node-b';
		$this->fake->add_vm( $vm );
		$this->cluster( array( 'nodes' => 'node-a, node-b' ) );

		$this->assert_done( $this->run_provision( $resource ) );
		$this->assertSame( 'node-b', Resource::find( $resource->id() )->get( 'node' ) );
		$this->assertSame( 'node-b', VmRecords::for_resource( $resource->id() )['node'] );
	}

	public function test_wipe_rebuilds_on_the_same_vmid_and_address(): void {
		$resource = $this->resource();
		$this->assert_done( $this->run_provision( $resource ) );
		$record = VmRecords::for_resource( $resource->id() );
		$ip     = IpPool::for_resource( $resource->id() );

		// A reinstall asks for a wipe.
		$resource->set( array( 'wipe' => true ) );
		$seen = count( $this->fake->calls() );
		$this->assert_done( $this->run_provision( $resource ) );

		$calls = array_slice( $this->fake->calls(), $seen );
		$paths = array_map( static fn( $c ) => $c['method'] . ' ' . $c['path'], $calls );

		$shutdown = array_search( 'POST /nodes/node-a/qemu/5000/status/shutdown', $paths, true );
		$delete   = array_search( 'DELETE /nodes/node-a/qemu/5000', $paths, true );
		$this->assertNotFalse( $shutdown );
		$this->assertNotFalse( $delete );
		$this->assertLessThan( $delete, $shutdown, 'Shutdown first, never a plug pull first.' );

		foreach ( $calls as $call ) {
			if ( 'DELETE' === $call['method'] ) {
				$this->assertSame( '/nodes/node-a/qemu/5000', $call['path'] );
			}
		}

		$fresh = Resource::find( $resource->id() );
		$this->assertEmpty( $fresh->get( 'wipe' ) );
		$this->assertSame( $record['vmid'], VmRecords::for_resource( $resource->id() )['vmid'] );
		$this->assertSame( $ip['address'], IpPool::for_resource( $resource->id() )['address'] );
		$this->assertSame( 'assigned', IpPool::for_resource( $resource->id() )['state'] );
		$this->assertSame( 'running', $this->fake->vm( 5000 )['status'] );
		$this->assertSame( Ownership::tag( $fresh ), $this->fake->vm( 5000 )['config']['description'] );
	}

	public function test_wipe_refuses_a_vm_that_is_not_ours(): void {
		$resource = $this->resource();
		$this->assert_done( $this->run_provision( $resource ) );

		$this->fake->request( 'PUT', '/nodes/node-a/qemu/5000/config', array( 'description' => 'taken over by someone' ) );
		$resource->set( array( 'wipe' => true ) );
		$seen = count( $this->fake->calls() );

		$result = $this->run_provision( $resource );

		$this->assertTrue( $result->is_failed() );
		$this->assertStringContainsString( 'Ownership check failed', $result->error() );

		foreach ( array_slice( $this->fake->calls(), $seen ) as $call ) {
			$this->assertSame( 'GET', $call['method'], 'Read-only: ' . $call['method'] . ' ' . $call['path'] );
		}

		$this->assertSame( 'running', $this->fake->vm( 5000 )['status'] );
	}

	public function test_driver_identity(): void {
		$this->assertSame( 'vm', $this->driver->kind() );
		$this->assertSame( 'proxmox', $this->driver->id() );
		$this->assertNotContains( 'Drivers\\Opens', class_implements( $this->driver ) );
		$this->assertSame( '2 of 3 servers', $this->driver->unit_label( 2, 3 ) );
		$this->assertSame( '0 of 1 server', $this->driver->unit_label( 0, 1 ) );
	}

	public function test_plan_fields_are_per_variation_and_list_the_clusters(): void {
		$fields = $this->driver->plan_fields();

		$this->assertSame( array( 'cluster', 'template', 'cores', 'memory_mb', 'disk_gb' ), array_keys( $fields ) );

		foreach ( $fields as $field ) {
			$this->assertTrue( $field[4], 'Every field can differ per variation.' );
		}

		$this->assertSame( 'text', $fields['cluster'][0] );
		$this->assertSame( 'number', $fields['template'][0] );
		$this->assertStringContainsString( 'lab', $fields['cluster'][2] );
		$this->assertStringContainsString( '9000 = Debian 12', $fields['template'][2] );
		$this->assertSame( array( 1, 2048, 30 ), array( $fields['cores'][3], $fields['memory_mb'][3], $fields['disk_gb'][3] ) );
	}
}

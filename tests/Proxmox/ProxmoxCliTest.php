<?php
/**
 * link-vm and the IP pool commands and screen. Only the fake client is used.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Proxmox;

use BeltoftServices\Cli\Commands;
use BeltoftServices\Jobs;
use BeltoftServices\Proxmox\Actions;
use BeltoftServices\Proxmox\Clusters;
use BeltoftServices\Proxmox\FakeClient;
use BeltoftServices\Proxmox\IpPool;
use BeltoftServices\Proxmox\IpPoolScreen;
use BeltoftServices\Proxmox\LinkVm;
use BeltoftServices\Proxmox\Ownership;
use BeltoftServices\Proxmox\Schema;
use BeltoftServices\Proxmox\VmRecords;
use BeltoftServices\Reconciler;
use BeltoftServices\Resource;
use BeltoftServices\Tests\TestCase;

require_once dirname( __DIR__ ) . '/Support/WpCliStub.php';

/**
 * Adopting a running machine changes bookkeeping and the tag, nothing else.
 */
class ProxmoxCliTest extends TestCase {

	/**
	 * The fake cluster.
	 *
	 * @var FakeClient
	 */
	private $fake;

	public function set_up(): void {
		parent::set_up();

		\WP_CLI::reset();
		$key = base64_encode( random_bytes( 32 ) );
		add_filter( 'beltoft_services_key', fn() => $key );
		$this->wipe();
		as_unschedule_all_actions( Jobs::SWEEP, array(), Jobs::GROUP );

		delete_option( FakeClient::OPTION );
		$this->fake = new FakeClient( true );
		add_filter( 'beltoft_services_proxmox_client', fn() => $this->fake );
		$this->fake->add_node( 'node-a' );
		$this->fake->add_pool( 'customers' );

		$this->cluster();
		IpPool::add( '192.0.2.10' );
		IpPool::add( '192.0.2.11' );
	}

	public function tear_down(): void {
		remove_all_filters( 'beltoft_services_key' );
		remove_all_filters( 'beltoft_services_proxmox_client' );
		delete_option( Clusters::OPTION );
		delete_option( FakeClient::OPTION );
		$this->wipe();

		parent::tear_down();
	}

	private function wipe(): void {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- test fixture reset.
		$wpdb->query( 'DELETE FROM ' . Schema::tables()['ips'] );
		$wpdb->query( 'DELETE FROM ' . Schema::tables()['vms'] );
		$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_%beltoft\\_services\\_%'" );
		// phpcs:enable
		wp_cache_flush();
	}

	private function cluster( array $over = array() ): void {
		$this->assertTrue(
			Clusters::save(
				'lab',
				array_merge(
					array(
					'label'             => 'Lab',
					'api_url'           => 'https://pve.example.invalid:8006',
					'token_id'          => 'svc@pve!beltoft',
					'token_secret'      => 'secret-secret-secret-1',
					'client'            => 'fake',
					'pool_api'          => 'pools',
					'nodes'             => 'node-a',
					'storage'           => 'disk-store',
					'cloudinit_storage' => 'ci-store',
					'bridge'            => 'vmbr9',
					'pool'              => 'customers',
					'gateway'           => '192.0.2.1',
					'nameservers'       => '192.0.2.53',
					'prefix'            => '24',
					'vmid_min'          => '200',
					'vmid_max'          => '999',
					'templates'         => '9000 = Debian 12',
					),
					$over
				)
			)
		);

		$stored        = get_option( Clusters::OPTION );
		$stored['lab'] = array_merge( $stored['lab'], array( 'persist' => 1 ) );
		update_option( Clusters::OPTION, $stored, false );
	}

	/**
	 * An active license on a Proxmox product, no lifecycle hooks.
	 *
	 * @return array<string, mixed>
	 */
	private function license(): array {
		$product = $this->service_product( 'proxmox', array( 'cluster' => 'lab', 'template' => 9000, 'cores' => 2, 'memory_mb' => 2048, 'disk_gb' => 30 ), array( 'slug' => 'vm-' . wp_generate_password( 5, false, false ) ) );

		return $this->service_license( $product, array( 'fire' => false ) );
	}

	private function vm( int $vmid = 200, array $config = array(), array $over = array() ): void {
		$this->fake->add_vm(
			array_merge(
				array(
					'vmid'   => $vmid,
					'node'   => 'node-a',
					'status' => 'running',
					'config' => array_merge(
						array(
							'description' => 'Customer notes',
							'ipconfig0'   => 'ip=192.0.2.10/24,gw=192.0.2.1',
							'net0'        => 'virtio=AA:BB:CC:DD:EE:FF,bridge=vmbr9',
							'memory'      => 2048,
						),
						$config
					),
				),
				$over
			)
		);
	}

	private function link( array $license, array $over = array() ) {
		return LinkVm::run(
			(int) $license['id'],
			array_merge(
				array(
					'cluster' => 'lab',
					'node'    => 'node-a',
					'vmid'    => 200,
					'ip'      => '192.0.2.10',
				),
				$over
			)
		);
	}

	private function failure( callable $call ): string {
		try {
			$call();
		} catch ( \RuntimeException $e ) {
			return $e->getMessage();
		}

		return '';
	}

	private function error_text( $result ): string {
		return is_wp_error( $result ) ? $result->get_error_message() : '';
	}

	public function test_link_vm_dry_run_lists_calls_and_writes_nothing(): void {
		$license = $this->license();
		$this->vm();

		( new Commands() )->link_vm( array( (string) $license['id'] ), array( 'cluster' => 'lab', 'node' => 'node-a', 'vmid' => '200', 'ip' => '192.0.2.10', 'dry-run' => true ) );

		$text = \WP_CLI::text();
		$this->assertStringContainsString( 'READ   GET /cluster/resources', $text );
		$this->assertStringContainsString( 'READ   GET /nodes/node-a/qemu/200/config', $text );
		$this->assertStringContainsString( 'WRITE  PUT /nodes/node-a/qemu/200/config description=<existing description> + newline + beltoft-services:', $text );
		$this->assertStringContainsString( 'WRITE  PUT /pools poolid=customers vms=200', $text );

		$this->assertSame( array(), $this->fake->calls(), 'A dry run makes no call, reads included.' );
		$this->assertNull( Resource::for_license( (int) $license['id'] ) );
		$this->assertNull( VmRecords::for_resource( 1 ) );
		$this->assertSame( 'free', IpPool::find( '192.0.2.10' )['state'] );
		$this->assertSame( 'Customer notes', $this->fake->vm( 200 )['config']['description'] );
	}

	public function test_link_vm_makes_only_the_listed_reads_tag_write_and_pool_write(): void {
		$license = $this->license();
		$this->vm();

		$result = $this->link( $license, array( 'confirm' => '' ) );

		$this->assertIsArray( $result, $this->error_text( $result ) );
		$this->assertTrue( $result['linked'] );

		$calls = array_map( static fn( $c ) => $c['method'] . ' ' . $c['path'], $this->fake->calls() );
		$this->assertSame(
			array(
				'GET /cluster/resources',
				'GET /nodes/node-a/qemu/200/config',
				'PUT /nodes/node-a/qemu/200/config',
				'PUT /pools',
			),
			$calls
		);

		$config_write = $this->fake->calls()[2]['params'];
		$this->assertSame( array( 'description', 'digest' ), array_keys( $config_write ), 'Only the description, guarded by the digest.' );

		$resource = Resource::for_license( (int) $license['id'] );
		$tag      = Ownership::tag( $resource );
		$this->assertSame( "Customer notes\n" . $tag, $this->fake->vm( 200 )['config']['description'], 'Existing text kept, the tag on its own line.' );
		$this->assertSame( array( 'poolid' => 'customers', 'vms' => '200' ), $this->fake->calls()[3]['params'] );
		$this->assertContains( 200, $this->fake->state()['pools']['customers']['members'] );

		$this->assertSame( 200, VmRecords::for_resource( $resource->id() )['vmid'] );
		$this->assertSame( 'assigned', IpPool::find( '192.0.2.10' )['state'] );
		$this->assertSame( $resource->id(), IpPool::find( '192.0.2.10' )['resource_id'] );
		$this->assertSame( 'running', $this->fake->vm( 200 )['status'], 'Not touched.' );
		$this->assertSame( 'virtio=AA:BB:CC:DD:EE:FF,bridge=vmbr9', $this->fake->vm( 200 )['config']['net0'] );
	}

	public function test_pool_api_pools_id_and_vm_already_in_a_pool(): void {
		$this->cluster( array( 'pool_api' => 'pools_id' ) );
		$license = $this->license();
		$this->vm();
		$this->assertIsArray( $this->link( $license ) );
		$this->assertSame( 'PUT /pools/customers', $this->fake->calls()[3]['method'] . ' ' . $this->fake->calls()[3]['path'] );
		$this->assertSame( array( 'vms' => '200' ), $this->fake->calls()[3]['params'] );

		// A machine in another pool is not moved.
		$this->fake->add_pool( 'other' );
		$license2 = $this->license();
		$this->vm( 201, array( 'ipconfig0' => 'ip=192.0.2.11/24,gw=192.0.2.1' ), array( 'pool' => 'other' ) );
		$before = count( $this->fake->calls() );
		$result = $this->link( $license2, array( 'vmid' => 201, 'ip' => '192.0.2.11' ) );
		$this->assertIsArray( $result, $this->error_text( $result ) );
		$new = array_slice( $this->fake->calls(), $before );
		$this->assertSame( array( 'GET', 'GET', 'PUT' ), array_column( $new, 'method' ), 'No pool call.' );
		$this->assertStringContainsString( 'not moved', implode( ' ', $result['warnings'] ) );
	}

	public function test_link_vm_adopts_a_machine_on_another_network_outside_the_pool(): void {
		$license = $this->license();
		$this->vm( 201, array( 'ipconfig0' => 'ip=10.10.10.6/24', 'net0' => 'virtio=11:22:33:44:55:66,bridge=vmbr1' ) );

		$refused = $this->link( $license, array( 'vmid' => 201, 'ip' => '10.10.10.6' ) );
		$this->assertStringContainsString( '--outside-pool', $this->error_text( $refused ) );
		$this->assertSame( array(), $this->fake->calls(), 'Refused before any call.' );

		$result = $this->link( $license, array( 'vmid' => 201, 'ip' => '10.10.10.6', 'outside_pool' => true ) );
		$this->assertIsArray( $result, $this->error_text( $result ) );

		$resource = Resource::for_license( (int) $license['id'] );
		$this->assertSame( '10.10.10.6', $resource->get( 'ip' ) );
		$this->assertNull( VmRecords::for_resource( $resource->id() )['ip_id'] );
		$this->assertSame( 'free', IpPool::find( '192.0.2.10' )['state'], 'The pool is untouched.' );
		$this->assertSame( 'virtio=11:22:33:44:55:66,bridge=vmbr1', $this->fake->vm( 201 )['config']['net0'], 'Bridge and MAC untouched.' );
		$this->assertSame( 'ip=10.10.10.6/24', $this->fake->vm( 201 )['config']['ipconfig0'] );

		// An address that IS in the pool is not "outside".
		$license2 = $this->license();
		$this->vm( 202 );
		$this->assertStringContainsString( 'is in the IP pool', $this->error_text( $this->link( $license2, array( 'vmid' => 202, 'outside_pool' => true ) ) ) );
	}

	public function test_link_vm_refuses_ip_that_does_not_match_the_vm_config(): void {
		$license = $this->license();
		$this->vm( 200, array( 'ipconfig0' => 'ip=192.0.2.11/24,gw=192.0.2.1' ) );

		$this->assertStringContainsString( 'ipconfig0', $this->error_text( $this->link( $license ) ) );
		$this->assertNull( Resource::for_license( (int) $license['id'] ) );
		$this->assertSame( 'free', IpPool::find( '192.0.2.10' )['state'] );
		$this->assertSame( array( 'GET', 'GET' ), array_column( $this->fake->calls(), 'method' ), 'Reads only.' );

		$this->vm( 203, array( 'ipconfig0' => 'ip=dhcp' ) );
		$this->assertStringContainsString( 'ipconfig0', $this->error_text( $this->link( $license, array( 'vmid' => 203 ) ) ) );

		// A different prefix of the same text must not match: 192.0.2.1 is not 192.0.2.10.
		$this->vm( 204, array( 'ipconfig0' => 'ip=192.0.2.100/24' ) );
		$this->assertStringContainsString( 'ipconfig0', $this->error_text( $this->link( $license, array( 'vmid' => 204 ) ) ) );
		$this->assertCount( 0, array_filter( $this->fake->calls(), static fn( $c ) => 'PUT' === $c['method'] ) );
	}

	public function test_link_vm_refuses_a_template_or_out_of_range_vmid_or_inactive_license(): void {
		$license = $this->license();

		$this->vm( 300, array(), array( 'template' => 1 ) );
		$this->assertStringContainsString( 'template', $this->error_text( $this->link( $license, array( 'vmid' => 300 ) ) ) );

		$this->vm( 150 );
		$this->assertStringContainsString( 'outside', $this->error_text( $this->link( $license, array( 'vmid' => 150 ) ) ) );
		$this->vm( 1000 );
		$this->assertStringContainsString( 'outside', $this->error_text( $this->link( $license, array( 'vmid' => 1000 ) ) ) );

		$this->vm( 301 );
		$this->assertStringContainsString( 'not in the cluster listing', $this->error_text( $this->link( $license, array( 'vmid' => 302 ) ) ) );
		$this->assertStringContainsString( 'node "node-a"', $this->error_text( $this->link( $license, array( 'vmid' => 301, 'node' => 'node-b' ) ) ) );

		$this->vm( 305, array( 'description' => "x\nbeltoft-services:other.site:abc" ) );
		$this->assertStringContainsString( 'already carries', $this->error_text( $this->link( $license, array( 'vmid' => 305 ) ) ) );

		$this->assertStringContainsString( 'not a Proxmox service', $this->error_text( LinkVm::run( (int) $this->service_license( $this->service_product( 'fake' ), array( 'fire' => false ) )['id'], array( 'cluster' => 'lab', 'node' => 'node-a', 'vmid' => 200, 'ip' => '192.0.2.10' ) ) ) );
		$this->assertStringContainsString( 'not configured', $this->error_text( $this->link( $license, array( 'cluster' => 'nope' ) ) ) );

		$this->assertNull( Resource::for_license( (int) $license['id'] ) );
		$this->assertCount( 0, array_filter( $this->fake->calls(), static fn( $c ) => 'PUT' === $c['method'] ), 'No write for any refusal.' );

		// An expired license.
		$expired = $this->license();
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- test fixture.
		$wpdb->update( \BeltoftLicenses\Install::tables()['licenses'], array( 'status' => 'revoked' ), array( 'id' => (int) $expired['id'] ) );
		$this->vm( 306 );
		$this->assertStringContainsString( 'active license', $this->error_text( $this->link( $expired, array( 'vmid' => 306 ) ) ) );

		// VMID already recorded; address already in use.
		$this->vm( 200 );
		$this->assertIsArray( $this->link( $license ) );
		$other = $this->license();
		$this->assertStringContainsString( 'already recorded', $this->error_text( $this->link( $other ) ) );
		$this->vm( 310, array( 'ipconfig0' => 'ip=192.0.2.10/24' ) );
		$this->assertStringContainsString( 'not free', $this->error_text( $this->link( $other, array( 'vmid' => 310 ) ) ) );

		// A license that has a resource already.
		$this->assertStringContainsString( 'already has a live resource', $this->error_text( $this->link( $license, array( 'vmid' => 311 ) ) ) );
	}

	public function test_link_vm_needs_confirmation_against_a_live_client(): void {
		$license = $this->license();
		$this->vm();

		$this->assertTrue( LinkVm::needs_confirmation( array( 'client' => 'live' ) ) );
		$this->assertFalse( LinkVm::needs_confirmation( array( 'client' => 'fake' ) ) );

		foreach ( array( '', 'LINK:201', 'link:200', 'LINK:200 ' ) as $wrong ) {
			$error = $this->error_text( $this->link( $license, array( 'live' => true, 'confirm' => $wrong ) ) );
			$this->assertStringContainsString( '--confirm=LINK:200', $error, "'$wrong' is not the confirmation." );
		}

		$this->assertSame( array(), $this->fake->calls(), 'Refused before any call.' );
		$this->assertNull( Resource::for_license( (int) $license['id'] ) );

		// A dry run needs none.
		$dry = $this->link( $license, array( 'live' => true, 'dry_run' => true ) );
		$this->assertIsArray( $dry );

		$ok = $this->link( $license, array( 'live' => true, 'confirm' => 'LINK:200' ) );
		$this->assertIsArray( $ok, $this->error_text( $ok ) );
		$this->assertNotNull( Resource::for_license( (int) $license['id'] ) );
	}

	public function test_a_failed_tag_write_leaves_nothing_behind(): void {
		$license = $this->license();
		$this->vm();
		$this->fake->fail_next( 'PUT', '#/config$#', array( 'status' => 500, 'message' => 'boom' ) );

		$this->assertStringContainsString( 'tag could not be written', $this->error_text( $this->link( $license ) ) );
		$this->assertNull( Resource::for_license( (int) $license['id'] ), 'The resource is closed again.' );
		$this->assertSame( 'free', IpPool::find( '192.0.2.10' )['state'] );
		$this->assertSame( array(), VmRecords::for_cluster( 'lab' ) );
		$this->assertSame( 'Customer notes', $this->fake->vm( 200 )['config']['description'] );

		// And the same license can be linked afterwards.
		$this->assertIsArray( $this->link( $license ) );
	}

	public function test_a_failed_pool_write_keeps_the_link_with_a_warning(): void {
		$license = $this->license();
		$this->vm();
		$this->fake->fail_next( 'PUT', '#^/pools#', array( 'status' => 500, 'message' => 'no pool' ) );

		$result = $this->link( $license );
		$this->assertIsArray( $result );
		$this->assertStringContainsString( 'could not be added to the pool', implode( ' ', $result['warnings'] ) );
		$this->assertNotNull( Resource::for_license( (int) $license['id'] ) );
	}

	public function test_linked_vm_is_protected_and_not_marked_for_update(): void {
		$license = $this->license();
		$this->vm();
		$this->assertIsArray( $this->link( $license ) );

		$resource = Resource::for_license( (int) $license['id'] );
		$this->assertSame( 'ready', $resource->get( 'state' ) );
		$this->assertSame( 'ready', $resource->get( 'desired' ) );
		$this->assertTrue( $resource->get( 'protected' ) );
		$this->assertFalse( $resource->get( 'plan_dirty' ), 'An update_plan would shut the production machine down.' );
		$this->assertSame( 'ready', $resource->get( 'notified' ) );
		$this->assertSame( 'proxmox', $resource->get( 'driver' ) );
		$this->assertSame( '192.0.2.10', $resource->get( 'ip' ) );
		$this->assertNull( Reconciler::operation( $resource ), 'Nothing to do for the reconciler.' );
		$this->assertSame( array(), Jobs::pending( $resource->id() ), 'No job is queued.' );

		// The adopted machine keeps its key and its disk: no swap, no wipe.
		$offered = Actions::offered( $resource );
		$this->assertArrayNotHasKey( 'reinstall', $offered );
		$this->assertArrayNotHasKey( 'replace_ssh_key', $offered );
		$this->assertNotNull( Actions::check( $resource, 'reinstall', array( 'confirm' => 'REINSTALL' ) ) );
		$this->assertNotNull( Actions::check( $resource, 'replace_ssh_key', array( 'key' => 'x' ) ) );

		// The ownership proof the driver uses holds.
		$verified = Ownership::verify( $this->fake, VmRecords::for_resource( $resource->id() ), $resource );
		$this->assertIsArray( $verified, $this->error_text( $verified ) );
	}

	public function test_ip_pool_cli(): void {
		$cli = new Commands();
		$cli->ip_pool_add( array( '198.51.100.8/30' ), array( 'gateway' => '198.51.100.9' ) );
		$this->assertStringContainsString( '1 address', \WP_CLI::text(), 'The gateway is not a host.' );

		\WP_CLI::reset();
		$cli->ip_pool_add( array( '198.51.100.20' ), array() );
		$cli->ip_pool_block( array( '198.51.100.20' ), array() );
		$this->assertSame( 'blocked', IpPool::find( '198.51.100.20' )['state'] );
		$cli->ip_pool_block( array( '198.51.100.20' ), array( 'unblock' => true ) );
		$this->assertSame( 'free', IpPool::find( '198.51.100.20' )['state'] );

		$this->assertNotSame( '', $this->failure( fn() => $cli->ip_pool_add( array( 'not-an-ip' ), array() ) ) );
		$this->assertNotSame( '', $this->failure( fn() => $cli->ip_pool_add( array( '2001:db8::1' ), array() ) ), 'IPv4 only.' );
		$this->assertNotSame( '', $this->failure( fn() => $cli->ip_pool_block( array( '203.0.113.1' ), array() ) ), 'Unknown address.' );

		$license = $this->license();
		$this->vm();
		$this->assertIsArray( $this->link( $license ) );
		$this->assertNotSame( '', $this->failure( fn() => $cli->ip_pool_block( array( '192.0.2.10' ), array() ) ), 'An address in use cannot be blocked.' );

		\WP_CLI::reset();
		$cli->ip_pool_list( array(), array( 'format' => 'json' ) );
		$rows = json_decode( \WP_CLI::$lines[0][1], true );
		$by   = array_column( $rows, null, 'address' );
		$this->assertSame( 'assigned', $by['192.0.2.10']['state'] );
		$this->assertSame( 'free', $by['192.0.2.11']['state'] );
	}

	public function test_ip_pool_screen_releases_closed_resource_addresses_with_typed_confirmation(): void {
		$license = $this->license();
		$this->vm();
		$this->assertIsArray( $this->link( $license ) );
		$resource = Resource::for_license( (int) $license['id'] );

		// In use by a live service: not a leftover.
		$this->assertSame( 'error', IpPoolScreen::apply( 'release', array( 'address' => '192.0.2.10' ) )[0] );
		$this->assertSame( 'assigned', IpPool::find( '192.0.2.10' )['state'] );

		$resource->close(); // "Mark deleted" leaves the address behind.
		$row = IpPool::find( '192.0.2.10' );
		$this->assertSame( 'assigned', $row['state'] );
		$this->assertSame( 'closed', IpPool::leftover( $row ) );

		foreach ( array( '', 'RELEASE', 'RELEASE 192.0.2.11', 'release 192.0.2.10' ) as $wrong ) {
			$this->assertSame( 'error', IpPoolScreen::apply( 'release', array( 'address' => '192.0.2.10', 'confirm' => $wrong ) )[0], "'$wrong'" );
			$this->assertSame( 'assigned', IpPool::find( '192.0.2.10' )['state'] );
		}

		$done = IpPoolScreen::apply( 'release', array( 'address' => '192.0.2.10', 'confirm' => 'RELEASE 192.0.2.10' ) );
		$this->assertSame( 'success', $done[0] );
		$this->assertSame( 'free', IpPool::find( '192.0.2.10' )['state'] );
		$this->assertNull( IpPool::find( '192.0.2.10' )['resource_id'] );
	}

	public function test_ip_pool_screen_releases_a_held_address_of_a_failed_resource_and_blocks_and_adds(): void {
		$license  = $this->license();
		$resource = Resource::create( $license, \BeltoftServices\Drivers\Registry::get( 'proxmox' ), \BeltoftServices\Product::plan( (int) $license['product_id'] ) );
		$this->assertInstanceOf( Resource::class, $resource );
		$held = IpPool::hold( $resource->id() );
		$this->assertSame( 'held', $held['state'] );

		// Held by a resource that is still being set up: not releasable.
		$this->assertSame( 'error', IpPoolScreen::apply( 'release', array( 'address' => $held['address'] ) )[0] );

		$this->assertTrue( $resource->set( array( 'state' => 'failed' ) ) );
		$this->assertSame( 'failed', IpPool::find( $held['address'] )['resource_id'] ? IpPool::leftover( IpPool::find( $held['address'] ) ) : '' );

		// A failed resource's address needs no typing.
		$this->assertSame( 'success', IpPoolScreen::apply( 'release', array( 'address' => $held['address'] ) )[0] );
		$this->assertSame( 'free', IpPool::find( $held['address'] )['state'] );

		$this->assertSame( 'success', IpPoolScreen::apply( 'block', array( 'address' => '192.0.2.11' ) )[0] );
		$this->assertSame( 'blocked', IpPool::find( '192.0.2.11' )['state'] );
		$this->assertSame( 'error', IpPoolScreen::apply( 'block', array( 'address' => '192.0.2.11' ) )[0] );
		$this->assertSame( 'success', IpPoolScreen::apply( 'unblock', array( 'address' => '192.0.2.11' ) )[0] );
		$this->assertSame( 'success', IpPoolScreen::apply( 'add', array( 'address' => '198.51.100.0/30' ) )[0] );
		$this->assertSame( 'error', IpPoolScreen::apply( 'add', array( 'address' => 'x' ) )[0] );
		$this->assertSame( 'error', IpPoolScreen::apply( 'drop', array() )[0] );
	}

	public function test_ip_pool_screen_needs_capability_and_nonce(): void {
		$_POST = array( 'op' => 'block', 'address' => '192.0.2.11' );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$message = '';

		try {
			IpPoolScreen::handle();
		} catch ( \WPDieException $e ) {
			$message = $e->getMessage();
		}

		$this->assertStringContainsString( 'permission', $message );
		$this->assertSame( 'free', IpPool::find( '192.0.2.11' )['state'] );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$message = '';

		try {
			IpPoolScreen::handle();
		} catch ( \WPDieException $e ) {
			$message = $e->getMessage();
		}

		$this->assertNotSame( '', $message, 'No nonce: refused.' );
		$this->assertStringNotContainsString( 'permission', $message );
		$this->assertSame( 'free', IpPool::find( '192.0.2.11' )['state'] );

		$_POST = array();
		wp_set_current_user( 0 );
	}

	public function test_the_pool_tab_renders_the_leftover_forms(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$license = $this->license();
		$this->vm();
		$this->assertIsArray( $this->link( $license ) );
		Resource::for_license( (int) $license['id'] )->close();

		ob_start();
		IpPoolScreen::render();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'RELEASE 192.0.2.10', $html );
		$this->assertStringContainsString( 'name="confirm"', $html );
		$this->assertStringContainsString( 'value="block"', $html );

		wp_set_current_user( 0 );
	}
}

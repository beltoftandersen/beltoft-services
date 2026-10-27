<?php
/**
 * The recurring Proxmox jobs. Only the fake client is used: nothing here reaches a Proxmox host.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Proxmox;

use BeltoftServices\Drivers\Registry;
use BeltoftServices\Jobs;
use BeltoftServices\Product;
use BeltoftServices\Proxmox\Access;
use BeltoftServices\Proxmox\Clusters;
use BeltoftServices\Proxmox\FakeClient;
use BeltoftServices\Proxmox\IpPool;
use BeltoftServices\Proxmox\Operations;
use BeltoftServices\Proxmox\Placement;
use BeltoftServices\Proxmox\ProxmoxJobs;
use BeltoftServices\Proxmox\Stock;
use BeltoftServices\Proxmox\Schema;
use BeltoftServices\Proxmox\VmRecords;
use BeltoftServices\Resource;
use BeltoftServices\Tests\TestCase;

/**
 * Capacity cache, power state, abuse report and password purge.
 */
class ProxmoxJobsTest extends TestCase {

	private const GB = 1073741824;

	/**
	 * The fake cluster (keeps its state, as on staging).
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

		$this->wipe();
		$this->settings(
			array(
				'pause_all'       => 0,
				'abuse_mode'      => 'report',
				'abuse_cpu'       => 90,
				'abuse_hours'     => 1,
				'host_reserve_gb' => 4,
				'admin_email'     => 'admin@example.com',
			)
		);

		delete_option( FakeClient::OPTION );
		$this->fake = new FakeClient( true );
		add_filter( 'beltoft_services_proxmox_client', fn() => $this->fake );

		$this->fake->add_node( 'node-a', array( 'maxmem' => 64 * self::GB, 'mem' => 30 * self::GB ) );
		$this->fake->add_node( 'node-b', array( 'maxmem' => 64 * self::GB, 'mem' => 1 * self::GB ) );

		$this->cluster();
		as_unschedule_all_actions( Jobs::SWEEP, array(), Jobs::GROUP );
		ProxmoxJobs::unschedule();
	}

	public function tear_down(): void {
		remove_all_filters( 'beltoft_services_key' );
		remove_all_filters( 'beltoft_services_proxmox_client' );
		delete_option( Clusters::OPTION );
		delete_option( FakeClient::OPTION );
		$this->wipe();
		ProxmoxJobs::unschedule();

		parent::tear_down();
	}

	private function wipe(): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- test fixture reset.
		$wpdb->query( 'DELETE FROM ' . Schema::tables()['vms'] );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- test fixture reset.
		$wpdb->query( 'DELETE FROM ' . Schema::tables()['ips'] );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- test fixture reset.
		$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_%beltoft\\_services\\_proxmox\\_%'" );
		wp_cache_flush();
	}

	/**
	 * Saves the cluster "lab" and marks it as keeping its fake state.
	 *
	 * @param array<string, mixed> $over Overrides of the stored cluster.
	 */
	private function cluster( array $over = array() ): void {
		$saved = Clusters::save(
			'lab',
			array(
				'label'             => 'Lab',
				'api_url'           => 'https://pve.example.invalid:8006',
				'token_id'          => 'svc@pve!beltoft',
				'token_secret'      => 'secret-secret-secret-1',
				'client'            => 'fake',
				'pool_api'          => 'pools',
				'nodes'             => 'node-a, node-b',
				'storage'           => 'disk-store',
				'cloudinit_storage' => 'ci-store',
				'bridge'            => 'vmbr9',
				'vlan'              => '',
				'boot_disk'         => 'scsi0',
				'gateway'           => '192.0.2.1',
				'nameservers'       => '192.0.2.53',
				'prefix'            => '24',
				'vmid_min'          => '5000',
				'vmid_max'          => '5099',
				'templates'         => '9000 = Debian 12',
				'block_smtp'        => '1',
			)
		);

		$this->assertTrue( $saved );

		$stored            = get_option( Clusters::OPTION );
		$stored['lab']     = array_merge( $stored['lab'], array( 'persist' => 1 ), $over );
		update_option( Clusters::OPTION, $stored, false );
	}

	/**
	 * A ready resource with a VM on the cluster.
	 *
	 * @param int    $vmid   VMID.
	 * @param string $node   Node.
	 * @param string $status running or stopped.
	 * @param int    $memory Configured memory in MB.
	 * @param array  $vm     Extra VM fields.
	 */
	private function vm( int $vmid, string $node = 'node-a', string $status = 'running', int $memory = 4096, array $vm = array() ): Resource {
		$product = $this->service_product( 'proxmox', array( 'cluster' => 'lab', 'template' => 9000, 'cores' => 2, 'memory_mb' => $memory, 'disk_gb' => 30 ), array( 'max' => 3 ) );
		$license = $this->service_license( $product, array( 'fire' => false ) );
		$r       = Resource::create( $license, Registry::get( 'proxmox' ), Product::plan( $product->get_id() ) );

		$this->assertInstanceOf( Resource::class, $r );
		$this->assertSame( $vmid, VmRecords::allocate( $r->id(), 'lab', $vmid, $vmid, array(), fn() => true ) );
		$this->assertTrue( $r->set( array( 'state' => 'ready', 'desired' => 'ready' ) ) );

		$this->fake->add_vm( array_merge( array( 'vmid' => $vmid, 'node' => $node, 'status' => $status, 'config' => array( 'memory' => $memory, 'cores' => 2 ) ), $vm ) );

		return Resource::find( $r->id() );
	}

	/**
	 * Sample rows: seventy a minute apart (a bit over an hour, as the `hour` timeframe gives), cpu given as PVE's fraction.
	 *
	 * @param callable $cpu fn( int $minute ): ?float.
	 * @param int      $end Time of the last sample.
	 * @return array<int, array<string, mixed>>
	 */
	private function samples( callable $cpu, int $end = 1700000000 ): array {
		$rows = array();

		for ( $m = 0; $m < 70; $m++ ) {
			$row = array( 'time' => $end - ( 69 - $m ) * 60 );
			$v   = $cpu( $m );

			if ( null !== $v ) {
				$row['cpu'] = $v;
			}

			$rows[] = $row;
		}

		return $rows;
	}

	private function paths( string $method, string $regex ): int {
		$n = 0;

		foreach ( $this->fake->calls() as $call ) {
			if ( $call['method'] === $method && 1 === preg_match( $regex, $call['path'] ) ) {
				++$n;
			}
		}

		return $n;
	}

	public function test_capacity_cached_and_no_call_on_page_requests(): void {
		$this->vm( 5000, 'node-a', 'running', 8192 );
		$this->vm( 5001, 'node-a', 'stopped', 4096 ); // A stopped VM still holds its memory.
		$this->fake->add_vm( array( 'vmid' => 700, 'node' => 'node-a', 'status' => 'running', 'config' => array( 'memory' => 16384 ) ) ); // Not ours.
		$this->fake->add_vm( array( 'vmid' => 9000, 'node' => 'node-b', 'template' => 1, 'config' => array( 'memory' => 2048 ) ) );

		ProxmoxJobs::capacity();

		$rows = get_transient( Placement::CAPACITY_PREFIX . 'lab' );
		$this->assertIsArray( $rows );
		$by = array_column( $rows, null, 'node' );

		$this->assertSame( 64 * self::GB, $by['node-a']['maxmem'] );
		$this->assertSame( ( 8192 + 4096 ) * 1048576 + 4 * self::GB, $by['node-a']['mem'], 'Committed memory of our VMs plus the host reserve.' );
		$this->assertSame( 30 * self::GB, $by['node-a']['mem_used'] );
		$this->assertSame( 4 * self::GB, $by['node-b']['mem'], 'Templates and foreign VMs are not committed.' );
		$this->assertSame( 'online', $by['node-a']['status'] );
		$this->assertArrayHasKey( 'maxcpu', $by['node-a'] );
		$this->assertArrayHasKey( 'cpu', $by['node-a'] );

		$calls = count( $this->fake->calls() );
		$this->assertSame( 4, $calls, 'GET /nodes, one /cluster/resources and one storage-status call per node; no per-VM call.' );

		$node = Placement::node( 'lab', Clusters::get( 'lab' ) );

		$this->assertSame( 'node-b', $node, 'node-b has the most uncommitted memory.' );
		$this->assertCount( $calls, $this->fake->calls(), 'A page request reads the cache and calls nothing.' );
	}

	public function test_capacity_is_not_cached_when_the_cluster_cannot_be_read(): void {
		$this->fake->fail_next( 'GET', '#/nodes$#', array( 'status' => 500 ) );

		ProxmoxJobs::capacity();

		$this->assertFalse( get_transient( Placement::CAPACITY_PREFIX . 'lab' ) );
	}

	private function stock_of( int $product_id ): string {
		wp_cache_flush();

		return wc_get_product( $product_id )->get_stock_status( 'edit' );
	}

	public function test_the_hourly_capacity_run_writes_the_stock_status(): void {
		IpPool::add( '192.0.2.10' );
		$small = $this->service_product( 'proxmox', array( 'cluster' => 'lab', 'template' => 9000, 'cores' => 2, 'memory_mb' => 4096, 'disk_gb' => 30 ) );
		$big   = $this->service_product( 'proxmox', array( 'cluster' => 'lab', 'template' => 9000, 'cores' => 2, 'memory_mb' => 100 * 1024, 'disk_gb' => 30 ) );
		$http  = $this->service_product( 'fake' );

		$this->assertFalse( get_transient( Placement::CAPACITY_PREFIX . 'lab' ), 'No cache yet.' );

		$seen = array();
		add_action(
			'woocommerce_product_set_stock_status',
			static function ( $id, $status ) use ( &$seen ) {
				$seen[ $id ] = $status;
			},
			10,
			2
		);

		ProxmoxJobs::capacity();

		$this->assertSame( 'instock', $this->stock_of( $small->get_id() ) );
		$this->assertSame( 'outofstock', $this->stock_of( $big->get_id() ), 'No node can hold 100 GB.' );
		$this->assertSame( 'instock', $this->stock_of( $http->get_id() ), 'Another driver is not touched.' );
		$this->assertSame( array( $big->get_id() => 'outofstock' ), $seen, 'One change, one hook.' );

		$seen = array();
		ProxmoxJobs::capacity();
		$this->assertSame( array(), $seen, 'The next run changes nothing and fires nothing.' );
	}

	public function test_the_on_demand_capacity_run_writes_the_stock_status(): void {
		IpPool::add( '192.0.2.10' );
		$product = $this->service_product( 'proxmox', array( 'cluster' => 'lab', 'template' => 9000, 'cores' => 2, 'memory_mb' => 4096, 'disk_gb' => 30 ) );
		ProxmoxJobs::capacity();
		$this->assertSame( 'instock', $this->stock_of( $product->get_id() ) );

		// The cache is flushed (an object cache flush) while the product is closed by this plugin.
		set_transient( Placement::CAPACITY_PREFIX . 'lab', array( array( 'node' => 'node-a', 'status' => 'online', 'maxmem' => 8 * self::GB, 'mem' => 8 * self::GB ) ), HOUR_IN_SECONDS );
		Stock::sync( 'lab' );
		$this->assertSame( 'outofstock', $this->stock_of( $product->get_id() ), 'Closed by the plugin (marker set).' );
		delete_transient( Placement::CAPACITY_PREFIX . 'lab' );

		do_action( ProxmoxJobs::CAPACITY_NOW, 'lab' );

		$this->assertIsArray( get_transient( Placement::CAPACITY_PREFIX . 'lab' ) );
		$this->assertSame( 'instock', $this->stock_of( $product->get_id() ) );
	}

	public function test_a_capacity_run_that_cannot_read_the_cluster_leaves_the_status_alone(): void {
		IpPool::add( '192.0.2.10' );
		$product = $this->service_product( 'proxmox', array( 'cluster' => 'lab', 'template' => 9000, 'cores' => 2, 'memory_mb' => 4096, 'disk_gb' => 30 ) );
		$this->fake->fail_next( 'GET', '#/nodes$#', array( 'status' => 500 ) );

		ProxmoxJobs::capacity();

		$this->assertSame( 'instock', $this->stock_of( $product->get_id() ), 'No cache was written, so no status is.' );
	}

	public function test_a_plain_fake_cluster_is_skipped(): void {
		$stored = get_option( Clusters::OPTION );
		unset( $stored['lab']['persist'] );
		update_option( Clusters::OPTION, $stored, false );

		ProxmoxJobs::capacity();
		ProxmoxJobs::power();

		$this->assertCount( 0, $this->fake->calls() );
	}

	public function test_power_state_uses_one_call_per_cluster_and_tracks_unexpected_stop(): void {
		$a = $this->vm( 5000, 'node-a', 'running', 4096, array( 'cpu' => 0.25 ) );
		$b = $this->vm( 5001, 'node-b', 'stopped' );
		$c = $this->vm( 5002, 'node-a', 'running', 4096, array( 'lock' => 'clone' ) );
		Operations::remember_power( $c->id(), 'stopped' );

		$before = array(
			$a->id() => $this->meta( $a ),
			$b->id() => $this->meta( $b ),
		);

		ProxmoxJobs::power();

		$this->assertSame( 1, $this->paths( 'GET', '#^/cluster/resources$#' ) );
		$this->assertCount( 1, $this->fake->calls(), 'One call for all the VMs.' );

		$status = Operations::cached_status( $a->id() );
		$this->assertSame( 'running', $status['power'] );
		$this->assertEqualsWithDelta( 0.25, $status['cpu'], 0.0001, 'The cpu stays PVE\'s fraction.' );
		$this->assertSame( 'stopped', Operations::cached_status( $b->id() )['power'] );
		$this->assertSame( 'stopped', Operations::cached_status( $c->id() )['power'], 'A VM with a lock is left to its operation.' );

		$this->assertSame( $before[ $a->id() ], $this->meta( Resource::find( $a->id() ) ), 'A running VM needs no meta write.' );
		$after_stop = $this->meta( Resource::find( $b->id() ) );
		$this->assertGreaterThan( 0, $after_stop['monitor_down_since'] ?? 0 );
		unset( $after_stop['monitor_down_since'] );
		$this->assertSame( $before[ $b->id() ], $after_stop, 'Only the monitoring start time was stored.' );

		$this->assertSame( 'ready', Resource::find( $b->id() )->get( 'state' ), 'A VM the customer stopped stays ready.' );
		$this->assertSame( 'ready', Resource::find( $b->id() )->get( 'desired' ) );
	}

	/**
	 * The resource's stored meta.
	 *
	 * @param Resource $r Resource.
	 * @return mixed
	 */
	private function meta( Resource $r ) {
		$row = \BeltoftLicenses\Licenses::activation( $r->id() );

		return $row['meta'] ?? null;
	}

	/**
	 * Runs the power job, then the abuse job and its batches; returns the resource.
	 */
	private function abuse_run(): void {
		ProxmoxJobs::power();
		ProxmoxJobs::abuse();
	}

	public function test_abuse_report_mode_never_pauses(): void {
		$r = $this->vm( 5000 );
		$this->fake->set_rrd( 5000, $this->samples( fn() => 0.97 ) );

		$this->abuse_run();

		$fresh = Resource::find( $r->id() );
		$this->assertSame( 'ready', $fresh->get( 'desired' ) );
		$this->assertSame( 'ready', $fresh->get( 'state' ) );
		$this->assertCount( 1, $this->mails );
		$this->assertStringStartsWith( '[', $this->mails[0]['subject'] );
		$this->assertStringContainsString( '] Abuse suspected: ', $this->mails[0]['subject'], 'Its own subject, not the service-failed one.' );
		$this->assertStringNotContainsString( 'Service failed', $this->mails[0]['subject'] );
		$this->assertStringNotContainsString( (string) $r->license()['license_key'], $this->mails[0]['subject'], 'The key is masked.' );
		$this->assertStringContainsString( 'Measured: CPU at or above 90%', $this->mails[0]['message'] );
		$this->assertStringContainsString( 'Mode: report only', $this->mails[0]['message'] );
		$this->assertStringContainsString( gmdate( 'Y-m-d' ), $this->mails[0]['message'], 'The text carries the date.' );

		$this->abuse_run();
		$this->assertCount( 1, $this->mails, 'The same day is not mailed twice.' );
		$this->assertSame( 2, $this->paths( 'GET', '#rrddata$#' ) );
	}

	public function test_abuse_pause_mode_desires_paused(): void {
		$this->settings( array( 'abuse_mode' => 'pause' ) );
		$r = $this->vm( 5000 );
		$this->fake->set_rrd( 5000, $this->samples( fn() => 0.97 ) );

		$this->abuse_run();

		$fresh = Resource::find( $r->id() );
		$this->assertSame( 'paused', $fresh->get( 'desired' ) );
		$this->assertNotEmpty( Jobs::pending( $r->id() ), 'A reconcile job was queued.' );
	}

	public function test_abuse_pause_mode_only_reports_protected_and_linked_resources(): void {
		$this->settings( array( 'abuse_mode' => 'pause' ) );
		$protected = $this->vm( 5000 );
		$linked    = $this->vm( 5001 );
		$normal    = $this->vm( 5002 );
		$this->assertTrue( $protected->set( array( 'protected' => true ) ) );
		$this->assertTrue( $linked->set( array( 'linked' => true ) ) );

		foreach ( array( 5000, 5001, 5002 ) as $vmid ) {
			$this->fake->set_rrd( $vmid, $this->samples( fn() => 0.97 ) );
		}

		$this->abuse_run();

		$this->assertSame( 'ready', Resource::find( $protected->id() )->get( 'desired' ), 'Protected: reported only.' );
		$this->assertSame( 'ready', Resource::find( $linked->id() )->get( 'desired' ), 'Linked: reported only.' );
		$this->assertSame( 'paused', Resource::find( $normal->id() )->get( 'desired' ) );
		$this->assertCount( 3, $this->mails, 'All three are reported.' );

		$bodies = array_column( $this->mails, 'message' );
		$this->assertSame( 1, count( array_filter( $bodies, static fn( $m ) => false !== strpos( $m, 'Mode: pause' ) ) ) );
		$this->assertSame( 2, count( array_filter( $bodies, static fn( $m ) => false !== strpos( $m, 'report only' ) ) ) );
	}

	public function test_abuse_off_does_nothing(): void {
		$this->settings( array( 'abuse_mode' => 'off' ) );
		$this->vm( 5000 );
		$this->fake->set_rrd( 5000, $this->samples( fn() => 0.97 ) );

		$this->abuse_run();

		$this->assertSame( 0, $this->paths( 'GET', '#rrddata$#' ) );
		$this->assertCount( 0, $this->mails );
	}

	public function test_abuse_skips_samples_without_cpu_or_time(): void {
		$r = $this->vm( 5000 );
		$this->fake->set_rrd( 5000, array( array( 'time' => 1700000000 ), array( 'cpu' => 0.99 ), array( 'cpu' => 'x', 'time' => 1 ) ) );

		$this->abuse_run();
		$this->abuse_run();

		$this->assertCount( 0, $this->mails, 'Never an alert.' );
		$this->assertSame( 'ready', Resource::find( $r->id() )->get( 'desired' ) );

		$events = \BeltoftLicenses\Events::for_license( (int) $r->license()['id'] );
		$texts  = wp_list_pluck( (array) $events, 'message' );
		$this->assertCount( 1, array_filter( $texts, fn( $t ) => false !== strpos( (string) $t, 'Abuse check not possible' ) ), 'Said once.' );
	}

	public function test_a_gap_ends_a_run_and_an_unfinished_newest_sample_does_not(): void {
		// 30 low minutes, a gap, 38 high minutes, and an unfinished last sample: the run is under an hour.
		$rows = $this->samples( fn( $m ) => $m < 30 ? 0.1 : ( 30 === $m ? null : ( 69 === $m ? null : 0.95 ) ) );
		$out  = ProxmoxJobs::analyse( $rows, 90.0 );

		$this->assertSame( 37 * 60, $out['longest'], 'Minutes 31 to 68.' );
		$this->assertNotNull( $out['trailing'], 'The newest unfinished sample is ignored: the run reaches the end.' );

		$low = ProxmoxJobs::analyse( $this->samples( fn() => 0.2 ), 90.0 );
		$this->assertNull( $low['trailing'] );
		$this->assertNull( ProxmoxJobs::analyse( array( array( 'cpu' => 0.9 ) ), 90.0 ) );
	}

	public function test_abuse_high_since_spans_several_hourly_runs(): void {
		$this->settings( array( 'abuse_hours' => 3 ) );
		$r   = $this->vm( 5000 );
		$end = 1700000000;
		$run = function ( int $at, callable $cpu ) use ( $r ) {
			$this->fake->set_rrd( 5000, $this->samples( $cpu, $at ) );
			ProxmoxJobs::power();
			ProxmoxJobs::abuse();
		};

		$run( $end, fn() => 0.97 );
		$run( $end + 3600, fn() => 0.97 );
		$this->assertCount( 0, $this->mails, 'About two hours of readings: under three.' );

		$run( $end + 7200, fn() => 0.97 );
		$this->assertCount( 1, $this->mails, 'Three hours of readings that join up.' );

		// A reading below the threshold resets the value.
		$run( $end + 10800, fn( $m ) => $m < 69 ? 0.97 : 0.1 );
		$this->assertFalse( get_transient( ProxmoxJobs::SINCE_PREFIX . $r->id() ) );
		$run( $end + 14400, fn() => 0.97 );
		$this->assertCount( 1, $this->mails, 'It starts again from zero.' );

		// A missed hour breaks the continuity.
		$run( $end + 7 * 3600, fn() => 0.97 );
		$since = get_transient( ProxmoxJobs::SINCE_PREFIX . $r->id() );
		$this->assertSame( $end + 7 * 3600 - 69 * 60, $since['since'] );
	}

	public function test_abuse_batches_never_loop(): void {
		$ids = array();

		for ( $i = 0; $i < 60; $i++ ) {
			$ids[] = $this->vm( 5000 + $i )->id();
		}

		ProxmoxJobs::power();
		ProxmoxJobs::abuse();

		$this->assertSame( ProxmoxJobs::BATCH_SIZE, $this->paths( 'GET', '#rrddata$#' ), 'The first batch ran at once.' );

		$pending = as_get_scheduled_actions( array( 'hook' => ProxmoxJobs::ABUSE_BATCH, 'group' => Jobs::GROUP, 'status' => \ActionScheduler_Store::STATUS_PENDING ) );
		$this->assertCount( 1, $pending );
		$this->assertGreaterThan( time() + 30, reset( $pending )->get_schedule()->get_date()->getTimestamp(), 'The next batch is a minute later.' );

		for ( $round = 0; $round < 10; $round++ ) {
			as_unschedule_all_actions( ProxmoxJobs::ABUSE_BATCH, array(), Jobs::GROUP );
			ProxmoxJobs::abuse_batch();
		}

		$this->assertSame( 60, $this->paths( 'GET', '#rrddata$#' ), 'Every VM is read exactly once, however often the batch hook runs.' );
		$this->assertFalse( get_transient( ProxmoxJobs::QUEUE ) );
		$this->assertFalse( as_has_scheduled_action( ProxmoxJobs::ABUSE_BATCH, array(), Jobs::GROUP ) );
	}

	public function test_abuse_run_is_capped(): void {
		$list = array();

		for ( $i = 0; $i < 530; $i++ ) {
			$list[] = array( 'resource' => 999999, 'cluster' => 'lab', 'vmid' => $i, 'node' => 'node-a' );
		}

		set_transient( ProxmoxJobs::RUNNING_PREFIX . 'lab', $list, HOUR_IN_SECONDS );
		ProxmoxJobs::abuse();

		$queue = get_transient( ProxmoxJobs::QUEUE );
		$this->assertSame( ProxmoxJobs::BATCH_SIZE, $queue['cursor'] );
		$this->assertCount( ProxmoxJobs::MAX_VMS, $queue['items'] );
	}

	public function test_jobs_do_nothing_while_the_kill_switch_is_on(): void {
		$r = $this->vm( 5000 );
		$this->fake->set_rrd( 5000, $this->samples( fn() => 0.97 ) );
		$this->settings( array( 'pause_all' => 1, 'abuse_mode' => 'pause' ) );

		ProxmoxJobs::capacity();
		ProxmoxJobs::power();
		ProxmoxJobs::abuse();
		ProxmoxJobs::abuse_batch();

		$this->assertCount( 0, $this->fake->calls() );
		$this->assertFalse( get_transient( Placement::CAPACITY_PREFIX . 'lab' ) );
		$this->assertSame( 'ready', Resource::find( $r->id() )->get( 'desired' ) );
	}

	public function test_jobs_are_scheduled_once_and_unscheduled_on_deactivation(): void {
		ProxmoxJobs::schedule();
		ProxmoxJobs::schedule();

		foreach ( array(
			ProxmoxJobs::CAPACITY => '0 * * * *',
			ProxmoxJobs::POWER    => '*/15 * * * *',
			ProxmoxJobs::ABUSE    => '20 * * * *',
		) as $hook => $cron ) {
			$actions = as_get_scheduled_actions( array( 'hook' => $hook, 'group' => Jobs::GROUP, 'status' => \ActionScheduler_Store::STATUS_PENDING ) );
			$this->assertCount( 1, $actions, $hook );
			$this->assertSame( $cron, reset( $actions )->get_schedule()->get_recurrence() );
		}

		$this->assertNotFalse( has_action( ProxmoxJobs::POWER, array( ProxmoxJobs::class, 'power' ) ) );
		$this->assertNotFalse( has_action( 'action_scheduler_init', array( ProxmoxJobs::class, 'schedule' ) ) );
		$this->assertNotFalse( has_action( 'deactivate_beltoft-services/beltoft-services.php' ) || has_action( 'deactivate_' . plugin_basename( BELTOFT_SERVICES_FILE ) ) );

		ProxmoxJobs::unschedule();

		foreach ( array( ProxmoxJobs::CAPACITY, ProxmoxJobs::POWER, ProxmoxJobs::ABUSE ) as $hook ) {
			$this->assertFalse( as_has_scheduled_action( $hook, array(), Jobs::GROUP ), $hook );
		}
	}

	public function test_deactivation_hook_unschedules_the_jobs(): void {
		$hooks = $GLOBALS['wp_filter'][ 'deactivate_' . plugin_basename( BELTOFT_SERVICES_FILE ) ] ?? null;
		$found = false;

		foreach ( $hooks ? $hooks->callbacks : array() as $callbacks ) {
			foreach ( $callbacks as $cb ) {
				$found = $found || ( is_array( $cb['function'] ) && ProxmoxJobs::class === $cb['function'][0] && 'unschedule' === $cb['function'][1] );
			}
		}

		$this->assertTrue( $found );
	}

	public function test_expired_and_unread_passwords_are_purged_by_the_sweep(): void {
		$expired = $this->vm( 5000 );
		$fresh   = $this->vm( 5001 );
		$resetting = $this->vm( 5002 );

		$old = time() - 60;
		$new = time() + Access::REVEAL_DAYS * DAY_IN_SECONDS;

		$this->assertTrue( $expired->set( array( Access::META_REVEAL => 'ciphertext-1', Access::META_UNTIL => $old ) ) );
		$this->assertTrue( $fresh->set( array( Access::META_REVEAL => 'ciphertext-2', Access::META_UNTIL => $new ) ) );
		$this->assertTrue( $resetting->set( array( Access::META_REVEAL => 'ciphertext-3', Access::META_UNTIL => $old, Access::META_RESET => 1 ) ) );

		ProxmoxJobs::purge_passwords();

		$e = Resource::find( $expired->id() );
		$this->assertSame( '', (string) $e->get( Access::META_REVEAL ), 'Past its window: wiped.' );
		$this->assertSame( 0, (int) $e->get( Access::META_UNTIL ) );
		$this->assertSame( 'ciphertext-2', Resource::find( $fresh->id() )->get( Access::META_REVEAL ) );
		$this->assertSame( 'ciphertext-3', Resource::find( $resetting->id() )->get( Access::META_REVEAL ), 'A reset not yet on the VM is left alone.' );

		// Once a day.
		$this->assertTrue( $fresh->set( array( Access::META_UNTIL => $old ) ) );
		ProxmoxJobs::purge_passwords();
		$this->assertSame( 'ciphertext-2', Resource::find( $fresh->id() )->get( Access::META_REVEAL ), 'The next run is tomorrow.' );

		delete_transient( ProxmoxJobs::PURGE_RAN );
		ProxmoxJobs::purge_passwords();
		$this->assertSame( '', (string) Resource::find( $fresh->id() )->get( Access::META_REVEAL ) );
	}

	public function test_the_password_purge_and_a_breach_hold_do_not_interfere(): void {
		$held = $this->vm( 5000 );
		$this->assertTrue(
			$held->set(
				array(
					'state'               => 'paused',
					'desired'             => 'paused',
					'paused_at'           => time() - 100,
					'breach_at'           => time() - 100,
					'breach_reason'       => 'Spam',
					Access::META_REVEAL   => 'ciphertext-held',
					Access::META_UNTIL    => time() - 60,
				)
			)
		);

		ProxmoxJobs::purge_passwords();

		$after = Resource::find( $held->id() );
		$this->assertSame( '', (string) $after->get( Access::META_REVEAL ), 'The expired password is purged under a hold too.' );
		$this->assertTrue( $after->on_hold() );
		$this->assertSame( 'paused', $after->get( 'desired' ) );
		$this->assertSame( 'paused', $after->get( 'state' ) );
		$this->assertSame( 'Spam', $after->get( 'breach_reason' ) );

		// A held service whose days are over is deleted, with no replacement (the deletion itself is tested elsewhere).
		$this->settings( array( 'deletion_mode' => 'on' ) );
		$this->assertTrue( Resource::find( $held->id() )->set( array( 'breach_at' => time() - 20 * DAY_IN_SECONDS, Access::META_REVEAL => 'ciphertext-again', Access::META_UNTIL => time() + DAY_IN_SECONDS ) ) );
		\BeltoftServices\Retention::run();
		$this->assertSame( 'deleted', Resource::find( $held->id() )->get( 'desired' ) );
		$this->assertFalse( Resource::find( $held->id() )->get( 'replace_after_delete' ) );
	}

	public function test_the_sweep_runs_the_purge(): void {
		$r = $this->vm( 5000 );
		$this->assertTrue( $r->set( array( Access::META_REVEAL => 'ciphertext', Access::META_UNTIL => time() - 5 ) ) );
		$this->assertNotFalse( has_action( Jobs::SWEEP, array( ProxmoxJobs::class, 'purge_passwords' ) ) );

		do_action( Jobs::SWEEP );

		$this->assertSame( '', (string) Resource::find( $r->id() )->get( Access::META_REVEAL ) );
	}

	public function test_host_reserve_is_bounded(): void {
		$out = apply_filters( 'sanitize_option_' . \BeltoftServices\Settings::OPTION, array( 'host_reserve_gb' => 99999 ) );
		$this->assertSame( 1024, $out['host_reserve_gb'] );

		$out = apply_filters( 'sanitize_option_' . \BeltoftServices\Settings::OPTION, array( 'host_reserve_gb' => -5 ) );
		$this->assertSame( 0, $out['host_reserve_gb'] );
	}

	public function test_placement_never_calls_proxmox_and_waits_without_a_cache(): void {
		delete_transient( Placement::CAPACITY_PREFIX . 'lab' );

		$this->assertNull( Placement::node( 'lab', Clusters::get( 'lab' ) ), 'Two nodes and no cache: no answer.' );
		$this->assertCount( 0, $this->fake->calls() );
	}

	public function test_capacity_counts_recorded_vms_that_are_not_listed_yet(): void {
		$placed   = $this->vm( 5000, 'node-a', 'running', 8192 );
		$unplaced = $this->vm( 5001, 'node-a', 'running', 4096 );

		$this->fake->request( 'POST', '/nodes/node-a/qemu/5000/status/stop' );
		$this->fake->request( 'DELETE', '/nodes/node-a/qemu/5000' ); // Not in the listing (a clone not visible yet).
		$this->assertNull( $this->fake->vm( 5000 ) );
		$this->fake->request( 'POST', '/nodes/node-a/qemu/5001/status/stop' );
		$this->fake->request( 'DELETE', '/nodes/node-a/qemu/5001' );
		$this->assertNull( $this->fake->vm( 5001 ) );
		$this->assertTrue( VmRecords::move( $placed->id(), 'node-a' ) );

		ProxmoxJobs::capacity();

		$by = array_column( get_transient( Placement::CAPACITY_PREFIX . 'lab' ), null, 'node' );
		$this->assertSame( 8192 * 1048576 + 4 * self::GB, $by['node-a']['mem'], 'The placed record counts at its plan memory; the unplaced one is not on a node yet.' );
		$this->assertSame( 'ready', $unplaced->get( 'state' ) );
	}

	public function test_saving_a_cluster_queues_a_capacity_run(): void {
		$this->fake->add_vm( array( 'vmid' => 9000, 'node' => 'node-a', 'template' => 1 ) );
		$this->cluster();

		$this->assertTrue( as_has_scheduled_action( ProxmoxJobs::CAPACITY_NOW, array( 'lab' ), Jobs::GROUP ), 'Queued, not run inline.' );
		$this->assertFalse( get_transient( Placement::CAPACITY_PREFIX . 'lab' ) );
		$this->assertCount( 0, $this->fake->calls() );

		$this->cluster();
		$this->assertCount( 1, as_get_scheduled_actions( array( 'hook' => ProxmoxJobs::CAPACITY_NOW, 'group' => Jobs::GROUP, 'status' => \ActionScheduler_Store::STATUS_PENDING ) ), 'One waiting run is enough.' );

		$this->run_jobs();

		$this->assertIsArray( get_transient( Placement::CAPACITY_PREFIX . 'lab' ) );
	}

	public function test_activating_the_plugin_queues_a_capacity_run_for_each_saved_cluster(): void {
		$this->assertNotFalse( has_action( 'activate_' . plugin_basename( BELTOFT_SERVICES_FILE ), array( ProxmoxJobs::class, 'activate' ) ) );

		$this->fake->add_vm( array( 'vmid' => 9000, 'node' => 'node-a', 'template' => 1 ) );
		$this->cluster();
		as_unschedule_all_actions( ProxmoxJobs::CAPACITY_NOW, array(), Jobs::GROUP );
		delete_transient( Placement::CAPACITY_PREFIX . 'lab' );

		ProxmoxJobs::activate();
		ProxmoxJobs::activate();

		$this->assertCount( 1, as_get_scheduled_actions( array( 'hook' => ProxmoxJobs::CAPACITY_NOW, 'args' => array( 'lab' ), 'group' => Jobs::GROUP, 'status' => \ActionScheduler_Store::STATUS_PENDING ) ), 'One run, queued once.' );
		$this->assertCount( 0, $this->fake->calls(), 'Queued, not run inline.' );

		$this->run_jobs();
		$this->assertIsArray( get_transient( Placement::CAPACITY_PREFIX . 'lab' ) );
	}

	public function test_capacity_cache_is_not_rewritten_when_resources_cannot_be_read(): void {
		set_transient( Placement::CAPACITY_PREFIX . 'lab', array( array( 'node' => 'old', 'status' => 'online', 'maxmem' => 1, 'mem' => 0 ) ), HOUR_IN_SECONDS );
		$this->fake->fail_next( 'GET', '#/cluster/resources$#', array( 'status' => 500 ) );

		ProxmoxJobs::capacity();

		$this->assertSame( 'old', get_transient( Placement::CAPACITY_PREFIX . 'lab' )[0]['node'] );
	}

	public function test_abuse_skips_vms_whose_cached_power_is_stopped(): void {
		$r = $this->vm( 5000 );
		$this->fake->set_rrd( 5000, $this->samples( fn() => 0.97 ) );
		set_transient( ProxmoxJobs::RUNNING_PREFIX . 'lab', array( array( 'resource' => $r->id(), 'cluster' => 'lab', 'vmid' => 5000, 'node' => 'node-a' ) ), HOUR_IN_SECONDS );
		Operations::remember_power( $r->id(), 'stopped' );

		ProxmoxJobs::abuse();

		$this->assertSame( 0, $this->paths( 'GET', '#rrddata$#' ) );
	}

	public function test_pause_mode_skips_a_resource_whose_desired_is_not_ready(): void {
		$this->settings( array( 'abuse_mode' => 'pause' ) );
		$r = $this->vm( 5000 );
		$this->fake->set_rrd( 5000, $this->samples( fn() => 0.97 ) );
		$this->assertTrue( $r->set( array( 'desired' => 'paused' ) ) );

		$this->abuse_run();

		$this->assertSame( 'paused', Resource::find( $r->id() )->get( 'desired' ) );
		$this->assertCount( 0, $this->mails );
		$this->assertSame( 0, $this->paths( 'GET', '#rrddata$#' ) );
	}

	public function test_the_cap_is_shared_round_robin_between_clusters(): void {
		$stored         = get_option( Clusters::OPTION );
		$stored['lab2'] = $stored['lab'];
		update_option( Clusters::OPTION, $stored, false );

		foreach ( array( 'lab', 'lab2' ) as $id ) {
			$list = array();

			for ( $i = 0; $i < 400; $i++ ) {
				$list[] = array( 'resource' => 999999, 'cluster' => $id, 'vmid' => $i, 'node' => 'node-a' );
			}

			set_transient( ProxmoxJobs::RUNNING_PREFIX . $id, $list, HOUR_IN_SECONDS );
		}

		ProxmoxJobs::abuse();

		$queue    = get_transient( ProxmoxJobs::QUEUE );
		$clusters = array_count_values( array_column( $queue['items'], 'cluster' ) );
		$this->assertSame( 250, $clusters['lab'] );
		$this->assertSame( 250, $clusters['lab2'] );
	}

	public function test_a_failed_clone_and_a_deleted_resource_stop_counting_at_the_next_run(): void {
		$failed  = $this->vm( 5000, 'node-a', 'running', 8192 );
		$deleted = $this->vm( 5001, 'node-a', 'running', 4096 );

		foreach ( array( 5000, 5001 ) as $vmid ) {
			$this->fake->request( 'POST', '/nodes/node-a/qemu/' . $vmid . '/status/stop' );
			$this->fake->request( 'DELETE', '/nodes/node-a/qemu/' . $vmid );
		}

		VmRecords::move( $failed->id(), 'node-a' );
		VmRecords::move( $deleted->id(), 'node-a' );

		ProxmoxJobs::capacity();
		$by = array_column( get_transient( Placement::CAPACITY_PREFIX . 'lab' ), null, 'node' );
		$this->assertSame( ( 8192 + 4096 ) * 1048576 + 4 * self::GB, $by['node-a']['mem'] );

		VmRecords::remove( $failed->id() ); // The failed clone's record is removed.
		$deleted->close(); // The resource is deleted (its row stays).

		ProxmoxJobs::capacity();
		$by = array_column( get_transient( Placement::CAPACITY_PREFIX . 'lab' ), null, 'node' );
		$this->assertSame( 4 * self::GB, $by['node-a']['mem'], 'Back to the reserve alone.' );
	}
}

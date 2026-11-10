<?php
/**
 * The alerts that replace the capacity stop: over the committed budget in steps, and the nightly cross-check of real free
 * memory and disk. Fake client and caches only.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Proxmox\Pve;

use BeltoftServices\Proxmox\Pve\Capacity;
use BeltoftServices\Proxmox\Pve\CapacityCheck;
use BeltoftServices\Proxmox\Pve\Clusters;
use BeltoftServices\Proxmox\Pve\Placement;
use BeltoftServices\Tests\TestCase;

/**
 * Capacity::overage_alerts() and CapacityCheck::run().
 */
class CapacityAlertsTest extends TestCase {

	private const GB = 1073741824;

	/**
	 * Mails sent.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private $mails = array();

	public function set_up(): void {
		parent::set_up();

		$key = base64_encode( random_bytes( 32 ) );
		add_filter( 'beltoft_services_key', fn() => $key );
		$this->mails = array();
		add_filter(
			'pre_wp_mail',
			function ( $pre, $args ) {
				$this->mails[] = $args;

				return true;
			},
			10,
			2
		);
		$this->settings( array( 'admin_email' => 'christian.beltoft@gmail.com', 'host_reserve_gb' => 4 ) );

		$this->assertTrue(
			Clusters::save(
				'lab',
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
					'gateway'           => '192.0.2.1',
					'nameservers'       => '192.0.2.53',
					'prefix'            => '24',
					'vmid_min'          => '5000',
					'vmid_max'          => '5099',
					'templates'         => '9000 = Debian 12',
				)
			)
		);
	}

	public function tear_down(): void {
		remove_all_filters( 'beltoft_services_key' );
		remove_all_filters( 'pre_wp_mail' );
		delete_option( Clusters::OPTION );
		delete_transient( Placement::CAPACITY_PREFIX . 'lab' );

		parent::tear_down();
	}

	/**
	 * Caches one node of 64 GB with this much memory committed (the host reserve of 4 GB is added, as the capacity job does).
	 *
	 * @param float $committed_gb Committed to servers, in GB.
	 * @param float $used_gb      Really in use on the node, in GB.
	 */
	private function node( float $committed_gb, float $used_gb = 10.0 ): void {
		set_transient(
			Placement::CAPACITY_PREFIX . 'lab',
			array(
				array(
					'node'     => 'node-a',
					'status'   => 'online',
					'maxmem'   => 64 * self::GB,
					'mem'      => (int) ( ( $committed_gb + 4 ) * self::GB ),
					'mem_used' => (int) ( $used_gb * self::GB ),
				),
			),
			HOUR_IN_SECONDS
		);
	}

	/**
	 * Mails about the budget.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function over_mails(): array {
		return array_values( array_filter( $this->mails, static fn( $m ) => false !== strpos( (string) $m['subject'], 'over its committed memory budget' ) ) );
	}

	public function test_the_admin_is_told_at_100_percent_and_at_each_further_10_percent_once_and_it_resets(): void {
		// Budget: 64 GB minus the 4 GB reserve = 60 GB.
		$this->node( 50 );
		$this->assertSame( array(), Capacity::overage_alerts( 'lab' ), 'Under the budget: quiet.' );

		$this->node( 61 ); // 101 percent.
		$this->assertSame( array( 'node-a' ), Capacity::overage_alerts( 'lab' ) );
		$this->assertCount( 1, $this->over_mails() );
		$this->assertSame( array(), Capacity::overage_alerts( 'lab' ), 'Once per step.' );

		$message = (string) $this->over_mails()[0]['message'];
		$this->assertStringContainsString( '61.0 GB of memory committed', $message, 'What is committed.' );
		$this->assertStringContainsString( 'a budget of 60.0 GB', $message, 'What the budget is.' );
		$this->assertStringContainsString( 'Memory kept free on each node, in GB', $message, 'Which setting defines it.' );
		$this->assertStringNotContainsString( 'cannot be sold', $message );
		$this->assertStringNotContainsString( "\u{2014}", $message );
		$this->assertStringNotContainsString( "\u{b7}", $message );

		$this->node( 67 ); // 111 percent: step 110.
		$this->assertSame( array( 'node-a' ), Capacity::overage_alerts( 'lab' ) );
		$this->assertCount( 2, $this->over_mails() );

		$this->node( 69 ); // 115 percent: same step.
		$this->assertSame( array(), Capacity::overage_alerts( 'lab' ) );

		$this->node( 73 ); // 121 percent: step 120.
		$this->assertSame( array( 'node-a' ), Capacity::overage_alerts( 'lab' ) );
		$this->assertCount( 3, $this->over_mails() );

		$this->node( 68 ); // Falls back to 113 percent: the 120 step is forgotten, no mail.
		$this->assertSame( array(), Capacity::overage_alerts( 'lab' ) );
		$this->node( 73 );
		$this->assertSame( array( 'node-a' ), Capacity::overage_alerts( 'lab' ), 'Crossing the 120 step again mails again.' );
		$this->assertCount( 4, $this->over_mails() );

		$this->node( 40 ); // Back under the budget: everything is forgotten.
		$this->assertSame( array(), Capacity::overage_alerts( 'lab' ) );
		$this->node( 61 );
		$this->assertSame( array( 'node-a' ), Capacity::overage_alerts( 'lab' ), 'Passing 100 percent again mails again.' );
		$this->assertCount( 5, $this->over_mails() );
	}

	public function test_a_placement_that_passes_the_budget_mails_once(): void {
		$this->node( 58 ); // 2 GB of room.
		$this->assertTrue( Capacity::place( 'lab', 'node-a', 4 * self::GB, static fn() => true ) );
		$this->assertCount( 1, $this->over_mails(), '62 of 60 GB.' );
		$this->assertTrue( Capacity::place( 'lab', 'node-a', 1 * self::GB, static fn() => true ) );
		$this->assertCount( 1, $this->over_mails(), 'Still the same step.' );
	}

	public function test_the_nightly_cross_check_mails_on_low_real_free_memory_and_is_quiet_otherwise(): void {
		$this->node( 20, 30 ); // 34 GB really free of 64: fine.
		$this->assertSame( array(), CapacityCheck::run() );
		$this->assertCount( 0, $this->mails );

		$this->node( 20, 62 ); // 2 GB free of 64: 3 percent.
		$this->assertSame( array( 'lab:node-a:memory' ), CapacityCheck::run() );
		$this->assertCount( 1, $this->mails );
		$this->assertSame( 'christian.beltoft@gmail.com', $this->mails[0]['to'] );
		$this->assertStringContainsString( 'real free memory is low', $this->mails[0]['subject'] );
		$this->assertStringContainsString( 'The plugin counts 20.0 GB committed against a budget of 60.0 GB', $this->mails[0]['message'] );
		$this->assertStringContainsString( 'Alert when real free memory is under', $this->mails[0]['message'], 'The setting is named.' );

		$this->assertSame( array(), CapacityCheck::run(), 'Once a day.' );
	}

	public function test_the_threshold_is_a_setting_with_a_filter(): void {
		$this->assertSame( 10.0, CapacityCheck::threshold( 'memory' ) );
		$this->assertSame( 10.0, CapacityCheck::threshold( 'disk' ) );

		$this->settings( array( 'capacity_free_memory_percent' => 20 ) );
		$this->node( 20, 55 ); // 9 GB free: 14 percent.
		$this->assertSame( array( 'lab:node-a:memory' ), CapacityCheck::run(), 'Under the 20 percent set.' );

		add_filter( 'beltoft_services_capacity_free_percent', static fn( $v, $kind ) => 'disk' === $kind ? 50.0 : $v, 10, 2 );
		$this->assertSame( 50.0, CapacityCheck::threshold( 'disk' ) );
	}

	public function test_the_nightly_cross_check_reads_the_real_free_disk_from_the_storage_cache_and_makes_no_call(): void {
		$this->node( 20, 10 );
		$cache = 'beltoft_services_disk_capacity_' . md5( 'lab|node-a|disk-store' );
		set_transient( $cache, array( 'total' => 1000 * self::GB, 'avail' => 500 * self::GB, 'read_at' => time() ), 2 * HOUR_IN_SECONDS );
		$this->assertSame( array(), CapacityCheck::run(), '50 percent free: quiet.' );

		set_transient( $cache, array( 'total' => 1000 * self::GB, 'avail' => 40 * self::GB, 'read_at' => time() ), 2 * HOUR_IN_SECONDS );
		$this->assertSame( array( 'lab:node-a:disk' ), CapacityCheck::run() );
		$this->assertStringContainsString( 'real free disk is low', $this->mails[0]['subject'] );
		$this->assertSame( array(), $this->http_calls(), 'Only the caches are read.' );

		delete_transient( $cache );
		$this->assertSame( array(), CapacityCheck::run(), 'No fresh reading: nothing to compare.' );
	}

	public function test_the_check_is_scheduled_nightly_and_a_cluster_without_a_cache_is_skipped(): void {
		delete_transient( Placement::CAPACITY_PREFIX . 'lab' );
		$this->assertSame( array(), CapacityCheck::run() );
		$this->assertNotFalse( has_action( \BeltoftServices\Proxmox\Vm\ProxmoxJobs::CAPACITY_CHECK, array( CapacityCheck::class, 'run' ) ) );
	}
}

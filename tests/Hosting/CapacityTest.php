<?php
/**
 * Capacity, placement and sold-out hosting plans.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Hosting;

require_once __DIR__ . '/HostingCase.php';

use BeltoftServices\Hosting\HostingCapacity;
use BeltoftServices\Hosting\Plan;
use BeltoftServices\Hosting\Records;
use BeltoftServices\Hosting\Stock;
use BeltoftServices\Resource;

/**
 * HostingCapacity and Stock.
 */
class CapacityTest extends HostingCase {

	/**
	 * A typed plan.
	 *
	 * @param array<string, mixed> $over Overrides.
	 * @return array<string, int|string|bool>
	 */
	private function plan( array $over = array() ): array {
		return Plan::typed( array_merge( array( 'node_group' => 'g1', 'cores' => 2, 'memory_mb' => 3072, 'disk_gb' => 20, 'sites' => 1, 'db_buffer_mb' => 100 ), $over ) );
	}

	public function test_the_cost_of_a_plan_uses_the_overcommit_settings_and_the_database_share(): void {
		$this->settings( array( 'hosting_mem_overcommit' => '1.5', 'hosting_disk_overcommit' => '2' ) );
		$cost = HostingCapacity::cost( $this->plan() );

		$this->assertEqualsWithDelta( 3072 / 1.5 + 100, $cost['memory'], 0.001, 'Memory caps are overcommitted, the database buffer share is not.' );
		$this->assertEqualsWithDelta( ( 20 + 5 ) * 1024 / 2, $cost['disk'], 0.001, 'Root disk plus the dump mount, on thin storage.' );

		$this->settings( array( 'hosting_mem_overcommit' => '1', 'hosting_disk_overcommit' => '1' ) );
		$cost = HostingCapacity::cost( $this->plan() );
		$this->assertEqualsWithDelta( 3172, $cost['memory'], 0.001 );
		$this->assertEqualsWithDelta( 25 * 1024, $cost['disk'], 0.001 );

		$this->assertSame( 1, HostingCapacity::dump_gb( 2 ), 'At least 1 GB.' );
	}

	public function test_usage_counts_the_accounts_on_a_node_and_the_reserve(): void {
		$this->settings( array( 'hosting_mem_overcommit' => '1', 'hosting_disk_overcommit' => '1', 'hosting_reserve_gb' => '2' ) );

		$u = HostingCapacity::usage( 'n1' );
		$this->assertSame( 30 * 1024.0, $u['memory_budget'] );
		$this->assertSame( 1000 * 1024.0, $u['disk_budget'] );
		$this->assertSame( 0, $u['accounts'] );

		$r = $this->resource( array( 'memory_mb' => 2048, 'disk_gb' => 10, 'db_buffer_mb' => 50 ) );
		$this->provision_fully( $r );

		$u = HostingCapacity::usage( 'n1' );
		$this->assertSame( 1, $u['accounts'] );
		$this->assertEqualsWithDelta( 2098, $u['memory_committed'], 0.001 );
		$this->assertEqualsWithDelta( 12 * 1024, $u['disk_committed'], 0.001 );
		$this->assertEqualsWithDelta( 30 * 1024 - 2098, HostingCapacity::free_memory( 'n1' ), 0.001 );

		$this->assertNull( HostingCapacity::usage( 'nope' ) );
		$this->assertSame( 0.0, HostingCapacity::free_memory( 'nope' ) );
	}

	public function test_a_plan_fits_until_the_budget_is_used_up(): void {
		$this->settings( array( 'hosting_mem_overcommit' => '1', 'hosting_disk_overcommit' => '1', 'hosting_reserve_gb' => '28' ) );
		// Budget: 4 GB of memory.
		$this->assertTrue( HostingCapacity::fits( 'n1', $this->plan( array( 'memory_mb' => 3900, 'db_buffer_mb' => 100 ) ) ) );
		$this->assertFalse( HostingCapacity::fits( 'n1', $this->plan( array( 'memory_mb' => 4000, 'db_buffer_mb' => 100 ) ) ), 'The database share counts.' );
		$this->assertFalse( HostingCapacity::fits( 'n1', $this->plan( array( 'disk_gb' => 900 ) ) ), 'Disk 900 plus the dump mount 225 is more than 1000.' );
		$this->assertFalse( HostingCapacity::fits( 'gone', $this->plan() ) );
	}

	public function test_overcommit_makes_room(): void {
		$this->settings( array( 'hosting_mem_overcommit' => '1', 'hosting_reserve_gb' => '28' ) );
		$big = $this->plan( array( 'memory_mb' => 6000 ) );
		$this->assertFalse( HostingCapacity::fits( 'n1', $big ) );

		$this->settings( array( 'hosting_mem_overcommit' => '2' ) );
		$this->assertTrue( HostingCapacity::fits( 'n1', $big ) );
	}

	public function test_placement_picks_the_node_with_the_most_free_memory_and_skips_full_or_disabled_ones(): void {
		$this->save_node( array( 'label' => 'Small', 'mem_gb' => 16 ), 'n2' );
		$this->save_node( array( 'label' => 'Big', 'mem_gb' => 64 ), 'n3' );
		$this->save_node( array( 'label' => 'Off', 'mem_gb' => 128, 'enabled' => '' ), 'n4' );
		$this->save_node( array( 'label' => 'Other group', 'mem_gb' => 128, 'node_group' => 'g2' ), 'n5' );

		$this->assertSame( 'n3', HostingCapacity::place( $this->plan() ) );
		$this->assertTrue( HostingCapacity::available( $this->plan() ) );
		$this->assertNull( HostingCapacity::place( $this->plan( array( 'node_group' => 'none' ) ) ) );

		$this->settings( array( 'hosting_reserve_gb' => '12', 'hosting_mem_overcommit' => '1' ) );
		$this->assertSame( 'n3', HostingCapacity::place( $this->plan( array( 'memory_mb' => 10000 ) ) ), 'Only the big node has room.' );
		$this->assertNull( HostingCapacity::place( $this->plan( array( 'memory_mb' => 90000 ) ) ) );
	}

	public function test_new_accounts_spread_by_free_room_and_a_full_group_waits(): void {
		$this->save_node( array( 'label' => 'Two', 'mem_gb' => 32 ), 'n2' );
		$this->settings( array( 'hosting_mem_overcommit' => '1', 'hosting_reserve_gb' => '20' ) );
		// Each node has 12 GB of budget. Accounts of 5 GB: two fit on each node, the fifth has no room.
		$plan = array( 'memory_mb' => 5000, 'db_buffer_mb' => 0, 'disk_gb' => 10 );

		$nodes = array();
		for ( $i = 0; $i < 4; $i++ ) {
			$r = $this->resource( $plan );
			$this->provision_fully( $r );
			$nodes[] = Records::for_resource( $r->id() )['node'];
		}

		$this->assertSame( array( 'n1', 'n2', 'n1', 'n2' ), $nodes );

		$fifth  = $this->resource( $plan );
		$result = $this->provision( $fifth );

		$this->assertTrue( $result->is_retry() );
		$this->assertStringContainsString( 'has room', $result->error() );
		$this->assertNull( Records::for_resource( $fifth->id() ), 'No node was taken.' );
		$this->assertCount( 4, $this->node->calls_to( '#/clone$#', 'POST' ), 'Nothing was cloned for the fifth.' );

		// A node is added: the waiting account goes on.
		$this->save_node( array( 'label' => 'Three', 'mem_gb' => 32 ), 'n3' );
		$this->provision_fully( $fifth );
		$this->assertSame( 'n3', Records::for_resource( $fifth->id() )['node'] );
	}

	public function test_a_deleted_account_gives_its_room_back(): void {
		$this->settings( array( 'hosting_mem_overcommit' => '1', 'hosting_reserve_gb' => '20' ) );
		$r = $this->resource( array( 'memory_mb' => 10000, 'db_buffer_mb' => 0 ) );
		$this->provision_fully( $r );
		$this->assertFalse( HostingCapacity::fits( 'n1', $this->plan( array( 'memory_mb' => 10000, 'db_buffer_mb' => 0 ) ) ) );

		$this->assertTrue( $this->driver->delete( Resource::find( $r->id() ) )->is_done() );

		$this->assertTrue( HostingCapacity::fits( 'n1', $this->plan( array( 'memory_mb' => 10000, 'db_buffer_mb' => 0 ) ) ) );
	}

	public function test_the_admin_is_told_once_a_day_when_a_node_fills_up(): void {
		$mails = array();
		add_filter(
			'pre_wp_mail',
			function ( $pre, $args ) use ( &$mails ) {
				$mails[] = $args;

				return true;
			},
			10,
			2
		);
		$this->settings( array( 'hosting_mem_overcommit' => '1', 'hosting_reserve_gb' => '20', 'hosting_alert_percent' => '80' ) );

		$this->assertSame( array(), HostingCapacity::alert(), 'Empty node: no alert.' );

		$this->provision_fully( $this->resource( array( 'memory_mb' => 10000, 'db_buffer_mb' => 0 ) ) ); // 10 of 12 GB.

		$this->assertSame( array( 'n1' ), HostingCapacity::alert() );
		$this->assertSame( array(), HostingCapacity::alert(), 'Once a day.' );
		$this->assertCount( 1, $mails );
		$this->assertSame( 'christian.beltoft@gmail.com', $mails[0]['to'] );
		$this->assertStringContainsString( 'n1', $mails[0]['subject'] );
		$this->assertStringNotContainsString( "\u{2014}", $mails[0]['message'] );
	}

	public function test_stock_closes_a_plan_with_no_room_and_never_blocks_an_existing_customer(): void {
		$product = $this->service_product(
			'hosting',
			array( 'node_group' => 'g1', 'memory_mb' => 2048, 'disk_gb' => 10, 'cores' => 1, 'sites' => 1 ),
			array( 'max' => 3 )
		);
		Stock::register();

		$this->assertTrue( Stock::available( $product ) );
		$this->assertTrue( $product->is_in_stock() );
		$this->assertTrue( $product->is_purchasable() );

		// No node of the group: sold out.
		$this->settings( array( 'hosting_reserve_gb' => '1000' ) );
		$fresh = wc_get_product( $product->get_id() );
		$this->assertFalse( Stock::available( $fresh ) );
		$this->assertFalse( apply_filters( 'woocommerce_product_is_in_stock', true, $fresh ) );
		$this->assertFalse( apply_filters( 'woocommerce_is_purchasable', true, $fresh ) );
		$this->assertFalse( apply_filters( 'woocommerce_is_purchasable', false, $fresh ), 'It only closes.' );

		// A switch or resubscribe request passes.
		$_REQUEST['switch-subscription'] = '5';
		$this->assertTrue( apply_filters( 'woocommerce_product_is_in_stock', true, $fresh ) );
		unset( $_REQUEST['switch-subscription'] );

		// Another product is left alone.
		$other = $this->service_product( 'fake' );
		$this->assertTrue( apply_filters( 'woocommerce_product_is_in_stock', true, $other ) );
	}
}

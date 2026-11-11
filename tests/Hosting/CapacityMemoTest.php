<?php
/**
 * HostingCapacity::usage() is computed once per node and request, and every write that can change it forgets it.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Hosting;

require_once __DIR__ . '/HostingCase.php';

use BeltoftServices\Hosting\HostingCapacity;
use BeltoftServices\Hosting\Plan;
use BeltoftServices\Hosting\Records;
use BeltoftServices\Hosting\Schema;

/**
 * The memo and its invalidation.
 *
 * Changed on purpose with the stock policy of 2026-11-11 (capacity never stops a sale, owner decision):
 * the tests that proved the cart, the cart check and the checkout read the records afresh for a sold-out decision
 * are gone (there is no such decision any more) and one test proves the display and cart path makes no capacity
 * query at all; a placement that found "no room" now picks the node with the most room; a switch is no longer refused
 * for capacity.
 */
class CapacityMemoTest extends HostingCase {

	/**
	 * A typed plan.
	 *
	 * @param array<string, mixed> $over Overrides.
	 * @return array<string, int|string|bool>
	 */
	private function plan( array $over = array() ): array {
		return Plan::typed( array_merge( array( 'node_group' => 'g1', 'cores' => 2, 'memory_mb' => 3072, 'disk_gb' => 20, 'sites' => 1, 'db_buffer_mb' => 100 ), $over ) );
	}

	/**
	 * Accounts placed on n1 without a provision run.
	 *
	 * @param int $count How many.
	 * @return \BeltoftServices\Resource[]
	 */
	private function accounts( int $count, array $plan = array() ): array {
		$out = array();
		for ( $i = 0; $i < $count; $i++ ) {
			$r = $this->resource( $plan );
			Records::open( $r->id(), 'n1' );
			$out[] = $r;
		}
		return $out;
	}

	private function queries( callable $fn ): int {
		global $wpdb;
		$before = $wpdb->num_queries;
		$fn();
		return $wpdb->num_queries - $before;
	}

	/**
	 * The SQL the shop runs while a closure runs, as a list.
	 *
	 * @param callable $fn What to run.
	 * @return string[]
	 */
	private function sql( callable $fn ): array {
		$seen = array();
		$grab = static function ( $query ) use ( &$seen ) {
			$seen[] = (string) $query;

			return $query;
		};
		add_filter( 'query', $grab );
		$fn();
		remove_filter( 'query', $grab );

		return $seen;
	}

	public function test_showing_stock_for_many_plans_makes_no_capacity_query(): void {
		global $wpdb;
		\BeltoftServices\Hosting\Stock::register();
		$this->settings( array( 'hosting_mem_overcommit' => '1', 'hosting_reserve_gb' => '2' ) );
		$this->accounts( 6, array( 'memory_mb' => 512, 'db_buffer_mb' => 0 ) );
		$products = array();
		for ( $i = 1; $i <= 12; $i++ ) {
			$products[] = $this->service_product( 'hosting', array( 'node_group' => 'g1', 'memory_mb' => 256 * $i, 'disk_gb' => 10, 'cores' => 1, 'sites' => 1 ), array( 'max' => 3 ) );
		}
		HostingCapacity::forget();

		$queries = $this->sql(
			function () use ( $products ) {
				foreach ( $products as $product ) {
					$fresh = wc_get_product( $product->get_id() );
					$fresh->is_purchasable();
					$fresh->is_in_stock();
					apply_filters( 'woocommerce_add_to_cart_validation', true, $product->get_id(), 1 );
				}
				do_action( 'woocommerce_check_cart_items' );
				do_action( 'woocommerce_checkout_process' );
			}
		);

		$tables = array( Schema::tables()['hosting'], $wpdb->prefix . 'beltoft_licenses_activations' );
		foreach ( $queries as $sql ) {
			foreach ( $tables as $table ) {
				$this->assertStringNotContainsString( $table, $sql, 'A page, the cart or the checkout read capacity: ' . $sql );
			}
		}
	}

	public function test_two_sequential_placements_see_each_others_commitment(): void {
		$this->settings( array( 'hosting_mem_overcommit' => '1', 'hosting_reserve_gb' => '27' ) );
		$this->save_node( array( 'label' => 'Two', 'mem_gb' => 32 ), 'n2' );
		// 5 GB budget on each node; the plan commits 3172 MB and an account 2098 MB, so one account fits on a node and a second does not.
		$plan = $this->plan();
		$a    = $this->resource();

		$this->assertSame( 'n1', HostingCapacity::place( $plan ) );
		Records::open( $a->id(), 'n1' );

		$this->assertSame( 'n2', HostingCapacity::place( $plan ), 'The second placement sees the first one without a forget() call.' );
		$this->assertFalse( HostingCapacity::fits( 'n1', $plan ) );

		Records::remove( $a->id() );
		$this->assertSame( 'n1', HostingCapacity::place( $plan ), 'A removed record frees its room at once.' );
	}

	public function test_a_state_change_to_deleted_frees_capacity_in_the_same_process(): void {
		$this->settings( array( 'hosting_mem_overcommit' => '1', 'hosting_reserve_gb' => '2' ) );
		list( $r ) = $this->accounts( 1 );

		$this->assertSame( 1, HostingCapacity::usage( 'n1' )['accounts'] );

		$this->assertTrue( $r->transition( 'deleted', 'test' ) );

		$this->assertSame( 0, HostingCapacity::usage( 'n1' )['accounts'] );
	}

	public function test_a_plan_change_is_seen_in_the_same_process(): void {
		$this->settings( array( 'hosting_mem_overcommit' => '1', 'hosting_reserve_gb' => '2' ) );
		list( $r ) = $this->accounts( 1, array( 'memory_mb' => 1024, 'db_buffer_mb' => 0 ) );
		$before    = HostingCapacity::usage( 'n1' )['memory_committed'];

		$plan                = (array) $r->get( 'plan' );
		$plan['memory_mb']   = 2048;
		$this->assertTrue( $r->set( array( 'plan' => $plan ) ) );

		$this->assertEqualsWithDelta( $before + 1024, HostingCapacity::usage( 'n1' )['memory_committed'], 0.001 );
	}

	public function test_saved_settings_and_nodes_are_seen_in_the_same_process(): void {
		$this->settings( array( 'hosting_reserve_gb' => '2' ) );
		$free = HostingCapacity::free_memory( 'n1' );

		$this->settings( array( 'hosting_reserve_gb' => '4' ) );
		$this->assertEqualsWithDelta( $free - 2048, HostingCapacity::free_memory( 'n1' ), 0.001 );

		$this->save_node( array( 'mem_gb' => 64 ) );
		$this->assertEqualsWithDelta( ( 64 - 4 ) * 1024, HostingCapacity::usage( 'n1' )['memory_budget'], 0.001 );

		\BeltoftServices\Hosting\Nodes::delete( 'n1' );
		$this->assertNull( HostingCapacity::usage( 'n1' ) );
	}

	public function test_a_job_boundary_resets_the_memo(): void {
		global $wpdb;
		$this->settings( array( 'hosting_mem_overcommit' => '1', 'hosting_reserve_gb' => '2' ) );
		list( $r ) = $this->accounts( 1 );
		$this->assertSame( 1, HostingCapacity::usage( 'n1' )['accounts'] );

		// Another process writes: a raw delete, which no hook of this process sees.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- test fixture.
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE resource_id = %d', Schema::tables()['hosting'], $r->id() ) );
		$this->assertSame( 1, HostingCapacity::usage( 'n1' )['accounts'], 'Inside one request the answer is remembered.' );

		do_action( 'action_scheduler_before_execute', 1 );
		$this->assertSame( 0, HostingCapacity::usage( 'n1' )['accounts'], 'The next job starts from the database.' );
	}

	// -- Decisions read the records afresh ---------------------------------------------------------

	/**
	 * Another process stores an account on a node: a raw insert, which no hook of this process sees.
	 *
	 * @param \BeltoftServices\Resource $r    The account.
	 * @param string                      $node Node record id.
	 */
	private function raw_record( \BeltoftServices\Resource $r, string $node = 'n1' ): void {
		global $wpdb;

		$now = gmdate( 'Y-m-d H:i:s' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- test fixture.
		$wpdb->insert( Schema::tables()['hosting'], array( 'resource_id' => $r->id(), 'node' => $node, 'created' => $now, 'updated' => $now ), array( '%d', '%s', '%s', '%s' ) );
	}

	/**
	 * One account fits on n1, a second does not (5 GB budget, the plan commits 3172 MB). The memo is warm and holds
	 * "one account fits"; then another process fills the node.
	 *
	 * @return array<string, mixed> The plan.
	 */
	private function fill_the_node_behind_the_memo(): array {
		$this->settings( array( 'hosting_mem_overcommit' => '1', 'hosting_reserve_gb' => '27' ) );
		$plan   = array( 'node_group' => 'g1', 'cores' => 2, 'memory_mb' => 3072, 'disk_gb' => 20, 'sites' => 1, 'db_buffer_mb' => 100 );
		$filler = $this->resource( $plan );

		$this->assertSame( 0, HostingCapacity::usage( 'n1' )['accounts'], 'The memo is warm and the node is empty.' );
		$this->raw_record( $filler );
		$this->assertSame( 0, HostingCapacity::usage( 'n1' )['accounts'], 'Nothing forgot: the memo is stale.' );

		return $plan;
	}

	public function test_a_placement_reads_the_records_inside_its_lock(): void {
		$this->save_node( array( 'label' => 'Two', 'mem_gb' => 32 ), 'n2' );
		$plan = $this->fill_the_node_behind_the_memo();
		$b    = $this->resource( $plan );

		$this->provision( $b );

		$this->assertSame( 'n2', Records::for_resource( $b->id() )['node'], 'The memo said both nodes are empty; inside the lock the records say n1 holds an account, so the account goes to n2.' );
	}

	public function test_a_switch_is_not_refused_for_capacity(): void {
		$this->settings( array( 'hosting_mem_overcommit' => '1', 'hosting_reserve_gb' => '27' ) );
		$r = $this->resource( array( 'node_group' => 'g1', 'memory_mb' => 1024, 'db_buffer_mb' => 0, 'disk_gb' => 10, 'sites' => 3 ) );
		Records::open( $r->id(), 'n1' );
		$target = Plan::typed( array_merge( (array) $r->get( 'plan' ), array( 'memory_mb' => 2048 ) ) );

		$filler = $this->resource( array( 'node_group' => 'g1', 'memory_mb' => 3500, 'db_buffer_mb' => 0, 'disk_gb' => 10 ) );
		$this->raw_record( $filler );
		HostingCapacity::forget();

		$this->assertNull( \BeltoftServices\Hosting\HostingSwitch::refusal( $r, $target ), 'The node is over its budget with the upgrade; the upgrade goes through all the same.' );
	}

	public function test_the_cart_no_longer_hooks_capacity_and_the_validation_still_runs_first(): void {
		\BeltoftServices\Hosting\Stock::register();

		$this->assertFalse( has_action( 'woocommerce_check_cart_items', array( HostingCapacity::class, 'forget' ) ), 'No capacity read is tied to the cart check any more.' );
		$this->assertFalse( has_action( 'woocommerce_checkout_process', array( HostingCapacity::class, 'forget' ) ) );
		$this->assertFalse( has_filter( 'woocommerce_is_purchasable', array( \BeltoftServices\Hosting\Stock::class, 'filter' ) ) );

		$validation = has_filter( 'woocommerce_add_to_cart_validation', array( \BeltoftServices\Billing\ExistingFlow::class, 'validation' ) );
		$this->assertNotFalse( $validation );
		$this->assertLessThan( 1, (int) $validation, 'Before the validation filters WooCommerce registers at priority 1.' );
	}

	public function test_find_many_returns_the_resources_in_the_order_of_the_ids_given(): void {
		$a = $this->resource();
		$b = $this->resource();
		$c = $this->resource();

		$this->assertSame( array( $c->id(), $a->id(), $b->id() ), array_keys( \BeltoftServices\Resource::find_many( array( $c->id(), $a->id(), $b->id(), $a->id() ) ) ), 'Given order, a repeated id once.' );
		$this->assertSame( array( $b->id(), $c->id(), $a->id() ), array_keys( \BeltoftServices\Resource::find_many( array( $b->id(), 999999, $c->id(), $a->id() ) ) ), 'An unknown id is left out.' );
	}
}

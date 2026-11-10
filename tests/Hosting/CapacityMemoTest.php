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

	public function test_stock_for_many_plans_costs_what_one_plan_costs(): void {
		$this->settings( array( 'hosting_mem_overcommit' => '1', 'hosting_reserve_gb' => '2' ) );
		$this->accounts( 6, array( 'memory_mb' => 512, 'db_buffer_mb' => 0 ) );

		HostingCapacity::forget();
		$one = $this->queries( fn() => HostingCapacity::available( $this->plan() ) );

		HostingCapacity::forget();
		$many = $this->queries( function () {
			for ( $i = 1; $i <= 40; $i++ ) {
				HostingCapacity::available( $this->plan( array( 'memory_mb' => 256 * $i ) ) );
			}
		} );

		$this->assertSame( $one, $many, 'Forty plans on the node cost the same queries as one.' );
		// One read of the records and one read of every account, however many accounts there are.
		$this->assertLessThanOrEqual( 3, $one );
	}

	public function test_two_sequential_placements_see_each_others_commitment(): void {
		$this->settings( array( 'hosting_mem_overcommit' => '1', 'hosting_reserve_gb' => '27' ) );
		// 5 GB budget; the plan commits 3172 MB and an account 2098 MB, so one account fits and a second does not.
		$plan = $this->plan();
		$a    = $this->resource();
		$b    = $this->resource();

		$this->assertSame( 'n1', HostingCapacity::place( $plan ) );
		Records::open( $a->id(), 'n1' );

		$this->assertNull( HostingCapacity::place( $plan ), 'The second placement sees the first one without a forget() call.' );
		$this->assertFalse( HostingCapacity::fits( 'n1', $plan ) );

		Records::remove( $a->id() );
		$this->assertSame( 'n1', HostingCapacity::place( $plan ), 'A removed record frees its room at once.' );
		unset( $b );
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
		$plan = $this->fill_the_node_behind_the_memo();
		$b    = $this->resource( $plan );

		$result = $this->provision( $b );

		$this->assertTrue( $result->is_retry(), 'The node is full: the placement waits.' );
		$this->assertNull( Records::for_resource( $b->id() ), 'No second account was placed on the full node.' );
	}

	public function test_a_switch_refusal_reads_the_records_afresh(): void {
		$this->settings( array( 'hosting_mem_overcommit' => '1', 'hosting_reserve_gb' => '27' ) );
		$r = $this->resource( array( 'node_group' => 'g1', 'memory_mb' => 1024, 'db_buffer_mb' => 0, 'disk_gb' => 10, 'sites' => 3 ) );
		Records::open( $r->id(), 'n1' );
		$target = Plan::typed( array_merge( (array) $r->get( 'plan' ), array( 'memory_mb' => 2048 ) ) );

		$this->assertNull( \BeltoftServices\Hosting\HostingSwitch::refusal( $r, $target ), 'Room on the node: this also warms the memo.' );

		$filler = $this->resource( array( 'node_group' => 'g1', 'memory_mb' => 3500, 'db_buffer_mb' => 0, 'disk_gb' => 10 ) );
		$this->raw_record( $filler );

		$this->assertStringContainsString( 'cannot be added to your server', (string) \BeltoftServices\Hosting\HostingSwitch::refusal( $r, $target ) );
	}

	private function stock_product(): \WC_Product {
		return $this->service_product( 'hosting', array( 'node_group' => 'g1', 'cores' => 2, 'memory_mb' => 3072, 'disk_gb' => 20, 'sites' => 1, 'db_buffer_mb' => 100 ), array( 'max' => 3 ) );
	}

	public function test_adding_to_the_cart_reads_the_records_afresh(): void {
		\BeltoftServices\Hosting\Stock::register();
		$this->fill_the_node_behind_the_memo();
		$product = $this->stock_product();

		apply_filters( 'woocommerce_add_to_cart_validation', true, $product->get_id(), 1 );

		$this->assertFalse( \BeltoftServices\Hosting\Stock::available( $product ), 'The add decides on the records, not on the memo.' );
	}

	public function test_checking_the_cart_reads_the_records_afresh(): void {
		\BeltoftServices\Hosting\Stock::register();
		$this->fill_the_node_behind_the_memo();
		$product = $this->stock_product();

		do_action( 'woocommerce_check_cart_items' );

		$this->assertFalse( \BeltoftServices\Hosting\Stock::available( $product ) );
	}

	public function test_the_checkout_reads_the_records_afresh(): void {
		\BeltoftServices\Hosting\Stock::register();
		$this->fill_the_node_behind_the_memo();
		$product = $this->stock_product();

		do_action( 'woocommerce_checkout_process' );

		$this->assertFalse( \BeltoftServices\Hosting\Stock::available( $product ) );
	}

	public function test_the_cart_forgets_run_before_woocommerces_own_cart_check(): void {
		\BeltoftServices\Hosting\Stock::register();

		$check = has_action( 'woocommerce_check_cart_items', array( WC()->cart, 'check_cart_items' ) );
		$this->assertNotFalse( $check, 'WooCommerce checks the cart on this action.' );

		$forget = has_action( 'woocommerce_check_cart_items', array( HostingCapacity::class, 'forget' ) );
		$this->assertNotFalse( $forget );
		$this->assertLessThan( (int) $check, (int) $forget, 'The forget runs before the cart check, whatever the registration order.' );

		$validation = has_filter( 'woocommerce_add_to_cart_validation', array( \BeltoftServices\Hosting\Stock::class, 'validation' ) );
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

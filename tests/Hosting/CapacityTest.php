<?php
/**
 * Capacity, placement and sold-out hosting plans.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Hosting;

require_once __DIR__ . '/HostingCase.php';

use BeltoftServices\Billing\ExistingFlow;
use BeltoftServices\Hosting\HostingCapacity;
use BeltoftServices\Hosting\Plan;
use BeltoftServices\Hosting\Records;
use BeltoftServices\Hosting\Stock;
use BeltoftServices\Resource;

/**
 * HostingCapacity and Stock.
 *
 * Changed on purpose with the stock policy of 2026-11-11 (capacity never stops a sale, owner decision): placement
 * picks the node with the most room left instead of waiting when none fits, and the stock tests now prove that only
 * WooCommerce's own stock status closes a plan.
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
		$this->assertNull( HostingCapacity::place( $this->plan( array( 'node_group' => 'none' ) ) ), 'No node in the group: nothing to place on.' );

		$this->settings( array( 'hosting_reserve_gb' => '12', 'hosting_mem_overcommit' => '1' ) );
		$this->assertSame( 'n3', HostingCapacity::place( $this->plan( array( 'memory_mb' => 10000 ) ) ), 'Only the big node has room.' );
		$this->assertSame( 'n3', HostingCapacity::place( $this->plan( array( 'memory_mb' => 90000 ) ) ), 'No node has room: the one with the most room left (the least over) takes it, never null.' );
	}

	public function test_placement_on_full_nodes_picks_the_least_over_node_and_skips_disabled_ones(): void {
		$this->settings( array( 'hosting_mem_overcommit' => '1', 'hosting_reserve_gb' => '0' ) );
		$this->save_node( array( 'label' => 'Small', 'mem_gb' => 4 ), 'n2' );
		$this->save_node( array( 'label' => 'Off', 'mem_gb' => 512, 'enabled' => '' ), 'n4' );
		// n1 has 32 GB, n2 has 4 GB, n4 is off. A 40 GB plan fits nowhere.
		$plan = $this->plan( array( 'memory_mb' => 40000, 'db_buffer_mb' => 0 ) );

		$this->assertFalse( HostingCapacity::fits( 'n1', $plan ) );
		$this->assertFalse( HostingCapacity::fits( 'n2', $plan ) );
		$this->assertSame( 'n1', HostingCapacity::place( $plan ), 'n1 is over by 8 GB, n2 by 36 GB, the disabled node is skipped whatever its room.' );
	}

	public function test_new_accounts_spread_by_free_room_and_a_full_group_still_places(): void {
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

		// Both nodes are at 10 of 12 GB: the fifth account does not fit anywhere, and is placed all the same (least over: the first on a tie).
		$fifth = $this->resource( $plan );
		$this->provision_fully( $fifth );

		$this->assertSame( 'n1', Records::for_resource( $fifth->id() )['node'], 'Capacity never stops a placement.' );
		$this->assertCount( 5, $this->node->calls_to( '#/clone$#', 'POST' ), 'The fifth was cloned.' );

		// A node is added: the next account goes there.
		$this->save_node( array( 'label' => 'Three', 'mem_gb' => 32 ), 'n3' );
		$sixth = $this->resource( $plan );
		$this->provision_fully( $sixth );
		$this->assertSame( 'n3', Records::for_resource( $sixth->id() )['node'] );
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

	public function test_a_plan_is_sold_out_only_by_the_woocommerce_stock_status_whatever_the_capacity(): void {
		$product = $this->service_product(
			'hosting',
			array( 'node_group' => 'g1', 'memory_mb' => 2048, 'disk_gb' => 10, 'cores' => 1, 'sites' => 1 ),
			array( 'max' => 3 )
		);
		Stock::register();

		$this->assertTrue( $product->is_in_stock() );
		$this->assertTrue( $product->is_purchasable() );

		// Capacity exhausted (no memory budget at all): still on sale.
		$this->settings( array( 'hosting_reserve_gb' => '1000' ) );
		$fresh = wc_get_product( $product->get_id() );
		$this->assertTrue( $fresh->is_in_stock() );
		$this->assertTrue( $fresh->is_purchasable() );
		$this->assertTrue( apply_filters( 'woocommerce_product_is_in_stock', true, $fresh ) );
		$this->assertTrue( apply_filters( 'woocommerce_is_purchasable', true, $fresh ) );

		// Set out of stock by hand: sold out, as WooCommerce does it.
		$fresh->set_stock_status( 'outofstock' );
		$fresh->save();
		$sold = wc_get_product( $product->get_id() );
		$this->assertFalse( $sold->is_in_stock() );
		$this->assertFalse( apply_filters( 'woocommerce_product_is_in_stock', $sold->is_in_stock(), $sold ) );

		// A bare request argument opens nothing: not for a guest, not in a posted form, not without the nonce.
		$_GET['switch-subscription']     = '5';
		$_GET['_wcsnonce']               = wp_create_nonce( 'wcs_switch_request' );
		$this->assertFalse( apply_filters( 'woocommerce_product_is_in_stock', false, $sold ), 'A guest with a switch link.' );
		unset( $_GET['switch-subscription'], $_GET['_wcsnonce'] );
		$_GET['resubscribe'] = '5';
		$this->assertFalse( apply_filters( 'woocommerce_product_is_in_stock', false, $sold ), 'A guest with resubscribe=5.' );
		unset( $_GET['resubscribe'] );

		$user  = self::factory()->user->create();
		$other = $this->service_product( 'fake' );
		$mine  = wcs_create_subscription( array( 'customer_id' => $user, 'billing_period' => 'month', 'billing_interval' => 1, 'status' => 'active' ) );
		$mine->add_product( $other );
		$mine->save();
		$holding = wcs_create_subscription( array( 'customer_id' => $user, 'billing_period' => 'month', 'billing_interval' => 1, 'status' => 'active' ) );
		$holding->add_product( $product );
		$holding->save();
		wp_set_current_user( $user );
		$_GET['switch-subscription'] = (string) $mine->get_id();
		$_GET['_wcsnonce']           = wp_create_nonce( 'wcs_switch_request' );
		$this->assertFalse( apply_filters( 'woocommerce_product_is_in_stock', false, $sold ), 'A logged-in customer with an unrelated own subscription.' );
		unset( $_GET['switch-subscription'], $_GET['_wcsnonce'] );

		// The login rule: a subscription with no customer does not match a guest.
		$guest_sub = wcs_create_subscription( array( 'customer_id' => $user, 'billing_period' => 'month', 'billing_interval' => 1, 'status' => 'active' ) );
		$guest_sub->add_product( $product );
		$guest_sub->set_customer_id( 0 );
		$guest_sub->save();
		wp_set_current_user( 0 );
		ExistingFlow::note( array( 'subscription_renewal' => array( 'subscription_id' => $guest_sub->get_id() ) ), $product->get_id(), 0 );
		$this->assertFalse( apply_filters( 'woocommerce_product_is_in_stock', false, $sold ), 'A guest naming a subscription with no customer.' );
		ExistingFlow::clear();

		// A bare switch argument of the holder is refused too (posted form, or no nonce).
		wp_set_current_user( $user );
		$add_filter = static fn( $can, $item, $s ) => $s instanceof \WC_Subscription && $s->has_status( 'active' );
		add_filter( 'woocommerce_subscriptions_can_item_be_switched', $add_filter, 10, 3 );
		$_REQUEST['switch-subscription'] = (string) $holding->get_id();
		$this->assertFalse( apply_filters( 'woocommerce_product_is_in_stock', false, $sold ), 'Posted form only.' );
		unset( $_REQUEST['switch-subscription'] );
		$_GET['switch-subscription'] = (string) $holding->get_id();
		$this->assertFalse( apply_filters( 'woocommerce_product_is_in_stock', false, $sold ), 'No nonce.' );
		unset( $_GET['switch-subscription'] );
		remove_filter( 'woocommerce_subscriptions_can_item_be_switched', $add_filter, 10 );
		wp_set_current_user( 0 );

		// Another product is left alone.
		$other = $this->service_product( 'fake' );
		$this->assertTrue( apply_filters( 'woocommerce_product_is_in_stock', true, $other ) );
		$this->assertFalse( apply_filters( 'woocommerce_product_is_in_stock', false, $other ) );
	}

	public function test_a_renewal_of_a_sold_out_plan_can_be_added_to_the_cart_reloaded_and_paid(): void {
		$product = $this->service_product(
			'hosting',
			array( 'node_group' => 'g1', 'memory_mb' => 2048, 'disk_gb' => 10, 'cores' => 1, 'sites' => 1 ),
			array( 'max' => 3 )
		);
		Stock::register();
		// Orders commit; the settings are written after them, so the test's rollback takes the settings back.
		$user = self::factory()->user->create();
		$sub  = wcs_create_subscription( array( 'customer_id' => $user, 'billing_period' => 'month', 'billing_interval' => 1, 'status' => 'active' ) );
		$sub->add_product( $product );
		$sub->save();
		$renewal = wcs_create_renewal_order( $sub );
		$plain   = wc_create_order( array( 'customer_id' => $user ) );
		$plain->add_product( $product );
		$product->set_stock_status( 'outofstock' );
		$product->save();
		$fresh = wc_get_product( $product->get_id() );
		$this->assertFalse( $fresh->is_in_stock(), 'Sold out for a new customer.' );

		// WooCommerce Subscriptions adds a renewal with its key in the item data; the check runs before the item is in the cart.
		$data = array( 'subscription_renewal' => array( 'subscription_id' => $sub->get_id(), 'renewal_order_id' => $renewal->get_id() ) );
		apply_filters( 'woocommerce_add_cart_item_data', $data, $product->get_id(), 0 );
		$this->assertFalse( wc_get_product( $product->get_id() )->is_in_stock(), 'A guest forging the renewal key is refused.' );
		wp_set_current_user( $user );
		$this->assertTrue( wc_get_product( $product->get_id() )->is_in_stock(), 'The renewal being added passes.' );
		ExistingFlow::clear();
		$this->assertFalse( wc_get_product( $product->get_id() )->is_in_stock(), 'Only while it is being added.' );

		// The key naming a subscription of somebody else, or one that holds another product, is refused.
		$foreign = wcs_create_subscription( array( 'customer_id' => self::factory()->user->create(), 'billing_period' => 'month', 'billing_interval' => 1, 'status' => 'active' ) );
		$foreign->add_product( $product );
		$foreign->save();
		apply_filters( 'woocommerce_add_cart_item_data', array( 'subscription_renewal' => array( 'subscription_id' => $foreign->get_id() ) ), $product->get_id(), 0 );
		$this->assertFalse( wc_get_product( $product->get_id() )->is_in_stock(), 'Somebody else\'s subscription.' );
		$unrelated = wcs_create_subscription( array( 'customer_id' => $user, 'billing_period' => 'month', 'billing_interval' => 1, 'status' => 'active' ) );
		$unrelated->add_product( $this->service_product( 'fake' ) );
		$unrelated->save();
		apply_filters( 'woocommerce_add_cart_item_data', array( 'subscription_renewal' => array( 'subscription_id' => $unrelated->get_id() ) ), $product->get_id(), 0 );
		$this->assertFalse( wc_get_product( $product->get_id() )->is_in_stock(), 'An own subscription of another product.' );
		ExistingFlow::clear();
		wp_set_current_user( $user );

		apply_filters( 'woocommerce_add_cart_item_data', array(), $product->get_id(), 0 );
		$this->assertFalse( wc_get_product( $product->get_id() )->is_in_stock(), 'A new purchase is not let through.' );

		// Paying the renewal order.
		$this->assertTrue( (bool) apply_filters( 'woocommerce_pay_order_product_in_stock', $fresh->is_in_stock(), $fresh, $renewal ) );
		$this->assertFalse( (bool) apply_filters( 'woocommerce_pay_order_product_in_stock', $fresh->is_in_stock(), $fresh, $plain ) );
	}

	public function test_a_real_switch_and_a_real_resubscribe_pass_on_a_hand_closed_plan(): void {
		Stock::register();
		$product = $this->service_product( 'hosting', array( 'node_group' => 'g1', 'memory_mb' => 2048, 'disk_gb' => 10, 'cores' => 1, 'sites' => 1 ), array( 'max' => 3 ) );
		$product->set_stock_status( 'outofstock' );
		$product->save();
		$sold = wc_get_product( $product->get_id() );
		$user = self::factory()->user->create();
		$sub  = wcs_create_subscription( array( 'customer_id' => $user, 'billing_period' => 'month', 'billing_interval' => 1, 'status' => 'active' ) );
		$sub->add_product( $product );
		$sub->save();
		wp_set_current_user( $user );
		// Subscriptions' own switch rule ends in this filter (the test plan is not a subscription product).
		add_filter( 'woocommerce_subscriptions_can_item_be_switched', static fn( $can, $item, $s ) => $s instanceof \WC_Subscription && $s->has_status( 'active' ), 10, 3 );

		$_GET['switch-subscription'] = (string) $sub->get_id();
		$_GET['_wcsnonce']           = wp_create_nonce( 'wcs_switch_request' );
		$this->assertTrue( apply_filters( 'woocommerce_product_is_in_stock', false, $sold ), 'A real switch link of an active subscription that holds the plan.' );

		$sub->update_status( 'cancelled' );
		$this->assertFalse( apply_filters( 'woocommerce_product_is_in_stock', false, $sold ), 'A cancelled subscription is not switched.' );
		unset( $_GET['switch-subscription'], $_GET['_wcsnonce'] );

		// A resubscribe is a cart line with Subscriptions' key; the argument alone is nothing.
		$_GET['resubscribe'] = (string) $sub->get_id();
		$this->assertFalse( apply_filters( 'woocommerce_product_is_in_stock', false, $sold ), 'The argument alone.' );
		unset( $_GET['resubscribe'] );
		ExistingFlow::note( array( 'subscription_resubscribe' => array( 'subscription_id' => $sub->get_id() ) ), $product->get_id(), 0 );
		$this->assertTrue( apply_filters( 'woocommerce_product_is_in_stock', false, $sold ), 'A resubscribe of a cancelled subscription that holds the plan.' );
		ExistingFlow::clear();
		wp_set_current_user( 0 );
	}

	public function test_a_product_out_of_stock_by_hand_cannot_be_added_to_the_cart_and_a_capacity_exhausted_one_can(): void {
		Stock::register();
		$this->settings( array( 'hosting_reserve_gb' => '1000' ) ); // No room anywhere.
		$open   = $this->service_product( 'hosting', array( 'node_group' => 'g1', 'memory_mb' => 2048, 'disk_gb' => 10, 'cores' => 1, 'sites' => 1 ), array( 'max' => 3 ) );
		$closed = $this->service_product( 'hosting', array( 'node_group' => 'g1', 'memory_mb' => 2048, 'disk_gb' => 10, 'cores' => 1, 'sites' => 1 ), array( 'max' => 3 ) );
		$closed->set_stock_status( 'outofstock' );
		$closed->save();

		WC()->cart->empty_cart();
		$this->assertNotFalse( WC()->cart->add_to_cart( $open->get_id() ), 'No room on any node: still sold.' );
		WC()->cart->empty_cart();
		wc_clear_notices();
		$this->assertFalse( WC()->cart->add_to_cart( $closed->get_id() ), 'Out of stock by hand: WooCommerce refuses.' );
		wc_clear_notices();
		WC()->cart->empty_cart();
	}

	public function test_a_plan_set_out_of_stock_while_in_the_cart_fails_the_cart_check_and_the_checkout(): void {
		Stock::register();
		$product = $this->service_product( 'hosting', array( 'node_group' => 'g1', 'memory_mb' => 2048, 'disk_gb' => 10, 'cores' => 1, 'sites' => 1 ), array( 'max' => 3 ) );
		WC()->cart->empty_cart();
		$this->assertNotFalse( WC()->cart->add_to_cart( $product->get_id() ) );
		$this->assertTrue( WC()->cart->check_cart_item_stock(), 'In stock: the cart passes.' );

		$closed = wc_get_product( $product->get_id() );
		$closed->set_stock_status( 'outofstock' );
		$closed->save();
		wc_clear_notices();
		foreach ( WC()->cart->get_cart() as $key => $item ) {
			WC()->cart->cart_contents[ $key ]['data'] = wc_get_product( $item['product_id'] );
		}
		$this->assertNotTrue( WC()->cart->check_cart_item_stock(), 'Out of stock by hand: the cart check and so the checkout refuse it, as WooCommerce does.' );
		wc_clear_notices();
		WC()->cart->empty_cart();
	}

	public function test_the_admin_is_told_at_100_percent_and_at_each_further_10_percent_once_and_it_resets(): void {
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
		// 12 GB memory budget on n1 (32 GB minus 20), no overcommit.
		$this->settings( array( 'hosting_mem_overcommit' => '1', 'hosting_reserve_gb' => '20', 'hosting_alert_percent' => '100' ) );
		$put = function ( int $mb ): \BeltoftServices\Resource {
			$r = $this->resource( array( 'memory_mb' => $mb, 'db_buffer_mb' => 0, 'disk_gb' => 1 ) );
			Records::open( $r->id(), 'n1' );

			return $r;
		};

		$a = $put( 11000 ); // 91 percent.
		$this->assertSame( array(), HostingCapacity::overage_alerts(), 'Under the budget: no mail.' );

		$b = $put( 2000 ); // 13000 of 12288 MB: 105 percent.
		$this->assertSame( array( 'n1:memory' ), HostingCapacity::overage_alerts() );
		$this->assertCount( 1, $mails );
		$this->assertSame( 'christian.beltoft@gmail.com', $mails[0]['to'] );
		$this->assertStringContainsString( 'committed', $mails[0]['message'] );
		$this->assertStringContainsString( '12.0 GB', $mails[0]['message'], 'The budget is named.' );
		$this->assertStringContainsString( 'Memory reserve per node', $mails[0]['message'], 'The setting that defines it is named.' );
		$this->assertStringNotContainsString( 'stopped selling', $mails[0]['message'] );
		$this->assertSame( array(), HostingCapacity::overage_alerts(), 'Once per step.' );

		$c = $put( 1500 ); // 14500: 118 percent, step 110.
		$this->assertSame( array( 'n1:memory' ), HostingCapacity::overage_alerts() );
		$this->assertCount( 2, $mails );
		$this->assertSame( array(), HostingCapacity::overage_alerts() );

		// Falls back to 105 percent: no mail, the 110 step is forgotten.
		$this->assertTrue( $c->transition( 'deleted', 'test' ) );
		$this->assertSame( array(), HostingCapacity::overage_alerts() );
		$this->assertCount( 2, $mails );

		$d = $put( 1500 ); // 118 percent again.
		$this->assertSame( array( 'n1:memory' ), HostingCapacity::overage_alerts(), 'Crossing the step again mails again.' );
		$this->assertCount( 3, $mails );

		// Under 100: forgotten, and the first pass mails again.
		$this->assertTrue( $d->transition( 'deleted', 'test' ) );
		$this->assertTrue( $b->transition( 'deleted', 'test' ) );
		$this->assertSame( array(), HostingCapacity::overage_alerts() );
		$put( 2000 );
		$this->assertSame( array( 'n1:memory' ), HostingCapacity::overage_alerts() );
		$this->assertCount( 4, $mails );
		unset( $a );
	}

	public function test_a_placement_over_the_budget_mails_the_admin(): void {
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
		$this->settings( array( 'hosting_mem_overcommit' => '1', 'hosting_reserve_gb' => '27' ) ); // 5 GB budget.
		$this->provision_fully( $this->resource( array( 'memory_mb' => 4000, 'db_buffer_mb' => 0, 'disk_gb' => 5 ) ) );
		$this->assertCount( 0, $mails );
		$this->provision_fully( $this->resource( array( 'memory_mb' => 4000, 'db_buffer_mb' => 0, 'disk_gb' => 5 ) ) );
		$this->assertCount( 1, $mails, 'The second account is placed over the budget and the admin is told.' );
	}

	public function test_the_cross_check_mails_on_low_real_free_memory_or_disk_and_is_quiet_otherwise(): void {
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
		$this->settings( array( 'capacity_free_memory_percent' => 10, 'capacity_free_disk_percent' => 10 ) );

		$this->assertSame( array(), HostingCapacity::cross_check( 'n1', array( 'mem_total_mb' => 32768, 'mem_free_mb' => 8000, 'disk_total_gb' => 1000, 'disk_free_gb' => 400 ) ) );
		$this->assertCount( 0, $mails, 'Plenty free: quiet.' );

		$this->assertSame( array( 'memory' ), HostingCapacity::cross_check( 'n1', array( 'mem_total_mb' => 32768, 'mem_free_mb' => 1000, 'disk_total_gb' => 1000, 'disk_free_gb' => 400 ) ) );
		$this->assertCount( 1, $mails );
		$this->assertStringContainsString( 'free memory', $mails[0]['subject'] );
		$this->assertStringContainsString( 'committed', $mails[0]['message'] );
		$this->assertStringContainsString( 'budget', $mails[0]['message'] );

		$this->assertSame( array( 'disk' ), HostingCapacity::cross_check( 'n1', array( 'mem_total_mb' => 32768, 'mem_free_mb' => 8000, 'disk_total_gb' => 1000, 'disk_free_gb' => 50 ) ) );
		$this->assertCount( 2, $mails );

		$this->assertSame( array(), HostingCapacity::cross_check( 'n1', array( 'mem_total_mb' => 32768, 'mem_free_mb' => 1000 ) ), 'Once a day per node and kind.' );
		$this->assertSame( array(), HostingCapacity::cross_check( 'n1', array() ), 'A member that is missing is not checked.' );
	}

	public function test_the_health_job_tells_the_admin_when_a_node_is_over_its_budget(): void {
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
		$this->settings( array( 'hosting_mem_overcommit' => '1', 'hosting_reserve_gb' => '20', 'hosting_alert_percent' => '100' ) ); // 12 GB budget.
		$r = $this->resource( array( 'memory_mb' => 13000, 'db_buffer_mb' => 0, 'disk_gb' => 1 ) );
		Records::open( $r->id(), 'n1' );

		\BeltoftServices\Hosting\NodeHealth::run();

		$over = array_filter( $mails, static fn( $m ) => false !== strpos( (string) $m['subject'], 'over its committed memory budget' ) );
		$this->assertCount( 1, $over, 'The hourly health job sends the overage alert.' );
	}

	public function test_a_failing_mailer_never_fails_or_retries_a_placement(): void {
		add_filter(
			'pre_wp_mail',
			static function () {
				throw new \RuntimeException( 'mailer down' );
			}
		);
		$this->settings( array( 'hosting_mem_overcommit' => '1', 'hosting_reserve_gb' => '27' ) ); // 5 GB budget.
		$first = $this->resource( array( 'memory_mb' => 4000, 'db_buffer_mb' => 0, 'disk_gb' => 5 ) );
		$this->provision_fully( $first );
		$second = $this->resource( array( 'memory_mb' => 4000, 'db_buffer_mb' => 0, 'disk_gb' => 5 ) );
		$this->provision_fully( $second ); // Over the budget: the alert throws, the placement goes on and the step is done, not retried.
		$this->assertNotNull( \BeltoftServices\Hosting\Records::for_resource( $second->id() ), 'The account is placed.' );
	}

	public function test_a_failing_mailer_never_fails_the_health_job(): void {
		add_filter(
			'pre_wp_mail',
			static function () {
				throw new \RuntimeException( 'mailer down' );
			}
		);
		$this->settings( array( 'hosting_mem_overcommit' => '1', 'hosting_reserve_gb' => '20', 'hosting_alert_percent' => '100' ) );
		$r = $this->resource( array( 'memory_mb' => 13000, 'db_buffer_mb' => 0, 'disk_gb' => 1 ) );
		Records::open( $r->id(), 'n1' );

		$this->assertIsArray( \BeltoftServices\Hosting\NodeHealth::run(), 'The job ends although the overage alert threw.' );
	}
}

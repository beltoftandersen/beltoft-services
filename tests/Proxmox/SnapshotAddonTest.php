<?php
/** The paid snapshot packs: slots, cart, clamp, cascade, card, Stock. @package BeltoftServices */

namespace BeltoftServices\Tests\Proxmox;

use BeltoftServices\Drivers\Registry;
use BeltoftServices\Lifecycle;
use BeltoftServices\Product;
use BeltoftServices\Proxmox\DiskCascade;
use BeltoftServices\Proxmox\Panels;
use BeltoftServices\Proxmox\SnapshotCart;
use BeltoftServices\Proxmox\SnapshotDriver;
use BeltoftServices\Proxmox\SnapshotExpiry;
use BeltoftServices\Proxmox\SnapshotMeta;
use BeltoftServices\Proxmox\Snapshots;
use BeltoftServices\Proxmox\Stock;
use BeltoftServices\Jobs;
use BeltoftServices\Resource;

require_once __DIR__ . '/SnapshotsTestCase.php';

class SnapshotAddonTest extends SnapshotsTestCase {

	public function set_up(): void {
		parent::set_up();
		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Isolated PHPUnit fixture reset.
		$wpdb->query( 'DELETE FROM ' . $wpdb->prefix . 'woocommerce_order_itemmeta' );
		$wpdb->query( 'DELETE FROM ' . $wpdb->prefix . 'woocommerce_order_items' );
		// phpcs:enable
		WC()->cart->empty_cart();
		wc_clear_notices();
	}

	public function tear_down(): void {
		WC()->cart->empty_cart();
		unset( $_REQUEST['beltoft_services_snapshot_for'], $_REQUEST['beltoft_services_snapshot_nonce'] );
		parent::tear_down();
	}

	// -- Helpers ---------------------------------------------------------------------------------

	/** A pack resource (ready) for a server. */
	private function pack( Resource $server, int $slots = 2, array $plan = array(), ?\WC_Product $product = null ): Resource {
		$product = $product ?? $this->service_product( 'proxmox-snapshots', array_merge( array( 'snapshot_slots' => $slots ), $plan ), array( 'slug' => 'snap-' . wp_generate_password( 6, false ) ) );
		$license = $this->service_license( $product, array( 'fire' => false, 'user_id' => $server->license()['user_id'] ) );
		$pack    = Resource::create( $license, Registry::get( SnapshotDriver::ID ), Product::plan( $product->get_id() ), array( 'server_license' => (int) $server->license()['id'] ) );
		$this->assertInstanceOf( Resource::class, $pack );
		Jobs::run( $pack->id() );
		$pack = $this->fresh( $pack );
		$this->assertSame( 'ready', $pack->get( 'state' ), (string) $pack->get( 'last_error' ) );

		return $pack;
	}

	/** The published variable subscription "Snapshots" with +2 (EUR 2) and +5 (EUR 4). */
	private function product( array $slots = array( 2 => '2', 5 => '4' ) ): \WC_Product_Variable {
		$product = new \WC_Product_Variable_Subscription();
		$product->set_name( 'Snapshots ' . wp_generate_password( 5, false ) );
		$product->set_status( 'publish' );
		$attribute = new \WC_Product_Attribute();
		$attribute->set_name( 'Pack' );
		$attribute->set_options( array_map( static fn( $n ) => '+' . $n, array_keys( $slots ) ) );
		$attribute->set_variation( true );
		$product->set_attributes( array( $attribute ) );
		$product->update_meta_data( Product::META_DRIVER, SnapshotDriver::ID );
		$product->update_meta_data( \BeltoftLicenses\Software::META_LICENSED, 'yes' );
		$product->update_meta_data( \BeltoftLicenses\Software::META_SLUG, 'snaps-' . wp_generate_password( 5, false ) );
		$product->save();
		foreach ( $slots as $n => $price ) {
			$variation = new \WC_Product_Subscription_Variation();
			$variation->set_parent_id( $product->get_id() );
			$variation->set_attributes( array( 'pack' => '+' . $n ) );
			$variation->set_regular_price( $price );
			$variation->update_meta_data( '_subscription_price', $price );
			$variation->update_meta_data( '_subscription_period', 'month' );
			$variation->update_meta_data( '_subscription_period_interval', '1' );
			$variation->update_meta_data( '_subscription_length', '0' );
			$variation->update_meta_data( Product::META_PLAN . 'snapshot_slots', (string) $n );
			$variation->set_status( 'publish' );
			$variation->save();
		}
		\WC_Product_Variable::sync( $product->get_id() );

		return wc_get_product( $product->get_id() );
	}

	private function variation( \WC_Product_Variable $product, int $slots ): \WC_Product_Variation {
		foreach ( $product->get_children() as $id ) {
			$v = wc_get_product( $id );
			if ( SnapshotCart::slots_of( $v ) === $slots ) {
				return $v;
			}
		}
		$this->fail( 'No variation with ' . $slots . ' slots.' );
	}

	private function as_owner( Resource $server ): void {
		wp_set_current_user( (int) $server->license()['user_id'] );
	}

	private function request_for( Resource $server ): void {
		$_REQUEST['beltoft_services_snapshot_for']   = (string) $server->license()['id'];
		$_REQUEST['beltoft_services_snapshot_nonce'] = wp_create_nonce( 'beltoft-services-snapshots:' . $server->license()['id'] );
	}

	private function add( \WC_Product_Variation $v ) {
		return WC()->cart->add_to_cart( $v->get_parent_id(), 1, $v->get_id(), array( 'attribute_pack' => '+' . SnapshotCart::slots_of( $v ) ) );
	}

	private function mails_about( string $text ): array {
		return array_values( array_filter( $this->mails, static fn( $m ) => false !== strpos( (string) $m['message'] . (string) $m['subject'], $text ) ) );
	}

	// -- Slots -----------------------------------------------------------------------------------

	public function test_slots_are_the_free_slots_plus_the_active_packs(): void {
		$this->on( array( 'snapshot_free_slots' => 1 ) );
		$server = $this->ready_vm();
		$this->assertSame( 1, Snapshots::slots( $server ) );
		$this->assertSame( 0, Snapshots::paid_slots( $server ) );
		$this->assertSame( 7, Snapshots::days_for( $server ) );

		$this->pack( $server, 2 );
		$this->pack( $server, 5 );

		$this->assertSame( 7, Snapshots::paid_slots( $server ) );
		$this->assertSame( 8, Snapshots::slots( $server ) );
		$this->assertSame( 30, Snapshots::days_for( $server ), 'The setting for paid slots.' );
		$panel = Panels::snapshots( $this->fresh( $server ) );
		$this->assertSame( 1, $panel['included'] );
		$this->assertSame( 7, $panel['added'] );
	}

	public function test_slots_are_capped_by_the_maximum(): void {
		$this->on( array( 'snapshot_free_slots' => 1, 'snapshot_max_slots' => 4 ) );
		$server = $this->ready_vm();
		$this->pack( $server, 5 );
		$this->assertSame( 4, Snapshots::slots( $server ) );
	}

	public function test_a_pack_keeps_snapshots_for_its_own_days_when_it_sets_them(): void {
		$this->on();
		$server = $this->ready_vm();
		$this->pack( $server, 2, array( 'snapshot_days' => 45 ) );
		$this->assertSame( 45, Snapshots::days_for( $server ) );
		$entry = $this->take( $server, 'a' );
		$this->assertSame( 45, (int) round( ( $entry['expires_at'] - time() ) / DAY_IN_SECONDS ) );
	}

	public function test_only_live_active_packs_of_this_server_count(): void {
		$this->on( array( 'snapshot_free_slots' => 1 ) );
		$server = $this->ready_vm();
		$other  = $this->ready_vm();
		$pack   = $this->pack( $server, 2 );
		$this->pack( $other, 5 );
		$this->assertSame( 3, Snapshots::slots( $server ) );

		$this->assertTrue( $pack->desire( 'paused', 'test' ) );
		$this->assertSame( 1, Snapshots::slots( $this->fresh( $server ) ), 'A pack that is meant to be paused no longer counts.' );
		Jobs::run( $pack->id() );
		$this->assertSame( 'paused', $this->fresh( $pack )->get( 'state' ) );
		$this->assertSame( 1, Snapshots::slots( $this->fresh( $server ) ) );
		$this->assertSame( 6, Snapshots::slots( $other ) );
	}

	public function test_a_lapsed_license_stops_the_slots(): void {
		$this->on( array( 'snapshot_free_slots' => 1 ) );
		$server = $this->ready_vm();
		$pack   = $this->pack( $server, 2 );
		$this->assertSame( 3, Snapshots::slots( $server ) );
		$this->assertTrue( \BeltoftLicenses\Licenses::update( (int) $pack->license()['id'], array( 'status' => 'revoked' ) ) );
		$this->assertFalse( Lifecycle::is_active( $this->fresh( $pack )->license() ) );
		$this->assertSame( 1, Snapshots::slots( $server ) );
	}

	public function test_a_pack_without_a_server_fails_to_provision(): void {
		$product = $this->service_product( 'proxmox-snapshots', array( 'snapshot_slots' => 2 ), array( 'slug' => 'snap-' . wp_generate_password( 6, false ) ) );
		$license = $this->service_license( $product, array( 'fire' => false ) );
		$pack    = Resource::create( $license, Registry::get( SnapshotDriver::ID ), Product::plan( $product->get_id() ), array() );
		$this->assertTrue( Registry::get( SnapshotDriver::ID )->provision( $pack )->is_failed() );
	}

	public function test_the_order_line_links_the_pack_to_the_customers_own_server_only(): void {
		$server  = $this->ready_vm();
		$mine    = $server->license();
		$other   = $this->ready_vm();
		$product = $this->service_product( 'proxmox-snapshots', array( 'snapshot_slots' => 2 ), array( 'slug' => 'snap-' . wp_generate_password( 6, false ) ) );

		foreach ( array( array( $mine, true ), array( $other->license(), false ) ) as $case ) {
			list( $target, $linked ) = $case;
			$order   = wc_create_order( array( 'customer_id' => (int) $mine['user_id'] ) );
			$item_id = $order->add_product( $product, 1 );
			$item    = $order->get_item( $item_id );
			$item->add_meta_data( '_beltoft_services_snapshot_server', (int) $target['id'], true );
			$item->add_meta_data( '_beltoft_services_snapshot_uid', wp_generate_uuid4(), true );
			$item->save();
			$order->save();
			$license = $this->service_license( $product, array( 'fire' => false, 'user_id' => $mine['user_id'], 'order_id' => $order->get_id() ) );
			$meta    = Lifecycle::initial_meta( $license, Registry::get( SnapshotDriver::ID ) );
			if ( $linked ) {
				$this->assertSame( (int) $mine['id'], $meta['server_license'] );
				$this->assertSame( (int) $item_id, $meta['order_item_id'] );
			} else {
				$this->assertArrayNotHasKey( 'server_license', $meta, 'Someone else\'s server is never linked.' );
			}
		}
	}

	// -- Cart ------------------------------------------------------------------------------------

	public function test_a_pack_for_the_customers_own_qcow2_server_goes_in_the_cart_one_line_per_purchase(): void {
		$this->on();
		$server  = $this->ready_vm();
		$product = $this->product();
		$two     = $this->variation( $product, 2 );
		$this->as_owner( $server );
		$this->request_for( $server );

		$one = $this->add( $two );
		$twin = $this->add( $two );
		$this->assertNotFalse( $one, wp_json_encode( wc_get_notices() ) );
		$this->assertNotFalse( $twin, wp_json_encode( wc_get_notices() ) );
		$this->assertNotSame( $one, $twin );
		$this->assertCount( 2, WC()->cart->get_cart() );

		foreach ( WC()->cart->get_cart() as $key => $item ) {
			$this->assertSame( (int) $server->license()['id'], $item['beltoft_services_snapshot_for'] );
			$this->assertStringContainsString( '_snapshots_', SnapshotCart::recurring_key( 'base', $item ) );
			WC()->cart->cart_contents[ $key ]['quantity'] = 4;
		}
		SnapshotCart::one_each( WC()->cart );
		foreach ( WC()->cart->get_cart() as $item ) {
			$this->assertSame( 1, $item['quantity'], 'One line is one purchase.' );
		}
	}

	public function test_the_buy_link_carries_the_pack_name_exactly(): void {
		$this->on();
		$server = $this->ready_vm();
		$this->as_owner( $server );
		$product = $this->product();
		$two     = $this->variation( $product, 2 );
		$url     = SnapshotCart::add_url( $this->fresh( $server ), $two );
		$this->assertNotSame( '', $url );
		$query = array();
		wp_parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $query );
		$this->assertSame( '+2', $query['attribute_pack'], 'The plus sign survives the link.' );
		$this->assertSame( (string) $two->get_id(), $query['variation_id'] );
		$this->assertSame( (string) $server->license()['id'], $query['beltoft_services_snapshot_for'] );
	}

	public function test_a_pack_for_a_raw_server_is_refused(): void {
		$this->on();
		$server = $this->ready_vm();
		$this->assertTrue( $server->set( array( 'boot_format' => 'raw' ) ) );
		$this->as_owner( $server );
		$this->request_for( $server );
		$this->assertFalse( $this->add( $this->variation( $this->product(), 2 ) ) );
		$this->assertCount( 0, WC()->cart->get_cart() );
	}

	public function test_a_pack_for_someone_elses_server_or_without_the_nonce_is_refused(): void {
		$this->on();
		$server = $this->ready_vm();
		$two    = $this->variation( $this->product(), 2 );

		wp_set_current_user( self::factory()->user->create() );
		$this->request_for( $server );
		$this->assertFalse( $this->add( $two ), 'Not the owner.' );

		$this->as_owner( $server );
		$_REQUEST['beltoft_services_snapshot_for']   = (string) $server->license()['id'];
		$_REQUEST['beltoft_services_snapshot_nonce'] = 'bad';
		$this->assertFalse( $this->add( $two ), 'Bad nonce.' );

		unset( $_REQUEST['beltoft_services_snapshot_for'], $_REQUEST['beltoft_services_snapshot_nonce'] );
		$this->assertFalse( $this->add( $two ), 'No server at all.' );
		$this->assertCount( 0, WC()->cart->get_cart() );
	}

	public function test_a_pack_for_a_paused_server_or_with_the_switch_off_is_refused(): void {
		$this->on();
		$server = $this->ready_vm();
		$two    = $this->variation( $this->product(), 2 );
		$this->as_owner( $server );
		$this->request_for( $server );

		$this->settings( array( 'snapshots_enabled' => 0 ) );
		$this->assertFalse( $this->add( $two ), 'Snapshots are off.' );
		$this->assertSame( '', SnapshotCart::add_url( $this->fresh( $server ), $two ) );

		$this->settings( array( 'snapshots_enabled' => 1 ) );
		$this->assertTrue( $server->set( array( 'state' => 'paused' ) ) );
		$this->assertFalse( $this->add( $two ), 'The server is not active.' );
	}

	public function test_a_pack_must_stay_within_the_maximum(): void {
		$this->on( array( 'snapshot_free_slots' => 1, 'snapshot_max_slots' => 6 ) );
		$server  = $this->ready_vm();
		$product = $this->product();
		$this->as_owner( $server );
		$this->request_for( $server );

		$this->assertNotFalse( $this->add( $this->variation( $product, 5 ) ), wp_json_encode( wc_get_notices() ) );
		// WooCommerce's form handler (the add_url link) runs this filter; the cart method does not.
		$two = $this->variation( $product, 2 );
		$this->assertFalse( SnapshotCart::validate( true, $two->get_parent_id(), 1, $two->get_id(), array(), array() ), 'Free 1 + 5 in the cart + 2 is over 6.' );
		$this->assertStringContainsString( 'cannot hold that many', wp_json_encode( wc_get_notices() ) );
		$this->assertTrue( SnapshotCart::validate( true, $two->get_parent_id(), 1, $two->get_id(), array(), array( 'subscription_renewal' => 1 ) ), 'A renewal line was bought before.' );

		$errors = new \WP_Error();
		SnapshotCart::checkout_validation( array(), $errors );
		$this->assertFalse( $errors->has_errors(), $errors->get_error_message() );

		$this->pack( $server, 5 );
		$errors = new \WP_Error();
		SnapshotCart::checkout_validation( array(), $errors );
		$this->assertSame( 'snapshot_limit', $errors->get_error_code(), 'The pack bought meanwhile leaves no room.' );
	}

	// -- A pack ends -----------------------------------------------------------------------------

	private function three_snapshots(): array {
		$this->on( array( 'snapshot_free_slots' => 1 ) );
		$server = $this->ready_vm();
		$pack   = $this->pack( $server, 2 );
		$this->take( $server, 'a' );
		$this->take( $server, 'b' );
		$this->take( $server, 'c' );
		$this->mails = array();

		return array( $this->fresh( $server ), $pack );
	}

	public function test_a_pack_pausing_clamps_the_snapshots_and_tells_the_customer(): void {
		list( $server, $pack ) = $this->three_snapshots();
		$this->assertSame( 30, (int) round( ( Snapshots::entries( $server )[0]['expires_at'] - time() ) / DAY_IN_SECONDS ) );

		$this->assertTrue( $pack->desire( 'paused', 'test' ) );
		Jobs::run( $pack->id() );

		$this->assertSame( 'paused', $this->fresh( $pack )->get( 'state' ) );
		$list = Snapshots::entries( $this->fresh( $server ) );
		$this->assertCount( 3, $list, 'Nothing is deleted.' );
		foreach ( $list as $entry ) {
			$this->assertLessThan( time() + 8 * DAY_IN_SECONDS, $entry['expires_at'] );
		}
		$this->assertCount( 1, $this->mails_about( 'allowance went down' ) );
		$this->assertCount( 3, $this->fake->snapshots( 5000 ), 'No Proxmox delete.' );
	}

	public function test_a_pack_being_deleted_clamps_too(): void {
		list( $server, $pack ) = $this->three_snapshots();
		$this->assertTrue( $pack->desire( 'deleted', 'test' ) );
		Jobs::run( $pack->id() );
		Jobs::run( $pack->id() );

		$this->assertSame( 'deleted', $this->fresh( $pack )->get( 'state' ) );
		$this->assertCount( 1, $this->mails_about( 'allowance went down' ) );
		$this->assertSame( 1, Snapshots::slots( $this->fresh( $server ) ) );
		$this->assertTrue( SnapshotExpiry::needs_clamp( $this->fresh( $server ) ) || array() !== (array) $this->fresh( $server )->get( SnapshotExpiry::NOTICE ) );
	}

	public function test_a_plan_change_to_fewer_slots_clamps(): void {
		list( $server, $pack ) = $this->three_snapshots();
		$plan = (array) $pack->get( 'plan' );
		$plan['snapshot_slots'] = 0;
		$this->assertTrue( $pack->set( array( 'plan' => $plan ) ) );
		$this->assertTrue( Registry::get( SnapshotDriver::ID )->update_plan( $this->fresh( $pack ) )->is_done() );
		$this->assertCount( 1, $this->mails_about( 'allowance went down' ) );
	}

	public function test_resuming_a_pack_gives_the_slots_back_without_a_clamp(): void {
		list( $server, $pack ) = $this->three_snapshots();
		$this->assertTrue( $pack->desire( 'paused', 'test' ) );
		Jobs::run( $pack->id() );
		$this->mails = array();
		$this->assertTrue( $this->fresh( $pack )->desire( 'ready', 'back' ) );
		Jobs::run( $pack->id() );
		$this->assertSame( 'ready', $this->fresh( $pack )->get( 'state' ) );
		$this->assertSame( 3, Snapshots::slots( $this->fresh( $server ) ) );
		$this->assertCount( 0, $this->mails_about( 'allowance went down' ) );
	}

	// -- The server ends -------------------------------------------------------------------------

	private function subscription( int $user ): \WC_Subscription {
		$sub = wcs_create_subscription( array( 'customer_id' => $user, 'billing_period' => 'month', 'billing_interval' => 1, 'status' => 'active' ) );
		$this->assertInstanceOf( \WC_Subscription::class, $sub );
		global $wpdb;
		$wpdb->delete( \BeltoftLicenses\Install::tables()['licenses'], array( 'subscription_id' => $sub->get_id() ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Test fixture reset.

		return $sub;
	}

	/** @return array{0: Resource, 1: \WC_Subscription, 2: Resource, 3: \WC_Subscription} */
	private function server_and_pack(): array {
		$this->on();
		$server     = $this->ready_vm();
		$user       = (int) $server->license()['user_id'];
		$server_sub = $this->subscription( $user );
		$this->assertTrue( \BeltoftLicenses\Licenses::update( (int) $server->license()['id'], array( 'subscription_id' => $server_sub->get_id() ) ) );
		$product  = $this->service_product( 'proxmox-snapshots', array( 'snapshot_slots' => 2 ), array( 'slug' => 'snap-' . wp_generate_password( 6, false ) ) );
		$pack_sub = $this->subscription( $user );
		$pack_sub->add_product( $product, 1, array( 'subtotal' => '2', 'total' => '2' ) );
		$pack_sub->save();
		$license = $this->service_license( $product, array( 'fire' => false, 'user_id' => $user, 'subscription_id' => $pack_sub->get_id() ) );
		$pack    = Resource::create( $license, Registry::get( SnapshotDriver::ID ), Product::plan( $product->get_id() ), array( 'server_license' => (int) $server->license()['id'] ) );
		$this->assertInstanceOf( Resource::class, $pack );

		return array( $this->fresh( $server ), $server_sub, $pack, $pack_sub );
	}

	public function test_the_pack_subscription_ends_with_its_server(): void {
		list( , $server_sub, , $pack_sub ) = $this->server_and_pack();
		list( , , , $foreign_sub ) = $this->server_and_pack();
		$server_sub->update_status( 'cancelled' );
		$this->assertSame( 'cancelled', wcs_get_subscription( $pack_sub->get_id() )->get_status() );
		$this->assertSame( 'active', wcs_get_subscription( $foreign_sub->get_id() )->get_status(), 'Another server\'s pack is untouched.' );
	}

	public function test_a_pending_cancel_server_puts_the_pack_in_pending_cancel_and_reactivation_restores_it(): void {
		list( , $server_sub, , $pack_sub ) = $this->server_and_pack();
		$server_sub->set_requires_manual_renewal( true );
		$pack_sub->set_requires_manual_renewal( true );
		foreach ( array( $server_sub, $pack_sub ) as $sub ) {
			$sub->update_dates( array( 'next_payment' => gmdate( 'Y-m-d H:i:s', time() + 30 * DAY_IN_SECONDS ) ) );
			$sub->save();
		}
		$server_sub->update_status( 'pending-cancel' );
		$fresh = wcs_get_subscription( $pack_sub->get_id() );
		$this->assertSame( 'pending-cancel', $fresh->get_status() );
		$this->assertSame( $server_sub->get_id(), (int) $fresh->get_meta( DiskCascade::MARKER ) );
		wcs_get_subscription( $server_sub->get_id() )->update_status( 'active' );
		$this->assertSame( 'active', wcs_get_subscription( $pack_sub->get_id() )->get_status() );
	}

	public function test_a_subscription_with_other_lines_is_left_alone_with_a_note(): void {
		list( , $server_sub, , $pack_sub ) = $this->server_and_pack();
		$pack_sub->add_product( $this->service_product( 'fake' ), 1, array( 'subtotal' => '3', 'total' => '3' ) );
		$pack_sub->save();
		$this->mails = array();
		$server_sub->update_status( 'cancelled' );
		$this->assertSame( 'active', wcs_get_subscription( $pack_sub->get_id() )->get_status() );
		$this->assertCount( 1, $this->mails );
	}

	public function test_a_deleted_server_ends_its_packs(): void {
		$this->on();
		$server = $this->ready_vm();
		$pack   = $this->pack( $server, 2 );
		$this->assertTrue( $this->fresh( $server )->transition( 'deleted', 'test' ) );
		do_action( 'beltoft_services_state_changed', $this->fresh( $server ), 'ready', 'deleted' );
		$this->assertSame( 'deleted', $this->fresh( $pack )->get( 'state' ) );
	}

	// -- The card --------------------------------------------------------------------------------

	public function test_the_card_offers_the_packs_with_their_prices_when_the_site_has_the_product(): void {
		$this->on( array( 'snapshot_free_slots' => 1, 'snapshot_max_slots' => 10 ) );
		$server = $this->ready_vm();
		$this->product();
		$this->as_owner( $server );

		$panel = Panels::snapshots( $this->fresh( $server ) );
		$this->assertCount( 2, $panel['buy'] );
		$this->assertSame( array( 2, 5 ), array_column( $panel['buy'], 'slots' ) );
		$this->assertStringContainsString( '2.00', $panel['buy'][0]['price'] );
		$this->assertStringContainsString( '4.00', $panel['buy'][1]['price'] );
		$this->assertStringContainsString( 'a month', $panel['buy'][0]['price'] );
		$this->assertStringContainsString( 'beltoft_services_snapshot_for=' . $server->license()['id'], $panel['buy'][0]['url'] );
		$this->assertSame( 30, $panel['buy'][0]['days'] );

		$html = $this->render_card( $this->fresh( $server ) );
		$this->assertStringContainsString( 'Add 2 snapshots', $html );
		$this->assertStringContainsString( 'Add 5 snapshots', $html );
		$this->assertStringContainsString( 'excluding VAT', $html );
		$this->assertStringContainsString( '0 of 1 used', preg_replace( '/\s+/', ' ', wp_strip_all_tags( $html ) ) );
	}

	public function test_the_card_has_no_buy_links_without_the_product_or_when_a_pack_would_not_fit(): void {
		$this->on( array( 'snapshot_free_slots' => 1, 'snapshot_max_slots' => 4 ) );
		$server = $this->ready_vm();
		$this->as_owner( $server );
		$this->assertSame( array(), Panels::snapshots( $this->fresh( $server ) )['buy'], 'No product on the site.' );
		$this->assertStringNotContainsString( 'data-snapshot-add', $this->render_card( $this->fresh( $server ) ) );

		$this->product();
		$panel = Panels::snapshots( $this->fresh( $server ) );
		$this->assertSame( array( 2 ), array_column( $panel['buy'], 'slots' ), 'The pack of 5 would go over the maximum of 4.' );

		$this->pack( $server, 2 );
		$this->assertSame( array(), Panels::snapshots( $this->fresh( $server ) )['buy'], 'No room left.' );
	}

	public function test_the_slot_line_shows_free_and_added_slots_and_the_days(): void {
		$this->on( array( 'snapshot_free_slots' => 1 ) );
		$server = $this->ready_vm();
		$this->pack( $server, 2 );
		$html = preg_replace( '/\s+/', ' ', wp_strip_all_tags( $this->render_card( $this->fresh( $server ) ) ) );
		$this->assertStringContainsString( '0 of 3 used (1 included, 2 added)', $html );
		$this->assertStringContainsString( 'Free snapshots are kept 7 days.', $html );
		$this->assertStringContainsString( 'With added slots, snapshots are kept 30 days.', $html );
	}

	public function test_a_card_without_added_slots_or_the_product_says_nothing_of_packs(): void {
		$this->on( array( 'snapshot_free_slots' => 2 ) );
		$server = $this->ready_vm();
		$html   = preg_replace( '/\s+/', ' ', wp_strip_all_tags( $this->render_card( $this->fresh( $server ) ) ) );
		$this->assertStringContainsString( '0 of 2 used', $html );
		$this->assertStringNotContainsString( 'included,', $html );
		$this->assertStringNotContainsString( 'With added slots', $html );
	}

	private function render_card( Resource $server ): string {
		ob_start();
		Panels::account( $server );

		return (string) ob_get_clean();
	}

	// -- Emails and the thank-you page -----------------------------------------------------------

	public function test_the_ready_email_says_how_many_slots_were_added(): void {
		$this->on();
		$server = $this->ready_vm();
		$pack   = $this->pack( $server, 5 );
		$lines  = apply_filters( 'beltoft_services_email_details', array(), 'beltoft_services_ready', $pack );
		$this->assertSame( '+5', $lines['Snapshot slots'] );
		$this->assertStringContainsString( 'kept 30 days', $lines['Kept'] );
		$this->assertArrayNotHasKey( 'Extra disk', $lines );
	}

	public function test_the_thank_you_row_has_the_slots_and_the_server_link(): void {
		$this->on();
		$server = $this->ready_vm();
		$pack   = $this->pack( $server, 2 );
		$row    = \BeltoftServices\ThankYou::row( $pack );
		$this->assertSame( 2, $row['snapshot']['slots'] );
		$this->assertSame( 'snapshot', $row['kind'] );
		$this->assertStringContainsString( '#beltoft-service-' . $server->id(), $row['url'] );
	}

	// -- Stock -----------------------------------------------------------------------------------

	public function test_stock_has_one_list_of_add_on_drivers(): void {
		$this->assertSame( array( 'proxmox-disk', 'proxmox-snapshots' ), Stock::addon_drivers() );
		$this->assertSame( array( 'proxmox', 'proxmox-disk', 'proxmox-snapshots' ), Stock::drivers() );
	}

	public function test_a_pack_is_never_out_of_stock_for_lack_of_capacity(): void {
		$this->settings( array( 'disk_promise_percent' => 1, 'disk_reserve_gb' => 0 ) );
		$product = $this->product();
		$two     = $this->variation( $product, 2 );
		$this->assertTrue( Stock::available( $two ) );
		$this->assertTrue( Stock::filter( true, $two ) );
	}
}

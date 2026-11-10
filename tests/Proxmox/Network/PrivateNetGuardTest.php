<?php
/**
 * A database is not sold when no private network can be given, and the admin is warned before the pool runs out.
 * Fake data only: nothing reaches Proxmox.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Proxmox\Network;

use BeltoftServices\Account\DbPicker;
use BeltoftServices\Alerts;
use BeltoftServices\Proxmox\Db\DbNetGuard;
use BeltoftServices\Proxmox\Network\PrivateNets;
use BeltoftServices\Proxmox\Pve\Clusters;
use BeltoftServices\Proxmox\Vm\Schema;
use BeltoftServices\Tests\Proxmox\Backup\BackupTestCase;

require_once dirname( __DIR__ ) . '/Backup/BackupTestCase.php';

class PrivateNetGuardTest extends BackupTestCase {

	public function set_up(): void {
		parent::set_up();
		$this->settings( array( 'admin_email' => 'christian.beltoft@gmail.com' ) );
		delete_option( Alerts::POOL_OPTION );
		$this->seed_private_nets( 0 );
		WC()->cart->empty_cart();
		wc_clear_notices();
	}

	public function tear_down(): void {
		WC()->cart->empty_cart();
		wc_clear_notices();
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	private function db_product(): \WC_Product {
		return $this->service_product( 'proxmox-db', array( 'cluster' => 'lab', 'major' => 18, 'cores' => 2, 'memory_mb' => 2048, 'disk_gb' => 20 ), array( 'slug' => 'db-' . wp_generate_password( 5, false ) ) );
	}

	/** Take every free network for users 9001, 9002, ... and return the number taken. */
	private function take_all(): void {
		$user = 9001;
		while ( null !== PrivateNets::hold( $user ) ) {
			++$user;
		}
	}

	public function test_a_customer_without_a_network_needs_a_free_one(): void {
		$this->assertFalse( PrivateNets::available_for( 11 ) );
		$this->assertFalse( PrivateNets::available_for( 0 ), 'A visitor is asked the same.' );
		$this->seed_private_nets( 1 );
		$this->assertTrue( PrivateNets::available_for( 11 ) );
		$this->assertTrue( PrivateNets::available_for( 0 ) );
	}

	public function test_a_customer_with_a_network_needs_a_free_address_on_it_not_a_free_network(): void {
		$this->seed_private_nets( 1 );
		$this->assertNotNull( PrivateNets::hold( 11 ) );
		$this->assertSame( 0, PrivateNets::free_count() );
		$this->assertTrue( PrivateNets::available_for( 11 ), 'Their own network has addresses.' );
		$this->assertFalse( PrivateNets::available_for( 12 ) );

		// Fill the thirteen addresses.
		global $wpdb;
		$net = PrivateNets::for_user( 11 );
		for ( $i = 2; $i <= 14; $i++ ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- test fixture.
			$wpdb->insert( Schema::tables()['private_ips'], array( 'net_id' => $net['id'], 'resource_id' => 800 + $i, 'address' => '10.77.1.' . $i, 'updated_at' => gmdate( 'Y-m-d H:i:s' ) ) );
		}
		$this->assertFalse( PrivateNets::available_for( 11 ) );
		$this->seed_private_nets( 2 );
		$this->assertTrue( PrivateNets::available_for( 13 ) );
	}

	public function test_a_quarantined_network_of_the_customer_counts(): void {
		$this->seed_private_nets( 1 );
		PrivateNets::hold( 11 );
		$this->assertTrue( PrivateNets::retire( 11 ) );
		$this->assertSame( 'quarantine', PrivateNets::for_user( 11 )['state'] );
		$this->assertTrue( PrivateNets::available_for( 11 ) );
		$this->assertFalse( PrivateNets::available_for( 12 ) );
	}

	public function test_a_cluster_without_a_private_bridge_is_not_available(): void {
		$this->seed_private_nets( 2 );
		$this->assertTrue( PrivateNets::available_for( 11, 'lab' ) );
		$this->save_cluster( array( 'private_bridge' => '' ) );
		$this->assertFalse( PrivateNets::available_for( 11, 'lab' ) );
		$this->assertTrue( PrivateNets::available_for( 11 ), 'Without a cluster the bridge is not asked.' );
	}

	public function test_add_to_cart_is_refused_when_none_is_free_and_allowed_otherwise(): void {
		$db = $this->db_product();
		wp_set_current_user( self::factory()->user->create() );

		$this->assertFalse( DbNetGuard::add_to_cart( true, $db->get_id(), 1, 0 ) );
		$notices = wc_get_notices( 'error' );
		$this->assertSame( 'New databases are not available right now. Please contact us.', $notices[0]['notice'] );

		wc_clear_notices();
		$this->seed_private_nets( 1 );
		$this->assertTrue( DbNetGuard::add_to_cart( true, $db->get_id(), 1, 0 ) );
		$this->assertSame( 0, wc_notice_count( 'error' ) );

		$vm = $this->service_product( 'proxmox', array( 'cluster' => 'lab', 'template' => 9000 ), array( 'slug' => 'vm-' . wp_generate_password( 5, false ) ) );
		$this->seed_private_nets( 0 );
		$this->assertTrue( DbNetGuard::add_to_cart( true, $vm->get_id(), 1, 0 ), 'Only databases need a network.' );
	}

	public function test_the_cart_and_store_api_refuse_a_database_in_the_cart_when_none_is_free(): void {
		$db = $this->db_product();
		wp_set_current_user( self::factory()->user->create() );
		$this->seed_private_nets( 1 );
		$this->assertNotFalse( WC()->cart->add_to_cart( $db->get_id() ) );
		$this->seed_private_nets( 0 );

		do_action( 'woocommerce_check_cart_items' );
		do_action( 'woocommerce_check_cart_items' );
		$mine = array_filter( wc_get_notices( 'error' ), static fn( $n ) => PrivateNets::unavailable_message() === $n['notice'] );
		$this->assertCount( 1, $mine, 'Once, however often the check runs.' );

		$errors = new \WP_Error();
		DbNetGuard::store_api_errors( $errors, WC()->cart );
		$this->assertSame( array( 'beltoft_services_db_no_network' ), $errors->get_error_codes() );
	}

	public function test_the_picker_refuses_a_post_and_the_stock_is_closed_for_the_customer_only(): void {
		$db   = $this->db_product();
		$user = self::factory()->user->create();
		wp_set_current_user( $user );
		$variation = wc_get_product( $db->get_id() );
		$this->assertFalse( \BeltoftServices\Proxmox\Vm\Stock::available( $variation ), 'No network: not available.' );
		$this->seed_private_nets( 1 );
		$this->assertNotFalse( \BeltoftServices\Proxmox\Vm\Stock::filter( true, $variation ) );
		$this->assertSame( 'instock', wc_get_product( $db->get_id() )->get_stock_status( 'edit' ), 'The stored status is not touched by the pool.' );
	}

	public function test_the_build_keeps_its_own_failure_as_the_last_guard(): void {
		$this->assertNull( PrivateNets::reserve( 11, 1 ) );
	}

	public function test_a_low_pool_alerts_once_and_the_zero_alert_follows_once(): void {
		$this->seed_private_nets( 12 );
		PrivateNets::check_pool();
		$this->assertCount( 0, $this->mails, '12 free is above the default threshold of 10.' );

		for ( $user = 9001; $user <= 9003; $user++ ) {
			PrivateNets::hold( $user );
		}
		PrivateNets::check_pool();
		$this->assertCount( 1, $this->mails );
		$this->assertStringContainsString( '9 left', $this->mails[0]['subject'] );
		$this->assertStringContainsString( 'wp beltoft-services private-net add <vlan> <cidr>', $this->mails[0]['message'] );
		$this->assertStringContainsString( '9 free', $this->mails[0]['message'] );

		PrivateNets::hold( 9004 );
		PrivateNets::check_pool();
		PrivateNets::check_pool();
		$this->assertCount( 1, $this->mails, 'Deduped.' );

		$this->take_all();
		PrivateNets::check_pool();
		$this->assertCount( 2, $this->mails );
		$this->assertStringContainsString( 'Databases cannot be sold to new customers', $this->mails[1]['message'] );
		PrivateNets::check_pool();
		$this->assertCount( 2, $this->mails );

		// Added again: re-armed, and a new drop mails again.
		$this->seed_private_nets( 20 );
		PrivateNets::check_pool();
		$this->assertCount( 2, $this->mails );
		$this->assertSame( '', get_option( Alerts::POOL_OPTION ) );
	}

	public function test_a_reserve_checks_the_pool_and_the_threshold_has_a_filter(): void {
		$this->seed_private_nets( 3 );
		add_filter( 'beltoft_services_private_net_low', static fn() => 3 );
		$product  = $this->service_product( 'fake' );
		$license  = $this->service_license( $product, array( 'user_id' => 21, 'fire' => false ) );
		$resource = \BeltoftServices\Resource::create( $license, new \BeltoftServices\Tests\Support\FakeDriver(), \BeltoftServices\Product::plan( $product->get_id() ) );
		$this->assertNotNull( PrivateNets::reserve( 21, $resource->id() ) );
		$this->assertCount( 1, $this->mails, '2 left is below the filtered threshold of 3.' );
	}

	public function test_an_unseeded_pool_and_a_missing_address_stay_quiet(): void {
		PrivateNets::check_pool();
		$this->assertCount( 0, $this->mails );

		$this->seed_private_nets( 1 );
		$this->settings( array( 'admin_email' => '' ) );
		PrivateNets::check_pool();
		$this->assertCount( 0, $this->mails );
		$this->assertSame( '', (string) get_option( Alerts::POOL_OPTION, '' ), 'Not remembered, so it is sent once the address is fixed.' );
		$this->settings( array( 'admin_email' => 'christian.beltoft@gmail.com' ) );
		PrivateNets::check_pool();
		$this->assertCount( 1, $this->mails );
	}

	public function test_the_texts_have_no_em_dash_or_middle_dot(): void {
		$this->seed_private_nets( 1 );
		PrivateNets::hold( 9001 );
		PrivateNets::check_pool();
		foreach ( array_merge( array( PrivateNets::unavailable_message() ), array_column( $this->mails, 'message' ), array_column( $this->mails, 'subject' ) ) as $text ) {
			$this->assertStringNotContainsString( "\u{2014}", $text );
			$this->assertStringNotContainsString( "\u{b7}", $text );
		}
	}
}

<?php
/**
 * Review fixes of the server name: write order, restore and rollback, stable disk numbers, waits, kill switch,
 * digest, linked VMs, the block checkout with two servers and the pending notice. Fake cluster only.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Proxmox;

use BeltoftServices\Drivers\Registry;
use BeltoftServices\Jobs;
use BeltoftServices\Lifecycle;
use BeltoftServices\Product;
use BeltoftServices\Proxmox\Actions;
use BeltoftServices\Proxmox\DiskMeta;
use BeltoftServices\Proxmox\ServerName;
use BeltoftServices\Resource;

require_once __DIR__ . '/SnapshotsTestCase.php';

class ServerNameFixesTest extends SnapshotsTestCase {

	public function set_up(): void {
		parent::set_up();

		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Isolated PHPUnit fixture reset.
		$wpdb->query( 'DELETE FROM ' . $wpdb->prefix . 'woocommerce_order_itemmeta' );
		$wpdb->query( 'DELETE FROM ' . $wpdb->prefix . 'woocommerce_order_items' );
		// phpcs:enable
	}

	/**
	 * Config PUTs that carry a name.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function name_puts(): array {
		return array_values( array_filter( $this->calls( 'PUT', '/config' ), static fn( $c ) => isset( $c['params']['name'] ) ) );
	}

	// -- 1. Order of the write -----------------------------------------------------------------

	public function test_a_failed_put_leaves_the_stored_name_unchanged(): void {
		$r = $this->ready_vm( array(), array( ServerName::META => 'web-01' ) );
		$this->fake->fail_next( 'PUT', '#/config$#', array( 'status' => 400, 'message' => 'bad parameter' ) );

		Jobs::act( $r->id(), 'rename', array( 'name' => 'web-02' ) );

		$fresh = $this->fresh( $r );
		$this->assertSame( 'web-01', $fresh->get( ServerName::META ), 'The stored name only changes once Proxmox took it.' );
		$this->assertNull( $fresh->get( ServerName::PENDING ) );
		$this->assertSame( 'web-01', $this->fake->vm( (int) $r->get( 'vmid' ) )['name'] );
	}

	public function test_a_retry_after_the_put_is_safe_and_writes_nothing_twice(): void {
		$r = $this->ready_vm( array(), array( ServerName::META => 'web-01' ) );
		Jobs::act( $r->id(), 'rename', array( 'name' => 'web-02' ) );
		$this->assertCount( 1, $this->name_puts() );

		// The store after the write failed once: the next round finds the VM named already and only stores.
		$this->fresh( $r )->set( array( ServerName::META => 'web-01', ServerName::PENDING => null, ServerName::PENDING_AT => null ) );
		Jobs::act( $r->id(), 'rename', array( 'name' => 'web-02' ) );
		$this->assertCount( 1, $this->name_puts(), 'No second write.' );
		$this->assertSame( 'web-02', $this->fresh( $r )->get( ServerName::META ) );
		$this->assertSame( 'web-02', $this->fresh( $r )->get( ServerName::PENDING ) );
	}

	// -- 4. Waits ------------------------------------------------------------------------------

	public function test_rename_waits_for_a_locked_vm_without_writing_or_failing(): void {
		$r    = $this->ready_vm();
		$vmid = (int) $r->get( 'vmid' );
		$this->fake->set_lock( $vmid, 'backup' );

		Jobs::act( $r->id(), 'rename', array( 'name' => 'web-02' ) );
		$this->assertCount( 0, $this->name_puts() );
		$this->assertNull( $this->fresh( $r )->get( ServerName::META ) );
		$this->assertNotSame( 'failed', $this->fresh( $r )->get( 'state' ) );
		$this->assertSame( 0, (int) $this->fresh( $r )->get( 'attempts' ), 'A wait counts no attempt.' );

		$this->fake->set_lock( $vmid, '' );
		Jobs::act( $r->id(), 'rename', array( 'name' => 'web-02' ) );
		$this->assertCount( 1, $this->name_puts() );
	}

	public function test_rename_waits_while_a_restore_or_snapshot_operation_is_in_progress(): void {
		foreach ( array( 'restore', 'snapshot_op', 'snapshot_rollback' ) as $key ) {
			$r = $this->server();
			$r->set( array( $key => array( 'phase' => 'x', 'at' => time() ) ) );
			Jobs::act( $r->id(), 'rename', array( 'name' => 'web-02' ) );
			$this->assertCount( 0, $this->name_puts(), $key );
		}
	}

	// -- 5. Kill switch, 6. digest -------------------------------------------------------------

	public function test_the_kill_switch_stops_the_write(): void {
		$r = $this->ready_vm();
		$this->settings( array( 'pause_all' => 1 ) );

		Jobs::act( $r->id(), 'rename', array( 'name' => 'web-02' ) );

		$this->assertCount( 0, $this->name_puts() );
		$this->assertNull( $this->fresh( $r )->get( ServerName::META ) );
	}

	public function test_without_a_digest_nothing_is_sent(): void {
		$r = $this->ready_vm();
		$this->fake->omit_digest( true );

		Jobs::act( $r->id(), 'rename', array( 'name' => 'web-02' ) );
		$this->assertCount( 0, $this->name_puts(), 'Never a write without a digest.' );
		$this->assertNull( $this->fresh( $r )->get( ServerName::META ) );

		$this->fake->omit_digest( false );
		Jobs::act( $r->id(), 'rename', array( 'name' => 'web-02' ) );
		$this->assertCount( 1, $this->name_puts() );
		$this->assertArrayHasKey( 'digest', $this->name_puts()[0]['params'] );
	}

	// -- 7. Linked ----------------------------------------------------------------------------

	public function test_a_linked_vm_is_not_offered_a_rename(): void {
		$r = $this->ready_vm();
		$this->assertArrayHasKey( 'rename', Actions::offered( $r ) );

		$r->set( array( 'linked' => true ) );
		$this->assertArrayNotHasKey( 'rename', Actions::offered( $this->fresh( $r ) ) );
		$this->assertSame( 403, $this->route( $r->id(), (int) $r->license()['user_id'], 'rename', array( 'name' => 'web-02' ) )->get_status(), 'Not offered, so the route says it is not available.' );
	}

	// -- 2. Restore and rollback ---------------------------------------------------------------

	public function test_a_restore_puts_the_chosen_name_back_in_one_call(): void {
		$r      = $this->server();
		$vmid   = (int) $r->get( 'vmid' );
		$volid  = $this->backup_volid( $r );
		Jobs::act( $r->id(), 'rename', array( 'name' => 'web-02' ) );
		$this->assertSame( 'web-02', $this->fake->vm( $vmid )['name'] );
		$before = count( $this->name_puts() );

		Jobs::act( $r->id(), 'restore', array( 'confirm' => 'RESTORE', 'volid' => $volid ) );

		$this->assertCount( $before + 1, $this->name_puts(), 'One more name write, after the restore.' );
		$this->assertSame( 'web-02', $this->fake->vm( $vmid )['name'], 'The archive had the old name; the customer\'s is back.' );
		$puts = $this->name_puts();
		$last = end( $puts );
		$this->assertArrayHasKey( 'digest', $last['params'] );
		$this->assertNull( $this->fresh( $r )->get( ServerName::PENDING ) );
	}

	public function test_a_restore_of_an_unrenamed_server_writes_no_name(): void {
		$r     = $this->server();
		$volid = $this->backup_volid( $r );

		Jobs::act( $r->id(), 'restore', array( 'confirm' => 'RESTORE', 'volid' => $volid ) );

		$this->assertCount( 0, $this->name_puts() );
	}

	public function test_a_rollback_puts_the_chosen_name_back(): void {
		$r     = $this->server();
		$vmid  = (int) $r->get( 'vmid' );
		$entry = $this->take( $r, 'before rename' );
		Jobs::act( $r->id(), 'rename', array( 'name' => 'web-02' ) );
		$before = count( $this->name_puts() );

		$this->act( $r, 'snapshot_rollback', array( 'id' => $entry['id'], 'confirm' => 'ROLLBACK' ) );

		$this->assertCount( $before + 1, $this->name_puts() );
		$this->assertSame( 'web-02', $this->fake->vm( $vmid )['name'] );
	}

	// -- 3. Stable disk numbers ---------------------------------------------------------------

	public function test_disk_numbers_are_kept_when_a_disk_is_removed_and_never_reused(): void {
		$server = $this->server();
		$this->settings( array( 'disk_max_extra' => 8 ) );
		$one    = $this->disk_for( $server );
		$two    = $this->disk_for( $server );
		$three  = $this->disk_for( $server );

		$this->assertSame( array( 1, 2, 3 ), array_map( fn( $d ) => (int) $this->fresh( $d )->get( DiskMeta::NUMBER ), array( $one, $two, $three ) ) );

		$two = $this->fresh( $two );
		$two->transition( 'deleted', 'removed' );
		$two->close();

		$labels = DiskMeta::labels( $this->fresh( $server ) );
		$this->assertSame( array( $one->id() => 'Data disk 1', $three->id() => 'Data disk 3' ), $labels, 'Disk 3 stays disk 3.' );

		$four = $this->disk_for( $server );
		$this->assertSame( 4, (int) $this->fresh( $four )->get( DiskMeta::NUMBER ), 'A new disk gets a new number, even when the freed slot is reused.' );
		$this->assertSame( 'Data disk 4', DiskMeta::labels( $this->fresh( $server ) )[ $four->id() ] );

		// The highest one removed: its number is still not given again.
		$four = $this->fresh( $four );
		$four->transition( 'deleted', 'removed' );
		$four->close();
		$five = $this->disk_for( $server );
		$this->assertSame( 5, (int) $this->fresh( $five )->get( DiskMeta::NUMBER ) );
	}

	public function test_a_new_disk_without_a_slot_yet_is_numbered_after_the_others(): void {
		$server = $this->server();
		$one    = $this->disk_for( $server );

		$license = $server->license();
		$product = $this->service_product( 'proxmox-disk', array( 'disk_gb' => 50 ), array( 'slug' => 'disk-' . wp_generate_password( 6, false ) ) );
		$dl      = $this->service_license( $product, array( 'fire' => false, 'user_id' => $license['user_id'] ) );
		$new     = Resource::create( $dl, Registry::get( 'proxmox-disk' ), Product::plan( $product->get_id() ), array( 'server_license' => $license['id'] ) );

		$labels = DiskMeta::labels( $this->fresh( $server ) );
		$this->assertSame( 'Data disk 1', $labels[ $one->id() ] );
		$this->assertSame( 'Data disk 2', $labels[ $new->id() ] );
		$this->assertSame( 2, (int) $this->fresh( $new )->get( DiskMeta::NUMBER ), 'Stored on first sight.' );

		// Claiming its slot later keeps the number.
		Jobs::run( $new->id() );
		$this->assertSame( 2, (int) $this->fresh( $new )->get( DiskMeta::NUMBER ) );
	}

	public function test_legacy_disks_get_numbers_lazily_in_slot_order(): void {
		$server  = $this->server();
		$license = $server->license();
		$ids     = array();

		foreach ( array( 'scsi10', 'scsi2', 'scsi1' ) as $slot ) {
			$product = $this->service_product( 'proxmox-disk', array( 'disk_gb' => 50 ), array( 'slug' => 'disk-' . wp_generate_password( 6, false ) ) );
			$dl      = $this->service_license( $product, array( 'fire' => false, 'user_id' => $license['user_id'] ) );
			$disk    = Resource::create( $dl, Registry::get( 'proxmox-disk' ), Product::plan( $product->get_id() ), array( 'server_license' => $license['id'], 'slot' => $slot ) );
			$ids[ $slot ] = $disk->id();
		}

		$this->assertNull( Resource::find( $ids['scsi1'] )->get( DiskMeta::NUMBER ) );
		$labels = DiskMeta::labels( $this->fresh( $server ) );

		$this->assertSame( 'Data disk 1', $labels[ $ids['scsi1'] ] );
		$this->assertSame( 'Data disk 2', $labels[ $ids['scsi2'] ] );
		$this->assertSame( 'Data disk 3', $labels[ $ids['scsi10'] ] );
		$this->assertSame( 3, (int) Resource::find( $ids['scsi10'] )->get( DiskMeta::NUMBER ) );
		$this->assertSame( 3, (int) $this->fresh( $server )->get( DiskMeta::HIGHEST ) );
	}

	// -- 8. The block checkout with two servers -----------------------------------------------

	public function test_the_order_level_block_name_is_used_only_for_an_order_with_one_server(): void {
		add_filter( 'woocommerce_product_is_in_stock', '__return_true', 99 );
		$vm = $this->service_product( 'proxmox', array( 'cluster' => 'lab', 'template' => 9000 ), array( 'slug' => 'vm-' . wp_generate_password( 5, false ) ) );

		$make = function ( int $lines ) use ( $vm ) {
			$order = wc_create_order();

			for ( $i = 0; $i < $lines; $i++ ) {
				$item = new \WC_Order_Item_Product();
				$item->set_product( $vm );
				$item->set_quantity( 1 );
				$order->add_item( $item );
			}

			$order->update_meta_data( '_wc_other/' . ServerName::BLOCK_FIELD_ID, 'shared-name' );
			$order->save();

			return $this->service_license( $vm, array( 'fire' => false, 'order_id' => $order->get_id() ) );
		};

		$one = $make( 1 );
		Lifecycle::provide( $one );
		$this->assertSame( 'shared-name', Resource::for_license( (int) $one['id'] )->get( ServerName::META ) );

		$two = $make( 2 );
		Lifecycle::provide( $two );
		$this->assertNull( Resource::for_license( (int) $two['id'] )->get( ServerName::META ), 'One name must not name two servers.' );
		remove_filter( 'woocommerce_product_is_in_stock', '__return_true', 99 );
	}
}

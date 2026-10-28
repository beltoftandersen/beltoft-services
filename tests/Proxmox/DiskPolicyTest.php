<?php
/** Extra disk policies: a lapsed disk is detached with its data kept, a server's end ends its disks. @package BeltoftServices */

namespace BeltoftServices\Tests\Proxmox;

use BeltoftServices\Drivers\Registry;
use BeltoftServices\Jobs;
use BeltoftServices\Product;
use BeltoftServices\Proxmox\DiskCapacity;
use BeltoftServices\Resource;

require_once __DIR__ . '/BackupTestCase.php';

class DiskPolicyTest extends BackupTestCase {
	public function set_up(): void {
		parent::set_up();
		global $wpdb;
		// WordPress' test rollback reuses post IDs; WooCommerce's custom order-item tables persist between tests.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Isolated PHPUnit fixture reset.
		$wpdb->query( 'DELETE FROM ' . $wpdb->prefix . 'woocommerce_order_itemmeta' );
		$wpdb->query( 'DELETE FROM ' . $wpdb->prefix . 'woocommerce_order_items' );
		// phpcs:enable
	}

	private function disk_for( Resource $server ): Resource {
		$server_license = $server->license();
		$this->assertTrue( DiskCapacity::refresh( 'lab', 'node-a', 'disk-store', $this->fake ) );
		$product = $this->service_product( 'proxmox-disk', array( 'disk_gb' => 50 ), array( 'slug' => 'disk-' . wp_generate_password( 6, false ) ) );
		$license = $this->service_license( $product, array( 'fire' => false, 'user_id' => $server_license['user_id'] ) );
		$disk = Resource::create( $license, Registry::get( 'proxmox-disk' ), Product::plan( $product->get_id() ), array( 'server_license' => $server_license['id'] ) );
		$this->assertInstanceOf( Resource::class, $disk );
		return $disk;
	}

	private function ready_disk(): Resource {
		$disk = $this->disk_for( $this->ready_vm() );
		Jobs::run( $disk->id() );
		$disk = Resource::find( $disk->id() );
		$this->assertSame( 'ready', $disk->get( 'state' ), (string) $disk->get( 'last_error' ) );
		return $disk;
	}

	private function images(): array {
		return $this->fake->request( 'GET', '/nodes/node-a/storage/disk-store/content', array( 'content' => 'images', 'vmid' => 5000 ) );
	}

	private function config(): array {
		return $this->fake->request( 'GET', '/nodes/node-a/qemu/5000/config' );
	}

	public function test_pause_detaches_and_keeps_the_volume_and_resume_reattaches_the_same_volume(): void {
		$disk = $this->ready_disk();
		$volname = (string) $disk->get( 'volname' );
		$driver = Registry::get( 'proxmox-disk' );
		$paused = $driver->pause( $disk );
		$this->assertTrue( $paused->is_done(), $paused->error() );
		$this->assertTrue( $disk->set( array( 'detached' => array( 'volid' => 'disk-store:5000/' . $volname, 'slot' => 'scsi1' ) ) ) );
		$config = $this->config();
		$this->assertArrayNotHasKey( 'scsi1', $config );
		$this->assertContains( 'disk-store:5000/' . $volname, array_values( array_filter( $config, static fn( $k ) => 0 === strpos( (string) $k, 'unused' ), ARRAY_FILTER_USE_KEY ) ) );
		$this->assertCount( 1, $this->images(), 'The volume and its data stay on the storage.' );
		$this->assertCount( 0, $this->calls( 'DELETE', '.raw' ) );
		$again = $driver->pause( Resource::find( $disk->id() ) );
		$this->assertTrue( $again->is_done(), 'A repeat is idempotent: ' . $again->error() );
		$this->assertCount( 1, $this->images() );

		$resumed = $driver->resume( Resource::find( $disk->id() ) );
		$this->assertTrue( $resumed->is_done(), $resumed->error() );
		$config = $this->config();
		$this->assertStringContainsString( $volname, (string) $config['scsi1'] );
		$this->assertStringContainsString( 'backup=0', (string) $config['scsi1'] );
		$this->assertSame( array(), array_filter( array_keys( $config ), static fn( $k ) => 0 === strpos( (string) $k, 'unused' ) ), 'The unusedN entry is gone.' );
		$this->assertCount( 1, $this->images() );
		$this->assertCount( 1, $this->calls( 'POST', '/storage/disk-store/content' ), 'No second allocation.' );
		$this->assertTrue( $driver->resume( Resource::find( $disk->id() ) )->is_done(), 'Resume is idempotent.' );
	}

	public function test_pause_with_a_pending_hot_detach_retries_and_does_not_mark_detached(): void {
		$disk = $this->ready_disk();
		$this->fake->hold_detach( 5000, true );
		$result = Registry::get( 'proxmox-disk' )->pause( $disk );
		$this->assertTrue( $result->is_retry(), $result->error() );
		$this->assertStringContainsString( 'restarted', $result->error() );
		$this->assertCount( 1, $this->images() );
		$this->assertCount( 0, $this->calls( 'DELETE', '.raw' ) );
	}

	public function test_pause_leaves_a_linked_disk_attached(): void {
		$disk = $this->ready_disk();
		$this->assertTrue( $disk->set( array( 'linked' => true ) ) );
		$before = count( $this->calls( 'PUT', '/qemu/5000/config' ) );
		$result = Registry::get( 'proxmox-disk' )->pause( Resource::find( $disk->id() ) );
		$this->assertTrue( $result->is_done() );
		$this->assertCount( $before, $this->calls( 'PUT', '/qemu/5000/config' ) );
		$this->assertArrayHasKey( 'scsi1', $this->config() );
	}

	public function test_pause_of_a_never_attached_disk_touches_nothing(): void {
		$disk = $this->disk_for( $this->ready_vm() );
		$before = count( $this->calls( 'PUT', '/qemu/5000/config' ) );
		$this->assertTrue( Registry::get( 'proxmox-disk' )->pause( $disk )->is_done() );
		$this->assertCount( $before, $this->calls( 'PUT', '/qemu/5000/config' ) );
	}

	public function test_pause_refuses_when_the_server_belongs_to_another_customer(): void {
		$disk = $this->ready_disk();
		$other = $this->ready_vm();
		$this->assertNotSame( (int) $disk->license()['user_id'], (int) $other->license()['user_id'] );
		$this->assertGreaterThan( 0, (int) $other->license()['user_id'] );
		$this->assertTrue( $disk->set( array( 'server_license' => (int) $other->license()['id'] ) ) );
		$before = count( $this->calls( 'PUT', '/qemu/5000/config' ) );
		$result = Registry::get( 'proxmox-disk' )->pause( Resource::find( $disk->id() ) );
		$this->assertTrue( $result->is_failed() );
		$this->assertStringContainsString( 'belongs to another customer', $result->error() );
		$this->assertCount( $before, $this->calls( 'PUT', '/qemu/5000/config' ) );
	}

	public function test_a_paused_disk_is_shown_as_detached_with_its_data_kept(): void {
		$disk = $this->ready_disk();
		$server = Resource::for_license( (int) $disk->get( 'server_license' ) );
		$items = \BeltoftServices\Proxmox\DiskPanels::server( $server )['items'];
		$this->assertFalse( $items[0]['paused'] );
		$this->assertTrue( $disk->transition( 'paused', 'test' ) );
		$items = \BeltoftServices\Proxmox\DiskPanels::server( $server )['items'];
		$this->assertTrue( $items[0]['paused'] );
		ob_start();
		\BeltoftServices\Proxmox\DiskPanels::account( Resource::find( $disk->id() ) );
		$html = (string) ob_get_clean();
		$this->assertStringContainsString( 'This disk is detached from your server because its subscription is not active. Your data is kept until the disk is deleted.', $html );
		$this->assertStringNotContainsString( 'sudo mkfs', $html, 'A detached disk shows no format instructions.' );
	}

	// -- A server's end ends its disks ---------------------------------------------------------

	private function subscription( string $status = 'active', ?int $user = null ): \WC_Subscription {
		$sub = wcs_create_subscription( array( 'customer_id' => $user ?? self::factory()->user->create(), 'billing_period' => 'month', 'billing_interval' => 1, 'status' => $status ) );
		$this->assertInstanceOf( \WC_Subscription::class, $sub );
		// WooCommerce Subscriptions only reactivates a pending-cancel subscription with manual renewal and an end date ahead.
		$sub->set_requires_manual_renewal( true );
		$sub->update_dates( array( 'next_payment' => gmdate( 'Y-m-d H:i:s', time() + 30 * DAY_IN_SECONDS ) ) );
		$sub->save();
		// The license table is not rolled back with the posts, which reuse their ids: forget rows left by earlier tests.
		global $wpdb;
		$wpdb->delete( \BeltoftLicenses\Install::tables()['licenses'], array( 'subscription_id' => $sub->get_id() ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Test fixture reset.
		return $sub;
	}

	private function notes( \WC_Subscription $sub ): string {
		return implode( "\n", array_map( static fn( $n ) => $n->content, wc_get_order_notes( array( 'order_id' => $sub->get_id() ) ) ) );
	}

	/** @return array{0: Resource, 1: \WC_Subscription, 2: Resource, 3: \WC_Subscription} server, its subscription, disk, the disk's subscription */
	private function server_and_disk( ?Resource $server = null ): array {
		$server = $server ?? $this->ready_vm();
		$user = (int) $server->license()['user_id'];
		$server_sub = $this->subscription( 'active', $user );
		$this->assertTrue( \BeltoftLicenses\Licenses::update( (int) $server->license()['id'], array( 'subscription_id' => $server_sub->get_id() ) ) );
		$disk_product = $this->service_product( 'proxmox-disk', array( 'disk_gb' => 50 ), array( 'slug' => 'disk-' . wp_generate_password( 6, false ) ) );
		$disk_sub = $this->subscription( 'active', $user );
		$disk_sub->add_product( $disk_product, 1, array( 'subtotal' => '5', 'total' => '5' ) );
		$disk_sub->save();
		$this->assertTrue( $this->fake_ready() );
		$license = $this->service_license( $disk_product, array( 'fire' => false, 'user_id' => $user, 'subscription_id' => $disk_sub->get_id() ) );
		$disk = Resource::create( $license, Registry::get( 'proxmox-disk' ), Product::plan( $disk_product->get_id() ), array( 'server_license' => (int) $server->license()['id'] ) );
		$this->assertInstanceOf( Resource::class, $disk );
		return array( Resource::find( $server->id() ), $server_sub, $disk, $disk_sub );
	}

	private function fake_ready(): bool {
		return DiskCapacity::refresh( 'lab', 'node-a', 'disk-store', $this->fake );
	}

	public function test_server_pending_cancel_puts_its_disk_subscription_in_pending_cancel_with_notes(): void {
		list( , $server_sub, , $disk_sub ) = $this->server_and_disk();
		$other = $this->subscription();
		$server_sub->update_status( 'pending-cancel' );
		$this->assertSame( 'pending-cancel', wcs_get_subscription( $disk_sub->get_id() )->get_status() );
		$this->assertStringContainsString( 'Ended with its server', $this->notes( $disk_sub ) );
		$this->assertStringContainsString( 'ends with this server', $this->notes( $server_sub ) );
		$this->assertSame( 'active', wcs_get_subscription( $other->get_id() )->get_status(), 'An unrelated subscription is untouched.' );
	}

	public function test_server_cancel_cancels_its_disk_subscription_only(): void {
		list( , $server_sub, , $disk_sub ) = $this->server_and_disk();
		list( , , , $foreign_sub ) = $this->server_and_disk();
		$server_sub->update_status( 'cancelled' );
		$this->assertSame( 'cancelled', wcs_get_subscription( $disk_sub->get_id() )->get_status() );
		$this->assertStringContainsString( 'Cancelled with its server.', $this->notes( $disk_sub ) );
		$this->assertSame( 'active', wcs_get_subscription( $foreign_sub->get_id() )->get_status(), 'The disk of another server stays.' );
		$disk_sub->update_status( 'active' );
	}

	public function test_a_disk_subscription_with_other_lines_gets_a_note_and_an_alert_not_a_cancel(): void {
		list( , $server_sub, , $disk_sub ) = $this->server_and_disk();
		$disk_sub->add_product( $this->service_product( 'fake' ), 1, array( 'subtotal' => '3', 'total' => '3' ) );
		$disk_sub->save();
		$this->mails = array();
		$server_sub->update_status( 'cancelled' );
		$this->assertSame( 'active', wcs_get_subscription( $disk_sub->get_id() )->get_status() );
		$this->assertStringContainsString( 'also holds other lines', $this->notes( $disk_sub ) );
		$this->assertStringContainsString( 'also holds other lines', $this->notes( $server_sub ) );
		$this->assertCount( 1, $this->mails );
		$this->assertSame( 'admin@example.com', is_array( $this->mails[0]['to'] ) ? $this->mails[0]['to'][0] : $this->mails[0]['to'] );
	}

	public function test_an_already_ended_disk_subscription_is_skipped(): void {
		list( , $server_sub, , $disk_sub ) = $this->server_and_disk();
		$disk_sub->update_status( 'cancelled' );
		$before = $this->notes( $disk_sub );
		$server_sub->update_status( 'cancelled' );
		$this->assertSame( $before, $this->notes( $disk_sub ) );
	}

	public function test_deletion_warning_lists_the_extra_disks_of_a_server_only(): void {
		list( $server, , $disk ) = $this->server_and_disk();
		$this->assertTrue( $disk->set( array( 'size_gb' => 50 ) ) );
		$email = \BeltoftServices\Emails\Emails::get( 'beltoft_services_deletion_warning' );
		$this->mails = array();
		$this->assertTrue( $email->trigger( $server, array( 'deletes_at' => time() + DAY_IN_SECONDS ) ) );
		$this->assertStringContainsString( 'Extra disks deleted with the server: 50 GB', wp_strip_all_tags( (string) $this->mails[0]['message'] ) );
		$this->mails = array();
		$this->assertTrue( $email->trigger( $disk, array( 'deletes_at' => time() + DAY_IN_SECONDS ) ) );
		$this->assertStringNotContainsString( 'Extra disks deleted with the server', (string) $this->mails[0]['message'] );
	}

	public function test_server_delete_is_refused_while_a_disk_license_is_active(): void {
		list( $server, , $disk ) = $this->server_and_disk();
		$this->settings( array( 'deletion_mode' => 'on' ) );
		$this->mails = array();
		$calls = count( $this->fake->calls() );
		$result = Registry::get( 'proxmox' )->delete( $server );
		$this->assertTrue( $result->is_retry() );
		$this->assertStringContainsString( 'active extra disk cannot be deleted', $result->error() );
		$this->assertCount( 0, $this->calls( 'DELETE', '/qemu/5000' ), 'No VM delete.' );
		$this->assertCount( 1, $this->mails, 'One admin alert.' );
		$this->assertTrue( \BeltoftLicenses\Licenses::update( (int) $disk->license()['id'], array( 'status' => 'revoked' ) ) );
		$next = Registry::get( 'proxmox' )->delete( Resource::find( $server->id() ) );
		$this->assertStringNotContainsString( 'active extra disk', (string) $next->error() );
	}

	public function test_pause_detaches_a_drive_that_holds_the_volume_even_without_an_attach_step(): void {
		$disk = $this->ready_disk();
		$this->assertTrue( $disk->set( array( 'steps' => array( 'server', 'slot' ) ) ) );
		$result = Registry::get( 'proxmox-disk' )->pause( Resource::find( $disk->id() ) );
		$this->assertTrue( $result->is_done(), $result->error() );
		$this->assertArrayNotHasKey( 'scsi1', $this->config() );
		$this->assertCount( 1, $this->images() );
		$this->assertArrayHasKey( 'detached', $result->meta() );
	}

	public function test_server_pending_cancel_then_reactivation_restores_the_disk_subscription(): void {
		list( , $server_sub, , $disk_sub ) = $this->server_and_disk();
		$server_sub->update_status( 'pending-cancel' );
		$fresh = wcs_get_subscription( $disk_sub->get_id() );
		$this->assertSame( 'pending-cancel', $fresh->get_status() );
		$this->assertSame( $server_sub->get_id(), (int) $fresh->get_meta( \BeltoftServices\Proxmox\DiskCascade::MARKER ) );
		wcs_get_subscription( $server_sub->get_id() )->update_status( 'active' );
		$back = wcs_get_subscription( $disk_sub->get_id() );
		$this->assertSame( 'active', $back->get_status() );
		$this->assertSame( '', (string) $back->get_meta( \BeltoftServices\Proxmox\DiskCascade::MARKER ) );
		$this->assertSame( 0, (int) $back->get_time( 'end' ) );
		$this->assertStringContainsString( 'Active again with its server', $this->notes( $disk_sub ) );
		$this->assertStringContainsString( 'is active again with this server', $this->notes( $server_sub ) );
	}

	public function test_reactivation_leaves_a_disk_without_the_marker_and_a_cancelled_one_alone(): void {
		list( , $server_sub, , $own_sub ) = $this->server_and_disk();
		$server_sub->update_status( 'pending-cancel' );
		$own_sub = wcs_get_subscription( $own_sub->get_id() );
		$own_sub->delete_meta_data( \BeltoftServices\Proxmox\DiskCascade::MARKER );
		$own_sub->save();
		wcs_get_subscription( $server_sub->get_id() )->update_status( 'active' );
		$this->assertSame( 'pending-cancel', wcs_get_subscription( $own_sub->get_id() )->get_status(), 'No marker: the customer ended it themselves.' );

		list( , $server_sub2, , $gone ) = $this->server_and_disk();
		$server_sub2->update_status( 'cancelled' );
		$this->assertSame( 'cancelled', wcs_get_subscription( $gone->get_id() )->get_status() );
		do_action( 'woocommerce_subscription_status_active', wcs_get_subscription( $server_sub2->get_id() ) );
		$this->assertSame( 'cancelled', wcs_get_subscription( $gone->get_id() )->get_status(), 'Cancelled stays cancelled.' );
	}
}

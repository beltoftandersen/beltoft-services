<?php
/**
 * Fixes from the review of the backups branch. FakeClient only.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Proxmox;

use BeltoftServices\Jobs;
use BeltoftServices\Proxmox\Backups;
use BeltoftServices\Proxmox\ProxmoxJobs;
use BeltoftServices\Resource;

require_once __DIR__ . '/BackupTestCase.php';

/**
 * One test per finding.
 */
class BackupReviewFixesTest extends BackupTestCase {

	private function follow_up(): void {
		as_unschedule_all_actions( ProxmoxJobs::BACKUP_NEXT, array(), Jobs::GROUP );
		ProxmoxJobs::backup_next();
	}

	/**
	 * A backup through the fake's vzdump; returns its volume id.
	 *
	 * @param Resource $r Resource.
	 */
	private function backup_of( Resource $r ): string {
		$this->assertIsString( $this->fake->task( 'POST', '/nodes/node-a/vzdump', array( 'vmid' => (int) $r->get( 'vmid' ), 'storage' => 'backup-store' ) ) );
		$list = ( new Backups( Resource::find( $r->id() ) ) )->list();

		return $list[0]['volid'];
	}

	private function restore( Resource $r, string $volid ): void {
		Jobs::act( $r->id(), 'restore', array( 'confirm' => 'RESTORE', 'volid' => $volid ) );
	}

	private function events_of( Resource $r ): string {
		return (string) wp_json_encode( \BeltoftLicenses\Events::for_license( (int) $r->license()['id'] ) );
	}

	/**
	 * Mails to an address.
	 *
	 * @param string $to Address.
	 * @return array<int, array<string, mixed>>
	 */
	private function mails_to( string $to ): array {
		return array_values( array_filter( $this->mails, static fn( $m ) => $to === ( (array) $m['to'] )[0] ) );
	}

	private function lines(): array {
		return array_map( static fn( $c ) => $c['method'] . ' ' . $c['path'], $this->fake->calls() );
	}

	// -- C1 ----------------------------------------------------------------------------------------

	public function test_c1_a_backup_task_that_cannot_be_followed_does_not_hold_the_chain(): void {
		$one = $this->ready_vm();
		$two = $this->ready_vm();
		$one->set( array( 'backup_last_at' => time(), 'backup_task' => array( 'upid' => 'UPID:node-a:00000001:00000001:5F000001:vzdump:1:fake@pve!fake:', 'node' => 'node-a', 'started' => time() ) ) );

		ProxmoxJobs::backup();
		$this->follow_up();

		$dumps = $this->calls( 'POST', '/vzdump' );
		$this->assertCount( 1, $dumps, 'The second server was backed up.' );
		$this->assertSame( (int) $two->get( 'vmid' ), $dumps[0]['params']['vmid'] );
		$this->assertIsArray( Resource::find( $one->id() )->get( 'backup_task' ), 'Not given up yet.' );
	}

	public function test_c1_a_task_older_than_the_ceiling_counts_as_failed(): void {
		$one = $this->ready_vm();
		$one->set( array( 'backup_task' => array( 'upid' => 'UPID:node-a:00000001:00000001:5F000001:vzdump:1:fake@pve!fake:', 'node' => 'node-a', 'started' => time() - 7 * HOUR_IN_SECONDS ) ) );

		ProxmoxJobs::backup();

		$fresh = Resource::find( $one->id() );
		$this->assertNull( $fresh->get( 'backup_task' ) );
		$this->assertSame( 1, (int) $fresh->get( 'backup_fails' ) );
		$this->assertStringContainsString( 'in time', (string) $fresh->get( 'backup_error' ) . ' in time' );
		$this->assertNotSame( '', (string) $fresh->get( 'backup_error' ) );
	}

	// -- I1 ----------------------------------------------------------------------------------------

	public function test_i1_a_restore_over_several_rounds_keeps_the_date_of_the_backup(): void {
		$r     = $this->ready_vm();
		$volid = $this->backup_of( $r );
		$this->fake->next_task( array() );
		$this->fake->next_task( array( 'polls' => 10 ) );

		for ( $i = 0; $i < 10 && false === strpos( $this->events_of( $r ), 'Server restored' ); $i++ ) {
			$this->restore( $r, $volid );
		}

		$events = $this->events_of( $r );
		$this->assertStringContainsString( 'Server restored from the backup of ' . gmdate( 'Y-m-d' ), $events );
		$this->assertStringNotContainsString( '1970', $events );
	}

	public function test_i1_a_restore_that_ended_with_warnings_in_a_later_round_succeeds(): void {
		$r     = $this->ready_vm();
		$vmid  = (int) $r->get( 'vmid' );
		$volid = $this->backup_of( $r );
		$this->mails = array();
		$this->fake->next_task( array() );
		$this->fake->next_task( array( 'polls' => 10, 'exitstatus' => 'WARNINGS: 1', 'effect' => true ) );

		for ( $i = 0; $i < 8; $i++ ) {
			$this->restore( $r, $volid );

			if ( false !== strpos( $this->events_of( $r ), 'Server restored' ) ) {
				break;
			}
		}

		Jobs::run( $r->id() ); // The queued plan change starts it.
		$this->assertSame( 'running', $this->fake->vm( $vmid )['status'] );
		$this->assertStringContainsString( 'Server restored from the backup of', $this->events_of( $r ) );
		$this->assertStringNotContainsString( 'Restore failed', $this->events_of( $r ) );
	}

	// -- I2 ----------------------------------------------------------------------------------------

	public function test_i2_a_failed_restore_tells_the_customer_without_the_proxmox_text(): void {
		$r     = $this->ready_vm();
		$volid = $this->backup_of( $r );
		$this->mails = array();
		$this->fake->next_task( array() );
		$this->fake->next_task( array( 'exitstatus' => 'ERROR: secret-storage-detail' ) );

		$this->restore( $r, $volid );

		$customer = $this->mails_to( 'customer@example.com' );
		$this->assertCount( 1, $customer );
		$this->assertStringContainsString( 'did not finish', $customer[0]['message'] );
		$this->assertStringNotContainsString( 'secret-storage-detail', $customer[0]['message'] . $customer[0]['subject'] );
		$this->assertCount( 1, $this->mails_to( 'admin@example.com' ), 'The admin hears of it too.' );
	}

	// -- I3 ----------------------------------------------------------------------------------------

	public function test_i3_a_server_on_its_way_out_is_not_due(): void {
		$r = $this->ready_vm();
		$this->assertTrue( Backups::due( $r, time() ) );

		$r->set( array( 'desired' => 'paused' ) );
		$this->assertFalse( Backups::due( Resource::find( $r->id() ), time() ) );
		$r->set( array( 'desired' => 'deleted' ) );
		$this->assertFalse( Backups::due( Resource::find( $r->id() ), time() ) );
		$r->set( array( 'desired' => 'ready', 'op' => 'update_plan' ) );
		$this->assertFalse( Backups::due( Resource::find( $r->id() ), time() ) );
	}

	public function test_i3_a_delete_waits_for_a_running_backup_before_it_purges_or_deletes(): void {
		$r = $this->ready_vm();
		$this->fake->next_task( array( 'polls' => 30 ) );
		ProxmoxJobs::backup();
		$this->assertIsArray( Resource::find( $r->id() )->get( 'backup_task' ) );
		as_unschedule_all_actions( ProxmoxJobs::BACKUP_NEXT, array(), Jobs::GROUP );
		$seen = count( $this->fake->calls() );

		$this->assertTrue( Resource::find( $r->id() )->desire( 'deleted', 'test' ) );
		Jobs::run( $r->id() );

		$after = array_slice( $this->lines(), $seen );
		$this->assertSame( array(), array_filter( $after, static fn( $l ) => 0 === strpos( $l, 'DELETE ' ) ), 'No purge and no VM delete while the backup runs.' );
		$this->assertNotNull( $this->fake->vm( (int) $r->get( 'vmid' ) ) );
		$this->assertNotSame( 'deleted', Resource::find( $r->id() )->get( 'state' ) );
	}

	public function test_i3_a_restore_does_not_switch_the_server_off_under_a_running_backup(): void {
		$r     = $this->ready_vm();
		$volid = $this->backup_of( $r );
		$this->fake->next_task( array( 'polls' => 30 ) );
		ProxmoxJobs::backup();
		as_unschedule_all_actions( ProxmoxJobs::BACKUP_NEXT, array(), Jobs::GROUP );
		$seen = count( $this->fake->calls() );

		$this->restore( $r, $volid );

		$this->assertSame( array(), array_filter( array_slice( $this->lines(), $seen ), static fn( $l ) => (bool) preg_match( '#/status/(shutdown|stop)$|^POST /nodes/node-a/qemu$#', $l ) ) );
		$this->assertSame( 'running', $this->fake->vm( (int) $r->get( 'vmid' ) )['status'] );
	}

	public function test_i3_a_locked_vm_is_not_restored_over(): void {
		$r     = $this->ready_vm();
		$vmid  = (int) $r->get( 'vmid' );
		$volid = $this->backup_of( $r );
		$this->fake->set_lock( $vmid, 'backup' );
		$seen = count( $this->fake->calls() );

		$this->restore( $r, $volid );

		$this->assertSame( array(), array_filter( array_slice( $this->lines(), $seen ), static fn( $l ) => (bool) preg_match( '#/status/(shutdown|stop)$|^POST /nodes/node-a/qemu$#', $l ) ) );
	}

	// -- I4 ----------------------------------------------------------------------------------------

	public function test_i4_a_slow_backup_delete_is_not_a_failed_try(): void {
		$r    = $this->ready_vm();
		$vmid = (int) $r->get( 'vmid' );
		$this->fake->add_backup( 'backup-store', array( 'vmid' => $vmid, 'ctime' => time() + 5 ) );
		$this->fake->next_task( array( 'polls' => 20 ) );

		$this->assertTrue( Resource::find( $r->id() )->desire( 'deleted', 'test' ) );
		Jobs::run( $r->id() );

		$this->assertSame( 0, (int) Resource::find( $r->id() )->get( 'backup_purge_tries' ) );
		$this->assertNotNull( $this->fake->vm( $vmid ) );
	}

	public function test_i4_three_quick_failures_do_not_give_up(): void {
		$r    = $this->ready_vm();
		$vmid = (int) $r->get( 'vmid' );
		$this->fake->add_backup( 'backup-store', array( 'vmid' => $vmid, 'ctime' => time() + 5 ) );
		$this->assertTrue( Resource::find( $r->id() )->desire( 'deleted', 'test' ) );

		for ( $i = 0; $i < 4; $i++ ) {
			$this->fake->fail_next( 'DELETE', '#/content/#', array( 'message' => 'offline' ) );
			Resource::find( $r->id() )->set( array( 'next_attempt_at' => time() - 1 ) );
			Jobs::run( $r->id() );
		}

		$this->assertNotNull( $this->fake->vm( $vmid ), 'Still trying: not enough time has passed.' );
		$this->assertFalse( get_option( Backups::ORPHANS ) );
	}

	// -- I5 ----------------------------------------------------------------------------------------

	private function pooled(): Resource {
		$this->fake->add_pool( 'customers' );
		$this->save_cluster( array( 'pool' => 'customers' ) );

		$r = $this->ready_vm();
		$this->assertSame( 'customers', $this->fake->vm( (int) $r->get( 'vmid' ) )['pool'] );

		return $r;
	}

	public function test_i5_a_vm_that_left_its_pool_in_the_restore_is_put_back(): void {
		$r     = $this->pooled();
		$vmid  = (int) $r->get( 'vmid' );
		$volid = $this->backup_of( $r );
		$this->fake->drop_pool_on_restore( true );

		$this->restore( $r, $volid );
		Jobs::run( $r->id() );

		$this->assertSame( 'customers', $this->fake->vm( $vmid )['pool'] );
		$this->assertSame( 'running', $this->fake->vm( $vmid )['status'] );
		$this->assertNull( Resource::find( $r->id() )->get( 'restore' ) );
		$this->assertCount( 1, array_filter( $this->fake->calls(), static fn( $c ) => 'PUT' === $c['method'] && '/pools' === $c['path'] && (string) $vmid === $c['params']['vms'] ) );
	}

	public function test_i5_a_vm_that_stays_in_its_pool_is_left_alone(): void {
		$r     = $this->pooled();
		$volid = $this->backup_of( $r );
		$seen  = count( $this->fake->calls() );

		$this->restore( $r, $volid );

		$this->assertSame( array(), array_filter( array_slice( $this->fake->calls(), $seen ), static fn( $c ) => 'PUT' === $c['method'] && 0 === strpos( $c['path'], '/pools' ) ) );
	}

	public function test_i5_when_the_pool_cannot_be_restored_the_admin_is_alerted(): void {
		$r     = $this->pooled();
		$volid = $this->backup_of( $r );
		$this->fake->drop_pool_on_restore( true );
		$this->mails = array();
		$this->fake->fail_next( 'PUT', '#^/pools$#', array( 'message' => 'permission check failed' ) );

		$this->restore( $r, $volid );

		$admin = $this->mails_to( 'admin@example.com' );
		$this->assertNotEmpty( $admin );
		$this->assertStringContainsString( 'put back into the pool "customers"', implode( ' ', array_column( $admin, 'message' ) ) );
		$this->assertNull( Resource::find( $r->id() )->get( 'restore' ) );
	}

	// -- I6 ----------------------------------------------------------------------------------------

	public function test_i6_a_sibling_with_another_plan_or_out_of_stock_is_not_one(): void {
		$v = $this->variable_product();
		$this->assertNotNull( Backups::sibling( $v['a-None'], true ) );

		$v['a-Daily']->update_meta_data( \BeltoftServices\Product::META_PLAN . 'cores', '8' );
		$v['a-Daily']->save();
		$this->assertNull( Backups::sibling( $v['a-None'], true ), 'Other cores: a silent plan change.' );

		$v = $this->variable_product();
		$v['a-Daily']->set_stock_status( 'outofstock' );
		$v['a-Daily']->save();
		wc_delete_product_transients( $v['a-Daily']->get_parent_id() );
		$this->assertNull( Backups::sibling( wc_get_product( $v['a-None']->get_id() ), true ), 'Cannot be bought.' );
	}

	// -- I7 ----------------------------------------------------------------------------------------

	public function test_i7_a_restored_config_without_the_tag_fails_the_restore(): void {
		$r     = $this->ready_vm();
		$vmid  = (int) $r->get( 'vmid' );
		$volid = 'backup-store:backup/vm/' . $vmid . '/2099-01-01T00:00:00Z';
		$this->fake->add_backup( 'backup-store', array( 'vmid' => $vmid, 'ctime' => time() + 10, 'volid' => $volid, 'config' => array( 'scsi0' => 'disk-store:vm-' . $vmid . '-disk-0,size=30G', 'cores' => 2, 'memory' => 4096, 'description' => 'beltoft-services:other-site:someone-else' ) ) );
		$this->mails = array();

		$this->restore( $r, $volid );

		$this->assertNull( Resource::find( $r->id() )->get( 'restore' ) );
		$this->assertStringContainsString( 'failed', $this->events_of( $r ) );
		$this->assertNotEmpty( $this->mails_to( 'admin@example.com' ) );
		$this->assertNotEmpty( $this->mails_to( 'customer@example.com' ) );
	}

	public function test_i7_a_restore_under_way_is_finished_even_if_the_plan_lost_its_backups(): void {
		$r     = $this->ready_vm();
		$vmid  = (int) $r->get( 'vmid' );
		$volid = $this->backup_of( $r );
		$this->restore( $r, $volid ); // Completes; now fake the middle of one.

		$this->fake->request( 'POST', '/nodes/node-a/qemu/' . $vmid . '/status/shutdown' );
		$this->assertSame( 'stopped', $this->fake->vm( $vmid )['status'] );
		$r = Resource::find( $r->id() );
		$r->set(
			array(
				'restore' => array( 'volid' => $volid, 'ctime' => time(), 'phase' => 'restored' ),
				'plan'    => array_merge( $r->get( 'plan' ), array( 'backup_days' => 0 ) ),
			)
		);

		$this->restore( $r, $volid );
		Jobs::run( $r->id() );

		$this->assertSame( 'running', $this->fake->vm( $vmid )['status'], 'The server is started again.' );
		$this->assertNull( Resource::find( $r->id() )->get( 'restore' ) );
	}
}

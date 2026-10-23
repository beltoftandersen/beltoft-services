<?php
/**
 * The fake cluster's backup endpoints (vzdump, storage content, prune, restore).
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Proxmox;

use BeltoftServices\Proxmox\FakeClient;
use BeltoftServices\Tests\TestCase;

/**
 * Backups against the fake cluster.
 */
class FakeClientBackupsTest extends TestCase {

	/**
	 * The fake cluster.
	 *
	 * @var FakeClient
	 */
	private $fake;

	public function set_up(): void {
		parent::set_up();

		$this->fake = new FakeClient();
		$this->fake->add_node( 'node-a' );
		$this->fake->add_vm(
			array(
				'vmid'   => 201,
				'node'   => 'node-a',
				'name'   => 'web',
				'status' => 'stopped',
				'config' => array(
					'scsi0'  => 'disk-store:vm-201-disk-0,size=30G',
					'cores'  => 2,
					'memory' => 2048,
				),
			)
		);
	}

	private function backup( int $vmid = 201 ): string {
		$upid = $this->fake->task(
			'POST',
			'/nodes/node-a/vzdump',
			array(
				'vmid'           => $vmid,
				'storage'        => 'backup-store',
				'mode'           => 'snapshot',
				'notes-template' => '{{guestname}}',
			)
		);
		$this->assertIsString( $upid );

		return $upid;
	}

	private function listing( array $params = array() ) {
		return $this->fake->request( 'GET', '/nodes/node-a/storage/backup-store/content', $params + array( 'content' => 'backup' ) );
	}

	public function test_vzdump_stores_a_backup_and_lists_it(): void {
		$upid = $this->backup();

		$this->assertTrue( $this->fake->wait_task( $upid, 10 ) );

		$list = $this->listing( array( 'vmid' => 201 ) );

		$this->assertCount( 1, $list );
		$this->assertSame( 201, $list[0]['vmid'] );
		$this->assertSame( 'web', $list[0]['notes'] );
		$this->assertSame( 'backup', $list[0]['content'] );
		$this->assertMatchesRegularExpression( '#^backup-store:backup/vm/201/\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$#', $list[0]['volid'] );
		$this->assertArrayNotHasKey( 'config', $list[0] );
		$this->assertSame( array(), $this->listing( array( 'vmid' => 202 ) ) );
	}

	public function test_two_backups_in_one_second_get_different_volids(): void {
		$this->backup();
		$this->backup();

		$this->assertCount( 2, array_unique( array_column( $this->listing(), 'volid' ) ) );
	}

	public function test_schema_rejects_unknown_and_bad_parameters(): void {
		$bad = $this->fake->request( 'POST', '/nodes/node-a/vzdump', array( 'vmid' => 201, 'storage' => 's', 'compress' => 'zstd' ) );
		$this->assertSame( 400, $bad->get_error_data()['status'] );

		$bad = $this->fake->request( 'POST', '/nodes/node-a/vzdump', array( 'vmid' => 201, 'storage' => 's', 'mode' => 'fast' ) );
		$this->assertSame( 400, $bad->get_error_data()['status'] );

		$bad = $this->fake->request( 'POST', '/nodes/node-a/vzdump', array( 'vmid' => 201 ) );
		$this->assertSame( 400, $bad->get_error_data()['status'] );

		$bad = $this->fake->request( 'GET', '/nodes/node-a/storage/backup-store/content', array( 'content' => 'iso' ) );
		$this->assertSame( 400, $bad->get_error_data()['status'] );

		$bad = $this->fake->request( 'DELETE', '/nodes/node-a/storage/backup-store/prunebackups', array( 'vmid' => 201 ) );
		$this->assertSame( 400, $bad->get_error_data()['status'] );

		$bad = $this->fake->request( 'POST', '/nodes/node-a/qemu', array( 'vmid' => 201, 'archive' => 'x', 'start' => 1 ) );
		$this->assertSame( 400, $bad->get_error_data()['status'] );
	}

	public function test_vzdump_of_a_missing_vm_fails(): void {
		$result = $this->fake->request( 'POST', '/nodes/node-a/vzdump', array( 'vmid' => 999, 'storage' => 'backup-store' ) );
		$this->assertWPError( $result );
	}

	public function test_delete_content_removes_one_volume_by_its_encoded_id(): void {
		$this->backup();
		$this->backup();
		$volid = $this->listing()[0]['volid'];

		$upid = $this->fake->task( 'DELETE', '/nodes/node-a/storage/backup-store/content/' . rawurlencode( $volid ) );
		$this->assertTrue( $this->fake->wait_task( $upid, 10 ) );

		$left = array_column( $this->listing(), 'volid' );
		$this->assertCount( 1, $left );
		$this->assertNotContains( $volid, $left );

		$missing = $this->fake->request( 'DELETE', '/nodes/node-a/storage/backup-store/content/' . rawurlencode( 'backup-store:backup/vm/201/none' ) );
		$this->assertWPError( $missing );
	}

	public function test_prune_keeps_the_newest_per_day_for_n_days(): void {
		$day = 86400;
		$now = time();

		// Three days, two backups on the oldest of the kept ones, one on another VM.
		foreach ( array( 0, 1, 2, 3 ) as $d ) {
			$this->fake->add_backup( 'backup-store', array( 'vmid' => 201, 'ctime' => $now - $d * $day ) );
		}
		$this->fake->add_backup( 'backup-store', array( 'vmid' => 201, 'ctime' => $now - 2 * $day - 60 ) );
		$this->fake->add_backup( 'backup-store', array( 'vmid' => 202, 'ctime' => $now - 9 * $day ) );

		$upid = $this->fake->task( 'DELETE', '/nodes/node-a/storage/backup-store/prunebackups', array( 'vmid' => 201, 'type' => 'qemu', 'prune-backups' => 'keep-daily=3' ) );
		$this->assertTrue( $this->fake->wait_task( $upid, 10 ) );

		$mine = array_map( static fn( $b ) => $b['ctime'], $this->listing( array( 'vmid' => 201 ) ) );
		sort( $mine );

		$this->assertSame( array( $now - 2 * $day, $now - $day, $now ), $mine );
		$this->assertCount( 1, $this->listing( array( 'vmid' => 202 ) ), 'Another VM is not touched.' );
	}

	public function test_restore_replaces_the_config_of_a_stopped_vm(): void {
		$this->backup();
		$volid = $this->listing()[0]['volid'];

		$this->fake->request( 'PUT', '/nodes/node-a/qemu/201/config', array( 'cores' => 8 ) );
		$this->assertSame( 8, $this->fake->vm( 201 )['config']['cores'] );

		$upid = $this->fake->task( 'POST', '/nodes/node-a/qemu', array( 'vmid' => 201, 'archive' => $volid, 'force' => 1, 'storage' => 'disk-store' ) );
		$this->assertTrue( $this->fake->wait_task( $upid, 10 ) );

		$this->assertSame( 2, $this->fake->vm( 201 )['config']['cores'] );
		$this->assertSame( 'stopped', $this->fake->vm( 201 )['status'] );
	}

	public function test_restore_refusals(): void {
		$this->backup();
		$volid = $this->listing()[0]['volid'];

		$no_force = $this->fake->request( 'POST', '/nodes/node-a/qemu', array( 'vmid' => 201, 'archive' => $volid ) );
		$this->assertWPError( $no_force );

		$this->fake->add_vm( array( 'vmid' => 202, 'node' => 'node-a', 'status' => 'running' ) );
		$running = $this->fake->request( 'POST', '/nodes/node-a/qemu', array( 'vmid' => 202, 'archive' => $volid, 'force' => 1 ) );
		$this->assertWPError( $running );
		$this->assertStringContainsString( 'running', $running->get_error_message() );

		$missing_vm = $this->fake->request( 'POST', '/nodes/node-a/qemu', array( 'vmid' => 777, 'archive' => $volid, 'force' => 1 ) );
		$this->assertWPError( $missing_vm );

		$bad_archive = $this->fake->request( 'POST', '/nodes/node-a/qemu', array( 'vmid' => 201, 'archive' => 'backup-store:backup/vm/201/none', 'force' => 1 ) );
		$this->assertWPError( $bad_archive );
	}
}

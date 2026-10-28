<?php
/** Reinstalling a server that has extra disks replaces only the boot disk. FakeClient only. @package BeltoftServices */

namespace BeltoftServices\Tests\Proxmox;

use BeltoftServices\Drivers\Registry;
use BeltoftServices\Jobs;
use BeltoftServices\Product;
use BeltoftServices\Proxmox\Actions;
use BeltoftServices\Proxmox\DiskCapacity;
use BeltoftServices\Resource;

require_once __DIR__ . '/BackupTestCase.php';

class DiskReinstallTest extends BackupTestCase {
	private const BOOT = 'disk-store:5000/vm-5000-disk-0.raw';

	private function disk_for( Resource $server ): Resource {
		$server_license = $server->license();
		$this->assertTrue( DiskCapacity::refresh( 'lab', 'node-a', 'disk-store', $this->fake ) );
		$product = $this->service_product( 'proxmox-disk', array( 'disk_gb' => 50 ), array( 'slug' => 'disk-' . wp_generate_password( 6, false ) ) );
		$license = $this->service_license( $product, array( 'fire' => false, 'user_id' => $server_license['user_id'] ) );
		$disk    = Resource::create( $license, Registry::get( 'proxmox-disk' ), Product::plan( $product->get_id() ), array( 'server_license' => $server_license['id'] ) );
		$this->assertInstanceOf( Resource::class, $disk );
		Jobs::run( $disk->id() );
		$disk = Resource::find( $disk->id() );
		$this->assertSame( 'ready', $disk->get( 'state' ), (string) $disk->get( 'last_error' ) );
		return $disk;
	}

	/** A real boot volume for VM 5000 (the fake clone shares the template's drive string). */
	private function real_boot(): void {
		$this->assertSame( self::BOOT, $this->fake->request( 'POST', '/nodes/node-a/storage/disk-store/content', array( 'filename' => 'vm-5000-disk-0.raw', 'size' => '30G', 'vmid' => 5000, 'format' => 'raw' ) ) );
		$this->assertNull( $this->fake->request( 'PUT', '/nodes/node-a/qemu/5000/config', array( 'scsi0' => self::BOOT . ',size=30G' ) ) );
	}

	/** A server with a real boot volume and $count extra disks. @return array{0: Resource, 1: array<int, Resource>} */
	private function server_with( int $count ): array {
		$server = $this->ready_vm();
		$this->real_boot();
		$disks = array();
		for ( $i = 0; $i < $count; $i++ ) {
			$disks[] = $this->disk_for( $server );
		}
		return array( Resource::find( $server->id() ), $disks );
	}

	/** Leaves a volume as an unusedN entry of VM 5000 (attached to a spare slot, then detached). */
	private function park( string $name ): string {
		$volid = $this->fake->request( 'POST', '/nodes/node-a/storage/disk-store/content', array( 'filename' => $name, 'size' => '1G', 'vmid' => 5000, 'format' => 'raw' ) );
		$this->assertNull( $this->fake->request( 'PUT', '/nodes/node-a/qemu/5000/config', array( 'scsi20' => $volid ) ) );
		$this->assertNull( $this->fake->request( 'PUT', '/nodes/node-a/qemu/5000/config', array( 'delete' => 'scsi20' ) ) );
		return $volid;
	}

	private function config(): array {
		return $this->fake->request( 'GET', '/nodes/node-a/qemu/5000/config' );
	}

	private function images(): array {
		return array_column( $this->fake->request( 'GET', '/nodes/node-a/storage/disk-store/content', array( 'content' => 'images', 'vmid' => 5000 ) ), 'volid' );
	}

	private function reinstall( Resource $server, array $args = array() ): void {
		Jobs::act( $server->id(), 'reinstall', array_merge( array( 'confirm' => 'REINSTALL' ), $args ) );
	}

	private function rebuild( int $id ): void {
		for ( $i = 0; $i < 12 && 'ready' !== Resource::find( $id )->get( 'state' ); $i++ ) {
			Resource::find( $id )->set( array( 'next_attempt_at' => time() - 1 ) );
			Jobs::run( $id );
			$this->run_jobs();
		}
		$this->assertSame( 'ready', Resource::find( $id )->get( 'state' ), (string) Resource::find( $id )->get( 'last_error' ) );
	}

	private function assert_disks_attached( array $disks ): void {
		$config = $this->config();
		foreach ( $disks as $disk ) {
			$disk = Resource::find( $disk->id() );
			$this->assertSame( 'ready', $disk->get( 'state' ) );
			$this->assertStringStartsWith( 'disk-store:5000/' . $disk->get( 'volname' ) . ',backup=0', $config[ $disk->get( 'slot' ) ], 'The extra disk stays in its slot.' );
			$this->assertContains( 'disk-store:5000/' . $disk->get( 'volname' ), $this->images() );
		}
	}

	public function test_one_extra_disk_survives_and_only_the_boot_volume_is_replaced(): void {
		list( $server, $disks ) = $this->server_with( 1 );
		$before = $this->config();
		$this->fake->add_vm( array( 'vmid' => 9100, 'node' => 'node-a', 'config' => array() ) ); // An unrelated VM must stay untouched.
		$seen = count( $this->fake->calls() );

		$this->reinstall( $server );
		$this->assertSame( 'boot', Resource::find( $server->id() )->get( 'wipe' ) );
		$this->rebuild( $server->id() );

		$fresh = Resource::find( $server->id() );
		$this->assertNull( $fresh->get( 'wipe' ) );
		$this->assertNull( $fresh->get( 'wipe_old_boot' ) );
		$this->assertSame( 'running', $this->fake->vm( 5000 )['status'] );
		$this->assert_disks_attached( $disks );
		$config = $this->config();
		$this->assertSame( $before['scsi1'], $config['scsi1'], 'The extra drive string is untouched.' );
		$this->assertStringStartsWith( self::BOOT, $config['scsi0'] );
		$this->assertStringContainsString( 'size=30G', $config['scsi0'], 'The boot disk is resized to the plan.' );
		$this->assertArrayNotHasKey( 'unused0', $config );
		$this->assertCount( 2, $this->images() );

		$calls = array_slice( $this->fake->calls(), $seen );
		$this->assertSame( array(), array_filter( $calls, static fn( $c ) => 'DELETE' === $c['method'] || false !== strpos( $c['path'], '/clone' ) ), 'No VM delete and no clone.' );
		$this->assertCount( 0, array_filter( $this->mails, static fn( $m ) => 'admin@example.com' === ( (array) $m['to'] )[0] ) );
	}

	public function test_the_sequence_is_stop_detach_destroy_old_import_then_configure(): void {
		list( $server ) = $this->server_with( 1 );
		$seen = count( $this->fake->calls() );
		$this->reinstall( $server );
		$this->rebuild( $server->id() );

		$calls = array_values( array_filter( array_slice( $this->fake->calls(), $seen ), static fn( $c ) => in_array( $c['method'], array( 'PUT', 'POST' ), true ) ) );
		$lines = array_map( static fn( $c ) => $c['method'] . ' ' . $c['path'] . ' ' . implode( ',', array_keys( $c['params'] ) ), $calls );
		$flat  = implode( "\n", $lines );
		$shutdown = strpos( $flat, '/status/shutdown' );
		$detach   = strpos( $flat, 'PUT /nodes/node-a/qemu/5000/config delete,digest' );
		$import   = strpos( $flat, 'POST /nodes/node-a/qemu/5000/config scsi0,boot,digest' );
		$this->assertNotFalse( $shutdown );
		$this->assertLessThan( $detach, $shutdown );
		$this->assertLessThan( $import, $detach );
		$deletes = array_values( array_filter( $calls, static fn( $c ) => 'PUT' === $c['method'] && isset( $c['params']['delete'] ) ) );
		$this->assertSame( array( 'scsi0', 'unused0' ), array_column( array_column( $deletes, 'params' ), 'delete' ), 'Exactly the boot drive, then the unused entry of the old boot volume.' );
		$importcall = array_values( array_filter( $calls, static fn( $c ) => isset( $c['params']['boot'] ) ) )[0];
		$this->assertSame( 'disk-store:0,import-from=disk-store:vm-9000-disk-0', $importcall['params']['scsi0'] );
		$this->assertSame( 'order=scsi0', $importcall['params']['boot'] );
		$this->assertLessThan( strpos( $flat, '/status/start' ), $import );
	}

	public function test_two_extra_disks_survive(): void {
		list( $server, $disks ) = $this->server_with( 2 );
		$this->assertSame( 'scsi2', $disks[1]->get( 'slot' ) );
		$this->reinstall( $server );
		$this->rebuild( $server->id() );
		$this->assert_disks_attached( $disks );
		$this->assertCount( 3, $this->images() );
		$this->assertArrayNotHasKey( 'unused0', $this->config() );
	}

	public function test_the_chosen_template_is_the_one_imported(): void {
		list( $server ) = $this->server_with( 1 );
		$this->fake->add_vm( array( 'vmid' => 9001, 'node' => 'node-a', 'name' => 'other', 'template' => 1, 'config' => array( 'scsi0' => 'disk-store:vm-9001-disk-0,size=12G', 'net0' => 'virtio=AA:BB:CC:DD:EE:00,bridge=vmbr0', 'agent' => '1' ) ) );
		$this->save_cluster( array( 'templates' => "9000 = Ubuntu 26.04\n9001 = Other" ) );
		$this->reinstall( $server, array( 'os' => '9001' ) );
		$this->rebuild( $server->id() );
		$import = array_values( array_filter( $this->fake->calls(), static fn( $c ) => isset( $c['params']['boot'] ) ) );
		$this->assertCount( 1, $import );
		$this->assertSame( 'disk-store:0,import-from=disk-store:vm-9001-disk-0', $import[0]['params']['scsi0'] );
	}

	public function test_a_server_without_extra_disks_still_deletes_and_clones(): void {
		$server = $this->ready_vm();
		$this->reinstall( $server );
		$this->assertTrue( Resource::find( $server->id() )->get( 'wipe' ) );
		$this->rebuild( $server->id() );
		$this->assertCount( 1, $this->calls( 'DELETE', '/qemu/5000' ) );
		$this->assertCount( 2, $this->calls( 'POST', '/clone' ) );
		$this->assertSame( array(), array_filter( $this->fake->calls(), static fn( $c ) => isset( $c['params']['boot'] ) ) );
	}

	public function test_a_deleted_disk_does_not_count_as_an_extra_disk(): void {
		list( $server, $disks ) = $this->server_with( 1 );
		$this->assertTrue( $disks[0]->transition( 'deleted', 'test' ) );
		$this->assertNull( Actions::check( Resource::find( $server->id() ), 'reinstall', array( 'confirm' => 'REINSTALL' ) ) );
		$this->reinstall( Resource::find( $server->id() ) );
		$this->assertTrue( Resource::find( $server->id() )->get( 'wipe' ) );
	}

	public function test_a_paused_disk_refuses_reinstall_before_the_vm_is_touched(): void {
		list( $server, $disks ) = $this->server_with( 1 );
		$paused = Registry::get( 'proxmox-disk' )->pause( $disks[0] );
		$this->assertTrue( $paused->is_done(), $paused->error() );
		$this->assertTrue( $disks[0]->set( $paused->meta() ) );
		$this->assertTrue( Resource::find( $disks[0]->id() )->transition( 'paused', 'test' ) );
		$args = array( 'confirm' => 'REINSTALL' );
		$this->assertSame( 'This server has an extra disk that is detached because its subscription is not active. Reinstall is possible again once the disk is active or removed.', Actions::check( Resource::find( $server->id() ), 'reinstall', $args ) );
		$seen = count( $this->fake->calls() );
		$this->reinstall( Resource::find( $server->id() ) );
		$this->assertNull( Resource::find( $server->id() )->get( 'wipe' ) );
		$this->assertSame( array(), array_values( array_filter( array_slice( $this->fake->calls(), $seen ), static fn( $c ) => 'GET' !== $c['method'] ) ), 'The job writes nothing.' );
		$this->assertSame( 'running', $this->fake->vm( 5000 )['status'] );
	}

	public function test_a_failed_disk_needs_support_before_a_reinstall(): void {
		list( $server, $disks ) = $this->server_with( 1 );
		$this->assertTrue( $disks[0]->set( array( 'state' => 'failed' ) ) );
		$this->assertStringContainsString( 'needs attention from support before a reinstall', (string) Actions::check( Resource::find( $server->id() ), 'reinstall', array( 'confirm' => 'REINSTALL' ) ) );
	}

	public function test_an_unused_entry_that_is_not_the_old_boot_volume_is_never_deleted(): void {
		list( $server, $disks ) = $this->server_with( 1 );
		$other = $this->park( 'vm-5000-keep-me.raw' );
		$this->assertSame( $other, $this->config()['unused0'] );
		$seen = count( $this->fake->calls() );
		$this->reinstall( $server );
		$this->rebuild( $server->id() );
		$config = $this->config();
		$this->assertSame( $other, $config['unused0'], 'A foreign unused entry stays.' );
		$this->assertContains( $other, $this->images() );
		$deleted = array();
		foreach ( array_slice( $this->fake->calls(), $seen ) as $call ) {
			if ( isset( $call['params']['delete'] ) ) {
				$deleted[] = $call['params']['delete'];
			}
		}
		$this->assertSame( array( 'scsi0', 'unused1' ), $deleted, 'Only the boot drive and the unused entry of the old boot volume.' );
		$this->assert_disks_attached( $disks );
		$this->assertNotContains( 'scsi1', $deleted );
	}

	public function test_a_retry_after_a_crash_at_every_phase_never_imports_twice_or_deletes_the_new_boot_disk(): void {
		foreach ( array( 'after_stop', 'after_detach', 'after_destroy', 'after_import_before_ready' ) as $phase ) {
			$this->tear_down();
			$this->set_up();
			list( $server, $disks ) = $this->server_with( 1 );
			$this->reinstall( $server );
			$id = $server->id();
			$r  = Resource::find( $id );
			$this->assertSame( 'boot', $r->get( 'wipe' ) );
			$this->assertTrue( $r->set( array( 'wipe_old_boot' => self::BOOT ) ) );
			// The remote state of a run that was cut off right after the named step.
			$this->fake->task( 'POST', '/nodes/node-a/qemu/5000/status/stop' );
			if ( 'after_stop' !== $phase ) {
				$this->fake->request( 'PUT', '/nodes/node-a/qemu/5000/config', array( 'delete' => 'scsi0' ) );
			}
			if ( in_array( $phase, array( 'after_destroy', 'after_import_before_ready' ), true ) ) {
				$this->fake->request( 'PUT', '/nodes/node-a/qemu/5000/config', array( 'delete' => 'unused0' ) );
				$this->assertTrue( Resource::find( $id )->set( array( 'wipe_boot_phase' => 'import' ) ) );
			}
			if ( 'after_import_before_ready' === $phase ) {
				$upid = $this->fake->request( 'POST', '/nodes/node-a/qemu/5000/config', array( 'scsi0' => 'disk-store:0,import-from=disk-store:vm-9000-disk-0', 'boot' => 'order=scsi0' ) );
				$this->assertTrue( $this->fake->wait_task( $upid, 5 ) );
			}
			$seen = count( $this->fake->calls() );
			$this->rebuild( $id );

			$after   = array_slice( $this->fake->calls(), $seen );
			$imports = array_filter( $this->fake->calls(), static fn( $c ) => isset( $c['params']['boot'] ) );
			$this->assertCount( 1, $imports, $phase . ': one import in all.' );
			$deleted  = array_values( array_map( static fn( $c ) => $c['params']['delete'], array_filter( $after, static fn( $c ) => isset( $c['params']['delete'] ) ) ) );
			$expected = array(
				'after_stop'                => array( 'scsi0', 'unused0' ),
				'after_detach'              => array( 'unused0' ),
				'after_destroy'             => array(),
				'after_import_before_ready' => array(),
			);
			$this->assertSame( $expected[ $phase ], $deleted, $phase . ': only the old boot drive and its unused entry, each once.' );
			$config = $this->config();
			$this->assertStringStartsWith( self::BOOT, $config['scsi0'], $phase . ': the new boot disk is there.' );
			$this->assertContains( self::BOOT, $this->images(), $phase . ': the new boot volume exists.' );
			$this->assert_disks_attached( $disks );
		}
	}

	public function test_a_rerun_with_the_import_phase_and_a_new_boot_disk_of_the_old_name_does_not_detach_it(): void {
		list( $server, $disks ) = $this->server_with( 1 );
		$this->reinstall( $server );
		// The real state of a run cut off after the import: old boot detached and destroyed, a NEW boot volume imported under the old name.
		$this->fake->task( 'POST', '/nodes/node-a/qemu/5000/status/stop' );
		$this->assertNull( $this->fake->request( 'PUT', '/nodes/node-a/qemu/5000/config', array( 'delete' => 'scsi0' ) ) );
		$this->assertNull( $this->fake->request( 'PUT', '/nodes/node-a/qemu/5000/config', array( 'delete' => 'unused0' ) ) );
		$this->assertNotContains( self::BOOT, $this->images() );
		$upid = $this->fake->request( 'POST', '/nodes/node-a/qemu/5000/config', array( 'scsi0' => 'disk-store:0,import-from=disk-store:vm-9000-disk-0', 'boot' => 'order=scsi0' ) );
		$this->assertTrue( $this->fake->wait_task( $upid, 5 ) );
		$this->assertStringStartsWith( self::BOOT, $this->config()['scsi0'], 'The new disk has the old name.' );
		$this->assertTrue( Resource::find( $server->id() )->set( array( 'wipe_old_boot' => self::BOOT, 'wipe_boot_phase' => 'import' ) ) );
		$seen = count( $this->fake->calls() );
		$this->rebuild( $server->id() );
		$new_calls = array_slice( $this->fake->calls(), $seen );
		$this->assertSame( array(), array_filter( $new_calls, static fn( $c ) => isset( $c['params']['delete'] ) ), 'Nothing is detached or destroyed.' );
		$this->assertCount( 0, array_filter( $new_calls, static fn( $c ) => isset( $c['params']['boot'] ) ), 'No second import.' );
		$this->assertContains( self::BOOT, $this->images(), 'The new boot volume is kept.' );
		$this->assertStringStartsWith( self::BOOT, $this->config()['scsi0'], 'It is still in the boot slot.' );
		$this->assert_disks_attached( $disks );
	}

	public function test_a_stranger_in_the_boot_slot_is_not_touched(): void {
		list( $server, $disks ) = $this->server_with( 1 );
		$this->reinstall( $server );
		$this->fake->task( 'POST', '/nodes/node-a/qemu/5000/status/stop' );
		$this->assertTrue( Resource::find( $server->id() )->set( array( 'wipe_old_boot' => 'disk-store:5000/vm-5000-somethingelse.raw' ) ) );
		Jobs::run( $server->id() );
		$this->assertSame( 'failed', Resource::find( $server->id() )->get( 'state' ) );
		$this->assertStringStartsWith( self::BOOT, $this->config()['scsi0'] );
		$this->assertSame( array(), array_filter( $this->fake->calls(), static fn( $c ) => isset( $c['params']['delete'] ) ) );
		$this->assert_disks_attached( $disks );
	}

	public function test_an_extra_disk_volume_in_the_boot_slot_is_refused(): void {
		list( $server, $disks ) = $this->server_with( 1 );
		$volid = 'disk-store:5000/' . $disks[0]->get( 'volname' );
		$this->assertNull( $this->fake->request( 'PUT', '/nodes/node-a/qemu/5000/config', array( 'delete' => 'scsi1,scsi0' ) ) );
		$this->assertNull( $this->fake->request( 'PUT', '/nodes/node-a/qemu/5000/config', array( 'scsi0' => $volid . ',backup=0' ) ) );
		$this->reinstall( Resource::find( $server->id() ) );
		Jobs::run( $server->id() );
		$this->assertSame( 'failed', Resource::find( $server->id() )->get( 'state' ) );
		$this->assertSame( array(), array_filter( $this->fake->calls(), static fn( $c ) => isset( $c['params']['delete'] ) && ! in_array( $c['params']['delete'], array( 'scsi1,scsi0' ), true ) ) );
	}

	public function test_a_missing_extra_disk_after_the_rebuild_alerts_once_and_changes_nothing(): void {
		list( $server, $disks ) = $this->server_with( 1 );
		$this->reinstall( $server );
		// While the VM is off, something takes the extra drive away (modelled).
		$this->fake->task( 'POST', '/nodes/node-a/qemu/5000/status/stop' );
		$this->fake->request( 'PUT', '/nodes/node-a/qemu/5000/config', array( 'delete' => 'scsi1' ) );
		$this->mails = array();
		$this->rebuild( $server->id() );
		$admin = array_values( array_filter( $this->mails, static fn( $m ) => 'admin@example.com' === ( (array) $m['to'] )[0] ) );
		$this->assertCount( 1, $admin );
		$this->assertArrayNotHasKey( 'scsi1', $this->config(), 'No automatic action.' );
		$this->assertContains( 'disk-store:5000/' . $disks[0]->get( 'volname' ), $this->images() );
		$this->assertNull( Resource::find( $server->id() )->get( 'disks_check' ) );
	}

	public function test_configure_leaves_extra_disks_and_their_unused_entries_alone(): void {
		list( $server, $disks ) = $this->server_with( 2 );
		$this->park( 'vm-5000-bs9-orphan.raw' );
		$before = $this->config();
		$seen   = count( $this->fake->calls() );
		$this->reinstall( $server );
		$this->rebuild( $server->id() );
		$after = $this->config();
		foreach ( array( 'scsi1', 'scsi2', 'unused0' ) as $key ) {
			$this->assertSame( $before[ $key ], $after[ $key ], $key );
		}
		foreach ( array_slice( $this->fake->calls(), $seen ) as $call ) {
			foreach ( array( 'scsi1', 'scsi2', 'unused0' ) as $key ) {
				$this->assertArrayNotHasKey( $key, $call['params'], 'No call writes ' . $key );
			}
		}
	}

	public function test_the_reinstall_confirm_text_names_the_extra_disks_without_an_em_dash(): void {
		list( $server ) = $this->server_with( 1 );
		$this->assertTrue( $server->set( array( 'agent' => 'ok' ) ) );
		ob_start();
		\BeltoftServices\Proxmox\Panels::account( Resource::find( $server->id() ) );
		$html = ob_get_clean();
		$this->assertStringContainsString( 'data-action="reinstall" data-disks="1"', $html );
		$this->assertStringContainsString( 'Extra disks are kept with their data and stay attached.', $html );
		$this->assertStringNotContainsString( "\xE2\x80\x94", $html );
		$js = (string) file_get_contents( dirname( __DIR__, 2 ) . '/assets/vm.js' );
		$this->assertStringContainsString( 'vm.strings.reinstallDisks', $js );
		$panels = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Proxmox/Panels.php' );
		$this->assertStringContainsString( "Reinstalling erases the server\\'s main disk. Extra disks are kept with their data and stay attached.", $panels );
	}

	public function test_the_fake_import_names_the_next_free_volume_and_destroys_nothing_else(): void {
		list( $server ) = $this->server_with( 1 );
		$this->fake->task( 'POST', '/nodes/node-a/qemu/5000/status/stop' );
		$this->assertNull( $this->fake->request( 'PUT', '/nodes/node-a/qemu/5000/config', array( 'delete' => 'scsi0' ) ) );
		$this->assertSame( self::BOOT, $this->config()['unused0'] );
		// The old volume is still there: the next free name is disk-1.
		$upid = $this->fake->request( 'POST', '/nodes/node-a/qemu/5000/config', array( 'scsi0' => 'disk-store:0,import-from=disk-store:vm-9000-disk-0', 'boot' => 'order=scsi0' ) );
		$this->assertTrue( $this->fake->wait_task( $upid, 5 ) );
		$this->assertSame( 'disk-store:5000/vm-5000-disk-1.raw,size=10G', $this->config()['scsi0'] );
		$this->assertContains( self::BOOT, $this->images() );
		$this->assertSame( self::BOOT, $this->config()['unused0'] );
		$this->assertNull( $this->fake->request( 'PUT', '/nodes/node-a/qemu/5000/config', array( 'delete' => 'unused0' ) ) );
		$this->assertNotContains( self::BOOT, $this->images() );
		$this->assertWPError( $this->fake->request( 'PUT', '/nodes/node-a/qemu/5000/config', array( 'scsi0' => 'disk-store:0,import-from=disk-store:vm-9000-disk-0' ) ), 'An occupied slot is not overwritten.' );
	}
}

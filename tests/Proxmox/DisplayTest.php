<?php
/**
 * The standard display (vga=std) on customer servers, so the web console shows the screen. FakeClient only.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Proxmox;

use BeltoftServices\Proxmox\Clusters;
use BeltoftServices\Proxmox\ConsoleTab;
use BeltoftServices\Proxmox\FakeClient;
use BeltoftServices\Proxmox\Operations;
use BeltoftServices\Proxmox\ProxmoxJobs;
use BeltoftServices\Resource;

require_once __DIR__ . '/BackupTestCase.php';

class DisplayTest extends BackupTestCase {

	public function set_up(): void {
		parent::set_up();

		delete_option( FakeClient::OPTION );
		$this->fake = new FakeClient( true );
		$this->fake->add_node( 'node-a' );
		$this->seed_capacity();
		$this->fake->add_vm( array( 'vmid' => 9000, 'node' => 'node-a', 'name' => 'ubuntu-26-template', 'template' => 1, 'config' => array( 'scsi0' => 'disk-store:vm-9000-disk-0,size=10G', 'net0' => 'virtio=AA:BB:CC:DD:EE:FF,bridge=vmbr0', 'agent' => '1', 'memory' => 512, 'cores' => 1, 'vga' => 'serial0', 'serial0' => 'socket' ) ) );
		$stored        = get_option( Clusters::OPTION );
		$stored['lab'] = array_merge( $stored['lab'], array( 'persist' => 1 ) );
		update_option( Clusters::OPTION, $stored, false );
	}

	public function tear_down(): void {
		delete_option( FakeClient::OPTION );
		parent::tear_down();
	}

	private function config( int $vmid = 5000, bool $current = false ): array {
		return (array) $this->fake->request( 'GET', '/nodes/node-a/qemu/' . $vmid . '/config', $current ? array( 'current' => 1 ) : array() );
	}

	private function serial( int $vmid = 5000 ): void {
		$this->assertNull( $this->fake->request( 'PUT', '/nodes/node-a/qemu/' . $vmid . '/config', array( 'vga' => 'serial0' ) ) );
	}

	private function vga_puts(): int {
		return count( array_filter( $this->calls( 'PUT', '/config' ), static fn( $c ) => isset( $c['params']['vga'] ) ) );
	}

	public function test_a_new_build_has_the_standard_display_and_keeps_the_serial_port(): void {
		$this->ready_vm();

		$config = $this->config();
		$this->assertSame( 'std', $config['vga'] );
		$this->assertSame( 'socket', $config['serial0'] );
	}

	public function test_the_reconcile_runs_from_the_sweep(): void {
		$this->assertSame( 23, has_action( \BeltoftServices\Jobs::SWEEP, array( ProxmoxJobs::class, 'displays' ) ) );
	}

	public function test_a_server_with_the_serial_display_gets_std_once_and_is_never_restarted(): void {
		$r = $this->ready_vm();
		$this->serial();
		$this->fake->hold_net_rate( 5000 );

		ProxmoxJobs::displays();

		$this->assertSame( 'std', $this->config()['vga'], 'The default view shows the pending value.' );
		$this->assertSame( 'serial0', $this->config( 5000, true )['vga'], 'The running VM still has the serial display.' );
		$this->assertSame( array(), $this->calls( 'POST', '/status/stop' ) );
		$this->assertSame( array(), $this->calls( 'POST', '/status/shutdown' ) );
		$this->assertSame( array(), $this->calls( 'POST', '/status/reboot' ) );
		$this->assertSame( 'running', $this->fake->vm( 5000 )['status'] );
		$this->assertNotNull( Resource::find( $r->id() )->get( 'display_std' ) );
		$this->assertNotNull( Resource::find( $r->id() )->get( 'display_pending_at' ) );

		$writes = $this->vga_puts();
		ProxmoxJobs::displays();
		$this->assertSame( $writes, $this->vga_puts(), 'Written once.' );
	}

	public function test_a_server_without_a_vga_key_gets_std_and_another_display_is_left_alone(): void {
		$r = $this->ready_vm();
		$this->assertNull( $this->fake->request( 'PUT', '/nodes/node-a/qemu/5000/config', array( 'delete' => 'vga' ) ) );
		ProxmoxJobs::displays();
		$this->assertSame( 'std', $this->config()['vga'] );

		$other = $this->ready_vm();
		$id    = (int) $other->get( 'vmid' );
		$this->assertNull( $this->fake->request( 'PUT', '/nodes/node-a/qemu/' . $id . '/config', array( 'vga' => 'qxl' ) ) );
		ProxmoxJobs::displays();
		$this->assertSame( 'qxl', $this->config( $id )['vga'] );
		$this->assertNotNull( Resource::find( $other->id() )->get( 'display_std' ), 'Checked, so not read again.' );
		unset( $r );
	}

	public function test_linked_locked_on_hold_and_not_ready_servers_are_skipped(): void {
		$r = $this->ready_vm();
		$this->serial();

		$r->set( array( 'linked' => true ) );
		ProxmoxJobs::displays();
		$this->assertSame( 'serial0', $this->config()['vga'], 'Adopted VM.' );
		$r->set( array( 'linked' => null ) );

		$this->fake->set_lock( 5000, 'backup' );
		ProxmoxJobs::displays();
		$this->assertSame( 'serial0', $this->config()['vga'], 'Locked VM.' );
		$this->fake->set_lock( 5000, '' );

		$r->set( array( 'desired' => 'paused' ) );
		ProxmoxJobs::displays();
		$this->assertSame( 'serial0', $this->config()['vga'], 'Not wanted ready.' );
		$r->set( array( 'desired' => 'ready' ) );

		$r->set( array( 'state' => 'provisioning' ) );
		ProxmoxJobs::displays();
		$this->assertSame( 'serial0', $this->config()['vga'], 'Not ready.' );
		$r->set( array( 'state' => 'ready' ) );

		ProxmoxJobs::displays();
		$this->assertSame( 'std', $this->config()['vga'], 'Ready again: written.' );
	}

	public function test_the_pending_note_ends_after_a_boot(): void {
		$r = $this->ready_vm();
		$r->set( array( 'display_std' => 1, 'display_pending_at' => time() - 100 ) );

		ProxmoxJobs::power();
		$this->assertNotNull( Resource::find( $r->id() )->get( 'display_pending_at' ), 'Running since before the change.' );

		$this->fake->request( 'POST', '/nodes/node-a/qemu/5000/status/stop' );
		$this->fake->elapse( 5 );
		ProxmoxJobs::power();
		$this->fake->request( 'POST', '/nodes/node-a/qemu/5000/status/start' );
		$this->fake->elapse( 5 );
		ProxmoxJobs::power();
		$this->assertNull( Resource::find( $r->id() )->get( 'display_pending_at' ) );
	}

	public function test_the_console_tab_says_so_only_while_the_change_is_pending(): void {
		$this->settings( array( 'console' => 1 ) );
		$r = $this->ready_vm();
		Operations::remember_power( $r->id(), 'running' );
		$note = 'The console shows the server screen after the next restart.';

		ob_start();
		ConsoleTab::pane( 'console', $r->id() );
		$this->assertStringNotContainsString( $note, (string) ob_get_clean() );

		$r->set( array( 'display_pending_at' => time() ) );
		ob_start();
		ConsoleTab::pane( 'console', $r->id() );
		$html = (string) ob_get_clean();
		$this->assertStringContainsString( $note, $html );
		$this->assertStringContainsString( 'data-console-open', $html );
	}
}

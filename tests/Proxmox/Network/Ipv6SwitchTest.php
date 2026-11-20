<?php
/**
 * The customer's IPv6 switch on a server: Add IPv6 and Remove IPv6 (one restart, ipconfig0, meta, ipfilter, log), the
 * refusals (cluster without a prefix, nothing to add or remove, no IPv4 address left), the REST route and the page.
 * FakeClient only.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Proxmox\Network;

use BeltoftServices\Jobs;
use BeltoftServices\Proxmox\Network\IpHistory;
use BeltoftServices\Proxmox\Network\Ipv6Switch;
use BeltoftServices\Proxmox\Network\Ipv6Tab;
use BeltoftServices\Proxmox\Pve\Clusters;
use BeltoftServices\Proxmox\Pve\FakeClient;
use BeltoftServices\Proxmox\Vm\Actions;
use BeltoftServices\Resource;
use BeltoftServices\Tests\Proxmox\Backup\BackupTestCase;

require_once dirname( __DIR__ ) . '/Backup/BackupTestCase.php';

class Ipv6SwitchTest extends BackupTestCase {

	private const ADDRESS = '2001:db8:430c:1:1388::1';
	private const GATEWAY = '2001:db8:430c:1::1';

	public function set_up(): void {
		parent::set_up();

		delete_option( FakeClient::OPTION );
		$this->fake = new FakeClient( true );
		$this->fake->add_node( 'node-a' );
		$this->seed_capacity();
		$this->fake->add_vm( array( 'vmid' => 9000, 'node' => 'node-a', 'name' => 'ubuntu-26-template', 'template' => 1, 'config' => array( 'scsi0' => 'disk-store:vm-9000-disk-0,size=10G', 'net0' => 'virtio=AA:BB:CC:DD:EE:FF,bridge=vmbr0', 'agent' => '1', 'memory' => 512, 'cores' => 1 ) ) );
		$stored        = get_option( Clusters::OPTION );
		$stored['lab'] = array_merge( $stored['lab'], array( 'persist' => 1 ) );
		update_option( Clusters::OPTION, $stored, false );
	}

	public function tear_down(): void {
		delete_option( FakeClient::OPTION );
		parent::tear_down();
	}

	private function ipv6_on(): void {
		$stored        = get_option( Clusters::OPTION );
		$stored['lab'] = array_merge( $stored['lab'], array( 'ipv6_prefix' => '2001:db8:430c:1::/64', 'fw_enabled' => '1', 'fw_ipfilter' => '1' ) );
		update_option( Clusters::OPTION, $stored, false );
	}

	private function config(): array {
		return (array) $this->fake->request( 'GET', '/nodes/node-a/qemu/5000/config' );
	}

	/** Power calls made after the mark. */
	private function power_calls( int $from ): array {
		return array_values( array_filter( array_slice( $this->fake->calls(), $from ), static fn( $c ) => 'POST' === $c['method'] && 1 === preg_match( '#/status/(start|stop|shutdown|reboot|reset)$#D', $c['path'] ) ) );
	}

	/** ipconfig0 writes made after the mark. */
	private function config_puts( int $from ): int {
		return count( array_filter( array_slice( $this->fake->calls(), $from ), static fn( $c ) => 'PUT' === $c['method'] && '/config' === substr( $c['path'], -7 ) && isset( $c['params']['ipconfig0'] ) ) );
	}

	private function ipfilter( int $vmid = 5000 ): array {
		return array_column( (array) ( $this->fake->vm( $vmid )['firewall_ipsets']['ipfilter-net0'] ?? array() ), 'cidr' );
	}

	/** A server built without IPv6 on a cluster that has a prefix now (the case the switch is for). */
	private function plain_vm(): Resource {
		// The firewall and the IP filter were on when the server was built (the prefix is what comes later).
		$stored        = get_option( Clusters::OPTION );
		$stored['lab'] = array_merge( $stored['lab'], array( 'fw_enabled' => '1', 'fw_ipfilter' => '1' ) );
		update_option( Clusters::OPTION, $stored, false );
		$r = $this->ready_vm();
		$this->ipv6_on();

		return Resource::find( $r->id() );
	}

	private function act( Resource $r, string $action ): void {
		Jobs::act( $r->id(), $action, array() );
	}

	private function last( Resource $r ): array {
		return (array) Resource::find( $r->id() )->get( Jobs::RESULT );
	}

	private function route( Resource $r, string $action ): \WP_REST_Response {
		wp_set_current_user( (int) $r->license()['user_id'] );
		$request = new \WP_REST_Request( 'POST', '/beltoft-services/v1/resources/' . $r->id() . '/actions/' . $action );
		$request->set_param( 'args', array() );

		try {
			return rest_get_server()->dispatch( $request );
		} finally {
			wp_set_current_user( 0 );
		}
	}

	public function test_add_is_offered_only_when_the_cluster_has_a_prefix(): void {
		$r = $this->ready_vm();
		$this->assertArrayNotHasKey( Ipv6Switch::ADD, Actions::offered( $r ) );
		$this->assertFalse( Ipv6Switch::applies( $r ) );
		$this->assertNotNull( Actions::check( $r, Ipv6Switch::ADD, array() ) );

		$this->ipv6_on();
		$r = Resource::find( $r->id() );
		$this->assertArrayHasKey( Ipv6Switch::ADD, Actions::offered( $r ) );
		$this->assertArrayNotHasKey( Ipv6Switch::REMOVE, Actions::offered( $r ) );
		$this->assertNull( Actions::check( $r, Ipv6Switch::ADD, array() ) );
		$this->assertNotNull( Actions::check( $r, Ipv6Switch::REMOVE, array() ), 'Nothing to remove.' );
	}

	public function test_add_writes_ip6_keeps_ipv4_updates_the_filter_and_restarts_once(): void {
		$r    = $this->plain_vm();
		$old  = $this->config()['ipconfig0'];
		$mark = count( $this->fake->calls() );
		$this->assertStringNotContainsString( 'ip6', $old );

		$this->act( $r, Ipv6Switch::ADD );

		$this->assertSame( $old . ',ip6=' . self::ADDRESS . '/64,gw6=' . self::GATEWAY, $this->config()['ipconfig0'] );
		$this->assertSame( 1, $this->config_puts( $mark ) );
		$this->assertCount( 1, $this->power_calls( $mark ) );
		$this->assertStringEndsWith( '/status/reboot', $this->power_calls( $mark )[0]['path'] );
		$this->assertSame( 'running', $this->fake->vm( 5000 )['status'] );

		$fresh = Resource::find( $r->id() );
		$this->assertSame( self::ADDRESS, $fresh->get( 'ip6' ) );
		$this->assertNull( $fresh->get( Ipv6Switch::OP ), 'The operation marker is gone.' );
		$this->assertContains( self::ADDRESS, $this->ipfilter() );
		$this->assertContains( (string) $fresh->get( 'ip' ), $this->ipfilter() );
		$this->assertCount( 1, IpHistory::for_address( self::ADDRESS ) );
		$this->assertNull( IpHistory::for_address( self::ADDRESS )[0]['ended_at'] );
		$this->assertTrue( $this->last( $r )['ok'] );
		$this->assertStringContainsString( 'restarted', (string) $this->last( $r )['text'] );

		$this->assertArrayHasKey( Ipv6Switch::REMOVE, Actions::offered( $fresh ) );
		$this->assertArrayNotHasKey( Ipv6Switch::ADD, Actions::offered( $fresh ) );
	}

	public function test_a_reboot_task_that_fails_still_ends_the_operation(): void {
		$r = $this->plain_vm();
		$this->fake->next_task( array( 'exitstatus' => 'command timed out - aborting', 'effect' => false ) );

		$this->act( $r, Ipv6Switch::ADD );

		$fresh = Resource::find( $r->id() );
		$this->assertNull( $fresh->get( Ipv6Switch::OP ), 'The operation marker is cleared, so the customer has the button back.' );
		$this->assertStringContainsString( 'did not restart', (string) $this->last( $r )['text'] );
		$this->assertArrayHasKey( Ipv6Switch::REMOVE, Actions::offered( $fresh ) );
	}

	public function test_a_stopped_server_is_not_started_and_takes_the_address_at_its_next_start(): void {
		$r = $this->plain_vm();
		$this->fake->request( 'POST', '/nodes/node-a/qemu/5000/status/stop' );
		$this->assertSame( 'stopped', $this->fake->vm( 5000 )['status'] );
		$mark = count( $this->fake->calls() );

		$this->act( $r, Ipv6Switch::ADD );

		$this->assertSame( array(), $this->power_calls( $mark ) );
		$this->assertStringContainsString( 'ip6=' . self::ADDRESS, $this->config()['ipconfig0'] );
		$this->assertSame( self::ADDRESS, Resource::find( $r->id() )->get( 'ip6' ) );
		$this->assertStringContainsString( 'starts', (string) $this->last( $r )['text'] . ' starts' );
		$this->assertTrue( $this->last( $r )['ok'] );
	}

	public function test_remove_takes_ip6_out_clears_meta_and_filter_and_restarts_once(): void {
		$r = $this->plain_vm();
		$this->act( $r, Ipv6Switch::ADD );
		$with = $this->config()['ipconfig0'];
		$v4   = substr( $with, 0, strpos( $with, ',ip6=' ) );
		$mark = count( $this->fake->calls() );

		$this->act( Resource::find( $r->id() ), Ipv6Switch::REMOVE );

		$this->assertSame( $v4, $this->config()['ipconfig0'] );
		$this->assertCount( 1, $this->power_calls( $mark ) );
		$fresh = Resource::find( $r->id() );
		$this->assertNull( $fresh->get( 'ip6' ) );
		$this->assertNull( $fresh->get( Ipv6Switch::OP ) );
		$this->assertNotContains( self::ADDRESS, $this->ipfilter() );
		$this->assertContains( (string) $fresh->get( 'ip' ), $this->ipfilter(), 'The IPv4 address stays allowed.' );
		$this->assertNotNull( IpHistory::for_address( self::ADDRESS )[0]['ended_at'] );
		$this->assertTrue( $this->last( $r )['ok'] );
		$this->assertArrayHasKey( Ipv6Switch::ADD, Actions::offered( $fresh ) );
		$this->assertArrayNotHasKey( Ipv6Switch::REMOVE, Actions::offered( $fresh ) );
	}

	public function test_a_server_that_was_removed_stays_without_ipv6_after_a_repair_run(): void {
		$r = $this->plain_vm();
		$this->act( $r, Ipv6Switch::ADD );
		$this->act( Resource::find( $r->id() ), Ipv6Switch::REMOVE );

		$this->assertSame( 'skipped', ( new \BeltoftServices\Proxmox\Vm\Operations( Resource::find( $r->id() ) ) )->reconcile_ipv6() );
		$this->assertStringNotContainsString( 'ip6', $this->config()['ipconfig0'] );
	}

	public function test_remove_is_refused_when_there_is_no_ipv4_address(): void {
		$r = $this->plain_vm();
		$this->act( $r, Ipv6Switch::ADD );
		$r = Resource::find( $r->id() );

		add_filter( 'beltoft_services_ipv6_removable', '__return_false' );
		$this->assertArrayNotHasKey( Ipv6Switch::REMOVE, Actions::offered( $r ) );
		$this->assertNotNull( Actions::check( $r, Ipv6Switch::REMOVE, array() ) );
		$this->assertSame( 403, $this->route( $r, Ipv6Switch::REMOVE )->get_status(), 'Not offered: the route does not know the action.' );
		remove_all_filters( 'beltoft_services_ipv6_removable' );

		// No IPv4 address in the resource: the same, without a filter.
		$r->set( array( 'ip' => null ) );
		$r = Resource::find( $r->id() );
		$this->assertFalse( Ipv6Switch::removable( $r ) );
		$this->assertArrayNotHasKey( Ipv6Switch::REMOVE, Actions::offered( $r ) );
		$this->assertStringContainsString( 'no IPv4', (string) Actions::check( $r, Ipv6Switch::REMOVE, array() ) );
		$mark = count( $this->fake->calls() );
		$this->act( $r, Ipv6Switch::REMOVE );
		$this->assertSame( array(), $this->power_calls( $mark ), 'Run directly, it is still refused.' );
		$this->assertStringContainsString( 'ip6=' . self::ADDRESS, $this->config()['ipconfig0'] );
	}

	public function test_a_second_add_or_a_remove_without_ipv6_is_refused(): void {
		$r = $this->plain_vm();
		$this->assertSame( 403, $this->route( $r, Ipv6Switch::REMOVE )->get_status() );
		$this->assertSame( 202, $this->route( $r, Ipv6Switch::ADD )->get_status() );
		$this->run_jobs();
		$this->assertSame( self::ADDRESS, Resource::find( $r->id() )->get( 'ip6' ), 'The queued job ran.' );
		$r = Resource::find( $r->id() );
		\BeltoftServices\Progress::clear( $r );
		$this->assertSame( 403, $this->route( $r, Ipv6Switch::ADD )->get_status() );
	}

	public function test_an_adopted_server_has_no_switch(): void {
		$r = $this->plain_vm();
		$r->set( array( 'linked' => true ) );
		$r = Resource::find( $r->id() );

		$this->assertFalse( Ipv6Switch::applies( $r ) );
		$this->assertArrayNotHasKey( Ipv6Switch::ADD, Actions::offered( $r ) );
	}

	public function test_a_run_that_was_cut_after_the_config_does_not_write_it_again(): void {
		$r = $this->plain_vm();
		$this->fake->request( 'PUT', '/nodes/node-a/qemu/5000/config', array( 'ipconfig0' => $this->config()['ipconfig0'] . ',ip6=' . self::ADDRESS . '/64,gw6=' . self::GATEWAY ) );
		$r->set( array( Ipv6Switch::OP => array( 'action' => 'add', 'phase' => 'config' ) ) );
		$mark = count( $this->fake->calls() );

		$this->act( Resource::find( $r->id() ), Ipv6Switch::ADD );

		$this->assertSame( 0, $this->config_puts( $mark ) );
		$this->assertCount( 1, $this->power_calls( $mark ) );
		$this->assertSame( self::ADDRESS, Resource::find( $r->id() )->get( 'ip6' ) );
		$this->assertNull( Resource::find( $r->id() )->get( Ipv6Switch::OP ) );
	}

	public function test_a_restart_that_was_already_done_is_not_sent_again(): void {
		$r = $this->plain_vm();
		$this->act( $r, Ipv6Switch::ADD );
		$r->set( array( Ipv6Switch::OP => array( 'action' => 'add', 'phase' => 'restart', 'restarted' => true ) ) );
		$mark = count( $this->fake->calls() );

		$this->act( Resource::find( $r->id() ), Ipv6Switch::ADD );

		$this->assertSame( array(), $this->power_calls( $mark ) );
		$this->assertNull( Resource::find( $r->id() )->get( Ipv6Switch::OP ) );
	}

	public function test_the_ipv6_tab_shows_the_plain_warning_and_the_button(): void {
		$r = $this->plain_vm();
		$this->assertArrayHasKey( Ipv6Tab::SLUG, Ipv6Tab::tabs( array( 'overview' => 'Overview', 'reinstall' => 'Reinstall' ), $r->id() ) );

		ob_start();
		Ipv6Tab::pane( Ipv6Tab::SLUG, $r->id() );
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'data-ipv6-action="ipv6_add"', $html );
		$this->assertStringContainsString( 'restarts once', $html );
		$this->assertStringContainsString( 'host keys', $html );
		$this->assertStringNotContainsString( '—', $html );
		$this->assertStringNotContainsString( '–', $html );
		$this->assertStringNotContainsString( '·', $html );

		$words = Ipv6Switch::words( Ipv6Switch::REMOVE ) + Ipv6Switch::words( Ipv6Switch::ADD, true );
		foreach ( array( Ipv6Switch::words( Ipv6Switch::ADD ), Ipv6Switch::words( Ipv6Switch::REMOVE ), Ipv6Switch::words( Ipv6Switch::ADD, true ), Ipv6Switch::words( Ipv6Switch::REMOVE, true ) ) as $set ) {
			$this->assertStringContainsString( 'restarts once', $set['text'] );
		}
		unset( $words );

		$this->act( $r, Ipv6Switch::ADD );
		ob_start();
		Ipv6Tab::pane( Ipv6Tab::SLUG, $r->id() );
		$html = (string) ob_get_clean();
		$this->assertStringContainsString( 'data-ipv6-action="ipv6_remove"', $html );
		$this->assertStringContainsString( self::ADDRESS, $html );
	}

	public function test_no_tab_without_a_prefix(): void {
		$r = $this->ready_vm();
		$this->assertArrayNotHasKey( Ipv6Tab::SLUG, Ipv6Tab::tabs( array( 'overview' => 'Overview' ), $r->id() ) );
	}
}

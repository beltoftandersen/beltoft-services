<?php
/**
 * The extra IPv4 addresses of a server, set up inside the guest through the guest agent: the sync's guest phase on add and on
 * remove, the "Apply again" action, the build step after a reinstall, and every way the guest can refuse. Fake client only.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Proxmox\Ipv4;

use BeltoftServices\Drivers\Registry;
use BeltoftServices\Jobs;
use BeltoftServices\Proxmox\Ipv4\Ipv4Driver;
use BeltoftServices\Proxmox\Ipv4\Ipv4Guest;
use BeltoftServices\Proxmox\Ipv4\Ipv4Meta;
use BeltoftServices\Proxmox\Ipv4\Ipv4Panels;
use BeltoftServices\Proxmox\Ipv4\Ipv4Server;
use BeltoftServices\Proxmox\Vm\Actions;
use BeltoftServices\Resource;

require_once __DIR__ . '/Ipv4TestCase.php';

class Ipv4GuestTest extends Ipv4TestCase {

	public function set_up(): void {
		parent::set_up();

		add_filter( 'beltoft_services_ipv4_guest_wait', '__return_zero' );
	}

	private function file( string $path ): ?string {
		$vm = $this->fake->vm( 5000 );

		return isset( $vm['guest_files'][ $path ] ) ? (string) $vm['guest_files'][ $path ] : null;
	}

	/** A server with one extra address, set up. */
	private function with_extra(): array {
		$server = $this->server( 1 );
		$addon  = $this->addon( $server );
		$this->assertSame( 'ready', $addon->get( 'state' ), (string) $addon->get( 'last_error' ) );

		return array( Resource::find( $server->id() ), $addon, (string) $addon->get( Ipv4Meta::ADDRESS ) );
	}

	public function test_an_added_extra_is_set_up_in_the_guest_and_the_main_address_is_not_touched(): void {
		list( $server, $addon, $address ) = $this->with_extra();
		$main                             = (string) $server->get( 'ip' );

		$this->assertSame( 'extra', $addon->get( Ipv4Meta::ROLE ) );
		$state = Ipv4Guest::state( $server );
		$this->assertSame( 'applied', $state['state'] );
		$this->assertSame( array( $address ), $state['applied'] );
		$this->assertSame( 'ens18', $state['nic'] );
		$this->assertSame( 'netplan', $state['stack'] );
		$this->assertTrue( $state['persisted'] );

		$this->assertSame( Ipv4Guest::script(), $this->file( Ipv4Guest::SCRIPT ), 'The apply script is the plugin file, unchanged.' );
		$want = json_decode( (string) $this->file( Ipv4Guest::WANT ), true );
		$this->assertSame( array( $address ), $want['extras'] );
		$this->assertSame( $main, $want['main'], 'The script is told the main address only to find the card by it.' );
		$this->assertNotContains( $main, $want['extras'] );
		$this->assertSame( array( array( '/bin/sh', Ipv4Guest::SCRIPT ) ), $this->fake->guest_execs( 5000 ) );

		// Nothing of the VM's own network settings changed, and the guest was not restarted.
		$this->assertStringStartsWith( 'ip=' . $main . '/', $this->config()['ipconfig0'] );
		$this->assertSame( $main, (string) $server->get( 'ip' ) );
		$this->assertContains( $address, $this->set(), 'The firewall step still ran before the guest step.' );
	}

	public function test_a_removed_extra_is_taken_out_of_the_guest(): void {
		list( $server, $addon, $address ) = $this->with_extra();

		for ( $i = 0; $i < 8; $i++ ) {
			Registry::get( Ipv4Driver::ID )->delete( Resource::find( $addon->id() ) );
			$this->run_jobs();

			if ( null === \BeltoftServices\Proxmox\Network\IpPool::find( $address )['resource_id'] ) {
				break;
			}
		}

		$this->assertCount( 2, $this->fake->guest_execs( 5000 ), 'The script ran again with the smaller list.' );
		$want = json_decode( (string) $this->file( Ipv4Guest::WANT ), true );
		$this->assertSame( array(), $want['extras'] );
		$this->assertNotContains( $address, $want['extras'] );
		$this->assertNull( Resource::find( $server->id() )->get( Ipv4Guest::META ), 'Nothing is left to remember once all extras are gone and the script said ok.' );
		$this->assertSame( 'free', \BeltoftServices\Proxmox\Network\IpPool::find( $address )['state'], 'The address went back to the pool after the guest step.' );
	}

	public function test_a_server_that_never_had_an_extra_is_not_touched(): void {
		$server = $this->server( 1 );
		Jobs::queue_act( $server->id(), Ipv4Server::SYNC );
		$this->run_jobs();

		$this->assertSame( array(), $this->fake->guest_execs( 5000 ) );
		$this->assertNull( $this->file( Ipv4Guest::SCRIPT ) );
		$this->assertNull( Resource::find( $server->id() )->get( Ipv4Guest::META ) );
		$this->assertSame( array(), Ipv4Guest::offered( Resource::find( $server->id() ) ), 'No button without an extra.' );
	}

	public function test_apply_again_is_a_customer_action_that_only_runs_the_guest_step(): void {
		list( $server ) = $this->with_extra();
		$offered        = Actions::offered( $server );

		$this->assertArrayHasKey( Ipv4Guest::ACTION, $offered );
		$this->assertNull( Actions::check( $server, Ipv4Guest::ACTION, array() ) );
		$this->assertContains( Ipv4Guest::ACTION, ( new \BeltoftServices\Proxmox\Vm\ProxmoxDriver() )->internal_actions() );

		$synced = (float) $server->get( Ipv4Server::SYNCED );
		$this->fake->set_guest_exec( 5000, 'blocked' );
		Jobs::act( $server->id(), Ipv4Guest::ACTION, array() );
		$this->assertSame( 'not_applied', Ipv4Guest::state( Resource::find( $server->id() ) )['state'] );

		$this->fake->set_guest_exec( 5000, 'ok' );
		Jobs::act( $server->id(), Ipv4Guest::ACTION, array() );
		$fresh = Resource::find( $server->id() );

		$this->assertTrue( $fresh->get( Jobs::RESULT )['ok'] );
		$this->assertSame( 'applied', Ipv4Guest::state( $fresh )['state'] );
		$this->assertSame( $synced, (float) $fresh->get( Ipv4Server::SYNCED ), 'Apply again is not a sync: it does not answer an add-on that waits for its own sync.' );
		$this->assertSame( '', Ipv4Server::working( $fresh ) );
	}

	public function test_apply_again_is_refused_without_extras_and_while_the_main_address_changes(): void {
		$server = $this->server( 1 );
		$this->assertNotNull( Actions::check( $server, Ipv4Guest::ACTION, array() ) );

		list( $server ) = $this->with_extra();
		$server->set( array( Ipv4Server::OP => array( 'action' => Ipv4Server::MAIN_ADD, 'phase' => 'config', 'started' => microtime( true ) ) ) );
		$this->assertNotNull( Actions::check( Resource::find( $server->id() ), Ipv4Guest::ACTION, array() ) );
	}

	public function test_a_reinstall_sets_the_extras_up_again_before_ready(): void {
		list( $server, , $address ) = $this->with_extra();
		$this->assertCount( 1, $this->fake->guest_execs( 5000 ) );

		Jobs::act( $server->id(), 'reinstall', array( 'confirm' => 'REINSTALL' ) );
		$this->run_jobs();
		$fresh = Resource::find( $server->id() );

		$this->assertSame( 'ready', $fresh->get( 'state' ), (string) $fresh->get( 'last_error' ) );
		$this->assertContains( 'extra_ips', (array) $fresh->get( 'steps' ) );
		$this->assertGreaterThanOrEqual( 1, count( $this->fake->guest_execs( 5000 ) ) );
		$this->assertSame( array( $address ), json_decode( (string) $this->file( Ipv4Guest::WANT ), true )['extras'] );
		$this->assertSame( 'applied', Ipv4Guest::state( $fresh )['state'] );
	}

	public function test_the_build_step_is_after_start_and_only_with_extras(): void {
		$method = new \ReflectionMethod( \BeltoftServices\Proxmox\Vm\Provision::class, 'build_steps' );
		$method->setAccessible( true );
		$steps = array( 'wipe', 'ip', 'vmid', 'clone', 'configure', 'start', 'ready' );

		$plain = $this->server( 1 );
		$this->assertSame( $steps, $method->invoke( null, $plain, $steps ) );

		list( $server ) = $this->with_extra();
		$this->assertSame( array( 'wipe', 'ip', 'vmid', 'clone', 'configure', 'start', 'extra_ips', 'ready' ), $method->invoke( null, $server, $steps ) );
	}

	public function test_a_build_waits_for_the_guest_agent_and_then_gives_up_without_failing(): void {
		list( $server ) = $this->with_extra();
		$this->fake->set_guest_agent_down( 5000, true );
		$record = \BeltoftServices\Proxmox\Vm\VmRecords::for_resource( $server->id() );
		$guest  = new Ipv4Guest( $server, $this->fake );
		$v      = array( 'node' => 'node-a', 'status' => 'running' );

		$first = $guest->at_build( $record, $v );
		$this->assertInstanceOf( \BeltoftServices\Drivers\StepResult::class, $first, 'First it waits.' );

		Resource::find( $server->id() )->set( array( Ipv4Guest::UNTIL => 1 ) );
		$second = ( new Ipv4Guest( Resource::find( $server->id() ), $this->fake ) )->at_build( $record, $v );
		$this->assertIsArray( $second, 'Then the build goes on.' );
		$this->assertSame( 'agent_missing', Ipv4Guest::state( Resource::find( $server->id() ) )['state'] );
	}

	public function test_a_guest_without_an_agent_is_not_set_up_and_the_addon_still_works(): void {
		$server = $this->server( 1 );
		$this->fake->set_guest_agent_down( 5000, true );
		$addon = $this->addon( $server );
		$fresh = Resource::find( $server->id() );

		$this->assertSame( 'ready', $addon->get( 'state' ), 'The add-on is delivered all the same.' );
		$this->assertContains( (string) $addon->get( Ipv4Meta::ADDRESS ), $this->set(), 'The firewall allows the address.' );
		$this->assertSame( 'assigned', \BeltoftServices\Proxmox\Network\IpPool::find( (string) $addon->get( Ipv4Meta::ADDRESS ) )['state'] );
		$state = Ipv4Guest::state( $fresh );
		$this->assertSame( 'agent_missing', $state['state'] );
		$this->assertSame( array(), $state['applied'] );
		$this->assertSame( array(), $this->fake->guest_execs( 5000 ) );

		$view = Ipv4Panels::view( $fresh );
		$this->assertSame( 'not_set_up', $view['addresses'][ count( $view['addresses'] ) - 1 ]['guest'] );
		$this->assertNotEmpty( $view['setup'], 'The manual lines are the fallback.' );
		$this->assertTrue( $view['guest']['can_apply'] );
	}

	public function test_an_exec_the_guest_refuses_is_not_set_up_and_not_a_failure(): void {
		$server = $this->server( 1 );
		$this->fake->set_guest_exec( 5000, 'blocked' );
		$addon = $this->addon( $server );

		$this->assertSame( 'ready', $addon->get( 'state' ) );
		$state = Ipv4Guest::state( Resource::find( $server->id() ) );
		$this->assertSame( 'not_applied', $state['state'] );
		$this->assertStringContainsString( 'refused', $state['note'] );
	}

	/**
	 * @dataProvider broken_scripts
	 *
	 * @param string $mode Fake guest mode.
	 */
	public function test_a_script_that_fails_or_leaves_no_status_is_an_error_that_is_remembered( string $mode ): void {
		$server = $this->server( 1 );
		$this->fake->set_guest_exec( 5000, $mode );
		$addon = $this->addon( $server );
		$state = Ipv4Guest::state( Resource::find( $server->id() ) );

		$this->assertSame( 'ready', $addon->get( 'state' ) );
		$this->assertSame( 'error', $state['state'] );
		$this->assertSame( array(), $state['applied'] );
		$this->assertNotSame( '', $state['note'] );
	}

	/** @return array<string, array{0: string}> */
	public function broken_scripts(): array {
		return array(
			'exit 1'          => array( 'error' ),
			'no status file'  => array( 'no_status' ),
			'status of other' => array( 'other_run' ),
			'never ends'      => array( 'hang' ),
		);
	}

	public function test_a_script_that_reports_a_problem_is_shown_as_not_set_up_or_not_kept(): void {
		$server = $this->server( 1 );
		$this->fake->set_guest_exec( 5000, 'unpersisted' );
		$addon   = $this->addon( $server );
		$fresh   = Resource::find( $server->id() );
		$state   = Ipv4Guest::state( $fresh );

		$this->assertSame( 'error', $state['state'] );
		$this->assertSame( array( (string) $addon->get( Ipv4Meta::ADDRESS ) ), $state['applied'], 'It works now.' );
		$this->assertFalse( $state['persisted'], 'But not after a restart.' );
		$this->assertStringContainsString( 'live only', $state['note'] );

		$this->fake->set_guest_exec( 5000, 'main_lost' );
		Jobs::act( $server->id(), Ipv4Guest::ACTION, array() );
		$state = Ipv4Guest::state( Resource::find( $server->id() ) );
		$this->assertSame( 'not_applied', $state['state'] );
		$this->assertStringContainsString( 'main address', $state['note'] );
		$this->assertSame( 'ready', Resource::find( $addon->id() )->get( 'state' ) );
	}

	public function test_a_stopped_server_and_a_paused_plugin_are_left_alone(): void {
		list( $server ) = $this->with_extra();
		$before         = count( $this->fake->guest_execs( 5000 ) );

		$this->settings( array( 'pause_all' => 1 ) );
		Jobs::act( $server->id(), Ipv4Guest::ACTION, array() );
		$this->assertSame( $before, count( $this->fake->guest_execs( 5000 ) ) );
		$this->settings( array( 'pause_all' => 0 ) );

		$this->fake->request( 'POST', '/nodes/node-a/qemu/5000/status/stop' );
		Jobs::act( $server->id(), Ipv4Guest::ACTION, array() );
		$state = Ipv4Guest::state( Resource::find( $server->id() ) );
		$this->assertSame( $before, count( $this->fake->guest_execs( 5000 ) ) );
		$this->assertSame( 'not_applied', $state['state'] );
		$this->assertStringContainsString( 'not running', $state['note'] );
		$this->assertNotSame( array(), $state['applied'], 'What was set up before is remembered, so a later removal still cleans the guest.' );
	}

	public function test_the_status_of_the_script_is_checked(): void {
		$wanted = array( '203.0.113.5', '203.0.113.6' );
		$good   = array(
			'v'         => 1,
			'run'       => 'abc',
			'ok'        => true,
			'stack'     => 'networkmanager',
			'nic'       => 'ens18',
			'profile'   => 'cloud-init ens18',
			'persisted' => true,
			'applied'   => array( '203.0.113.5', '198.51.100.1', 'nonsense' ),
			'errors'    => array(),
		);
		$parsed = Ipv4Guest::parse_status( wp_json_encode( $good ), 'abc', $wanted );

		$this->assertTrue( $parsed['ok'] );
		$this->assertSame( array( '203.0.113.5' ), $parsed['applied'], 'Only addresses that were asked for.' );
		$this->assertSame( 'networkmanager', $parsed['stack'] );
		$this->assertSame( 'cloud-init ens18', $parsed['profile'] );

		$this->assertNull( Ipv4Guest::parse_status( wp_json_encode( $good ), 'other', $wanted ), 'A status of another run.' );
		$this->assertNull( Ipv4Guest::parse_status( 'not json', 'abc', $wanted ) );
		$this->assertNull( Ipv4Guest::parse_status( wp_json_encode( array_merge( $good, array( 'v' => 2 ) ) ), 'abc', $wanted ) );

		$bad = Ipv4Guest::parse_status( wp_json_encode( array_merge( $good, array( 'nic' => '<script>x</script>', 'stack' => 'evil', 'errors' => array( "ip failed\n<b>x</b>" ) ) ) ), 'abc', $wanted );
		$this->assertSame( '', $bad['nic'] );
		$this->assertSame( '', $bad['stack'] );
		$this->assertFalse( $bad['ok'], 'An error makes it not ok even when the script said ok.' );
		$this->assertSame( array( 'ip failed x' ), $bad['errors'] );
	}

	public function test_the_page_shows_the_state_of_each_extra_and_the_real_card_name(): void {
		list( $server, , $address ) = $this->with_extra();
		$this->fake->set_guest_exec( 5000, 'ok', array( 'nic' => 'enp6s18', 'stack' => 'networkmanager', 'profile' => 'cloud-init enp6s18' ) );
		Jobs::act( $server->id(), Ipv4Guest::ACTION, array() );
		$fresh = Resource::find( $server->id() );
		$view  = Ipv4Panels::view( $fresh );
		$rows  = array_column( $view['addresses'], 'guest', 'address' );

		$this->assertSame( 'applied', $rows[ $address ] );
		$this->assertSame( '', $rows[ (string) $fresh->get( 'ip' ) ], 'The main address has no state.' );
		$this->assertSame( 'applied', $view['guest']['state'] );
		$this->assertTrue( $view['guest']['can_apply'] );
		$this->assertSame( (int) $fresh->id(), $view['guest']['resource_id'] );
		$this->assertStringContainsString( 'dev enp6s18 label enp6s18:1', $view['setup']['ifupdown'] );
		$this->assertStringContainsString( 'nmcli connection modify "cloud-init enp6s18" +ipv4.addresses ' . $address . '/32', $view['setup']['networkmanager'] );

		ob_start();
		$ipv4 = $view;
		include BELTOFT_SERVICES_PATH . 'templates/account/ipv4.php';
		$html = (string) ob_get_clean();
		$this->assertStringContainsString( 'Set up in the server', $html );
		$this->assertStringContainsString( 'class="bs-state bs-state--ok"', $html, 'The state is the status component, not a comma in the label.' );
		$this->assertStringContainsString( 'beltoft-services-vm__ipv4-name', $html, 'Address, label and state share one first line.' );
		$this->assertMatchesRegularExpression( '#<li>\s*<span class="beltoft-services-vm__ipv4-name">#', $html );
		$this->assertStringContainsString( 'data-ipv4-guest-action="ipv4_guest"', $html );
		$this->assertStringContainsString( 'Apply again', $html );
	}

	public function test_the_page_before_any_run_uses_the_old_names_in_the_lines(): void {
		$server = $this->server( 1 );
		$this->fake->set_guest_exec( 5000, 'blocked' );
		$this->addon( $server );
		$lines = Ipv4Panels::setup_lines( Resource::find( $server->id() ) );

		$this->assertStringContainsString( 'dev eth0 label eth0:1', $lines['ifupdown'] );
		$this->assertStringContainsString( '"Wired connection 1"', $lines['networkmanager'] );
	}
}

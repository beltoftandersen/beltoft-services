<?php
/**
 * Which shell the reinstall gets: ssh only for the live client, never for the fake or the test server, never injected.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\ManualServer;

use BeltoftServices\ManualServer\FakeRescueShell;
use BeltoftServices\ManualServer\RescueShells;
use BeltoftServices\ManualServer\SshRescueShell;

require_once __DIR__ . '/ManualServerTestCase.php';

final class RescueShellsTest extends ManualServerTestCase {

	public function tear_down(): void {
		remove_all_filters( 'beltoft_services_dedicated_shell' );
		parent::tear_down();
	}

	public function test_the_fake_client_and_the_test_server_get_the_fake_shell(): void {
		foreach ( array( 'fake', 'test_server', 'live' ) as $mode ) {
			$this->settings( array( 'dedicated_client' => $mode ) );
			$this->assertInstanceOf( FakeRescueShell::class, RescueShells::for(), $mode . ': no live constant here, so never a real shell.' );
		}
	}

	public function test_a_filter_cannot_bring_in_the_real_shell(): void {
		add_filter( 'beltoft_services_dedicated_shell', static fn() => new SshRescueShell() );
		$this->assertInstanceOf( FakeRescueShell::class, RescueShells::for() );
	}

	public function test_the_real_shell_refuses_addresses_that_are_not_public_and_runs_nothing(): void {
		$shell = new SshRescueShell();

		foreach ( array( '127.0.0.1', '10.0.0.5', '192.168.1.1', 'localhost', '', '::1' ) as $ip ) {
			$this->assertFalse( $shell->reachable( $ip ), $ip );
			$this->assertWPError( $shell->images( $ip, 'pw' ), $ip );
		}

		$this->assertWPError( $shell->images( '203.0.113.9', '' ), 'No password, no call.' );
	}
}

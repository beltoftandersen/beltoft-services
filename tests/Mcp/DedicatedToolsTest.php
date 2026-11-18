<?php
/**
 * The MCP tools of a dedicated server, over the fake Robot.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Mcp;

require_once dirname( __DIR__ ) . '/ManualServer/ControlsTestCase.php';
require_once __DIR__ . '/CallsTools.php';

use BeltoftServices\Api\Tokens;
use BeltoftServices\ManualServer\DedicatedReinstall;
use BeltoftServices\ManualServer\FakeRescueShell;
use BeltoftServices\ManualServer\FakeRobotClient;
use BeltoftServices\Mcp\Tools;
use BeltoftServices\Resource;
use BeltoftServices\Tests\ManualServer\ControlsTestCase;

/**
 * Levels, the dashboard's own checks, the confirmation in place of the emailed code, and that no tool shows a password.
 */
final class DedicatedToolsTest extends ControlsTestCase {

	use CallsTools;

	public function set_up(): void {
		parent::set_up();
		$this->mcp_set_up();
		add_filter( 'beltoft_services_mcp_rate_limit', '__return_zero' );
		add_filter( 'beltoft_services_rest_rate_limit', '__return_zero' );
		$this->settings( array( 'dedicated_images' => implode( "\n", FakeRescueShell::IMAGES ) ) );
	}

	public function tear_down(): void {
		$this->mcp_tear_down();
		remove_all_filters( 'beltoft_services_rest_rate_limit' );
		parent::tear_down();
	}

	/** @return array{0: Resource, 1: int} */
	private function server( bool $key = false ): array {
		$user = $this->new_user();
		$r    = $this->controlled( $key, $user );

		return array( $r, $user );
	}

	private function robot_calls( string $call ): array {
		return array_values( array_filter( FakeRobotClient::calls(), static fn( array $c ): bool => $call === $c['call'] ) );
	}

	public function test_the_levels(): void {
		$defs = Tools::definitions();

		$this->assertSame( Tokens::READ, $defs['dedicated_info']['level'] );

		foreach ( array( 'dedicated_restart', 'dedicated_power_on', 'dedicated_set_rdns', 'dedicated_rescue' ) as $name ) {
			$this->assertSame( Tokens::ACTIONS, $defs[ $name ]['level'], $name );
			$this->assertFalse( $defs[ $name ]['confirm'], $name );
		}

		foreach ( array( 'dedicated_hard_reset', 'dedicated_set_firewall', 'dedicated_reinstall' ) as $name ) {
			$this->assertSame( Tokens::FULL, $defs[ $name ]['level'], $name );
			$this->assertTrue( $defs[ $name ]['confirm'], $name );
		}
	}

	public function test_info_and_the_everyday_actions(): void {
		list( $r, $user ) = $this->server();
		$id    = $r->id();
		$token = $this->oauth( $user, Tokens::ACTIONS );

		$info = $this->json( $this->tool( 'dedicated_info', array( 'id' => $id ), $token ) );
		$this->assertSame( array( 'sw', 'hw' ), array_column( $info['reset'], 'type' ) );
		$this->assertEqualsCanonicalizing( FakeRescueShell::IMAGES, $info['reinstall_systems'] );

		$this->assertTrue( $this->json( $this->tool( 'dedicated_restart', array( 'id' => $id ), $token ) )['restarted'] );
		$this->assertSame( 'sw', $this->robot_calls( 'reset' )[0]['type'] );

		$this->assertTrue( $this->json( $this->tool( 'dedicated_power_on', array( 'id' => $id ), $token ) )['signal_sent'] );

		$set = $this->json( $this->tool( 'dedicated_set_rdns', array( 'id' => $id, 'ip' => $this->ipv4(), 'ptr' => 'srv.example.com' ), $token ) );
		$this->assertSame( 'srv.example.com', $set['name'] );
		$gone = $this->json( $this->tool( 'dedicated_set_rdns', array( 'id' => $id, 'ip' => $this->ipv4(), 'ptr' => '' ), $token ) );
		$this->assertSame( $this->ipv4(), $gone['removed'] );
		$this->assertTrue( $this->tool( 'dedicated_set_rdns', array( 'id' => $id, 'ip' => '8.8.8.8', 'ptr' => 'x.example.com' ), $token )['isError'] );
	}

	public function test_the_rescue_tool_works_only_with_the_customers_key_and_shows_no_password(): void {
		list( $r, $user ) = $this->server( false );
		$token = $this->oauth( $user, Tokens::ACTIONS );

		$refused = $this->tool( 'dedicated_rescue', array( 'id' => $r->id(), 'on' => true ), $token );
		$this->assertTrue( $refused['isError'] );
		$this->assertStringContainsString( 'dashboard', $this->text( $refused ) );
		$this->assertSame( array(), $this->robot_calls( 'rescue_activate' ), 'No password is made for a tool.' );

		list( $k, $u2 ) = $this->server( true );
		$t2   = $this->oauth( $u2, Tokens::ACTIONS );
		$done = $this->json( $this->tool( 'dedicated_rescue', array( 'id' => $k->id(), 'on' => true, 'restart' => true ), $t2 ) );
		$this->assertSame( 'on', $done['rescue'] );
		$this->assertTrue( $done['restarted'] );
		$this->assertStringNotContainsString( 'password', strtolower( (string) wp_json_encode( $done ) ) );

		$off = $this->json( $this->tool( 'dedicated_rescue', array( 'id' => $k->id(), 'on' => false ), $t2 ) );
		$this->assertSame( 'off', $off['rescue'] );
	}

	public function test_a_stranger_and_a_read_token_are_refused(): void {
		list( $r ) = $this->server();
		$stranger = $this->oauth( $this->new_user(), Tokens::FULL );
		$this->assertTrue( $this->tool( 'dedicated_info', array( 'id' => $r->id() ), $stranger )['isError'] );
		$this->assertTrue( $this->tool( 'dedicated_restart', array( 'id' => $r->id() ), $stranger )['isError'] );

		$read = $this->oauth( (int) $r->license()['user_id'], Tokens::READ );
		$this->assertFalse( $this->tool( 'dedicated_info', array( 'id' => $r->id() ), $read )['isError'] );
		$this->assertTrue( $this->tool( 'dedicated_restart', array( 'id' => $r->id() ), $read )['isError'] );
		$this->assertTrue( $this->tool( 'dedicated_hard_reset', array( 'id' => $r->id(), 'type' => 'hw' ), $read )['isError'] );
	}

	public function test_a_hard_reset_is_summarised_and_runs_only_after_the_confirmation(): void {
		list( $r, $user ) = $this->server();
		$token = $this->oauth( $user, Tokens::FULL );
		$args  = array( 'id' => $r->id(), 'type' => 'hw' );

		$first = $this->json( $this->tool( 'dedicated_hard_reset', $args, $token ) );
		$this->assertTrue( $first['requires_confirmation'] );
		$this->assertStringContainsString( 'power failure', $first['summary'] );
		$this->assertSame( array(), $this->robot_calls( 'reset' ), 'Nothing is sent before the confirmation.' );

		$done = $this->json( $this->tool( 'dedicated_hard_reset', $args + array( 'confirm' => $first['confirm'] ), $token ) );
		$this->assertTrue( $done['done'] );
		$this->assertSame( 'hw', $this->robot_calls( 'reset' )[0]['type'], 'No emailed code over a tool: the confirmation is the yes.' );
	}

	public function test_the_everyday_restart_cannot_do_a_hardware_reset(): void {
		list( $r, $user ) = $this->server();
		$token = $this->oauth( $user, Tokens::ACTIONS );

		$bad = $this->tool( 'dedicated_hard_reset', array( 'id' => $r->id(), 'type' => 'hw' ), $token );
		$this->assertTrue( $bad['isError'], 'Everyday access has no hardware reset.' );
		$this->assertSame( array(), $this->robot_calls( 'reset' ) );
	}

	public function test_the_firewall_tool_warns_about_a_lockout_and_replaces_the_rules_after_the_confirmation(): void {
		list( $r, $user ) = $this->server();
		$token = $this->oauth( $user, Tokens::FULL );
		$args  = array( 'id' => $r->id(), 'status' => 'active', 'input' => array( array( 'action' => 'accept', 'ip_version' => 'ipv4', 'protocol' => 'tcp', 'dst_port' => '443', 'name' => 'web' ) ) );

		$first = $this->json( $this->tool( 'dedicated_set_firewall', $args, $token ) );
		$this->assertStringContainsString( 'lock them out', $first['summary'] );
		$this->assertSame( array(), $this->robot_calls( 'firewall_set' ) );

		$this->json( $this->tool( 'dedicated_set_firewall', $args + array( 'confirm' => $first['confirm'] ), $token ) );
		$this->assertSame( 'active', $this->robot_calls( 'firewall_set' )[0]['status'] );

		$bad = $this->tool( 'dedicated_set_firewall', array( 'id' => $r->id(), 'status' => 'active', 'input' => array( array( 'action' => 'accept', 'dst_port' => '99999' ) ) ), $token );
		$this->assertTrue( $bad['isError'] );
	}

	public function test_a_reinstall_names_a_listed_system_confirms_and_starts_the_job_without_a_code(): void {
		list( $r, $user ) = $this->server();
		$token = $this->oauth( $user, Tokens::FULL );
		$args  = array( 'id' => $r->id(), 'image' => 'Debian-1201-bookworm-amd64-base.tar.gz' );

		$this->assertTrue( $this->tool( 'dedicated_reinstall', array( 'id' => $r->id(), 'image' => 'Made-up.tar.gz' ), $token )['isError'], 'Only a listed system.' );

		$first = $this->json( $this->tool( 'dedicated_reinstall', $args, $token ) );
		$this->assertStringContainsString( 'Erase all disks', $first['summary'] );
		$this->assertStringContainsString( 'Debian-1201-bookworm-amd64-base.tar.gz', $first['summary'] );
		$this->assertNull( Resource::find( $r->id() )->get( DedicatedReinstall::META ) );

		$done = $this->json( $this->tool( 'dedicated_reinstall', $args + array( 'confirm' => $first['confirm'] ), $token ) );
		$this->assertTrue( $done['queued'] );
		$this->assertSame( 'running', Resource::find( $r->id() )->get( DedicatedReinstall::META )['state'] );
	}

	public function test_no_tool_shows_a_password(): void {
		foreach ( Tools::definitions() as $name => $def ) {
			if ( 0 === strpos( $name, 'dedicated_' ) ) {
				$this->assertFalse( $def['secret'], $name );
			}
		}
	}
}

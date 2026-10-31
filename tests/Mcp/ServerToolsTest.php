<?php
/**
 * The tools of a virtual server on the fake cluster: firewall, usage e-mails, console link, status. Nothing here reaches a
 * Proxmox host.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Mcp;

use BeltoftLicenses\Events;
use BeltoftServices\Api\Tokens;
use BeltoftServices\Jobs;
use BeltoftServices\Resource;
use BeltoftServices\Tests\Proxmox\FirewallTestCase;

require_once dirname( __DIR__ ) . '/Proxmox/FirewallTestCase.php';
require_once __DIR__ . '/CallsTools.php';

/**
 * Server tool tests.
 */
final class ServerToolsTest extends FirewallTestCase {

	use CallsTools;

	public function set_up(): void {
		parent::set_up();
		$this->mcp_set_up();
		add_filter( 'beltoft_services_mcp_rate_limit', '__return_zero' );
	}

	public function tear_down(): void {
		$this->mcp_tear_down();
		parent::tear_down();
	}

	/**
	 * A ready server with the firewall on offer, its owner, and an actions token.
	 *
	 * @return array{0: Resource, 1: string, 2: string} Server, an actions token, a full token.
	 */
	private function server_with_tokens(): array {
		list( $r, $user ) = $this->ready();
		$this->save_cluster( array( 'fw_enabled' => '1' ) );

		return array( Resource::find( $r->id() ), $this->oauth( $user, Tokens::ACTIONS ), $this->oauth( $user, Tokens::FULL ) );
	}

	/**
	 * @return string[]
	 */
	private function history( Resource $r ): array {
		return array_column( Events::for_license( (int) $r->license()['id'] ), 'message' );
	}

	public function test_get_firewall_shows_the_state_but_not_the_visitors_address(): void {
		list( $r, $token ) = $this->server_with_tokens();

		$data = $this->json( $this->tool( 'get_firewall', array( 'id' => $r->id() ), $token ) );

		$this->assertTrue( $data['editable'] );
		$this->assertFalse( $data['managed'] );
		$this->assertArrayNotHasKey( 'my_ip', $data );
		$this->assertArrayNotHasKey( 'now', $data );
		$this->assertStringNotContainsString( '203.0.113.9', $this->text( $this->tool( 'get_firewall', array( 'id' => $r->id() ), $token ) ) );
		$this->assertContains( 'Claude: firewall read', $this->history( $r ) );

		$this->assertTrue( $this->tool( 'get_firewall', array( 'id' => $r->id() ), $this->oauth( self::factory()->user->create() ) )['isError'] );
	}

	public function test_set_firewall_never_uses_the_apps_address_for_the_ssh_check(): void {
		list( $r, $token ) = $this->server_with_tokens();
		$_SERVER['REMOTE_ADDR'] = '203.0.113.5';
		$limited = array( array( 'proto' => 'tcp', 'from' => 22, 'sources' => array( '203.0.113.5' ), 'label' => 'Office' ) );

		// The visitor is covered by the rule, but the visitor is the app, so it does not count.
		$refused = $this->tool( 'set_firewall', array( 'id' => $r->id(), 'on' => true, 'rules' => $limited ), $token );
		$this->assertTrue( $refused['isError'] );
		$this->assertStringContainsString( 'SSH', $this->text( $refused ) );
		$this->assertFalse( Jobs::acting( $r->id() ) );

		// An address the call names that the rules do not cover.
		$this->assertTrue( $this->tool( 'set_firewall', array( 'id' => $r->id(), 'on' => true, 'rules' => $limited, 'my_ip' => '198.51.100.9' ), $token )['isError'] );

		// The customer's own address, named by the call.
		$ok = $this->tool( 'set_firewall', array( 'id' => $r->id(), 'on' => true, 'rules' => $limited, 'my_ip' => '203.0.113.5' ), $token );
		$this->assertFalse( $ok['isError'], $this->text( $ok ) );
		$this->assertTrue( Jobs::acting( $r->id() ) );
		$this->assertContains( 'Claude: firewall change requested', $this->history( $r ) );
		$this->assertContains( 'Claude: firewall change requested (refused)', $this->history( $r ) );
		unset( $_SERVER['REMOTE_ADDR'] );
	}

	public function test_set_firewall_accepts_ssh_from_anywhere_and_checks_every_rule(): void {
		list( $r, $token ) = $this->server_with_tokens();

		$bad = $this->tool( 'set_firewall', array( 'id' => $r->id(), 'on' => true, 'rules' => array( array( 'proto' => 'tcp', 'from' => 99999 ) ) ), $token );
		$this->assertTrue( $bad['isError'], 'The schema keeps ports to 1 to 65535.' );
		$this->assertTrue( $this->tool( 'set_firewall', array( 'id' => $r->id(), 'on' => true, 'rules' => array( array( 'proto' => 'tcp', 'from' => 80, 'sources' => array( '10.0.0.1' ) ) ) ), $token )['isError'], 'The dashboard refuses a private source.' );

		$ok = $this->tool( 'set_firewall', array( 'id' => $r->id(), 'on' => true, 'rules' => array( array( 'proto' => 'tcp', 'from' => 22 ), array( 'proto' => 'tcp', 'from' => 443 ) ) ), $token );
		$this->assertFalse( $ok['isError'], $this->text( $ok ) );
		$this->assertCount( 2, Resource::find( $r->id() )->get( 'fw_rules' ) );
	}

	public function test_turning_the_firewall_off_needs_full_and_a_confirmation_that_does_not_exist_yet(): void {
		list( $r, $actions, $full ) = $this->server_with_tokens();
		$args   = array( 'id' => $r->id(), 'on' => false, 'rules' => array() );
		$before = $this->history( $r );

		$low = $this->tool( 'set_firewall', $args, $actions );
		$this->assertTrue( $low['isError'] );
		$this->assertStringContainsString( 'Full access', $this->text( $low ) );

		$high = $this->tool( 'set_firewall', $args, $full );
		$this->assertTrue( $high['isError'] );
		$this->assertStringContainsString( 'confirmation', $this->text( $high ) );

		$this->assertFalse( Jobs::acting( $r->id() ) );
		$this->assertSame( $before, $this->history( $r ), 'A call that was stopped at the gate writes nothing.' );
	}

	public function test_usage_emails_console_link_status_and_recovery_points_of_a_server(): void {
		list( $r, $token ) = $this->server_with_tokens();

		$off = $this->json( $this->tool( 'set_usage_alerts', array( 'id' => $r->id(), 'on' => false ), $token ) );
		$this->assertSame( 'off', $off['usage_emails'] );
		$this->assertContains( 'Claude: usage e-mails turned off', $this->history( $r ) );
		$this->assertSame( 'on', $this->json( $this->tool( 'set_usage_alerts', array( 'id' => $r->id(), 'on' => true ), $token ) )['usage_emails'] );

		$link = $this->json( $this->tool( 'console_link', array( 'id' => $r->id() ), $token ) );
		$this->assertStringContainsString( '/console', $link['console_page'] );
		$this->assertStringNotContainsString( 'nonce', $link['console_page'] );
		$this->assertStringNotContainsString( '_wpnonce', $link['console_page'] );
		$this->assertTrue( $this->tool( 'console_link', array( 'id' => $r->id() ), $this->oauth( self::factory()->user->create() ) )['isError'] );

		$status = $this->json( $this->tool( 'server_status', array( 'id' => $r->id() ), $token ) );
		$this->assertSame( 'ready', $status['state'] );
		$this->assertArrayHasKey( 'ip', $status );

		$details = $this->json( $this->tool( 'service_details', array( 'id' => $r->id() ), $token ) );
		$this->assertSame( 'vm', $details['type'] );
		$this->assertArrayHasKey( 'firewall', $details['server'] );
		$this->assertStringNotContainsString( 'fw_error', $this->text( $this->tool( 'service_details', array( 'id' => $r->id() ), $token ) ) );

		$points = $this->json( $this->tool( 'list_recovery_points', array( 'id' => $r->id() ), $token ) );
		$this->assertArrayHasKey( 'backups', $points );
		$this->assertArrayHasKey( 'snapshots', $points );

		// Nothing the dashboard keeps secret is in any of it.
		$all = $this->text( $this->tool( 'service_details', array( 'id' => $r->id() ), $token ) ) . $this->text( $this->tool( 'list_recovery_points', array( 'id' => $r->id() ), $token ) );
		$this->assertStringNotContainsString( self::SECRET, $all );
	}
}

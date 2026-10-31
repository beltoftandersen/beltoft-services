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
		$this->assertNotContains( 'Claude: firewall read', $this->history( $r ), 'Reads leave no row in the history.' );

		$this->assertTrue( $this->tool( 'get_firewall', array( 'id' => $r->id() ), $this->oauth( self::factory()->user->create() ) )['isError'] );
	}

	public function test_set_firewall_does_not_trust_an_address_from_the_app_and_keeps_ssh_open(): void {
		list( $r, $token ) = $this->server_with_tokens();
		$_SERVER['REMOTE_ADDR'] = '203.0.113.5';
		$limited = array( array( 'proto' => 'tcp', 'from' => 22, 'sources' => array( '203.0.113.5' ), 'label' => 'Office' ) );

		// There is no argument for an address: the model's word is not taken.
		$this->assertTrue( $this->tool( 'set_firewall', array( 'id' => $r->id(), 'on' => true, 'rules' => $limited, 'my_ip' => '203.0.113.5' ), $token )['isError'] );

		// The visitor is covered by the rule, but the visitor is the app, so it does not count.
		$refused = $this->tool( 'set_firewall', array( 'id' => $r->id(), 'on' => true, 'rules' => $limited ), $token );
		$this->assertTrue( $refused['isError'] );
		$this->assertStringContainsString( 'SSH', $this->text( $refused ) );
		$this->assertFalse( Jobs::acting( $r->id() ) );
		$this->assertContains( 'Claude: firewall change requested (refused)', $this->history( $r ) );
		unset( $_SERVER['REMOTE_ADDR'] );

		// SSH open to anyone is fine.
		$open = $this->tool( 'set_firewall', array( 'id' => $r->id(), 'on' => true, 'rules' => array( array( 'proto' => 'tcp', 'from' => 22 ) ) ), $token );
		$this->assertFalse( $open['isError'], $this->text( $open ) );
	}

	public function test_a_managed_firewall_may_change_other_rules_while_the_ssh_rules_stay_as_they_are(): void {
		list( $r, $token ) = $this->server_with_tokens();
		$web     = array( 'proto' => 'tcp', 'from' => 443 );
		$ssh_ip  = array( 'proto' => 'tcp', 'from' => 22, 'sources' => array( '203.0.113.5' ) );

		// The customer set it up in the dashboard: SSH only from one address.
		$this->put_in_dashboard( $r, array( $ssh_ip ) );

		$same = $this->tool( 'set_firewall', array( 'id' => $r->id(), 'on' => true, 'rules' => array( $ssh_ip, $web ) ), $token );
		$this->assertFalse( $same['isError'], $this->text( $same ) );

		// Another address for SSH is a change of the SSH rules: refused.
		$this->assertTrue( $this->tool( 'set_firewall', array( 'id' => $r->id(), 'on' => true, 'rules' => array( array( 'proto' => 'tcp', 'from' => 22, 'sources' => array( '198.51.100.9' ) ) ) ), $token )['isError'] );
		// Removing SSH is a change too.
		$this->assertTrue( $this->tool( 'set_firewall', array( 'id' => $r->id(), 'on' => true, 'rules' => array( $web ) ), $token )['isError'] );
	}

	/**
	 * What the dashboard's own PUT stores, signed in as the owner without a connected app.
	 *
	 * @param array<int, array<string, mixed>> $rules Rules.
	 */
	private function put_in_dashboard( Resource $r, array $rules ): void {
		$_SERVER['REMOTE_ADDR'] = '203.0.113.5';
		$response               = $this->route( 'PUT', $r->id(), (int) $r->license()['user_id'], array( 'on' => true, 'rules' => $rules ) );
		unset( $_SERVER['REMOTE_ADDR'] );
		$this->assertSame( 202, $response->get_status() );
		Jobs::run( $r->id() );
		wp_set_current_user( 0 );
	}

	public function test_a_rule_set_that_opens_a_wide_range_to_anyone_is_the_same_as_turning_the_firewall_off(): void {
		list( $r, $actions, $full ) = $this->server_with_tokens();
		$before = $this->history( $r );

		foreach ( array(
			array( array( 'proto' => 'tcp', 'from' => 1, 'to' => 65535 ) ),
			array( array( 'proto' => 'udp', 'from' => 1000, 'to' => 1100 ) ),
			array( array( 'proto' => 'tcp', 'from' => 22 ), array( 'proto' => 'tcp', 'from' => 2000, 'to' => 2200, 'sources' => array() ) ),
		) as $rules ) {
			$args = array( 'id' => $r->id(), 'on' => true, 'rules' => $rules );
			$low  = $this->tool( 'set_firewall', $args, $actions );
			$this->assertStringContainsString( 'Full access', $this->text( $low ) );
			$high = $this->json( $this->tool( 'set_firewall', $args, $full ) );
			$this->assertTrue( $high['requires_confirmation'] );
			$this->assertStringContainsString( 'many ports', $high['summary'] );
		}

		$this->assertFalse( Jobs::acting( $r->id() ), 'Only summaries so far.' );

		// A wide range from named addresses, or 100 ports to anyone in all, is an ordinary rule.
		$ok = $this->tool( 'set_firewall', array( 'id' => $r->id(), 'on' => true, 'rules' => array( array( 'proto' => 'tcp', 'from' => 22 ), array( 'proto' => 'tcp', 'from' => 1, 'to' => 65535, 'sources' => array( '203.0.113.0/24' ) ), array( 'proto' => 'tcp', 'from' => 3000, 'to' => 3098 ) ) ), $actions );
		$this->assertFalse( $ok['isError'], $this->text( $ok ) );
		$this->assertNotSame( $before, $this->history( $r ) );
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

	public function test_turning_the_firewall_off_needs_full_and_the_customers_confirmation(): void {
		list( $r, $actions, $full ) = $this->server_with_tokens();
		$args   = array( 'id' => $r->id(), 'on' => false, 'rules' => array() );

		$low = $this->tool( 'set_firewall', $args, $actions );
		$this->assertTrue( $low['isError'] );
		$this->assertStringContainsString( 'Full access', $this->text( $low ) );
		$this->assertFalse( Jobs::acting( $r->id() ) );

		// First call: a summary, nothing else.
		$first = $this->json( $this->tool( 'set_firewall', $args, $full ) );
		$this->assertTrue( $first['requires_confirmation'] );
		$this->assertStringContainsString( 'off', $first['summary'] );
		$this->assertFalse( Jobs::acting( $r->id() ) );
		$this->assertNotContains( 'Claude: firewall change requested', $this->history( $r ) );

		// Second call with the token: the dashboard's own "are you sure" is answered by the server.
		$done = $this->tool( 'set_firewall', $args + array( 'confirm' => $first['confirm'] ), $full );
		$this->assertFalse( $done['isError'], $this->text( $done ) );
		$this->assertTrue( Jobs::acting( $r->id() ) );
		$this->assertContains( 'Claude: firewall change requested', $this->history( $r ) );
		$this->assertContains( 'Claude: set firewall confirmed by the customer', $this->history( $r ) );

		// The same token does nothing a second time.
		$again = $this->tool( 'set_firewall', $args + array( 'confirm' => $first['confirm'] ), $full );
		$this->assertTrue( $again['isError'] );
		$this->assertStringContainsString( 'used already', $this->text( $again ) );
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

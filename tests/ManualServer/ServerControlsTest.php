<?php
/**
 * Restart and power, the rescue system, reverse DNS and the firewall of a dedicated server, against the fake Robot.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\ManualServer;

use BeltoftServices\ManualServer\FakeRobotClient;
use BeltoftServices\ManualServer\FirewallRules;
use BeltoftServices\ManualServer\ServerControls;
use BeltoftServices\Resource;

require_once __DIR__ . '/ControlsTestCase.php';

final class ServerControlsTest extends ControlsTestCase {

	private function last_call( string $call ): array {
		$found = array_values( array_filter( FakeRobotClient::calls(), static fn( array $c ): bool => $call === $c['call'] ) );

		return $found ? end( $found ) : array();
	}

	public function test_the_controls_need_a_delivered_server_with_a_supplier_number(): void {
		$v = $this->server_product();
		$r = $this->ready_server( $v['u'], $this->new_user() );

		$this->assertSame( 0, ServerControls::number( $r ), 'Delivered by hand: no number.' );
		$this->assertWPError( ServerControls::reset( $r, 'sw' ) );
		$this->assertSame( array(), FakeRobotClient::calls() );
		$this->assertSame( self::NUMBER, ServerControls::number( $this->controlled() ) );
	}

	public function test_the_overview_lists_what_the_server_offers_and_no_password(): void {
		$r        = $this->controlled();
		$overview = ServerControls::overview( $r );

		$this->assertSame( array( 'sw', 'hw' ), array_column( $overview['reset'], 'type' ), 'man is never offered.' );
		$this->assertSame( array( false, true ), array_column( $overview['reset'], 'code' ), 'Only the software restart needs no code.' );
		$this->assertTrue( $overview['wol'] );
		$this->assertSame( array( 'active' => false, 'password' => false, 'key' => false ), $overview['rescue'] );
		$this->assertSame( 'disabled', $overview['firewall']['status'] );
		$this->assertSame( array(), $overview['rdns'] );
		$this->assertSame( array( $this->ipv4() ), $overview['addresses']['ipv4'] );
	}

	public function test_reset_sends_only_a_type_the_server_lists(): void {
		$r = $this->controlled();

		$this->assertIsArray( ServerControls::reset( $r, 'sw' ) );
		$this->assertSame( 'sw', $this->last_call( 'reset' )['type'] );

		$bad = ServerControls::reset( $r, 'power' );
		$this->assertWPError( $bad );
		$this->assertSame( 'dedicated_invalid', $bad->get_error_code(), 'The fake server lists sw, hw and man only.' );

		$this->assertSame( 'dedicated_invalid', ServerControls::reset( $r, 'man' )->get_error_code(), 'A manual reset is never sent.' );
		$this->assertSame( 1, count( array_filter( FakeRobotClient::calls(), static fn( $c ) => 'reset' === $c['call'] ) ) );
	}

	public function test_wake_on_lan_and_a_server_without_it(): void {
		$r = $this->controlled();
		$this->assertIsArray( ServerControls::wol( $r ) );
		$this->assertSame( self::NUMBER, $this->last_call( 'wol' )['number'] );

		FakeRobotClient::add_server( self::NUMBER, array( 'sw', 'hw' ), false );
		$why = ServerControls::wol( Resource::find( $r->id() ) );
		$this->assertSame( 'dedicated_wol_not_available', $why->get_error_code() );
		$this->assertStringNotContainsString( 'WOL', $why->get_error_message() );
	}

	public function test_rescue_without_a_key_keeps_the_returned_password_encrypted(): void {
		$r = $this->controlled();

		$this->assertSame( array( 'active' => true, 'rebooting' => false ), ServerControls::rescue_activate( $r, false ) );
		$call = $this->last_call( 'rescue_activate' );
		$this->assertSame( array(), $call['keys'] );

		$fresh    = Resource::find( $r->id() );
		$password = FakeRobotClient::rescue_password( self::NUMBER );
		$this->assertNotSame( '', $password );
		$this->assertSame( $password, ServerControls::rescue_password( $fresh ) );
		$this->assertStringNotContainsString( $password, (string) $fresh->get( ServerControls::RESCUE_PASSWORD ), 'Encrypted at rest.' );
		$this->assertStringNotContainsString( $password, (string) wp_json_encode( ServerControls::overview( $fresh ) ), 'Not in the overview.' );
		$this->assertTrue( ServerControls::overview( $fresh )['rescue']['password'] );

		$this->assertSame( array( 'active' => false ), ServerControls::rescue_deactivate( $fresh ) );
		$this->assertNull( ServerControls::rescue_password( Resource::find( $r->id() ) ), 'Wiped when the rescue system is switched off.' );
	}

	public function test_rescue_with_the_customers_key_uses_the_key_and_stores_no_password(): void {
		$r = $this->controlled( true );

		$this->assertIsArray( ServerControls::rescue_activate( $r, false ) );
		$call = $this->last_call( 'rescue_activate' );
		$this->assertCount( 1, $call['keys'] );
		$this->assertSame( $call['keys'][0], (string) Resource::find( $r->id() )->get( 'robot_key_fingerprint' ) );
		$this->assertNull( ServerControls::rescue_password( Resource::find( $r->id() ) ) );
	}

	public function test_rescue_and_boot_restarts_with_the_software_reset(): void {
		$r = $this->controlled();

		$this->assertSame( array( 'active' => true, 'rebooting' => true ), ServerControls::rescue_activate( $r, true ) );
		$this->assertSame( 'sw', $this->last_call( 'reset' )['type'] );
	}

	public function test_reverse_dns_for_the_ipv4_address_and_inside_the_ipv6_net(): void {
		$r = $this->controlled();

		$this->assertIsArray( ServerControls::rdns_set( $r, $this->ipv4(), 'srv.example.com.' ) );
		$this->assertSame( 'srv.example.com', $this->last_call( 'rdns_set' )['ptr'], 'The trailing dot is dropped.' );

		$six = '2001:db8:' . dechex( self::NUMBER % 65535 ) . '::25';
		$this->assertIsArray( ServerControls::rdns_set( $r, $six, 'mail.example.com' ) );
		$this->assertSame( array( $this->ipv4() => 'srv.example.com', $six => 'mail.example.com' ), array_column( ServerControls::overview( $r )['rdns'], 'ptr', 'ip' ) );

		foreach ( array( '192.0.2.200', '2001:db8:ffff::1', '10.0.0.1', 'not an ip' ) as $foreign ) {
			$this->assertSame( 'dedicated_invalid', ServerControls::rdns_set( $r, $foreign, 'x.example.com' )->get_error_code(), $foreign );
		}

		$this->assertSame( 'dedicated_invalid', ServerControls::rdns_set( $r, $this->ipv4(), 'no_dots' )->get_error_code() );
		$this->assertIsArray( ServerControls::rdns_delete( $r, $this->ipv4() ) );
		$this->assertArrayNotHasKey( $this->ipv4(), array_column( ServerControls::overview( $r )['rdns'], 'ptr', 'ip' ) );
	}

	public function test_firewall_rules_are_checked_against_the_documented_fields(): void {
		$ok = FirewallRules::clean( array( array( 'name' => 'ssh', 'ip_version' => 'ipv4', 'protocol' => 'tcp', 'dst_port' => '22', 'src_ip' => '203.0.113.0/24', 'action' => 'accept' ) ) );
		$this->assertSame( 'accept', $ok[0]['action'] );

		$bad = array(
			array( array( 'name' => 'x' ) ),
			array( array( 'action' => 'accept', 'protocol' => 'tcp' ) ),
			array( array( 'action' => 'accept', 'ip_version' => 'ipv6', 'src_ip' => '203.0.113.1' ) ),
			array( array( 'action' => 'accept', 'ip_version' => 'ipv4', 'src_ip' => '999.1.1.1' ) ),
			array( array( 'action' => 'accept', 'ip_version' => 'ipv4', 'protocol' => 'icmpv6' ) ),
			array( array( 'action' => 'accept', 'dst_port' => '70000' ) ),
			array( array( 'action' => 'drop' ) ),
		);

		foreach ( $bad as $i => $rules ) {
			$this->assertWPError( FirewallRules::clean( $rules ), (string) $i );
		}
	}

	public function test_only_a_rule_set_that_leaves_ssh_closed_is_risky(): void {
		$ssh  = array( array( 'action' => 'accept', 'ip_version' => 'ipv4', 'protocol' => 'tcp', 'dst_port' => '22' ) );
		$web  = array( array( 'action' => 'accept', 'ip_version' => 'ipv4', 'protocol' => 'tcp', 'dst_port' => '443' ) );
		$any  = array( array( 'action' => 'accept' ) );
		$from = array( array( 'action' => 'accept', 'ip_version' => 'ipv4', 'protocol' => 'tcp', 'dst_port' => '22', 'src_ip' => '203.0.113.5' ) );

		$this->assertFalse( FirewallRules::risky( 'active', $ssh ) );
		$this->assertFalse( FirewallRules::risky( 'active', $any ) );
		$this->assertFalse( FirewallRules::risky( 'active', array( array( 'action' => 'accept', 'dst_port' => '20-30', 'ip_version' => 'ipv4', 'protocol' => 'tcp' ) ) ) );
		$this->assertTrue( FirewallRules::risky( 'active', $web ) );
		$this->assertTrue( FirewallRules::risky( 'active', array() ) );
		$this->assertTrue( FirewallRules::risky( 'active', $from ), 'Only one address may log in: that is a lockout risk.' );
		$this->assertFalse( FirewallRules::risky( 'disabled', array() ) );
	}

	public function test_a_firewall_change_keeps_the_output_rules_and_the_flags(): void {
		$r = $this->controlled();

		$rules = FirewallRules::clean( array( array( 'name' => 'ssh', 'ip_version' => 'ipv4', 'protocol' => 'tcp', 'dst_port' => '22', 'action' => 'accept' ) ) );
		$this->assertIsArray( ServerControls::firewall_set( $r, 'active', $rules ) );

		$view = ServerControls::overview( $r )['firewall'];
		$this->assertSame( 'active', $view['status'] );
		$this->assertTrue( $view['whitelist_hos'], 'The flag the customer did not change stays.' );
		$this->assertSame( '22', $view['input'][0]['dst_port'] );
		$this->assertSame( 1, $this->last_call( 'firewall_set' )['input'] );
	}

	public function test_a_change_is_refused_while_a_reinstall_runs_but_rdns_is_not(): void {
		$r = $this->controlled();
		$r->set( array( \BeltoftServices\ManualServer\DedicatedReinstall::META => array( 'state' => 'running', 'step' => 'wait' ) ) );
		$r = Resource::find( $r->id() );

		$this->assertSame( 'dedicated_busy', ServerControls::reset( $r, 'sw' )->get_error_code() );
		$this->assertSame( 'dedicated_busy', ServerControls::rescue_activate( $r, false )->get_error_code() );
		$this->assertIsArray( ServerControls::rdns_set( $r, $this->ipv4(), 'a.example.com' ) );
	}

	public function test_a_supplier_error_becomes_a_plain_sentence_without_the_supplier(): void {
		$r = $this->controlled();
		FakeRobotClient::add_server( self::NUMBER, array(), true );

		$why = ServerControls::reset( Resource::find( $r->id() ), 'sw' );
		$this->assertSame( 'dedicated_reset_not_available', $why->get_error_code() );
		$this->assertSame( 'RESET_NOT_AVAILABLE', $why->get_error_data()['robot'] );
		$this->assertStringNotContainsStringIgnoringCase( 'hetzner', $why->get_error_message() );
		$this->assertStringNotContainsString( 'RESET', $why->get_error_message() );
	}
}

<?php
/**
 * The firewall helper: the fake client's firewall, validation, the apply job, the build step. Fake client only.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Proxmox;

use BeltoftServices\Jobs;
use BeltoftServices\Proxmox\Firewall;
use BeltoftServices\Proxmox\Provision;
use BeltoftServices\Resource;

require_once __DIR__ . '/FirewallTestCase.php';

class FirewallTest extends FirewallTestCase {

	private const NODE = 'node-a';

	private function base( Resource $r ): string {
		return '/nodes/' . self::NODE . '/qemu/' . (int) $r->get( 'vmid' ) . '/firewall';
	}

	/**
	 * A server whose firewall the dashboard manages: the switch on, a ready server, the wanted state stored and applied.
	 *
	 * @param array<int, array<string, mixed>> $rules Rules (request shape).
	 * @param bool                             $on    On or off.
	 */
	private function managed( array $rules, bool $on = true, array $meta = array() ): Resource {
		list( $r ) = $this->ready( $meta );
		$this->save_cluster( array( 'fw_enabled' => '1' ) );

		return $this->apply( $r, $rules, $on );
	}

	private function apply( Resource $r, array $rules, bool $on = true ): Resource {
		$r      = Resource::find( $r->id() );
		$normal = Firewall::normalise( $rules, $r );
		$this->assertIsArray( $normal, is_wp_error( $normal ) ? $normal->get_error_message() : '' );
		$this->assertGreaterThan( 0, Firewall::store( $r, $on, $normal, true, 1 ) );
		Jobs::act( $r->id(), 'fw_apply', array() );

		return Resource::find( $r->id() );
	}

	// -- The fake client -----------------------------------------------------------------------------

	public function test_fake_creates_a_rule_without_enable_disabled_and_refuses_a_line_feed(): void {
		list( $r ) = $this->ready();
		$base      = $this->base( $r );

		$this->assertNull( $this->fake->request( 'POST', $base . '/rules', array( 'type' => 'in', 'action' => 'ACCEPT', 'proto' => 'tcp', 'dport' => '8080', 'comment' => 'x' ) ) );
		$rules = $this->fake->request( 'GET', $base . '/rules' );
		$this->assertSame( 0, $rules[ count( $rules ) - 1 ]['enable'] );

		$bad = $this->fake->request( 'POST', $base . '/rules', array( 'type' => 'in', 'action' => 'ACCEPT', 'comment' => "a\nb" ) );
		$this->assertWPError( $bad );
	}

	public function test_fake_rule_put_and_delete_use_the_digest_and_renumber(): void {
		list( $r ) = $this->ready();
		$base      = $this->base( $r );

		foreach ( array( 'a', 'b', 'c' ) as $c ) {
			$this->fake->request( 'POST', $base . '/rules', array( 'type' => 'in', 'action' => 'ACCEPT', 'enable' => 1, 'comment' => $c ) );
		}

		$rules  = $this->fake->request( 'GET', $base . '/rules' );
		$offset = count( $rules ) - 3;
		$digest = $rules[0]['digest'];

		$this->assertWPError( $this->fake->request( 'DELETE', $base . '/rules/' . $offset, array( 'digest' => str_repeat( '0', 40 ) ) ), 'A stale digest is refused.' );
		$this->assertNull( $this->fake->request( 'PUT', $base . '/rules/' . $offset, array( 'comment' => 'a2', 'digest' => $digest ) ) );
		$this->assertSame( 'a2', $this->fake->request( 'GET', $base . '/rules/' . $offset )['comment'] );

		$fresh = $this->fake->request( 'GET', $base . '/rules' )[0]['digest'];
		$this->assertNull( $this->fake->request( 'DELETE', $base . '/rules/' . $offset, array( 'digest' => $fresh ) ) );

		$after = $this->fake->request( 'GET', $base . '/rules' );
		$this->assertSame( array( 'b', 'c' ), array_slice( array_column( $after, 'comment' ), -2 ) );
		$this->assertSame( $offset, $after[ $offset ]['pos'], 'The positions after the deleted rule move up.' );
	}

	public function test_fake_inbound_follows_policy_iface_and_disabled_rules(): void {
		list( $r ) = $this->ready();
		$base      = $this->base( $r );
		$vmid      = (int) $r->get( 'vmid' );

		$this->fake->request( 'PUT', $base . '/options', array( 'policy_in' => 'DROP' ) );
		$this->fake->request( 'POST', $base . '/rules', array( 'type' => 'in', 'action' => 'ACCEPT', 'proto' => 'tcp', 'dport' => '8000:8100', 'source' => '203.0.113.0/24', 'iface' => 'net0', 'enable' => 1 ) );
		$this->fake->request( 'POST', $base . '/rules', array( 'type' => 'in', 'action' => 'ACCEPT', 'proto' => 'tcp', 'dport' => '9000', 'iface' => 'net0' ) );

		$this->assertTrue( $this->fake->fake_inbound_allowed( $vmid, 'tcp', 8050, '203.0.113.9' ) );
		$this->assertFalse( $this->fake->fake_inbound_allowed( $vmid, 'tcp', 8050, '198.51.100.1' ), 'Another address.' );
		$this->assertFalse( $this->fake->fake_inbound_allowed( $vmid, 'tcp', 9000, '203.0.113.9' ), 'A rule made without enable does nothing.' );
		$this->assertFalse( $this->fake->fake_inbound_allowed( $vmid, 'tcp', 8050, '203.0.113.9', 'net1' ), 'A rule with an iface does not open the other NIC.' );

		$this->fake->request( 'PUT', $base . '/options', array( 'policy_in' => 'ACCEPT' ) );
		$this->assertTrue( $this->fake->fake_inbound_allowed( $vmid, 'tcp', 1234, '198.51.100.1' ) );
	}

	public function test_fake_ipset_create_add_remove_delete(): void {
		list( $r ) = $this->ready();
		$base      = $this->base( $r );

		$this->assertNull( $this->fake->request( 'POST', $base . '/ipset', array( 'name' => 'ipfilter-net0' ) ) );
		$this->assertNull( $this->fake->request( 'POST', $base . '/ipset/ipfilter-net0', array( 'cidr' => '192.0.2.10' ) ) );
		$this->assertSame( '192.0.2.10', $this->fake->request( 'GET', $base . '/ipset/ipfilter-net0' )[0]['cidr'] );
		$this->assertWPError( $this->fake->request( 'DELETE', $base . '/ipset/ipfilter-net0' ), 'Only an empty set is deleted.' );
		$this->assertNull( $this->fake->request( 'DELETE', $base . '/ipset/ipfilter-net0/192.0.2.10' ) );
		$this->assertNull( $this->fake->request( 'DELETE', $base . '/ipset/ipfilter-net0' ) );
	}

	// -- Validation ------------------------------------------------------------------------------------

	public function test_normalise_accepts_and_refuses(): void {
		list( $r ) = $this->ready( array( 'ip' => '192.0.2.10' ) );
		$this->save_cluster( array( 'fw_enabled' => '1', 'fw_max_rules' => '3' ) );
		$r = Resource::find( $r->id() );

		$ok = Firewall::normalise( array( $this->rule( 'tcp', 8000, 8100, array( '203.0.113.7', '198.51.100.0/24' ), "My app!<b>" ), $this->rule( 'icmp', 0 ) ), $r );
		$this->assertCount( 2, $ok );
		$this->assertSame( 'My appb', $ok[0]['label'] );
		$this->assertSame( array( '198.51.100.0/24', '203.0.113.7' ), $ok[0]['sources'] );
		$this->assertSame( 'ping', strtolower( $ok[1]['label'] ) );
		$this->assertMatchesRegularExpression( '/^[0-9a-f]{8}$/', $ok[0]['id'] );

		$bad = array(
			'port 0'          => $this->rule( 'tcp', 0 ),
			'port 65536'      => $this->rule( 'tcp', 65536 ),
			'reversed range'  => $this->rule( 'tcp', 9000, 8000 ),
			'proto'           => $this->rule( 'sctp', 80 ),
			'anywhere source' => $this->rule( 'tcp', 80, 0, array( '0.0.0.0/0' ) ),
			'loopback'        => $this->rule( 'tcp', 80, 0, array( '127.0.0.1' ) ),
			'private'         => $this->rule( 'tcp', 80, 0, array( '10.0.0.5' ) ),
			'multicast'       => $this->rule( 'tcp', 80, 0, array( '224.0.0.1' ) ),
			'link local'      => $this->rule( 'tcp', 80, 0, array( '169.254.1.1' ) ),
			'ipv6'            => $this->rule( 'tcp', 80, 0, array( '2001:db8::1' ) ),
			'own address'     => $this->rule( 'tcp', 80, 0, array( '192.0.2.10' ) ),
			'six sources'     => $this->rule( 'tcp', 80, 0, array( '203.0.113.1', '203.0.113.2', '203.0.113.3', '203.0.113.4', '203.0.113.5', '203.0.113.6' ) ),
			'junk source'     => $this->rule( 'tcp', 80, 0, array( 'example.com' ) ),
		);

		foreach ( $bad as $name => $rule ) {
			$result = Firewall::normalise( array( $rule ), $r );
			$this->assertWPError( $result, $name );
			$this->assertSame( 422, $result->get_error_data()['status'], $name );
			$this->assertSame( 0, $result->get_error_data()['rule'], $name );
		}

		$this->assertWPError( Firewall::normalise( array( $this->rule( 'tcp', 80 ), $this->rule( 'tcp', 80 ) ), $r ), 'Duplicates.' );
		$this->assertWPError( Firewall::normalise( array( $this->rule( 'tcp', 1 ), $this->rule( 'tcp', 2 ), $this->rule( 'tcp', 3 ), $this->rule( 'tcp', 4 ) ), $r ), 'Over the maximum of the cluster.' );
		$this->assertWPError( Firewall::normalise( 'nope', $r ) );
	}

	public function test_unknown_fields_are_dropped_and_origin_is_not_trusted(): void {
		list( $r ) = $this->ready();
		$rule      = $this->rule( 'tcp', 8080 ) + array( 'origin' => 'default', 'action' => 'DROP', 'iface' => 'net1', 'type' => 'out', 'comment' => 'x' );
		$out       = Firewall::normalise( array( $rule ), $r );

		$this->assertSame( 'customer', $out[0]['origin'] );
		$this->assertSame( array( 'id', 'proto', 'from', 'to', 'sources', 'label', 'origin', 'created' ), array_keys( $out[0] ) );
	}

	public function test_ssh_lock_out_rule(): void {
		list( $r ) = $this->ready();
		$rules     = static fn( array $input ) => Firewall::normalise( $input, $r );

		$this->assertTrue( Firewall::blocks_ssh( $rules( array( $this->rule( 'tcp', 80 ) ) ), '203.0.113.9' ) );
		$this->assertFalse( Firewall::blocks_ssh( $rules( array( $this->rule( 'tcp', 22 ) ) ), '203.0.113.9' ) );
		$this->assertFalse( Firewall::blocks_ssh( $rules( array( $this->rule( 'tcp', 20, 30 ) ) ), '' ), 'A range with 22 in it.' );
		$this->assertTrue( Firewall::blocks_ssh( $rules( array( $this->rule( 'tcp', 22, 22, array( '198.51.100.7' ) ) ) ), '203.0.113.9' ) );
		$this->assertFalse( Firewall::blocks_ssh( $rules( array( $this->rule( 'tcp', 22, 22, array( '203.0.113.0/24' ) ) ) ), '203.0.113.9' ) );
		$this->assertTrue( Firewall::blocks_ssh( $rules( array( $this->rule( 'udp', 22 ) ) ), '203.0.113.9' ), 'UDP is not SSH.' );
	}

	public function test_defaults_are_the_standard_set_plus_the_apps_ports(): void {
		list( $r ) = $this->ready();
		$ids       = static fn( array $rules ) => array_map( static fn( $x ) => $x['proto'] . ':' . $x['from'] . ':' . $x['origin'], $rules );

		$this->assertSame( array( 'tcp:22:default', 'tcp:80:default', 'tcp:443:default', 'icmp:0:default' ), $ids( Firewall::defaults( $r ) ) );

		$r->set( array( 'app_id' => 'coolify' ) );
		$with = $ids( Firewall::defaults( Resource::find( $r->id() ) ) );
		$this->assertContains( 'tcp:8000:app', $with );
		$this->assertContains( 'tcp:6001:app', $with );
		$this->assertCount( 1, array_keys( $with, 'tcp:22:default', true ) );
		$this->assertNotContains( 'tcp:22:app', $with );
	}

	public function test_editable_is_false_when_locked_linked_off_or_not_ready(): void {
		list( $r ) = $this->ready();
		$this->assertFalse( Firewall::editable( $r ), 'The cluster switch is off.' );

		$this->save_cluster( array( 'fw_enabled' => '1' ) );
		$r = Resource::find( $r->id() );
		$this->assertTrue( Firewall::editable( $r ) );

		$r->set( array( 'fw_locked' => true ) );
		$this->assertFalse( Firewall::editable( Resource::find( $r->id() ) ), 'Locked by the database work.' );
		$r->set( array( 'fw_locked' => null, 'linked' => true ) );
		$this->assertFalse( Firewall::editable( Resource::find( $r->id() ) ), 'Adopted.' );
		$r->set( array( 'linked' => null ) );
		$this->assertTrue( Firewall::editable( Resource::find( $r->id() ) ) );
	}

	// -- The apply job ---------------------------------------------------------------------------------

	public function test_apply_writes_rules_policy_and_keeps_the_smtp_block(): void {
		$r    = $this->managed( array( $this->rule( 'tcp', 22 ), $this->rule( 'tcp', 8080, 0, array( '203.0.113.7' ) ) ) );
		$vmid = (int) $r->get( 'vmid' );
		$vm   = $this->fake->vm( $vmid );

		$this->assertSame( 'DROP', $vm['firewall_options']['policy_in'], 'The inbound policy is written explicitly.' );
		$this->assertSame( 'ACCEPT', $vm['firewall_options']['policy_out'] );
		$this->assertSame( 1, (int) $vm['firewall_options']['macfilter'] );
		$this->assertSame( 1, (int) $vm['firewall_options']['enable'] );
		$this->assertContains( Provision::SMTP_COMMENT, $this->comments( $vmid ) );

		foreach ( $vm['firewall_rules'] as $rule ) {
			if ( 0 === strpos( $rule['comment'], 'bs-fw:' ) ) {
				$this->assertSame( 'in', $rule['type'] );
				$this->assertSame( 'ACCEPT', $rule['action'] );
				$this->assertSame( 'net0', $rule['iface'] );
				$this->assertSame( 1, (int) $rule['enable'], 'Never created disabled.' );
			}
		}

		$this->assertTrue( $this->fake->fake_inbound_allowed( $vmid, 'tcp', 22, '198.51.100.1' ) );
		$this->assertTrue( $this->fake->fake_inbound_allowed( $vmid, 'tcp', 8080, '203.0.113.7' ) );
		$this->assertFalse( $this->fake->fake_inbound_allowed( $vmid, 'tcp', 8080, '198.51.100.1' ) );
		$this->assertFalse( $this->fake->fake_inbound_allowed( $vmid, 'tcp', 3306, '203.0.113.7' ) );
		$this->assertFalse( $this->fake->fake_outbound_allowed( $vmid, 'tcp', 25 ), 'The SMTP block is still enforced.' );
		$this->assertTrue( $this->fake->fake_outbound_allowed( $vmid, 'tcp', 443 ) );

		$this->assertSame( 'idle', $r->get( 'fw_state' ) );
		$this->assertSame( (int) $r->get( 'fw_rev' ), (int) $r->get( 'fw_applied_rev' ) );
		$this->assertNull( $r->get( 'fw_error' ) );
	}

	public function test_apply_does_not_depend_on_the_default_policy(): void {
		foreach ( array( 'ACCEPT', 'DROP' ) as $default ) {
			$this->fake->set_default_policy_in( $default );
			$r    = $this->managed( array( $this->rule( 'tcp', 22 ) ) );
			$vmid = (int) $r->get( 'vmid' );

			$this->assertFalse( $this->fake->fake_inbound_allowed( $vmid, 'tcp', 3306, '198.51.100.1' ), 'Closed whatever an unset policy does (' . $default . ').' );
			$this->assertTrue( $this->fake->fake_inbound_allowed( $vmid, 'tcp', 22, '198.51.100.1' ) );
		}
	}

	public function test_a_server_that_is_not_taken_over_is_never_touched(): void {
		list( $r ) = $this->ready();
		$vm        = $this->fake->vm( (int) $r->get( 'vmid' ) );

		$this->assertArrayNotHasKey( 'policy_in', $vm['firewall_options'], 'The build wrote no inbound policy.' );
		$this->assertFalse( Firewall::managed( $r ) );

		$this->save_cluster( array( 'fw_enabled' => '1' ) );
		$this->fake->writes = array();
		$fresh              = Resource::find( $r->id() );
		$this->assertTrue( Firewall::editable( $fresh ) );
		$this->assertSame( array(), $this->fake->writes, 'Switching the feature on writes nothing to any server.' );
	}

	public function test_off_keeps_the_smtp_block_and_the_rules_and_opens_inbound(): void {
		$r    = $this->managed( array( $this->rule( 'tcp', 22 ) ) );
		$vmid = (int) $r->get( 'vmid' );
		$r    = $this->apply( $r, array( $this->rule( 'tcp', 22 ) ), false );

		$this->assertSame( 'ACCEPT', $this->fake->vm( $vmid )['firewall_options']['policy_in'] );
		$this->assertTrue( $this->fake->fake_inbound_allowed( $vmid, 'tcp', 3306, '198.51.100.1' ) );
		$this->assertFalse( $this->fake->fake_outbound_allowed( $vmid, 'tcp', 25 ) );
		$this->assertCount( 1, Firewall::rules_of( $r ), 'The rules are kept.' );

		$r = $this->apply( $r, array( $this->rule( 'tcp', 22 ) ), true );
		$this->assertSame( 'DROP', $this->fake->vm( $vmid )['firewall_options']['policy_in'] );
	}

	public function test_a_port_is_never_closed_during_a_change(): void {
		$r    = $this->managed( array( $this->rule( 'tcp', 22 ), $this->rule( 'tcp', 8080 ) ) );
		$vmid = (int) $r->get( 'vmid' );

		$this->fake->writes = array();
		$this->apply( $r, array( $this->rule( 'tcp', 22 ), $this->rule( 'tcp', 9090 ) ) );

		$posts   = array_keys( preg_grep( '#POST .*/firewall/rules #', $this->fake->writes ) );
		$deletes = array_keys( preg_grep( '#DELETE #', $this->fake->writes ) );
		$this->assertNotEmpty( $posts );
		$this->assertNotEmpty( $deletes );
		$this->assertLessThan( min( $deletes ), max( $posts ), 'Everything is added before anything is removed.' );
		$this->assertTrue( $this->fake->fake_inbound_allowed( $vmid, 'tcp', 9090, '198.51.100.1' ) );
		$this->assertFalse( $this->fake->fake_inbound_allowed( $vmid, 'tcp', 8080, '198.51.100.1' ) );
	}

	public function test_only_bs_fw_rules_are_ever_removed(): void {
		list( $r ) = $this->ready();
		$vmid      = (int) $r->get( 'vmid' );
		$base      = $this->base( $r );

		foreach ( array( 'admin rule', 'bs-db:pg', 'bs-sys:custom', '' ) as $comment ) {
			$params = array( 'type' => 'in', 'action' => 'ACCEPT', 'proto' => 'tcp', 'dport' => '5432', 'enable' => 1 );
			$this->fake->request( 'POST', $base . '/rules', '' === $comment ? $params : $params + array( 'comment' => $comment ) );
		}

		$this->save_cluster( array( 'fw_enabled' => '1' ) );
		$r = $this->apply( $r, array( $this->rule( 'tcp', 8080 ) ) );
		$r = $this->apply( $r, array() );
		Jobs::act( $r->id(), 'fw_reset', array() );
		$r = $this->apply( Resource::find( $r->id() ), array(), false );

		$comments = $this->comments( $vmid );
		$this->assertContains( 'admin rule', $comments );
		$this->assertContains( 'bs-db:pg', $comments );
		$this->assertContains( 'bs-sys:custom', $comments );
		$this->assertContains( '', $comments );
		$this->assertContains( Provision::SMTP_COMMENT, $comments );
		$this->assertSame( array(), array_filter( $comments, static fn( $c ) => 0 === strpos( $c, 'bs-fw:' ) ), 'The dashboard rules are gone.' );
	}

	public function test_a_second_run_makes_no_write(): void {
		$r = $this->managed( array( $this->rule( 'tcp', 22 ), $this->rule( 'udp', 5000, 5100 ) ) );
		$this->fake->writes = array();

		Jobs::act( $r->id(), 'fw_apply', array() );
		$this->assertSame( array(), $this->fake->writes );
	}

	public function test_history_line_has_counts_and_ports_but_no_addresses(): void {
		$r = $this->managed( array( $this->rule( 'tcp', 22 ), $this->rule( 'tcp', 8080, 0, array( '203.0.113.7' ) ) ) );
		$r = $this->apply( $r, array( $this->rule( 'tcp', 22 ) ) );

		$events = \BeltoftLicenses\Events::for_license( (int) $r->license()['id'] );
		$texts  = implode( ' | ', array_map( static fn( $e ) => (string) ( $e['message'] ?? $e['event'] ?? wp_json_encode( $e ) ), (array) $events ) );
		$this->assertStringContainsString( 'Firewall on: 0 opened, 1 closed (8080)', $texts );
		$this->assertStringNotContainsString( '203.0.113.7', $texts );
	}

	public function test_a_stale_digest_is_retried(): void {
		$r = $this->managed( array( $this->rule( 'tcp', 22 ), $this->rule( 'tcp', 8080 ) ) );
		$this->fake->fail_next( 'DELETE', '#/firewall/rules/\d+$#', array( 'status' => 500, 'message' => 'detected modified configuration - file changed by other user? Try again.' ) );

		$r = $this->apply( $r, array( $this->rule( 'tcp', 22 ) ) );
		$this->assertSame( 'applying', $r->get( 'fw_state' ), 'The run asked to be tried again.' );
		$this->assertNotEmpty( $r->get( 'act_attempts' ) );

		Jobs::act( $r->id(), 'fw_apply', array() );
		$r = Resource::find( $r->id() );
		$this->assertSame( 'idle', $r->get( 'fw_state' ) );
		$this->assertSame( array(), array_filter( $this->comments( (int) $r->get( 'vmid' ) ), static fn( $c ) => 'bs-fw:' . Firewall::rule_id( 'tcp', 8080, 8080, array() ) === $c ) );
	}

	public function test_many_rules_continue_over_several_runs(): void {
		$rules = array();

		for ( $i = 0; $i < 25; $i++ ) {
			$rules[] = $this->rule( 'tcp', 9000 + $i );
		}

		list( $r ) = $this->ready();
		$this->save_cluster( array( 'fw_enabled' => '1', 'fw_max_rules' => '30' ) );
		$r = Resource::find( $r->id() );
		Firewall::store( $r, true, Firewall::normalise( $rules, $r ), true, 1 );

		$this->fake->writes = array();
		Jobs::act( $r->id(), 'fw_apply', array() );
		$first = count( $this->fake->writes );
		$this->assertLessThanOrEqual( Firewall::WRITE_BUDGET, $first );
		$this->assertSame( 'applying', Resource::find( $r->id() )->get( 'fw_state' ) );

		for ( $i = 0; $i < 6 && 'idle' !== Resource::find( $r->id() )->get( 'fw_state' ); $i++ ) {
			Jobs::act( $r->id(), 'fw_apply', array() );
		}

		$this->assertSame( 'idle', Resource::find( $r->id() )->get( 'fw_state' ) );
		$this->assertCount( 25, array_filter( $this->comments( (int) $r->get( 'vmid' ) ), static fn( $c ) => 0 === strpos( $c, 'bs-fw:' ) ) );
	}

	public function test_a_read_back_that_differs_fails_with_a_fixed_text_for_the_customer(): void {
		list( $r ) = $this->ready();
		$this->save_cluster( array( 'fw_enabled' => '1' ) );
		$this->fake->drop_posts = true;
		$r                      = Resource::find( $r->id() );
		Firewall::store( $r, true, Firewall::normalise( array( $this->rule( 'tcp', 22 ) ), $r ), true, 1 );
		Jobs::act( $r->id(), 'fw_apply', array() );

		$r = Resource::find( $r->id() );
		$this->assertSame( 'failed', $r->get( 'fw_state' ) );
		$this->assertNotEmpty( $r->get( 'fw_error' ) );
		$this->assertSame( 'This did not finish. We have been told and will look into it.', $r->get( Jobs::RESULT )['text'] );
	}

	public function test_a_private_nic_gets_its_own_rule_and_customer_rules_stay_on_net0(): void {
		list( $r ) = $this->ready();
		$vmid      = (int) $r->get( 'vmid' );
		$this->fake->request( 'PUT', '/nodes/node-a/qemu/' . $vmid . '/config', array( 'net1' => 'virtio,bridge=vmbr1,tag=1000,firewall=1' ) );
		$this->save_cluster( array( 'fw_enabled' => '1' ) );
		$this->apply( $r, array( $this->rule( 'tcp', 22 ) ) );

		$this->assertContains( 'bs-sys:private', $this->comments( $vmid ) );
		$this->assertTrue( $this->fake->fake_inbound_allowed( $vmid, 'tcp', 5432, '172.16.0.5', 'net1' ), 'The private side stays open under a DROP policy.' );
		$this->assertFalse( $this->fake->fake_inbound_allowed( $vmid, 'tcp', 5432, '198.51.100.1', 'net0' ) );

		foreach ( $this->fake->vm( $vmid )['firewall_rules'] as $rule ) {
			if ( 0 === strpos( $rule['comment'], 'bs-fw:' ) ) {
				$this->assertSame( 'net0', $rule['iface'] );
			}
		}
	}

	public function test_ipfilter_is_off_until_the_cluster_asks_and_then_built_from_the_servers_address(): void {
		$r    = $this->managed( array( $this->rule( 'tcp', 22 ) ) );
		$vmid = (int) $r->get( 'vmid' );
		$this->assertArrayNotHasKey( 'ipfilter', $this->fake->vm( $vmid )['firewall_options'] );
		$this->assertArrayNotHasKey( 'firewall_ipsets', $this->fake->vm( $vmid ) );

		$this->save_cluster( array( 'fw_enabled' => '1', 'fw_ipfilter' => '1' ) );
		$this->apply( $r, array( $this->rule( 'tcp', 22 ) ) );
		$vm = $this->fake->vm( $vmid );
		$this->assertSame( 1, (int) $vm['firewall_options']['ipfilter'] );
		$this->assertSame( (string) $r->get( 'ip' ), $vm['firewall_ipsets']['ipfilter-net0'][0]['cidr'] );
		$this->assertCount( 1, $vm['firewall_ipsets']['ipfilter-net0'] );
	}

	public function test_ipfilter_waits_for_the_address(): void {
		$r = $this->managed( array( $this->rule( 'tcp', 22 ) ) );
		$r->set( array( 'ip' => '' ) );
		$this->save_cluster( array( 'fw_enabled' => '1', 'fw_ipfilter' => '1' ) );
		$this->apply( Resource::find( $r->id() ), array( $this->rule( 'tcp', 22 ) ) );

		$this->assertArrayNotHasKey( 'ipfilter', $this->fake->vm( (int) $r->get( 'vmid' ) )['firewall_options'], 'A filter without the address would cut all outgoing traffic.' );
	}

	// -- Build and rebuild -------------------------------------------------------------------------------

	public function test_a_new_server_gets_the_standard_set_when_the_feature_is_on(): void {
		$this->save_cluster( array( 'fw_enabled' => '1', 'fw_default_mode' => 'standard' ) );
		list( $r ) = $this->ready();
		$vmid      = (int) $r->get( 'vmid' );

		$this->assertTrue( Firewall::managed( $r ) );
		$this->assertSame( 'DROP', $this->fake->vm( $vmid )['firewall_options']['policy_in'] );
		$this->assertTrue( $this->fake->fake_inbound_allowed( $vmid, 'tcp', 22, '198.51.100.1' ) );
		$this->assertTrue( $this->fake->fake_inbound_allowed( $vmid, 'tcp', 443, '198.51.100.1' ) );
		$this->assertTrue( $this->fake->fake_inbound_allowed( $vmid, 'icmp', 0, '198.51.100.1' ) );
		$this->assertFalse( $this->fake->fake_inbound_allowed( $vmid, 'tcp', 3306, '198.51.100.1' ) );
		$this->assertFalse( $this->fake->fake_outbound_allowed( $vmid, 'tcp', 25 ) );
		$this->assertSame( 'idle', $r->get( 'fw_state' ) );
	}

	public function test_a_new_server_of_an_open_cluster_or_a_switched_off_one_gets_what_it_always_got(): void {
		foreach ( array( array( 'fw_enabled' => '1', 'fw_default_mode' => 'open' ), array( 'fw_enabled' => '0' ) ) as $settings ) {
			$this->save_cluster( $settings );
			list( $r ) = $this->ready();
			$vm        = $this->fake->vm( (int) $r->get( 'vmid' ) );

			$this->assertFalse( Firewall::managed( $r ) );
			$this->assertArrayNotHasKey( 'policy_in', $vm['firewall_options'] );
			$this->assertSame( 1, (int) $vm['firewall_options']['enable'] );
			$this->assertSame( array( Provision::SMTP_COMMENT ), $this->comments( (int) $r->get( 'vmid' ) ) );
		}
	}

	public function test_a_reinstall_applies_the_customers_rules_again(): void {
		$r = $this->managed( array( $this->rule( 'tcp', 22 ), $this->rule( 'tcp', 8080, 0, array( '203.0.113.7' ) ) ) );
		$r->set( array( 'app_id' => null ) );
		Jobs::act( $r->id(), 'reinstall', array( 'confirm' => 'REINSTALL', 'os' => '9000' ) );

		for ( $i = 0; $i < 12 && 'ready' !== Resource::find( $r->id() )->get( 'state' ); $i++ ) {
			Resource::find( $r->id() )->set( array( 'next_attempt_at' => time() - 1 ) );
			Jobs::run( $r->id() );
			$this->run_jobs();
		}

		$fresh = Resource::find( $r->id() );
		$vmid  = (int) $fresh->get( 'vmid' );
		$this->assertSame( 'ready', $fresh->get( 'state' ) );
		$this->assertSame( 'DROP', $this->fake->vm( $vmid )['firewall_options']['policy_in'] );
		$this->assertTrue( $this->fake->fake_inbound_allowed( $vmid, 'tcp', 8080, '203.0.113.7' ) );
		$this->assertFalse( $this->fake->fake_inbound_allowed( $vmid, 'tcp', 8080, '198.51.100.1' ) );
		$this->assertFalse( $this->fake->fake_outbound_allowed( $vmid, 'tcp', 25 ) );
	}

	public function test_only_the_rules_marked_app_follow_the_app(): void {
		list( $r ) = $this->ready();
		$r->set( array( 'app_id' => 'coolify' ) );
		$this->save_cluster( array( 'fw_enabled' => '1' ) );
		$r = Resource::find( $r->id() );

		$rules   = Firewall::defaults( $r );
		$rules[] = Firewall::normalise( array( $this->rule( 'tcp', 7777 ) ), $r )[0];
		Firewall::store( $r, true, $rules, true, 1 );

		$r->set( array( 'app_id' => 'docker' ) );
		$method = new \ReflectionMethod( Firewall::class, 'with_app_ports' );
		$method->setAccessible( true );
		$out = $method->invoke( new Firewall( Resource::find( $r->id() ) ), Firewall::rules_of( Resource::find( $r->id() ) ) );

		$ports = array_map( static fn( $x ) => $x['from'] . ':' . $x['origin'], $out );
		$this->assertNotContains( '8000:app', $ports );
		$this->assertContains( '7777:customer', $ports );
		$this->assertContains( '22:default', $ports );
	}

	// -- The actions ---------------------------------------------------------------------------------------

	public function test_reset_replaces_the_rules_with_the_defaults(): void {
		$r    = $this->managed( array( $this->rule( 'tcp', 22 ), $this->rule( 'tcp', 8080 ) ), true );
		$vmid = (int) $r->get( 'vmid' );

		Jobs::act( $r->id(), 'fw_reset', array() );
		$this->assertTrue( $this->fake->fake_inbound_allowed( $vmid, 'tcp', 443, '198.51.100.1' ) );
		$this->assertFalse( $this->fake->fake_inbound_allowed( $vmid, 'tcp', 8080, '198.51.100.1' ) );
		$this->assertSame( 'DROP', $this->fake->vm( $vmid )['firewall_options']['policy_in'] );
		$this->assertFalse( Resource::find( $r->id() )->get( 'fw_ssh_ack' ) );

		// Off, then reset: back on, with the standard rules.
		$this->apply( Resource::find( $r->id() ), array(), false );
		Jobs::act( $r->id(), 'fw_reset', array() );
		$this->assertSame( 'DROP', $this->fake->vm( $vmid )['firewall_options']['policy_in'] );
	}

	public function test_the_actions_are_offered_only_when_editable_and_apply_needs_a_managed_server(): void {
		list( $r ) = $this->ready();
		$this->assertSame( array(), Firewall::offered( $r ) );
		$this->assertArrayNotHasKey( 'fw_apply', ( new \BeltoftServices\Proxmox\ProxmoxDriver() )->customer_actions( $r ) );

		$this->save_cluster( array( 'fw_enabled' => '1' ) );
		$r = Resource::find( $r->id() );
		$this->assertSame( array( 'fw_apply', 'fw_reset' ), array_keys( Firewall::offered( $r ) ) );
		$this->assertArrayHasKey( 'fw_reset', ( new \BeltoftServices\Proxmox\ProxmoxDriver() )->customer_actions( $r ) );
		$this->assertNotNull( Firewall::check( $r, 'fw_apply', array() ), 'Nothing to apply before the customer set it up.' );
		$this->assertNull( Firewall::check( $r, 'fw_reset', array() ) );
	}

	public function test_a_job_for_a_server_that_is_not_editable_is_refused_without_a_write(): void {
		list( $r ) = $this->ready();
		$this->save_cluster( array( 'fw_enabled' => '1' ) );
		$r = Resource::find( $r->id() );
		Firewall::store( $r, true, array(), true, 1 );
		$r->set( array( 'fw_locked' => true ) );

		$this->fake->writes = array();
		Jobs::act( $r->id(), 'fw_apply', array() );
		$this->assertSame( array(), $this->fake->writes );
	}

	public function test_the_admin_panel_shows_the_firewall_line_and_the_error(): void {
		$show = static function ( Resource $r ): string {
			ob_start();
			\BeltoftServices\Proxmox\Panels::admin( $r );

			return (string) ob_get_clean();
		};

		$r = $this->managed( array( $this->rule( 'tcp', 22 ) ) );
		$this->assertMatchesRegularExpression( '#Firewall</th><td>on, 1 rules, idle \(applied 1 of 1\) Last change .* by user 1#', $show( $r ) );

		$r->set( array( 'fw_error' => 'boom' ) );
		$this->assertStringContainsString( 'Firewall error</th><td>boom', $show( Resource::find( $r->id() ) ) );

		$r->set( array( 'fw_locked' => true ) );
		$this->assertStringContainsString( 'Managed with the databases', $show( Resource::find( $r->id() ) ) );
	}

	public function test_the_cluster_settings_have_safe_defaults_and_are_clamped(): void {
		$c = \BeltoftServices\Proxmox\Clusters::get( 'lab' );
		$this->assertSame( 0, $c['fw_enabled'], 'Off until the live checks passed.' );
		$this->assertSame( 'standard', $c['fw_default_mode'] );
		$this->assertSame( 30, $c['fw_max_rules'] );
		$this->assertSame( 0, $c['fw_ipfilter'] );

		$this->save_cluster( array( 'fw_enabled' => '1', 'fw_default_mode' => 'weird', 'fw_max_rules' => '500', 'fw_ipfilter' => '1' ) );
		$c = \BeltoftServices\Proxmox\Clusters::get( 'lab' );
		$this->assertSame( array( 1, 'standard', Firewall::MAX_CEILING, 1 ), array( $c['fw_enabled'], $c['fw_default_mode'], $c['fw_max_rules'], $c['fw_ipfilter'] ) );
		$this->save_cluster( array( 'fw_max_rules' => '0', 'fw_default_mode' => 'open' ) );
		$c = \BeltoftServices\Proxmox\Clusters::get( 'lab' );
		$this->assertSame( array( 1, 'open' ), array( $c['fw_max_rules'], $c['fw_default_mode'] ) );
	}

	public function test_the_texts_have_no_em_dash_and_no_provider_name(): void {
		$files = array(
			BELTOFT_SERVICES_PATH . 'src/Proxmox/Firewall.php',
			BELTOFT_SERVICES_PATH . 'src/Proxmox/FirewallTab.php',
			BELTOFT_SERVICES_PATH . 'src/Proxmox/FirewallRoute.php',
			BELTOFT_SERVICES_PATH . 'templates/account/firewall.php',
			BELTOFT_SERVICES_PATH . 'assets/firewall.js',
		);

		foreach ( $files as $file ) {
			$text = (string) file_get_contents( $file );
			$this->assertStringNotContainsString( "\u{2014}", $text, basename( $file ) );
			$this->assertDoesNotMatchRegularExpression( '/hetzner/i', $text, basename( $file ) );
		}
	}
}

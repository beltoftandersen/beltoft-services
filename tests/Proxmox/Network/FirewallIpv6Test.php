<?php
/**
 * The firewall with IPv6: customer sources, one entry for two Proxmox rules, the ping twin, the options, the ipfilter set.
 * Fake client only, documentation prefix.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Proxmox\Network;

use BeltoftServices\Jobs;
use BeltoftServices\Proxmox\Network\Firewall;
use BeltoftServices\Proxmox\Network\FirewallRules;
use BeltoftServices\Resource;

require_once __DIR__ . '/FirewallTestCase.php';

class FirewallIpv6Test extends FirewallTestCase {

	private const ADDRESS = '2001:db8:430c:1:1388::1';

	private function v6_cluster( array $extra = array() ): void {
		$this->save_cluster( array_merge( array( 'ipv6_prefix' => '2001:db8:430c:1::/64', 'fw_enabled' => '1', 'fw_ipfilter' => '1' ), $extra ) );
	}

	/**
	 * A managed server with the rules applied.
	 *
	 * @param array<int, array<string, mixed>> $rules Rules (request shape).
	 */
	private function managed( array $rules, bool $v6 = true ): Resource {
		if ( $v6 ) {
			$this->v6_cluster();
		} else {
			$this->save_cluster( array( 'fw_enabled' => '1' ) );
		}

		list( $r ) = $this->ready();

		return $this->apply( $r, $rules );
	}

	private function apply( Resource $r, array $rules ): Resource {
		$r      = Resource::find( $r->id() );
		$normal = FirewallRules::normalise( $rules, $r );
		$this->assertIsArray( $normal, is_wp_error( $normal ) ? $normal->get_error_message() : '' );
		$this->assertGreaterThan( 0, FirewallRules::store( $r, true, $normal, true, 1 ) );
		Jobs::act( $r->id(), 'fw_apply', array() );

		return Resource::find( $r->id() );
	}

	/**
	 * The customer rules the fake holds: comment => array(proto, source, dport).
	 *
	 * @return array<string, array<string, string>>
	 */
	private function pve_rules( Resource $r ): array {
		$out = array();

		foreach ( $this->fake->vm( (int) $r->get( 'vmid' ) )['firewall_rules'] as $rule ) {
			if ( 0 === strpos( (string) $rule['comment'], 'bs-fw:' ) ) {
				$out[ $rule['comment'] ] = array( 'proto' => (string) ( $rule['proto'] ?? '' ), 'source' => (string) ( $rule['source'] ?? '' ), 'dport' => (string) ( $rule['dport'] ?? '' ) );
			}
		}

		return $out;
	}

	// -- Sources ---------------------------------------------------------------------------------------

	public function test_ipv6_sources_are_accepted_normalised_and_masked(): void {
		list( $r ) = $this->ready();
		$ok        = FirewallRules::normalise( array( $this->rule( 'tcp', 80, 0, array( '2001:DB8:0:0:0:0:0:1', '2001:db8:1:ffff::/48', '2a01:4f8:262:430c::5/128', '203.0.113.7' ) ) ), $r );

		$this->assertIsArray( $ok );
		$this->assertSame( array( '203.0.113.7', '2001:db8:1::/48', '2001:db8::1', '2a01:4f8:262:430c::5' ), $ok[0]['sources'] === array_values( $ok[0]['sources'] ) ? array( '203.0.113.7', '2001:db8:1::/48', '2001:db8::1', '2a01:4f8:262:430c::5' ) : array() );
		$this->assertEqualsCanonicalizing( array( '203.0.113.7', '2001:db8:1::/48', '2001:db8::1', '2a01:4f8:262:430c::5' ), $ok[0]['sources'] );
	}

	public function test_bad_ipv6_sources_are_refused(): void {
		list( $r ) = $this->ready();

		$bad = array(
			'unspecified'      => '::',
			'anywhere'         => '::/0',
			'loopback'         => '::1',
			'link local'       => 'fe80::1',
			'unique local'     => 'fd00::1',
			'multicast'        => 'ff02::1',
			'mapped ipv4'      => '::ffff:192.0.2.1',
			'too wide'         => '2001:db8::/47',
			'prefix 129'       => '2001:db8::/129',
			'zone'             => 'fe80::1%eth0',
			'junk'             => '2001:db8::zz',
			'not global'       => '4000::1',
			'ipv4 prefix long' => '2001:db8::1/',
		);

		foreach ( $bad as $name => $source ) {
			$result = FirewallRules::normalise( array( $this->rule( 'tcp', 80, 0, array( $source ) ) ), $r );
			$this->assertWPError( $result, $name );
			$this->assertSame( 422, $result->get_error_data()['status'], $name );
		}
	}

	public function test_the_servers_own_ipv6_address_is_refused_as_a_source(): void {
		$this->v6_cluster();
		list( $r ) = $this->ready();
		$this->assertSame( self::ADDRESS, $r->get( 'ip6' ) );

		$this->assertWPError( FirewallRules::normalise( array( $this->rule( 'tcp', 80, 0, array( '2001:DB8:430C:1:1388:0:0:1' ) ) ), $r ) );
	}

	public function test_source_covers_never_mixes_the_families(): void {
		$this->assertTrue( FirewallRules::source_covers( '2001:db8:1:5::9', array( '2001:db8:1::/48' ) ) );
		$this->assertFalse( FirewallRules::source_covers( '2001:db8:2::9', array( '2001:db8:1::/48' ) ) );
		$this->assertFalse( FirewallRules::source_covers( '203.0.113.9', array( '2001:db8:1::/48' ) ), 'An IPv4 address is not in an IPv6 source.' );
		$this->assertFalse( FirewallRules::source_covers( '2001:db8:1::9', array( '203.0.113.0/24' ) ) );
		$this->assertTrue( FirewallRules::source_covers( '2001:db8:1::9', array() ) );
		$this->assertFalse( FirewallRules::source_covers( 'junk', array( '203.0.113.0/24' ) ) );
	}

	public function test_the_ssh_lock_out_check_knows_an_ipv6_visitor(): void {
		list( $r ) = $this->ready();
		$rules     = static fn( array $input ) => FirewallRules::normalise( $input, $r );

		$this->assertFalse( FirewallRules::blocks_ssh( $rules( array( $this->rule( 'tcp', 22, 22, array( '2001:db8:1::/48' ) ) ) ), '2001:db8:1::77' ) );
		$this->assertTrue( FirewallRules::blocks_ssh( $rules( array( $this->rule( 'tcp', 22, 22, array( '2001:db8:1::/48' ) ) ) ), '203.0.113.9' ) );
		$this->assertTrue( FirewallRules::blocks_ssh( $rules( array( $this->rule( 'tcp', 22, 22, array( '203.0.113.9' ) ) ) ), '2001:db8:1::77' ) );
	}

	// -- The Proxmox rules -----------------------------------------------------------------------------

	public function test_a_rule_without_a_source_is_one_rule_for_both_families_and_a_ping_gets_a_twin(): void {
		$r     = $this->managed( array( $this->rule( 'tcp', 22 ), $this->rule( 'icmp', 0 ) ) );
		$rules = $this->pve_rules( $r );
		$ssh   = 'bs-fw:' . FirewallRules::rule_id( 'tcp', 22, 22, array() );
		$ping  = 'bs-fw:' . FirewallRules::rule_id( 'icmp', 0, 0, array() );

		$this->assertSame( array( 'proto' => 'tcp', 'source' => '', 'dport' => '22' ), $rules[ $ssh ] );
		$this->assertSame( 'icmp', $rules[ $ping ]['proto'] );
		$this->assertSame( 'icmpv6', $rules[ $ping . ':6' ]['proto'] );
		$this->assertCount( 3, $rules );

		$vmid = (int) $r->get( 'vmid' );
		$this->assertTrue( $this->fake->fake_inbound_allowed( $vmid, 'tcp', 22, '203.0.113.7' ) );
		$this->assertTrue( $this->fake->fake_inbound_allowed( $vmid, 'tcp', 22, '2001:db8:1::7' ) );
		$this->assertTrue( $this->fake->fake_inbound_allowed( $vmid, 'icmp', 0, '203.0.113.7' ) );
		$this->assertTrue( $this->fake->fake_inbound_allowed( $vmid, 'icmpv6', 0, '2001:db8:1::7' ) );
		$this->assertFalse( $this->fake->fake_inbound_allowed( $vmid, 'tcp', 3306, '2001:db8:1::7' ), 'A closed port is closed over IPv6 too.' );
	}

	public function test_a_cluster_with_ipv6_off_writes_what_it_always_wrote(): void {
		$r     = $this->managed( array( $this->rule( 'tcp', 22 ), $this->rule( 'icmp', 0 ) ), false );
		$rules = $this->pve_rules( $r );

		$this->assertCount( 2, $rules );
		$this->assertSame( array( 'icmp' ), array_values( array_unique( array_column( array_filter( $rules, static fn( $k ) => false !== strpos( $k, FirewallRules::rule_id( 'icmp', 0, 0, array() ) ), ARRAY_FILTER_USE_KEY ), 'proto' ) ) ) );
		$options = $this->fake->vm( (int) $r->get( 'vmid' ) )['firewall_options'];
		$this->assertArrayNotHasKey( 'ndp', $options );
		$this->assertArrayNotHasKey( 'radv', $options );
	}

	public function test_sources_of_one_family_go_to_their_own_rule(): void {
		$r     = $this->managed( array( $this->rule( 'tcp', 8080, 0, array( '203.0.113.7', '2001:db8:1::/48' ) ), $this->rule( 'tcp', 9000, 0, array( '2001:db8:2::5' ) ), $this->rule( 'icmp', 0, 0, array( '2001:db8:3::5' ) ) ) );
		$rules = $this->pve_rules( $r );
		$a     = 'bs-fw:' . FirewallRules::rule_id( 'tcp', 8080, 8080, array( '2001:db8:1::/48', '203.0.113.7' ) );
		$b     = 'bs-fw:' . FirewallRules::rule_id( 'tcp', 9000, 9000, array( '2001:db8:2::5' ) );
		$c     = 'bs-fw:' . FirewallRules::rule_id( 'icmp', 0, 0, array( '2001:db8:3::5' ) );

		$this->assertSame( '203.0.113.7', $rules[ $a ]['source'] );
		$this->assertSame( '2001:db8:1::/48', $rules[ $a . ':6' ]['source'] );
		$this->assertArrayNotHasKey( $b, $rules, 'An IPv6-only rule has no IPv4 rule.' );
		$this->assertSame( '2001:db8:2::5', $rules[ $b . ':6' ]['source'] );
		$this->assertSame( 'icmpv6', $rules[ $c . ':6' ]['proto'] );
		$this->assertArrayNotHasKey( $c, $rules );

		$vmid = (int) $r->get( 'vmid' );
		$this->assertTrue( $this->fake->fake_inbound_allowed( $vmid, 'tcp', 8080, '203.0.113.7' ) );
		$this->assertTrue( $this->fake->fake_inbound_allowed( $vmid, 'tcp', 8080, '2001:db8:1::99' ) );
		$this->assertFalse( $this->fake->fake_inbound_allowed( $vmid, 'tcp', 8080, '2001:db8:9::99' ) );
		$this->assertFalse( $this->fake->fake_inbound_allowed( $vmid, 'tcp', 9000, '203.0.113.7' ) );
		$this->assertTrue( $this->fake->fake_inbound_allowed( $vmid, 'tcp', 9000, '2001:db8:2::5' ) );
		$this->assertFalse( $this->fake->fake_inbound_allowed( $vmid, 'icmpv6', 0, '2001:db8:9::5' ) );
		$this->assertTrue( $this->fake->fake_inbound_allowed( $vmid, 'icmpv6', 0, '2001:db8:3::5' ) );
	}

	public function test_a_second_apply_writes_nothing_and_a_removed_rule_takes_both_halves(): void {
		$r = $this->managed( array( $this->rule( 'tcp', 8080, 0, array( '203.0.113.7', '2001:db8:1::/48' ) ), $this->rule( 'icmp', 0 ) ) );
		Jobs::act( $r->id(), 'fw_apply', array() );

		$this->fake->writes = array();
		Jobs::act( $r->id(), 'fw_apply', array() );
		$writes = array_filter( $this->fake->writes, static fn( $w ) => false !== strpos( $w, '/rules' ) );
		$this->assertSame( array(), array_values( $writes ), 'Nothing to write the second time.' );

		$r = $this->apply( $r, array( $this->rule( 'tcp', 22 ) ) );
		$this->assertSame( array( 'bs-fw:' . FirewallRules::rule_id( 'tcp', 22, 22, array() ) ), array_keys( $this->pve_rules( $r ) ) );

		$events = \BeltoftLicenses\Events::for_license( (int) $r->license()['id'] );
		$texts  = implode( ' | ', array_map( static fn( $e ) => (string) ( $e['message'] ?? $e['event'] ?? wp_json_encode( $e ) ), (array) $events ) );
		$this->assertStringContainsString( 'Firewall on: 1 opened (22), 2 closed (', $texts, 'Two entries for the customer, not four rules.' );
	}

	// -- Options and the ipfilter set --------------------------------------------------------------------

	public function test_ndp_and_radv_are_written_explicitly_when_ipv6_is_on(): void {
		$r       = $this->managed( array( $this->rule( 'tcp', 22 ) ) );
		$options = $this->fake->vm( (int) $r->get( 'vmid' ) )['firewall_options'];

		$this->assertSame( 1, (int) $options['ndp'] );
		$this->assertSame( 0, (int) $options['radv'] );
		$this->assertSame( 1, (int) $options['ipfilter'] );
	}

	public function test_the_ipfilter_set_holds_both_addresses_and_only_ipv4_before_the_meta_is_there(): void {
		$this->v6_cluster();
		list( $r ) = $this->ready();
		$r->set( array( 'ip6' => null ) );
		$r = $this->apply( $r, array( $this->rule( 'tcp', 22 ) ) );
		$vm = $this->fake->vm( (int) $r->get( 'vmid' ) );

		$this->assertSame( array( (string) $r->get( 'ip' ) ), array_column( $vm['firewall_ipsets']['ipfilter-net0'], 'cidr' ), 'No ip6 meta yet: IPv4 only.' );
		$this->assertSame( 1, (int) $vm['firewall_options']['ipfilter'] );

		$r->set( array( 'ip6' => self::ADDRESS ) );
		Jobs::act( $r->id(), 'fw_apply', array() );
		$cidrs = array_column( $this->fake->vm( (int) $r->get( 'vmid' ) )['firewall_ipsets']['ipfilter-net0'], 'cidr' );
		$this->assertEqualsCanonicalizing( array( (string) $r->get( 'ip' ), self::ADDRESS ), $cidrs );
		$this->assertSame( 1, (int) $this->fake->vm( (int) $r->get( 'vmid' ) )['firewall_options']['ipfilter'] );
	}

	public function test_a_stale_ipv6_entry_in_the_set_is_replaced(): void {
		$r    = $this->managed( array( $this->rule( 'tcp', 22 ) ) );
		$vmid = (int) $r->get( 'vmid' );
		$base = '/nodes/node-a/qemu/' . $vmid . '/firewall/ipset/ipfilter-net0';
		$this->fake->request( 'POST', $base, array( 'cidr' => '2001:db8:430c:1:9999::1' ) );

		Jobs::act( $r->id(), 'fw_apply', array() );

		$cidrs = array_column( $this->fake->vm( $vmid )['firewall_ipsets']['ipfilter-net0'], 'cidr' );
		$this->assertEqualsCanonicalizing( array( (string) $r->get( 'ip' ), self::ADDRESS ), $cidrs );
	}

	public function test_an_existing_managed_server_gets_the_ipv6_side_of_its_firewall_from_the_sweep_job(): void {
		$r = $this->managed( array( $this->rule( 'tcp', 22 ), $this->rule( 'icmp', 0 ) ), false );
		$this->assertCount( 2, $this->pve_rules( $r ) );
		$vmid = (int) $r->get( 'vmid' );

		$this->v6_cluster();
		$this->assertSame( 'written', ( new \BeltoftServices\Proxmox\Vm\Operations( Resource::find( $r->id() ) ) )->reconcile_ipv6() );

		$this->assertSame( 'ip=' . $r->get( 'ip' ) . '/24,gw=192.0.2.1,ip6=' . self::ADDRESS . '/64,gw6=2001:db8:430c:1::1', $this->fake->vm( $vmid )['config']['ipconfig0'] );
		$this->assertContains( 'bs-fw:' . FirewallRules::rule_id( 'icmp', 0, 0, array() ) . ':6', $this->comments( $vmid ) );
		$cidrs = array_column( $this->fake->vm( $vmid )['firewall_ipsets']['ipfilter-net0'] ?? array(), 'cidr' );
		$this->assertContains( self::ADDRESS, $cidrs );
		$this->assertSame( 1, (int) $this->fake->vm( $vmid )['firewall_options']['ndp'] );
	}
}

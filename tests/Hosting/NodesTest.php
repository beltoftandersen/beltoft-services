<?php
/**
 * Stored hosting nodes and the client gate.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Hosting;

require_once __DIR__ . '/HostingCase.php';

use BeltoftServices\Hosting\Agent;
use BeltoftServices\Hosting\Clients;
use BeltoftServices\Hosting\FakeAgent;
use BeltoftServices\Hosting\FakeNode;
use BeltoftServices\Hosting\Nodes;
use BeltoftServices\Hosting\Records;
use BeltoftServices\Proxmox\Client;

/**
 * Nodes (records, secrets, validation) and Clients (the live gate).
 */
class NodesTest extends HostingCase {

	public function test_secrets_are_encrypted_in_the_option_and_decrypted_by_get(): void {
		$raw = (string) wp_json_encode( get_option( Nodes::OPTION ) );

		$this->assertStringNotContainsString( self::NODE_SECRET, $raw );
		$this->assertStringNotContainsString( self::AGENT_KEY, $raw );

		$node = Nodes::get( 'n1' );
		$this->assertSame( self::NODE_SECRET, $node['token_secret'] );
		$this->assertSame( self::AGENT_KEY, $node['agent_key'] );
		$this->assertTrue( Nodes::has_secret( 'n1' ) );
		$this->assertTrue( Nodes::has_secret( 'n1', 'agent_key' ) );
		$this->assertFalse( Nodes::has_secret( 'nope' ) );
	}

	public function test_an_empty_secret_keeps_the_saved_one(): void {
		$this->save_node( array( 'token_secret' => '', 'agent_key' => '', 'label' => 'Renamed' ) );

		$node = Nodes::get( 'n1' );
		$this->assertSame( 'Renamed', $node['label'] );
		$this->assertSame( self::NODE_SECRET, $node['token_secret'] );
		$this->assertSame( self::AGENT_KEY, $node['agent_key'] );
	}

	public function test_a_new_node_needs_both_secrets(): void {
		$result = Nodes::save( 'n2', $this->valid( array( 'token_secret' => '', 'agent_key' => '' ) ) );

		$this->assertWPError( $result );
		$this->assertStringEndsWith( '_secret', $result->get_error_code() );
	}

	public function test_a_short_secret_is_refused(): void {
		$this->assertWPError( Nodes::save( 'n2', $this->valid( array( 'token_secret' => 'short' ) ) ) );
	}

	/**
	 * Values that a form could post.
	 *
	 * @param array<string, mixed> $over Overrides.
	 * @return array<string, mixed>
	 */
	private function valid( array $over = array() ): array {
		$base = array(
			'label'        => 'Node two',
			'node_group'   => 'g1',
			'client'       => 'fake',
			'api_url'      => 'https://node2.example.invalid:8006',
			'token_id'     => 'svc@pve!hosting',
			'token_secret' => self::NODE_SECRET,
			'pve_node'     => 'pve2',
			'agent_url'    => 'https://node2.example.invalid:8765',
			'agent_key_id' => 'k1',
			'agent_key'    => self::AGENT_KEY,
			'pool'         => 'hosting-pool',
			'storage'      => 'local-zfs',
			'bridge'       => 'hbr0',
			'gateway'      => '10.200.0.1',
			'lan_cidr'     => '10.200.0.0/16',
			'ip_first'     => 10,
			'ssh_port_min' => 22001,
			'ssh_port_max' => 22999,
			'vmid_min'     => 1000,
			'vmid_max'     => 1999,
			'template'     => 9000,
			'net_mbit'     => 0,
			'mem_gb'       => 32,
			'disk_gb'      => 1000,
			'db_host'      => '10.200.0.3',
		);

		return array_merge( $base, $over );
	}

	public function test_validation_refuses_bad_values(): void {
		$bad = array(
			'id'          => array( 'BAD ID', array() ),
			'http url'    => array( 'n2', array( 'api_url' => 'http://node2.example.invalid:8006' ) ),
			'url path'    => array( 'n2', array( 'agent_url' => 'https://node2.example.invalid:8765/x' ) ),
			'token id'    => array( 'n2', array( 'token_id' => 'nope' ) ),
			'group'       => array( 'n2', array( 'node_group' => 'Bad Group' ) ),
			'label'       => array( 'n2', array( 'label' => '' ) ),
			'bridge'      => array( 'n2', array( 'bridge' => '1bad' ) ),
			'cidr'        => array( 'n2', array( 'lan_cidr' => '10.200.0.0/8' ) ),
			'first host'  => array( 'n2', array( 'ip_first' => 70000 ) ),
			'gateway'     => array( 'n2', array( 'gateway' => 'gw' ) ),
			'port range'  => array( 'n2', array( 'ssh_port_min' => 23000 ) ),
			'vmid range'  => array( 'n2', array( 'vmid_min' => 2000 ) ),
			'template'    => array( 'n2', array( 'template' => 5 ) ),
			'pin'         => array( 'n2', array( 'pin_sha256' => 'abc' ) ),
			'client'      => array( 'n2', array( 'client' => 'real' ) ),
			'pve node'    => array( 'n2', array( 'pve_node' => 'bad node' ) ),
		);

		foreach ( $bad as $name => $case ) {
			$this->assertWPError( Nodes::save( $case[0], $this->valid( $case[1] ) ), $name );
		}

		$this->assertArrayNotHasKey( 'n2', Nodes::all() );
	}

	public function test_live_cannot_be_saved_without_the_constant(): void {
		$result = Nodes::save( 'n2', $this->valid( array( 'client' => 'live' ) ) );

		$this->assertWPError( $result );
		$this->assertStringContainsString( 'BELTOFT_SERVICES_HOSTING_LIVE', $result->get_error_message() );
		$this->assertFalse( Clients::live_allowed(), 'The constant is never defined in the suite.' );
	}

	public function test_a_node_stored_as_live_reads_as_fake_unless_allowed(): void {
		$all                 = get_option( Nodes::OPTION );
		$all['n1']['client'] = 'live';
		update_option( Nodes::OPTION, $all );

		$this->assertSame( 'fake', Nodes::all()['n1']['client'] );
		$this->assertSame( 'live', Nodes::view( true )['n1']['client'] );
	}

	public function test_nodes_of_a_group_are_listed_when_enabled(): void {
		$this->save_node( array( 'label' => 'Two' ), 'n2' );
		$this->save_node( array( 'label' => 'Other', 'node_group' => 'g2' ), 'n3' );
		$this->save_node( array( 'label' => 'Off', 'enabled' => '' ), 'n4' );

		$this->assertSame( array( 'n1', 'n2', 'n3', 'n4' ), array_keys( Nodes::all() ) );
		$this->assertSame( array( 'n1', 'n2' ), Nodes::in_group( 'g1' ) );
		$this->assertSame( array( 'n3' ), Nodes::in_group( 'g2' ) );
		$this->assertSame( array(), Nodes::in_group( 'none' ) );
	}

	public function test_a_node_in_use_cannot_be_deleted(): void {
		$this->save_node( array( 'label' => 'Two' ), 'n2' );
		Records::open( 4242, 'n2' );

		$this->assertSame( 1, Nodes::in_use( 'n2' ) );
		$this->assertFalse( Nodes::delete( 'n2' ) );
		$this->assertArrayHasKey( 'n2', Nodes::all() );

		Records::remove( 4242 );
		$this->assertTrue( Nodes::delete( 'n2' ) );
		$this->assertArrayNotHasKey( 'n2', Nodes::all() );
		$this->assertFalse( Nodes::delete( 'n2' ) );
	}

	// -- Clients: the gate ------------------------------------------------------------------------

	public function test_a_fake_node_gets_the_fake_clients(): void {
		$node = Nodes::get( 'n1' );

		$this->assertSame( 'fake', Clients::kind( $node, false ) );
		$this->assertSame( 'fake', Clients::kind( $node, true ) );
		$this->assertInstanceOf( Client::class, Clients::build_node( $node, false ) );
		$this->assertInstanceOf( Agent::class, Clients::build_agent( $node, true ) );
		$this->assertNull( Clients::gate( 'n1', $node, true ) );
	}

	public function test_a_live_node_needs_the_constant_and_the_tag_and_never_gets_a_fake(): void {
		$node           = Nodes::get( 'n1' );
		$node['client'] = 'live';

		// No constant: the live setting is ignored, a fake comes back (a copied database never goes live).
		$this->assertSame( 'fake', Clients::kind( $node, false ) );
		$this->assertInstanceOf( FakeNode::class, Clients::build_node( $node, false ) );
		$this->assertInstanceOf( FakeAgent::class, Clients::build_agent( $node, false ) );

		// With the constant but no explicit site tag: refused, with the tag message.
		$this->assertTrue( Clients::untagged( $node, true ) );
		$this->assertStringContainsString( 'site tag', (string) Clients::gate( 'n1', $node, true ) );

		foreach ( array( 'build_node', 'build_agent' ) as $build ) {
			try {
				Clients::$build( $node, true );
				$this->fail( $build . ' built a client for a live node.' );
			} catch ( \RuntimeException $e ) {
				$this->assertSame( 'The site tag is not set.', $e->getMessage() );
			}
		}

		// With the tag as well: the node API gets the live client (no call is made by building it), and a bad
		// setting is refused by the live client itself, never answered by a fake.
		$this->settings( array( 'site_tag' => 'tagone' ) );
		$this->assertNull( Clients::gate( 'n1', $node, true ) );
		$this->assertInstanceOf( \BeltoftServices\Proxmox\LiveClient::class, Clients::build_node( $node, true ) );
		$this->assertNotInstanceOf( FakeNode::class, Clients::build_node( $node, true ) );

		try {
			Clients::build_node( array_merge( $node, array( 'api_url' => 'http://node.example.invalid:8006' ) ), true );
			$this->fail( 'A live client was built for an address without https.' );
		} catch ( \InvalidArgumentException $e ) {
			$this->assertStringContainsString( 'https', $e->getMessage() );
		}

		try {
			Clients::build_agent( $node, true );
			$this->fail( 'build_agent built a client for a live node without a certificate.' );
		} catch ( \RuntimeException $e ) {
			$this->assertStringContainsString( 'mutual TLS', $e->getMessage() );
		}
	}

	public function test_only_a_fake_can_be_injected_and_it_never_sees_a_secret(): void {
		remove_all_filters( 'beltoft_services_hosting_client' );
		remove_all_filters( 'beltoft_services_hosting_agent' );

		$seen = array();
		add_filter(
			'beltoft_services_hosting_client',
			function ( $client, $public ) use ( &$seen ) {
				$seen[] = $public;

				return new class() implements Client {
					public function request( string $method, string $path, array $params = array() ) {
						return null;
					}
					public function task( string $method, string $path, array $params = array() ) {
						return '';
					}
					public function wait_task( string $upid, int $timeout ) {
						return true;
					}
				};
			},
			10,
			2
		);

		$client = Clients::build_node( Nodes::get( 'n1' ), false );

		$this->assertInstanceOf( FakeNode::class, $client, 'A client that is not a FakeNode is ignored.' );
		$this->assertCount( 1, $seen );
		$this->assertArrayNotHasKey( 'token_secret', $seen[0] );
		$this->assertArrayNotHasKey( 'agent_key', $seen[0] );
		$this->assertSame( 'pve1', $seen[0]['pve_node'] );
	}
}

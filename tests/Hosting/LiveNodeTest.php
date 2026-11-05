<?php
/**
 * The live node API client behind the gate and the read-only wrapper. No network: the live client is only built,
 * never called, and the wrapper is tested over a recorder.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Hosting;

require_once __DIR__ . '/HostingCase.php';

use BeltoftServices\Cli\HostingCommand;
use BeltoftServices\Hosting\Clients;
use BeltoftServices\Hosting\FakeNode;
use BeltoftServices\Hosting\Nodes;
use BeltoftServices\Hosting\ReadOnlyClient;
use BeltoftServices\Proxmox\LiveClient;

/**
 * Clients::build_node and ReadOnlyClient.
 */
class LiveNodeTest extends HostingCase {

	public function test_the_live_node_client_exists_only_behind_the_constant_the_node_setting_and_the_site_tag(): void {
		$node = Nodes::get( 'n1' );
		$live = array_merge( $node, array( 'client' => 'live' ) );

		$this->assertInstanceOf( FakeNode::class, Clients::build_node( $node, true ), 'A fake node stays fake.' );
		$this->assertInstanceOf( FakeNode::class, Clients::build_node( $live, false ), 'No constant: fake.' );

		try {
			Clients::build_node( $live, true );
			$this->fail( 'Built without a site tag.' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'The site tag is not set.', $e->getMessage() );
		}

		$this->settings( array( 'site_tag' => 'tagone' ) );
		$client = Clients::build_node( $live, true );
		$this->assertInstanceOf( LiveClient::class, $client );
		$this->assertFalse( Clients::live_allowed(), 'The constant is never defined in the suite.' );
	}

	public function test_an_injected_fake_is_never_taken_for_a_live_node(): void {
		$this->settings( array( 'site_tag' => 'tagone' ) );
		add_filter( 'beltoft_services_hosting_client', fn() => new FakeNode() );

		$this->assertInstanceOf( LiveClient::class, Clients::build_node( array_merge( Nodes::get( 'n1' ), array( 'client' => 'live' ) ), true ) );
	}

	public function test_the_live_client_refuses_bad_settings_and_no_allowance(): void {
		$base = array( 'api_url' => 'https://node.example.invalid:8006', 'token_id' => 'svc@pve!hosting', 'token_secret' => self::NODE_SECRET );

		new LiveClient( $base, true );
		$this->assertTrue( true );

		foreach ( array( 'allowance' => array( $base, false ), 'http' => array( array_merge( $base, array( 'api_url' => 'http://x.invalid' ) ), true ), 'token' => array( array_merge( $base, array( 'token_id' => 'nope' ) ), true ), 'secret' => array( array_merge( $base, array( 'token_secret' => '' ) ), true ), 'pin' => array( array_merge( $base, array( 'pin_sha256' => 'abc' ) ), true ) ) as $name => $case ) {
			try {
				new LiveClient( $case[0], $case[1] );
				$this->fail( $name . ': built.' );
			} catch ( \RuntimeException | \InvalidArgumentException $e ) {
				$this->assertStringNotContainsString( self::NODE_SECRET, $e->getMessage(), $name );
			}
		}
	}

	public function test_the_read_only_client_lets_get_through_and_stops_everything_else(): void {
		$node = new FakeNode();
		$node->add_node( 'pve1' );
		$node->add_template( 9000, 'pve1' );
		$ro = new ReadOnlyClient( $node );

		$this->assertIsArray( $ro->request( 'GET', '/cluster/resources', array( 'type' => 'vm' ) ) );
		$this->assertIsArray( $ro->request( 'get', '/cluster/resources', array( 'type' => 'vm' ) ), 'The method is read case-insensitively.' );

		foreach ( array( 'POST', 'PUT', 'DELETE', 'PATCH' ) as $method ) {
			$error = $ro->request( $method, '/nodes/pve1/lxc/9000/clone', array( 'newid' => 1500 ) );
			$this->assertWPError( $error, $method );
			$this->assertSame( 'read_only', $error->get_error_code() );
		}

		$this->assertSame( 'read_only', $ro->task( 'POST', '/nodes/pve1/lxc/9000/clone', array( 'newid' => 1500 ) )->get_error_code() );
		$this->assertCount( 2, $node->calls(), 'Only the two reads reached the client.' );
		$this->assertArrayNotHasKey( 1500, $node->state()['cts'] );
	}

	public function test_node_check_goes_through_the_read_only_client(): void {
		require_once dirname( __DIR__ ) . '/Support/WpCliStub.php';
		\WP_CLI::reset();

		( new HostingCommand() )->node( array( 'check', 'n1' ), array() );

		foreach ( $this->node->calls() as $call ) {
			$this->assertSame( 'GET', $call['method'] );
		}

		$this->assertStringContainsString( 'checks passed', \WP_CLI::text() );
		$source = (string) file_get_contents( BELTOFT_SERVICES_PATH . 'src/Cli/HostingCommand.php' );
		$this->assertStringContainsString( 'new ReadOnlyClient( Clients::for_node( $node ) )', $source, 'The check cannot be given a writing client by accident.' );
	}
}

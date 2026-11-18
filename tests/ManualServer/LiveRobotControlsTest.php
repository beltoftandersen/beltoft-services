<?php
/**
 * The requests the HTTP client makes for the controls, against a stub (never the network): paths, methods and form bodies as the documentation shows them.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\ManualServer;

use BeltoftServices\ManualServer\LiveRobotClient;

require_once __DIR__ . '/ManualServerTestCase.php';

final class LiveRobotControlsTest extends ManualServerTestCase {

	/** @var array<int, array<string, mixed>> */
	private array $sent = array();

	/** @var array<string, array{0: int, 1: string}> Path => status and body to answer. */
	private array $answers = array();

	public function set_up(): void {
		parent::set_up();
		$this->sent    = array();
		$this->answers = array();
		$this->http_responder = function ( $url, $args ) {
			$this->sent[] = array( 'method' => $args['method'], 'url' => $url, 'body' => (string) ( $args['body'] ?? '' ), 'auth' => (string) ( $args['headers']['Authorization'] ?? '' ) );
			$path         = (string) wp_parse_url( $url, PHP_URL_PATH );
			$hit          = $this->answers[ $args['method'] . ' ' . $path ] ?? array( 200, '{}' );

			return array( 'headers' => array(), 'body' => $hit[1], 'response' => array( 'code' => $hit[0], 'message' => '' ), 'cookies' => array(), 'filename' => null );
		};
	}

	private function client(): LiveRobotClient {
		return new LiveRobotClient( 'https://robot-ws.your-server.de', 'user', 'pass' );
	}

	private function last(): array {
		return end( $this->sent );
	}

	public function test_reset_and_wake_on_lan(): void {
		$this->client()->reset_options( 321 );
		$this->assertSame( array( 'GET', 'https://robot-ws.your-server.de/reset/321', '' ), array( $this->last()['method'], $this->last()['url'], $this->last()['body'] ) );

		$this->client()->reset_server( 321, 'hw' );
		$this->assertSame( array( 'POST', 'https://robot-ws.your-server.de/reset/321', 'type=hw' ), array( $this->last()['method'], $this->last()['url'], $this->last()['body'] ) );

		$this->client()->wol( 321 );
		$this->assertSame( array( 'POST', 'https://robot-ws.your-server.de/wol/321', '' ), array( $this->last()['method'], $this->last()['url'], $this->last()['body'] ) );
		$this->assertStringStartsWith( 'Basic ', $this->last()['auth'] );
	}

	public function test_rescue_with_and_without_keys(): void {
		$this->client()->rescue_activate( 321, 'linux', array() );
		$this->assertSame( 'os=linux', $this->last()['body'] );

		$this->client()->rescue_activate( 321, 'linux', array( 'a1:b2', 'c3:d4' ) );
		$this->assertSame( 'os=linux&authorized_key%5B%5D=a1%3Ab2&authorized_key%5B%5D=c3%3Ad4', $this->last()['body'] );

		$this->client()->rescue_deactivate( 321 );
		$this->assertSame( array( 'DELETE', 'https://robot-ws.your-server.de/boot/321/rescue' ), array( $this->last()['method'], $this->last()['url'] ) );

		$this->answers['POST /boot/321/rescue'] = array( 200, '{"rescue":{"active":true,"password":"jEt0dtUvomlyOwRr","os":"linux"}}' );
		$got = $this->client()->rescue_activate( 321, 'linux', array() );
		$this->assertSame( 'jEt0dtUvomlyOwRr', $got['password'] );
	}

	public function test_reverse_dns_for_ipv4_and_ipv6(): void {
		$this->answers['GET /rdns'] = array( 404, '{"error":{"status":404,"code":"NOT_FOUND","message":"No reverse DNS entries found"}}' );
		$this->assertSame( array(), $this->client()->rdns_list( '203.0.113.7' ), 'No entries is an empty list.' );
		$this->assertSame( 'https://robot-ws.your-server.de/rdns?server_ip=203.0.113.7', $this->last()['url'] );

		$this->client()->rdns_set( '203.0.113.7', 'srv.example.com' );
		$this->assertSame( array( 'POST', 'https://robot-ws.your-server.de/rdns/203.0.113.7', 'ptr=srv.example.com' ), array( $this->last()['method'], $this->last()['url'], $this->last()['body'] ) );

		$this->client()->rdns_set( '2001:db8:1::25', 'v6.example.com' );
		$this->assertSame( 'https://robot-ws.your-server.de/rdns/2001%3Adb8%3A1%3A%3A25', $this->last()['url'] );

		$this->assertTrue( $this->client()->rdns_delete( '203.0.113.7' ), 'The documented empty answer is a success.' );
		$this->assertSame( 'DELETE', $this->last()['method'] );

		$this->answers['DELETE /rdns/9.9.9.9'] = array( 404, '{"error":{"status":404,"code":"IP_NOT_FOUND","message":"x"}}' );
		$this->assertSame( 'IP_NOT_FOUND', $this->client()->rdns_delete( '9.9.9.9' )->get_error_code() );
	}

	public function test_the_firewall_body_is_the_documented_form_encoding(): void {
		$this->client()->firewall_set(
			321,
			array(
				'status'        => 'active',
				'whitelist_hos' => true,
				'filter_ipv6'   => false,
				'rules'         => array(
					'input'  => array( array( 'name' => 'ssh', 'ip_version' => 'ipv4', 'dst_port' => '22', 'action' => 'accept', 'src_ip' => null ) ),
					'output' => array( array( 'name' => 'Allow all', 'action' => 'accept' ) ),
				),
			)
		);
		$body = rawurldecode( $this->last()['body'] );

		$this->assertSame( 'POST', $this->last()['method'] );
		$this->assertSame( 'https://robot-ws.your-server.de/firewall/321', $this->last()['url'] );
		$this->assertStringContainsString( 'status=active', $body );
		$this->assertStringContainsString( 'whitelist_hos=true', $body );
		$this->assertStringContainsString( 'filter_ipv6=false', $body );
		$this->assertStringContainsString( 'rules[input][0][name]=ssh', $body );
		$this->assertStringContainsString( 'rules[input][0][dst_port]=22', $body );
		$this->assertStringContainsString( 'rules[output][0][action]=accept', $body );
		$this->assertStringNotContainsString( 'src_ip', $body, 'An omitted field is a wildcard: it is not sent as empty.' );
		$this->assertStringContainsString( 'rules%5Binput%5D%5B0%5D%5Bname%5D=ssh', $this->last()['body'], 'Brackets are encoded, as in the documentation.' );
	}

	public function test_the_product_list_is_a_plain_get(): void {
		$this->answers['GET /order/server/product'] = array( 200, '[{"product":{"id":"AX42-1","dist":["Rescue system","Debian 12 base"]}}]' );
		$products                                   = $this->client()->products();

		$this->assertSame( 'GET', $this->last()['method'] );
		$this->assertSame( array( 'Rescue system', 'Debian 12 base' ), $products[0]['dist'] );
	}
}

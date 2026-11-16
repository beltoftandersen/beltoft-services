<?php
/** DNS transport tests; the network is replaced through the wire filter. @package BeltoftServices */
namespace BeltoftServices\Tests\Domains;

use BeltoftServices\Domains\DnsTransport;
use BeltoftServices\Domains\SocketChannel;
use BeltoftServices\Tests\Support\CannedChannel;
use BeltoftServices\Domains\DnsWire;
use BeltoftServices\Tests\Support\DnsPackets;
use BeltoftServices\Tests\TestCase;

class DnsTransportTest extends TestCase {
	protected function tearDown(): void {
		remove_all_filters( 'beltoft_services_dns_wire' );
		DnsTransport::use( null );
		parent::tearDown();
	}

	public function test_answer_is_parsed(): void {
		add_filter( 'beltoft_services_dns_wire', static fn( $pre, $ip, $name, $type ) => DnsPackets::response( $name, $type, array( 'id' => 77, 'aa' => true, 'answer' => array( array( 'name' => $name, 'type' => DnsWire::A, 'data' => '8.8.8.8' ) ) ) ), 10, 4 );
		$response = DnsTransport::ask( '192.5.6.30', 'example.com', DnsWire::A );
		$this->assertSame( '8.8.8.8', $response['answer'][0]['data'] );
	}

	public function test_truncated_udp_answer_is_asked_again_over_tcp(): void {
		$seen = array();
		add_filter(
			'beltoft_services_dns_wire',
			static function ( $pre, $ip, $name, $type, $protocol ) use ( &$seen ) {
				$seen[] = $protocol;
				return 'udp' === $protocol
					? DnsPackets::response( $name, $type, array( 'tc' => true ) )
					: DnsPackets::response( $name, $type, array( 'aa' => true, 'answer' => array( array( 'name' => $name, 'type' => DnsWire::TXT, 'data' => array( 'big' ) ) ) ) );
			},
			10,
			5
		);
		$response = DnsTransport::ask( '192.5.6.30', 'example.com', DnsWire::TXT );
		$this->assertSame( array( 'udp', 'tcp' ), $seen );
		$this->assertSame( array( 'big' ), $response['answer'][0]['data'] );
	}

	public function test_filter_errors_and_bad_packets_are_returned(): void {
		add_filter( 'beltoft_services_dns_wire', static fn() => new \WP_Error( 'dns_timeout', 'slow' ) );
		$this->assertSame( 'dns_timeout', DnsTransport::ask( '192.5.6.30', 'example.com', DnsWire::A )->get_error_code() );
		remove_all_filters( 'beltoft_services_dns_wire' );
		add_filter( 'beltoft_services_dns_wire', static fn() => 'xx' );
		$this->assertSame( 'dns_malformed', DnsTransport::ask( '192.5.6.30', 'example.com', DnsWire::A )->get_error_code() );
		remove_all_filters( 'beltoft_services_dns_wire' );
		add_filter( 'beltoft_services_dns_wire', static fn( $pre, $ip, $name ) => DnsPackets::response( 'other.example', DnsWire::A ), 10, 3 );
		$this->assertSame( 'dns_mismatch', DnsTransport::ask( '192.5.6.30', 'example.com', DnsWire::A )->get_error_code() );
	}

	public function test_a_private_address_never_gets_a_socket(): void {
		$this->assertSame( 'dns_unreachable', DnsTransport::ask( '127.0.0.1', 'example.com', DnsWire::A )->get_error_code() );
		$this->assertSame( 'dns_unreachable', DnsTransport::ask( '::1', 'example.com', DnsWire::A )->get_error_code() );
	}

	private function full( string $name, int $type, bool $tc = false ): string {
		return DnsPackets::response( $name, $type, array( 'id' => 9, 'aa' => true, 'tc' => $tc, 'answer' => $tc ? array() : array( array( 'name' => $name, 'type' => DnsWire::TXT, 'data' => array( 'x' ) ) ) ) );
	}

	public function test_a_complete_udp_answer_is_the_only_send(): void {
		$channel = new CannedChannel( array( 'udp' => $this->full( 'example.com', DnsWire::TXT ) ) );
		DnsTransport::use( $channel );
		$r = DnsTransport::ask( '192.5.6.30', 'example.com', DnsWire::TXT );
		$this->assertSame( array( 'x' ), $r['answer'][0]['data'] );
		$this->assertSame( array( 'udp' ), $channel->protocols() );
		$this->assertSame( array( '192.5.6.30', 53 ), array( $channel->sent[0][0], $channel->sent[0][1] ) );
	}

	public function test_a_truncated_udp_answer_is_asked_over_tcp_once_only(): void {
		$channel = new CannedChannel( array( 'udp' => $this->full( 'example.com', DnsWire::TXT, true ), 'tcp' => $this->full( 'example.com', DnsWire::TXT, true ) ) );
		DnsTransport::use( $channel );
		$r = DnsTransport::ask( '192.5.6.30', 'example.com', DnsWire::TXT );
		$this->assertTrue( $r['tc'], 'A TCP answer that is truncated again is returned as it is.' );
		$this->assertSame( array( 'udp', 'tcp' ), $channel->protocols() );
	}

	public function test_a_channel_error_is_returned_as_it_is_and_tcp_is_not_tried(): void {
		$channel = new CannedChannel( array( 'udp' => new \WP_Error( 'dns_timeout', 'Name server did not answer.' ), 'tcp' => $this->full( 'example.com', DnsWire::TXT ) ) );
		DnsTransport::use( $channel );
		$this->assertSame( 'dns_timeout', DnsTransport::ask( '192.5.6.30', 'example.com', DnsWire::TXT )->get_error_code() );
		$this->assertSame( array( 'udp' ), $channel->protocols() );
	}

	public function test_the_port_and_the_trusted_option_are_ignored_without_trusted(): void {
		$channel = new CannedChannel( array( 'udp' => $this->full( 'example.com', DnsWire::TXT ) ) );
		DnsTransport::use( $channel );
		DnsTransport::ask( '192.5.6.30', 'example.com', DnsWire::TXT, array( 'port' => 5353 ) );
		$this->assertSame( 53, $channel->sent[0][1] );
		$this->assertSame( 'dns_unreachable', DnsTransport::ask( '10.0.0.5', 'example.com', DnsWire::TXT, array( 'port' => 5353 ) )->get_error_code() );
	}

	public function test_a_private_address_never_reaches_the_channel(): void {
		$channel = new CannedChannel( array( 'udp' => $this->full( 'example.com', DnsWire::TXT ) ) );
		DnsTransport::use( $channel );
		$this->assertSame( 'dns_unreachable', DnsTransport::ask( '10.0.0.5', 'example.com', DnsWire::TXT )->get_error_code() );
		$this->assertSame( array(), $channel->sent );
	}

	public function test_a_trusted_ask_uses_its_port_and_a_name_as_host_and_asks_tcp_when_truncated(): void {
		$channel = new CannedChannel( array( 'udp' => $this->full( 'example.com', DnsWire::TXT, true ), 'tcp' => $this->full( 'example.com', DnsWire::TXT ) ) );
		DnsTransport::use( $channel );
		$data = DnsTransport::ask( 'ns1.example.net', 'example.com', DnsWire::TXT, array( 'trusted' => true, 'port' => 5353 ) );
		$this->assertIsArray( $data );
		$this->assertSame( array( 'udp', 'tcp' ), $channel->protocols() );
		$this->assertSame( 5353, $channel->sent[1][1] );
	}

	public function test_a_silent_udp_server_gives_dns_timeout_after_the_lowered_wait(): void {
		$proc = proc_open( array( PHP_BINARY, dirname( __DIR__ ) . '/Support/dns-test-server.php', 'silent' ), array( 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $pipes );
		$this->assertIsResource( $proc );
		$port = (int) trim( (string) fgets( $pipes[1] ) );
		$old  = SocketChannel::timeout( 0.3 );
		$r    = DnsTransport::ask( '127.0.0.1', 'example.com', DnsWire::TXT, array( 'trusted' => true, 'port' => $port ) );
		SocketChannel::timeout( $old );
		proc_terminate( $proc );
		proc_close( $proc );
		$this->assertSame( 'dns_timeout', $r->get_error_code() );
	}
}

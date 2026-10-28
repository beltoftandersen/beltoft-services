<?php
/** DNS transport tests; the network is replaced through the wire filter. @package BeltoftServices */
namespace BeltoftServices\Tests\Domains;

use BeltoftServices\Domains\DnsTransport;
use BeltoftServices\Domains\DnsWire;
use BeltoftServices\Tests\Support\DnsPackets;
use BeltoftServices\Tests\TestCase;

class DnsTransportTest extends TestCase {
	protected function tearDown(): void {
		remove_all_filters( 'beltoft_services_dns_wire' );
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
}

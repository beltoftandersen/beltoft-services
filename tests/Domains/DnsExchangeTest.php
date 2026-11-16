<?php
/** A name server's answer that does not fit in one UDP datagram is read over TCP. @package BeltoftServices */
namespace BeltoftServices\Tests\Domains;

use BeltoftServices\Domains\DnsQuery;
use BeltoftServices\Domains\DnsTransport;
use BeltoftServices\Tests\TestCase;

class DnsExchangeTest extends TestCase {
	public function test_a_key_question_asks_with_edns_so_a_large_answer_fits(): void {
		$wire = DnsQuery::packet( 7, 'example.com', 48 );
		$head = unpack( 'nid/nflags/nquestions/nanswers/nauthority/nadditional', substr( $wire, 0, 12 ) );
		$this->assertSame( 1, $head['additional'] );
		$question = "\x07example\x03com\x00" . pack( 'nn', 48, 1 );
		$this->assertSame( $question, substr( $wire, 12, strlen( $question ) ), 'The DNSKEY question itself.' );
		$opt = unpack( 'ctype_root/ntype/nsize', substr( $wire, 12 + strlen( $question ) ) );
		$this->assertSame( array( 0, 41, 4096 ), array( $opt['type_root'], $opt['type'], $opt['size'] ) );
	}

	public function test_a_truncated_udp_answer_is_asked_again_over_tcp(): void {
		$proc = proc_open( array( PHP_BINARY, dirname( __DIR__ ) . '/Support/dns-tc-server.php' ), array( 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $pipes );
		$this->assertIsResource( $proc );
		$port = (int) trim( (string) fgets( $pipes[1] ) );
		$this->assertGreaterThan( 0, $port, 'The test name server started.' );

		$wire = DnsQuery::packet( 7, 'example.com', 48 );
		$data = DnsTransport::exchange( '127.0.0.1', $wire, $port );
		proc_close( $proc );

		$this->assertIsString( $data );
		$this->assertSame( 40, DnsQuery::parse_answer( $data, 7, 48, 'example.com' ), 'The full answer came over TCP, all forty keys.' );
	}

	/** Runs the scripted test name server and asks it one DNSKEY question; returns [exchange result, the packet sent]. */
	private function ask_server( string $scenario ): array {
		$proc = proc_open( array( PHP_BINARY, dirname( __DIR__ ) . '/Support/dns-test-server.php', $scenario ), array( 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $pipes );
		$this->assertIsResource( $proc );
		$port = (int) trim( (string) fgets( $pipes[1] ) );
		$this->assertGreaterThan( 0, $port, 'The test name server started.' );

		$wire   = DnsQuery::packet( 7, 'example.com', 48 );
		$result = DnsTransport::exchange( '127.0.0.1', $wire, $port );
		proc_terminate( $proc );
		proc_close( $proc );

		return array( $result, $wire );
	}

	public function test_a_reply_with_another_id_is_handed_back_unchecked_and_refused_by_the_parser(): void {
		list( $data ) = $this->ask_server( 'wrong_id' );
		$this->assertIsString( $data, 'The transport does not look at the id.' );
		$this->assertSame( 'dns_response', DnsQuery::parse_answer( $data, 7, 48, 'example.com' )->get_error_code() );
	}

	public function test_a_truncated_answer_with_nothing_in_it_is_read_again_over_tcp(): void {
		list( $data ) = $this->ask_server( 'tc_empty' );
		$this->assertSame( 1, DnsQuery::parse_answer( $data, 7, 48, 'example.com' ) );
	}

	public function test_a_tcp_answer_that_comes_in_three_pieces_is_read_whole(): void {
		list( $data ) = $this->ask_server( 'tc_two_segments' );
		$this->assertSame( 3, DnsQuery::parse_answer( $data, 7, 48, 'example.com' ) );
	}

	public function test_a_tcp_answer_shorter_than_its_length_prefix_is_an_error(): void {
		list( $data ) = $this->ask_server( 'tc_short_body' );
		$this->assertSame( 'dns_timeout', $data->get_error_code() );
	}

	public function test_a_tcp_connection_closed_without_an_answer_is_an_error(): void {
		list( $data ) = $this->ask_server( 'tc_closed' );
		$this->assertSame( 'dns_timeout', $data->get_error_code() );
	}

	public function test_an_answer_that_is_truncated_over_tcp_too_is_handed_back_as_is_and_refused_by_the_parser(): void {
		list( $data ) = $this->ask_server( 'tc_empty_loop' );
		$this->assertIsString( $data );
		$this->assertSame( 'dns_response', DnsQuery::parse_answer( $data, 7, 48, 'example.com' )->get_error_code(), 'Truncated: not an authoritative answer.' );
	}
}

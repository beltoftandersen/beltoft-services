<?php
/** A name server's answer that does not fit in one UDP datagram is read over TCP. @package BeltoftServices */
namespace BeltoftServices\Tests\Domains;

use BeltoftServices\Domains\DnsQuery;
use BeltoftServices\Tests\TestCase;

class DnsExchangeTest extends TestCase {
	public function test_a_truncated_udp_answer_is_asked_again_over_tcp(): void {
		list( $value ) = $this->ask_server( 'tc_empty' ); // One key over TCP.
		$this->assertSame( 1, $value );
	}

	/** Runs the scripted test name server and reads the number of DNSKEY records from it through the strict reader. */
	private function ask_server( string $scenario ): array {
		$proc = proc_open( array( PHP_BINARY, dirname( __DIR__ ) . '/Support/dns-test-server.php', $scenario ), array( 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $pipes );
		$this->assertIsResource( $proc );
		$port = (int) trim( (string) fgets( $pipes[1] ) );
		$this->assertGreaterThan( 0, $port, 'The test name server started.' );

		$result = DnsQuery::read_wire( '127.0.0.1', 'example.com', 48, $port );
		proc_terminate( $proc );
		proc_close( $proc );

		return array( $result );
	}

	public function test_a_reply_with_another_id_is_refused(): void {
		list( $data ) = $this->ask_server( 'wrong_id' );
		$this->assertSame( 'dns_response', $data->get_error_code(), 'An answer with another id is refused.' );
	}

	public function test_a_truncated_answer_with_nothing_in_it_is_read_again_over_tcp(): void {
		list( $data ) = $this->ask_server( 'tc_empty' );
		$this->assertSame( 1, $data );
	}

	public function test_a_tcp_answer_that_comes_in_three_pieces_is_read_whole(): void {
		list( $data ) = $this->ask_server( 'tc_two_segments' );
		$this->assertSame( 3, $data );
	}

	public function test_a_tcp_answer_shorter_than_its_length_prefix_is_an_error(): void {
		list( $data ) = $this->ask_server( 'tc_short_body' );
		$this->assertSame( 'dns_transport', $data->get_error_code() );
	}

	public function test_a_tcp_connection_closed_without_an_answer_is_an_error(): void {
		list( $data ) = $this->ask_server( 'tc_closed' );
		$this->assertSame( 'dns_transport', $data->get_error_code() );
	}

	public function test_an_answer_that_is_truncated_over_tcp_too_is_refused(): void {
		list( $data ) = $this->ask_server( 'tc_empty_loop' );
		$this->assertSame( 'dns_response', $data->get_error_code(), 'Truncated over TCP too: not an authoritative answer.' );
	}

	public function test_a_large_key_set_that_does_not_fit_in_a_datagram_is_read_whole_over_tcp(): void {
		$proc = proc_open( array( PHP_BINARY, dirname( __DIR__ ) . '/Support/dns-tc-server.php' ), array( 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $pipes );
		$this->assertIsResource( $proc );
		$port = (int) trim( (string) fgets( $pipes[1] ) );
		$this->assertGreaterThan( 0, $port );
		$value = DnsQuery::read_wire( '127.0.0.1', 'example.com', 48, $port );
		proc_close( $proc );
		$this->assertSame( 40, $value, 'The full answer came over TCP, all forty keys.' );
	}
}

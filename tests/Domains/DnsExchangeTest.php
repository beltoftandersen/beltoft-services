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
}

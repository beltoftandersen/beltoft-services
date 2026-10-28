<?php
/** DNS wire format tests. @package BeltoftServices */
namespace BeltoftServices\Tests\Domains;

use BeltoftServices\Domains\DnsWire;
use BeltoftServices\Tests\Support\DnsPackets;
use BeltoftServices\Tests\TestCase;

class DnsWireTest extends TestCase {
	public function test_query_has_header_question_and_opt(): void {
		$wire = DnsWire::query( 'Example.com.', DnsWire::TXT, 4660 );
		$head = unpack( 'nid/nflags/nqd/nan/nns/nar', $wire );
		$this->assertSame( array( 4660, 0, 1, 0, 0, 1 ), array_values( $head ) );
		$this->assertStringContainsString( "\x07Example\x03com\x00" . pack( 'nn', 16, 1 ), $wire );
		$this->assertSame( "\x00" . pack( 'nnNn', 41, 1232, 0, 0 ), substr( $wire, -11 ) );
	}

	public function test_parses_each_record_type(): void {
		$rrs    = array(
			array( 'name' => 'example.com', 'type' => DnsWire::A, 'ttl' => 60, 'data' => '192.0.2.1' ),
			array( 'name' => 'example.com', 'type' => DnsWire::AAAA, 'ttl' => 60, 'data' => '2001:db8::1' ),
			array( 'name' => 'EXAMPLE.com', 'type' => DnsWire::NS, 'ttl' => 60, 'data' => 'NS1.Example.net' ),
			array( 'name' => 'example.com', 'type' => DnsWire::CNAME, 'ttl' => 60, 'data' => 'target.example.net' ),
			array( 'name' => 'example.com', 'type' => DnsWire::MX, 'ttl' => 60, 'data' => array( 'priority' => 10, 'target' => 'mail.example.com' ) ),
			array( 'name' => 'example.com', 'type' => DnsWire::SRV, 'ttl' => 60, 'data' => array( 'priority' => 1, 'weight' => 2, 'port' => 443, 'target' => 'sip.example.com' ) ),
			array( 'name' => 'example.com', 'type' => DnsWire::TXT, 'ttl' => 60, 'data' => array( 'v=spf1 ', 'include:x' ) ),
			array( 'name' => 'example.com', 'type' => DnsWire::CAA, 'ttl' => 60, 'data' => array( 'flag' => 128, 'tag' => 'issue', 'value' => 'letsencrypt.org' ) ),
			array( 'name' => 'example.com', 'type' => DnsWire::DS, 'ttl' => 60, 'data' => array( 'key_tag' => 2371, 'algorithm' => 13, 'digest_type' => 2, 'digest' => 'abcdef0123' ) ),
			array( 'name' => 'example.com', 'type' => DnsWire::SOA, 'ttl' => 60, 'data' => array( 'mname' => 'ns1.example.net', 'rname' => 'host.example.net', 'serial' => 2026102801, 'minimum' => 300 ) ),
			array( 'name' => 'example.com', 'type' => 99, 'ttl' => 60, 'data' => 'unknown-bytes' ),
			array( 'name' => 'example.com', 'type' => DnsWire::A, 'ttl' => 60, 'data' => '192.0.2.9', 'class' => 3 ),
		);
		$packet = DnsPackets::response( 'example.com', DnsWire::A, array( 'id' => 7, 'aa' => true, 'answer' => $rrs, 'additional' => array( array( 'name' => '', 'type' => DnsWire::OPT, 'ttl' => 0, 'data' => '' ) ) ) );
		$parsed = DnsWire::parse( $packet, 7, 'EXAMPLE.com.', DnsWire::A );
		$this->assertIsArray( $parsed );
		$this->assertTrue( $parsed['aa'] );
		$this->assertFalse( $parsed['tc'] );
		$this->assertSame( 0, $parsed['rcode'] );
		$this->assertSame( array(), $parsed['additional'] );
		$answer = $parsed['answer'];
		$this->assertCount( 10, $answer );
		$this->assertSame( array( 'name' => 'example.com', 'type' => 1, 'ttl' => 60, 'data' => '192.0.2.1' ), $answer[0] );
		$this->assertSame( '2001:db8::1', $answer[1]['data'] );
		$this->assertSame( 'ns1.example.net', $answer[2]['data'] );
		$this->assertSame( 'example.com', $answer[2]['name'] );
		$this->assertSame( 'target.example.net', $answer[3]['data'] );
		$this->assertSame( array( 'priority' => 10, 'target' => 'mail.example.com' ), $answer[4]['data'] );
		$this->assertSame( array( 'priority' => 1, 'weight' => 2, 'port' => 443, 'target' => 'sip.example.com' ), $answer[5]['data'] );
		$this->assertSame( array( 'v=spf1 ', 'include:x' ), $answer[6]['data'] );
		$this->assertSame( array( 'flag' => 128, 'tag' => 'issue', 'value' => 'letsencrypt.org' ), $answer[7]['data'] );
		$this->assertSame( array( 'key_tag' => 2371, 'algorithm' => 13, 'digest_type' => 2, 'digest' => 'abcdef0123' ), $answer[8]['data'] );
		$this->assertSame( array( 'mname' => 'ns1.example.net', 'rname' => 'host.example.net', 'serial' => 2026102801, 'minimum' => 300 ), $answer[9]['data'] );
	}

	public function test_name_compression_is_followed(): void {
		$packet = DnsPackets::response( 'example.com', DnsWire::NS, array( 'id' => 9, 'compress' => true, 'answer' => array( array( 'name' => 'example.com', 'type' => DnsWire::NS, 'data' => 'ns1.example.net' ) ) ) );
		$parsed = DnsWire::parse( $packet, 9, 'example.com', DnsWire::NS );
		$this->assertSame( 'example.com', $parsed['answer'][0]['name'] );
		// A compressed name inside rdata: ns2.example.com points at example.com in the question.
		$question = DnsPackets::name( 'example.com' );
		$head     = pack( 'nnnnnn', 9, 0x8000, 1, 1, 0, 0 );
		$rdata    = "\x03ns2\xc0\x0c";
		$packet   = $head . $question . pack( 'nn', 2, 1 ) . "\xc0\x0c" . pack( 'nnNn', 2, 1, 60, strlen( $rdata ) ) . $rdata;
		$parsed   = DnsWire::parse( $packet, 9, 'example.com', DnsWire::NS );
		$this->assertSame( 'ns2.example.com', $parsed['answer'][0]['data'] );
	}

	public function test_pointer_loop_and_forward_pointer_are_malformed(): void {
		$head   = pack( 'nnnnnn', 9, 0x8000, 1, 1, 0, 0 );
		$qname  = DnsPackets::name( 'example.com' );
		$offset = 12 + strlen( $qname ) + 4;
		// The owner name points at itself.
		$loop = $head . $qname . pack( 'nn', 1, 1 ) . pack( 'n', 0xc000 | $offset ) . pack( 'nnNn', 1, 1, 60, 4 ) . "\x01\x02\x03\x04";
		$this->assertSame( 'dns_malformed', DnsWire::parse( $loop, 9, 'example.com', 1 )->get_error_code() );
		// The owner name points forward, past itself.
		$forward = $head . $qname . pack( 'nn', 1, 1 ) . pack( 'n', 0xc000 | ( $offset + 2 ) ) . pack( 'nnNn', 1, 1, 60, 4 ) . "\x01\x02\x03\x04";
		$this->assertSame( 'dns_malformed', DnsWire::parse( $forward, 9, 'example.com', 1 )->get_error_code() );
		// A chain of 25 backwards pointers (kept in the data of a skipped record) exceeds the jump limit.
		$prefix = $head . $qname . pack( 'nn', 1, 1 );
		$data   = strlen( $prefix ) + 2 + 10;
		$chain  = '';
		for ( $i = 0; $i < 25; ++$i ) { $chain .= pack( 'n', 0xc000 | ( 0 === $i ? 12 : $data + 2 * ( $i - 1 ) ) ); }
		$deep = pack( 'nnnnnn', 9, 0x8000, 1, 2, 0, 0 ) . $qname . pack( 'nn', 1, 1 ) . "\xc0\x0c" . pack( 'nnNn', 99, 1, 60, strlen( $chain ) ) . $chain . pack( 'n', 0xc000 | ( $data + 48 ) ) . pack( 'nnNn', 1, 1, 60, 4 ) . "\x01\x02\x03\x04";
		$this->assertSame( 'dns_malformed', DnsWire::parse( $deep, 9, 'example.com', 1 )->get_error_code() );
		// The same record with a short chain of 3 pointers is fine, so the failure above is the jump limit.
		$short = pack( 'nnnnnn', 9, 0x8000, 1, 2, 0, 0 ) . $qname . pack( 'nn', 1, 1 ) . "\xc0\x0c" . pack( 'nnNn', 99, 1, 60, 6 ) . pack( 'nnn', 0xc00c, 0xc000 | $data, 0xc000 | ( $data + 2 ) ) . pack( 'n', 0xc000 | ( $data + 4 ) ) . pack( 'nnNn', 1, 1, 60, 4 ) . "\x01\x02\x03\x04";
		$this->assertSame( 'example.com', DnsWire::parse( $short, 9, 'example.com', 1 )['answer'][0]['name'] );
	}

	public function test_truncated_and_oversized_packets_are_malformed(): void {
		$packet = DnsPackets::response( 'example.com', DnsWire::A, array( 'id' => 3, 'answer' => array( array( 'name' => 'example.com', 'type' => DnsWire::A, 'data' => '192.0.2.1' ) ) ) );
		foreach ( array( 5, 12, 20, strlen( $packet ) - 1 ) as $cut ) {
			$this->assertSame( 'dns_malformed', DnsWire::parse( substr( $packet, 0, $cut ), 3, 'example.com', 1 )->get_error_code(), "cut at $cut" );
		}
		// An A record with a 3 byte rdata, a label length of 64, a record that claims more bytes than there are.
		$head = pack( 'nnnnnn', 3, 0x8000, 1, 1, 0, 0 ) . DnsPackets::name( 'example.com' ) . pack( 'nn', 1, 1 );
		$this->assertSame( 'dns_malformed', DnsWire::parse( $head . "\xc0\x0c" . pack( 'nnNn', 1, 1, 60, 3 ) . "\x01\x02\x03", 3, 'example.com', 1 )->get_error_code() );
		$this->assertSame( 'dns_malformed', DnsWire::parse( pack( 'nnnnnn', 3, 0x8000, 1, 0, 0, 0 ) . chr( 64 ) . str_repeat( 'a', 64 ) . "\0" . pack( 'nn', 1, 1 ), 3, 'example.com', 1 )->get_error_code() );
		$this->assertSame( 'dns_malformed', DnsWire::parse( $head . "\xc0\x0c" . pack( 'nnNn', 1, 1, 60, 400 ) . 'xx', 3, 'example.com', 1 )->get_error_code() );
		// A TXT string that runs past the record.
		$this->assertSame( 'dns_malformed', DnsWire::parse( $head . "\xc0\x0c" . pack( 'nnNn', 16, 1, 60, 3 ) . "\x09ab", 3, 'example.com', 1 )->get_error_code() );
		// A query is not a response.
		$this->assertSame( 'dns_malformed', DnsWire::parse( DnsWire::query( 'example.com', 1, 3 ), 3, 'example.com', 1 )->get_error_code() );
	}

	public function test_id_and_question_must_match(): void {
		$packet = DnsPackets::response( 'example.com', DnsWire::TXT, array( 'id' => 5 ) );
		$this->assertIsArray( DnsWire::parse( $packet, 5, 'EXAMPLE.COM', DnsWire::TXT ) );
		$this->assertSame( 'dns_mismatch', DnsWire::parse( $packet, 6, 'example.com', DnsWire::TXT )->get_error_code() );
		$this->assertSame( 'dns_mismatch', DnsWire::parse( $packet, 5, 'example.org', DnsWire::TXT )->get_error_code() );
		$this->assertSame( 'dns_mismatch', DnsWire::parse( $packet, 5, 'example.com', DnsWire::A )->get_error_code() );
	}

	public function test_flags_are_read(): void {
		$packet = DnsPackets::response( 'example.com', DnsWire::A, array( 'id' => 5, 'rcode' => 3, 'tc' => true ) );
		$parsed = DnsWire::parse( $packet, 5, 'example.com', DnsWire::A );
		$this->assertSame( 3, $parsed['rcode'] );
		$this->assertTrue( $parsed['tc'] );
		$this->assertFalse( $parsed['aa'] );
	}
}

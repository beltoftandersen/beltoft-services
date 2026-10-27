<?php
/** The questions sent to name servers. @package BeltoftServices */
namespace BeltoftServices\Tests\Domains;

use BeltoftServices\Domains\DnsQuery;
use BeltoftServices\Tests\TestCase;

class DnsQueryTest extends TestCase {
	public function test_a_serial_check_is_a_plain_soa_question(): void {
		$wire = DnsQuery::packet( 7, 'example.com', 6 );
		$head = unpack( 'nid/nflags/nquestions/nanswers/nauthority/nadditional', substr( $wire, 0, 12 ) );

		$this->assertSame( 1, $head['questions'] );
		$this->assertSame( 0, $head['additional'], 'No EDNS record: nothing but the question.' );
		$this->assertSame( 6, unpack( 'ntype', substr( $wire, -4, 2 ) )['type'] );
	}

	public function test_a_signature_check_asks_for_the_soa_with_signatures_requested(): void {
		$wire = DnsQuery::packet( 7, 'example.com', 46 );
		$head = unpack( 'nid/nflags/nquestions/nanswers/nauthority/nadditional', substr( $wire, 0, 12 ) );

		$this->assertSame( 1, $head['additional'], 'An EDNS0 record is attached.' );
		// The question: example.com SOA IN, then the OPT record (root name, type 41, size 4096, DO bit, no data).
		$question = "\x07example\x03com\x00" . pack( 'nn', 6, 1 );
		$this->assertSame( $question, substr( $wire, 12, strlen( $question ) ), 'A name server refuses a direct RRSIG question, so the SOA is asked for.' );
		$opt = unpack( 'ctype_root/ntype/nsize/Nflags/nlength', substr( $wire, 12 + strlen( $question ) ) );
		$this->assertSame( array( 0, 41, 4096, 0x8000, 0 ), array( $opt['type_root'], $opt['type'], $opt['size'], $opt['flags'], $opt['length'] ) );
	}
}

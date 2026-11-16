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

	/**
	 * A canned answer: header, the question, and the given answers (type, rdata), names compressed to the question.
	 *
	 * @param int                           $id      Id.
	 * @param int                           $flags   Header flags.
	 * @param int                           $qtype   Type asked.
	 * @param array<int, array{0:int,1:string}> $answers Answers.
	 */
	private function answer( int $id, int $flags, int $qtype, array $answers ): string {
		$wire = pack( 'nnnnnn', $id, $flags, 1, count( $answers ), 0, 0 ) . "\x07example\x03com\x00" . pack( 'nn', $qtype, 1 );

		foreach ( $answers as $a ) {
			$wire .= "\xc0\x0c" . pack( 'nnNn', $a[0], 1, 300, strlen( $a[1] ) ) . $a[1];
		}

		return $wire;
	}

	public function test_an_authoritative_answer_gives_the_soa_serial(): void {
		$soa = "\x02ns\xc0\x0c\x04host\xc0\x0c" . pack( 'NNNNN', 2026111601, 3600, 600, 86400, 300 );

		$this->assertSame( 2026111601, DnsQuery::parse_answer( $this->answer( 7, 0x8400, 6, array( array( 6, $soa ) ) ), 7, 6 ) );
	}

	public function test_the_earliest_signature_expiry_and_the_key_count_are_read(): void {
		// RRSIG rdata: type covered (2), algorithm (1), labels (1), original ttl (4), expiration (4 at offset 8).
		$sig = static fn( int $expires ) => pack( 'nccNN', 6, 13, 2, 300, $expires ) . str_repeat( "\0", 4 );

		$this->assertSame( 1700000000, DnsQuery::parse_answer( $this->answer( 7, 0x8400, 6, array( array( 46, $sig( 1800000000 ) ), array( 46, $sig( 1700000000 ) ) ) ), 7, 46 ) );
		$this->assertSame( 2, DnsQuery::parse_answer( $this->answer( 7, 0x8400, 48, array( array( 48, 'k1' ), array( 48, 'k2' ) ) ), 7, 48 ) );
	}

	public function test_answers_that_are_not_authoritative_or_not_ours_are_refused(): void {
		$soa = "\x02ns\xc0\x0c\x04host\xc0\x0c" . pack( 'NNNNN', 1, 3600, 600, 86400, 300 );

		$this->assertSame( 'dns_response', DnsQuery::parse_answer( $this->answer( 8, 0x8400, 6, array( array( 6, $soa ) ) ), 7, 6 )->get_error_code(), 'Another id.' );
		$this->assertSame( 'dns_response', DnsQuery::parse_answer( $this->answer( 7, 0x8000, 6, array( array( 6, $soa ) ) ), 7, 6 )->get_error_code(), 'Not authoritative (no AA).' );
		$this->assertSame( 'dns_response', DnsQuery::parse_answer( $this->answer( 7, 0x8600, 6, array( array( 6, $soa ) ) ), 7, 6 )->get_error_code(), 'Truncated.' );
		$this->assertSame( 'dns_not_authoritative', DnsQuery::parse_answer( $this->answer( 7, 0x8005, 6, array() ), 7, 6 )->get_error_code(), 'REFUSED: the server does not serve the zone.' );
		$this->assertSame( 'dns_missing', DnsQuery::parse_answer( $this->answer( 7, 0x8400, 6, array() ), 7, 6 )->get_error_code(), 'No record in the answer.' );
		$this->assertSame( 'dns_response', DnsQuery::parse_answer( substr( $this->answer( 7, 0x8400, 6, array( array( 6, 'xx' ) ) ), 0, -3 ), 7, 6 )->get_error_code(), 'A cut off answer.' );
	}
}

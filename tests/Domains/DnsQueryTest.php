<?php
/** The questions sent to name servers. @package BeltoftServices */
namespace BeltoftServices\Tests\Domains;

use BeltoftServices\Domains\DnsQuery;
use BeltoftServices\Domains\DnsTransport;
use BeltoftServices\Tests\Support\CannedChannel;
use BeltoftServices\Tests\TestCase;

class DnsQueryTest extends TestCase {
	protected function tearDown(): void {
		DnsTransport::use( null );
		parent::tearDown();
	}

	/** What read_wire sends for a type, and the channel it used. */
	private function sent( int $type ): array {
		$channel = new CannedChannel( array() );
		DnsTransport::use( $channel );
		DnsQuery::read_wire( '10.10.10.2', 'example.com', $type );
		$wire = $channel->sent[0][2];

		return array( $wire, unpack( 'nid/nflags/nquestions/nanswers/nauthority/nadditional', substr( $wire, 0, 12 ) ), $channel );
	}

	/** The value read from a canned UDP answer; the channel echoes the id of the question unless told not to. */
	private function read( string $packet, int $type, bool $echo_id = true ): int|\WP_Error {
		DnsTransport::use( new CannedChannel( array( 'udp' => $packet ), $echo_id ) );

		return DnsQuery::read_wire( '10.10.10.2', 'example.com', $type );
	}

	public function test_a_serial_check_is_an_soa_question_with_recursion_off_and_goes_to_our_server_without_the_public_check(): void {
		list( $wire, $head, $channel ) = $this->sent( 6 );

		$this->assertSame( 0, $head['flags'], 'RD off: a name server that is authoritative ignores it.' );
		$this->assertSame( 1, $head['questions'] );
		$this->assertSame( "\x07example\x03com\x00" . pack( 'nn', 6, 1 ), substr( $wire, 12, 17 ) );
		$this->assertSame( array( '10.10.10.2', 53, 'udp' ), array( $channel->sent[0][0], $channel->sent[0][1], $channel->sent[0][3] ), 'A private address is allowed for our own server.' );
	}

	public function test_a_signature_check_asks_for_the_soa_with_signatures_requested(): void {
		list( $wire ) = $this->sent( 46 );

		$question = "\x07example\x03com\x00" . pack( 'nn', 6, 1 );
		$this->assertSame( $question, substr( $wire, 12, strlen( $question ) ), 'A name server refuses a direct RRSIG question, so the SOA is asked for.' );
		$opt = unpack( 'ctype_root/ntype/nsize/Nflags/nlength', substr( $wire, 12 + strlen( $question ) ) );
		$this->assertSame( array( 0, 41, 4096, 0x8000, 0 ), array( $opt['type_root'], $opt['type'], $opt['size'], $opt['flags'], $opt['length'] ) );
	}

	public function test_a_key_check_asks_for_the_keys_with_a_large_udp_size_and_no_signatures(): void {
		list( $wire ) = $this->sent( 48 );

		$question = "\x07example\x03com\x00" . pack( 'nn', 48, 1 );
		$this->assertSame( $question, substr( $wire, 12, strlen( $question ) ) );
		$opt = unpack( 'ctype_root/ntype/nsize/Nflags', substr( $wire, 12 + strlen( $question ) ) );
		$this->assertSame( array( 41, 4096, 0 ), array( $opt['type'], $opt['size'], $opt['flags'] ) );
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

		$this->assertSame( 2026111601, $this->read( $this->answer( 7, 0x8400, 6, array( array( 6, $soa ) ) ), 6 ) );
	}

	public function test_the_earliest_signature_expiry_and_the_key_count_are_read(): void {
		// RRSIG rdata: type covered (2), algorithm (1), labels (1), original ttl (4), expiration (4 at offset 8).
		$sig = static fn( int $expires ) => pack( 'nCCNNNn', 6, 13, 2, 300, $expires, 0, 1 ) . "\0";

		$this->assertSame( 1700000000, $this->read( $this->answer( 7, 0x8400, 6, array( array( 46, $sig( 1800000000 ) ), array( 46, $sig( 1700000000 ) ) ) ), 46 ) );
		$this->assertSame( 2, $this->read( $this->answer( 7, 0x8400, 48, array( array( 48, pack( 'nCC', 257, 3, 13 ) . 'k1' ), array( 48, pack( 'nCC', 257, 3, 13 ) . 'k2' ) ) ), 48 ) );
	}

	public function test_answers_that_are_not_authoritative_or_not_ours_are_refused(): void {
		$soa = "\x02ns\xc0\x0c\x04host\xc0\x0c" . pack( 'NNNNN', 1, 3600, 600, 86400, 300 );

		$this->assertSame( 'dns_response', $this->read( $this->answer( 8, 0x8400, 6, array( array( 6, $soa ) ) ), 6, false )->get_error_code(), 'Another id.' );
		$this->assertSame( 'dns_response', $this->read( $this->answer( 7, 0x8000, 6, array( array( 6, $soa ) ) ), 6 )->get_error_code(), 'Not authoritative (no AA).' );
		$truncated = $this->answer( 7, 0x8600, 6, array( array( 6, $soa ) ) );
		DnsTransport::use( new CannedChannel( array( 'udp' => $truncated, 'tcp' => $truncated ) ) );
		$this->assertSame( 'dns_response', DnsQuery::read_wire( '10.10.10.2', 'example.com', 6 )->get_error_code(), 'Truncated over TCP too.' );
		$this->assertSame( 'dns_not_authoritative', $this->read( $this->answer( 7, 0x8005, 6, array() ), 6 )->get_error_code(), 'REFUSED: the server does not serve the zone.' );
		$this->assertSame( 'dns_missing', $this->read( $this->answer( 7, 0x8400, 6, array() ), 6 )->get_error_code(), 'No record in the answer.' );
		$this->assertSame( 'dns_response', $this->read( substr( $this->answer( 7, 0x8400, 6, array( array( 6, 'xx' ) ) ), 0, -3 ), 6 )->get_error_code(), 'A cut off answer.' );
	}

	/** A canned answer with its own question name and type and its own owner name (uncompressed). */
	private function answer_for( string $qname, int $qtype, string $owner, int $type, string $rdata ): string {
		$name = static function ( string $n ): string { $o = ''; foreach ( explode( '.', $n ) as $l ) { $o .= chr( strlen( $l ) ) . $l; } return $o . "\0"; };
		return pack( 'nnnnnn', 7, 0x8400, 1, 1, 0, 0 ) . $name( $qname ) . pack( 'nn', $qtype, 1 ) . $name( $owner ) . pack( 'nnNn', $type, 1, 300, strlen( $rdata ) ) . $rdata;
	}

	public function test_an_answer_for_another_owner_name_is_refused(): void {
		$soa = "\x02ns\xc0\x0c\x04host\xc0\x0c" . pack( 'NNNNN', 2026111601, 3600, 600, 86400, 300 );
		$this->assertSame( 2026111601, $this->read( $this->answer_for( 'example.com', 6, 'example.com', 6, $soa ), 6 ), 'The right owner is read.' );
		$this->assertSame( 'dns_response', $this->read( $this->answer_for( 'example.com', 6, 'other.org', 6, $soa ), 6 )->get_error_code(), 'A serial of another zone must not count.' );
		$this->assertSame( 'dns_response', $this->read( $this->answer_for( 'example.com', 48, 'other.org', 48, 'k1' ), 48 )->get_error_code(), 'Keys of another zone must not count for the DS.' );
	}

	public function test_an_answer_that_does_not_echo_the_question_is_refused(): void {
		$soa = "\x02ns\xc0\x0c\x04host\xc0\x0c" . pack( 'NNNNN', 2026111601, 3600, 600, 86400, 300 );
		$this->assertSame( 'dns_response', $this->read( $this->answer_for( 'other.org', 6, 'example.com', 6, $soa ), 6 )->get_error_code(), 'Another question name.' );
		$this->assertSame( 'dns_response', $this->read( $this->answer_for( 'example.com', 16, 'example.com', 48, 'k1' ), 48 )->get_error_code(), 'Another question type.' );
		$this->assertSame( 2026111601, $this->read( $this->answer_for( 'EXAMPLE.com', 6, 'Example.COM', 6, $soa ), 6 ), 'Case and a trailing dot do not matter.' );
	}

	public function test_an_answer_with_records_only_in_the_authority_section_has_no_value(): void {
		$wire = pack( 'nnnnnn', 7, 0x8400, 1, 0, 1, 0 ) . "\x07example\x03com\x00" . pack( 'nn', 6, 1 ) . "\xc0\x0c" . pack( 'nnNn', 6, 1, 300, 22 ) . "\0\0" . pack( 'NNNNN', 1, 3600, 600, 86400, 300 );
		$this->assertSame( 'dns_missing', $this->read( $wire, 6 )->get_error_code() );
	}

	public function test_an_answer_that_claims_more_records_than_it_holds_is_refused(): void {
		$wire = pack( 'nnnnnn', 7, 0x8400, 1, 3, 0, 0 ) . "\x07example\x03com\x00" . pack( 'nn', 48, 1 ) . "\xc0\x0c" . pack( 'nnNn', 48, 1, 300, 2 ) . 'k1';
		$this->assertSame( 'dns_response', $this->read( $wire, 48 )->get_error_code() );
	}

	public function test_an_owner_pointer_to_nowhere_is_refused(): void {
		$wire = pack( 'nnnnnn', 7, 0x8400, 1, 1, 0, 0 ) . "\x07example\x03com\x00" . pack( 'nn', 48, 1 ) . "\xc3\xe8" . pack( 'nnNn', 48, 1, 300, 2 ) . 'k1';
		$this->assertSame( 'dns_response', $this->read( $wire, 48 )->get_error_code() );
	}

	public function test_a_name_with_a_label_over_63_bytes_is_refused_and_nothing_is_sent(): void {
		$channel = new CannedChannel( array() );
		DnsTransport::use( $channel );
		$this->assertSame( 'dns_response', DnsQuery::read_wire( '10.10.10.2', str_repeat( 'a', 64 ) . '.example.com', 6 )->get_error_code() );
		$this->assertSame( array(), $channel->sent );
	}

	public function test_no_answer_from_the_server_is_dns_transport(): void {
		DnsTransport::use( new CannedChannel( array( 'udp' => new \WP_Error( 'dns_timeout', 'x' ) ) ) );
		$this->assertSame( 'dns_transport', DnsQuery::read_wire( '10.10.10.2', 'example.com', 6 )->get_error_code() );
	}
}

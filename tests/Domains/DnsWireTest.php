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

	public function test_a_name_with_a_label_over_63_bytes_or_over_253_in_all_is_refused_not_cut(): void {
		$long = str_repeat( 'a', 64 ) . '.example.com';
		$this->assertSame( 'dns_bad_name', DnsWire::query( $long, DnsWire::A, 1 )->get_error_code() );
		$this->assertIsString( DnsWire::query( str_repeat( 'a', 63 ) . '.example.com', DnsWire::A, 1 ), 'A label of 63 bytes is fine.' );
		$name253 = implode( '.', array( str_repeat( 'a', 63 ), str_repeat( 'b', 63 ), str_repeat( 'c', 63 ), str_repeat( 'd', 61 ) ) );
		$this->assertSame( 253, strlen( $name253 ) );
		$this->assertIsString( DnsWire::query( $name253, DnsWire::A, 1 ) );
		$this->assertSame( 'dns_bad_name', DnsWire::query( $name253 . 'x', DnsWire::A, 1 )->get_error_code() );
		$this->assertSame( 'dns_bad_name', DnsWire::query( 'a..example.com', DnsWire::A, 1 )->get_error_code(), 'An empty label is not skipped either.' );
	}

	public function test_the_transport_sends_nothing_for_a_name_that_cannot_be_encoded(): void {
		$seen = 0;
		add_filter( 'beltoft_services_dns_wire', static function ( $r ) use ( &$seen ) { ++$seen; return $r; }, 10, 1 );
		$result = \BeltoftServices\Domains\DnsTransport::ask( '203.0.113.5', str_repeat( 'a', 64 ) . '.example.com', DnsWire::TXT );
		$this->assertSame( 'dns_bad_name', $result->get_error_code() );
		$this->assertSame( 0, $seen, 'Refused before the network (or its test replacement) is reached.' );
	}

	/** A response header and the question for example.com A (id 3), with the counts given. */
	private function head( int $qd = 1, int $an = 0, int $ns = 0, int $ar = 0 ): string {
		return pack( 'nnnnnn', 3, 0x8400, $qd, $an, $ns, $ar ) . ( $qd > 0 ? DnsPackets::name( 'example.com' ) . pack( 'nn', 1, 1 ) : '' );
	}

	private function code( string $packet ): string {
		$r = DnsWire::parse( $packet, 3, 'example.com', 1 );
		return is_wp_error( $r ) ? $r->get_error_code() : 'ok';
	}

	public function test_a_compression_pointer_inside_rdata_is_followed(): void {
		$ns = $this->head( 1, 1 ) . "\xc0\x0c" . pack( 'nnNn', 2, 1, 60, 5 ) . "\x02ns\xc0\x0c";
		$r  = DnsWire::parse( $ns, 3, 'example.com', 1 );
		$this->assertSame( 'ns.example.com', $r['answer'][0]['data'] );
		$mx = $this->head( 1, 1 ) . "\xc0\x0c" . pack( 'nnNn', 15, 1, 60, 9 ) . pack( 'n', 10 ) . "\x04mail\xc0\x0c";
		$this->assertSame( 'mail.example.com', DnsWire::parse( $mx, 3, 'example.com', 1 )['answer'][0]['data']['target'] );
	}

	public function test_bad_pointers_and_names_are_malformed(): void {
		$base = $this->head( 1, 1 );
		$this->assertSame( 'dns_malformed', $this->code( $base . "\xc0" . chr( strlen( $base ) ) . pack( 'nnNn', 1, 1, 60, 4 ) . "\1\2\3\4" ), 'A pointer to itself.' );
		$this->assertSame( 'dns_malformed', $this->code( $base . "\xc0\xff" . pack( 'nnNn', 1, 1, 60, 4 ) . "\1\2\3\4" ), 'A pointer forward.' );
		$this->assertSame( 'dns_malformed', $this->code( $this->head( 1, 1 ) . chr( 64 ) . str_repeat( 'a', 64 ) . "\0" . pack( 'nnNn', 1, 1, 60, 4 ) . "\1\2\3\4" ), 'A label of 64 bytes.' );
		$long = implode( '.', array( str_repeat( 'a', 63 ), str_repeat( 'b', 63 ), str_repeat( 'c', 63 ), str_repeat( 'd', 63 ) ) );
		$this->assertSame( 'dns_malformed', $this->code( $this->head( 1, 1 ) . DnsPackets::name( $long ) . pack( 'nnNn', 1, 1, 60, 4 ) . "\1\2\3\4" ), 'A name over 254 bytes.' );
	}

	public function test_a_chain_of_pointers_has_a_limit(): void {
		// An unknown record (type 99, skipped) carries a chain of pointers in its rdata; the next record's owner points at the end of it.
		$build = function ( int $links ): string {
			$head  = $this->head( 1, 2 );
			$first = strlen( $head ) + 12; // Where the rdata of the first record starts.
			$rdata = '';
			for ( $i = 0; $i < $links; ++$i ) { $rdata .= pack( 'n', 0xc000 | ( 0 === $i ? 12 : $first + 2 * ( $i - 1 ) ) ); }
			$last = $first + 2 * ( $links - 1 );
			return $head . "\xc0\x0c" . pack( 'nnNn', 99, 1, 60, strlen( $rdata ) ) . $rdata . pack( 'n', 0xc000 | $last ) . pack( 'nnNn', 1, 1, 60, 4 ) . "\1\2\3\4";
		};
		$this->assertSame( 'ok', $this->code( $build( 5 ) ), 'A short chain is followed.' );
		$this->assertSame( 'dns_malformed', $this->code( $build( 25 ) ), 'A chain of 25 jumps is refused.' );
	}

	public function test_the_question_count_and_record_counts_are_checked(): void {
		$this->assertSame( 'dns_mismatch', $this->code( pack( 'nnnnnn', 3, 0x8400, 0, 0, 0, 0 ) ), 'No question.' );
		$this->assertSame( 'dns_mismatch', $this->code( $this->head( 2 ) ), 'Two questions.' );
		$this->assertSame( 'dns_malformed', $this->code( $this->head( 1, 2 ) ), 'More answers claimed than present.' );
		$one = $this->head( 1, 1 ) . "\xc0\x0c" . pack( 'nnNn', 1, 1, 60, 4 ) . "\1\2\3\4";
		$this->assertSame( 'ok', $this->code( $one . "\0\0\0" ), 'Bytes after the last record are ignored.' );
	}

	public function test_other_classes_and_opt_records_are_skipped_and_an_empty_txt_string_is_kept(): void {
		$chaos = $this->head( 1, 2 ) . "\xc0\x0c" . pack( 'nnNn', 1, 3, 60, 4 ) . "\1\2\3\4" . "\xc0\x0c" . pack( 'nnNn', 1, 1, 60, 4 ) . "\5\6\7\8";
		$r     = DnsWire::parse( $chaos, 3, 'example.com', 1 );
		$this->assertCount( 1, $r['answer'], 'Class CH is skipped.' );
		$opt = $this->head( 1, 0, 0, 1 ) . "\0" . pack( 'nnNn', 41, 4096, 0, 0 );
		$this->assertSame( array(), DnsWire::parse( $opt, 3, 'example.com', 1 )['additional'], 'The OPT record is skipped.' );
		$txt = $this->head( 1, 1 ) . "\xc0\x0c" . pack( 'nnNn', 16, 1, 60, 3 ) . "\0\1a";
		$this->assertSame( array( '', 'a' ), DnsWire::parse( $txt, 3, 'example.com', 1 )['answer'][0]['data'] );
	}

	public function test_an_uppercase_name_is_asked_as_typed_and_answered_in_lowercase(): void {
		$this->assertStringContainsString( "\x07EXAMPLE\x03COM\x00", DnsWire::query( 'EXAMPLE.COM', 1, 3 ) );
		$packet = DnsPackets::response( 'example.com', 1, array( 'id' => 3, 'answer' => array( array( 'name' => 'WWW.Example.COM', 'type' => 1, 'data' => '192.0.2.1' ) ) ) );
		$this->assertSame( 'www.example.com', DnsWire::parse( $packet, 3, 'EXAMPLE.com', 1 )['answer'][0]['name'] );
	}

	public function test_the_default_query_bytes_are_the_ones_the_proof_resolver_has_always_sent(): void {
		// Captured from the builder before it got options: RD off, one question, one OPT record (1232, DO off).
		$expected = hex2bin( '1234' . '0000' . '0001' . '0000' . '0000' . '0001' . '076578616d706c6503636f6d00' . '0010' . '0001' . '00' . '0029' . '04d0' . '00000000' . '0000' );
		$this->assertSame( $expected, DnsWire::query( 'example.com', DnsWire::TXT, 0x1234 ) );
	}

	public function test_query_options_set_rd_do_and_the_udp_size(): void {
		$wire = DnsWire::query( 'example.com', DnsWire::DNSKEY, 7, array( 'rd' => true, 'do' => true, 'size' => 4096 ) );
		$head = unpack( 'nid/nflags', $wire );
		$this->assertSame( 0x0100, $head['flags'] );
		$opt = unpack( 'ctype_root/ntype/nsize/Nflags/nlength', substr( $wire, -11 ) );
		$this->assertSame( array( 0, 41, 4096, 0x8000, 0 ), array( $opt['type_root'], $opt['type'], $opt['size'], $opt['flags'], $opt['length'] ) );
		$this->assertSame( "\x07example\x03com\x00" . pack( 'nn', 48, 1 ), substr( $wire, 12, 17 ) );
	}

	public function test_dnskey_and_rrsig_records_are_parsed(): void {
		$key = pack( 'nCC', 257, 3, 13 ) . 'KEYBYTES';
		$sig = pack( 'nCCNNNn', 6, 13, 2, 3600, 1800000000, 1700000000, 4242 ) . "\x07example\x03com\x00" . 'SIGNATURE';
		$p   = $this->head( 1, 2 ) . "\xc0\x0c" . pack( 'nnNn', 48, 1, 60, strlen( $key ) ) . $key . "\xc0\x0c" . pack( 'nnNn', 46, 1, 60, strlen( $sig ) ) . $sig;
		$r   = DnsWire::parse( $p, 3, 'example.com', 1 );
		$this->assertSame( array( 'flags' => 257, 'protocol' => 3, 'algorithm' => 13, 'key' => base64_encode( 'KEYBYTES' ) ), $r['answer'][0]['data'] );
		$this->assertSame( array( 'covered' => 6, 'algorithm' => 13, 'labels' => 2, 'original_ttl' => 3600, 'expiration' => 1800000000, 'inception' => 1700000000, 'key_tag' => 4242, 'signer' => 'example.com' ), $r['answer'][1]['data'] );
		$bad = $this->head( 1, 1 ) . "\xc0\x0c" . pack( 'nnNn', 48, 1, 60, 3 ) . 'abc';
		$this->assertSame( 'dns_malformed', $this->code( $bad ), 'A key record under 4 bytes is malformed.' );
	}

	public function test_the_ad_and_ra_bits_and_the_opt_record_are_read(): void {
		$p = pack( 'nnnnnn', 3, 0x8400 | 0x0020 | 0x0080, 1, 0, 0, 1 ) . DnsPackets::name( 'example.com' ) . pack( 'nn', 1, 1 ) . "\0" . pack( 'nnNn', 41, 1400, 0x8000, 0 );
		$r = DnsWire::parse( $p, 3, 'example.com', 1 );
		$this->assertTrue( $r['ad'] );
		$this->assertTrue( $r['ra'] );
		$this->assertSame( array( 'size' => 1400, 'do' => true ), $r['opt'] );
		$plain = DnsWire::parse( $this->head(), 3, 'example.com', 1 );
		$this->assertFalse( $plain['ad'] );
		$this->assertNull( $plain['opt'] );
	}
}

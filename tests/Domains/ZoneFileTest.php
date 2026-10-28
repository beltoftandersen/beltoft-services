<?php
/** BIND zone file parser tests. @package BeltoftServices */
namespace BeltoftServices\Tests\Domains;

use BeltoftServices\Domains\Records;
use BeltoftServices\Domains\ZoneFile;
use BeltoftServices\Tests\TestCase;

class ZoneFileTest extends TestCase {
	private function reasons( array $result ): array {
		return array_column( $result['skipped'], 'reason' );
	}

	public function test_cloudflare_style_export(): void {
		$text = <<<'ZONE'
;; Domain:     example.com.
;; Exported:   2026-10-28 10:00:00

;; SOA Record
example.com.	3600	IN	SOA	ns1.cloudflare.com. dns.cloudflare.com. 2050000000 10000 2400 604800 3600

;; NS Records
example.com.	86400	IN	NS	ns1.cloudflare.com.
example.com.	86400	IN	NS	ns2.cloudflare.com.

;; A Records
example.com.	1	IN	A	192.0.2.10
www.example.com.	300	IN	A	192.0.2.11 ; web

;; AAAA Records
example.com.	300	IN	AAAA	2001:db8::10

;; CNAME Records
blog.example.com.	300	IN	CNAME	example.com.
shop.example.com.	300	IN	CNAME	shops.example.net.

;; MX Records
example.com.	300	IN	MX	10 mail.example.com.

;; TXT Records
example.com.	300	IN	TXT	"v=spf1 include:_spf.example.net ~all"
_beltoft-verify.example.com.	300	IN	TXT	"beltoft-verify=v1:abc"
_dmarc.example.com.	300	IN	TXT	"v=DMARC1; p=none"

;; SRV Records
_sip._tcp.example.com.	300	IN	SRV	10 60 5060 sip.example.com.

;; CAA Records
example.com.	300	IN	CAA	0 issue "letsencrypt.org"

;; Other
example.com.	300	IN	HINFO	"a" "b"
foo.example.net.	300	IN	A	192.0.2.99
ZONE;
		$result = ZoneFile::parse( $text, 'example.com' );

		$this->assertEquals(
			array(
				array( 'name' => '@', 'type' => 'A', 'ttl' => 3600, 'value' => '192.0.2.10' ),
				array( 'name' => 'www', 'type' => 'A', 'ttl' => 300, 'value' => '192.0.2.11' ),
				array( 'name' => '@', 'type' => 'AAAA', 'ttl' => 300, 'value' => '2001:db8::10' ),
				array( 'name' => 'blog', 'type' => 'CNAME', 'ttl' => 300, 'value' => 'example.com' ),
				array( 'name' => 'shop', 'type' => 'CNAME', 'ttl' => 300, 'value' => 'shops.example.net' ),
				array( 'name' => '@', 'type' => 'MX', 'ttl' => 300, 'value' => 'mail.example.com', 'priority' => 10 ),
				array( 'name' => '@', 'type' => 'TXT', 'ttl' => 300, 'value' => 'v=spf1 include:_spf.example.net ~all' ),
				array( 'name' => '_dmarc', 'type' => 'TXT', 'ttl' => 300, 'value' => 'v=DMARC1; p=none' ),
				array( 'name' => '_sip._tcp', 'type' => 'SRV', 'ttl' => 300, 'value' => 'sip.example.com', 'priority' => 10, 'weight' => 60, 'port' => 5060 ),
				array( 'name' => '@', 'type' => 'CAA', 'ttl' => 300, 'value' => 'letsencrypt.org', 'flag' => 0, 'tag' => 'issue' ),
			),
			$result['records']
		);
		$this->assertSame(
			array( 'managed by us', 'replaced by our name servers', 'replaced by our name servers', 'verification record', 'type not supported', 'outside this domain' ),
			$this->reasons( $result )
		);
		$this->assertSame( 'foo.example.net. 300 IN A 192.0.2.99', $result['skipped'][5]['text'] );
		$this->assertNotWPError( Records::validate( 'example.com', $result['records'] ) );
	}

	public function test_one_com_style_export_with_parentheses_origin_and_ttl(): void {
		$text = <<<'ZONE'
$ORIGIN example.com.
$TTL 1h
@	IN	SOA	ns01.one.com. hostmaster.one.com. (
		2026102801 ; serial
		3600       ; refresh
		900        ; retry
		604800     ; expire
		3600 )     ; minimum
	IN	NS	ns01.one.com.
	IN	NS	ns02.one.com.
@	IN	A	192.0.2.5
www	IN	CNAME	@
mail	600	IN	A	192.0.2.6
	IN	TXT	"v=spf1 a ~all"
@	IN	MX	10	mail
@	IN	MX	20	mx2.example.net.
ftp.dev	IN	A	192.0.2.7
a.b.c	IN	A	192.0.2.8
google._domainkey	IN	TXT	( "v=DKIM1; k=rsa; "
	"p=MIIBIjANBgkq" "hkiG9w0BAQEF" )
$ORIGIN sub.example.com.
host	IN	A	192.0.2.9
ZONE;
		$result = ZoneFile::parse( $text, 'example.com' );
		$rows   = $result['records'];

		$this->assertSame( array( 'managed by us', 'replaced by our name servers', 'replaced by our name servers' ), $this->reasons( $result ) );
		$this->assertEquals( array( 'name' => '@', 'type' => 'A', 'ttl' => 3600, 'value' => '192.0.2.5' ), $rows[0] );
		$this->assertEquals( array( 'name' => 'www', 'type' => 'CNAME', 'ttl' => 3600, 'value' => 'example.com' ), $rows[1] );
		$this->assertEquals( array( 'name' => 'mail', 'type' => 'A', 'ttl' => 600, 'value' => '192.0.2.6' ), $rows[2] );
		$this->assertEquals( array( 'name' => 'mail', 'type' => 'TXT', 'ttl' => 3600, 'value' => 'v=spf1 a ~all' ), $rows[3], 'a blank owner is the previous owner' );
		$this->assertSame( 'mail.example.com', $rows[4]['value'] );
		$this->assertSame( 'mx2.example.net', $rows[5]['value'] );
		$this->assertSame( 'ftp.dev', $rows[6]['name'] );
		$this->assertSame( 'a.b.c.example.com', $rows[7]['name'], 'a name with more than one label stays absolute for the validator' );
		$this->assertSame( 'v=DKIM1; k=rsa; p=MIIBIjANBgkqhkiG9w0BAQEF', $rows[8]['value'] );
		$this->assertSame( 'host.sub', $rows[9]['name'] );
		$this->assertNotWPError( Records::validate( 'example.com', $rows ) );
	}

	public function test_quoted_strings_escapes_and_comments(): void {
		$text   = "@ IN TXT \"say \\\"hi\\\" ; not a comment\" \"back\\\\slash\" \"A\\066C\" ; real comment\n";
		$result = ZoneFile::parse( $text, 'example.com' );

		$this->assertSame( 'say "hi" ; not a comment' . 'back\\slash' . 'ABC', $result['records'][0]['value'] );
		$this->assertSame( array(), $result['skipped'] );
	}

	public function test_ttl_and_class_in_either_order_and_units(): void {
		$result = ZoneFile::parse( "a IN 2h A 192.0.2.1\nb 1d IN A 192.0.2.2\nc A 192.0.2.3\n", 'example.com' );

		$this->assertSame( array( 7200, 86400, 3600 ), array_column( $result['records'], 'ttl' ) );
	}

	public function test_lines_that_do_not_parse_are_skipped_with_the_line_number(): void {
		$result = ZoneFile::parse( "a IN A 192.0.2.1\nb IN MX notanumber mail\n\$INCLUDE other.zone\nc IN A\nd IN A 192.0.2.4\n", 'example.com' );

		$this->assertCount( 2, $result['records'] );
		$this->assertSame( array( 2, 3, 4 ), array_column( $result['skipped'], 'line' ) );
		$this->assertSame( array( 'could not read this line', 'could not read this line', 'could not read this line' ), $this->reasons( $result ) );
	}

	public function test_skipped_text_is_cut_at_120_characters(): void {
		$result = ZoneFile::parse( 'x IN HINFO ' . str_repeat( 'y', 300 ) . "\n", 'example.com' );

		$this->assertSame( 120, strlen( $result['skipped'][0]['text'] ) );
	}

	public function test_too_large_input_is_refused(): void {
		$big = ZoneFile::parse( str_repeat( 'a', 102401 ), 'example.com' );
		$this->assertWPError( $big );
		$this->assertSame( 'zone_file_too_large', $big->get_error_code() );

		$many = ZoneFile::parse( str_repeat( "a IN A 192.0.2.1\n", 501 ), 'example.com' );
		$this->assertWPError( $many );
		$this->assertSame( 'zone_file_too_large', $many->get_error_code() );

		$this->assertIsArray( ZoneFile::parse( str_repeat( "a IN A 192.0.2.1\n", 500 ), 'example.com' ) );
	}

	public function test_a_parenthesis_group_counts_as_one_line(): void {
		$text = str_repeat( "a IN TXT ( \"x\"\n \"y\"\n \"z\" )\n", 400 );

		$this->assertIsArray( ZoneFile::parse( $text, 'example.com' ) );
	}

	public function test_at_most_200_records_are_kept(): void {
		$text   = '';
		for ( $i = 1; $i <= 230; $i++ ) {
			$text .= "h{$i} IN A 192.0.2.1\n";
		}
		$result = ZoneFile::parse( $text, 'example.com' );

		$this->assertCount( 200, $result['records'] );
		$this->assertCount( 30, $result['skipped'] );
		$this->assertSame( 'too many records', $result['skipped'][0]['reason'] );
		$this->assertSame( 201, $result['skipped'][0]['line'] );
	}

	public function test_cloudflare_auto_ttl_becomes_one_hour(): void {
		$parsed = \BeltoftServices\Domains\ZoneFile::parse( "app.example.com.\t1\tIN\tA\t192.0.2.1\nb.example.com.\t30\tIN\tA\t192.0.2.2", 'example.com' );
		$this->assertSame( 3600, $parsed['records'][0]['ttl'] );
		$this->assertSame( 60, $parsed['records'][1]['ttl'] );
	}
}

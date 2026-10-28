<?php
/** Customer DNS record validation. @package BeltoftServices */
namespace BeltoftServices\Tests\Domains;

use BeltoftServices\Domains\Records;
use BeltoftServices\Tests\TestCase;

class RecordsTest extends TestCase {
	public function test_valid_a_record_becomes_powerdns_rrset(): void {
		$this->assertSame( array( array( 'name' => 'www.example.com.', 'type' => 'A', 'ttl' => 300, 'changetype' => 'REPLACE', 'records' => array( array( 'content' => '192.0.2.1', 'disabled' => false ) ) ) ), Records::validate( 'example.com', array( array( 'name' => 'www', 'type' => 'A', 'value' => '192.0.2.1', 'ttl' => 300 ) ) ) );
	}

	public function test_long_txt_value_is_written_as_several_strings_and_round_trips(): void {
		$value = 'v=DKIM1; k=rsa; p=' . substr( str_repeat( 'MIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8A', 30 ), 0, 582 );
		$this->assertSame( 600, strlen( $value ) );
		$sets = Records::validate( 'example.com', array( array( 'name' => 'k1._domainkey', 'type' => 'TXT', 'value' => $value ) ) );
		$content = $sets[0]['records'][0]['content'];
		$this->assertSame( 3, preg_match_all( '/"[^"]*"/', $content, $m ) );
		foreach ( $m[0] as $string ) { $this->assertLessThanOrEqual( 255, strlen( $string ) - 2 ); }
		$this->assertSame( 1, preg_match( '/^"[^"]{255}" "[^"]{255}" "[^"]{90}"$/', $content ) );
		$rows = Records::to_rows( 'example.com', array( array( 'name' => 'k1._domainkey.example.com.', 'type' => 'TXT', 'ttl' => 3600, 'records' => array( array( 'content' => $content ) ) ) ) );
		$this->assertSame( $value, $rows[0]['value'] );
		$this->assertIsArray( Records::validate( 'example.com', array( array( 'name' => '@', 'type' => 'TXT', 'value' => str_repeat( 'x', 2048 ) ) ) ) );
	}

	public function test_a_multibyte_character_on_the_255_byte_boundary_is_never_cut(): void {
		$value = str_repeat( 'a', 254 ) . 'é' . str_repeat( 'b', 5 ) . '€' . str_repeat( 'c', 300 );
		$sets  = Records::validate( 'example.com', array( array( 'name' => '@', 'type' => 'TXT', 'value' => $value ) ) );
		$content = $sets[0]['records'][0]['content'];
		preg_match_all( '/"((?:[^"\\\\]|\\\\.)*)"/', $content, $m );
		foreach ( $m[1] as $chunk ) {
			$raw = stripcslashes( $chunk );
			$this->assertLessThanOrEqual( 255, strlen( $raw ) );
			$this->assertSame( 1, preg_match( '//u', $raw ) );
		}
		$rows = Records::to_rows( 'example.com', array( array( 'name' => 'example.com.', 'type' => 'TXT', 'records' => array( array( 'content' => $content ) ) ) ) );
		$this->assertSame( $value, $rows[0]['value'] );
	}

	public function test_a_quote_inside_a_long_txt_value_stays_escaped_per_string(): void {
		$value = str_repeat( 'a', 254 ) . '"' . str_repeat( 'b', 10 );
		$sets = Records::validate( 'example.com', array( array( 'name' => '@', 'type' => 'TXT', 'value' => $value ) ) );
		$rows = Records::to_rows( 'example.com', array( array( 'name' => 'example.com.', 'type' => 'TXT', 'records' => array( array( 'content' => $sets[0]['records'][0]['content'] ) ) ) ) );
		$this->assertSame( $value, $rows[0]['value'] );
	}

	public function test_invalid_names_and_addresses_are_refused(): void {
		foreach ( array(
			array( 'name' => 'outside.example.net.', 'type' => 'A', 'value' => '192.0.2.1' ),
			array( 'name' => 'www', 'type' => 'A', 'value' => 'not-an-ip' ),
			array( 'name' => '@', 'type' => 'CNAME', 'value' => 'target.example.net' ),
			array( 'name' => 'www', 'type' => 'TXT', 'value' => str_repeat( 'x', 2049 ) ),
			array( 'name' => '@', 'type' => 'NS', 'value' => 'ns1.example.net' ),
		) as $record ) {
			$this->assertWPError( Records::validate( 'example.com', array( $record ) ) );
		}
	}

	public function test_free_text_cannot_carry_control_characters_and_names_have_a_length_limit(): void {
		$this->assertWPError( Records::validate( 'example.com', array( array( 'name' => '@', 'type' => 'TXT', 'value' => "line\nbreak" ) ) ) );
		$this->assertWPError( Records::validate( 'example.com', array( array( 'name' => '@', 'type' => 'CAA', 'flag' => 0, 'tag' => 'issue', 'value' => "c\x00a" ) ) ) );
		$long = implode( '.', array_fill( 0, 40, 'abcdefg' ) ) . '.example.com.';
		$this->assertWPError( Records::validate( 'example.com', array( array( 'name' => $long, 'type' => 'A', 'value' => '192.0.2.1' ) ) ) );
		$this->assertIsArray( Records::validate( 'example.com', array( array( 'name' => '@', 'type' => 'TXT', 'value' => 'v=spf1 -all' ) ) ) );
	}

	public function test_more_than_200_records_are_refused(): void {
		$records = array_fill( 0, 201, array( 'name' => 'www', 'type' => 'A', 'value' => '192.0.2.1' ) );
		$this->assertWPError( Records::validate( 'example.com', $records ) );
	}

	public function test_cname_cannot_share_name(): void {
		$this->assertWPError( Records::validate( 'example.com', array( array( 'name' => 'www', 'type' => 'CNAME', 'value' => 'target.example.net' ), array( 'name' => 'www', 'type' => 'A', 'value' => '192.0.2.1' ) ) ) );
	}
}

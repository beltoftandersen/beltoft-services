<?php
/** Editor rows built from zone records. @package BeltoftServices */
namespace BeltoftServices\Tests\Domains;

use BeltoftServices\Domains\Records;
use BeltoftServices\Tests\TestCase;

class RecordsEditorRowsTest extends TestCase {
	private function sets(): array {
		$set = static fn( $name, $type, $ttl, array $contents ) => array( 'name' => $name, 'type' => $type, 'ttl' => $ttl, 'records' => array_map( static fn( $c ) => array( 'content' => $c, 'disabled' => false ), $contents ) );
		return array(
			$set( 'example.com.', 'SOA', 3600, array( 'ns1.example.net. hostmaster.example.net. 1 1 1 1 1' ) ),
			$set( 'example.com.', 'NS', 3600, array( 'ns1.example.net.', 'ns2.example.net.' ) ),
			$set( 'example.com.', 'A', 300, array( '192.0.2.10', '192.0.2.11' ) ),
			$set( 'www.example.com.', 'CNAME', 3600, array( 'example.com.' ) ),
			$set( 'example.com.', 'MX', 3600, array( '10 mail.example.com.' ) ),
			$set( 'example.com.', 'TXT', 3600, array( '"v=spf1 include:\\"x\\" -all"' ) ),
			$set( '_sip._tcp.example.com.', 'SRV', 3600, array( '10 5 5060 sip.example.com.' ) ),
			$set( 'example.com.', 'CAA', 3600, array( '0 issue "letsencrypt.org"' ) ),
			$set( 'a.b.c.example.com.', 'A', 3600, array( '192.0.2.20' ) ),
		);
	}

	public function test_rows_skip_our_ns_and_soa_and_use_editor_fields(): void {
		$rows = Records::to_rows( 'example.com', $this->sets() );
		$this->assertCount( 8, $rows, 'Two A records, CNAME, MX, TXT, SRV, CAA and the deep name; no NS or SOA.' );
		$this->assertSame( array( 'name' => '@', 'type' => 'A', 'ttl' => 300, 'value' => '192.0.2.10', 'disabled' => false ), $rows[0] );
		$this->assertSame( 'www', $rows[2]['name'] );
		$this->assertSame( array( 10, 'mail.example.com' ), array( $rows[3]['priority'], $rows[3]['value'] ) );
		$this->assertSame( 'v=spf1 include:"x" -all', $rows[4]['value'] );
		$this->assertSame( array( 10, 5, 5060, 'sip.example.com' ), array( $rows[5]['priority'], $rows[5]['weight'], $rows[5]['port'], $rows[5]['value'] ) );
		$this->assertSame( array( 0, 'issue', 'letsencrypt.org' ), array( $rows[6]['flag'], $rows[6]['tag'], $rows[6]['value'] ) );
	}

	public function test_rows_saved_unchanged_give_back_the_same_records(): void {
		$sets = array_values( array_filter( $this->sets(), static fn( $s ) => ! in_array( $s['type'], array( 'NS', 'SOA' ), true ) ) );
		$validated = Records::validate( 'example.com', Records::to_rows( 'example.com', $sets ) );
		$this->assertIsArray( $validated, is_wp_error( $validated ) ? $validated->get_error_code() : '' );
		$key = static fn( $s ) => $s['name'] . '|' . $s['type'] . '|' . $s['ttl'] . '|' . implode( ',', array_column( $s['records'], 'content' ) );
		$expected = array_map( $key, $sets );
		sort( $expected );
		$actual = array_map( $key, $validated );
		sort( $actual );
		$this->assertSame( $expected, $actual );
	}
}

<?php
/** Customer DNS record validation. @package BeltoftServices */
namespace BeltoftServices\Tests\Domains;

use BeltoftServices\Domains\Records;
use BeltoftServices\Tests\TestCase;

class RecordsTest extends TestCase {
	public function test_valid_a_record_becomes_powerdns_rrset(): void {
		$this->assertSame( array( array( 'name' => 'www.example.com.', 'type' => 'A', 'ttl' => 300, 'changetype' => 'REPLACE', 'records' => array( array( 'content' => '192.0.2.1', 'disabled' => false ) ) ) ), Records::validate( 'example.com', array( array( 'name' => 'www', 'type' => 'A', 'value' => '192.0.2.1', 'ttl' => 300 ) ) ) );
	}

	public function test_invalid_names_and_addresses_are_refused(): void {
		foreach ( array(
			array( 'name' => 'outside.example.net.', 'type' => 'A', 'value' => '192.0.2.1' ),
			array( 'name' => 'www', 'type' => 'A', 'value' => 'not-an-ip' ),
			array( 'name' => '@', 'type' => 'CNAME', 'value' => 'target.example.net' ),
			array( 'name' => 'www', 'type' => 'TXT', 'value' => str_repeat( 'x', 256 ) ),
			array( 'name' => '@', 'type' => 'NS', 'value' => 'ns1.example.net' ),
		) as $record ) {
			$this->assertWPError( Records::validate( 'example.com', array( $record ) ) );
		}
	}

	public function test_more_than_200_records_are_refused(): void {
		$records = array_fill( 0, 201, array( 'name' => 'www', 'type' => 'A', 'value' => '192.0.2.1' ) );
		$this->assertWPError( Records::validate( 'example.com', $records ) );
	}

	public function test_cname_cannot_share_name(): void {
		$this->assertWPError( Records::validate( 'example.com', array( array( 'name' => 'www', 'type' => 'CNAME', 'value' => 'target.example.net' ), array( 'name' => 'www', 'type' => 'A', 'value' => '192.0.2.1' ) ) ) );
	}
}

<?php
/** Fake DNS cross-request persistence. @package BeltoftServices */
namespace BeltoftServices\Tests\Domains;

use BeltoftServices\Domains\FakeDns;
use BeltoftServices\Tests\TestCase;

class FakeDnsTest extends TestCase {
	public function test_persisted_zone_survives_a_new_client_and_applies_rrset_patch(): void {
		delete_option( FakeDns::OPTION );
		$first = new FakeDns( true );
		$this->assertTrue( $first->create_zone( 'persist-example.net', array( 'ns1.example.net', 'ns2.example.net' ) ) );
		$second = new FakeDns( true );
		$this->assertNotNull( $second->zone( 'persist-example.net' ) );
		$set = array( 'name' => 'www.persist-example.net.', 'type' => 'A', 'ttl' => 3600, 'changetype' => 'REPLACE', 'records' => array( array( 'content' => '192.0.2.1', 'disabled' => false ) ) );
		$this->assertTrue( $second->replace_rrsets( 'persist-example.net', array( $set ) ) );
		$this->assertCount( 1, ( new FakeDns( true ) )->zone( 'persist-example.net' )['rrsets'] );
		$this->assertTrue( $second->replace_rrsets( 'persist-example.net', array( array( 'name' => $set['name'], 'type' => 'A', 'changetype' => 'DELETE' ) ) ) );
		$this->assertSame( array(), ( new FakeDns( true ) )->zone( 'persist-example.net' )['rrsets'] );
	}
}

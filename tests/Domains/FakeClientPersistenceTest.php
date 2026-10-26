<?php
/** Persistent fake registrar staging flow. @package BeltoftServices */
namespace BeltoftServices\Tests\Domains;

use BeltoftServices\Domains\FakeClient;
use BeltoftServices\Tests\TestCase;

class FakeClientPersistenceTest extends TestCase {
	public function test_register_and_renew_survive_new_client_instances(): void {
		delete_option( FakeClient::OPTION );
		$client = new FakeClient( true );
		$body = array( 'domain' => array( 'name' => 'fake-persist-example', 'extension' => 'com' ), 'owner_handle' => 'FAKEOWNER-XX' );
		$created = $client->request( 'POST', '/domains', $body );
		$this->assertIsArray( $created );
		$id = (int) $created['id'];
		$second = new FakeClient( true );
		$this->assertSame( $id, $second->request( 'GET', '/domains', array(), array( 'full_name' => 'fake-persist-example.com' ) )['results'][0]['id'] );
		$this->assertSame( 'taken', $second->request( 'POST', '/domains/check', array( 'domains' => array( $body['domain'] ) ) )['results'][0]['status'] );
		$second->request( 'POST', '/domains/' . $id . '/renew', array( 'period' => 1 ) );
		$this->assertGreaterThan( strtotime( $created['expiration_date'] ), strtotime( ( new FakeClient( true ) )->request( 'GET', '/domains/' . $id )['expiration_date'] ) );
	}
}

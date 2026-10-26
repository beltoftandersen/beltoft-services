<?php
/** Daily registrar sync tests. @package BeltoftServices */
namespace BeltoftServices\Tests\Domains;

use BeltoftServices\Domains\DomainDriver;
use BeltoftServices\Domains\FakeClient;
use BeltoftServices\Domains\Sync;
use BeltoftServices\Resource;
use BeltoftServices\Tests\TestCase;

class SyncTest extends TestCase {
	public function test_sync_stores_registrar_status_and_expiry(): void {
		$product = $this->service_product( 'domain' );
		$license = $this->service_license( $product, array( 'fire' => false ) );
		$r = Resource::create( $license, new DomainDriver(), array(), array( 'state' => 'ready', 'domain' => 'sync-example.net', 'remote_id' => '902', 'expires_at' => '2027-10-26 00:00:00' ) );
		$this->assertInstanceOf( Resource::class, $r );
		$op = new FakeClient();
		$op->reply( 'GET', '/domains/902', array( 'status' => 'ACT', 'expiration_date' => '2027-10-26 00:00:00', 'is_locked' => true ) );
		$op->reply( 'GET', '/resellers', array( 'balance' => 100, 'currency' => 'EUR' ) );
		add_filter( 'beltoft_services_domains_client', static fn() => $op );
		Sync::run();
		$r = Resource::find( $r->id() );
		$this->assertSame( 'ACT', $r->get( 'registrar_status' ) );
		$this->assertTrue( $r->get( 'is_locked' ) );
		$this->assertGreaterThan( 0, $r->get( 'last_sync' ) );
	}
}

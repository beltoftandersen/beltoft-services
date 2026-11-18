<?php
/**
 * The storage driver with the fake client: provision, idempotent create, pause and resume, resize, delete, alias.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Storage;

use BeltoftServices\Storage\HostAlias;
use BeltoftServices\Crypto;
use BeltoftServices\Domains\Clients;
use BeltoftServices\Domains\FakeDns;
use BeltoftServices\Resource;
use BeltoftServices\Storage\Boxes;
use BeltoftServices\Storage\FakeStorageClient;
use BeltoftServices\Storage\StorageDriver;

require_once __DIR__ . '/StorageTestCase.php';

final class StorageDriverTest extends StorageTestCase {

	public function test_provision_builds_a_protected_box_with_access_and_snapshots(): void {
		$r = $this->ready_storage();
		$box = FakeStorageClient::state()['boxes'][ Boxes::id( $r ) ];
		$this->assertSame( 'active', $box['status'] );
		$this->assertTrue( $box['protected'], 'Delete protection is on.' );
		$this->assertTrue( $box['access']['ssh_enabled'] );
		$this->assertTrue( $box['access']['reachable_externally'] );
		$this->assertFalse( $box['access']['samba_enabled'] );
		$this->assertSame( 'day', $box['snapshot_plan']['every'] );
		$this->assertSame( 'fsn1', $box['location'], 'Germany only.' );
		$this->assertSame( $box['username'], $r->get( 'storage_username' ) );
		$this->assertStringStartsWith( 'bs1:', (string) $r->get( 'storage_password' ), 'The password is kept encrypted.' );
		$this->assertTrue( $this->client->password_is( Boxes::id( $r ), (string) Boxes::password( $r ) ) );
		$this->assertContains( 'snapshots', (array) $r->get( 'steps' ) );
	}

	public function test_provision_is_idempotent_by_our_box_name(): void {
		$r = $this->storage_resource();
		$first = ( new StorageDriver() )->provision( $r );
		$this->assertTrue( $first->is_retry() && $first->is_poll(), 'A new box is waited for.' );
		// The job result was lost: nothing was stored on the resource.
		$second = ( new StorageDriver() )->provision( Resource::find( $r->id() ) );
		$this->assertSame( 1, FakeStorageClient::state()['creates'], 'The box was found by its name, not bought again.' );
		$this->assertCount( 1, FakeStorageClient::state()['boxes'] );
		$this->assertTrue( $second->is_done() || $second->is_retry() );
	}

	public function test_a_lost_create_response_is_recovered_by_name(): void {
		$r = $this->storage_resource();
		$name = \BeltoftServices\Storage\Provision::box_name( $r );
		$this->client->create_box( $name, 'bx11', 'fsn1', 'A-long-password-1!', array(), array() );
		$result = $this->build( $r );
		$this->assertTrue( $result->is_done(), $result->error() );
		$this->assertSame( 1, FakeStorageClient::state()['creates'] );
	}

	public function test_nothing_is_built_when_selling_is_switched_off(): void {
		$this->settings( array( 'storage_enabled' => 0 ) );
		$r = $this->storage_resource();
		$result = ( new StorageDriver() )->provision( $r );
		$this->assertTrue( $result->is_retry() );
		$this->assertSame( 0, FakeStorageClient::state()['creates'] );
	}

	public function test_pause_turns_access_off_and_resume_restores_the_same_switches(): void {
		$r = $this->ready_storage();
		$id = Boxes::id( $r );
		$this->client->set_access( $id, array( 'samba_enabled' => true ) );
		$paused = ( new StorageDriver() )->pause( $r );
		$this->assertTrue( $paused->is_done() );
		$r->set( $paused->meta() );
		$access = FakeStorageClient::state()['boxes'][ $id ]['access'];
		$this->assertFalse( $access['reachable_externally'] );
		$this->assertFalse( $access['ssh_enabled'] );
		$this->assertFalse( $access['samba_enabled'] );
		$this->assertGreaterThan( 0, (int) Resource::find( $r->id() )->get( 'storage_locked_at' ) );
		$this->assertArrayHasKey( $id, FakeStorageClient::state()['boxes'], 'Data stays.' );
		$resumed = ( new StorageDriver() )->resume( Resource::find( $r->id() ) );
		$this->assertTrue( $resumed->is_done() );
		$access = FakeStorageClient::state()['boxes'][ $id ]['access'];
		$this->assertTrue( $access['reachable_externally'] );
		$this->assertTrue( $access['ssh_enabled'] );
		$this->assertTrue( $access['samba_enabled'], 'The switch the customer had is back.' );
	}

	public function test_a_larger_type_is_applied_and_a_smaller_one_is_not(): void {
		$r = $this->ready_storage();
		$id = Boxes::id( $r );
		$r->change_plan( array( 'storage_type' => 'bx21', 'storage_size_gb' => 5000 ), 'test' );
		// The licence's product is the cheap one: the price does not cover bx21, so the guard keeps the box as it is.
		$refused = ( new StorageDriver() )->update_plan( Resource::find( $r->id() ) );
		$this->assertTrue( $refused->is_done() );
		$this->assertSame( 'bx11', FakeStorageClient::state()['boxes'][ $id ]['type'] );
		$this->settings( array( 'storage_cost_table' => "bx11=3.20\nbx21=5.00" ) );
		$done = ( new StorageDriver() )->update_plan( Resource::find( $r->id() ) );
		$this->assertTrue( $done->is_done() );
		$this->assertSame( 'bx21', FakeStorageClient::state()['boxes'][ $id ]['type'] );
		$this->assertSame( 5000000000000, FakeStorageClient::state()['boxes'][ $id ]['size_bytes'] );
		Resource::find( $r->id() )->set( $done->meta() );
		$r2 = Resource::find( $r->id() );
		$r2->change_plan( array( 'storage_type' => 'bx11', 'storage_size_gb' => 1000 ), 'test' );
		( new StorageDriver() )->update_plan( Resource::find( $r->id() ) );
		$this->assertSame( 'bx21', FakeStorageClient::state()['boxes'][ $id ]['type'], 'No downgrade.' );
		$this->assertTrue( $this->client->change_type( $id, 'bx11' ) instanceof \WP_Error, 'The fake refuses a smaller type too.' );
	}

	public function test_delete_is_off_by_default_and_changes_nothing(): void {
		$r = $this->ready_storage();
		$result = ( new StorageDriver() )->delete( $r );
		$this->assertTrue( $result->is_retry() );
		$this->assertArrayHasKey( Boxes::id( $r ), FakeStorageClient::state()['boxes'] );
		$this->assertTrue( FakeStorageClient::state()['boxes'][ Boxes::id( $r ) ]['protected'] );
	}

	public function test_delete_lifts_the_lock_first_when_allowed(): void {
		$r = $this->ready_storage();
		$this->settings( array( 'storage_deletes_enabled' => 1 ) );
		$id = Boxes::id( $r );
		$this->assertTrue( $this->client->delete_box( $id ) instanceof \WP_Error, 'Locked: the provider refuses.' );
		$result = ( new StorageDriver() )->delete( $r );
		$this->assertTrue( $result->is_done() );
		$this->assertArrayNotHasKey( $id, FakeStorageClient::state()['boxes'] );
		$this->assertSame( '', $result->meta()['storage_password'] );
	}

	public function test_the_default_retention_is_fourteen_days(): void {
		$r = $this->storage_resource();
		$product = (int) $r->license()['product_id'];
		$this->assertSame( 14, \BeltoftServices\Product::retention_days( $product ) );
	}

	public function test_the_alias_is_off_by_default_and_the_upstream_host_is_shown(): void {
		$r = $this->ready_storage();
		$this->assertSame( '', (string) $r->get( 'storage_alias' ) );
		$c = Boxes::connection( $r );
		$this->assertSame( (string) $r->get( 'storage_host' ), $c['host'] );
	}

	public function test_the_alias_is_a_cname_in_the_fake_dns_when_switched_on(): void {
		$dns = new FakeDns( true );
		$dns->create_zone( 'example.test', array( 'ns1.example.test' ) );
		add_filter( 'beltoft_services_domains_dns', static fn() => $dns );
		$this->settings( array( 'storage_alias_enabled' => 1, 'storage_alias_writer' => 'own', 'storage_alias_zone' => 'example.test', 'storage_alias_pattern' => 'backup{n}.{zone}' ) );
		$r = $this->ready_storage();
		$alias = (string) $r->get( 'storage_alias' );
		$this->assertSame( 'backup' . HostAlias::number( $r ) . '.example.test', $alias );
		$set = null;
		foreach ( (array) $dns->zone( 'example.test' )['rrsets'] as $rr ) {
			if ( 'CNAME' === $rr['type'] ) { $set = $rr; }
		}
		$this->assertNotNull( $set );
		$this->assertSame( (string) $r->get( 'storage_host' ), rtrim( $set['records'][0]['content'], '.' ) );
		$c = Boxes::connection( $r );
		$this->assertSame( $alias, $c['host'] );
		$this->assertSame( (string) $r->get( 'storage_host' ), $c['tls_host'], 'SMB and WebDAV get the upstream host.' );
		remove_all_filters( 'beltoft_services_domains_dns' );
	}

	public function test_a_zone_that_is_not_ours_keeps_the_box_on_the_upstream_host(): void {
		$this->settings( array( 'storage_alias_enabled' => 1, 'storage_alias_writer' => 'own', 'storage_alias_zone' => 'missing.test' ) );
		$r = $this->ready_storage();
		$this->assertSame( '', (string) $r->get( 'storage_alias' ) );
		$this->assertSame( (string) $r->get( 'storage_host' ), Boxes::connection( $r )['host'] );
	}
}

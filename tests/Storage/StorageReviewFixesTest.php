<?php
/**
 * Fixes from the review of 2026-11-17: a foreign CNAME is never repointed, a box that is already gone is a finished delete,
 * and the purchase is made under one lock with the box stored before the lock is let go.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Storage;

use BeltoftServices\Locks;
use BeltoftServices\Resource;
use BeltoftServices\Storage\Boxes;
use BeltoftServices\Storage\FakeAliasDns;
use BeltoftServices\Storage\FakeStorageClient;
use BeltoftServices\Storage\HostAlias;
use BeltoftServices\Storage\StorageDriver;

require_once __DIR__ . '/StorageTestCase.php';

final class StorageReviewFixesTest extends StorageTestCase {

	public function set_up(): void {
		parent::set_up();
		$this->settings( array( 'storage_alias_enabled' => 1, 'storage_alias_writer' => 'cloudflare', 'storage_alias_zone' => 'example.test', 'storage_alias_pattern' => 'backup{n}.{zone}' ) );
	}

	public function test_a_foreign_cname_at_the_alias_name_is_not_repointed(): void {
		$r = $this->storage_resource();
		FakeAliasDns::seed( 'backup' . $r->id() . '.example.test', 'CNAME', 'manual.example.net' );
		$this->assertSame( '', HostAlias::publish( $r, 'u1.host.test' ) );
		$rows = array_values( FakeAliasDns::records() );
		$this->assertCount( 1, $rows );
		$this->assertSame( 'manual.example.net', $rows[0]['content'], 'The record is as it was.' );
		$this->assertSame( '', (string) Resource::find( $r->id() )->get( 'storage_alias_record' ) );
	}

	public function test_our_own_record_is_found_by_its_stored_id_and_updated(): void {
		$r = $this->ready_storage();
		$this->assertNotSame( '', (string) $r->get( 'storage_alias_record' ), 'The id of the record we made is kept.' );
		HostAlias::publish( Resource::find( $r->id() ), 'moved.host.test' );
		$this->assertSame( 'moved.host.test', array_values( FakeAliasDns::records() )[0]['content'] );
		// The same record id with another resource's id on it is not ours.
		$other = $this->storage_resource();
		FakeAliasDns::seed( 'backup' . $other->id() . '.example.test', 'CNAME', 'old.host.test' );
		$other->set( array( 'storage_alias_record' => 'r999' ) );
		$this->assertSame( '', HostAlias::publish( Resource::find( $other->id() ), 'new.host.test' ) );
	}

	public function test_a_box_that_is_already_gone_is_a_finished_delete(): void {
		$r  = $this->ready_storage();
		$id = Boxes::id( $r );
		$this->settings( array( 'storage_deletes_enabled' => 1 ) );
		$this->client->set_protection( $id, false );
		$this->client->delete_box( $id );
		$result = ( new StorageDriver() )->delete( Resource::find( $r->id() ) );
		$this->assertTrue( $result->is_done(), $result->error() );
		$this->assertSame( '', $result->meta()['storage_box_id'] );
		$this->assertSame( array(), FakeAliasDns::records(), 'The alias record was cleaned up too.' );
	}

	public function test_a_not_found_answer_of_the_live_client_counts_as_gone(): void {
		$this->assertTrue( Boxes::not_found( new \WP_Error( 'storage_http_404', 'x', array( 'status' => 404 ) ) ) );
		$this->assertTrue( Boxes::not_found( new \WP_Error( 'storage_not_found', 'x' ) ) );
		$this->assertFalse( Boxes::not_found( new \WP_Error( 'storage_http_500', 'x', array( 'status' => 500 ) ) ) );
	}

	public function test_the_box_is_stored_inside_the_purchase_lock_and_the_lock_is_let_go(): void {
		$r = $this->storage_resource();
		( new StorageDriver() )->provision( $r );
		// The caller never stored the result, yet the box counts for a parallel provision.
		$this->assertNotSame( '', Boxes::id( Resource::find( $r->id() ) ) );
		$this->assertSame( 1, FakeStorageClient::state()['creates'] );
		$this->assertTrue( Locks::acquire( Locks::name( 'storage-spend', 0 ), 0 ) );
		Locks::release( Locks::name( 'storage-spend', 0 ) );
	}

	public function test_a_provision_that_cannot_take_the_purchase_lock_buys_nothing(): void {
		$name = Locks::name( 'storage-spend', 0 );
		$db   = new \mysqli( DB_HOST, DB_USER, DB_PASSWORD, DB_NAME );
		$db->query( "SELECT GET_LOCK('" . $db->real_escape_string( $name ) . "', 0)" );
		$this->assertTrue( false !== $db->query( 'SELECT 1' ) );
		$r      = $this->storage_resource();
		$result = ( new StorageDriver() )->provision( $r );
		$db->query( "SELECT RELEASE_LOCK('" . $db->real_escape_string( $name ) . "')" );
		$db->close();
		$this->assertTrue( $result->is_retry() );
		$this->assertSame( 0, FakeStorageClient::state()['creates'] );
	}
}

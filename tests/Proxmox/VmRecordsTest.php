<?php
/**
 * VM records and VMID allocation.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Proxmox;

use BeltoftServices\Locks;
use BeltoftServices\Proxmox\Clusters;
use BeltoftServices\Proxmox\Schema;
use BeltoftServices\Proxmox\VmRecords;
use BeltoftServices\Tests\TestCase;

/**
 * Unique VMIDs per cluster. Nothing here reaches a Proxmox host: the "is it free" check is a closure.
 */
class VmRecordsTest extends TestCase {

	public function set_up(): void {
		parent::set_up();

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- test fixture reset.
		$wpdb->query( 'DELETE FROM ' . Schema::tables()['vms'] );

		$key = base64_encode( random_bytes( 32 ) );
		add_filter( 'beltoft_services_key', fn() => $key );
		delete_option( Clusters::OPTION );
	}

	public function tear_down(): void {
		remove_all_filters( 'beltoft_services_key' );
		delete_option( Clusters::OPTION );

		parent::tear_down();
	}

	private function free(): callable {
		return fn( int $id ): bool => true;
	}

	public function test_medusa_vmid_race_gives_distinct_ids(): void {
		global $wpdb;

		// Two provisions see the same list of ids in use on the cluster.
		$taken = array( 5000 );
		$a     = VmRecords::allocate( 1, 'lab', 5000, 5099, $taken, $this->free() );
		$b     = VmRecords::allocate( 2, 'lab', 5000, 5099, $taken, $this->free() );

		$this->assertSame( 5001, $a );
		$this->assertSame( 5002, $b );

		// A duplicate insert is refused by the unique key, whatever the code above does.
		$suppress = $wpdb->suppress_errors( true );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- test.
		$dup = $wpdb->insert(
			Schema::tables()['vms'],
			array(
				'resource_id' => 3,
				'cluster'     => 'lab',
				'vmid'        => 5001,
				'created_at'  => gmdate( 'Y-m-d H:i:s' ),
			)
		);
		$wpdb->suppress_errors( $suppress );
		$this->assertFalse( $dup );
	}

	public function test_allocation_is_idempotent_per_resource(): void {
		$calls = 0;
		$free  = function () use ( &$calls ) {
			++$calls;
			return true;
		};

		$this->assertSame( 5000, VmRecords::allocate( 1, 'lab', 5000, 5099, array(), $free ) );
		$this->assertSame( 5000, VmRecords::allocate( 1, 'lab', 5000, 5099, array( 5000 ), $free ), 'the resource keeps its id, even when the cluster now lists it' );
		$this->assertSame( 1, $calls );
		$this->assertSame( '1', (string) $this->lock_free() );
	}

	private function lock_free(): string {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- test read.
		return (string) $wpdb->get_var( $wpdb->prepare( 'SELECT IS_FREE_LOCK( %s )', Locks::name( 'vmid', 0 ) ) );
	}

	public function test_allocation_skips_ids_in_use_on_the_cluster(): void {
		$this->assertSame( 5003, VmRecords::allocate( 1, 'lab', 5000, 5099, array( 5000, 5001, 5002, 4999, 6000 ), $this->free() ) );
	}

	public function test_the_same_vmid_on_another_cluster_is_fine(): void {
		$this->assertSame( 5000, VmRecords::allocate( 1, 'lab', 5000, 5099, array(), $this->free() ) );
		$this->assertSame( 5000, VmRecords::allocate( 2, 'other', 5000, 5099, array(), $this->free() ) );
		$this->assertSame( 5001, VmRecords::allocate( 3, 'lab', 5000, 5099, array(), $this->free() ) );
	}

	public function test_allocation_skips_an_id_the_nextid_assertion_refuses(): void {
		$asked = array();
		$free  = function ( int $id ) use ( &$asked ) {
			$asked[] = $id;
			return 5000 !== $id;
		};

		$this->assertSame( 5001, VmRecords::allocate( 1, 'lab', 5000, 5099, array(), $free ) );
		$this->assertSame( array( 5000, 5001 ), $asked );
		$this->assertNull( VmRecords::for_resource( 99 ) );
		$this->assertFalse( VmRecords::owned( 'lab', 5000, 1 ), 'the refused id was not kept' );
		$this->assertTrue( VmRecords::owned( 'lab', 5001, 1 ) );
		$this->assertSame( 1, $this->rows(), 'no leftover row for the refused id' );

		// The refused id is tried again by the next resource (it may be free by then): asked once more.
		$asked = array();
		$this->assertSame( 5000, VmRecords::allocate( 2, 'lab', 5000, 5099, array(), fn( int $id ) => true ) );
	}

	private function rows(): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- test read.
		return (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Schema::tables()['vms'] );
	}

	public function test_a_refused_id_is_not_asked_twice_in_one_run(): void {
		$asked = array();
		$free  = function ( int $id ) use ( &$asked ) {
			$asked[] = $id;
			return $id >= 5003;
		};

		$this->assertSame( 5003, VmRecords::allocate( 1, 'lab', 5000, 5099, array(), $free ) );
		$this->assertSame( array( 5000, 5001, 5002, 5003 ), $asked );
	}

	public function test_the_nextid_assertion_is_attempted_at_most_fifty_times(): void {
		$asked = 0;
		$free  = function () use ( &$asked ) {
			++$asked;
			return false;
		};

		$this->assertNull( VmRecords::allocate( 1, 'lab', 5000, 5999, array(), $free ) );
		$this->assertSame( 50, $asked );
		$this->assertSame( 0, $this->rows() );
	}

	public function test_pool_exhaustion_returns_null(): void {
		$this->assertSame( 5000, VmRecords::allocate( 1, 'lab', 5000, 5001, array(), $this->free() ) );
		$this->assertSame( 5001, VmRecords::allocate( 2, 'lab', 5000, 5001, array(), $this->free() ) );
		$this->assertNull( VmRecords::allocate( 3, 'lab', 5000, 5001, array(), $this->free() ) );
		$this->assertNull( VmRecords::allocate( 4, 'lab', 5000, 5003, array( 5002, 5003 ), $this->free() ), 'range used up by the cluster list' );
		$this->assertNull( VmRecords::allocate( 5, 'lab', 5010, 5000, array(), $this->free() ), 'an inverted range' );
		$this->assertNull( VmRecords::allocate( 6, 'lab', 0, 10, array(), $this->free() ), 'ids below 1 are not VMIDs' );
	}

	public function test_a_throwing_assertion_releases_the_lock_and_keeps_no_phantom(): void {
		try {
			VmRecords::allocate( 1, 'lab', 5000, 5099, array(), function () {
				throw new \RuntimeException( 'cluster unreachable' );
			} );
			$this->fail( 'the error must reach the caller' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'cluster unreachable', $e->getMessage() );
		}

		$this->assertSame( '1', $this->lock_free() );
		// The recorded id stays: a retry gets the same one and asserts it again.
		$this->assertSame( 5000, VmRecords::allocate( 1, 'lab', 5000, 5099, array(), $this->free() ) );
	}

	public function test_records_are_read_moved_and_removed(): void {
		VmRecords::allocate( 7, 'lab', 5000, 5099, array(), $this->free() );

		$row = VmRecords::for_resource( 7 );
		$this->assertSame( 7, $row['resource_id'] );
		$this->assertSame( 'lab', $row['cluster'] );
		$this->assertSame( 5000, $row['vmid'] );
		$this->assertSame( '', $row['node'] );
		$this->assertNull( $row['ip_id'] );

		$this->assertTrue( VmRecords::move( 7, 'node-a' ) );
		$this->assertSame( 'node-a', VmRecords::for_resource( 7 )['node'] );
		$this->assertFalse( VmRecords::move( 8, 'node-a' ), 'no record for that resource' );

		$this->assertTrue( VmRecords::set_ip( 7, 12 ) );
		$this->assertSame( 12, VmRecords::for_resource( 7 )['ip_id'] );

		$this->assertTrue( VmRecords::owned( 'lab', 5000, 7 ) );
		$this->assertFalse( VmRecords::owned( 'lab', 5000, 8 ), 'another resource does not own it' );
		$this->assertFalse( VmRecords::owned( 'other', 5000, 7 ), 'nor does it on another cluster' );
		$this->assertFalse( VmRecords::owned( 'lab', 5001, 7 ) );

		$this->assertTrue( VmRecords::remove( 7 ) );
		$this->assertFalse( VmRecords::remove( 7 ) );
		$this->assertNull( VmRecords::for_resource( 7 ) );
		$this->assertSame( 5000, VmRecords::allocate( 8, 'lab', 5000, 5099, array(), $this->free() ), 'a removed id can be reused' );
	}

	public function test_a_cluster_with_records_cannot_be_deleted(): void {
		$input = array(
			'label'             => 'Lab cluster',
			'api_url'           => 'https://pve.example.invalid:8006',
			'token_id'          => 'svc@pve!beltoft',
			'token_secret'      => 'secret-secret-secret-1',
			'client'            => 'fake',
			'pool_api'          => 'pools',
			'nodes'             => 'node-a',
			'storage'           => 'disk-store',
			'cloudinit_storage' => 'ci-store',
			'bridge'            => 'vmbr9',
			'boot_disk'         => 'scsi0',
			'gateway'           => '192.0.2.1',
			'nameservers'       => '192.0.2.53',
			'prefix'            => '24',
			'vmid_min'          => '5000',
			'vmid_max'          => '5099',
			'templates'         => '9000 = Debian 12',
		);
		$this->assertTrue( Clusters::save( 'lab', $input ) );
		$this->assertTrue( Clusters::save( 'idle', $input ) );

		VmRecords::allocate( 1, 'lab', 5000, 5099, array(), $this->free() );

		$this->assertSame( 1, Clusters::in_use( 'lab' ) );
		$this->assertSame( 0, Clusters::in_use( 'idle' ) );
		$this->assertFalse( Clusters::delete( 'lab' ) );
		$this->assertNotNull( Clusters::get( 'lab' ) );
		$this->assertTrue( Clusters::delete( 'idle' ) );

		VmRecords::remove( 1 );
		$this->assertTrue( Clusters::delete( 'lab' ) );
	}
}

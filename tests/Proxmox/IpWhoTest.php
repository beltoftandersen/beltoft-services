<?php
/**
 * The abuse lookup: who holds or held an address.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Proxmox;

use BeltoftServices\Cli\Commands;
use BeltoftServices\Product;
use BeltoftServices\Proxmox\IpHistory;
use BeltoftServices\Proxmox\IpPool;
use BeltoftServices\Proxmox\IpWho;
use BeltoftServices\Proxmox\PrivateNets;
use BeltoftServices\Proxmox\Schema;
use BeltoftServices\Resource;
use BeltoftServices\Tests\Support\FakeDriver;
use BeltoftServices\Tests\TestCase;

require_once dirname( __DIR__ ) . '/Support/WpCliStub.php';

/**
 * Documentation addresses only; read-only lookups.
 */
class IpWhoTest extends TestCase {

	public function set_up(): void {
		parent::set_up();

		\WP_CLI::reset();

		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- test fixture reset.
		foreach ( array( 'ips', 'private_ips', 'private_nets', 'ip_log' ) as $table ) {
			$wpdb->query( 'DELETE FROM ' . Schema::tables()[ $table ] );
		}
		// phpcs:enable
		delete_transient( IpPool::FREE_TRANSIENT );
	}

	/**
	 * A resource whose license belongs to a new customer.
	 *
	 * @return array{0: Resource, 1: int} The resource and the customer id.
	 */
	private function resource(): array {
		$user_id = self::factory()->user->create(
			array(
				'display_name' => 'Ada Customer',
				'user_email'   => 'christian.beltoft+' . wp_generate_password( 6, false ) . '@gmail.com',
			)
		);
		$product = $this->service_product( 'fake' );
		$license = $this->service_license( $product, array( 'user_id' => $user_id, 'fire' => false ) );
		$result  = Resource::create( $license, new FakeDriver(), Product::plan( $product->get_id() ) );
		$this->assertInstanceOf( Resource::class, $result );

		return array( $result, $user_id );
	}

	/**
	 * Moves the log rows of an address in time.
	 *
	 * @param string  $address Address.
	 * @param string  $start   Start (UTC).
	 * @param ?string $end     End (UTC) or null.
	 */
	private function backdate( string $address, string $start, ?string $end ): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- test fixture.
		$wpdb->update( Schema::tables()['ip_log'], array( 'started_at' => $start, 'ended_at' => $end ), array( 'address' => $address ) );
	}

	public function test_a_public_address_names_its_holder(): void {
		IpPool::add( '192.0.2.10' );
		list( $resource, $user_id ) = $this->resource();
		IpPool::hold( $resource->id() );
		IpPool::assign( $resource->id() );

		$rows = IpWho::lookup( '192.0.2.10' );

		$this->assertCount( 1, $rows );
		$this->assertSame( 'public', $rows[0]['kind'] );
		$this->assertSame( (string) $resource->id(), $rows[0]['resource_id'] );
		$this->assertSame( (string) $resource->license()['id'], $rows[0]['license_id'] );
		$this->assertSame( (string) $user_id, $rows[0]['customer_id'] );
		$this->assertSame( 'Ada Customer', $rows[0]['name'] );
		$this->assertSame( get_userdata( $user_id )->user_email, $rows[0]['email'] );
		$this->assertNotSame( '', $rows[0]['product'] );
		$this->assertSame( (string) $resource->get( 'state' ), $rows[0]['state'] );
		$this->assertSame( '', $rows[0]['until'], 'still held' );
		$this->assertSame( '', $rows[0]['note'] );
	}

	public function test_a_private_address_names_its_holder(): void {
		PrivateNets::add( 1000, '10.64.0.0/28' );
		list( $resource, $user_id ) = $this->resource();
		PrivateNets::hold( $user_id );
		$address = PrivateNets::address( $user_id, $resource->id() );

		$rows = IpWho::lookup( $address );

		$this->assertCount( 1, $rows );
		$this->assertSame( 'private', $rows[0]['kind'] );
		$this->assertSame( (string) $user_id, $rows[0]['customer_id'] );
		$this->assertSame( '', $rows[0]['until'] );
	}

	public function test_history_answers_who_held_it_at_a_time(): void {
		IpPool::add( '192.0.2.20' );
		list( $one ) = $this->resource();
		list( $two ) = $this->resource();

		IpPool::hold( $one->id() );
		IpPool::release( $one->id() );
		$this->backdate( '192.0.2.20', '2026-10-01 08:00:00', '2026-10-10 08:00:00' );

		IpPool::hold( $two->id() );
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- test fixture: the second holding starts when the first ended.
		$wpdb->update( Schema::tables()['ip_log'], array( 'started_at' => '2026-10-10 08:00:00' ), array( 'resource_id' => $two->id() ) );

		$then = IpWho::lookup( '192.0.2.20', strtotime( '2026-10-05 12:00:00 UTC' ) );
		$this->assertCount( 1, $then );
		$this->assertSame( (string) $one->id(), $then[0]['resource_id'] );
		$this->assertSame( '2026-10-10 08:00:00', $then[0]['until'] );

		$later = IpWho::lookup( '192.0.2.20', strtotime( '2026-10-20 00:00:00 UTC' ) );
		$this->assertCount( 1, $later );
		$this->assertSame( (string) $two->id(), $later[0]['resource_id'] );

		$this->assertSame( array(), IpWho::lookup( '192.0.2.20', strtotime( '2026-09-01 00:00:00 UTC' ) ), 'before anyone held it' );
		$this->assertCount( 2, IpWho::lookup( '192.0.2.20' ), 'without --at: every holding' );
	}

	public function test_release_and_private_release_close_the_log(): void {
		IpPool::add( '192.0.2.30' );
		list( $resource, $user_id ) = $this->resource();
		IpPool::hold( $resource->id() );
		$this->assertNull( IpHistory::for_address( '192.0.2.30' )[0]['ended_at'] );
		IpPool::release( $resource->id() );
		$this->assertNotNull( IpHistory::for_address( '192.0.2.30' )[0]['ended_at'] );
		$this->assertSame( array(), IpWho::lookup( '192.0.2.30', time() + 60 ) );

		PrivateNets::add( 1000, '10.64.0.0/28' );
		PrivateNets::hold( $user_id );
		$address = PrivateNets::address( $user_id, $resource->id() );
		PrivateNets::release_and_retire( $resource->id() );
		$this->assertNotNull( IpHistory::for_address( $address )[0]['ended_at'] );
	}

	public function test_take_logs_the_adopted_address(): void {
		IpPool::add( '192.0.2.40' );
		list( $resource ) = $this->resource();
		$this->assertTrue( IpPool::take( '192.0.2.40', $resource->id() ) );

		$this->assertCount( 1, IpHistory::for_address( '192.0.2.40' ) );
	}

	public function test_an_address_held_before_the_log_is_shown_from_the_activation(): void {
		IpPool::add( '192.0.2.50' );
		list( $resource ) = $this->resource();
		IpPool::hold( $resource->id() );
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- test fixture: forget the log.
		$wpdb->query( 'DELETE FROM ' . Schema::tables()['ip_log'] );

		$rows = IpWho::lookup( '192.0.2.50', time() + 60 );

		$this->assertCount( 1, $rows );
		$this->assertStringContainsString( 'before holdings were logged', $rows[0]['note'] );
		$this->assertSame( array(), IpWho::lookup( '192.0.2.50', 1000 ), 'before the resource existed' );
	}

	public function test_it_is_read_only_and_unknown_addresses_are_empty(): void {
		IpPool::add( '192.0.2.60' );
		$before = IpPool::rows();

		$this->assertSame( array(), IpWho::lookup( '192.0.2.60' ) );
		$this->assertSame( array(), IpWho::lookup( '203.0.113.9' ) );
		$this->assertSame( $before, IpPool::rows() );
		$this->assertSame( array(), IpHistory::for_address( '192.0.2.60' ) );
	}

	public function test_the_command_prints_a_table_and_json(): void {
		IpPool::add( '192.0.2.70' );
		list( $resource ) = $this->resource();
		IpPool::hold( $resource->id() );
		$cli = new Commands();

		$cli->who( array( '192.0.2.70' ), array() );
		$this->assertStringContainsString( 'christian.beltoft+', \WP_CLI::text() );

		\WP_CLI::reset();
		$cli->who( array( '192.0.2.70' ), array( 'format' => 'json' ) );
		$decoded = json_decode( \WP_CLI::$lines[0][1], true );
		$this->assertSame( 'Ada Customer', $decoded[0]['name'] );

		\WP_CLI::reset();
		$cli->who( array( '192.0.2.70' ), array( 'at' => '2000-01-01 00:00', 'format' => 'json' ) );
		$this->assertSame( '[]', \WP_CLI::$lines[0][1] );

		\WP_CLI::reset();
		$cli->who( array( '192.0.2.70' ), array( 'at' => '2100-01-01 00:00', 'format' => 'json' ) );
		$this->assertNotSame( '[]', \WP_CLI::$lines[0][1] );
	}

	public function test_the_command_refuses_bad_input(): void {
		$cli = new Commands();

		try {
			$cli->who( array( 'not-an-ip' ), array() );
			$this->fail( 'A bad address is refused.' );
		} catch ( \RuntimeException $e ) {
			$this->assertStringContainsString( 'IPv4', $e->getMessage() );
		}

		try {
			$cli->who( array( '192.0.2.1' ), array( 'at' => 'no idea' ) );
			$this->fail( 'A bad date is refused.' );
		} catch ( \RuntimeException $e ) {
			$this->assertStringContainsString( '--at', $e->getMessage() );
		}
	}
}

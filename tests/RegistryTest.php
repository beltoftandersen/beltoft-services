<?php
/**
 * Driver registry and step results.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests;

use BeltoftServices\Drivers\Registry;
use BeltoftServices\Drivers\StepResult;
use BeltoftServices\Http\HttpDriver;

/**
 * Registry and StepResult.
 */
class RegistryTest extends TestCase {

	public function test_registry_uses_the_filter(): void {
		$this->assertSame( array( 'http', 'proxmox', 'proxmox-db', 'proxmox-disk', 'proxmox-snapshots', 'proxmox-app', 'domain', 'dns', 'mail', 'ssl', 'licence-key', 'hosting' ), array_keys( Registry::all() ) );
		$this->assertInstanceOf( HttpDriver::class, Registry::get( 'http' ) );
		$this->assertSame( 'vm', Registry::get( 'proxmox' )->kind() );
		$this->assertSame( 'disk', Registry::get( 'proxmox-disk' )->kind() );

		$fake = $this->fake_driver();

		$this->assertSame( array( 'http', 'proxmox', 'proxmox-db', 'proxmox-disk', 'proxmox-snapshots', 'proxmox-app', 'domain', 'dns', 'mail', 'ssl', 'licence-key', 'hosting', 'fake' ), array_keys( Registry::all() ) );
		$this->assertSame( $fake, Registry::get( 'fake' ) );
		$this->assertSame( array( 'http', 'vm', 'db', 'disk', 'snapshot', 'applic', 'domain', 'dns', 'mail', 'ssl', 'lickey', 'hosting', 'fake' ), Registry::kinds() );
	}

	/**
	 * The shipped drivers, in the order the admin screen and the jobs iterate them, whatever the request type.
	 * The ids and classes are pinned: a driver missing here strands a paying customer's service.
	 */
	public function test_the_shipped_drivers_are_registered_in_todays_order(): void {
		$golden = array(
			'http'              => \BeltoftServices\Http\HttpDriver::class,
			'proxmox'           => \BeltoftServices\Proxmox\Vm\ProxmoxDriver::class,
			'proxmox-db'        => \BeltoftServices\Proxmox\DbDriver::class,
			'proxmox-disk'      => \BeltoftServices\Proxmox\Disk\DiskDriver::class,
			'proxmox-snapshots' => \BeltoftServices\Proxmox\SnapshotDriver::class,
			'proxmox-app'       => \BeltoftServices\Proxmox\AppLicenceDriver::class,
			'domain'            => \BeltoftServices\Domains\DomainDriver::class,
			'dns'               => \BeltoftServices\Domains\DnsDriver::class,
			'mail'              => \BeltoftServices\Mail\MailDriver::class,
			'ssl'               => \BeltoftServices\Ssl\SslDriver::class,
			'licence-key'       => \BeltoftServices\Licences\KeyDriver::class,
			'hosting'           => \BeltoftServices\Hosting\HostingDriver::class,
		);

		$actual = array();
		foreach ( Registry::all() as $id => $driver ) {
			$actual[ $id ] = get_class( $driver );
		}
		$this->assertSame( $golden, $actual );

		// A plugin adding a driver late, at the default priority, still comes after the shipped ones.
		$this->fake_driver();
		$this->assertSame( 'fake', array_key_last( Registry::all() ) );
	}

	/**
	 * Core names no driver: with every callback gone the registry is empty.
	 */
	public function test_the_registry_is_built_from_the_filter_alone(): void {
		remove_all_filters( 'beltoft_services_drivers' );

		$this->assertSame( array(), Registry::all() );
	}

	public function test_unknown_driver_is_null(): void {
		$this->fake_driver();

		$this->assertNull( Registry::get( 'nope' ) );
		$this->assertNull( Registry::get( '' ) );
	}

	public function test_step_results(): void {
		$done = StepResult::done( array( 'a' => 1 ), 'Created' );
		$this->assertTrue( $done->is_done() );
		$this->assertFalse( $done->is_retry() );
		$this->assertFalse( $done->is_failed() );
		$this->assertSame( array( 'a' => 1 ), $done->meta() );
		$this->assertSame( 'Created', $done->event() );
		$this->assertSame( '', $done->error() );

		$plain = StepResult::done();
		$this->assertSame( array(), $plain->meta() );
		$this->assertSame( '', $plain->event() );

		$retry = StepResult::retry( 60, 'busy', array( 'step' => 'x' ) );
		$this->assertTrue( $retry->is_retry() );
		$this->assertFalse( $retry->is_done() );
		$this->assertSame( 60, $retry->delay() );
		$this->assertSame( 'busy', $retry->error() );
		$this->assertSame( array( 'step' => 'x' ), $retry->meta() );

		$fail = StepResult::fail( 'broken', array( 'z' => true ) );
		$this->assertTrue( $fail->is_failed() );
		$this->assertFalse( $fail->is_retry() );
		$this->assertSame( 'broken', $fail->error() );
		$this->assertSame( array( 'z' => true ), $fail->meta() );
		$this->assertSame( 0, $fail->delay() );
	}
}

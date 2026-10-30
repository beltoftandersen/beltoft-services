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
		$this->assertSame( array( 'http', 'proxmox', 'proxmox-db', 'proxmox-disk', 'proxmox-snapshots', 'proxmox-app', 'domain', 'dns' ), array_keys( Registry::all() ) );
		$this->assertInstanceOf( HttpDriver::class, Registry::get( 'http' ) );
		$this->assertSame( 'vm', Registry::get( 'proxmox' )->kind() );
		$this->assertSame( 'disk', Registry::get( 'proxmox-disk' )->kind() );

		$fake = $this->fake_driver();

		$this->assertSame( array( 'http', 'proxmox', 'proxmox-db', 'proxmox-disk', 'proxmox-snapshots', 'proxmox-app', 'domain', 'dns', 'fake' ), array_keys( Registry::all() ) );
		$this->assertSame( $fake, Registry::get( 'fake' ) );
		$this->assertSame( array( 'http', 'vm', 'db', 'disk', 'snapshot', 'applic', 'domain', 'dns', 'fake' ), Registry::kinds() );
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

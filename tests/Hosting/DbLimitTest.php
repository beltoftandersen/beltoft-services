<?php
/**
 * The database limit the plugin sends to the node agent (db.limit): the agent's safety net against a full database server.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Hosting;

require_once __DIR__ . '/HostingCase.php';

use BeltoftServices\Hosting\HostingUsage;
use BeltoftServices\Resource;

/**
 * HostingUsage::push_limit() and the hourly job.
 */
class DbLimitTest extends HostingCase {

	private function account( int $disk_gb = 10 ): Resource {
		$r = $this->resource( array( 'disk_gb' => $disk_gb ) );
		$this->provision_fully( $r );
		$r->set( array( 'state' => 'ready', 'desired' => 'ready' ) );

		return Resource::find( $r->id() );
	}

	public function test_provisioning_sends_the_limit_of_the_plan_times_the_hard_percent(): void {
		$r     = $this->account();
		$calls = $this->agent->calls_of( 'db.limit' );

		$this->assertCount( 1, $calls );
		$this->assertSame( 'c' . $r->id(), $calls[0]['args']['account'] );
		$this->assertSame( 10 * 1024 * HostingUsage::HARD_PERCENT / 100, $calls[0]['args']['limit_mb'] );
	}

	public function test_the_hourly_job_sends_it_once_per_change(): void {
		$r = $this->account();
		$n = count( $this->agent->calls_of( 'db.limit' ) );

		HostingUsage::push_limit( $r );
		( new HostingUsage( Resource::find( $r->id() ) ) )->sample();
		$this->assertCount( $n, $this->agent->calls_of( 'db.limit' ), 'Already sent: not again.' );

		$r->set( array( 'plan' => array_merge( (array) $r->get( 'plan' ), array( 'disk_gb' => 20 ) ) ) );
		( new HostingUsage( Resource::find( $r->id() ) ) )->sample();
		$calls = $this->agent->calls_of( 'db.limit' );
		$this->assertCount( $n + 1, $calls );
		$this->assertSame( 20 * 1024 * 150 / 100, end( $calls )['args']['limit_mb'] );

		( new HostingUsage( Resource::find( $r->id() ) ) )->sample();
		$this->assertCount( $n + 1, $this->agent->calls_of( 'db.limit' ) );
	}

	public function test_a_plan_change_through_the_driver_sends_the_new_limit(): void {
		$r = $this->account();
		$r->set( array( 'plan' => array_merge( (array) $r->get( 'plan' ), array( 'disk_gb' => 20 ) ) ) );

		$this->assertTrue( $this->driver->update_plan( Resource::find( $r->id() ) )->is_done() );
		$calls = $this->agent->calls_of( 'db.limit' );
		$this->assertSame( 30720, end( $calls )['args']['limit_mb'] );
	}

	public function test_an_older_agent_without_the_operation_breaks_nothing_and_is_asked_again_next_hour(): void {
		$r = $this->resource( array( 'disk_gb' => 10 ) );
		$this->agent->set_unsupported( array( 'db.limit', 'db.sizes' ) );
		$this->provision_fully( $r );
		$r->set( array( 'state' => 'ready', 'desired' => 'ready' ) );
		$r = Resource::find( $r->id() );

		$this->assertNull( $r->get( 'db_limit_sent' ) );

		( new HostingUsage( $r ) )->sample();
		$this->assertNotEmpty( (array) Resource::find( $r->id() )->get( 'usage' ), 'The rest of the usage job ran.' );
		$this->assertTrue( $this->driver->update_plan( Resource::find( $r->id() ) )->is_done(), 'A plan change is not held up.' );

		$this->agent->set_unsupported( array() );
		( new HostingUsage( Resource::find( $r->id() ) ) )->sample();
		$this->assertSame( 15360, Resource::find( $r->id() )->get( 'db_limit_sent' ) );
	}
}

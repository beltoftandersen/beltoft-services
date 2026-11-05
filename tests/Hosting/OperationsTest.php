<?php
/**
 * Pause, resume, plan change and delete of a hosting account, against the fakes.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Hosting;

require_once __DIR__ . '/HostingCase.php';

use BeltoftServices\Hosting\Records;
use BeltoftServices\Hosting\Sites;
use BeltoftServices\Resource;

/**
 * The operations after provisioning.
 */
class OperationsTest extends HostingCase {

	/**
	 * A provisioned account.
	 */
	private function ready(): Resource {
		$r = $this->resource();
		$this->provision_fully( $r );

		return Resource::find( $r->id() );
	}

	public function test_pause_suspends_the_edge_then_stops_the_container_and_keeps_the_data(): void {
		$r = $this->ready();

		$result = $this->driver->pause( $r );

		$this->assertTrue( $result->is_done(), $result->error() );
		$this->assertSame( 'stopped', $this->ct( $r )['status'] );

		$routes = $this->agent->state()['edge'][ 'c' . $r->id() ];
		$this->assertCount( 1, $routes );
		$this->assertTrue( $routes[0]['suspended'] );

		$this->assertNotEmpty( $this->agent->state()['dbs'], 'The database is kept.' );
		$this->assertNotNull( Sites::find( $r->id(), 'w' . $r->id() ) );
		$this->assertNotNull( Records::for_resource( $r->id() ) );
	}

	public function test_pausing_twice_is_safe(): void {
		$r = $this->ready();
		$this->assertTrue( $this->driver->pause( $r )->is_done() );
		$stops = count( $this->node->calls_to( '#/status/(shutdown|stop)$#' ) );

		$this->assertTrue( $this->driver->pause( $r )->is_done() );

		$this->assertSame( $stops, count( $this->node->calls_to( '#/status/(shutdown|stop)$#' ) ), 'A stopped container gets no second stop.' );
	}

	public function test_resume_starts_the_container_and_routes_the_names_again(): void {
		$r = $this->ready();
		$this->driver->pause( $r );

		$result = $this->driver->resume( Resource::find( $r->id() ) );

		$this->assertTrue( $result->is_done(), $result->error() );
		$this->assertSame( 'running', $this->ct( $r )['status'] );
		$this->assertFalse( $this->agent->state()['edge'][ 'c' . $r->id() ][0]['suspended'] );
	}

	public function test_pause_resume_pause_runs_each_edge_job_anew(): void {
		$r = $this->ready();
		$this->driver->pause( $r );
		$this->driver->resume( Resource::find( $r->id() ) );
		$this->driver->pause( Resource::find( $r->id() ) );

		$this->assertTrue( $this->agent->state()['edge'][ 'c' . $r->id() ][0]['suspended'] );

		$ids = array_column( $this->agent->calls_of( 'edge.apply' ), 'job_id' );
		$this->assertSame( count( $ids ), count( array_unique( $ids ) ), 'No job id is used twice.' );
	}

	public function test_resume_of_an_unfinished_provision_continues_provisioning(): void {
		$this->agent->pending( 'ct.prepare', 2 );
		$r = $this->resource();
		$this->assertTrue( $this->provision( $r )->is_poll() );
		$this->assertNotContains( 'ready', Resource::find( $r->id() )->get( 'steps' ) );

		$result = $this->driver->resume( Resource::find( $r->id() ) );
		for ( $i = 0; $i < 12 && $result->is_retry(); $i++ ) {
			$result = $this->driver->resume( Resource::find( $r->id() ) );
		}

		$this->assertTrue( $result->is_done(), $result->error() );
		$this->assertContains( 'ready', Resource::find( $r->id() )->get( 'steps' ) );
	}

	public function test_pause_and_delete_of_an_account_that_was_never_built_do_nothing(): void {
		$r = $this->resource();

		$this->assertTrue( $this->driver->pause( $r )->is_done() );
		$this->assertTrue( $this->driver->delete( $r )->is_done() );
		$this->assertCount( 0, $this->node->calls() );
		$this->assertCount( 0, $this->agent->calls() );
	}

	public function test_delete_removes_everything_it_made(): void {
		$r       = $this->ready();
		$account = 'c' . $r->id();
		$vmid    = Records::for_resource( $r->id() )['vmid'];

		$result = $this->driver->delete( $r );

		$this->assertTrue( $result->is_done(), $result->error() );
		$this->assertArrayNotHasKey( $vmid, $this->node->state()['cts'] );
		$this->assertArrayNotHasKey( $account, $this->agent->state()['edge'] );
		$this->assertArrayNotHasKey( $account, $this->agent->state()['dbs'] );
		$this->assertNull( Records::for_resource( $r->id() ) );
		$this->assertSame( array(), Sites::for_resource( $r->id() ) );

		$destroy = $this->node->calls_to( '#/lxc/\d+$#', 'DELETE' );
		$this->assertCount( 1, $destroy );
		$this->assertSame( 1, $destroy[0]['params']['purge'] );

		// The address, port and id are free for the next account.
		$next = $this->resource();
		$this->provision_fully( $next );
		$this->assertSame( $vmid, Records::for_resource( $next->id() )['vmid'] );
		$this->assertSame( 22001, Records::for_resource( $next->id() )['ssh_port'] );
	}

	public function test_delete_stops_a_running_container_first_and_can_run_again(): void {
		$r = $this->ready();
		$this->assertSame( 'running', $this->ct( $r )['status'] );

		$this->assertTrue( $this->driver->delete( $r )->is_done() );

		$again = $this->driver->delete( Resource::find( $r->id() ) );
		$this->assertTrue( $again->is_done(), 'A second delete finds nothing left.' );
		$this->assertCount( 1, $this->node->calls_to( '#/lxc/\d+$#', 'DELETE' ) );
	}

	public function test_delete_resumes_after_a_failed_destroy_task_without_repeating_the_agent_jobs(): void {
		$r = $this->ready();
		$this->node->next_task( array() ); // The shutdown works.
		$this->node->next_task( array( 'exitstatus' => 'storage busy' ) ); // The destroy fails.

		$first = $this->driver->delete( $r );
		$this->assertTrue( $first->is_retry(), 'First: ' . $first->error() );
		$this->assertStringContainsString( 'still there', $first->error() );

		$edge_jobs = count( $this->agent->calls_of( 'edge.apply' ) );
		$result    = $first;
		for ( $i = 0; $i < 6 && ! $result->is_done(); $i++ ) {
			$result = $this->driver->delete( Resource::find( $r->id() ) );
		}

		$this->assertTrue( $result->is_done(), $result->error() );
		$this->assertSame( $edge_jobs, count( $this->agent->calls_of( 'edge.apply' ) ), 'Finished steps are not run again.' );
	}

	public function test_delete_refuses_a_container_that_does_not_carry_our_tag(): void {
		$r      = $this->ready();
		$record = Records::for_resource( $r->id() );

		// Another container at the recorded id, without the tag line (the fake overwrites by id).
		$this->node->add_ct(
			array(
				'vmid'   => $record['vmid'],
				'node'   => 'pve1',
				'name'   => 'imposter',
				'status' => 'stopped',
			)
		);

		$result = $this->driver->delete( $r );

		$this->assertTrue( $result->is_failed() );
		$this->assertStringContainsString( 'Ownership check failed', $result->error() );
		$this->assertCount( 0, $this->node->calls_to( '#/lxc/\d+$#', 'DELETE' ), 'Nothing was destroyed.' );
		$this->assertArrayHasKey( $record['vmid'], $this->node->state()['cts'] );
	}

	public function test_a_listing_error_is_a_retry_and_keeps_the_records(): void {
		$r = $this->ready();

		$this->node->fail_next( 'GET', '#/cluster/resources$#', array( 'status' => 500, 'message' => 'hidden' ) );
		$result = $this->driver->delete( $r );

		$this->assertTrue( $result->is_retry() );
		$this->assertNotNull( Records::for_resource( $r->id() ) );
		$this->assertArrayHasKey( 1000, $this->node->state()['cts'] );
	}

	public function test_update_plan_applies_limits_and_grows_the_disk_but_never_shrinks_it(): void {
		$r = $this->ready();

		$r->set( array( 'plan' => array_merge( (array) $r->get( 'plan' ), array( 'cores' => 4, 'memory_mb' => 8192, 'disk_gb' => 20 ) ) ) );
		$result = $this->driver->update_plan( Resource::find( $r->id() ) );

		$this->assertTrue( $result->is_done(), $result->error() );
		$c = $this->ct( $r )['config'];
		$this->assertSame( 4, $c['cores'] );
		$this->assertSame( 8192, $c['memory'] );
		$this->assertStringContainsString( 'size=20G', $c['rootfs'] );
		$this->assertStringContainsString( 'size=5G', $c['mp0'], 'The dump mount is a quarter of the disk and grows with it.' );

		$r2 = Resource::find( $r->id() );
		$r2->set( array( 'plan' => array_merge( (array) $r2->get( 'plan' ), array( 'disk_gb' => 5 ) ) ) );
		$this->assertTrue( $this->driver->update_plan( Resource::find( $r->id() ) )->is_done() );
		$this->assertStringContainsString( 'size=20G', $this->ct( $r )['config']['rootfs'] );
		$this->assertStringContainsString( 'size=5G', $this->ct( $r )['config']['mp0'] );
	}

	public function test_status_comes_from_the_records_without_a_call(): void {
		$r      = $this->ready();
		$before = count( $this->node->calls() );

		$status = $this->driver->status( $r );

		$this->assertSame( 'n1', $status['node'] );
		$this->assertSame( 1000, $status['vmid'] );
		$this->assertSame( 1, $status['sites'] );
		$this->assertSame( $before, count( $this->node->calls() ) );
		$this->assertNull( $this->driver->status( $this->resource() ) );
	}
}

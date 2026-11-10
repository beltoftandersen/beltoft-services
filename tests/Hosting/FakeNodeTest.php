<?php
/**
 * The fake hosting node: lxc routes, parameter checks, tasks.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Hosting;

use BeltoftServices\Hosting\FakeNode;
use BeltoftServices\Tests\TestCase;

/**
 * FakeNode on its own.
 */
class FakeNodeTest extends TestCase {

	/**
	 * A node with a template, a pool and one running container.
	 */
	private function node(): FakeNode {
		$n = new FakeNode();
		$n->add_node( 'pve1' );
		$n->add_pool( 'p1' );
		$n->add_template( 9000, 'pve1' );
		$n->add_ct(
			array(
				'vmid'   => 1200,
				'node'   => 'pve1',
				'status' => 'running',
			)
		);

		return $n;
	}

	private function clone_to( FakeNode $n, int $id, array $over = array() ) {
		return $n->task(
			'POST',
			'/nodes/pve1/lxc/9000/clone',
			array_merge(
				array(
					'newid'       => $id,
					'hostname'    => 'c' . $id,
					'pool'        => 'p1',
					'description' => "line\nbeltoft-services:t:u",
					'full'        => 0,
				),
				$over
			)
		);
	}

	public function test_a_linked_clone_is_a_task_that_creates_a_stopped_tagged_container_in_the_pool(): void {
		$n    = $this->node();
		$upid = $this->clone_to( $n, 1300 );

		$this->assertIsString( $upid );
		$this->assertStringStartsWith( 'UPID:pve1:', $upid );
		$this->assertTrue( $n->wait_task( $upid, 10 ) );

		$ct = $n->state()['cts'][1300];
		$this->assertSame( 'stopped', $ct['status'] );
		$this->assertSame( 'p1', $ct['pool'] );
		$this->assertSame( 'c1300', $ct['config']['hostname'] );
		$this->assertStringContainsString( 'beltoft-services:t:u', $ct['config']['description'] );
		$this->assertContains( 1300, $n->state()['pools']['p1']['members'] );
	}

	public function test_clone_refusals(): void {
		$n = $this->node();

		$this->assertSame( 400, $this->clone_to( $n, 1200 )->get_error_data()['status'], 'The id exists.' );
		$this->assertSame( 500, $n->task( 'POST', '/nodes/pve1/lxc/1200/clone', array( 'newid' => 1301, 'full' => 0 ) )->get_error_data()['status'], 'A linked clone needs a template.' );
		$this->assertSame( 500, $this->clone_to( $n, 1302, array( 'pool' => 'missing' ) )->get_error_data()['status'] );
		$this->assertSame( 500, $n->task( 'POST', '/nodes/pve1/lxc/777/clone', array( 'newid' => 1303 ) )->get_error_data()['status'], 'No such source.' );
		$this->assertSame( 400, $n->request( 'POST', '/nodes/pve1/lxc/9000/clone', array() )->get_error_data()['status'], 'newid is required.' );
		$this->assertSame( 400, $n->request( 'POST', '/nodes/pve1/lxc/9000/clone', array( 'newid' => 1304, 'bogus' => 1 ) )->get_error_data()['status'], 'Unknown parameter.' );
		$this->assertSame( 400, $n->request( 'POST', '/nodes/pve1/lxc/9000/clone', array( 'newid' => 'abc' ) )->get_error_data()['status'], 'Wrong type.' );
		$this->assertSame( 501, $n->request( 'GET', '/nodes/pve1/qemu/9000/config' )->get_error_data()['status'], 'No qemu routes: this fake knows containers only.' );
	}

	public function test_config_roundtrip_with_a_digest(): void {
		$n   = $this->node();
		$cfg = $n->request( 'GET', '/nodes/pve1/lxc/1200/config' );

		$this->assertArrayHasKey( 'digest', $cfg );
		$this->assertNull( $n->request( 'PUT', '/nodes/pve1/lxc/1200/config', array( 'cores' => 4, 'digest' => $cfg['digest'] ) ) );
		$this->assertSame( 4, $n->request( 'GET', '/nodes/pve1/lxc/1200/config' )['cores'] );

		$stale = $n->request( 'PUT', '/nodes/pve1/lxc/1200/config', array( 'cores' => 8, 'digest' => $cfg['digest'] ) );
		$this->assertWPError( $stale, 'The digest is old now.' );

		$this->assertNull( $n->request( 'PUT', '/nodes/pve1/lxc/1200/config', array( 'delete' => 'cores' ) ) );
		$this->assertArrayNotHasKey( 'cores', $n->request( 'GET', '/nodes/pve1/lxc/1200/config' ) );

		$this->assertWPError( $n->request( 'PUT', '/nodes/pve1/lxc/1200/config', array( 'net0' => 'name=eth0' ) ), 'net0 without a bridge.' );
		$this->assertWPError( $n->request( 'PUT', '/nodes/pve1/lxc/1200/config', array( 'rootfs' => 'x' ) ), 'rootfs is not a config key here: resize does that.' );
	}

	public function test_resize_grows_and_refuses_to_shrink(): void {
		$n = $this->node();

		$grow = $n->task( 'PUT', '/nodes/pve1/lxc/1200/resize', array( 'disk' => 'rootfs', 'size' => '10G' ) );
		$this->assertTrue( $n->wait_task( $grow, 10 ) );
		$this->assertStringContainsString( 'size=10G', $n->request( 'GET', '/nodes/pve1/lxc/1200/config' )['rootfs'] );

		$add = $n->task( 'PUT', '/nodes/pve1/lxc/1200/resize', array( 'disk' => 'rootfs', 'size' => '+2G' ) );
		$this->assertTrue( $n->wait_task( $add, 10 ) );
		$this->assertStringContainsString( 'size=12G', $n->request( 'GET', '/nodes/pve1/lxc/1200/config' )['rootfs'] );

		$shrink = $n->request( 'PUT', '/nodes/pve1/lxc/1200/resize', array( 'disk' => 'rootfs', 'size' => '5G' ) );
		$this->assertWPError( $shrink );
		$this->assertStringContainsString( 'shrinking', $shrink->get_error_message() );
		$this->assertWPError( $n->request( 'PUT', '/nodes/pve1/lxc/1200/resize', array( 'disk' => 'nodisk', 'size' => '5G' ) ) );
	}

	public function test_power_tasks_and_delete_rules(): void {
		$n = $this->node();

		$this->assertSame( 'running', $n->request( 'GET', '/nodes/pve1/lxc/1200/status/current' )['status'] );
		$this->assertWPError( $n->request( 'DELETE', '/nodes/pve1/lxc/1200', array( 'purge' => 1 ) ), 'A running container is not destroyed.' );
		$this->assertWPError( $n->request( 'POST', '/nodes/pve1/lxc/9000/status/start' ), 'A template does not start.' );

		$this->assertTrue( $n->wait_task( $n->task( 'POST', '/nodes/pve1/lxc/1200/status/shutdown', array( 'timeout' => 10 ) ), 10 ) );
		$this->assertSame( 'stopped', $n->request( 'GET', '/nodes/pve1/lxc/1200/status/current' )['status'] );
		$this->assertTrue( $n->wait_task( $n->task( 'POST', '/nodes/pve1/lxc/1200/status/start' ), 10 ) );
		$this->assertSame( 'running', $n->request( 'GET', '/nodes/pve1/lxc/1200/status/current' )['status'] );
		$this->assertTrue( $n->wait_task( $n->task( 'POST', '/nodes/pve1/lxc/1200/status/stop' ), 10 ) );

		$this->assertTrue( $n->wait_task( $n->task( 'DELETE', '/nodes/pve1/lxc/1200', array( 'purge' => 1 ) ), 10 ) );
		$this->assertArrayNotHasKey( 1200, $n->state()['cts'] );
		$this->assertWPError( $n->request( 'GET', '/nodes/pve1/lxc/1200/config' ) );
	}

	public function test_resources_nextid_and_firewall_options(): void {
		$n = $this->node();

		$list = $n->request( 'GET', '/cluster/resources', array( 'type' => 'vm' ) );
		$this->assertSame( array( 9000, 1200 ), array_column( $list, 'vmid' ) );
		$this->assertSame( 'lxc', $list[0]['type'] );
		$this->assertSame( 1, $list[0]['template'] );

		$this->assertSame( 1201, $n->request( 'GET', '/cluster/nextid', array( 'vmid' => 1201 ) ) );
		$this->assertSame( 400, $n->request( 'GET', '/cluster/nextid', array( 'vmid' => 1200 ) )->get_error_data()['status'] );

		$this->assertNull( $n->request( 'PUT', '/nodes/pve1/lxc/1200/firewall/options', array( 'enable' => 1, 'ipfilter' => 1, 'policy_in' => 'DROP' ) ) );
		$this->assertSame( array( 'enable' => 1, 'ipfilter' => 1, 'policy_in' => 'DROP' ), $n->request( 'GET', '/nodes/pve1/lxc/1200/firewall/options' ) );
		$this->assertWPError( $n->request( 'PUT', '/nodes/pve1/lxc/1200/firewall/options', array( 'bogus' => 1 ) ) );
	}

	public function test_scripted_tasks_failures_and_polls(): void {
		$n = $this->node();

		$n->next_task( array( 'polls' => 3 ) );
		$upid = $this->clone_to( $n, 1400 );
		$this->assertArrayHasKey( 1400, $n->state()['cts'] );
		$this->assertSame( 'task_pending', $n->wait_task( $upid, 2 )->get_error_code() );
		$this->assertTrue( $n->wait_task( $upid, 30 ) );

		$n->next_task( array( 'exitstatus' => 'boom' ) );
		$failed = $this->clone_to( $n, 1401 );
		$this->assertSame( 'task_failed', $n->wait_task( $failed, 10 )->get_error_code() );
		$this->assertArrayNotHasKey( 1401, $n->state()['cts'], 'A failed task does not apply its effect.' );

		$n->fail_next( 'GET', '#/status/current$#', array( 'status' => 503, 'message' => 'busy' ) );
		$this->assertSame( 503, $n->request( 'GET', '/nodes/pve1/lxc/1200/status/current' )->get_error_data()['status'] );
		$this->assertIsArray( $n->request( 'GET', '/nodes/pve1/lxc/1200/status/current' ), 'The failure was consumed.' );

		$this->assertCount( 2, $n->calls_to( '#/status/current$#', 'GET' ) );
	}

	public function test_persisted_state_round_trips_under_the_same_option_name(): void {
		delete_option( 'beltoft_services_fake_hosting_node' );

		$n = new FakeNode( true );
		$n->add_node( 'pve9' );
		$n->add_pool( 'rt' );

		$stored = get_option( 'beltoft_services_fake_hosting_node' );
		$this->assertIsArray( $stored );
		$this->assertArrayHasKey( 'pve9', $stored['nodes'] );
		$this->assertArrayHasKey( 'rt', $stored['pools'] );
		$this->assertSame( $stored, ( new FakeNode( true ) )->state() );

		delete_option( 'beltoft_services_fake_hosting_node' );
	}

	public function test_persist_shares_the_state_between_instances(): void {
		$a = new FakeNode( true );
		$a->add_node( 'pve1' );
		$a->add_template( 9000, 'pve1' );
		$a->request( 'GET', '/cluster/nextid' );

		$b = new FakeNode( true );
		$this->assertArrayHasKey( 9000, $b->state()['cts'] );

		$this->assertTrue( $b->wait_task( $b->task( 'POST', '/nodes/pve1/lxc/9000/clone', array( 'newid' => 1500 ) ), 10 ) );
		$this->assertArrayHasKey( 1500, ( new FakeNode( true ) )->state()['cts'] );
		$this->assertArrayNotHasKey( 1500, ( new FakeNode() )->state()['cts'], 'An in-memory node is its own.' );

		delete_option( FakeNode::OPTION );
	}
}

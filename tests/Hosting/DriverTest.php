<?php
/**
 * The hosting driver: registration, plan fields, ownership, and a license that goes all the way through the
 * reconciler. Fakes only.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Hosting;

require_once __DIR__ . '/HostingCase.php';

use BeltoftServices\Drivers\Registry;
use BeltoftServices\Drivers\StopsOnDelete;
use BeltoftServices\Hosting\CtOwnership;
use BeltoftServices\Hosting\HostingDriver;
use BeltoftServices\Hosting\Nodes;
use BeltoftServices\Hosting\Plan;
use BeltoftServices\Hosting\Records;
use BeltoftServices\Product;
use BeltoftServices\Reconciler;
use BeltoftServices\Resource;

/**
 * HostingDriver, Plan, CtOwnership and the whole path.
 */
class DriverTest extends HostingCase {

	public function test_the_driver_is_registered_through_the_filter(): void {
		$driver = Registry::get( 'hosting' );

		$this->assertInstanceOf( HostingDriver::class, $driver );
		$this->assertInstanceOf( StopsOnDelete::class, $driver );
		$this->assertSame( 'hosting', $driver->id() );
		$this->assertSame( 'hosting', $driver->kind() );
		$this->assertContains( 'hosting', Registry::kinds() );
		$this->assertMatchesRegularExpression( '/^[a-z0-9_-]{1,20}$/D', $driver->kind() );
		$this->assertNotSame( 'site', $driver->kind() );
		$this->assertSame( '1 of 3 hosting accounts', $driver->unit_label( 1, 3 ) );

		// The built-in registration leaves a driver that is already registered alone.
		$mine = new HostingDriver();
		$all  = HostingDriver::add( array( 'hosting' => $mine ) );
		$this->assertSame( $mine, $all['hosting'] );
		$this->assertSame( 'nope', HostingDriver::add( 'nope' ) );
	}

	public function test_the_plan_fields_are_the_plans_list(): void {
		$expected = array( 'node_group', 'sites', 'disk_gb', 'cores', 'memory_mb', 'swap_mb', 'php_workers', 'php_memory_mb', 'upload_mb', 'files', 'db_buffer_mb', 'valkey_mb', 'staging', 'ssh', 'backup_days', 'mail_mailboxes', 'mail_quota_mb', 'mail_aliases', 'mail_per_minute', 'site_mail_per_day' );

		$fields = $this->driver->plan_fields();
		$this->assertSame( $expected, array_keys( $fields ) );

		foreach ( $fields as $key => $field ) {
			$this->assertTrue( $field[4], $key . ' can vary per variation (one product, four plans).' );
		}
	}

	public function test_plan_values_are_read_with_product_plan_and_typed(): void {
		$product = $this->service_product(
			'hosting',
			array(
				'node_group' => 'g1',
				'sites'      => 3,
				'disk_gb'    => 25,
				'ssh'        => true,
				'staging'    => 'per_site',
			),
			array(
				'variations' => array(
					array( 'cores' => 2, 'memory_mb' => 2048 ),
					array( 'cores' => 4, 'memory_mb' => 8192, 'sites' => 10 ),
				),
			)
		);

		$variations = $product->get_children();
		$plan       = Plan::typed( Product::plan( $product->get_id(), (int) $variations[1] ) );

		$this->assertSame( 4, $plan['cores'] );
		$this->assertSame( 8192, $plan['memory_mb'] );
		$this->assertSame( 10, $plan['sites'] );
		$this->assertSame( 25, $plan['disk_gb'], 'The product value, no override.' );
		$this->assertTrue( $plan['ssh'] );
		$this->assertSame( 'per_site', $plan['staging'] );
		$this->assertSame( 'g1', $plan['node_group'] );
		$this->assertSame( 2000000, $plan['files'], 'A default.' );
		$this->assertNull( Plan::problem( $plan ) );
	}

	public function test_plan_typing_and_problems(): void {
		$plan = Plan::typed( array( 'cores' => '2', 'memory_mb' => '-5', 'staging' => 'weird', 'ssh' => 'no', 'node_group' => array() ) );

		$this->assertSame( 2, $plan['cores'] );
		$this->assertSame( 0, $plan['memory_mb'] );
		$this->assertSame( 'one', $plan['staging'] );
		$this->assertFalse( $plan['ssh'] );
		$this->assertSame( '', $plan['node_group'] );
		$this->assertStringContainsString( 'memory_mb', (string) Plan::problem( $plan ) );
		$this->assertStringContainsString( 'node group', (string) Plan::problem( array_merge( $plan, array( 'memory_mb' => 512, 'node_group' => 'Bad Group' ) ) ) );

		$this->assertSame( 5, Plan::workers_per_site( array( 'php_workers' => 10 ), 2 ) );
		$this->assertSame( 2, Plan::workers_per_site( array( 'php_workers' => 3 ), 5 ), 'At least 2.' );
		$this->assertSame( 10, Plan::workers_per_site( array( 'php_workers' => 10 ), 0 ) );
	}

	public function test_ownership_verify_gives_distinct_refusals(): void {
		$r = $this->resource();
		$this->provision_fully( $r );
		$r      = Resource::find( $r->id() );
		$record = Records::for_resource( $r->id() );
		$node   = Nodes::get( 'n1' );

		$ok = CtOwnership::verify( $this->node, $node, $record, $r );
		$this->assertSame( 'pve1', $ok['node'] );
		$this->assertSame( 'running', $ok['status'] );

		$range = CtOwnership::verify( $this->node, array_merge( $node, array( 'vmid_max' => 1000 ) ), array_merge( $record, array( 'vmid' => 1001 ) ), $r );
		$this->assertSame( 'out_of_range', $range->get_error_code() );

		$other = CtOwnership::verify( $this->node, $node, array_merge( $record, array( 'vmid' => 1500 ) ), $r );
		$this->assertSame( 'not_recorded', $other->get_error_code() );

		$this->assertSame( 'no_node', CtOwnership::verify( $this->node, array_merge( $node, array( 'pve_node' => '' ) ), $record, $r )->get_error_code() );

		$this->node->add_ct( array( 'vmid' => $record['vmid'], 'node' => 'pve1', 'name' => 'x', 'template' => 1 ) );
		$this->assertSame( 'is_template', CtOwnership::verify( $this->node, $node, $record, $r )->get_error_code() );

		$this->node->add_ct( array( 'vmid' => $record['vmid'], 'node' => 'pve1', 'name' => 'x', 'config' => array( 'description' => 'beltoft-services:other:' . $r->get( 'uuid' ) ) ) );
		$this->assertSame( 'not_ours', CtOwnership::verify( $this->node, $node, $record, $r )->get_error_code(), 'The same uuid under another site tag is not ours.' );
	}

	public function test_the_tag_line_matches_only_as_a_whole_line(): void {
		$r = $this->resource();
		$this->provision_fully( $r );
		$r   = Resource::find( $r->id() );
		$tag = CtOwnership::tag( $r );

		$this->assertMatchesRegularExpression( '/^beltoft-services:[a-z0-9._-]+:[A-Za-z0-9._:-]+$/D', $tag );
		$this->assertSame( $tag, $this->ct( $r )['config']['description'] );
	}

	public function test_customer_actions_and_acts_are_empty_for_now(): void {
		$r = $this->resource();

		$this->assertSame( array(), $this->driver->customer_actions( $r ) );
		$this->assertTrue( $this->driver->act( $r, 'reboot<script>', array() )->is_failed() );
	}

	public function test_the_panels_print_escaped_facts_only(): void {
		$r = $this->resource();

		ob_start();
		$this->driver->render_admin( $r );
		$this->assertStringContainsString( 'No hosting record yet', (string) ob_get_clean() );

		$this->provision_fully( $r );
		$r = Resource::find( $r->id() );

		ob_start();
		$this->driver->render_admin( $r );
		$admin = (string) ob_get_clean();
		$this->assertStringContainsString( '<td>n1</td>', $admin );
		$this->assertStringContainsString( '<td>22001</td>', $admin );
		$this->assertStringContainsString( '10.200.0.10', $admin );

		ob_start();
		$this->driver->render_account( $r );
		$this->assertSame( '<p>1 website</p>', (string) ob_get_clean() );

		$this->assertStringNotContainsString( "\u{2014}", $admin );
		$this->assertStringNotContainsString( "\u{00B7}", $admin );
	}

	/**
	 * Steps the reconciler until the resource reaches its desired state (or a few rounds pass).
	 *
	 * @param Resource $r Resource.
	 */
	private function settle( Resource $r ): Resource {
		for ( $i = 0; $i < 8; $i++ ) {
			$r->set( array( 'next_attempt_at' => null ) );
			Reconciler::step( Resource::find( $r->id() ) );
			$r = Resource::find( $r->id() );

			if ( $r->get( 'state' ) === $r->get( 'desired' ) ) {
				break;
			}
		}

		return $r;
	}

	public function test_a_license_goes_through_the_reconciler_to_ready_and_back_after_a_pause(): void {
		$product = $this->service_product(
			'hosting',
			array(
				'node_group'  => 'g1',
				'sites'       => 3,
				'disk_gb'     => 10,
				'cores'       => 2,
				'memory_mb'   => 2048,
				'php_workers' => 20,
			),
			array( 'max' => 3 )
		);

		$license = $this->service_license( $product );
		$this->run_jobs();

		$resource = Resource::for_license( (int) $license['id'] );

		$this->assertNotNull( $resource, 'The license became a hosting resource.' );
		$this->assertSame( 'hosting', $resource->get( 'driver' ) );
		$this->assertSame( 'ready', $resource->get( 'state' ), (string) $resource->get( 'last_error' ) );
		$this->assertSame( 'running', $this->ct( $resource )['status'] );
		$this->assertNotEmpty( $this->agent->state()['edge'] );

		$resource->desire( 'paused', 'test' );
		$resource = $this->settle( $resource );
		$this->assertSame( 'paused', $resource->get( 'state' ), (string) $resource->get( 'last_error' ) );
		$this->assertSame( 'stopped', $this->ct( $resource )['status'] );

		$resource->desire( 'ready', 'test' );
		$resource = $this->settle( $resource );
		$this->assertSame( 'ready', $resource->get( 'state' ), (string) $resource->get( 'last_error' ) );
		$this->assertSame( 'running', $this->ct( $resource )['status'] );
	}
}

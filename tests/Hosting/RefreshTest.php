<?php
/**
 * The container files refresh after a template change. Fakes only.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Hosting;

require_once __DIR__ . '/HostingCase.php';

use BeltoftServices\Hosting\HostingRefresh;
use BeltoftServices\Hosting\Records;
use BeltoftServices\Resource;

/**
 * HostingRefresh.
 */
class RefreshTest extends HostingCase {

	private function account(): Resource {
		$r = $this->resource();
		$this->provision_fully( $r );
		$r->set( array( 'state' => 'ready', 'desired' => 'ready' ) );

		return Resource::find( $r->id() );
	}

	public function test_a_template_change_queues_a_sweep(): void {
		as_unschedule_all_actions( HostingRefresh::HOOK );
		$this->save_node( array( 'template' => '9005' ) );

		$this->assertTrue( false !== as_next_scheduled_action( HostingRefresh::HOOK ) || as_has_scheduled_action( HostingRefresh::HOOK ) );
	}

	public function test_the_sweep_refreshes_each_ready_account_once_per_template_and_records_the_version(): void {
		$r = $this->account();

		$this->assertSame( array( 'done' => 1, 'skipped' => 0, 'failed' => 0 ), HostingRefresh::run() );
		$r = Resource::find( $r->id() );
		$this->assertSame( 'a1b2c3d4e5f6', $r->get( 'runner_version' ) );
		$this->assertSame( 9000, (int) $r->get( 'runner_for' ) );

		$this->assertSame( array( 'done' => 0, 'skipped' => 0, 'failed' => 0 ), HostingRefresh::run(), 'Nothing again for the same template.' );

		// A new template: every account again.
		$this->save_node( array( 'template' => '9003' ) );
		$this->assertSame( 1, HostingRefresh::run()['done'] );
		$this->assertSame( 9003, (int) Resource::find( $r->id() )->get( 'runner_for' ) );
	}

	public function test_a_container_that_is_not_running_is_skipped_and_done_on_the_next_sweep(): void {
		$r    = $this->account();
		$vmid = (int) Records::for_resource( $r->id() )['vmid'];

		$this->agent->set_not_running( $vmid );
		$this->assertSame( array( 'done' => 0, 'skipped' => 1, 'failed' => 0 ), HostingRefresh::run() );
		$this->assertSame( '', (string) Resource::find( $r->id() )->get( 'runner_version' ) );

		$this->agent->set_not_running( $vmid, false );
		$this->assertSame( 1, HostingRefresh::run()['done'], 'A new job id: the agent would return the stored failure for the old one.' );
	}

	public function test_accounts_that_are_not_ready_are_left_alone(): void {
		$r = $this->account();
		$r->set( array( 'state' => 'paused', 'desired' => 'paused' ) );

		$this->assertSame( 0, array_sum( HostingRefresh::run() ) );
	}

	public function test_the_admin_panel_shows_the_version(): void {
		$r = $this->account();
		HostingRefresh::run();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		ob_start();
		\BeltoftServices\Admin\Panel::render( Resource::find( $r->id() )->license() );
		$html = (string) ob_get_clean();

		wp_set_current_user( 0 );
		$this->assertStringContainsString( 'a1b2c3d4e5f6', $html );
	}
}

<?php
/**
 * The progress payload, the action marker, the status route's progress key and the markup.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests;

use BeltoftServices\Jobs;
use BeltoftServices\Progress;
use BeltoftServices\Resource;
use BeltoftServices\Tests\Support\FakeDriver;
use BeltoftLicenses\Account\View;

/**
 * Progress tests. Nothing here calls a server: only resource meta and the fake driver.
 */
final class ProgressTest extends TestCase {

	/**
	 * A resource of a driver id, with meta.
	 *
	 * @param string               $driver Driver id stored in the meta.
	 * @param array<string, mixed> $meta   Meta.
	 * @return array{0: Resource, 1: FakeDriver, 2: int}
	 */
	private function resource( string $driver, array $meta ): array {
		$fake                   = $this->fake_driver();
		$fake->customer_actions = array( 'reinstall' => 'Reinstall', 'restart' => 'Restart' );
		$product                = $this->service_product( 'fake', array(), array( 'max' => 3 ) );
		$row                    = $this->service_license( $product, array( 'fire' => false ) );
		$resource               = Resource::create( $row, $fake, array(), $meta );

		$this->assertInstanceOf( Resource::class, $resource );
		$resource->set( array( 'driver' => $driver ) );
		wp_set_current_user( (int) $row['user_id'] );

		return array( $resource, $fake, (int) $row['user_id'] );
	}

	/**
	 * Step statuses by key.
	 *
	 * @param array<string, mixed> $payload Payload.
	 * @return array<string, string>
	 */
	private function statuses( array $payload ): array {
		return array_column( $payload['steps'], 'status', 'key' );
	}

	public function test_a_server_build_lists_its_steps_with_the_current_one(): void {
		list( $r ) = $this->resource( 'proxmox', array( 'state' => 'provisioning', 'steps' => array( 'ip', 'vmid' ) ) );

		$p = Progress::build( $r );

		$this->assertSame( 'build', $p['kind'] );
		$this->assertSame( 'running', $p['phase'] );
		$this->assertTrue( $p['active'] );
		$this->assertSame( 'clone', $p['current'] );
		$this->assertSame( array( 'ip' => 'done', 'vmid' => 'done', 'clone' => 'current', 'configure' => 'todo', 'start' => 'todo', 'ready' => 'todo' ), $this->statuses( $p ) );
		$this->assertSame( 'Setting up your server. Usually about 3 minutes.', $p['text'] );
		$this->assertSame( 3, $p['minutes'] );
		$this->assertSame( 'Done', $p['steps'][0]['status_label'] );
		$this->assertSame( 'In progress', $p['steps'][2]['status_label'] );
	}

	public function test_a_new_service_waits_in_the_queue_with_no_current_step(): void {
		list( $r ) = $this->resource( 'proxmox', array( 'state' => 'requested' ) );

		$p = Progress::build( $r );

		$this->assertSame( 'queued', $p['phase'] );
		$this->assertTrue( $p['active'] );
		$this->assertSame( '', $p['current'] );
		$this->assertSame( array( 'todo' ), array_values( array_unique( $this->statuses( $p ) ) ) );
		$this->assertSame( 'Waiting to start. It begins in a moment.', $p['text'] );
		$this->assertGreaterThan( 0, $p['started'], 'A build began when its license was made.' );
	}

	public function test_a_driver_without_a_step_list_still_gets_a_title(): void {
		list( $r ) = $this->resource( 'whatever', array( 'state' => 'provisioning', 'steps' => array( 'x' ) ) );

		$p = Progress::build( $r );

		$this->assertSame( array(), $p['steps'] );
		$this->assertTrue( $p['active'] );
		$this->assertSame( 'Setting up your service. Usually about 3 minutes.', $p['text'] );
	}

	public function test_a_reinstall_starts_with_the_wipe_and_moves_on(): void {
		list( $r ) = $this->resource( 'proxmox', array( 'state' => 'provisioning', 'wipe' => true, 'steps' => array( 'ip', 'vmid' ), 'last_reinstall_at' => 1700000000 ) );

		$p = Progress::build( $r );

		$this->assertSame( 'reinstall', $p['kind'] );
		$this->assertSame( array( 'wipe' => 'current', 'clone' => 'todo', 'configure' => 'todo', 'start' => 'todo', 'ready' => 'todo' ), $this->statuses( $p ) );
		$this->assertSame( 'Reinstalling your server. Usually about 3 minutes.', $p['text'] );
		$this->assertSame( 1700000000, $p['started'] );

		$r->set( array( 'wipe' => null, 'steps' => array( 'ip', 'vmid', 'clone' ) ) );
		$p = Progress::build( $r );

		$this->assertSame( array( 'wipe' => 'done', 'clone' => 'done', 'configure' => 'current', 'start' => 'todo', 'ready' => 'todo' ), $this->statuses( $p ) );
		$this->assertSame( 'configure', $p['current'] );
	}

	public function test_a_restore_follows_its_phase_while_the_server_stays_ready(): void {
		list( $r ) = $this->resource( 'proxmox', array( 'state' => 'ready', 'restore' => array( 'volid' => 'v', 'phase' => '' ) ) );

		$expect = array(
			''         => array( 'restore' => 'current', 'plan' => 'todo', 'start' => 'todo' ),
			'restored' => array( 'restore' => 'done', 'plan' => 'current', 'start' => 'todo' ),
			'planned'  => array( 'restore' => 'done', 'plan' => 'done', 'start' => 'current' ),
		);

		foreach ( $expect as $phase => $statuses ) {
			$r->set( array( 'restore' => array( 'volid' => 'v', 'phase' => $phase ) ) );
			$p = Progress::build( $r );

			$this->assertSame( 'restore', $p['kind'], $phase );
			$this->assertSame( $statuses, $this->statuses( $p ), $phase );
			$this->assertSame( 10, $p['minutes'] );
		}
	}

	public function test_a_snapshot_rollback_follows_its_phase_while_the_server_stays_ready(): void {
		list( $r ) = $this->resource( 'proxmox', array( 'state' => 'ready', 'snapshot_rollback' => array( 'id' => 'a', 'phase' => 'stopping' ) ) );

		$expect = array(
			'stopping' => array( 'rollback' => 'current', 'plan' => 'todo', 'start' => 'todo' ),
			'rolling'  => array( 'rollback' => 'current', 'plan' => 'todo', 'start' => 'todo' ),
			'rolled'   => array( 'rollback' => 'done', 'plan' => 'current', 'start' => 'todo' ),
		);

		foreach ( $expect as $phase => $statuses ) {
			$r->set( array( 'snapshot_rollback' => array( 'id' => 'a', 'phase' => $phase ) ) );
			$p = Progress::build( $r );

			$this->assertSame( 'rollback', $p['kind'], $phase );
			$this->assertTrue( $p['active'], $phase );
			$this->assertSame( $statuses, $this->statuses( $p ), $phase );
			$this->assertSame( 'Going back to your snapshot', $p['title'] );
		}

		$labels = array_column( $p['steps'], 'label' );
		$this->assertSame( array( 'Go back to the snapshot', 'Apply your plan', 'Start the server' ), $labels );
	}

	public function test_the_plan_change_after_a_rollback_keeps_the_rollback_steps(): void {
		list( $r ) = $this->resource( 'proxmox', array( 'state' => 'ready', 'plan_dirty' => true, 'snapshot_rolled_at' => time() - 30 ) );

		$p = Progress::build( $r );

		$this->assertSame( 'rollback', $p['kind'] );
		$this->assertSame( array( 'rollback' => 'done', 'plan' => 'current', 'start' => 'todo' ), $this->statuses( $p ) );

		$r->set( array( 'snapshot_rolled_at' => time() - Progress::ROLLBACK_PLAN_WINDOW - 5 ) );
		$this->assertSame( 'update', Progress::build( $r )['kind'], 'An old rollback does not claim a later plan change.' );

		$r->set( array( 'snapshot_rolled_at' => time(), 'plan_dirty' => false ) );
		$this->assertSame( '', Progress::build( $r )['kind'] );
	}

	public function test_a_requested_rollback_is_queued_with_its_steps_and_a_requested_take_is_a_generic_action(): void {
		list( $r ) = $this->resource( 'proxmox', array( 'state' => 'ready' ) );

		Progress::mark( $r, 'snapshot_rollback', 'Roll back to a snapshot' );
		$p = Progress::build( $r );
		$this->assertSame( 'rollback', $p['kind'] );
		$this->assertSame( 'queued', $p['phase'] );
		$this->assertSame( array( 'todo' ), array_values( array_unique( $this->statuses( $p ) ) ) );

		Progress::mark( $r, 'snapshot_take', 'Take a snapshot' );
		$p = Progress::build( $r );
		$this->assertSame( 'action', $p['kind'] );
		$this->assertTrue( $p['active'] );
		$this->assertSame( 'Take a snapshot requested. It starts in a moment.', $p['text'] );
	}

	public function test_an_extra_disk_attach_has_its_own_steps(): void {
		list( $r ) = $this->resource( 'proxmox-disk', array( 'state' => 'provisioning', 'steps' => array( 'server', 'slot' ) ) );

		$p = Progress::build( $r );

		$this->assertSame( 'Adding your disk. Usually about 3 minutes.', $p['text'] );
		$this->assertSame( 'allocate', $p['current'] );
		$this->assertSame( array( 'server', 'slot', 'allocate', 'attach', 'verify' ), array_keys( $this->statuses( $p ) ) );
	}

	public function test_disk_resize_detach_and_removal_are_titles_only(): void {
		list( $r ) = $this->resource( 'proxmox-disk', array( 'state' => 'ready', 'plan_dirty' => true ) );
		$this->assertSame( 'Making your disk larger. Usually about 2 minutes.', Progress::build( $r )['text'] );

		$r->set( array( 'plan_dirty' => false, 'desired' => 'paused' ) );
		$p = Progress::build( $r );
		$this->assertSame( 'Detaching your disk. Usually about 1 minute.', $p['text'] );
		$this->assertSame( 'pause', $p['kind'] );

		$r->set( array( 'desired' => 'deleted', 'state' => 'deleting' ) );
		$p = Progress::build( $r );
		$this->assertSame( 'delete', $p['kind'] );
		$this->assertSame( 'Removing your service. Usually about 1 minute.', $p['text'] );
		$this->assertSame( array(), $p['steps'] );
	}

	public function test_a_resumed_service_and_an_applied_change_are_shown(): void {
		list( $r ) = $this->resource( 'proxmox', array( 'state' => 'paused', 'desired' => 'ready' ) );
		$this->assertSame( 'resume', Progress::build( $r )['kind'] );

		$r->set( array( 'state' => 'ready', 'plan_dirty' => true ) );
		$this->assertSame( 'Applying your change. Usually about 2 minutes.', Progress::build( $r )['text'] );
	}

	public function test_a_quiet_service_is_idle(): void {
		foreach ( array( 'ready', 'paused', 'deleted' ) as $state ) {
			list( $r ) = $this->resource( 'proxmox', array( 'state' => $state, 'desired' => 'paused' === $state ? 'paused' : ( 'deleted' === $state ? 'deleted' : 'ready' ) ) );

			$p = Progress::build( $r );

			$this->assertSame( 'idle', $p['phase'], $state );
			$this->assertFalse( $p['active'], $state );
			$this->assertSame( '', $p['text'], $state );
			$this->assertSame( array(), $p['steps'], $state );
			$this->assertSame( 0, $p['minutes'], $state );
		}
	}

	public function test_a_failed_build_marks_the_step_and_never_shows_the_error(): void {
		list( $r ) = $this->resource( 'proxmox', array( 'state' => 'failed', 'failed_op' => 'provision', 'steps' => array( 'ip', 'vmid', 'clone' ), 'last_error' => 'secret-host.internal 10.9.8.7 hunter2' ) );

		$p = Progress::build( $r );

		$this->assertSame( 'failed', $p['phase'] );
		$this->assertFalse( $p['active'] );
		$this->assertSame( 'configure', $p['current'] );
		$this->assertSame( 'failed', $this->statuses( $p )['configure'] );
		$this->assertSame( 'This stopped before it was finished.', $p['text'] );
		$this->assertStringNotContainsString( 'hunter2', (string) wp_json_encode( $p ) );
		$this->assertStringNotContainsString( '10.9.8.7', (string) wp_json_encode( $p ) );

		$r->set( array( 'failed_op' => '' ) );
		$this->assertSame( 'idle', Progress::build( $r )['phase'], 'A failure of something that is no work shows nothing.' );
	}

	public function test_the_payload_holds_no_secrets(): void {
		list( $r ) = $this->resource( 'proxmox', array( 'state' => 'provisioning', 'steps' => array( 'ip' ), 'ip' => '203.0.113.9', 'reveal_secret' => 'pw-1234', 'wipe' => true ) );

		$json = (string) wp_json_encode( Progress::build( $r ) );

		$this->assertStringNotContainsString( '203.0.113.9', $json );
		$this->assertStringNotContainsString( 'pw-1234', $json );
		$this->assertSame( array( 'kind', 'phase', 'active', 'title', 'steps', 'current', 'minutes', 'eta', 'text', 'started' ), array_keys( Progress::build( $r ) ) );
	}

	public function test_the_marker_shows_a_requested_action_at_once(): void {
		list( $r ) = $this->resource( 'proxmox', array( 'state' => 'ready' ) );

		Progress::mark( $r, 'reinstall' );
		$p = Progress::build( $r );

		$this->assertSame( 'queued', $p['phase'] );
		$this->assertSame( 'reinstall', $p['kind'] );
		$this->assertTrue( $p['active'] );
		$this->assertSame( 'Reinstall requested. It starts in a moment.', $p['text'] );
		$this->assertSame( 'wipe', $p['steps'][0]['key'] );
		$this->assertSame( array( 'todo' ), array_values( array_unique( $this->statuses( $p ) ) ) );
		$this->assertSame( Progress::pending( $r )['at'], $p['started'] );

		Progress::mark( $r, 'restore' );
		$this->assertSame( 'Restore requested. It starts in a moment.', Progress::build( $r )['text'] );
	}

	public function test_every_action_leaves_a_marker_and_a_vanished_job_lets_it_lapse(): void {
		list( $r ) = $this->resource( 'proxmox', array( 'state' => 'ready' ) );

		Progress::mark( $r, 'replace_ssh_key', 'Replace SSH key' );
		$p = Progress::build( $r );

		$this->assertSame( 'action', $p['kind'] );
		$this->assertSame( 'queued', $p['phase'] );
		$this->assertSame( 'Replace SSH key', $p['title'] );
		$this->assertSame( 'Replace SSH key requested. It starts in a moment.', $p['text'] );
		$this->assertSame( array(), $p['steps'] );

		Progress::mark( $r, 'x' );
		$this->assertSame( 'Your request is on its way. This can take a minute.', Progress::build( $r )['text'] );

		$r->set( array( Progress::MARKER => array( 'action' => 'reinstall', 'at' => time() - Progress::MARKER_TTL - 1 ) ) );
		$this->assertNull( Progress::pending( $r ), 'A job that never ran must not leave the card waiting for ever.' );
		$this->assertSame( 'idle', Progress::build( $r )['phase'] );

		Progress::mark( $r, 'reinstall' );
		Progress::clear( $r );
		$this->assertNull( $r->get( Progress::MARKER ) );
	}

	public function test_a_waiting_or_retrying_job_keeps_an_old_marker_alive(): void {
		list( $r ) = $this->resource( 'fake', array( 'state' => 'ready' ) );
		$r->set( array( Progress::MARKER => array( 'action' => 'reinstall', 'at' => time() - Progress::MARKER_TTL - 600 ) ) );

		$this->assertNull( Progress::pending( $r ) );

		Jobs::queue_act( $r->id(), 'reinstall', array(), 3600 );

		$this->assertNotNull( Progress::pending( $r ), 'The job still waits: the card keeps saying requested.' );
		$this->assertSame( 'queued', Progress::build( $r )['phase'] );
	}

	public function test_real_work_wins_over_the_marker(): void {
		list( $r ) = $this->resource( 'proxmox', array( 'state' => 'provisioning', 'wipe' => true, 'steps' => array( 'ip' ) ) );
		Progress::mark( $r, 'reinstall' );

		$p = Progress::build( $r );

		$this->assertSame( 'running', $p['phase'] );
		$this->assertSame( 'wipe', $p['current'] );
	}

	public function test_filters_change_minutes_and_steps(): void {
		list( $r ) = $this->resource( 'proxmox', array( 'state' => 'provisioning', 'steps' => array() ) );

		add_filter( 'beltoft_services_progress_minutes', static fn( $m, $kind, $driver ) => 'build' === $kind && 'proxmox' === $driver ? 7 : $m, 10, 3 );
		add_filter( 'beltoft_services_progress_steps', static fn( $s, $kind ) => 'build' === $kind ? array( 'one' => 'One', 'two' => 'Two' ) : $s, 10, 2 );

		$p = Progress::build( $r );

		$this->assertSame( 7, $p['minutes'] );
		$this->assertSame( 'Usually about 7 minutes.', $p['eta'] );
		$this->assertSame( array( 'one' => 'current', 'two' => 'todo' ), $this->statuses( $p ) );

	}

	public function test_the_action_route_marks_before_queueing_and_the_job_clears_it(): void {
		global $wp_rest_server;
		$wp_rest_server = new \WP_REST_Server();
		do_action( 'rest_api_init', $wp_rest_server );

		list( $r, $driver, $user ) = $this->resource( 'fake', array( 'state' => 'ready' ) );

		$post = function ( string $action ) use ( $r, $user ): \WP_REST_Response {
			wp_set_current_user( $user );
			return rest_get_server()->dispatch( new \WP_REST_Request( 'POST', '/beltoft-services/v1/resources/' . $r->id() . '/actions/' . $action ) );
		};

		$this->assertSame( 202, $post( 'restart' )->get_status() );
		$this->assertSame( 'restart', Resource::find( $r->id() )->get( Progress::MARKER )['action'], 'Every action leaves a marker, with the label the card shows.' );
		$this->assertSame( 'Restart', Resource::find( $r->id() )->get( Progress::MARKER )['label'] );
		$this->run_jobs();
		$this->assertNull( Resource::find( $r->id() )->get( Progress::MARKER ) );

		$this->assertSame( 202, $post( 'reinstall' )->get_status() );
		$fresh = Resource::find( $r->id() );
		$this->assertSame( 'reinstall', $fresh->get( Progress::MARKER )['action'] );

		wp_set_current_user( $user );
		$status = rest_get_server()->dispatch( new \WP_REST_Request( 'GET', '/beltoft-services/v1/resources/' . $r->id() ) )->get_data();
		$this->assertSame( 'queued', $status['progress']['phase'], 'The status poll shows it before the job has run.' );

		$this->run_jobs();

		$this->assertNull( Resource::find( $r->id() )->get( Progress::MARKER ), 'The job that ran cleared the marker.' );
		$this->assertContains( 'act', array_column( $driver->calls(), 'op' ) );
		$wp_rest_server = null;
	}

	public function test_a_dropped_action_clears_its_marker_too(): void {
		list( $r ) = $this->resource( 'fake', array( 'state' => 'ready' ) );
		Progress::mark( $r, 'reinstall' );

		Jobs::act( $r->id(), 'not-offered', array() );

		$this->assertNull( Resource::find( $r->id() )->get( Progress::MARKER ) );
	}

	public function test_a_failing_action_clears_the_marker_when_its_retries_run_out(): void {
		list( $r, $driver ) = $this->resource( 'fake', array( 'state' => 'ready' ) );
		$driver->will( 'act', \BeltoftServices\Drivers\StepResult::fail( 'no' ) );
		Progress::mark( $r, 'reinstall' );

		Jobs::act( $r->id(), 'reinstall', array() );

		$this->assertNull( Resource::find( $r->id() )->get( Progress::MARKER ) );
	}

	public function test_a_retrying_action_keeps_the_marker(): void {
		list( $r, $driver ) = $this->resource( 'fake', array( 'state' => 'ready' ) );
		$driver->will( 'act', \BeltoftServices\Drivers\StepResult::retry( 5, 'later' ) );
		Progress::mark( $r, 'reinstall' );

		Jobs::act( $r->id(), 'reinstall', array() );

		$this->assertNotNull( Resource::find( $r->id() )->get( Progress::MARKER ), 'Still waiting for the retry: the card keeps saying requested.' );
	}

	public function test_power_says_busy_while_work_is_shown(): void {
		$render = static function ( bool $busy ): string {
			$vars = array( 'ip' => '', 'plan' => array( 'cores' => 1, 'memory_mb' => 1, 'disk_gb' => 1 ), 'power' => 'running', 'os_label' => '', 'os_options' => array(), 'os_current' => 0, 'has_key' => false, 'can_reveal' => false, 'forms' => false, 'ssh_user' => 'root', 'backups' => null, 'metrics' => false, 'disk_used' => null, 'disk_total' => null, 'extra_disks' => array( 'items' => array(), 'add' => array(), 'mode' => 'off' ), 'busy' => $busy, 'snapshots' => null, 'snapshot_warnings' => array( 'reinstall' => '', 'restore' => '' ), 'resource_id' => 1 );
			ob_start();
			( static function ( array $vars ): void {
				extract( $vars, EXTR_SKIP ); // phpcs:ignore WordPress.PHP.DontExtract.extract_extract
				include BELTOFT_SERVICES_PATH . 'templates/account/vm.php';
			} )( $vars );
			return (string) ob_get_clean();
		};

		$this->assertStringContainsString( 'Running', $render( false ) );
		$busy = $render( true );
		$this->assertStringContainsString( 'Busy', $busy );
		$this->assertStringNotContainsString( 'Running', $busy );
	}

	public function test_the_server_panel_passes_busy_from_the_progress(): void {
		$this->assertStringContainsString( "Progress::build( \$r )['active']", (string) file_get_contents( BELTOFT_SERVICES_PATH . 'src/Proxmox/Panels.php' ) );
	}

	public function test_a_driver_cannot_write_the_marker(): void {
		$this->assertContains( Progress::MARKER, \BeltoftServices\Reconciler::RESERVED );
	}

	public function test_markup_has_a_live_region_and_marks_busy_only_while_active(): void {
		list( $r ) = $this->resource( 'proxmox', array( 'state' => 'provisioning', 'steps' => array( 'ip' ) ) );

		ob_start();
		Progress::render( Progress::build( $r ) );
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'role="status"', $html );
		$this->assertStringContainsString( 'aria-live="polite"', $html );
		$this->assertStringContainsString( 'aria-busy="true"', $html );
		$this->assertStringContainsString( 'aria-current="step"', $html );
		$this->assertStringContainsString( 'data-step="vmid"', $html );
		$this->assertStringContainsString( 'class="beltoft-services-progress is-running"', $html );
		$this->assertStringNotContainsString( 'data-progress-url', $html );
		$this->assertStringNotContainsString( '—', $html );

		$r->set( array( 'state' => 'ready' ) );
		ob_start();
		Progress::render( Progress::build( $r ), 'https://example.test/progress' );
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'is-idle', $html );
		$this->assertStringNotContainsString( 'aria-busy', $html );
		$this->assertStringContainsString( 'role="status"', $html, 'The live region exists before its text changes.' );
		$this->assertStringContainsString( 'data-progress-url="https://example.test/progress"', $html );
	}

	public function test_the_card_prints_the_block_for_its_owner_only(): void {
		list( $r ) = $this->resource( 'proxmox', array( 'state' => 'provisioning', 'steps' => array( 'ip' ) ) );
		$render    = function () use ( $r ): string {
			ob_start();
			do_action( 'beltoft_licenses_account_license', $r->license(), View::license( $r->license() ) );
			return (string) ob_get_clean();
		};

		$this->assertStringContainsString( 'data-progress', $render() );

		wp_set_current_user( self::factory()->user->create() );
		$this->assertStringNotContainsString( 'data-progress', $render() );
	}

	public function test_assets_follow_the_site_rules(): void {
		foreach ( array( 'assets/progress.js', 'assets/account.js', 'assets/vm.js', 'assets/account.css', 'templates/account/progress.php', 'src/Progress.php' ) as $file ) {
			$text = (string) file_get_contents( BELTOFT_SERVICES_PATH . $file );

			$this->assertStringNotContainsString( '—', $text, $file );
		}

		$css = (string) file_get_contents( BELTOFT_SERVICES_PATH . 'assets/account.css' );
		$mine = substr( $css, (int) strpos( $css, 'Live progress of a service' ) );
		$this->assertStringContainsString( '.is-busy::before', $mine );
		$this->assertStringNotContainsString( 'border-radius', $mine, 'Square corners.' );

		$js = (string) file_get_contents( BELTOFT_SERVICES_PATH . 'assets/account.js' ) . (string) file_get_contents( BELTOFT_SERVICES_PATH . 'assets/vm.js' );
		$this->assertStringContainsString( "setAttribute( 'aria-busy', 'true' )", $js );
		$this->assertStringContainsString( "removeAttribute( 'aria-busy' )", $js );
	}
}

<?php
/**
 * Jobs: queueing, the lock, the kill switch and the sweep.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests;

use BeltoftServices\Drivers\StepResult;
use BeltoftServices\Jobs;
use BeltoftServices\Progress;
use BeltoftServices\Resource;
use BeltoftServices\Tests\Support\FakeDriver;

/**
 * Jobs tests.
 */
final class JobsTest extends TestCase {

	public function set_up(): void {
		parent::set_up();

		add_filter( 'pre_wp_mail', '__return_true' );

		// Tests run the sweep themselves.
		as_unschedule_all_actions( Jobs::SWEEP, array(), Jobs::GROUP );
	}

	/**
	 * A resource of a fake-driver service product.
	 *
	 * @param array<string, mixed> $meta Initial meta.
	 * @return array{0: Resource, 1: FakeDriver}
	 */
	private function resource( array $meta = array() ): array {
		$driver   = $this->fake_driver();
		$product  = $this->service_product( 'fake', array(), array( 'max' => 3 ) );
		$license  = $this->service_license( $product, array( 'fire' => false ) );
		$resource = Resource::create( $license, $driver, array(), $meta );

		$this->assertInstanceOf( Resource::class, $resource );

		return array( $resource, $driver );
	}

	/**
	 * Pending reconcile actions of a resource, as scheduled timestamps.
	 *
	 * @param int $id Activation id.
	 * @return int[]
	 */
	private function pending( int $id ): array {
		$actions = as_get_scheduled_actions(
			array(
				'hook'     => Jobs::HOOK,
				'args'     => array( $id ),
				'group'    => Jobs::GROUP,
				'status'   => \ActionScheduler_Store::STATUS_PENDING,
				'per_page' => -1,
			)
		);

		$times = array();
		foreach ( $actions as $action ) {
			$date    = $action->get_schedule()->get_date();
			$times[] = $date ? $date->getTimestamp() : time();
		}

		return $times;
	}

	public function test_a_customer_action_that_crashes_gets_a_failed_result_and_is_not_repeated(): void {
		list( $r ) = $this->resource( array( 'state' => 'ready', 'desired' => 'ready' ) );
		$action_id = as_schedule_single_action( time(), Jobs::ACT, array( $r->id(), 'private_detach', array() ), Jobs::GROUP );
		Jobs::job_failed( $action_id, new \Error( 'Class "BeltoftServices\\Locks" not found' ) );
		$result = Resource::find( $r->id() )->get( Jobs::RESULT );
		$this->assertSame( 'private_detach', $result['action'] );
		$this->assertFalse( $result['ok'], 'The page waiting for it stops and says so.' );
		$this->assertNotSame( '', $result['text'] );
	}

	public function test_any_plugin_job_waits_while_the_hold_file_or_the_pause_setting_holds_and_runs_afterwards(): void {
		$ran = 0;
		add_action( 'beltoft_services_test_sweep', static function () use ( &$ran ) {
			++$ran;
		} );
		$file = trailingslashit( WP_CONTENT_DIR ) . Jobs::HOLD_FILE;
		touch( $file );

		try {
			$id = as_schedule_single_action( time() - 5, 'beltoft_services_test_sweep', array( 7 ), Jobs::GROUP );
			\ActionScheduler_QueueRunner::instance()->process_action( $id );
			$this->assertSame( 0, $ran, 'A deploy hold file stops a job that does not check it by itself.' );
			$this->assertTrue( as_has_scheduled_action( 'beltoft_services_test_sweep', array( 7 ), Jobs::GROUP ), 'It keeps its place.' );
		} finally {
			unlink( $file );
		}

		$next = as_next_scheduled_action( 'beltoft_services_test_sweep', array( 7 ), Jobs::GROUP );
		$this->assertGreaterThan( time() + 30, $next );
		as_unschedule_all_actions( 'beltoft_services_test_sweep', array( 7 ), Jobs::GROUP );
		$id = as_schedule_single_action( time() - 5, 'beltoft_services_test_sweep', array( 7 ), Jobs::GROUP );
		\ActionScheduler_QueueRunner::instance()->process_action( $id );
		$this->assertSame( 1, $ran, 'Without a hold it runs.' );
	}

	public function test_a_reconcile_job_that_keeps_crashing_backs_off_and_ends_in_a_failed_resource(): void {
		list( $r ) = $this->resource( array( 'state' => 'provisioning', 'desired' => 'ready' ) );
		$delays = array();

		for ( $i = 1; $i <= 6; $i++ ) {
			$action_id = as_schedule_single_action( time(), Jobs::HOOK, array( $r->id() ), Jobs::GROUP );
			\ActionScheduler::store()->mark_failure( $action_id );
			Jobs::job_failed( $action_id, new \Error( 'Allowed memory size exhausted' ) );
			$times = $this->pending( $r->id() );
			$delays[] = $times ? min( $times ) - time() : null;
			foreach ( $times as $unused ) {
				as_unschedule_all_actions( Jobs::HOOK, array( $r->id() ), Jobs::GROUP );
			}
		}

		$this->assertGreaterThanOrEqual( 55, $delays[0] );
		$this->assertLessThanOrEqual( 65, $delays[0] );
		$this->assertGreaterThanOrEqual( 290, $delays[1], 'The second crash waits the backoff, not a minute.' );
		$this->assertGreaterThanOrEqual( 14000, $delays[4] );
		$this->assertNull( $delays[5], 'After the fifth backoff nothing is queued again.' );
		$this->assertSame( 'failed', Resource::find( $r->id() )->get( 'state' ) );
	}

	public function test_a_resumable_action_whose_job_was_lost_is_queued_again_once_and_a_server_action_is_not(): void {
		list( $r ) = $this->resource( array( 'state' => 'ready', 'desired' => 'ready' ) );
		$failed = array();
		add_action(
			'beltoft_services_action_failed',
			static function ( $res, $action, $args, $why ) use ( &$failed ) {
				$failed[] = array( $action, $why );
			},
			10,
			4
		);
		add_filter( 'beltoft_services_action_resumable', static fn( $yes, $driver, $action ) => 'fake' === $driver && 'resumable' === $action ? true : $yes, 10, 3 );

		// An action that keeps its steps: queued again, the marker and the page stay, no failed result.
		Progress::mark( $r, 'resumable', 'Doing it' );
		$id = as_schedule_single_action( time() - 5, Jobs::ACT, array( $r->id(), 'resumable', array( 'x' ) ), Jobs::GROUP );
		\ActionScheduler::store()->mark_failure( $id );
		Jobs::job_failed( $id, new \Error( 'Class not found' ) );

		$fresh = Resource::find( $r->id() );
		$this->assertNotNull( Progress::pending( $fresh ), 'Still in progress.' );
		$this->assertNull( $fresh->get( Jobs::RESULT ) );
		// The repeat carries the click time of the marker it repeats (a fourth job argument), so it is dropped when the first run finished.
		$at = Resource::find( $r->id() )->get( Progress::MARKER )['at'];
		$this->assertTrue( as_has_scheduled_action( Jobs::ACT, array( $r->id(), 'resumable', array( 'x' ), $at ), Jobs::GROUP ), 'Queued again with its arguments.' );
		$this->assertSame( array(), $failed );

		// Lost again: that is a failure.
		$again = as_schedule_single_action( time() - 5, Jobs::ACT, array( $r->id(), 'resumable', array( 'x' ) ), Jobs::GROUP );
		\ActionScheduler::store()->mark_failure( $again );
		Jobs::job_failed( $again, new \Error( 'Class not found' ) );
		$this->assertFalse( Resource::find( $r->id() )->get( Jobs::RESULT )['ok'] );
		$this->assertNull( Progress::pending( Resource::find( $r->id() ) ) );
		$this->assertSame( array( array( 'resumable', 'stopped' ) ), $failed );

		// A server action (not resumable) is unchanged: a failed result at once, nothing queued again.
		Progress::mark( $r, 'reboot', 'Reboot' );
		$reboot = as_schedule_single_action( time() - 5, Jobs::ACT, array( $r->id(), 'reboot', array() ), Jobs::GROUP );
		\ActionScheduler::store()->mark_failure( $reboot );
		Jobs::job_failed( $reboot, new \Error( 'Class not found' ) );
		$this->assertFalse( Resource::find( $r->id() )->get( Jobs::RESULT )['ok'] );
		$this->assertSame( 'reboot', Resource::find( $r->id() )->get( Jobs::RESULT )['action'] );
		$this->assertFalse( as_has_scheduled_action( Jobs::ACT, array( $r->id(), 'reboot', array() ), Jobs::GROUP ) );
	}

	public function test_a_marker_nothing_works_on_ends_with_a_failed_result_and_one_staff_alert(): void {
		list( $r ) = $this->resource( array( 'state' => 'ready', 'desired' => 'ready' ) );
		$mails = 0;
		$hooks = 0;
		add_filter(
			'pre_wp_mail',
			static function () use ( &$mails ) {
				++$mails;
				return true;
			},
			20
		);
		add_action(
			'beltoft_services_action_failed',
			static function () use ( &$hooks ) {
				++$hooks;
			}
		);
		$this->settings( array( 'admin_email' => 'christian.beltoft@gmail.com' ) );

		Progress::mark( $r, 'reboot', 'Reboot' );
		$this->assertNotNull( Progress::pending( Resource::find( $r->id() ) ), 'A young marker stands.' );

		$fresh = Resource::find( $r->id() );
		$fresh->set( array( Progress::MARKER => array( 'action' => 'reboot', 'at' => time() - Progress::MARKER_TTL - 5, 'label' => 'Reboot' ) ) );

		$this->assertNull( Progress::pending( Resource::find( $r->id() ) ) );
		$result = Resource::find( $r->id() )->get( Jobs::RESULT );
		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'reboot', $result['action'] );
		$this->assertSame( 1, $mails, 'Staff are told.' );
		$this->assertSame( 1, $hooks );

		Progress::pending( Resource::find( $r->id() ) );
		Jobs::sweep();
		$this->assertSame( 1, $mails, 'Once.' );
		$this->assertSame( 1, $hooks );
	}

	public function test_a_build_job_whose_process_died_is_queued_again(): void {
		list( $r ) = $this->resource();
		as_unschedule_all_actions( Jobs::HOOK, array( $r->id() ), Jobs::GROUP );
		$action_id = as_schedule_single_action( time() - 5, Jobs::HOOK, array( $r->id() ), Jobs::GROUP );
		\ActionScheduler::store()->mark_failure( $action_id );
		Jobs::job_failed( $action_id, array( 'message' => 'Allowed memory size exhausted' ) );
		$this->assertCount( 1, $this->pending( $r->id() ), 'A new reconcile job is waiting.' );
	}

	public function test_a_hold_queues_jobs_again_without_running_them(): void {
		list( $r, $driver ) = $this->resource();
		as_unschedule_all_actions( Jobs::HOOK, array( $r->id() ), Jobs::GROUP );
		add_filter( 'beltoft_services_jobs_hold', '__return_true' );
		Jobs::run( $r->id() );
		Jobs::act( $r->id(), 'reboot', array() );
		remove_filter( 'beltoft_services_jobs_hold', '__return_true' );
		$this->assertSame( array(), $driver->calls(), 'Nothing reached the driver while held.' );
		$this->assertCount( 1, $this->pending( $r->id() ), 'The job waits to run after the hold.' );
		$this->assertTrue( as_has_scheduled_action( Jobs::ACT, array( $r->id(), 'reboot', array() ), Jobs::GROUP ) );
	}

	public function test_the_hold_file_holds_jobs_and_an_old_one_is_ignored(): void {
		$file = trailingslashit( WP_CONTENT_DIR ) . Jobs::HOLD_FILE;
		touch( $file );
		$this->assertTrue( Jobs::on_hold() );
		touch( $file, time() - 1000 );
		$this->assertFalse( Jobs::on_hold(), 'A forgotten hold does not stop the service for ever.' );
		unlink( $file );
		$this->assertFalse( Jobs::on_hold() );
	}

	public function test_the_cleaners_timeout_reports_a_customer_action_that_never_finished(): void {
		list( $r ) = $this->resource( array( 'state' => 'ready', 'desired' => 'ready' ) );
		$action_id = as_schedule_single_action( time(), Jobs::ACT, array( $r->id(), 'reboot', array() ), Jobs::GROUP );
		Jobs::job_failed( $action_id, 300 );
		$this->assertFalse( Resource::find( $r->id() )->get( Jobs::RESULT )['ok'] );
	}

	public function test_a_stop_signal_ends_the_runner_between_jobs(): void {
		$this->settings( array( 'pause_all' => 1 ) );
		$slept = 0;
		// The signal arrives while the runner waits for work: it ends after that wait instead of running on.
		$ran = Jobs::run_due( 30, 1, static function () use ( &$slept ) { ++$slept; Jobs::stop_runner(); }, static fn() => 1000 );
		$this->assertSame( 0, $ran );
		$this->assertSame( 1, $slept );
	}

	public function test_enqueue_keeps_one_pending_job_per_resource(): void {
		list( $resource ) = $this->resource();

		Jobs::enqueue( $resource->id() );
		Jobs::enqueue( $resource->id() );
		Jobs::enqueue( $resource->id(), 300 );

		$this->assertCount( 1, $this->pending( $resource->id() ) );
	}

	public function test_kill_switch_makes_no_driver_calls_and_resumes_after(): void {
		list( $resource, $driver ) = $this->resource();
		Jobs::enqueue( $resource->id() );

		$this->settings( array( 'pause_all' => 1 ) );
		$before = time();
		$this->run_jobs();

		$this->assertSame( array(), $driver->calls() );
		$this->assertSame( 'requested', Resource::find( $resource->id() )->get( 'state' ) );
		$pending = $this->pending( $resource->id() );
		$this->assertCount( 1, $pending, 'The job keeps its place.' );
		$this->assertEqualsWithDelta( $before + 900, $pending[0], 2 );

		// The sweep queues nothing new while paused either.
		Jobs::sweep();
		$this->run_jobs();
		$this->assertSame( array(), $driver->calls() );

		// Turned off: the waiting job runs now.
		$this->settings( array( 'pause_all' => 0 ) );
		$this->run_jobs();

		$this->assertCount( 1, $driver->calls( 'provision' ) );
		$this->assertSame( 'ready', Resource::find( $resource->id() )->get( 'state' ) );
	}

	public function test_turning_the_kill_switch_off_keeps_retry_backoff(): void {
		list( $resource, $driver ) = $this->resource();
		$driver->will( 'provision', StepResult::retry( 0, 'Later' ) );
		Jobs::run( $resource->id() );

		$this->settings( array( 'pause_all' => 1 ) );
		$this->settings( array( 'pause_all' => 0 ) );
		$this->run_jobs();

		$this->assertCount( 1, $driver->calls( 'provision' ), 'A job waiting for its retry time is not brought forward.' );
	}

	public function test_second_runner_under_lock_reschedules_without_calling_the_driver(): void {
		list( $resource, $driver ) = $this->resource();

		$other = new \mysqli( DB_HOST, DB_USER, DB_PASSWORD, DB_NAME ); // phpcs:ignore WordPress.DB.RestrictedClasses.mysql__mysqli -- a second connection holds the lock.
		global $wpdb;
		$held = $other->query( $wpdb->prepare( 'SELECT GET_LOCK( %s, 1 )', \BeltoftServices\Locks::name( 'resource', $resource->id() ) ) );
		$this->assertSame( '1', $held->fetch_row()[0], 'The second connection holds the lock.' );

		$before = time();
		Jobs::run( $resource->id() );

		$other->close();

		$this->assertSame( array(), $driver->calls() );
		$this->assertSame( 'requested', Resource::find( $resource->id() )->get( 'state' ) );
		$pending = $this->pending( $resource->id() );
		$this->assertCount( 1, $pending );
		$this->assertEqualsWithDelta( $before + Jobs::LOCKED_DELAY, $pending[0], 2 );

		// With the other runner gone, the lock is free again.
		Jobs::run( $resource->id() );
		$this->assertCount( 1, $driver->calls( 'provision' ) );
	}

	public function test_driver_exception_is_a_retry_and_releases_the_lock(): void {
		list( $resource ) = $this->resource();

		add_filter(
			'beltoft_services_drivers',
			static function ( $drivers ) {
				$drivers['fake'] = new class() extends FakeDriver {
					public function provision( Resource $r ): StepResult {
						throw new \RuntimeException( 'Driver crashed' );
					}
				};
				return $drivers;
			},
			20
		);

		Jobs::run( $resource->id() );

		$fresh = Resource::find( $resource->id() );
		$this->assertSame( 1, $fresh->get( 'attempts' ), 'A crash counts as a retry.' );
		$this->assertSame( 'Driver crashed', $fresh->get( 'last_error' ) );
		$this->assertCount( 1, $this->pending( $resource->id() ) );

		global $wpdb;
		$this->assertSame( '1', $wpdb->get_var( $wpdb->prepare( 'SELECT IS_FREE_LOCK( %s )', \BeltoftServices\Locks::name( 'resource', $resource->id() ) ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	}

	public function test_sweep_picks_up_due_retries(): void {
		list( $due, $driver ) = $this->resource(
			array(
				'state'           => 'provisioning',
				'attempts'        => 2,
				'next_attempt_at' => time() - 10,
			)
		);

		$this->assertSame( array(), $this->pending( $due->id() ), 'Its job was lost.' );

		Jobs::sweep();
		$this->assertCount( 1, $this->pending( $due->id() ) );

		$this->run_jobs();

		$fresh = Resource::find( $due->id() );
		$this->assertSame( 'ready', $fresh->get( 'state' ) );
		$this->assertCount( 1, $driver->calls( 'provision' ) );
	}

	public function test_sweep_waits_for_the_retry_time_and_skips_settled_and_failed(): void {
		$product  = $this->service_product( 'fake', array(), array( 'max' => 3 ) );
		$driver   = $this->fake_driver();
		$make     = function ( array $meta ) use ( $product, $driver ) {
			return Resource::create( $this->service_license( $product, array( 'fire' => false ) ), $driver, array(), $meta );
		};
		$later    = $make(
			array(
				'state'           => 'provisioning',
				'op'              => 'provision',
				'attempts'        => 1,
				'next_attempt_at' => time() + 600,
			)
		);
		$settled  = $make( array( 'state' => 'ready' ) );
		$failed   = $make(
			array(
				'state'          => 'failed',
				'failed_op'      => 'provision',
				'failed_desired' => 'ready',
				'attempts'       => 6,
			)
		);
		$dirty    = $make(
			array(
				'state'      => 'ready',
				'plan_dirty' => true,
			)
		);
		$pausing  = $make(
			array(
				'state'   => 'ready',
				'desired' => 'paused',
			)
		);

		$resuming = $make(
			array(
				'state'          => 'failed',
				'failed_op'      => 'pause',
				'failed_desired' => 'paused',
				'op'             => 'pause',
				'attempts'       => 6,
			)
		);

		Jobs::sweep();

		$this->assertCount( 1, $this->pending( $resuming->id() ), 'A failed pause that should be ready again resumes.' );
		$this->assertEqualsWithDelta( time() + 600, $this->pending( $later->id() )[0] ?? 0, 2 );
		$this->assertSame( array(), $this->pending( $settled->id() ) );
		$this->assertSame( array(), $this->pending( $failed->id() ), 'A failed resource waits for the admin.' );
		$this->assertCount( 1, $this->pending( $dirty->id() ) );
		$this->assertCount( 1, $this->pending( $pausing->id() ) );
	}

	/**
	 * A resource on a license with the given status, created without the listeners.
	 *
	 * @param string               $status License status.
	 * @param array<string, mixed> $meta   Resource meta.
	 */
	private function resource_on_license( string $status, array $meta ): Resource {
		$driver   = $this->fake_driver();
		$product  = $this->service_product( 'fake', array(), array( 'max' => 3 ) );
		$license  = $this->service_license( $product, array( 'fire' => false ) );
		$resource = Resource::create( $license, $driver, array(), $meta );

		$this->assertInstanceOf( Resource::class, $resource );

		// The status changes without the listeners: a change the service never heard of.
		global $wpdb;
		$wpdb->update( \BeltoftLicenses\Install::tables()['licenses'], array( 'status' => $status ), array( 'id' => (int) $license['id'] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		return $resource;
	}

	public function test_sweep_pauses_a_ready_service_whose_license_is_not_active(): void {
		$revoked = $this->resource_on_license( \BeltoftLicenses\Licenses::STATUS_REVOKED, array( 'state' => 'ready' ) );
		$active  = $this->resource_on_license( \BeltoftLicenses\Licenses::STATUS_ACTIVE, array( 'state' => 'ready' ) );

		// A license that is gone is not active either.
		$gone = $this->resource_on_license( \BeltoftLicenses\Licenses::STATUS_ACTIVE, array( 'state' => 'ready' ) );
		global $wpdb;
		$wpdb->delete( \BeltoftLicenses\Install::tables()['licenses'], array( 'id' => (int) $gone->license()['id'] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$this->assertSame( array(), $gone->license() );

		Jobs::sweep();

		$this->assertSame( 'paused', Resource::find( $revoked->id() )->get( 'desired' ) );
		$this->assertSame( 'paused', Resource::find( $gone->id() )->get( 'desired' ) );
		$this->assertSame( 'ready', Resource::find( $active->id() )->get( 'desired' ) );
		$this->assertCount( 1, $this->pending( $revoked->id() ) );
		$this->assertSame( array(), $this->pending( $active->id() ) );

		$events = wp_list_pluck( \BeltoftLicenses\Events::for_license( (int) $revoked->license()['id'] ), 'message' );
		$this->assertContains( 'License not active (found by the sweep)', $events );
	}

	public function test_sweep_only_alerts_for_a_paused_service_on_an_active_license(): void {
		$this->settings( array( 'admin_email' => 'ops@example.com' ) );
		$mails = array();
		add_filter(
			'pre_wp_mail',
			static function ( $pre, $atts ) use ( &$mails ) {
				$mails[] = $atts;
				return true;
			},
			20,
			2
		);

		$resource = $this->resource_on_license( \BeltoftLicenses\Licenses::STATUS_ACTIVE, array( 'state' => 'paused', 'desired' => 'paused' ) );

		Jobs::sweep();
		Jobs::sweep();

		$fresh = Resource::find( $resource->id() );
		$this->assertSame( 'paused', $fresh->get( 'desired' ), 'Never resumed automatically.' );
		$this->assertSame( 'paused', $fresh->get( 'state' ) );
		$this->assertCount( 1, $mails, 'One alert, not one per sweep.' );
		$this->assertStringContainsString( 'license is active', $mails[0]['message'] );
	}

	public function test_sweep_is_scheduled_every_fifteen_minutes(): void {
		Jobs::schedule();
		Jobs::schedule();

		$actions = as_get_scheduled_actions(
			array(
				'hook'   => Jobs::SWEEP,
				'group'  => Jobs::GROUP,
				'status' => \ActionScheduler_Store::STATUS_PENDING,
			)
		);

		$this->assertCount( 1, $actions );
		$schedule = reset( $actions )->get_schedule();
		$this->assertInstanceOf( \ActionScheduler_CronSchedule::class, $schedule );
		$this->assertSame( '*/15 * * * *', $schedule->get_recurrence() );

		Jobs::unschedule();
		$this->assertFalse( as_has_scheduled_action( Jobs::SWEEP, array(), Jobs::GROUP ) );
	}

	public function test_hooks_are_registered(): void {
		$this->assertNotFalse( has_action( Jobs::HOOK, array( Jobs::class, 'run' ) ) );
		$this->assertNotFalse( has_action( Jobs::SWEEP, array( Jobs::class, 'sweep' ) ) );
		$this->assertNotFalse( in_array( array( Jobs::class, 'schedule' ), Jobs::callbacks(), true ) );
	}

	public function test_run_jobs_fails_on_a_failed_action(): void {
		add_action(
			'beltoft_services_test_boom',
			static function () {
				throw new \RuntimeException( 'Boom' );
			}
		);
		as_enqueue_async_action( 'beltoft_services_test_boom', array(), Jobs::GROUP );

		$this->expectException( \PHPUnit\Framework\AssertionFailedError::class );
		$this->expectExceptionMessage( 'Boom' );
		$this->run_jobs();
	}

	public function test_run_jobs_fails_at_its_round_cap(): void {
		add_action(
			'beltoft_services_test_forever',
			static function () {
				as_enqueue_async_action( 'beltoft_services_test_forever', array( wp_generate_uuid4() ), Jobs::GROUP );
			}
		);
		as_enqueue_async_action( 'beltoft_services_test_forever', array(), Jobs::GROUP );

		$this->expectException( \PHPUnit\Framework\AssertionFailedError::class );
		$this->run_jobs( 3 );
	}

	public function test_a_running_action_does_not_count_as_pending_so_it_can_schedule_its_next_attempt(): void {
		$this->assertFalse( Jobs::pending_action( 'beltoft_services_test_retry', array( 7 ) ) );
		$id = as_schedule_single_action( time() + 600, 'beltoft_services_test_retry', array( 7 ), Jobs::GROUP );
		$this->assertTrue( Jobs::pending_action( 'beltoft_services_test_retry', array( 7 ) ) );
		\ActionScheduler::store()->log_execution( $id );
		$this->assertTrue( as_has_scheduled_action( 'beltoft_services_test_retry', array( 7 ), Jobs::GROUP ), 'Action Scheduler counts the running action.' );
		$this->assertFalse( Jobs::pending_action( 'beltoft_services_test_retry', array( 7 ) ), 'A running action is not pending.' );
		as_unschedule_all_actions( 'beltoft_services_test_retry' );
	}

	public function test_a_pending_action_counts_while_another_copy_is_running(): void {
		$running = as_schedule_single_action( time() - 10, 'beltoft_services_test_retry', array( 8 ), Jobs::GROUP );
		\ActionScheduler::store()->log_execution( $running );
		as_schedule_single_action( time() + 600, 'beltoft_services_test_retry', array( 8 ), Jobs::GROUP );
		$this->assertTrue( Jobs::pending_action( 'beltoft_services_test_retry', array( 8 ) ), 'The waiting copy is pending even while one runs.' );
		$this->assertFalse( Jobs::schedule_once( time() + 900, 'beltoft_services_test_retry', array( 8 ) ), 'No duplicate next to a waiting copy.' );
		as_unschedule_all_actions( 'beltoft_services_test_retry' );
	}

	// -- Shared additions of 0.30.0: a driver's own sentence, and the one way a route starts an action ----

	public function test_a_driver_sentence_is_the_outcome_text_and_is_never_stored_as_meta(): void {
		list( $r, $driver ) = $this->resource( array( 'state' => 'ready', 'desired' => 'ready' ) );
		$driver->customer_actions = array( 'tidy' => 'Tidy up' );
		$driver->will( 'act', StepResult::done( array( Jobs::TEXT => 'Everything is tidy.', 'tidied' => 1 ) ) );

		Jobs::act( $r->id(), 'tidy', array() );
		$fresh = Resource::find( $r->id() );
		$this->assertSame( array( 'action' => 'tidy', 'ok' => true, 'text' => 'Everything is tidy.' ), array_intersect_key( $fresh->get( Jobs::RESULT ), array_flip( array( 'action', 'ok', 'text' ) ) ) );
		$this->assertSame( 1, $fresh->get( 'tidied' ) );
		$this->assertNull( $fresh->get( Jobs::TEXT ), 'The sentence is not resource meta.' );
	}

	public function test_a_refusal_is_what_the_driver_says_not_a_word_in_the_event_text(): void {
		list( $r, $driver ) = $this->resource( array( 'state' => 'ready', 'desired' => 'ready' ) );
		$driver->customer_actions = array( 'tidy' => 'Tidy up' );
		$seen                     = array();
		add_action(
			'beltoft_services_action_done',
			static function ( $res, $action ) use ( &$seen ) {
				$seen[] = $action;
			},
			10,
			2
		);

		// Done, with a site called "refused.example" in its event: it worked.
		$driver->will( 'act', StepResult::done( array(), 'Address of refused.example changed' ) )->will( 'act', StepResult::refused( array(), 'Tidy refused: nothing to tidy' ) );
		Progress::mark( $r, 'tidy', 'Tidy up' );
		Jobs::act( $r->id(), 'tidy', array() );
		$this->assertTrue( Resource::find( $r->id() )->get( Jobs::RESULT )['ok'] );
		$this->assertSame( array( 'tidy' ), $seen, 'The follow-ups of a done action run.' );

		// Done as a refusal (the driver says so): not ok, and no follow-ups.
		$seen = array();
		Progress::mark( $r, 'tidy', 'Tidy up' );
		Jobs::act( $r->id(), 'tidy', array() );
		$this->assertFalse( Resource::find( $r->id() )->get( Jobs::RESULT )['ok'] );
		$this->assertSame( array(), $seen );
	}

	public function test_a_customer_action_that_ended_well_fires_the_done_hook_after_its_marker_is_gone_and_a_failure_does_not(): void {
		list( $r, $driver ) = $this->resource( array( 'state' => 'ready', 'desired' => 'ready' ) );
		$driver->customer_actions = array( 'tidy' => 'Tidy up' );
		$seen                     = array();
		add_action(
			'beltoft_services_action_done',
			static function ( $res, $action, $args ) use ( &$seen ) {
				$seen[] = array( $action, $args, null === Progress::pending( $res ), $res->get( Jobs::RESULT )['ok'] );
			},
			10,
			3
		);

		// The driver answers done, then a failure, then an expected refusal (the last answer stays).
		$driver->will( 'act', StepResult::done() )->will( 'act', StepResult::fail( 'broke' ) )->will( 'act', StepResult::fail( 'taken', array( Jobs::TEXT => 'That name is taken.', Jobs::EXPECTED => true ) ) );

		// A follow-up change may be started from the hook: the marker is gone, so the service is free.
		Progress::mark( $r, 'tidy', 'Tidy up' );
		Jobs::act( $r->id(), 'tidy', array( 'x' ) );
		$this->assertSame( array( array( 'tidy', array( 'x' ), true, true ) ), $seen );

		// A failure and an expected refusal do not fire it.
		Jobs::act( $r->id(), 'tidy', array() );
		Jobs::act( $r->id(), 'tidy', array() );
		$this->assertCount( 1, $seen, (string) wp_json_encode( $seen ) );
	}

	public function test_a_refusal_with_a_sentence_is_told_to_the_customer_and_not_to_staff(): void {
		$mails = 0;
		add_filter(
			'pre_wp_mail',
			static function () use ( &$mails ) {
				++$mails;
				return true;
			}
		);
		list( $r, $driver ) = $this->resource( array( 'state' => 'ready', 'desired' => 'ready' ) );
		$driver->customer_actions = array( 'tidy' => 'Tidy up' );
		$driver->will( 'act', StepResult::fail( 'taken', array( Jobs::TEXT => 'That name is taken.', Jobs::EXPECTED => true ) ) );

		Jobs::act( $r->id(), 'tidy', array() );
		$last = Resource::find( $r->id() )->get( Jobs::RESULT );
		$this->assertFalse( $last['ok'] );
		$this->assertSame( 'That name is taken.', $last['text'] );
		$this->assertSame( 0, $mails, 'No alert for something the customer can read and fix.' );
	}

	public function test_a_sentence_alone_does_not_hide_a_real_failure_from_staff(): void {
		$mails = 0;
		add_filter(
			'pre_wp_mail',
			static function () use ( &$mails ) {
				++$mails;
				return true;
			}
		);
		list( $r, $driver ) = $this->resource( array( 'state' => 'ready', 'desired' => 'ready' ) );
		$driver->customer_actions = array( 'tidy' => 'Tidy up' );
		$driver->will( 'act', StepResult::fail( 'broke', array( Jobs::TEXT => 'The password could not be stored.' ) ) );

		Jobs::act( $r->id(), 'tidy', array() );
		$last = Resource::find( $r->id() )->get( Jobs::RESULT );
		$this->assertFalse( $last['ok'] );
		$this->assertSame( 'The password could not be stored.', $last['text'], 'The customer reads the sentence.' );
		$this->assertSame( 1, $mails, 'Staff are mailed once.' );
		$this->assertNull( Resource::find( $r->id() )->get( Jobs::EXPECTED ), 'The flag is not stored.' );
	}

	public function test_a_failure_without_a_sentence_still_alerts_staff_with_the_fixed_text(): void {
		$mails = 0;
		add_filter(
			'pre_wp_mail',
			static function () use ( &$mails ) {
				++$mails;
				return true;
			}
		);
		list( $r, $driver ) = $this->resource( array( 'state' => 'ready', 'desired' => 'ready' ) );
		$driver->customer_actions = array( 'tidy' => 'Tidy up' );
		$driver->will( 'act', StepResult::fail( 'broke' ) );

		Jobs::act( $r->id(), 'tidy', array() );
		$last = Resource::find( $r->id() )->get( Jobs::RESULT );
		$this->assertFalse( $last['ok'] );
		$this->assertSame( 'This did not finish. We have been told and will look into it.', $last['text'] );
		$this->assertGreaterThan( 0, $mails );
	}

	public function test_start_action_marks_queues_and_refuses_while_another_action_is_pending(): void {
		list( $r, $driver ) = $this->resource( array( 'state' => 'ready', 'desired' => 'ready' ) );
		$driver->customer_actions = array( 'tidy' => 'Tidy up' );

		$this->assertTrue( Jobs::start_action( $r, 'tidy', 'Tidy up', array( 'x' => 1 ) ) );
		$this->assertSame( 'tidy', Progress::pending( Resource::find( $r->id() ) )['action'] );
		$this->assertTrue( as_has_scheduled_action( Jobs::ACT, array( $r->id(), 'tidy', array( 'x' => 1 ) ), Jobs::GROUP ) );

		$prepared = false;
		$second   = Jobs::start_action(
			$r,
			'tidy',
			'Tidy up',
			array( 'x' => 2 ),
			static function () use ( &$prepared ) {
				$prepared = true;
				return true;
			}
		);
		$this->assertWPError( $second );
		$this->assertSame( 'busy', $second->get_error_code() );
		$this->assertSame( 'Wait until tidy up is done.', $second->get_error_message(), 'The marker label, first letter lower case.' );
		$this->assertFalse( $prepared, 'Nothing is stored for an action that is refused.' );
		$this->assertFalse( as_has_scheduled_action( Jobs::ACT, array( $r->id(), 'tidy', array( 'x' => 2 ) ), Jobs::GROUP ) );
	}

	public function test_start_action_runs_the_preparation_first_and_an_error_of_it_refuses_the_action(): void {
		list( $r, $driver ) = $this->resource( array( 'state' => 'ready', 'desired' => 'ready' ) );
		$driver->customer_actions = array( 'tidy' => 'Tidy up' );

		$refused = Jobs::start_action( $r, 'tidy', 'Tidy up', array(), static fn() => new \WP_Error( 'nope', 'Not now.', array( 'status' => 422 ) ) );
		$this->assertWPError( $refused );
		$this->assertSame( 'nope', $refused->get_error_code() );
		$this->assertNull( Progress::pending( Resource::find( $r->id() ) ), 'No marker, no job.' );
		$this->assertFalse( as_has_scheduled_action( Jobs::ACT, array( $r->id(), 'tidy', array() ), Jobs::GROUP ) );

		$stored = Jobs::start_action(
			$r,
			'tidy',
			'Tidy up',
			array(),
			static function () use ( $r ) {
				return $r->set( array( 'prepared' => 'yes' ) );
			}
		);
		$this->assertTrue( $stored );
		$this->assertSame( 'yes', Resource::find( $r->id() )->get( 'prepared' ) );
		$this->assertNotNull( Progress::pending( Resource::find( $r->id() ) ) );
	}

	public function test_the_queued_answer_says_how_to_read_the_outcome(): void {
		list( $r ) = $this->resource( array( 'state' => 'ready', 'desired' => 'ready' ) );
		$answer    = \BeltoftServices\Api\Routes::queued( $r, 'tidy', array( 'extra' => 'x' ), 'On its way.' );
		$data      = $answer->get_data();

		$this->assertSame( 202, $answer->get_status() );
		$this->assertTrue( $data['queued'] );
		$this->assertSame( 'tidy', $data['action'] );
		$this->assertSame( '/resources/' . $r->id(), $data['status'] );
		$this->assertSame( 'On its way.', $data['message'] );
		$this->assertSame( 'x', $data['extra'] );
		$this->assertEqualsWithDelta( time(), $data['at'], 5 );
		$this->assertStringContainsString( 'no-store', (string) $answer->get_headers()['Cache-Control'] );

		$busy = \BeltoftServices\Api\Routes::not_queued( new \WP_Error( 'busy', 'Wait until tidying is done.' ) );
		$this->assertSame( 409, $busy->get_status() );
		$this->assertSame( 'beltoft_services_busy', $busy->get_data()['code'] );
		$this->assertSame( 422, \BeltoftServices\Api\Routes::not_queued( new \WP_Error( 'x', 'No.', array( 'status' => 422 ) ) )->get_status() );
	}
}

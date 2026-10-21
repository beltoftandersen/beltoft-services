<?php
/**
 * Jobs: queueing, the lock, the kill switch and the sweep.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests;

use BeltoftServices\Drivers\StepResult;
use BeltoftServices\Jobs;
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
		$this->assertEqualsWithDelta( $before + 60, $pending[0], 2 );

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
		$this->assertNotFalse( has_action( 'action_scheduler_init', array( Jobs::class, 'schedule' ) ) );
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
}

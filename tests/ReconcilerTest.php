<?php
/**
 * The reconciler: one driver operation per step, retries, failures and alerts.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests;

use BeltoftServices\Drivers\StepResult;
use BeltoftServices\Jobs;
use BeltoftServices\Reconciler;
use BeltoftServices\Resource;
use BeltoftServices\Tests\Support\FakeDriver;

/**
 * Reconciler tests.
 */
final class ReconcilerTest extends TestCase {

	/**
	 * Mails caught by pre_wp_mail.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private $mails = array();

	public function set_up(): void {
		parent::set_up();

		$this->mails = array();
		add_filter( 'pre_wp_mail', array( $this, 'catch_mail' ), 10, 2 );

		// The recurring sweep is tested in JobsTest; keep it out of these runs.
		as_unschedule_all_actions( Jobs::SWEEP, array(), Jobs::GROUP );
	}

	/**
	 * Records a mail instead of sending it.
	 *
	 * @param mixed                $pre  Short-circuit value.
	 * @param array<string, mixed> $atts Mail arguments.
	 * @return bool
	 */
	public function catch_mail( $pre, $atts ) {
		// Only the admin alerts: the customer's service emails are tested in EmailsTest.
		$to = is_array( $atts['to'] ) ? implode( ',', $atts['to'] ) : (string) $atts['to'];
		if ( false === strpos( $to, 'customer@example.com' ) ) {
			$this->mails[] = $atts;
		}
		return true;
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
		$resource = Resource::create( $license, $driver, array( 'ram' => 2 ), $meta );

		$this->assertInstanceOf( Resource::class, $resource );

		return array( $resource, $driver );
	}

	/**
	 * The scheduled time of the pending reconcile action of a resource, or null.
	 *
	 * @param int $id Activation id.
	 */
	private function pending_at( int $id ): ?int {
		$actions = as_get_scheduled_actions(
			array(
				'hook'     => Jobs::HOOK,
				'args'     => array( $id ),
				'group'    => Jobs::GROUP,
				'status'   => \ActionScheduler_Store::STATUS_PENDING,
				'per_page' => 1,
			)
		);

		if ( ! $actions ) {
			return null;
		}

		$date = reset( $actions )->get_schedule()->get_date();

		return $date ? $date->getTimestamp() : time();
	}

	/**
	 * Makes the resource's retry time due now (as if the backoff had passed).
	 *
	 * @param Resource $r Resource.
	 */
	private function make_due( Resource $r ): void {
		Resource::find( $r->id() )->set( array( 'next_attempt_at' => time() - 1 ) );
	}

	/**
	 * What an admin "Retry now" does: reset the count and queue the resource.
	 *
	 * @param Resource $r Resource.
	 */
	private function admin_retry( Resource $r ): void {
		Resource::find( $r->id() )->set( array( 'attempts' => 0 ) );
		Jobs::enqueue( $r->id() );
	}

	public function test_provision_done_makes_ready(): void {
		list( $resource, $driver ) = $this->resource();
		$driver->will( 'provision', StepResult::done( array( 'remote_id' => 'vm-1' ), 'Server created' ) );

		$changes = array();
		add_action(
			'beltoft_services_state_changed',
			static function ( $r, $old, $new ) use ( &$changes ) {
				$changes[] = array( $r->id(), $old, $new );
			},
			10,
			3
		);

		Jobs::enqueue( $resource->id() );
		$this->run_jobs();

		$fresh = Resource::find( $resource->id() );
		$this->assertCount( 1, $driver->calls( 'provision' ) );
		$this->assertCount( 1, $driver->calls() );
		$this->assertSame( 'ready', $fresh->get( 'state' ) );
		$this->assertSame( 'vm-1', $fresh->get( 'remote_id' ) );
		$this->assertSame( 0, $fresh->get( 'attempts' ) );
		$this->assertSame(
			array(
				array( $resource->id(), 'requested', 'provisioning' ),
				array( $resource->id(), 'provisioning', 'ready' ),
			),
			$changes
		);
		$this->assertNull( $this->pending_at( $resource->id() ) );

		$messages = wp_list_pluck( \BeltoftLicenses\Events::for_license( (int) $resource->license()['id'] ), 'message' );
		$this->assertContains( 'Server created', $messages );
	}

	public function test_retry_backoff_and_fail_after_five(): void {
		list( $resource, $driver ) = $this->resource();
		$driver->will( 'provision', StepResult::retry( 0, 'Not yet' ) );

		$expected = array( 60, 300, 900, 3600, 14400 );

		foreach ( $expected as $index => $backoff ) {
			$this->make_due( $resource );
			$before = time();
			Jobs::run( $resource->id() );
			$fresh = Resource::find( $resource->id() );

			$this->assertSame( 'provisioning', $fresh->get( 'state' ), "Still provisioning after attempt $index." );
			$this->assertSame( $index + 1, $fresh->get( 'attempts' ) );
			$this->assertSame( 'Not yet', $fresh->get( 'last_error' ) );
			$this->assertGreaterThanOrEqual( $before + $backoff, $fresh->get( 'next_attempt_at' ) );
			$this->assertLessThanOrEqual( time() + $backoff, $fresh->get( 'next_attempt_at' ) );

			// Each retry reschedules the job at its backoff.
			$this->assertEqualsWithDelta( $before + $backoff, $this->pending_at( $resource->id() ), 2 );
			as_unschedule_all_actions( Jobs::HOOK, array( $resource->id() ), Jobs::GROUP );
		}

		// The sixth try fails for good.
		$this->make_due( $resource );
		Jobs::run( $resource->id() );
		$fresh = Resource::find( $resource->id() );

		$this->assertSame( 'failed', $fresh->get( 'state' ) );
		$this->assertSame( 6, $fresh->get( 'attempts' ) );
		$this->assertSame( 'Not yet', $fresh->get( 'last_error' ) );
		$this->assertSame( 'provision', $fresh->get( 'failed_op' ) );
		$this->assertNull( $this->pending_at( $resource->id() ) );
		$this->assertCount( 6, $driver->calls( 'provision' ) );
		$this->assertCount( 1, $this->mails );
	}

	public function test_retry_uses_the_longer_of_driver_delay_and_backoff(): void {
		list( $resource, $driver ) = $this->resource();
		$driver->will( 'provision', StepResult::retry( 7200, 'Slow' ) );

		$before = time();
		Jobs::run( $resource->id() );

		$this->assertGreaterThanOrEqual( $before + 7200, Resource::find( $resource->id() )->get( 'next_attempt_at' ) );
	}

	public function test_medusa_retry_count_survives_failures(): void {
		list( $resource, $driver ) = $this->resource();
		$driver->will( 'provision', StepResult::retry( 0, 'Timeout' ) )
			->will( 'provision', StepResult::retry( 0, 'Timeout' ) )
			->will( 'provision', StepResult::fail( 'Quota exceeded' ) )
			->will( 'provision', StepResult::retry( 0, 'Timeout' ) );

		Jobs::run( $resource->id() );
		$this->make_due( $resource );
		Jobs::run( $resource->id() );
		$this->assertSame( 2, Resource::find( $resource->id() )->get( 'attempts' ) );

		$this->make_due( $resource );
		Jobs::run( $resource->id() );
		$failed = Resource::find( $resource->id() );
		$this->assertSame( 'failed', $failed->get( 'state' ) );
		$this->assertSame( 'Quota exceeded', $failed->get( 'last_error' ) );
		$this->assertSame( 3, $failed->get( 'attempts' ), 'A failure never resets the count.' );

		// A failed operation is not retried by itself: more runs make no driver call.
		Jobs::run( $resource->id() );
		$this->run_jobs();
		$this->assertCount( 3, $driver->calls( 'provision' ) );
		$this->assertSame( 3, Resource::find( $resource->id() )->get( 'attempts' ) );

		// The admin "Retry now" resets the count, and the failed operation runs again.
		$this->admin_retry( $resource );
		$this->run_jobs();
		$this->assertCount( 4, $driver->calls( 'provision' ) );
		$this->assertSame( 1, Resource::find( $resource->id() )->get( 'attempts' ) );
	}

	public function test_attempts_count_per_operation(): void {
		list( $resource, $driver ) = $this->resource();
		$driver->will( 'provision', StepResult::retry( 0, 'Timeout' ) );
		$driver->will( 'pause', StepResult::retry( 0, 'Busy' ) );

		Jobs::run( $resource->id() );
		$this->make_due( $resource );
		Jobs::run( $resource->id() );
		$this->assertSame( 2, Resource::find( $resource->id() )->get( 'attempts' ) );

		Resource::find( $resource->id() )->desire( 'paused', 'License revoked' );
		Jobs::run( $resource->id() );

		$fresh = Resource::find( $resource->id() );
		$this->assertSame( 'pause', $fresh->get( 'op' ) );
		$this->assertSame( 1, $fresh->get( 'attempts' ), 'A new operation starts its own count.' );
		$this->assertSame( 'Busy', $fresh->get( 'last_error' ) );
	}

	public function test_revoked_during_provision_retry_pauses(): void {
		list( $resource, $driver ) = $this->resource();
		$driver->will( 'provision', StepResult::retry( 0, 'Booting' ) );

		Jobs::run( $resource->id() );
		$this->assertSame( 'provisioning', Resource::find( $resource->id() )->get( 'state' ) );

		Resource::find( $resource->id() )->desire( 'paused', 'License revoked' );
		Jobs::run( $resource->id() );

		$fresh = Resource::find( $resource->id() );
		$this->assertCount( 1, $driver->calls( 'pause' ) );
		$this->assertSame( 'paused', $fresh->get( 'state' ) );
		$this->assertCount( 1, $driver->calls( 'provision' ) );
	}

	public function test_failed_plan_update_then_revoked_pauses(): void {
		list( $resource, $driver ) = $this->resource(
			array(
				'state'      => 'ready',
				'plan_dirty' => true,
			)
		);
		$driver->will( 'update_plan', StepResult::fail( 'Resize refused' ) );

		Jobs::run( $resource->id() );
		$failed = Resource::find( $resource->id() );
		$this->assertSame( 'failed', $failed->get( 'state' ) );
		$this->assertSame( 'update_plan', $failed->get( 'failed_op' ) );

		$failed->desire( 'paused', 'License revoked' );
		Jobs::enqueue( $resource->id() );
		$this->run_jobs();

		$this->assertCount( 1, $driver->calls( 'pause' ) );
		$this->assertSame( 'paused', Resource::find( $resource->id() )->get( 'state' ) );
	}

	public function test_failed_resource_to_delete_gets_its_own_attempts_and_is_swept(): void {
		list( $resource, $driver ) = $this->resource(
			array(
				'state'     => 'failed',
				'failed_op' => 'provision',
				'op'        => 'provision',
				'attempts'  => 6,
			)
		);
		$driver->will( 'delete', StepResult::retry( 0, 'Locked by host' ) );
		$resource->desire( 'deleted', 'Retention over' );

		Jobs::sweep();
		$before = time();
		$this->run_jobs();

		$fresh = Resource::find( $resource->id() );
		$this->assertCount( 1, $driver->calls( 'delete' ) );
		$this->assertSame( 'delete', $fresh->get( 'op' ) );
		$this->assertSame( 1, $fresh->get( 'attempts' ) );
		$this->assertEqualsWithDelta( $before + 60, $fresh->get( 'next_attempt_at' ), 2 );
		$this->assertEqualsWithDelta( $before + 60, $this->pending_at( $resource->id() ), 2 );
	}

	public function test_failed_resume_is_rerun_by_an_admin_retry(): void {
		list( $resource, $driver ) = $this->resource( array( 'state' => 'paused' ) );
		$driver->will( 'resume', StepResult::fail( 'Host down' ) )->will( 'resume', StepResult::done() );

		Jobs::run( $resource->id() );
		$failed = Resource::find( $resource->id() );
		$this->assertSame( 'failed', $failed->get( 'state' ) );
		$this->assertSame( 'resume', $failed->get( 'failed_op' ) );

		$this->admin_retry( $resource );
		$this->run_jobs();

		$this->assertCount( 2, $driver->calls( 'resume' ) );
		$this->assertSame( array(), $driver->calls( 'provision' ) );
		$this->assertSame( 'ready', Resource::find( $resource->id() )->get( 'state' ) );
	}

	public function test_pause_before_anything_exists_needs_no_driver_call(): void {
		list( $resource, $driver ) = $this->resource();
		$resource->desire( 'paused', 'License revoked' );

		Jobs::run( $resource->id() );

		$this->assertSame( array(), $driver->calls() );
		$this->assertSame( 'paused', Resource::find( $resource->id() )->get( 'state' ) );

		// With steps recorded something may exist: the driver pauses it.
		list( $partial, $driver2 ) = $this->resource( array( 'steps' => array( 'vm_created' ) ) );
		$partial->desire( 'paused', 'License revoked' );
		Jobs::run( $partial->id() );
		$this->assertCount( 1, $driver2->calls( 'pause' ) );
	}

	public function test_early_run_waits_for_the_retry_time(): void {
		list( $resource, $driver ) = $this->resource();
		$driver->will( 'provision', StepResult::retry( 0, 'Booting' ) );

		$before = time();
		Jobs::run( $resource->id() );

		// A sweep and a stray job inside the backoff window make no driver call.
		Jobs::sweep();
		Jobs::run( $resource->id() );
		$this->run_jobs();

		$this->assertCount( 1, $driver->calls( 'provision' ) );
		$this->assertEqualsWithDelta( $before + 60, $this->pending_at( $resource->id() ), 2 );
		$this->assertCount(
			1,
			as_get_scheduled_actions(
				array(
					'hook'   => Jobs::HOOK,
					'args'   => array( $resource->id() ),
					'status' => \ActionScheduler_Store::STATUS_PENDING,
				),
				'ids'
			)
		);

		// Once due, the next window allows exactly one more call.
		$this->make_due( $resource );
		Jobs::run( $resource->id() );
		Jobs::run( $resource->id() );
		$this->assertCount( 2, $driver->calls( 'provision' ) );
	}

	public function test_desire_change_does_not_wait_out_a_long_backoff(): void {
		list( $resource, $driver ) = $this->resource();
		$driver->will( 'provision', StepResult::retry( 14400, 'Host busy' ) );

		Jobs::run( $resource->id() );
		$this->assertGreaterThan( time() + 14000, $this->pending_at( $resource->id() ) );

		// What a lifecycle listener does on revocation.
		Resource::find( $resource->id() )->desire( 'paused', 'License revoked' );
		Jobs::enqueue( $resource->id() );
		$this->assertLessThanOrEqual( time(), $this->pending_at( $resource->id() ) );

		$this->run_jobs();

		$this->assertCount( 1, $driver->calls( 'pause' ) );
		$this->assertSame( 'paused', Resource::find( $resource->id() )->get( 'state' ) );
	}

	public function test_unstorable_driver_meta_is_a_retry_not_ready(): void {
		list( $resource, $driver ) = $this->resource();
		$driver->will( 'provision', StepResult::done( array( 'remote_id' => "bad \xB1\x31" ) ) )
			->will( 'provision', StepResult::done( array( 'remote_id' => 'vm-9' ) ) );

		Jobs::run( $resource->id() );

		$fresh = Resource::find( $resource->id() );
		$this->assertSame( 'provisioning', $fresh->get( 'state' ) );
		$this->assertSame( 1, $fresh->get( 'attempts' ) );
		$this->assertSame( Reconciler::META_ERROR, $fresh->get( 'last_error' ) );
		$this->assertSame( '', $fresh->get( 'remote_id' ) );

		$this->make_due( $resource );
		Jobs::run( $resource->id() );
		$fresh = Resource::find( $resource->id() );
		$this->assertSame( 'ready', $fresh->get( 'state' ) );
		$this->assertSame( 'vm-9', $fresh->get( 'remote_id' ) );

		// The same on retry and fail: the attempt is recorded without the meta.
		list( $other, $driver2 ) = $this->resource();
		$driver2->will( 'provision', StepResult::retry( 0, 'Waiting', array( 'x' => "bad \xB1\x31" ) ) );
		Jobs::run( $other->id() );
		$this->assertSame( 1, Resource::find( $other->id() )->get( 'attempts' ) );
		$this->assertSame( Reconciler::META_ERROR, Resource::find( $other->id() )->get( 'last_error' ) );
	}

	public function test_desired_change_mid_flight_is_applied_after_the_step(): void {
		list( $resource, $driver ) = $this->resource();

		// While provisioning runs, the license is revoked: another Resource instance desires paused.
		add_filter(
			'beltoft_services_drivers',
			static function ( $drivers ) use ( $resource ) {
				$drivers['fake'] = new class( $drivers['fake'], $resource->id() ) extends FakeDriver {
					/**
					 * Wrapped driver.
					 *
					 * @var FakeDriver
					 */
					public $inner;

					/**
					 * Activation id.
					 *
					 * @var int
					 */
					private $resource_id;

					public function __construct( FakeDriver $inner, int $resource_id ) {
						$this->inner       = $inner;
						$this->resource_id = $resource_id;
					}

					public function provision( Resource $r ): StepResult {
						Resource::find( $this->resource_id )->desire( 'paused', 'License revoked' );
						return $this->inner->provision( $r );
					}

					public function pause( Resource $r ): StepResult {
						return $this->inner->pause( $r );
					}
				};
				return $drivers;
			},
			20
		);

		Jobs::enqueue( $resource->id() );
		$this->run_jobs();

		$fresh = Resource::find( $resource->id() );
		$this->assertSame( 'paused', $fresh->get( 'desired' ) );
		$this->assertSame( 'paused', $fresh->get( 'state' ) );
		$this->assertCount( 1, $driver->calls( 'provision' ) );
		$this->assertCount( 1, $driver->calls( 'pause' ) );
	}

	public function test_failure_alerts_the_admin_once_per_error(): void {
		$this->settings( array( 'admin_email' => 'ops@example.com' ) );
		list( $resource, $driver ) = $this->resource();
		$driver->will( 'provision', StepResult::fail( 'Host unreachable' ) )
			->will( 'provision', StepResult::fail( 'Host unreachable' ) )
			->will( 'provision', StepResult::fail( 'Disk full' ) );

		Jobs::run( $resource->id() );

		$this->assertCount( 1, $this->mails );
		$license = $resource->license();
		$this->assertSame( 'ops@example.com', $this->mails[0]['to'] );
		$this->assertSame( '[' . wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) . '] Service failed: …' . substr( $license['license_key'], -4 ), $this->mails[0]['subject'] );
		$this->assertStringNotContainsString( $license['license_key'], $this->mails[0]['subject'], 'The key is masked in the subject.' );
		$this->assertStringContainsString( 'Host unreachable', $this->mails[0]['message'] );
		$this->assertStringContainsString( 'fake', $this->mails[0]['message'] );
		$this->assertStringContainsString( (string) $license['id'], $this->mails[0]['message'] );
		$this->assertStringContainsString( 'failed', $this->mails[0]['message'] );

		$this->admin_retry( $resource );
		$this->run_jobs();
		$this->assertCount( 1, $this->mails, 'The same error is not sent twice.' );

		$this->admin_retry( $resource );
		$this->run_jobs();
		$this->assertCount( 3, $driver->calls( 'provision' ) );
		$this->assertCount( 2, $this->mails, 'A new error is sent.' );
		$this->assertStringContainsString( 'Disk full', $this->mails[1]['message'] );
	}

	public function test_alerts_again_after_a_recovery(): void {
		list( $resource, $driver ) = $this->resource();
		$driver->will( 'provision', StepResult::fail( 'Boom' ) )->will( 'provision', StepResult::done() );
		$driver->will( 'pause', StepResult::fail( 'Boom' ) );

		Jobs::run( $resource->id() );
		$this->admin_retry( $resource );
		$this->run_jobs();
		$this->assertSame( 'ready', Resource::find( $resource->id() )->get( 'state' ) );

		Resource::find( $resource->id() )->desire( 'paused', 'Suspended' );
		Jobs::run( $resource->id() );

		$this->assertCount( 2, $this->mails );
	}

	public function test_operation_table(): void {
		// state, desired, plan_dirty, failed_op, attempts, steps, expected operation.
		$cases = array(
			array( 'requested', 'ready', false, '', 0, false, 'provision' ),
			array( 'provisioning', 'ready', false, '', 2, false, 'provision' ),
			array( 'failed', 'ready', false, 'provision', 0, false, 'provision' ),
			array( 'failed', 'ready', false, 'provision', 3, false, null ),
			array( 'failed', 'ready', false, 'resume', 0, false, 'resume' ),
			array( 'failed', 'ready', false, 'update_plan', 0, false, 'update_plan' ),
			array( 'failed', 'ready', false, 'pause', 2, false, 'resume' ),
			array( 'failed', 'ready', false, 'delete', 1, false, 'resume' ),
			array( 'failed', 'ready', false, '', 0, false, null ),
			array( 'paused', 'ready', false, '', 0, false, 'resume' ),
			array( 'paused', 'ready', true, '', 0, false, 'resume' ),
			array( 'ready', 'ready', true, '', 0, false, 'update_plan' ),
			array( 'ready', 'ready', false, '', 0, false, null ),
			array( 'deleting', 'ready', false, '', 0, false, null ),
			array( 'ready', 'paused', false, '', 0, false, 'pause' ),
			array( 'provisioning', 'paused', false, '', 0, false, 'pause' ),
			array( 'failed', 'paused', false, 'provision', 6, false, 'pause' ),
			array( 'failed', 'paused', false, 'pause', 2, false, null ),
			array( 'requested', 'paused', false, '', 0, false, 'pause' ),
			array( 'requested', 'paused', false, '', 0, true, 'pause' ),
			array( 'paused', 'paused', true, '', 0, false, null ),
			array( 'deleting', 'paused', false, '', 0, false, null ),
			array( 'ready', 'deleted', false, '', 0, false, 'delete' ),
			array( 'paused', 'deleted', false, '', 0, false, 'delete' ),
			array( 'requested', 'deleted', false, '', 0, false, 'delete' ),
			array( 'provisioning', 'deleted', false, '', 0, false, 'delete' ),
			array( 'deleting', 'deleted', false, '', 0, false, 'delete' ),
			array( 'failed', 'deleted', false, 'provision', 6, false, 'delete' ),
			array( 'failed', 'deleted', false, 'delete', 1, false, null ),
			array( 'deleted', 'deleted', false, '', 0, false, null ),
		);

		list( $resource ) = $this->resource();

		foreach ( $cases as $case ) {
			list( $state, $desired, $dirty, $failed_op, $attempts, $steps, $operation ) = $case;
			$resource->set(
				array(
					'state'          => $state,
					'desired'        => $desired,
					'plan_dirty'     => $dirty,
					'failed_op'      => $failed_op,
					'failed_desired' => $desired,
					'op'             => $failed_op,
					'attempts'       => $attempts,
					'steps'          => $steps ? array( 'created' ) : array(),
				)
			);

			$this->assertSame( $operation, Reconciler::operation( $resource ), "$state → $desired" . ( $dirty ? ' (plan dirty)' : '' ) . ( $failed_op ? " (failed $failed_op, $attempts attempts)" : '' ) . ( $steps ? ' (with steps)' : '' ) );
		}

		// The admin wait holds only under the desired state the resource failed under.
		$resource->set(
			array(
				'state'          => 'failed',
				'desired'        => 'ready',
				'failed_op'      => 'provision',
				'failed_desired' => 'paused',
				'op'             => 'provision',
				'attempts'       => 3,
			)
		);
		$this->assertSame( 'provision', Reconciler::operation( $resource ) );
	}

	public function test_alerts_once_per_operation_and_error(): void {
		list( $resource, $driver ) = $this->resource();
		$driver->will( 'provision', StepResult::fail( 'Host unreachable' ) );
		$driver->will( 'pause', StepResult::retry( 0, 'Host unreachable' ) );

		Jobs::run( $resource->id() );
		$this->assertCount( 1, $this->mails );

		Resource::find( $resource->id() )->desire( 'paused', 'License revoked' );

		for ( $i = 0; $i < 6; $i++ ) {
			$this->make_due( $resource );
			Jobs::run( $resource->id() );
		}

		$fresh = Resource::find( $resource->id() );
		$this->assertSame( 'failed', $fresh->get( 'state' ) );
		$this->assertSame( 'pause', $fresh->get( 'failed_op' ) );
		$this->assertCount( 6, $driver->calls( 'pause' ) );
		$this->assertCount( 2, $this->mails, 'The failed pause is alerted although the error text is the same.' );
		$this->assertStringContainsString( 'Operation: pause', $this->mails[1]['message'] );
	}

	public function test_invalid_admin_email_keeps_the_alert_for_later(): void {
		$this->settings( array( 'admin_email' => 'not-an-address' ) );
		list( $resource, $driver ) = $this->resource();
		$driver->will( 'provision', StepResult::fail( 'Host unreachable' ) );

		Jobs::run( $resource->id() );

		$this->assertSame( array(), $this->mails );
		$this->assertSame( '', (string) Resource::find( $resource->id() )->get( 'alerted' ) );
		$problems = \BeltoftServices\Alerts::problems();
		$this->assertArrayHasKey( $resource->id(), $problems );
		$this->assertSame( $resource->id(), $problems[ $resource->id() ]['resource'] );

		$this->settings( array( 'admin_email' => 'ops@example.com' ) );
		$this->admin_retry( $resource );
		$this->run_jobs();

		$this->assertCount( 1, $this->mails );
		$this->assertSame( 'ops@example.com', $this->mails[0]['to'] );
		$this->assertFalse( get_option( \BeltoftServices\Alerts::PROBLEM_OPTION ) );
	}

	public function test_failed_pause_reinstated_then_revoked_again(): void {
		list( $resource, $driver ) = $this->resource( array( 'state' => 'ready' ) );
		for ( $i = 0; $i < 6; $i++ ) {
			$driver->will( 'pause', StepResult::retry( 0, 'Host unreachable' ) );
		}
		$driver->will( 'pause', StepResult::fail( 'Host unreachable' ) );

		$resource->desire( 'paused', 'License revoked' );
		for ( $i = 0; $i < 6; $i++ ) {
			$this->make_due( $resource );
			Jobs::run( $resource->id() );
		}

		$this->assertSame( 'failed', Resource::find( $resource->id() )->get( 'state' ) );
		$this->assertCount( 1, $this->mails );

		// Waiting for the admin: nothing runs by itself.
		Jobs::run( $resource->id() );
		$this->assertCount( 6, $driver->calls( 'pause' ) );

		// Reinstated: the failed pause leaves the service running, so it resumes.
		Resource::find( $resource->id() )->desire( 'ready', 'License reinstated' );
		Jobs::enqueue( $resource->id() );
		$this->run_jobs();
		$this->assertCount( 1, $driver->calls( 'resume' ) );
		$this->assertSame( 'ready', Resource::find( $resource->id() )->get( 'state' ) );

		// Revoked again: pause runs with a fresh count and its failure is alerted.
		Resource::find( $resource->id() )->desire( 'paused', 'License revoked' );
		Jobs::enqueue( $resource->id() );
		$this->run_jobs();

		$fresh = Resource::find( $resource->id() );
		$this->assertCount( 7, $driver->calls( 'pause' ) );
		$this->assertSame( 'failed', $fresh->get( 'state' ) );
		$this->assertSame( 1, $fresh->get( 'attempts' ) );
		$this->assertCount( 2, $this->mails );
	}

	public function test_failed_bookkeeping_write_is_logged_and_not_requeued(): void {
		list( $resource ) = $this->resource();
		$table            = \BeltoftLicenses\Install::tables()['activations'];
		$break            = static function ( $query ) use ( $table ) {
			return 0 === strpos( ltrim( $query ), 'UPDATE `' . $table . '`' ) ? '' : $query;
		};

		add_filter(
			'beltoft_services_drivers',
			static function ( $drivers ) use ( $break ) {
				$drivers['fake'] = new class( $break ) extends FakeDriver {
					/**
					 * Query filter.
					 *
					 * @var callable
					 */
					private $break;

					public function __construct( callable $brk ) {
						$this->break = $brk;
					}

					public function provision( Resource $r ): StepResult {
						add_filter( 'query', $this->break );
						return StepResult::retry( 0, 'Booting' );
					}
				};
				return $drivers;
			},
			20
		);

		$log  = wp_tempnam( 'bs-log' );
		$prev = ini_set( 'error_log', $log ); // phpcs:ignore WordPress.PHP.IniSet.Risky

		Jobs::run( $resource->id() );
		Jobs::run( $resource->id() );

		ini_set( 'error_log', $prev ); // phpcs:ignore WordPress.PHP.IniSet.Risky
		remove_filter( 'query', $break );
		$logged = (string) file_get_contents( $log ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		wp_delete_file( $log );

		$this->assertNull( $this->pending_at( $resource->id() ), 'Nothing loops on a broken write.' );
		$this->assertSame( 1, substr_count( $logged, 'resource ' . $resource->id() . ' could not be updated' ) );
		$this->assertCount( 1, $this->mails, 'The admin is told, at most once an hour.' );
		$this->assertStringContainsString( 'Resource could not be updated', $this->mails[0]['message'] );
		$this->assertNotFalse( get_transient( 'beltoft_services_not_stored_' . $resource->id() ) );
	}

	public function test_sweep_sends_an_alert_once_the_address_is_fixed(): void {
		$this->settings( array( 'admin_email' => '' ) );
		list( $resource, $driver ) = $this->resource();
		$driver->will( 'provision', StepResult::fail( 'Host unreachable' ) );

		Jobs::run( $resource->id() );
		$this->assertSame( array(), $this->mails );

		$this->settings( array( 'admin_email' => 'ops@example.com' ) );
		Jobs::sweep();

		$this->assertCount( 1, $this->mails );
		$this->assertStringContainsString( 'Host unreachable', $this->mails[0]['message'] );
		$this->assertSame( array(), \BeltoftServices\Alerts::problems() );

		Jobs::sweep();
		$this->assertCount( 1, $this->mails, 'Sent once.' );
		$this->assertCount( 1, $driver->calls( 'provision' ), 'The sweep does not retry the failed operation.' );
	}

	public function test_problem_record_is_kept_per_resource(): void {
		$this->settings( array( 'admin_email' => '' ) );
		list( $first, $driver ) = $this->resource();
		$driver->will( 'provision', StepResult::fail( 'Host unreachable' ) );
		Jobs::run( $first->id() );

		list( $second, $driver2 ) = $this->resource();
		$driver2->will( 'provision', StepResult::fail( 'Host unreachable' ) );
		Jobs::run( $second->id() );
		$this->assertCount( 2, \BeltoftServices\Alerts::problems() );

		$this->settings( array( 'admin_email' => 'ops@example.com' ) );
		\BeltoftServices\Alerts::admin( Resource::find( $second->id() ), 'Host unreachable' );

		$this->assertSame( array( $first->id() ), array_keys( \BeltoftServices\Alerts::problems() ) );
	}

	public function test_driver_retry_delay_is_capped_at_the_longest_backoff(): void {
		list( $resource, $driver ) = $this->resource();
		$driver->will( 'provision', StepResult::retry( 999999, 'Come back next month' ) );

		Jobs::run( $resource->id() );

		$this->assertLessThanOrEqual( time() + 14400, Resource::find( $resource->id() )->get( 'next_attempt_at' ) );
		$this->assertLessThanOrEqual( time() + 14400, $this->pending_at( $resource->id() ) );
	}

	public function test_renewal_during_a_retrying_delete_makes_a_new_service(): void {
		list( $resource, $driver ) = $this->resource( array( 'state' => 'paused' ) );
		$driver->will( 'delete', StepResult::retry( 0, 'Host busy' ) )->will( 'delete', StepResult::done() );
		$license_id = (int) $resource->license()['id'];

		$this->assertTrue( $resource->desire( 'deleted', 'Retention over', true ) );
		$this->assertTrue( Resource::find( $resource->id() )->get( 'replace_after_delete' ) );
		Jobs::run( $resource->id() );
		$this->assertSame( 'deleting', Resource::find( $resource->id() )->get( 'state' ) );

		// The customer renews: deleted is terminal, the history says what happens.
		$this->assertFalse( Resource::find( $resource->id() )->desire( 'ready', 'Subscription renewed' ) );
		$this->assertSame( 'deleted', Resource::find( $resource->id() )->get( 'desired' ) );
		$this->assertContains( 'Deletion in progress. A new service will follow when it completes.', wp_list_pluck( \BeltoftLicenses\Events::for_license( $license_id ), 'message' ) );

		$this->make_due( $resource );
		Jobs::run( $resource->id() );

		$this->assertFalse( Resource::find( $resource->id() )->is_live() );
		$replacement = Resource::for_license( $license_id );
		$this->assertNotNull( $replacement );
		$this->assertNotSame( $resource->id(), $replacement->id() );

		$this->run_jobs();

		$fresh = Resource::find( $replacement->id() );
		$this->assertSame( 'ready', $fresh->get( 'state' ) );
		$this->assertSame( 'ready', $fresh->get( 'desired' ) );
		$this->assertCount( 1, $driver->calls( 'provision' ) );
	}

	public function test_deletion_without_replace_on_an_active_license_creates_nothing(): void {
		list( $resource, $driver ) = $this->resource( array( 'state' => 'paused' ) );
		$driver->will( 'delete', StepResult::retry( 0, 'Host busy' ) )->will( 'delete', StepResult::done() );
		$license_id = (int) $resource->license()['id'];

		$this->assertTrue( $resource->desire( 'deleted', 'Deleted by the admin' ) );
		$this->assertFalse( Resource::find( $resource->id() )->get( 'replace_after_delete' ) );
		Jobs::run( $resource->id() );

		$this->assertFalse( Resource::find( $resource->id() )->desire( 'ready', 'Subscription renewed' ) );
		$messages = wp_list_pluck( \BeltoftLicenses\Events::for_license( $license_id ), 'message' );
		$this->assertContains( 'Deletion in progress.', $messages );
		$this->assertNotContains( 'Deletion in progress. A new service will follow when it completes.', $messages );

		$this->make_due( $resource );
		Jobs::run( $resource->id() );
		$this->run_jobs();

		$this->assertSame( 'deleted', Resource::find( $resource->id() )->get( 'state' ) );
		$this->assertNull( Resource::for_license( $license_id ), 'The license is active, but the deletion was not a replacement.' );
		$this->assertSame( array(), $driver->calls( 'provision' ) );
		$this->assertSame( array(), $this->mails );
	}

	public function test_deleted_terminal_text_names_no_replacement_on_an_inactive_license(): void {
		list( $resource ) = $this->resource( array( 'state' => 'paused' ) );
		$license_id       = (int) $resource->license()['id'];

		$resource->desire( 'deleted', 'Retention over', true );
		\BeltoftLicenses\Licenses::update( $license_id, array( 'status' => \BeltoftLicenses\Licenses::STATUS_SUSPENDED ) );
		$this->assertFalse( Resource::find( $resource->id() )->desire( 'paused', 'Payment failed' ) );

		$this->assertContains( 'Deletion in progress.', wp_list_pluck( \BeltoftLicenses\Events::for_license( $license_id ), 'message' ) );
	}

	public function test_failed_replacement_after_deletion_alerts_the_admin(): void {
		list( $resource, $driver ) = $this->resource( array( 'state' => 'paused' ) );
		$resource->desire( 'deleted', 'Retention over', true );
		$license_id = (int) $resource->license()['id'];

		global $wpdb;
		$other = new \mysqli( DB_HOST, DB_USER, DB_PASSWORD, DB_NAME ); // phpcs:ignore WordPress.DB.RestrictedClasses.mysql__mysqli -- a second connection holds the license lock.
		$held  = $other->query( $wpdb->prepare( 'SELECT GET_LOCK( %s, 1 )', \BeltoftServices\Locks::name( 'license', $license_id ) ) );
		$this->assertSame( '1', $held->fetch_row()[0] );
		add_filter( 'beltoft_services_lock_timeout', '__return_zero' );

		Jobs::run( $resource->id() );
		$other->close();

		$this->assertSame( 'deleted', Resource::find( $resource->id() )->get( 'state' ) );
		$this->assertNull( Resource::for_license( $license_id ) );
		$this->assertCount( 1, $this->mails );
		$this->assertStringContainsString( 'A new service could not be created after the deletion: Another request is creating this resource', $this->mails[0]['message'] );
	}

	public function test_replacement_without_a_driver_alerts_the_admin(): void {
		list( $resource ) = $this->resource( array( 'state' => 'paused' ) );
		$resource->desire( 'deleted', 'Retention over', true );
		$license_id = (int) $resource->license()['id'];

		// The driver's plugin goes away while the deletion runs.
		add_filter(
			'beltoft_services_drivers',
			static function ( $drivers ) {
				$drivers['fake'] = new class() extends FakeDriver {
					public function delete( Resource $r ): StepResult {
						remove_all_filters( 'beltoft_services_drivers' );
						return StepResult::done();
					}
				};
				return $drivers;
			},
			20
		);

		Jobs::run( $resource->id() );

		$this->assertNull( Resource::for_license( $license_id ) );
		$this->assertCount( 1, $this->mails );
		$this->assertStringContainsString( 'no driver is available for the product', $this->mails[0]['message'] );
	}

	public function test_dunning_during_a_retrying_delete_still_deletes(): void {
		list( $resource, $driver ) = $this->resource( array( 'state' => 'paused' ) );
		$driver->will( 'delete', StepResult::retry( 0, 'Host busy' ) )->will( 'delete', StepResult::done() );
		$license_id = (int) $resource->license()['id'];

		$resource->desire( 'deleted', 'Retention over' );
		Jobs::run( $resource->id() );

		\BeltoftLicenses\Licenses::update( $license_id, array( 'status' => \BeltoftLicenses\Licenses::STATUS_SUSPENDED ) );
		$this->assertFalse( Resource::find( $resource->id() )->desire( 'paused', 'Payment failed' ) );

		$this->make_due( $resource );
		Jobs::run( $resource->id() );

		$this->assertSame( 'deleted', Resource::find( $resource->id() )->get( 'state' ) );
		$this->assertNull( Resource::for_license( $license_id ), 'No new service on a suspended license.' );
		$this->assertSame( array(), $driver->calls( 'provision' ) );
	}

	public function test_desire_while_a_job_holds_the_lock_is_applied_by_the_next_step(): void {
		list( $resource, $driver ) = $this->resource( array( 'state' => 'ready' ) );

		global $wpdb;
		$other = new \mysqli( DB_HOST, DB_USER, DB_PASSWORD, DB_NAME ); // phpcs:ignore WordPress.DB.RestrictedClasses.mysql__mysqli -- a second connection holds the lock.
		$held  = $other->query( $wpdb->prepare( 'SELECT GET_LOCK( %s, 1 )', \BeltoftServices\Locks::name( 'resource', $resource->id() ) ) );
		$this->assertSame( '1', $held->fetch_row()[0] );

		$this->assertFalse( $resource->desire( 'paused', 'License revoked' ) );
		$other->close();

		$fresh = Resource::find( $resource->id() );
		$this->assertSame( 'ready', $fresh->get( 'desired' ) );
		$this->assertSame( 'paused', $fresh->pending_desire()['state'] );
		$this->assertFalse( wp_load_alloptions()[ Resource::pending_desire_option( $resource->id() ) ] ?? false, 'Not autoloaded.' );

		// A reconciler meta write cannot drop it.
		$fresh->set( array( 'steps' => array( 'x' ) ) );
		$this->assertNotNull( Resource::find( $resource->id() )->pending_desire() );
		$this->assertNotNull( $this->pending_at( $resource->id() ) );

		$this->run_jobs();

		$fresh = Resource::find( $resource->id() );
		$this->assertSame( 'paused', $fresh->get( 'desired' ) );
		$this->assertNull( $fresh->pending_desire() );
		$this->assertFalse( get_option( Resource::pending_desire_option( $resource->id() ) ) );
		$this->assertSame( 'paused', $fresh->get( 'state' ) );
		$this->assertCount( 1, $driver->calls( 'pause' ) );
		$this->assertContains( 'License revoked', wp_list_pluck( \BeltoftLicenses\Events::for_license( (int) $resource->license()['id'] ), 'message' ) );
	}

	/**
	 * Queues a desired state as a desire() that found the resource lock busy does.
	 *
	 * @param Resource $r      Resource.
	 * @param string   $state  Desired state.
	 * @param string   $reason Reason.
	 */
	private function desire_while_locked( Resource $r, string $state, string $reason ): void {
		global $wpdb;
		$other = new \mysqli( DB_HOST, DB_USER, DB_PASSWORD, DB_NAME ); // phpcs:ignore WordPress.DB.RestrictedClasses.mysql__mysqli -- a second connection holds the lock.
		$held  = $other->query( $wpdb->prepare( 'SELECT GET_LOCK( %s, 1 )', \BeltoftServices\Locks::name( 'resource', $r->id() ) ) );
		$this->assertSame( '1', $held->fetch_row()[0] );

		add_filter( 'beltoft_services_desire_lock_wait', '__return_zero' );
		$this->assertFalse( Resource::find( $r->id() )->desire( $state, $reason ) );
		remove_filter( 'beltoft_services_desire_lock_wait', '__return_zero' );
		$other->close();
	}

	public function test_pending_desire_that_cannot_be_stored_is_kept_and_alerted(): void {
		list( $resource, $driver ) = $this->resource( array( 'state' => 'ready' ) );
		$this->desire_while_locked( $resource, 'paused', 'License revoked' );

		$table = \BeltoftLicenses\Install::tables()['activations'];
		$break = static function ( $query ) use ( $table ) {
			return 0 === strpos( ltrim( $query ), 'UPDATE `' . $table . '`' ) ? '' : $query;
		};

		add_filter( 'query', $break );
		Jobs::run( $resource->id() );
		Jobs::run( $resource->id() );
		remove_filter( 'query', $break );

		$fresh = Resource::find( $resource->id() );
		$this->assertSame( 'ready', $fresh->get( 'desired' ) );
		$this->assertSame( 'paused', $fresh->pending_desire()['state'], 'The revocation is kept for the next step.' );
		$this->assertCount( 1, $this->mails, 'The admin is told, at most once an hour.' );
		$this->assertStringContainsString( 'Desired state "paused" could not be stored', $this->mails[0]['message'] );
		$this->assertNotFalse( get_transient( 'beltoft_services_not_stored_' . $resource->id() ) );

		// The sweep picks it up once the database works again.
		Jobs::sweep();
		$this->run_jobs();

		$fresh = Resource::find( $resource->id() );
		$this->assertSame( 'paused', $fresh->get( 'desired' ) );
		$this->assertSame( 'paused', $fresh->get( 'state' ) );
		$this->assertNull( $fresh->pending_desire() );
		$this->assertCount( 1, $driver->calls( 'pause' ) );
	}

	public function test_newer_pending_desire_written_during_apply_survives(): void {
		list( $resource, $driver ) = $this->resource( array( 'state' => 'ready' ) );
		$this->desire_while_locked( $resource, 'paused', 'License revoked' );

		// While the step applies the pause, another request's desire finds the lock busy (the
		// job holds it on this connection, so that request is played by queue_desire()).
		$table   = \BeltoftLicenses\Install::tables()['activations'];
		$id      = $resource->id();
		$written = false;
		$newer   = static function ( $query ) use ( $table, $id, &$written ) {
			if ( ! $written && 0 === strpos( ltrim( $query ), 'UPDATE `' . $table . '`' ) ) {
				$written = true;
				Resource::find( $id )->queue_desire( 'deleted', 'Retention over' );
			}
			return $query;
		};

		add_filter( 'query', $newer );
		Jobs::run( $resource->id() );
		remove_filter( 'query', $newer );

		$this->assertTrue( $written );
		$fresh = Resource::find( $resource->id() );
		$this->assertSame( 'paused', $fresh->get( 'desired' ) );
		$this->assertSame( 'deleted', $fresh->pending_desire()['state'], 'The newer desire survives.' );

		$this->run_jobs();

		$fresh = Resource::find( $resource->id() );
		$this->assertSame( 'deleted', $fresh->get( 'state' ) );
		$this->assertNull( $fresh->pending_desire() );
		$this->assertCount( 1, $driver->calls( 'delete' ) );
	}

	public function test_older_pending_desire_never_overrides_a_newer_stored_one(): void {
		list( $resource, $driver ) = $this->resource( array( 'state' => 'ready' ) );
		$this->desire_while_locked( $resource, 'paused', 'License revoked' );

		// A later request gets the lock first and stores its own desire.
		$this->assertTrue( Resource::find( $resource->id() )->desire( 'ready', 'License reinstated' ) );
		$this->assertNull( Resource::find( $resource->id() )->pending_desire(), 'The older pending desire is dropped.' );

		$this->run_jobs();

		$this->assertSame( 'ready', Resource::find( $resource->id() )->get( 'desired' ) );
		$this->assertSame( array(), $driver->calls( 'pause' ) );
	}

	public function test_pause_without_steps_stops_when_it_cannot_be_stored(): void {
		list( $resource, $driver ) = $this->resource();
		$resource->desire( 'paused', 'License revoked' );
		$table = \BeltoftLicenses\Install::tables()['activations'];
		$break = static function ( $query ) use ( $table ) {
			return 0 === strpos( ltrim( $query ), 'UPDATE `' . $table . '`' ) ? '' : $query;
		};

		$log  = wp_tempnam( 'bs-log' );
		$prev = ini_set( 'error_log', $log ); // phpcs:ignore WordPress.PHP.IniSet.Risky
		add_filter( 'query', $break );

		Jobs::run( $resource->id() );

		remove_filter( 'query', $break );
		ini_set( 'error_log', $prev ); // phpcs:ignore WordPress.PHP.IniSet.Risky
		$logged = (string) file_get_contents( $log ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		wp_delete_file( $log );

		$this->assertStringContainsString( 'resource ' . $resource->id() . ' could not be updated', $logged );
		$this->assertNull( $this->pending_at( $resource->id() ) );
		$this->assertSame( 'requested', Resource::find( $resource->id() )->get( 'state' ) );
		$this->assertSame( array(), $driver->calls() );
	}

	public function test_pause_resume_and_update_plan(): void {
		list( $resource, $driver ) = $this->resource( array( 'state' => 'ready' ) );

		$resource->desire( 'paused', 'Suspended' );
		Jobs::run( $resource->id() );
		$this->assertSame( 'paused', Resource::find( $resource->id() )->get( 'state' ) );

		// Paused with a new plan: resume first, then the plan follows in the next step.
		$resource->set( array( 'plan_dirty' => true ) );
		$resource->desire( 'ready', 'Reactivated' );
		Jobs::enqueue( $resource->id() );
		$this->run_jobs();

		$fresh = Resource::find( $resource->id() );
		$this->assertSame( 'ready', $fresh->get( 'state' ) );
		$this->assertFalse( $fresh->get( 'plan_dirty' ) );
		$this->assertSame( array( 'pause', 'resume', 'update_plan' ), wp_list_pluck( $driver->calls(), 'op' ) );
	}

	public function test_plan_changed_during_update_stays_dirty(): void {
		list( $resource, $driver ) = $this->resource(
			array(
				'state'      => 'ready',
				'plan_dirty' => true,
			)
		);

		add_filter(
			'beltoft_services_drivers',
			static function ( $drivers ) use ( $resource ) {
				$drivers['fake'] = new class( $resource->id() ) extends FakeDriver {
					/**
					 * Activation id.
					 *
					 * @var int
					 */
					private $resource_id;

					public function __construct( int $resource_id ) {
						$this->resource_id = $resource_id;
					}

					public function update_plan( Resource $r ): StepResult {
						Resource::find( $this->resource_id )->set(
							array(
								'plan'       => array( 'ram' => 8 ),
								'plan_dirty' => true,
							)
						);
						return StepResult::done();
					}
				};
				return $drivers;
			},
			20
		);

		Jobs::run( $resource->id() );

		$fresh = Resource::find( $resource->id() );
		$this->assertTrue( $fresh->get( 'plan_dirty' ) );
		$this->assertNotNull( $this->pending_at( $resource->id() ), 'The newer plan is applied in another step.' );
	}

	public function test_delete_done_closes_the_resource(): void {
		list( $resource, $driver ) = $this->resource( array( 'state' => 'paused' ) );
		$resource->desire( 'deleted', 'Retention over' );

		$changes = array();
		add_action(
			'beltoft_services_state_changed',
			static function ( $r, $old, $new ) use ( &$changes ) {
				$changes[] = array( $old, $new );
			},
			10,
			3
		);

		Jobs::run( $resource->id() );

		$fresh = Resource::find( $resource->id() );
		$this->assertSame( 'deleted', $fresh->get( 'state' ) );
		$this->assertFalse( $fresh->is_live() );
		$this->assertCount( 1, $driver->calls( 'delete' ) );
		$this->assertSame(
			array(
				array( 'paused', 'deleting' ),
				array( 'deleting', 'deleted' ),
			),
			$changes
		);
	}

	public function test_driver_meta_cannot_overwrite_reconciler_state(): void {
		list( $resource, $driver ) = $this->resource();
		$driver->will(
			'provision',
			StepResult::done(
				array(
					'steps'   => array( 'created' ),
					'desired' => 'deleted',
					'uuid'    => 'hijack',
				)
			)
		);

		Jobs::run( $resource->id() );

		$fresh = Resource::find( $resource->id() );
		$this->assertSame( array( 'created' ), $fresh->get( 'steps' ) );
		$this->assertSame( 'ready', $fresh->get( 'desired' ) );
		$this->assertSame( $resource->get( 'uuid' ), $fresh->get( 'uuid' ) );
	}

	public function test_retry_and_fail_merge_driver_meta(): void {
		list( $resource, $driver ) = $this->resource();
		$driver->will( 'provision', StepResult::retry( 0, 'Waiting', array( 'steps' => array( 'a' ) ) ) )
			->will( 'provision', StepResult::fail( 'Broken', array( 'steps' => array( 'a', 'b' ) ) ) );

		Jobs::run( $resource->id() );
		$this->assertSame( array( 'a' ), Resource::find( $resource->id() )->get( 'steps' ) );

		$this->make_due( $resource );
		Jobs::run( $resource->id() );
		$this->assertSame( array( 'a', 'b' ), Resource::find( $resource->id() )->get( 'steps' ) );
	}

	public function test_invalid_resource_is_skipped_and_alerted_once(): void {
		list( $resource, $driver ) = $this->resource();

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- corrupt the row on purpose.
		$wpdb->update( \BeltoftLicenses\Install::tables()['activations'], array( 'meta' => '{}' ), array( 'id' => $resource->id() ) );

		Jobs::run( $resource->id() );
		Jobs::run( $resource->id() );

		$this->assertSame( array(), $driver->calls() );
		$this->assertCount( 1, $this->mails );
		$this->assertStringContainsString( 'Resource metadata missing', $this->mails[0]['message'] );
		$this->assertFalse( Resource::find( $resource->id() )->is_valid() );
	}

	public function test_missing_driver_waits_without_counting_an_attempt(): void {
		list( $resource ) = $this->resource();
		$resource->set( array( 'driver' => 'gone' ) );

		$before = time();
		Jobs::run( $resource->id() );

		$fresh = Resource::find( $resource->id() );
		$this->assertSame( 'requested', $fresh->get( 'state' ) );
		$this->assertSame( 0, $fresh->get( 'attempts' ) );
		$this->assertEqualsWithDelta( $before + 900, $this->pending_at( $resource->id() ), 2 );
		$this->assertCount( 1, $this->mails );
	}

	public function test_queued_desire_that_cannot_be_written_alerts_the_admin(): void {
		list( $resource ) = $this->resource( array( 'state' => 'ready' ) );

		global $wpdb;
		$name  = Resource::pending_desire_option( $resource->id() );
		$table = $wpdb->options;
		$break = static function ( $query ) use ( $table, $name ) {
			return 0 === strpos( ltrim( $query ), 'INSERT INTO `' . $table . '`' ) && false !== strpos( $query, $name ) ? '' : $query;
		};

		add_filter( 'query', $break );
		$this->desire_while_locked( $resource, 'paused', 'License revoked' );
		remove_filter( 'query', $break );

		$this->assertNull( Resource::find( $resource->id() )->pending_desire() );
		$this->assertCount( 1, $this->mails, 'The lost desire is not silent.' );
		$this->assertStringContainsString( 'Desired state "paused" could not be stored', $this->mails[0]['message'] );
	}

	public function test_deliberate_deletion_without_replacement_is_not_turned_back_on(): void {
		list( $resource ) = $this->resource( array( 'state' => 'paused' ) );

		$this->assertTrue( $resource->desire( 'deleted', 'Deleted by an admin' ) );
		$this->assertTrue( Resource::find( $resource->id() )->desire( 'deleted', 'Retention period ended', true ) );
		$this->assertFalse( Resource::find( $resource->id() )->get( 'replace_after_delete' ), 'False wins over a later true.' );

		list( $other ) = $this->resource( array( 'state' => 'paused' ) );

		$this->assertTrue( $other->desire( 'deleted', 'Retention period ended', true ) );
		$this->assertTrue( Resource::find( $other->id() )->desire( 'deleted', 'Deleted by an admin' ) );
		$this->assertFalse( Resource::find( $other->id() )->get( 'replace_after_delete' ) );
	}

	public function test_queued_deletion_keeps_its_place_and_no_replacement(): void {
		list( $resource, $driver ) = $this->resource( array( 'state' => 'ready' ) );

		Resource::find( $resource->id() )->queue_desire( 'deleted', 'Deleted by an admin' );
		Resource::find( $resource->id() )->queue_desire( 'deleted', 'Retention period ended', true );
		$this->assertFalse( Resource::find( $resource->id() )->pending_desire()['replace'], 'False wins in the queue too.' );

		Resource::find( $resource->id() )->queue_desire( 'ready', 'License active' );
		$this->assertSame( 'deleted', Resource::find( $resource->id() )->pending_desire()['state'], 'A queued deletion is terminal too.' );

		$this->run_jobs();

		$fresh = Resource::find( $resource->id() );
		$this->assertSame( 'deleted', $fresh->get( 'state' ) );
		$this->assertFalse( $fresh->get( 'replace_after_delete' ) );
		$this->assertCount( 1, $driver->calls( 'delete' ) );
	}

	public function test_deleted_listeners_see_the_closed_resource(): void {
		list( $resource ) = $this->resource( array( 'state' => 'paused' ) );
		$resource->desire( 'deleted', 'Retention over' );

		$seen = array();
		add_action(
			'beltoft_services_state_changed',
			static function ( $r, $old, $new ) use ( &$seen ) {
				$stored = Resource::find( $r->id() );
				$seen[] = array( $new, $r->is_live(), $stored->is_live() );
			},
			10,
			3
		);

		Jobs::run( $resource->id() );

		$this->assertSame( array( 'deleted', false, false ), end( $seen ), 'The resource is closed before listeners hear of the deletion.' );
	}

	public function test_a_failing_state_listener_never_breaks_the_reconciler(): void {
		list( $resource, $driver ) = $this->resource();
		$log      = wp_tempnam( 'beltoft-services-log' );
		$previous = ini_set( 'error_log', $log ); // phpcs:ignore WordPress.PHP.IniSet.Risky -- keeps the logged failure out of the test output.

		add_action(
			'beltoft_services_state_changed',
			static function () {
				throw new \RuntimeException( 'Listener exploded' );
			}
		);

		Jobs::enqueue( $resource->id() );
		$this->run_jobs();

		$fresh = Resource::find( $resource->id() );
		$this->assertCount( 1, $driver->calls( 'provision' ) );
		$this->assertSame( 'ready', $fresh->get( 'state' ) );
		$this->assertSame( 0, $fresh->get( 'attempts' ) );
		$this->assertSame( '', $fresh->get( 'last_error' ) );

		// A deletion still closes the resource.
		$fresh->desire( 'deleted', 'Gone' );
		Jobs::run( $resource->id() );
		ini_set( 'error_log', (string) $previous ); // phpcs:ignore WordPress.PHP.IniSet.Risky

		$this->assertFalse( Resource::find( $resource->id() )->is_live() );
		$this->assertStringContainsString( 'Listener exploded', (string) file_get_contents( $log ), 'The failure is logged.' );
		wp_delete_file( $log );
	}

	public function test_license_alert_masks_the_key_in_the_subject(): void {
		$this->settings( array( 'admin_email' => 'ops@example.com' ) );
		$product = $this->service_product( 'fake' );
		$license = $this->service_license( $product, array( 'fire' => false ) );

		\BeltoftServices\Alerts::license( $license, 'No driver' );

		$this->assertCount( 1, $this->mails );
		$this->assertStringEndsWith( 'Service not created: …' . substr( $license['license_key'], -4 ), $this->mails[0]['subject'] );
		$this->assertStringNotContainsString( $license['license_key'], $this->mails[0]['subject'] );
	}
}

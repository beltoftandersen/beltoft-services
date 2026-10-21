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
		$this->mails[] = $atts;
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
		Jobs::run( $resource->id() );
		$fresh = Resource::find( $resource->id() );

		$this->assertSame( 'failed', $fresh->get( 'state' ) );
		$this->assertSame( 6, $fresh->get( 'attempts' ) );
		$this->assertSame( 'Not yet', $fresh->get( 'last_error' ) );
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
		Jobs::run( $resource->id() );
		$this->assertSame( 2, Resource::find( $resource->id() )->get( 'attempts' ) );

		Jobs::run( $resource->id() );
		$failed = Resource::find( $resource->id() );
		$this->assertSame( 'failed', $failed->get( 'state' ) );
		$this->assertSame( 'Quota exceeded', $failed->get( 'last_error' ) );
		$this->assertSame( 3, $failed->get( 'attempts' ), 'A failure never resets the count.' );

		// An admin retry of a resource that never became ready provisions again; the count goes on.
		Jobs::run( $resource->id() );
		$this->assertSame( 4, Resource::find( $resource->id() )->get( 'attempts' ) );
		$this->assertCount( 4, $driver->calls( 'provision' ) );
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
		$this->assertSame( '[' . wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) . '] Service failed: ' . $license['license_key'], $this->mails[0]['subject'] );
		$this->assertStringContainsString( 'Host unreachable', $this->mails[0]['message'] );
		$this->assertStringContainsString( 'fake', $this->mails[0]['message'] );
		$this->assertStringContainsString( (string) $license['id'], $this->mails[0]['message'] );
		$this->assertStringContainsString( 'failed', $this->mails[0]['message'] );

		Jobs::run( $resource->id() );
		$this->assertCount( 1, $this->mails, 'The same error is not sent twice.' );

		Jobs::run( $resource->id() );
		$this->assertCount( 2, $this->mails, 'A new error is sent.' );
		$this->assertStringContainsString( 'Disk full', $this->mails[1]['message'] );
	}

	public function test_alerts_again_after_a_recovery(): void {
		list( $resource, $driver ) = $this->resource();
		$driver->will( 'provision', StepResult::fail( 'Boom' ) )->will( 'provision', StepResult::done() );
		$driver->will( 'pause', StepResult::fail( 'Boom' ) );

		Jobs::run( $resource->id() );
		Jobs::run( $resource->id() );
		$this->assertSame( 'ready', Resource::find( $resource->id() )->get( 'state' ) );

		Resource::find( $resource->id() )->desire( 'paused', 'Suspended' );
		Jobs::run( $resource->id() );

		$this->assertCount( 2, $this->mails );
	}

	public function test_operation_table(): void {
		$cases = array(
			array( 'requested', 'ready', false, '', 'provision' ),
			array( 'provisioning', 'ready', false, '', 'provision' ),
			array( 'failed', 'ready', false, 'provisioning', 'provision' ),
			array( 'failed', 'ready', false, 'requested', 'provision' ),
			array( 'failed', 'ready', false, 'ready', null ),
			array( 'failed', 'ready', false, '', null ),
			array( 'paused', 'ready', false, '', 'resume' ),
			array( 'ready', 'paused', false, '', 'pause' ),
			array( 'ready', 'deleted', false, '', 'delete' ),
			array( 'paused', 'deleted', false, '', 'delete' ),
			array( 'requested', 'deleted', false, '', 'delete' ),
			array( 'failed', 'deleted', false, 'ready', 'delete' ),
			array( 'deleting', 'deleted', false, '', 'delete' ),
			array( 'deleted', 'deleted', false, '', null ),
			array( 'ready', 'ready', true, '', 'update_plan' ),
			array( 'ready', 'ready', false, '', null ),
			array( 'paused', 'paused', true, '', null ),
			array( 'provisioning', 'paused', false, '', null ),
		);

		list( $resource ) = $this->resource();

		foreach ( $cases as $case ) {
			list( $state, $desired, $dirty, $from, $operation ) = $case;
			$resource->set(
				array(
					'state'       => $state,
					'desired'     => $desired,
					'plan_dirty'  => $dirty,
					'failed_from' => $from,
				)
			);

			$this->assertSame( $operation, Reconciler::operation( $resource ), "$state → $desired" . ( $dirty ? ' (plan dirty)' : '' ) . ( $from ? " (failed from $from)" : '' ) );
		}
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
}

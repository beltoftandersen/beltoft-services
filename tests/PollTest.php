<?php
/**
 * Polls (a wait that is no error), the stale-error clean-up and the site runner.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests;

use BeltoftServices\Cli\Commands;
use BeltoftServices\Drivers\StepResult;
use BeltoftServices\Jobs;
use BeltoftServices\Locks;
use BeltoftServices\Reconciler;
use BeltoftServices\Resource;
use BeltoftServices\Tests\Support\FakeDriver;

require_once __DIR__ . '/Support/WpCliStub.php';

/**
 * Poll and runner tests.
 */
final class PollTest extends TestCase {

	/**
	 * Mails caught by pre_wp_mail.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private $mails = array();

	public function set_up(): void {
		parent::set_up();

		$this->mails = array();
		add_filter(
			'pre_wp_mail',
			function ( $pre, $atts ) {
				$to = is_array( $atts['to'] ) ? implode( ',', $atts['to'] ) : (string) $atts['to'];
				if ( false === strpos( $to, 'customer@example.com' ) ) {
					$this->mails[] = $atts;
				}
				return true;
			},
			10,
			2
		);

		as_unschedule_all_actions( Jobs::SWEEP, array(), Jobs::GROUP );

		// Jobs other tests left in the shared queue must not be run by the runner.
		foreach ( array( Jobs::HOOK, Jobs::ACT, 'beltoft_services_runner_probe' ) as $hook ) {
			\ActionScheduler::store()->cancel_actions_by_hook( $hook );
		}

		\WP_CLI::reset();
	}

	/**
	 * A resource of a fake-driver service product.
	 *
	 * @param array<string, mixed> $meta Initial meta.
	 * @return array{0: Resource, 1: FakeDriver}
	 */
	private function resource( array $meta = array() ): array {
		$driver                   = $this->fake_driver();
		$driver->customer_actions = array( 'restart' => 'Restart' );
		$product                  = $this->service_product( 'fake', array(), array( 'max' => 3 ) );
		$license                  = $this->service_license( $product, array( 'fire' => false ) );
		$resource                 = Resource::create( $license, $driver, array(), $meta );

		$this->assertInstanceOf( Resource::class, $resource );

		return array( $resource, $driver );
	}

	private function make_due( Resource $r ): void {
		Resource::find( $r->id() )->set( array( 'next_attempt_at' => time() - 1 ) );
	}

	/**
	 * Pending actions of a hook.
	 *
	 * @param string $hook Hook.
	 * @return array<int, \ActionScheduler_Action>
	 */
	private function queued( string $hook ): array {
		return as_get_scheduled_actions( array( 'hook' => $hook, 'group' => Jobs::GROUP, 'status' => \ActionScheduler_Store::STATUS_PENDING, 'per_page' => -1 ) );
	}

	// -- StepResult ---------------------------------------------------------------------------------

	public function test_a_poll_is_a_retry_with_a_short_clamped_delay_and_a_budget(): void {
		$poll = StepResult::poll( 5, 'Waiting for x', 120 );

		$this->assertTrue( $poll->is_retry() );
		$this->assertTrue( $poll->is_poll() );
		$this->assertSame( 5, $poll->poll_seconds() );
		$this->assertSame( 120, $poll->poll_budget() );
		$this->assertSame( 2, StepResult::poll( 0, 'x', 10 )->poll_seconds(), 'Never below 2 seconds.' );
		$this->assertSame( 60, StepResult::poll( 600, 'x', 10 )->poll_seconds() );
		$this->assertTrue( $poll->with_progress()->is_poll(), 'Progress keeps it a poll.' );
		$this->assertTrue( $poll->with_progress()->has_progress() );
		$this->assertFalse( StepResult::retry( 5, 'x' )->is_poll() );
		$this->assertFalse( StepResult::wait( 60, 'x', time() + 100 )->is_poll() );
	}

	// -- Reconciler ---------------------------------------------------------------------------------

	public function test_a_poll_is_due_in_seconds_counts_no_attempt_and_writes_no_error(): void {
		list( $resource, $driver ) = $this->resource();
		$driver->will( 'provision', StepResult::poll( 5, 'clone: waiting for the Proxmox task', 1800 ) );

		$before = time();
		Jobs::run( $resource->id() );

		$fresh = Resource::find( $resource->id() );
		$this->assertSame( 'provisioning', $fresh->get( 'state' ) );
		$this->assertSame( 0, $fresh->get( 'attempts' ) );
		$this->assertSame( '', $fresh->get( 'last_error' ) );
		$this->assertSame( 'clone: waiting for the Proxmox task', $fresh->get( 'waiting' ) );
		$this->assertEqualsWithDelta( $before + 5, $fresh->get( 'next_attempt_at' ), 2 );
		$this->assertGreaterThan( 0, $fresh->get( 'wait_since' ) );
		$this->assertSame( array(), $this->mails );
	}

	public function test_a_plain_retry_still_has_the_sixty_second_floor(): void {
		list( $resource, $driver ) = $this->resource();
		$driver->will( 'provision', StepResult::retry( 0, 'Timeout' ) );

		Jobs::run( $resource->id() );

		$fresh = Resource::find( $resource->id() );
		$this->assertSame( 1, $fresh->get( 'attempts' ) );
		$this->assertGreaterThanOrEqual( time() + 59, $fresh->get( 'next_attempt_at' ) );
		$this->assertSame( 'Timeout', $fresh->get( 'last_error' ) );
	}

	public function test_polls_are_not_counted_until_the_budget_is_used_up_then_five_counted_retries_fail_it(): void {
		list( $resource, $driver ) = $this->resource();
		$driver->will( 'provision', StepResult::poll( 5, 'Still waiting', 100 ) );

		for ( $run = 0; $run < 4; $run++ ) {
			$this->make_due( $resource );
			Jobs::run( $resource->id() );
			$this->assertSame( 0, Resource::find( $resource->id() )->get( 'attempts' ), 'Within the budget nothing is counted.' );
		}

		// The wait has now lasted longer than its budget.
		Resource::find( $resource->id() )->set( array( 'wait_since' => time() - 101 ) );

		foreach ( array( 1, 2, 3, 4, 5 ) as $attempt ) {
			$this->make_due( $resource );
			Jobs::run( $resource->id() );
			$fresh = Resource::find( $resource->id() );
			$this->assertSame( $attempt, $fresh->get( 'attempts' ), 'Past the budget every run counts.' );
			$this->assertSame( 'Still waiting', $fresh->get( 'last_error' ) );
			$this->assertSame( '', $fresh->get( 'waiting' ) );
			$this->assertGreaterThanOrEqual( time() + 59, $fresh->get( 'next_attempt_at' ), 'The backoff applies again.' );
			$this->assertSame( 'provisioning', $fresh->get( 'state' ) );
		}

		$this->make_due( $resource );
		Jobs::run( $resource->id() );
		$this->assertSame( 'failed', Resource::find( $resource->id() )->get( 'state' ) );
		$this->assertNotEmpty( $this->mails, 'The admin is told.' );
	}

	public function test_progress_starts_the_wait_again_but_only_up_to_the_cap(): void {
		list( $resource, $driver ) = $this->resource();
		$driver->will( 'provision', StepResult::poll( 5, 'Moving', 100 )->with_progress() );

		$this->make_due( $resource );
		Jobs::run( $resource->id() );
		Resource::find( $resource->id() )->set( array( 'wait_since' => time() - 5000 ) );

		$this->make_due( $resource );
		Jobs::run( $resource->id() );

		$fresh = Resource::find( $resource->id() );
		$this->assertSame( 0, $fresh->get( 'attempts' ), 'Progress renewed the episode.' );
		$this->assertEqualsWithDelta( time(), $fresh->get( 'wait_since' ), 2 );
		$this->assertSame( 2, $fresh->get( 'progress_resets' ) );

		// Endless progress ends: past the cap an old episode counts.
		for ( $run = 3; $run <= Reconciler::MAX_PROGRESS_RESETS + 1; $run++ ) {
			$this->make_due( $resource );
			Jobs::run( $resource->id() );
		}

		Resource::find( $resource->id() )->set( array( 'wait_since' => time() - 5000, 'next_attempt_at' => time() - 1 ) );
		Jobs::run( $resource->id() );
		$this->assertSame( 1, Resource::find( $resource->id() )->get( 'attempts' ) );
	}

	public function test_done_clears_the_wait_and_any_error(): void {
		list( $resource, $driver ) = $this->resource();
		$driver->will( 'provision', StepResult::poll( 5, 'Waiting', 100 ) )->will( 'provision', StepResult::done() );

		Jobs::run( $resource->id() );
		$this->assertSame( 'Waiting', Resource::find( $resource->id() )->get( 'waiting' ) );

		$this->make_due( $resource );
		Jobs::run( $resource->id() );

		$fresh = Resource::find( $resource->id() );
		$this->assertSame( 'ready', $fresh->get( 'state' ) );
		$this->assertSame( '', $fresh->get( 'waiting' ) );
		$this->assertSame( 0, $fresh->get( 'wait_since' ) );
		$this->assertSame( '', $fresh->get( 'last_error' ) );
	}

	public function test_a_driver_cannot_write_the_wait_keys(): void {
		foreach ( array( 'waiting', 'wait_since', 'act_wait_since' ) as $key ) {
			$this->assertContains( $key, Reconciler::RESERVED );
		}

		list( $resource, $driver ) = $this->resource();
		$driver->will( 'provision', StepResult::done( array( 'waiting' => 'x', 'wait_since' => 5 ) ) );
		Jobs::run( $resource->id() );

		$this->assertSame( '', Resource::find( $resource->id() )->get( 'waiting' ) );
	}

	public function test_a_poll_that_is_hit_by_the_kill_switch_waits_without_a_driver_call(): void {
		list( $resource, $driver ) = $this->resource();
		$this->settings( array( 'pause_all' => 1 ) );

		Jobs::run( $resource->id() );

		$this->assertSame( array(), $driver->calls() );
	}

	public function test_a_stale_error_is_cleared_when_the_operation_that_wrote_it_is_over(): void {
		// The "start task did not leave the VM running (OK)" seen live: a retry stored it, the plan was applied
		// by another path meanwhile (plan_dirty cleared), so no operation runs and done() never clears it.
		list( $resource, ) = $this->resource( array( 'state' => 'ready' ) );
		$resource->set(
			array(
				'op'              => 'update_plan',
				'attempts'        => 1,
				'next_attempt_at' => time() - 1,
				'plan_dirty'      => false,
				'last_error'      => 'update: the start task did not leave the VM running (OK)',
				'waiting'         => 'update: waiting',
				'wait_since'      => time() - 30,
			)
		);

		Jobs::run( $resource->id() );

		$fresh = Resource::find( $resource->id() );
		$this->assertSame( '', $fresh->get( 'last_error' ) );
		$this->assertSame( '', $fresh->get( 'waiting' ) );
		$this->assertSame( 0, $fresh->get( 'wait_since' ) );
	}

	public function test_a_failed_resource_keeps_its_error(): void {
		list( $resource, ) = $this->resource( array( 'state' => 'failed', 'last_error' => 'Boom', 'failed_op' => 'provision', 'failed_desired' => 'ready', 'attempts' => 6 ) );

		Jobs::run( $resource->id() );

		$this->assertSame( 'Boom', Resource::find( $resource->id() )->get( 'last_error' ) );
	}

	// -- Customer actions -----------------------------------------------------------------------------

	public function test_an_act_poll_is_queued_in_seconds_without_counting(): void {
		list( $resource, $driver ) = $this->resource( array( 'state' => 'ready' ) );
		$driver->will( 'act', StepResult::poll( 5, 'rollback: the server did not switch off', 180 ) );

		$before = time();
		Jobs::act( $resource->id(), 'restart', array() );

		$queued = array_values( $this->queued( Jobs::ACT ) );
		$this->assertCount( 1, $queued );
		$this->assertEqualsWithDelta( $before + 5, $queued[0]->get_schedule()->get_date()->getTimestamp(), 2 );

		$fresh = Resource::find( $resource->id() );
		$this->assertSame( 0, (int) $fresh->get( 'act_attempts' ) );
		$this->assertSame( 'rollback: the server did not switch off', $fresh->get( 'waiting' ) );
		$this->assertSame( '', $fresh->get( 'last_error' ) );
		$this->assertSame( array(), $this->mails );
	}

	public function test_an_act_poll_past_its_budget_counts_and_uses_the_backoff(): void {
		list( $resource, $driver ) = $this->resource( array( 'state' => 'ready' ) );
		$driver->will( 'act', StepResult::poll( 5, 'Waiting', 180 ) );

		Jobs::act( $resource->id(), 'restart', array() );
		as_unschedule_all_actions( Jobs::ACT, array( $resource->id(), 'restart', array() ), Jobs::GROUP );
		Resource::find( $resource->id() )->set( array( 'act_wait_since' => array( 'restart' => array( 'since' => time() - 181, 'resets' => 0 ) ) ) );

		$before = time();
		Jobs::act( $resource->id(), 'restart', array() );

		$queued = array_values( $this->queued( Jobs::ACT ) );
		$this->assertCount( 1, $queued );
		$this->assertGreaterThanOrEqual( $before + 59, $queued[0]->get_schedule()->get_date()->getTimestamp() );
		$fresh = Resource::find( $resource->id() );
		$this->assertSame( 1, $fresh->get( 'act_attempts' ) );
		$this->assertSame( '', $fresh->get( 'waiting' ) );
	}

	public function test_an_act_that_ends_clears_the_wait_and_a_stale_error(): void {
		list( $resource, $driver ) = $this->resource( array( 'state' => 'ready', 'last_error' => 'update: the start task did not leave the VM running (OK)', 'waiting' => 'x', 'act_wait_since' => array( 'restart' => array( 'since' => time() - 5, 'resets' => 0 ) ) ) );
		$driver->will( 'act', StepResult::done() );

		Jobs::act( $resource->id(), 'restart', array() );

		$fresh = Resource::find( $resource->id() );
		$this->assertSame( '', $fresh->get( 'last_error' ) );
		$this->assertSame( '', $fresh->get( 'waiting' ) );
		$this->assertEmpty( $fresh->get( 'act_wait_since' ) );
	}

	public function test_an_expired_act_episode_is_kept_so_every_later_run_counts(): void {
		list( $resource, $driver ) = $this->resource( array( 'state' => 'ready' ) );
		$driver->will( 'act', StepResult::poll( 5, 'Waiting', 180 ) );
		$resource->set( array( 'act_wait_since' => array( 'restart' => array( 'since' => time() - 500, 'resets' => 0 ) ) ) );

		foreach ( array( 1, 2, 3 ) as $attempt ) {
			Jobs::act( $resource->id(), 'restart', array() );
			as_unschedule_all_actions( Jobs::ACT, array( $resource->id(), 'restart', array() ), Jobs::GROUP );
			$this->assertSame( $attempt, (int) Resource::find( $resource->id() )->get( 'act_attempts' ), 'Counted, not a fresh free episode.' );
		}
	}

	public function test_an_act_episode_belongs_to_its_action_name(): void {
		list( $resource, $driver ) = $this->resource( array( 'state' => 'ready' ) );
		$driver->customer_actions = array( 'restart' => 'Restart', 'other' => 'Other' );
		$driver->will( 'act', StepResult::poll( 5, 'Waiting', 180 ) );
		$resource->set( array( 'act_wait_since' => array( 'restart' => array( 'since' => time() - 500, 'resets' => 0 ) ) ) );

		Jobs::act( $resource->id(), 'other', array() );

		$fresh = Resource::find( $resource->id() );
		$this->assertSame( 0, (int) $fresh->get( 'act_attempts' ), 'Action B starts its own episode.' );
		$this->assertNotSame( '', $fresh->get( 'waiting' ) );
		$this->assertArrayHasKey( 'restart', $fresh->get( 'act_wait_since' ) );
		$this->assertArrayHasKey( 'other', $fresh->get( 'act_wait_since' ) );
	}

	public function test_act_progress_renews_the_episode_only_up_to_the_cap(): void {
		list( $resource, $driver ) = $this->resource( array( 'state' => 'ready' ) );
		$driver->will( 'act', StepResult::poll( 5, 'Moving', 180 )->with_progress() );
		$resource->set( array( 'act_wait_since' => array( 'restart' => array( 'since' => time() - 500, 'resets' => Reconciler::MAX_PROGRESS_RESETS - 1 ) ) ) );

		Jobs::act( $resource->id(), 'restart', array() );
		as_unschedule_all_actions( Jobs::ACT, array( $resource->id(), 'restart', array() ), Jobs::GROUP );
		$this->assertSame( 0, (int) Resource::find( $resource->id() )->get( 'act_attempts' ), 'The last renewal is free.' );

		Resource::find( $resource->id() )->set( array( 'act_wait_since' => array( 'restart' => array( 'since' => time() - 500, 'resets' => Reconciler::MAX_PROGRESS_RESETS ) ) ) );
		Jobs::act( $resource->id(), 'restart', array() );

		$this->assertSame( 1, (int) Resource::find( $resource->id() )->get( 'act_attempts' ), 'Past the cap the old episode counts.' );
	}

	public function test_an_act_that_waits_for_ever_reaches_the_admin_alert(): void {
		$this->settings( array( 'admin_email' => 'ops@example.com' ) );
		list( $resource, $driver ) = $this->resource( array( 'state' => 'ready' ) );
		$driver->will( 'act', StepResult::poll( 5, 'The server did not switch off', 180 ) );

		$skew = 0;
		add_filter( 'beltoft_services_now', static function ( $t ) use ( &$skew ) { return $t + $skew; } );

		// Five-second polls for the 180 second budget, then the five counted retries, then the failure.
		for ( $run = 1; $run <= 80 && ! $this->mails; $run++ ) {
			Jobs::act( $resource->id(), 'restart', array() );
			as_unschedule_all_actions( Jobs::ACT, array( $resource->id(), 'restart', array() ), Jobs::GROUP );
			$skew += 6;
		}

		$this->assertCount( 1, $this->mails, 'The admin is told.' );
		$this->assertLessThanOrEqual( 180 / 6 + 8, $run, 'About 31 free polls, 5 counted retries, then the failure.' );
		$this->assertSame( 'ready', Resource::find( $resource->id() )->get( 'state' ), 'A failed action never changes the service state.' );
	}

	// -- Locks ----------------------------------------------------------------------------------------

	public function test_a_job_that_meets_the_lock_comes_back_in_five_seconds(): void {
		$this->assertSame( 5, Jobs::LOCKED_DELAY );
	}

	// -- Budget ---------------------------------------------------------------------------------------

	public function test_the_run_budget_is_45_seconds_here_and_ten_in_a_web_request_and_can_be_filtered(): void {
		$this->assertSame( 45, Jobs::budget(), 'CLI and cron runs.' );
		$this->assertSame( 45, Jobs::time_left(), 'Outside a job the whole budget is left.' );

		add_filter( 'beltoft_services_run_budget', static fn() => 10 );
		$this->assertSame( 10, Jobs::budget() );
		add_filter( 'beltoft_services_run_budget', static fn() => 9999, 20 );
		$this->assertSame( 60, Jobs::budget(), 'Capped at a minute.' );
	}

	// -- The runner -----------------------------------------------------------------------------------

	private function clock(): array {
		$t         = new \stdClass();
		$t->now    = time();
		$t->sleeps = array();

		return array(
			$t,
			static function () use ( $t ) {
				return $t->now;
			},
			static function ( $seconds ) use ( $t ) {
				$t->sleeps[] = $seconds;
				$t->now     += $seconds;
			},
		);
	}

	public function test_run_due_runs_a_due_reconcile_job_and_a_due_customer_action(): void {
		list( $resource, ) = $this->resource( array( 'state' => 'ready' ) );
		Jobs::queue_act( $resource->id(), 'restart', array(), 0 );
		list( $other, $driver )   = $this->resource();
		Jobs::enqueue( $other->id(), 0 );

		list( $t, $now, $sleep ) = $this->clock();
		$ran                     = Jobs::run_due( 3, 3, $sleep, $now );

		$this->assertSame( 2, $ran );
		$this->assertCount( 1, $driver->calls( 'act' ) );
		$this->assertSame( 'ready', Resource::find( $other->id() )->get( 'state' ) );
		$this->assertSame( 0, \ActionScheduler::store()->get_claim_count(), 'The claim is released.' );
	}

	public function test_run_due_also_runs_the_app_install_and_app_status_jobs(): void {
		$seen = array();
		add_action( \BeltoftServices\Proxmox\AppInstall::HOOK, static function ( $id ) use ( &$seen ) { $seen[] = 'install ' . $id; }, 5 );
		add_action( \BeltoftServices\Proxmox\AppStatus::HOOK, static function ( $id ) use ( &$seen ) { $seen[] = 'status ' . $id; }, 5 );
		as_schedule_single_action( time() - 5, \BeltoftServices\Proxmox\AppInstall::HOOK, array( 987654 ), Jobs::GROUP );
		as_schedule_single_action( time() - 5, \BeltoftServices\Proxmox\AppStatus::HOOK, array( 987654 ), Jobs::GROUP );

		list( , $now, $sleep ) = $this->clock();
		$ran                   = Jobs::run_due( 3, 3, $sleep, $now );

		$this->assertSame( 2, $ran );
		sort( $seen );
		$this->assertSame( array( 'install 987654', 'status 987654' ), $seen );
	}

	public function test_run_due_leaves_future_jobs_and_other_hooks_alone(): void {
		list( $resource, $driver ) = $this->resource();
		Jobs::enqueue( $resource->id(), 600 );

		$probe = array();
		add_action( 'beltoft_services_runner_probe', static function () use ( &$probe ) { $probe[] = 'other hook'; } );
		as_enqueue_async_action( 'beltoft_services_runner_probe', array(), Jobs::GROUP );

		$ran = Jobs::run_due( 0 );

		$this->assertSame( 0, $ran );
		$this->assertSame( array(), $probe );
		$this->assertSame( array(), $driver->calls() );
		$this->assertCount( 1, $this->queued( Jobs::HOOK ), 'The future job is still waiting.' );
	}

	public function test_run_due_does_nothing_while_the_kill_switch_is_on(): void {
		list( $resource, $driver ) = $this->resource();
		Jobs::enqueue( $resource->id(), 0 );
		$this->settings( array( 'pause_all' => 1 ) );

		$this->assertSame( 0, Jobs::run_due( 0 ) );
		$this->assertSame( array(), $driver->calls() );
	}

	public function test_run_due_repeats_every_interval_until_the_time_is_up_and_picks_up_new_jobs(): void {
		list( $resource, $driver ) = $this->resource();
		$driver->will( 'provision', StepResult::poll( 3, 'Waiting', 100 ) )->will( 'provision', StepResult::done() );
		Jobs::enqueue( $resource->id(), 0 );

		list( $t, $now, $sleep ) = $this->clock();
		$start                   = $t->now;

		$ran = Jobs::run_due( 9, 3, $sleep, $now );

		$this->assertSame( array( 3, 3, 3 ), $t->sleeps );
		$this->assertSame( $start + 9, $t->now );
		$this->assertSame( 1, $ran, 'Only the first pass found something due: the poll is 3 seconds of real time away.' );
		$this->assertSame( 'provisioning', Resource::find( $resource->id() )->get( 'state' ) );
	}

	public function test_run_due_seconds_zero_with_nothing_due_never_sleeps(): void {
		list( $t, $now, $sleep ) = $this->clock();

		$this->assertSame( 0, Jobs::run_due( 0, 3, $sleep, $now ) );
		$this->assertSame( array(), $t->sleeps );
	}

	public function test_run_due_never_runs_an_action_another_claim_holds(): void {
		list( $resource, $driver ) = $this->resource();
		Jobs::enqueue( $resource->id(), 0 );

		$store = \ActionScheduler::store();
		$claim = $store->stake_claim( 10, null, array( Jobs::HOOK ) );
		$this->assertNotEmpty( $claim->get_actions() );

		$this->assertSame( 0, Jobs::run_due( 0 ) );
		$this->assertSame( array(), $driver->calls() );

		$store->release_claim( $claim );
		$this->assertSame( 1, Jobs::run_due( 0 ) );
		$this->assertCount( 1, $driver->calls( 'provision' ) );
	}

	public function test_a_job_the_runner_runs_still_honours_the_resource_lock(): void {
		list( $resource, $driver ) = $this->resource();
		Jobs::enqueue( $resource->id(), 0 );

		$other = new \mysqli( DB_HOST, DB_USER, DB_PASSWORD, DB_NAME ); // phpcs:ignore WordPress.DB.RestrictedClasses.mysql__mysqli -- a second connection holds the lock.
		global $wpdb;
		$held = $other->query( $wpdb->prepare( 'SELECT GET_LOCK( %s, 1 )', Locks::name( 'resource', $resource->id() ) ) );
		$this->assertSame( '1', $held->fetch_row()[0] );

		Jobs::run_due( 0 );
		$other->close();

		$this->assertSame( array(), $driver->calls(), 'No driver call without the lock.' );
		$this->assertNotEmpty( Jobs::pending( $resource->id() ), 'It is queued again.' );
	}

	public function test_run_due_starts_no_job_after_its_seconds_and_leaves_the_rest_pending_and_unclaimed(): void {
		$driver = $this->fake_driver();
		$ids    = array();

		for ( $i = 0; $i < 5; $i++ ) {
			$product  = $this->service_product( 'fake', array(), array( 'max' => 3 ) );
			$license  = $this->service_license( $product, array( 'fire' => false ) );
			$resource = Resource::create( $license, $driver, array(), array() );
			Jobs::enqueue( $resource->id(), 0 );
			$ids[] = $resource->id();
		}

		list( $t, $now, $sleep ) = $this->clock();
		// Every job "takes" a whole run budget of 45 seconds on the injected clock.
		add_action( Jobs::HOOK, static function () use ( $t ) { $t->now += 45; }, 5 );

		$start = $t->now;
		$ran   = Jobs::run_due( 10, 3, $sleep, $now );

		$this->assertSame( 1, $ran, 'The first job ran, none was started after the 10 seconds.' );
		$this->assertLessThanOrEqual( 10 + 45, $t->now - $start, 'Within the seconds plus one job budget.' );
		$this->assertSame( 0, \ActionScheduler::store()->get_claim_count(), 'Nothing stays claimed.' );
		$this->assertCount( 4, $this->queued( Jobs::HOOK ), 'The rest is still pending.' );
	}

	// -- WP-CLI -----------------------------------------------------------------------------------------

	public function test_the_run_due_command_reports_the_jobs_it_ran(): void {
		list( $resource, ) = $this->resource();
		Jobs::enqueue( $resource->id(), 0 );

		( new Commands() )->run_due( array(), array( 'seconds' => '0' ) );

		$this->assertStringContainsString( 'Ran 1 due job(s)', \WP_CLI::$lines[0][1] );
	}

	public function test_the_run_due_command_is_documented_as_a_subcommand(): void {
		$doc = ( new \ReflectionMethod( Commands::class, 'run_due' ) )->getDocComment();

		$this->assertStringContainsString( '@subcommand run-due', (string) $doc );
		$this->assertStringContainsString( 'flock -n', (string) $doc );
	}
}

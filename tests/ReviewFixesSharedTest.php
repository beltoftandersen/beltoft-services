<?php
/**
 * Review fixes of the shared controller (0.31.1): Jobs, Progress, Background, Routes, StepUp.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests;

use BeltoftServices\Api\Routes;
use BeltoftServices\Background;
use BeltoftServices\Drivers\StepResult;
use BeltoftServices\JobFailures;
use BeltoftServices\Jobs;
use BeltoftServices\Locks;
use BeltoftServices\Progress;
use BeltoftServices\Resource;
use BeltoftServices\Tests\Support\FakeDriver;

/**
 * Tests of the findings S1 to S11 (StepUp, S12, is in StepUpTest).
 */
final class ReviewFixesSharedTest extends TestCase {

	private const HOOK = 'beltoft_services_test_review_fixes';

	/** @var int */
	private $mails = 0;

	public function set_up(): void {
		parent::set_up();

		$this->mails = 0;
		add_filter(
			'pre_wp_mail',
			function () {
				++$this->mails;
				return true;
			}
		);
		$this->settings( array( 'admin_email' => 'christian.beltoft@gmail.com' ) );
		as_unschedule_all_actions( Jobs::SWEEP, array(), Jobs::GROUP );
		as_unschedule_all_actions( Jobs::ACT, array(), Jobs::GROUP );
		delete_option( Background::OPTION );
	}

	/**
	 * A ready resource of a fake-driver service.
	 *
	 * @return array{0: Resource, 1: FakeDriver}
	 */
	private function resource(): array {
		$driver   = $this->fake_driver();
		$product  = $this->service_product( 'fake', array(), array( 'max' => 3 ) );
		$license  = $this->service_license( $product, array( 'fire' => false ) );
		$resource = Resource::create( $license, $driver, array(), array( 'state' => 'ready', 'desired' => 'ready' ) );

		$this->assertInstanceOf( Resource::class, $resource );

		return array( $resource, $driver );
	}

	/**
	 * Writes the marker the way it stood at a time.
	 *
	 * @param Resource $r      Resource.
	 * @param string   $action Action.
	 * @param int      $at     Click time.
	 * @param int      $seen   Last run (0: none).
	 */
	private function marker( Resource $r, string $action, int $at, int $seen = 0 ): void {
		Resource::find( $r->id() )->set( array( Progress::MARKER => array( 'action' => $action, 'at' => $at, 'label' => 'Doing it' ) + ( $seen ? array( 'seen' => $seen ) : array() ) ) );
	}

	/**
	 * A lost job of an action, as Action Scheduler would mark it.
	 *
	 * @param int    $id     Resource id.
	 * @param string $action Action.
	 */
	private function lose( int $id, string $action ): void {
		$job = as_schedule_single_action( time() - 5, Jobs::ACT, array( $id, $action, array( 'x' ) ), Jobs::GROUP );
		\ActionScheduler::store()->mark_failure( $job );
		Jobs::job_failed( $job, new \Error( 'Process died' ) );
	}

	// -- S1: liveness ----------------------------------------------------------------------------

	public function test_s1_a_long_action_that_was_worked_on_lately_is_resumed_though_it_was_clicked_long_ago(): void {
		list( $r ) = $this->resource();
		add_filter( 'beltoft_services_action_resumable', static fn( $yes, $driver, $action ) => 'fake' === $driver && 'long' === $action ? true : $yes, 10, 3 );
		$at = time() - 40 * MINUTE_IN_SECONDS;
		$this->marker( $r, 'long', $at, time() - 60 );

		$this->lose( $r->id(), 'long' );

		$this->assertTrue( as_has_scheduled_action( Jobs::ACT, array( $r->id(), 'long', array( 'x' ), $at ), Jobs::GROUP ), 'Queued again, carrying the click time.' );
		$fresh = Resource::find( $r->id() );
		$this->assertNull( $fresh->get( Jobs::RESULT ), 'No failed result: it goes on.' );
		$this->assertSame( $at, $fresh->get( Progress::MARKER )['at'], 'The click time is never refreshed.' );
	}

	public function test_s1_an_action_nothing_worked_on_for_a_while_is_given_up_not_resumed(): void {
		list( $r ) = $this->resource();
		add_filter( 'beltoft_services_action_resumable', static fn( $yes, $driver, $action ) => 'fake' === $driver && 'long' === $action ? true : $yes, 10, 3 );
		$this->marker( $r, 'long', time() - 40 * MINUTE_IN_SECONDS, time() - Progress::MARKER_TTL - 30 );

		$this->lose( $r->id(), 'long' );

		$this->assertFalse( Resource::find( $r->id() )->get( Jobs::RESULT )['ok'] );
		$this->assertNull( Progress::pending( Resource::find( $r->id() ) ) );
	}

	public function test_s1_each_run_of_the_job_refreshes_the_liveness_time_and_not_the_click_time(): void {
		list( $r, $driver ) = $this->resource();
		$driver->customer_actions = array( 'long' => 'Long thing' );
		$driver->will( 'act', StepResult::poll( 5, 'still going', 3600 ) );
		$at = time() - 40 * MINUTE_IN_SECONDS;
		$this->marker( $r, 'long', $at );

		Jobs::act( $r->id(), 'long', array() );

		$marker = Resource::find( $r->id() )->get( Progress::MARKER );
		$this->assertSame( $at, $marker['at'] );
		$this->assertGreaterThanOrEqual( time() - 2, $marker['seen'] );
		$this->assertNotNull( Progress::pending( Resource::find( $r->id() ) ), 'Not lapsed: it is worked on.' );
		$this->assertSame( $at, Progress::pending( Resource::find( $r->id() ) )['at'], 'The page still shows the click time.' );
	}

	// -- S2: a resumed job never runs the action a second time -------------------------------------

	public function test_s2_a_resumed_job_is_dropped_when_the_first_run_finished_and_runs_while_its_marker_stands(): void {
		list( $r, $driver ) = $this->resource();
		$driver->customer_actions = array( 'tidy' => 'Tidy up' );

		// The marker is gone (the first run finished): the repeat does nothing.
		Jobs::act( $r->id(), 'tidy', array(), 12345 );
		$this->assertSame( array(), $driver->calls( 'act' ) );

		// Another action took over: dropped too.
		$this->marker( $r, 'other', 12345 );
		Jobs::act( $r->id(), 'tidy', array(), 12345 );
		$this->assertSame( array(), $driver->calls( 'act' ) );

		// The marker it repeats stands: it runs.
		$this->marker( $r, 'tidy', 12345 );
		Jobs::act( $r->id(), 'tidy', array(), 12345 );
		$this->assertCount( 1, $driver->calls( 'act' ) );
	}

	public function test_s2_a_scheduled_action_that_never_had_a_marker_still_runs(): void {
		list( $r, $driver ) = $this->resource();
		$driver->customer_actions = array( 'tidy' => 'Tidy up' );

		Jobs::act( $r->id(), 'tidy', array() );

		$this->assertCount( 1, $driver->calls( 'act' ), 'A renewal, a snapshot schedule, an SSL refresh have no marker.' );
	}

	public function test_s2_the_requeues_of_a_resumed_job_keep_its_guard(): void {
		list( $r, $driver ) = $this->resource();
		$driver->customer_actions = array( 'long' => 'Long thing' );
		$driver->will( 'act', StepResult::poll( 5, 'still going', 3600 ) );
		$this->marker( $r, 'long', 777 );

		Jobs::act( $r->id(), 'long', array( 'a' => 1 ), 777 );

		$this->assertTrue( as_has_scheduled_action( Jobs::ACT, array( $r->id(), 'long', array( 'a' => 1 ), 777 ), Jobs::GROUP ), 'The poll is queued with the same guard.' );
	}

	// -- S3: the start of an action is one step under the lock -------------------------------------

	public function test_s3_start_action_answers_busy_while_the_resource_lock_is_held_elsewhere(): void {
		list( $r, $driver ) = $this->resource();
		$driver->customer_actions = array( 'tidy' => 'Tidy up' );

		$other = @new \mysqli( DB_HOST, DB_USER, DB_PASSWORD, DB_NAME ); // phpcs:ignore WordPress.DB.RestrictedClasses -- A second connection holds the lock.

		if ( $other->connect_errno ) {
			$this->markTestSkipped( 'No second database connection.' );
		}

		$name = Locks::name( 'resource', $r->id() );
		$other->query( "SELECT GET_LOCK('" . $other->real_escape_string( $name ) . "', 0)" );

		try {
			$started = Jobs::start_action( $r, 'tidy', 'Tidy up' );
		} finally {
			$other->query( "SELECT RELEASE_LOCK('" . $other->real_escape_string( $name ) . "')" );
			$other->close();
		}

		$this->assertWPError( $started );
		$this->assertSame( 'busy', $started->get_error_code() );
		$this->assertSame( 409, $started->get_error_data()['status'] );
		$this->assertNull( Resource::find( $r->id() )->get( Progress::MARKER ), 'Nothing was marked.' );
		$this->assertFalse( as_has_scheduled_action( Jobs::ACT, array( $r->id(), 'tidy', array() ), Jobs::GROUP ) );
	}

	public function test_s3_start_action_works_when_the_preparation_takes_the_lock_itself_and_may_answer_the_job_arguments(): void {
		list( $r ) = $this->resource();

		$started = Jobs::start_action(
			$r,
			'tidy',
			'Tidy up',
			array(),
			static function () use ( $r ) {
				$r->locked( static fn(): bool => true );

				return array( 'rev' => 7 );
			}
		);

		$this->assertTrue( $started );
		$this->assertSame( 'tidy', Progress::pending( Resource::find( $r->id() ) )['action'] );
		$this->assertTrue( as_has_scheduled_action( Jobs::ACT, array( $r->id(), 'tidy', array( 'rev' => 7 ) ), Jobs::GROUP ) );
		$this->assertSame( array( 'rev' => 7 ), Resource::find( $r->id() )->get( Progress::MARKER )['args'], 'The marker keeps the arguments for a lapsed action.' );
	}

	public function test_s3_a_refused_preparation_marks_nothing_and_a_pending_marker_refuses_the_start(): void {
		list( $r ) = $this->resource();

		$refused = Jobs::start_action( $r, 'tidy', 'Tidy up', array(), static fn() => new \WP_Error( 'taken', 'No', array( 'status' => 422 ) ) );
		$this->assertSame( 'taken', $refused->get_error_code() );
		$this->assertNull( Resource::find( $r->id() )->get( Progress::MARKER ) );

		$this->assertTrue( Jobs::start_action( $r, 'tidy', 'Tidy up' ) );
		$busy = Jobs::start_action( $r, 'other', 'Other' );
		$this->assertSame( 'busy', $busy->get_error_code() );
		$this->assertSame( 'tidy', Progress::pending( Resource::find( $r->id() ) )['action'] );
	}

	// -- S4: lapsed() writes only from a fresh marker --------------------------------------------

	public function test_s4_lapsed_leaves_a_marker_that_was_replaced_since_and_is_safe_to_call_twice(): void {
		list( $r ) = $this->resource();
		$old = time() - Progress::MARKER_TTL - 50;
		$this->marker( $r, 'reboot', $old );
		$stale = Resource::find( $r->id() );

		// Meanwhile the old action ended and the customer started another.
		Progress::clear( Resource::find( $r->id() ) );
		Progress::mark( Resource::find( $r->id() ), 'restart', 'Restart' );

		JobFailures::lapsed( $stale, 'reboot', $old );

		$this->assertSame( 'restart', Resource::find( $r->id() )->get( Progress::MARKER )['action'], 'The new marker stands.' );
		$this->assertNull( Resource::find( $r->id() )->get( Jobs::RESULT ) );
		$this->assertSame( 0, $this->mails );

		// An old marker lapses once; a second call from a stale copy does nothing more.
		$this->marker( $r, 'reboot', $old );
		$stale = Resource::find( $r->id() );
		JobFailures::lapsed( $stale, 'reboot', $old );
		JobFailures::lapsed( $stale, 'reboot', $old );
		$this->assertSame( 1, $this->mails, 'One staff mail.' );
		$this->assertNull( Resource::find( $r->id() )->get( Progress::MARKER ) );
	}

	public function test_s4_a_lapsed_action_tells_features_what_it_was_about(): void {
		list( $r ) = $this->resource();
		$seen = array();
		add_action(
			'beltoft_services_action_failed',
			static function ( $res, $action, $args, $why ) use ( &$seen ) {
				$seen[] = array( $action, $args, $why );
			},
			10,
			4
		);

		$this->assertTrue( Jobs::start_action( $r, 'tidy', 'Tidy up', array( 'email' => 'a@b.test' ) ) );
		$fresh = Resource::find( $r->id() );
		$marker = $fresh->get( Progress::MARKER );
		$marker['at'] = time() - Progress::MARKER_TTL - 50;
		$fresh->set( array( Progress::MARKER => $marker ) );
		as_unschedule_all_actions( Jobs::ACT, array( $r->id(), 'tidy', array( 'email' => 'a@b.test' ) ), Jobs::GROUP );

		Progress::pending( Resource::find( $r->id() ) );

		$this->assertSame( array( array( 'tidy', array( 'email' => 'a@b.test' ), 'lapsed' ) ), $seen );
	}

	// -- S5, S6, S7 -------------------------------------------------------------------------------

	public function test_s5_a_refused_result_does_not_fire_the_done_hook(): void {
		list( $r, $driver ) = $this->resource();
		$driver->customer_actions = array( 'tidy' => 'Tidy up' );
		$fired                    = 0;
		add_action(
			'beltoft_services_action_done',
			static function () use ( &$fired ) {
				++$fired;
			}
		);

		$driver->will( 'act', StepResult::done( array(), 'The server refused the change' ) )->will( 'act', StepResult::done() );
		Jobs::act( $r->id(), 'tidy', array() );
		$this->assertSame( 0, $fired, 'A refusal is not a success.' );
		$this->assertFalse( Resource::find( $r->id() )->get( Jobs::RESULT )['ok'] );

		Jobs::act( $r->id(), 'tidy', array() );
		$this->assertSame( 1, $fired );
	}

	public function test_s6_an_action_dropped_after_a_day_parked_clears_its_marker_and_fires_failed_once(): void {
		list( $r, $driver ) = $this->resource();
		$driver->customer_actions = array();
		add_filter( 'beltoft_services_park_action', '__return_true' );
		$failed = array();
		add_action(
			'beltoft_services_action_failed',
			static function ( $res, $action, $args, $why ) use ( &$failed ) {
				$failed[] = array( $action, $why );
			},
			10,
			4
		);

		Progress::mark( Resource::find( $r->id() ), 'attach', 'Attach' );
		Resource::find( $r->id() )->set( array( 'act_parked_since' => time() - 2 * DAY_IN_SECONDS ) );

		Jobs::act( $r->id(), 'attach', array() );

		$fresh = Resource::find( $r->id() );
		$this->assertNull( $fresh->get( Progress::MARKER ), 'The marker is gone, so it cannot lapse and alert again.' );
		$this->assertFalse( $fresh->get( Jobs::RESULT )['ok'] );
		$this->assertSame( array( array( 'attach', 'stopped' ) ), $failed );

		$mails = $this->mails;
		Progress::pending( Resource::find( $r->id() ) );
		$this->assertSame( $mails, $this->mails, 'No second alert.' );
	}

	public function test_s7_a_drivers_sentence_is_plain_text_and_capped(): void {
		list( $r, $driver ) = $this->resource();
		$driver->customer_actions = array( 'tidy' => 'Tidy up' );
		$driver->will( 'act', StepResult::done( array( Jobs::TEXT => '<b>Done</b> <script>x()</script>' . str_repeat( 'word ', 200 ) ) ) );

		Jobs::act( $r->id(), 'tidy', array() );

		$text = Resource::find( $r->id() )->get( Jobs::RESULT )['text'];
		$this->assertStringNotContainsString( '<', $text );
		$this->assertLessThanOrEqual( Jobs::TEXT_MAX, mb_strlen( $text ) );
		$this->assertStringStartsWith( 'Done', $text );
	}

	// -- S8 to S10: Background -------------------------------------------------------------------

	public function test_s8_a_hook_is_not_a_resource_hook_unless_it_says_so_and_the_real_ones_say_so(): void {
		Background::declare( self::HOOK, array( 'label' => 'x' ) );
		$hooks = Background::hooks();
		$this->assertFalse( $hooks[ self::HOOK ]['resource'] );

		foreach ( array( \BeltoftServices\Apps\AppInstall::HOOK, \BeltoftServices\Apps\AppStatus::HOOK, \BeltoftServices\Mail\MailHold::HOOK, \BeltoftServices\Mail\MailDns::HOOK, \BeltoftServices\Mail\MailDomains::RETRY_HOOK, \BeltoftServices\Licences\KeyOrder::KEY_HOOK, \BeltoftServices\Apps\PleskLicence::SYNC_HOOK, \BeltoftServices\Mail\MailDns::RECHECK ) as $hook ) {
			$this->assertTrue( $hooks[ $hook ]['resource'] ?? false, $hook );
		}

		$this->assertFalse( $hooks[ \BeltoftServices\Domains\DnsClaimJobs::CHECK ]['resource'] );
	}

	public function test_s9_a_finished_job_of_an_undeclared_hook_reads_no_option(): void {
		$reads = 0;
		add_filter(
			'pre_option_' . Background::OPTION,
			static function ( $pre ) use ( &$reads ) {
				++$reads;
				return $pre;
			}
		);

		Background::completed( 1, new \ActionScheduler_Action( 'some_other_plugins_hook', array( 1 ) ) );
		$this->assertSame( 0, $reads );

		Background::declare( self::HOOK, array() );
		Background::completed( 1, new \ActionScheduler_Action( self::HOOK, array( 1 ) ) );
		$this->assertSame( 1, $reads, 'A declared hook looks at its records.' );
	}

	public function test_s10_ids_from_a_due_list_as_strings_match_the_jobs_scheduled_with_integers(): void {
		$this->assertSame( array( 5, 'a', '05', 0 ), Background::args( array( '5', 'a', '05', '0' ) ) );

		Background::declare( self::HOOK, array( 'due' => static fn(): array => array( array( '5' ) ) ) );
		as_schedule_single_action( time() + 600, self::HOOK, array( 5 ), Jobs::GROUP );

		$this->assertSame( 0, Background::watchdog(), 'The waiting job is seen: no duplicate at every sweep.' );

		as_unschedule_all_actions( self::HOOK );
		$this->assertSame( 1, Background::watchdog() );
		$this->assertTrue( as_has_scheduled_action( self::HOOK, array( 5 ), Jobs::GROUP ), 'Scheduled with an integer.' );
		$this->assertSame( 0, Background::watchdog() );
	}

	// -- S11 ---------------------------------------------------------------------------------------

	public function test_s11_a_refusal_is_409_only_when_busy_else_its_own_status_or_422(): void {
		$busy = Routes::not_queued( new \WP_Error( 'busy', 'Wait.', array( 'status' => 409 ) ) );
		$this->assertSame( 409, $busy->get_status() );
		$this->assertSame( 'beltoft_services_busy', $busy->get_data()['code'] );

		$this->assertSame( 422, Routes::not_queued( new \WP_Error( 'exists', 'In use.' ) )->get_status() );
		$this->assertSame( 500, Routes::not_queued( new \WP_Error( 'failed', 'No.', array( 'status' => 500 ) ) )->get_status() );
		$this->assertSame( 409, Routes::not_queued( new \WP_Error( 'busy', 'Wait.' ) )->get_status() );
		$this->assertSame( 'beltoft_services_ack', Routes::not_queued( new \WP_Error( 'beltoft_services_ack', 'Ack.' ) )->get_data()['code'], 'No double prefix.' );
	}
}

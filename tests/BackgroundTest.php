<?php
/**
 * Background hooks: the runner claim, the crash re-queue with backoff, the staff alert and the watchdog.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests;

use BeltoftServices\Background;
use BeltoftServices\Jobs;
use BeltoftServices\Reconciler;

/**
 * Background tests.
 */
final class BackgroundTest extends TestCase {

	private const HOOK = 'beltoft_services_test_background';

	/** @var array<int, array<string, mixed>> */
	private array $mails = array();

	/** @var array<int, array<int, mixed>> */
	private array $failed = array();

	/** @var array<int, array<int, int>> */
	private array $due = array();

	public function set_up(): void {
		parent::set_up();

		$this->mails  = array();
		$this->failed = array();
		$this->due    = array();
		delete_option( Background::OPTION );

		add_filter(
			'pre_wp_mail',
			function ( $pre, $atts ) {
				$this->mails[] = $atts;
				return true;
			},
			10,
			2
		);
		add_action(
			Background::FAILED,
			function ( $hook, $args, $why ) {
				$this->failed[] = array( $hook, $args, $why );
			},
			10,
			3
		);
		Background::declare(
			self::HOOK,
			array(
				'resource' => false,
				'label'    => 'Test job',
				'due'      => fn(): array => $this->due,
			)
		);
		as_unschedule_all_actions( self::HOOK );
	}

	/**
	 * A job of the hook that Action Scheduler gave up on.
	 *
	 * @param array<int, mixed> $args Arguments.
	 */
	private function lost( array $args ): int {
		$id = as_schedule_single_action( time() - 5, self::HOOK, $args, Jobs::GROUP );
		\ActionScheduler::store()->mark_failure( $id );

		return $id;
	}

	/**
	 * Seconds until the pending job of the hook with these arguments, or null.
	 *
	 * @param array<int, mixed> $args Arguments.
	 */
	private function due_in( array $args ): ?int {
		$ids = as_get_scheduled_actions(
			array(
				'hook'     => self::HOOK,
				'args'     => $args,
				'group'    => Jobs::GROUP,
				'status'   => \ActionScheduler_Store::STATUS_PENDING,
				'per_page' => 1,
			),
			'ids'
		);

		return $ids ? (int) \ActionScheduler::store()->get_date( $ids[0] )->getTimestamp() - time() : null;
	}

	public function test_a_declared_hook_is_claimed_by_the_runners(): void {
		$this->assertContains( self::HOOK, apply_filters( 'beltoft_services_job_hooks', array() ) );
		$this->assertTrue( Background::registered( self::HOOK ) );
		$this->assertFalse( Background::registered( 'some_other_hook' ) );
	}

	public function test_the_features_of_this_part_are_declared(): void {
		foreach ( array(
			\BeltoftServices\Mail\MailHold::HOOK,
			\BeltoftServices\Mail\MailDomains::RETRY_HOOK,
			\BeltoftServices\Mail\MailDns::HOOK,
			\BeltoftServices\Domains\DnsClaimJobs::CHECK,
			\BeltoftServices\Domains\DnsClaimJobs::SCAN,
			\BeltoftServices\Apps\AppInstall::HOOK,
			\BeltoftServices\Apps\AppStatus::HOOK,
			\BeltoftServices\Apps\AppOwnDomain::HOOK,
			\BeltoftServices\Apps\AppWww::HOOK,
			\BeltoftServices\Apps\AppDomainRelease::HOOK,
			\BeltoftServices\Apps\PleskLicence::KEY_HOOK,
			\BeltoftServices\Apps\PleskLicence::SYNC_HOOK,
			\BeltoftServices\Licences\KeyOrder::KEY_HOOK,
			\BeltoftServices\Licences\KeyOrder::SYNC_HOOK,
		) as $hook ) {
			$this->assertTrue( Background::registered( $hook ), $hook );
			$this->assertContains( $hook, apply_filters( 'beltoft_services_job_hooks', array() ), $hook );
		}
	}

	public function test_a_crash_is_queued_again_along_the_backoff_and_staff_hear_once(): void {
		$id = $this->lost( array( 5 ) );
		Jobs::job_failed( $id, new \Error( 'Class not found' ) );

		$wait = $this->due_in( array( 5 ) );
		$this->assertNotNull( $wait );
		$this->assertEqualsWithDelta( Reconciler::BACKOFF[0], $wait, 5 );
		$this->assertSame( array( array( self::HOOK, array( 5 ), 'Class not found' ) ), $this->failed );
		$this->assertCount( 1, $this->mails );

		// The same error again (reported twice, or the next run): the next backoff step, no second mail.
		as_unschedule_all_actions( self::HOOK );
		Jobs::job_failed( $this->lost( array( 5 ) ), new \Error( 'Class not found' ) );
		$this->assertEqualsWithDelta( Reconciler::BACKOFF[1], (int) $this->due_in( array( 5 ) ), 5 );
		$this->assertCount( 1, $this->mails, 'One staff mail per error.' );
		$this->assertCount( 2, $this->failed );

		// Another error is another mail.
		as_unschedule_all_actions( self::HOOK );
		Jobs::job_failed( $this->lost( array( 5 ) ), array( 'message' => 'Allowed memory size exhausted' ) );
		$this->assertCount( 2, $this->mails );
	}

	public function test_the_cleaners_timeout_is_a_crash_too(): void {
		Jobs::job_failed( $this->lost( array( 9 ) ), 300 );

		$this->assertNotNull( $this->due_in( array( 9 ) ) );
		$this->assertStringContainsString( 'marked failed after 300 seconds', $this->failed[0][2] );
	}

	public function test_after_the_last_backoff_step_the_job_is_left_alone_and_the_watchdog_waits_a_day(): void {
		$this->due = array( array( 7 ) );

		for ( $i = 0; $i <= count( Reconciler::BACKOFF ); ++$i ) {
			as_unschedule_all_actions( self::HOOK );
			Jobs::job_failed( $this->lost( array( 7 ) ), new \Error( 'Still broken' ) );
		}

		$this->assertNull( $this->due_in( array( 7 ) ), 'Not queued again after the whole backoff.' );
		$this->assertCount( count( Reconciler::BACKOFF ) + 1, $this->failed, 'The feature is told every time.' );
		$this->assertSame( 0, Background::watchdog(), 'The watchdog leaves it for a day.' );

		$records = get_option( Background::OPTION );
		foreach ( array_keys( $records ) as $key ) {
			$records[ $key ]['at'] = time() - Background::COOL_OFF - 5;
		}
		update_option( Background::OPTION, $records );
		$this->assertSame( 1, Background::watchdog() );
		$this->assertNotNull( $this->due_in( array( 7 ) ) );
	}

	public function test_a_job_that_finishes_starts_its_count_again(): void {
		Jobs::job_failed( $this->lost( array( 3 ) ), new \Error( 'x' ) );
		$this->assertNotEmpty( get_option( Background::OPTION ) );

		$done = as_schedule_single_action( time() - 5, self::HOOK, array( 3 ), Jobs::GROUP );
		\ActionScheduler::store()->mark_complete( $done );
		Background::completed( $done );

		$this->assertSame( array(), get_option( Background::OPTION ) );
	}

	public function test_a_hook_that_is_not_declared_is_not_handled(): void {
		$this->assertFalse( Background::failed( 'beltoft_services_unknown_hook', array( 1 ), new \Error( 'x' ) ) );
		$this->assertSame( array(), $this->failed );
	}

	public function test_the_watchdog_queues_a_missing_job_once(): void {
		$this->due = array( array( 11 ), array( 12 ) );

		$this->assertNull( $this->due_in( array( 11 ) ) );
		$this->assertSame( 2, Background::watchdog() );
		$this->assertNotNull( $this->due_in( array( 11 ) ) );
		$this->assertSame( 0, Background::watchdog(), 'Never queued twice.' );
	}

	public function test_a_hook_without_a_due_list_has_no_watchdog_and_a_throwing_list_is_skipped(): void {
		Background::declare( 'beltoft_services_test_background_two', array( 'resource' => false ) );
		Background::declare(
			'beltoft_services_test_background_three',
			array(
				'resource' => false,
				'due'      => static function () {
					throw new \RuntimeException( 'broken' );
				},
			)
		);

		$this->assertSame( 0, Background::watchdog() );
	}
}

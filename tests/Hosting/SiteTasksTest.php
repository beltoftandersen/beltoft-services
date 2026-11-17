<?php
/**
 * The background tasks of a hosting account: Add website step by step, resume, cleanup after a real failure, a slow install, one change at a time, the watchdog.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Hosting;

require_once __DIR__ . '/HostingCase.php';

use BeltoftServices\Api\Routes;
use BeltoftServices\Crypto;
use BeltoftServices\Hosting\HostingActions;
use BeltoftServices\Hosting\Sites;
use BeltoftServices\Hosting\SiteDomains;
use BeltoftServices\Hosting\Actions\Steps;
use BeltoftServices\Progress;
use BeltoftServices\Jobs;
use BeltoftServices\Resource;

/**
 * The hosting changes on the shared action controller (Progress marker, Jobs::queue_act, Jobs::act): request mode, job mode, steps.
 */
class SiteTasksTest extends HostingCase {

	public function set_up(): void {
		parent::set_up();
		$this->settings( array( 'hosting_php_versions' => '8.3,8.4,8.5' ) );
		add_filter( 'beltoft_services_rest_rate_limit', '__return_zero' );
	}

	public function tear_down(): void {
		remove_all_filters( 'beltoft_services_rest_rate_limit' );
		remove_all_filters( 'beltoft_services_hosting_action_wait' );
		wp_set_current_user( 0 );
		global $wp_rest_server;
		$wp_rest_server = null;

		parent::tear_down();
	}

	/**
	 * A ready account.
	 */
	private function account(): Resource {
		$r = $this->resource( array( 'sites' => 3, 'php_workers' => 10, 'php_memory_mb' => 512, 'upload_mb' => 100 ) );
		$this->provision_fully( $r );
		$r->set( array( 'state' => 'ready', 'desired' => 'ready' ) );

		return Resource::find( $r->id() );
	}

	/**
	 * A fresh read of the resource (nothing kept from an earlier run: what a crash or a deploy leaves).
	 *
	 * @param Resource $r Resource.
	 */
	private function fresh( Resource $r ): Resource {
		wp_cache_flush();

		return Resource::find( $r->id() );
	}

	/**
	 * The change in progress as the page sees it, or null when it ended.
	 *
	 * @param Resource $r Resource.
	 * @return array<string, mixed>|null
	 */
	private function task( Resource $r ): ?array {
		return Steps::view( $this->fresh( $r ) );
	}

	/**
	 * The shared outcome of the last action.
	 *
	 * @param Resource $r Resource.
	 * @return array<string, mixed>
	 */
	private function result( Resource $r ): array {
		return (array) $this->fresh( $r )->get( Jobs::RESULT );
	}

	/**
	 * One run of the account's task, as the runner makes it. No waiting: the job answers poll and the test looks again.
	 *
	 * @param Resource $r      Resource.
	 * @param string   $action Job action.
	 */
	private function step( Resource $r, string $action ): void {
		// The arguments are the job's own (what the route queued); the test runs the waiting job as a runner would.
		$ids = as_get_scheduled_actions( array( 'hook' => Jobs::ACT, 'group' => Jobs::GROUP, 'status' => \ActionScheduler_Store::STATUS_PENDING, 'per_page' => -1 ), 'ids' );

		foreach ( $ids as $id ) {
			$args = \ActionScheduler::store()->fetch_action( (string) $id )->get_args();

			if ( (int) $args[0] === $r->id() && $args[1] === $action ) {
				Jobs::act( $r->id(), $action, (array) $args[2] );

				return;
			}
		}

		Jobs::act( $r->id(), $action, array() );
	}

	/**
	 * Runs the task until it ends.
	 *
	 * @param Resource $r      Resource.
	 * @param string   $action Job action.
	 * @param int      $most   Most runs.
	 * @return string[] The words of the step after each run (the page shows these).
	 */
	private function drive( Resource $r, string $action, int $most = 60 ): array {
		$seen = array();

		for ( $i = 0; $i < $most; $i++ ) {
			$this->step( $this->fresh( $r ), $action );
			$task = $this->task( $r );

			if ( null === $task ) {
				return $seen;
			}

			$seen[] = $task['step_label'];
		}

		$this->fail( 'The task did not end in ' . $most . ' runs: ' . (string) wp_json_encode( $this->task( $r ) ) );
	}

	/**
	 * The distinct agent job ids submitted for an operation, for one website (the first website's own calls are not counted).
	 *
	 * @param string $op   Operation.
	 * @param string $slug Website.
	 * @return string[]
	 */
	private function job_ids( string $op, string $slug ): array {
		$ids = array_unique( array_column( $this->agent->calls_of( $op ), 'job_id' ) );

		return array_values( array_filter( $ids, static fn( $id ) => false !== strpos( (string) $id, '_' . $slug . ':' ) ) );
	}

	/**
	 * The job of the task is lost (never run, or removed): nothing waits for the task any more.
	 *
	 * @param Resource $r      Resource.
	 * @param string   $action Job action.
	 */
	private function lose_the_job( Resource $r, string $action ): void {
		foreach ( as_get_scheduled_actions( array( 'hook' => Jobs::ACT, 'group' => Jobs::GROUP, 'status' => \ActionScheduler_Store::STATUS_PENDING, 'per_page' => -1 ), 'ids' ) as $id ) {
			\ActionScheduler::store()->cancel_action( (string) $id );
		}

		$this->assertFalse( Jobs::acting( $r->id() ) );
	}

	public function test_add_website_is_a_task_and_each_agent_step_runs_in_its_own_job_run(): void {
		$r = $this->account();

		foreach ( array( 'db.create', 'site.create', 'site.wp_install', 'edge.apply' ) as $op ) {
			$this->agent->pending( $op, 2 ); // Queued, then running, then done.
		}

		$answer = ( new HostingActions( $r ) )->queue( 'add_site', array( 'blog', 'Blog', '8.4', true ) );

		$this->assertSame( 'pending', $answer['status'] );
		$this->assertSame( 'blog', $answer['slug'] );
		$this->assertSame( 'add_site', $answer['task']['action'] );
		$this->assertSame( array(), $this->job_ids( 'db.create', 'blog' ), 'Nothing reaches the node in the request.' );
		$this->assertTrue( ! empty( Sites::find( $r->id(), 'blog' )['flags']['pending'] ), 'The row exists at once, marked pending.' );
		$this->assertTrue( Jobs::acting( $r->id() ) );

		$words = $this->drive( $r, 'add_site' );

		$this->assertContains( 'Making the database', $words );
		$this->assertContains( 'Making the website', $words );
		$this->assertContains( 'Installing WordPress', $words );
		$this->assertContains( 'Setting up the address', $words );
		$this->assertGreaterThanOrEqual( 8, count( $words ), 'Every wait for the agent was a run of its own.' );

		$site = Sites::find( $r->id(), 'blog' );
		$this->assertEmpty( $site['flags']['pending'] );
		$this->assertSame( '8.4', $site['php'] );
		$this->assertSame( 'active', SiteDomains::find( 'blog.preview.example.test' )['state'] );
		$this->assertContains( 'blog.preview.example.test', array_column( $this->agent->state()['edge'][ 'c' . $r->id() ], 'host' ) );
		$this->assertArrayHasKey( 'blog', $this->agent->state()['sites'][1000] );
		$this->assertNull( $this->task( $r ) );

		foreach ( array( 'db.create', 'site.create', 'site.wp_install' ) as $op ) {
			$this->assertCount( 1, $this->job_ids( $op, 'blog' ), $op . ' ran once, however often it was read.' );
		}

		$last = $this->result( $r );
		$this->assertTrue( $last['ok'] );
		$this->assertSame( 'add_site', $last['action'] );
	}

	public function test_a_slow_install_completes_and_nothing_is_removed(): void {
		$r = $this->account();
		$this->agent->pending( 'site.wp_install', 10 ); // Ten more reads before it is done.

		( new HostingActions( $r ) )->queue( 'add_site', array( 'slowpoke', '', '', true ) );
		$words = $this->drive( $r, 'add_site' );

		$this->assertGreaterThanOrEqual( 10, count( array_keys( $words, 'Installing WordPress', true ) ), 'The install was waited for, run after run.' );
		$this->assertEmpty( Sites::find( $r->id(), 'slowpoke' )['flags']['pending'] );
		$this->assertSame( array(), $this->agent->calls_of( 'site.delete' ), 'Nothing was undone because it was slow.' );
		$this->assertSame( array(), $this->agent->calls_of( 'db.drop' ) );
		$this->assertTrue( $this->result( $r )['ok'] );
	}

	public function test_a_slow_agent_is_not_a_failure_for_a_caller_that_waits_either(): void {
		$r = $this->account();
		add_filter( 'beltoft_services_hosting_action_wait', '__return_zero' );
		$this->agent->pending( 'site.wp_install', 50 );

		$err = ( new HostingActions( $r ) )->add_site( 'inline', '', '', true );

		$this->assertWPError( $err );
		$this->assertSame( 'busy', $err->get_error_code() );
		$this->assertNotNull( Sites::find( $r->id(), 'inline' ), 'The website stays, marked pending: the next add of the same name goes on.' );
		$this->assertSame( array(), $this->agent->calls_of( 'site.delete' ) );
		$this->assertSame( array(), $this->agent->calls_of( 'db.drop' ) );
	}

	/**
	 * Where a task can stand while the agent works: the call, the words of the step, the calls that were already done.
	 *
	 * @return array<string, array{0: string, 1: string, 2: string[]}>
	 */
	public static function steps(): array {
		return array(
			'room'    => array( 'site.workers', 'Making room for the new website', array() ),
			'db'      => array( 'db.create', 'Making the database', array() ),
			'site'    => array( 'site.create', 'Making the website', array( 'db_add_again' ) ),
			'install' => array( 'site.wp_install', 'Installing WordPress', array( 'db_add_again', 'site_add_again' ) ),
			'address' => array( 'edge.apply', 'Setting up the address', array( 'db_add_again', 'site_add_again', 'site_install_again' ) ),
		);
	}

	/**
	 * @dataProvider steps
	 *
	 * @param string   $op     Agent operation the task waits for.
	 * @param string   $words  The step in words.
	 * @param string[] $memoed Calls that were done before it.
	 */
	public function test_a_task_resumes_at_the_step_where_a_crash_or_a_deploy_stopped_it( string $op, string $words, array $memoed ): void {
		$r = $this->account();
		( new HostingActions( $r ) )->add_site( 'first', '', '', false );

		// Every website has 3 workers and the account 4: the new website has to make room first.
		$r = $this->fresh( $r );
		$r->set( array( 'plan' => array_merge( (array) $r->get( 'plan' ), array( 'php_workers' => 4 ) ) ) );

		foreach ( Sites::for_resource( $r->id() ) as $site ) {
			Sites::update( $r->id(), (string) $site['slug'], array( 'workers' => 3 ) );
		}

		$r = $this->fresh( $r );
		$this->agent->pending( $op, 3 );
		( new HostingActions( $r ) )->queue( 'add_site', array( 'again', '', '', true ) );

		// Run until the task stands at the step: the agent is working on it.
		$task = null;

		for ( $i = 0; $i < 40; $i++ ) {
			$this->step( $this->fresh( $r ), 'add_site' );
			$task = $this->task( $r );
			$this->assertNotNull( $task, 'The task reached the step before it ended.' );

			if ( $words === $task['step_label'] ) {
				break;
			}
		}

		$this->assertSame( $words, $task['step_label'] );

		foreach ( $memoed as $name ) {
			$this->assertArrayHasKey( $name, Steps::state( $this->fresh( $r ) )['memo'], 'What was done is kept in the steps.' );
		}

		// The process is gone: nothing but the stored task is left. A new run goes on, and the steps already done are not asked again.
		$this->drive( $this->fresh( $r ), 'add_site' );

		$this->assertEmpty( Sites::find( $r->id(), 'again' )['flags']['pending'], $op );
		$this->assertTrue( $this->result( $r )['ok'], $op );

		foreach ( array( 'db.create', 'site.create', 'site.wp_install' ) as $each ) {
			$this->assertCount( 1, $this->job_ids( $each, 'again' ), $each . ' was never started twice' );
		}
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public static function failing(): array {
		return array(
			'db'      => array( 'db.create' ),
			'site'    => array( 'site.create' ),
			'install' => array( 'site.wp_install' ),
		);
	}

	/**
	 * @dataProvider failing
	 */
	public function test_a_real_failure_runs_the_cleanup_as_steps_and_removes_the_record( string $op ): void {
		$r = $this->account();
		$this->agent->fail_next( $op, 'scripted failure 10.200.1.5' );
		$this->agent->pending( 'db.drop', 2 );
		$this->agent->pending( 'site.delete', 2 );

		( new HostingActions( $r ) )->queue( 'add_site', array( 'doomed', 'Doomed', '', true ) );
		$words = $this->drive( $r, 'add_site' );

		$this->assertContains( 'Cleaning up', $words, 'The cleanup is steps too.' );
		$this->assertNull( Sites::find( $r->id(), 'doomed' ), 'The name is free for a second try.' );
		$this->assertNull( $this->task( $r ) );
		$this->assertNotSame( array(), $this->agent->calls_of( 'db.drop' ), 'The database it made is dropped.' );
		$this->assertNotSame( array(), $this->agent->calls_of( 'site.delete' ) );

		$last = $this->result( $r );
		$this->assertFalse( $last['ok'] );
		$this->assertStringNotContainsString( '10.200', $last['text'], 'What the agent said never reaches the customer.' );
		$this->assertNotSame( '', $last['text'] );
	}

	public function test_a_failure_that_crashes_in_the_cleanup_goes_on_with_the_same_cleanup(): void {
		$r = $this->account();
		$this->agent->fail_next( 'site.create' );
		$this->agent->pending( 'db.drop', 3 );

		( new HostingActions( $r ) )->queue( 'add_site', array( 'half', '', '', true ) );

		// Until the cleanup waits for the agent.
		for ( $i = 0; $i < 20; $i++ ) {
			$this->step( $r, 'add_site' );

			if ( null !== ( Steps::state( $this->fresh( $r ) )['fail'] ?? null ) ) {
				break;
			}
		}

		$this->assertNotNull( Steps::state( $this->fresh( $r ) )['fail'], 'The failure is kept in the task.' );
		$this->assertNotNull( Sites::find( $r->id(), 'half' ), 'Not removed before the cleanup is done.' );

		$this->drive( $this->fresh( $r ), 'add_site' );

		$this->assertNull( Sites::find( $r->id(), 'half' ) );
		$this->assertCount( 1, $this->job_ids( 'site.create', 'half' ), 'The website was not made again.' );
	}

	public function test_a_second_change_is_refused_while_one_runs_and_allowed_when_it_is_done(): void {
		$r = $this->account();
		$a = new HostingActions( $r );

		$first = $a->queue( 'add_site', array( 'one', '', '', false ) );
		$this->assertSame( 'pending', $first['status'] );

		foreach ( array( array( 'add_site', array( 'two', '', '', false ) ), array( 'set_php', array( 'w' . $r->id(), '8.4' ) ), array( 'remove_site', array( 'one', 'one' ) ), array( 'add_domain', array( 'w' . $r->id(), 'example.org' ) ) ) as $change ) {
			$err = ( new HostingActions( $this->fresh( $r ) ) )->queue( $change[0], $change[1] );
			$this->assertWPError( $err, $change[0] );
			$this->assertSame( 'busy', $err->get_error_code() );
			$this->assertSame( 'Wait until adding a website is done.', $err->get_error_message() );
		}

		$this->assertNull( Sites::find( $r->id(), 'two' ), 'The refused change did nothing.' );
		$this->assertNull( SiteDomains::find( 'example.org' ), 'Not even the local part of it.' );

		$this->drive( $r, 'add_site' );

		$this->assertSame( 'pending', ( new HostingActions( $this->fresh( $r ) ) )->queue( 'set_php', array( 'w' . $r->id(), '8.4' ) )['status'] );
	}

	public function test_the_routes_answer_202_with_the_task_and_409_wait_until_done(): void {
		$r    = $this->account();
		$user = (int) $r->license()['user_id'];
		$base = '/' . Routes::NAMESPACE . '/resources/' . $r->id() . '/hosting';

		wp_set_current_user( $user );
		$post = new \WP_REST_Request( 'POST', $base . '/sites' );
		$post->set_header( 'content-type', 'application/json' );
		$post->set_body( (string) wp_json_encode( array( 'slug' => 'fresh', 'install' => false ) ) );
		$res = rest_get_server()->dispatch( $post );

		$this->assertSame( 202, $res->get_status() );
		$this->assertSame( 'pending', $res->get_data()['status'] );
		$this->assertSame( 'Adding a website', $res->get_data()['task']['label'] );

		$overview = rest_get_server()->dispatch( new \WP_REST_Request( 'GET', $base ) )->get_data();
		$this->assertSame( 'add_site', $overview['task']['action'] );
		$pending  = array_values( array_filter( $overview['sites'], static fn( $s ) => ! empty( $s['pending'] ) ) );
		$this->assertCount( 1, $pending, 'The new website shows at once.' );
		$this->assertSame( 'fresh', $pending[0]['slug'] );
		$this->assertSame( 'Adding a website', $pending[0]['task']['step_label'] );

		$cache = new \WP_REST_Request( 'POST', $base . '/sites/w' . $r->id() . '/cache' );
		$refused = rest_get_server()->dispatch( $cache );
		$this->assertSame( 409, $refused->get_status() );
		$this->assertSame( 'Wait until adding a website is done.', $refused->get_data()['message'] );

		$this->run_jobs();
		$this->assertNull( rest_get_server()->dispatch( new \WP_REST_Request( 'GET', $base ) )->get_data()['task'] );
	}

	public function test_remove_website_is_a_task_and_a_crash_after_the_record_went_only_sends_the_edge(): void {
		$r = $this->account();
		( new HostingActions( $r ) )->add_site( 'bye', '', '', false );
		$this->agent->pending( 'site.delete', 2 );
		$this->agent->pending( 'db.drop', 2 );

		$answer = ( new HostingActions( $this->fresh( $r ) ) )->queue( 'remove_site', array( 'bye', 'bye' ) );
		$this->assertSame( 'pending', $answer['status'] );
		$this->assertSame( 'bye', $answer['removed'] );
		$this->assertNotNull( Sites::find( $r->id(), 'bye' ), 'Nothing is removed in the request.' );

		$words = $this->drive( $r, 'remove_site' );
		$this->assertContains( 'Removing the website', $words );
		$this->assertContains( 'Removing the database', $words );
		$this->assertNull( Sites::find( $r->id(), 'bye' ) );
		$this->assertNull( SiteDomains::find( 'bye.preview.example.test' ) );
		$this->assertNotContains( 'bye.preview.example.test', array_column( $this->agent->state()['edge'][ 'c' . $r->id() ], 'host' ) );
	}

	/**
	 * The marker of the change in progress made old: nobody has worked on it for longer than the shared limit.
	 *
	 * @param Resource $r Resource.
	 */
	private function age_the_marker( Resource $r ): void {
		$fresh  = $this->fresh( $r );
		$marker = (array) $fresh->get( Progress::MARKER );
		$fresh->set( array( Progress::MARKER => array_merge( $marker, array( 'at' => Jobs::now() - Progress::MARKER_TTL - 10 ) ) ) );
	}

	/**
	 * The waiting job of the account's change, the way Action Scheduler marks it when its process died.
	 *
	 * @param Resource $r Resource.
	 * @return int The action id.
	 */
	private function the_job_dies( Resource $r ): int {
		$ids = as_get_scheduled_actions( array( 'hook' => Jobs::ACT, 'group' => Jobs::GROUP, 'status' => \ActionScheduler_Store::STATUS_PENDING, 'per_page' => -1 ), 'ids' );
		$this->assertNotEmpty( $ids, 'A job waits.' );
		$id = (int) end( $ids );
		\ActionScheduler::store()->mark_failure( $id );
		Jobs::job_failed( $id, new \Error( 'Class not found' ) );

		return $id;
	}

	public function test_a_change_nobody_works_on_ends_failed_with_one_staff_alert_and_a_website_that_was_not_made_is_cleaned_up(): void {
		$r = $this->account();
		( new HostingActions( $r ) )->queue( 'add_site', array( 'stuck', '', '', true ) );
		$this->assertNotNull( Sites::find( $r->id(), 'stuck' ) );
		$mails = 0;
		add_filter(
			'pre_wp_mail',
			static function () use ( &$mails ) {
				++$mails;
				return true;
			}
		);

		// The job is gone (lost, never run) and the marker is old.
		$this->lose_the_job( $r, 'add_site' );
		$this->age_the_marker( $r );

		$this->assertNull( Progress::pending( $this->fresh( $r ) ), 'The shared controller ends it.' );
		$last = $this->result( $r );
		$this->assertFalse( $last['ok'] );
		$this->assertSame( 'add_site', $last['action'] );
		$this->assertSame( 1, $mails, 'Staff are told once.' );

		Progress::pending( $this->fresh( $r ) );
		$this->assertSame( 1, $mails, 'Not again.' );

		$this->assertSame( 'abandon_site', $this->task( $r )['action'], 'The clean-up of the half made website is a hosting change of its own, queued through the same path.' );
		$this->drive( $r, 'abandon_site' );
		$this->assertNull( Sites::find( $r->id(), 'stuck' ), 'The website that was never made is removed, step by step.' );

		$shown = Steps::last( $this->fresh( $r ) );
		$this->assertSame( 'add_site', $shown['action'], 'The customer reads that the add did not work, not that a clean-up went well.' );
		$this->assertStringContainsString( 'could not be made', $shown['text'] );
	}

	public function test_a_young_marker_without_a_waiting_job_still_holds_the_account_and_an_old_one_does_not(): void {
		$r = $this->account();
		( new HostingActions( $r ) )->queue( 'set_workers', array( 'w' . $r->id(), 3 ) );
		$this->lose_the_job( $r, 'set_workers' );

		$this->assertNotNull( $this->task( $r ) );
		$busy = ( new HostingActions( $this->fresh( $r ) ) )->queue( 'set_php', array( 'w' . $r->id(), '8.4' ) );
		$this->assertSame( 'Wait until changing the PHP workers is done.', $busy->get_error_message() );

		$this->age_the_marker( $r );

		$this->assertNull( $this->task( $r ) );
		$this->assertFalse( $this->result( $r )['ok'] );
		$this->assertSame( 'pending', ( new HostingActions( $this->fresh( $r ) ) )->queue( 'set_php', array( 'w' . $r->id(), '8.4' ) )['status'] );
	}

	public function test_the_shared_sweep_ends_a_stale_change_of_an_account_whose_customer_never_opens_the_page(): void {
		$r = $this->account();
		( new HostingActions( $r ) )->queue( 'purge_cache', array( 'w' . $r->id() ) );
		$this->lose_the_job( $r, 'purge_cache' );

		Jobs::sweep();
		$this->assertNotNull( $this->fresh( $r )->get( Progress::MARKER ), 'Not old enough.' );

		$this->age_the_marker( $r );
		Jobs::sweep();

		$this->assertNull( $this->fresh( $r )->get( Progress::MARKER ) );
		$this->assertFalse( $this->result( $r )['ok'] );
	}

	public function test_a_lost_job_of_a_change_is_queued_again_once_and_goes_on_where_it_stopped(): void {
		$r = $this->account();
		$this->agent->pending( 'site.create', 3 );
		( new HostingActions( $r ) )->queue( 'add_site', array( 'resumed', '', '', true ) );

		// Two runs, then the process dies (a deploy): Action Scheduler marks the job failed.
		$this->step( $r, 'add_site' );
		$this->step( $this->fresh( $r ), 'add_site' );
		$this->assertArrayHasKey( 'db_add_resumed', Steps::state( $this->fresh( $r ) )['memo'] );

		// The jobs the two runs queued are still waiting: only the last one is lost.
		$this->the_job_dies( $r );

		$this->assertNotNull( $this->task( $r ), 'The change is still in progress.' );
		$this->assertTrue( Jobs::acting( $r->id() ), 'Queued again.' );
		$this->assertArrayNotHasKey( 'ok', $this->result( $r ), 'No failed result.' );

		$this->drive( $r, 'add_site' );
		$this->assertEmpty( Sites::find( $r->id(), 'resumed' )['flags']['pending'] );
		$this->assertCount( 1, $this->job_ids( 'db.create', 'resumed' ), 'The step that was done was not started again.' );
		$this->assertTrue( $this->result( $r )['ok'] );
	}

	public function test_the_database_password_is_never_in_the_job_arguments_or_the_task(): void {
		$r        = $this->account();
		$password = (string) Crypto::decrypt( (string) $r->get( 'db_secret' ) );
		$this->assertNotSame( '', $password );

		( new HostingActions( $r ) )->queue( 'add_site', array( 'secret', '', '', true ) );
		$this->step( $r, 'add_site' );
		$this->step( $this->fresh( $r ), 'add_site' );

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$this->assertSame( '0', (string) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}actionscheduler_actions WHERE args LIKE %s", '%' . $wpdb->esc_like( $password ) . '%' ) ) );
		$this->drive( $r, 'add_site' );
		$this->assertStringNotContainsString( $password, (string) wp_json_encode( $this->result( $r ) ) );
	}

	public function test_a_job_for_a_task_that_is_gone_does_nothing(): void {
		$r = $this->account();

		$this->step( $r, 'set_php' );

		$this->assertNull( $this->task( $r ) );
		$this->assertNull( Sites::find( $r->id(), 'nothing' ) );
	}

	public function test_the_other_changes_are_tasks_with_their_local_work_done_in_the_request(): void {
		$r    = $this->account();
		$slug = 'w' . $r->id();

		$this->assertSame( 'invalid', ( new HostingActions( $r ) )->queue( 'set_php', array( $slug, '7.0' ) )->get_error_code(), 'The checks stay in the request.' );
		$this->assertNull( $this->task( $r ) );
		$this->assertSame( 'not_found', ( new HostingActions( $r ) )->queue( 'purge_cache', array( 'ghost' ) )->get_error_code() );
		$this->assertSame( 'limit', ( new HostingActions( $r ) )->queue( 'set_workers', array( $slug, 1 ) )->get_error_code() );

		$answer = ( new HostingActions( $r ) )->queue( 'add_domain', array( $slug, 'shop.example.org' ) );
		$this->assertSame( 'pending', $answer['status'] );
		$this->assertSame( 'external', $answer['kind'], 'The customer learns at once what to point the name at.' );
		$this->assertNotNull( SiteDomains::find( 'shop.example.org' ), 'The record and the DNS are the request\'s own work.' );
		$this->assertSame( array(), $this->agent->calls_of( 'site.domains' ) );
		$this->drive( $r, 'names' );
		$this->assertNotSame( array(), $this->agent->calls_of( 'site.domains' ) + $this->agent->calls_of( 'edge.apply' ) );
	}

	/**
	 * The hosting page as the customer's browser first gets it.
	 *
	 * @param Resource $r Resource.
	 */
	private function page( Resource $r ): string {
		ob_start();
		\BeltoftServices\Hosting\HostingAccount::render( $this->fresh( $r ) );

		return (string) ob_get_clean();
	}

	public function test_the_page_shows_the_new_website_at_once_with_its_step_and_locks_the_changes(): void {
		$r = $this->account();
		$this->agent->pending( 'db.create', 3 );
		( new HostingActions( $r ) )->queue( 'add_site', array( 'fresh-one', 'Fresh one', '', true ) );

		$html = $this->page( $r );
		$this->assertStringContainsString( 'Being set up', $html );
		$this->assertStringContainsString( 'Fresh one', $html );
		$this->assertStringContainsString( 'data-hosting-task=', $html, 'The script locks the buttons and watches the task from this.' );
		$this->assertStringContainsString( 'Wait until adding a website is done.', $html );

		$this->step( $r, 'add_site' );
		$this->assertStringContainsString( 'Making the database', $this->page( $r ) );

		$this->drive( $r, 'add_site' );
		$html = $this->page( $r );
		$this->assertStringNotContainsString( 'Being set up', $html );
		$this->assertStringNotContainsString( 'data-hosting-task=', $html );
		$this->assertStringContainsString( 'fresh-one', $html, 'The website is a panel of its own now.' );
		$this->assertStringNotContainsString( "\u{2014}", $html );
		$this->assertStringNotContainsString( "\u{00B7}", $html );
	}

	public function test_a_change_that_failed_is_told_on_the_page_with_its_reason(): void {
		$r = $this->account();
		$this->agent->fail_next( 'site.create' );
		( new HostingActions( $r ) )->queue( 'add_site', array( 'nope', 'Nope', '', true ) );
		$this->drive( $r, 'add_site' );

		$html = $this->page( $r );
		$this->assertStringContainsString( 'data-task-failed', $html );
		$this->assertStringContainsString( 'Adding a website. This did not finish.', $html );
		$this->assertStringNotContainsString( 'Being set up', $html );
	}

	public function test_backup_sizes_are_gigabytes_with_one_decimal_and_the_list_says_what_a_backup_holds(): void {
		$gib = 1073741824;

		$this->assertSame( '1.2 GB', \BeltoftServices\Hosting\HostingAccount::backup_size( (int) ( 1.24 * $gib ) ) );
		$this->assertSame( '1.6 GB', \BeltoftServices\Hosting\HostingAccount::backup_size( (int) ( 1.56 * $gib ) ) );
		$this->assertSame( '2.0 GB', \BeltoftServices\Hosting\HostingAccount::backup_size( 2 * $gib ) );
		$this->assertSame( '0.5 GB', \BeltoftServices\Hosting\HostingAccount::backup_size( (int) ( 0.5 * $gib ) ) );
		$this->assertSame( '12.0 GB', \BeltoftServices\Hosting\HostingAccount::backup_size( 12 * $gib ) );
		$this->assertSame( 'Under 0.1 GB', \BeltoftServices\Hosting\HostingAccount::backup_size( 5 * 1048576 ) );
		$this->assertSame( '0.0 GB', \BeltoftServices\Hosting\HostingAccount::backup_size( 0 ) );

		$r = $this->account();
		$r->set(
			array(
				'plan'        => array_merge( (array) $r->get( 'plan' ), array( 'backup_days' => 14 ) ),
				'backup_list' => array(
					array( 'volid' => 'pbs-store:backup/ct/1000/2026-11-14', 'ctime' => time() - 86400, 'kind' => 'nightly', 'size' => (int) ( 1.38 * $gib ) ),
					array( 'volid' => 'pbs-store:backup/ct/1000/2026-11-15', 'ctime' => time(), 'kind' => 'nightly', 'size' => (int) ( 1.24 * $gib ) ),
				),
			)
		);
		$html = $this->page( $r );

		$this->assertStringContainsString( '<th>Data in backup</th>', $html );
		$this->assertStringContainsString( '<td>1.2 GB</td>', $html );
		$this->assertStringContainsString( '<td>1.4 GB</td>', $html );
		$this->assertStringContainsString( 'Each backup holds your whole hosting as it was that night. Backups are kept apart from your disk and do not use its space.', $html );
	}

	public function test_the_hosting_driver_offers_no_customer_action_and_runs_the_tasks_as_internal_ones(): void {
		$r      = $this->account();
		$driver = $this->driver;

		$this->assertSame( array(), $driver->customer_actions( $r ), 'The generic action route and buttons never reach a hosting change.' );
		$this->assertInstanceOf( \BeltoftServices\Drivers\InternalActions::class, $driver );
		$this->assertEqualsCanonicalizing( array( 'add_site', 'remove_site', 'set_php', 'php_rollback', 'set_workers', 'purge_cache', 'set_country_block', 'names', 'set_sftp_password', 'add_ssh_key', 'remove_ssh_key', 'abandon_site', 'remove_domain', 'wordpress_address', 'staging_create', 'staging_refresh', 'staging_push', 'staging_undo', 'staging_delete', 'backup_now', 'backup_delete', 'restore_backup', 'restore_undo', 'resend_country' ), $driver->internal_actions() );
		$this->assertTrue( $driver->act( $r, 'bogus', array() )->is_failed() );
	}
}

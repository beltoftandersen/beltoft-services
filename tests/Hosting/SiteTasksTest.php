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
use BeltoftServices\Hosting\Tasks;
use BeltoftServices\Jobs;
use BeltoftServices\Resource;

/**
 * Tasks, and the job mode and request mode of the hosting action groups.
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
	 * One run of the account's task, as the runner makes it. No waiting: the job answers poll and the test looks again.
	 *
	 * @param Resource $r      Resource.
	 * @param string   $action Job action.
	 */
	private function step( Resource $r, string $action ): void {
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
			$task = Tasks::get( $this->fresh( $r ) );

			if ( null === $task ) {
				return $seen;
			}

			$seen[] = Tasks::view( $task )['step_label'];
		}

		$this->fail( 'The task did not end in ' . $most . ' runs: ' . (string) wp_json_encode( Tasks::get( $this->fresh( $r ) ) ) );
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
		as_unschedule_all_actions( Jobs::ACT, array( $r->id(), $action, array() ), Jobs::GROUP );
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
		$this->assertNull( Tasks::get( $this->fresh( $r ) ) );

		foreach ( array( 'db.create', 'site.create', 'site.wp_install' ) as $op ) {
			$this->assertCount( 1, $this->job_ids( $op, 'blog' ), $op . ' ran once, however often it was read.' );
		}

		$last = $this->fresh( $r )->get( Tasks::LAST );
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
		$this->assertTrue( $this->fresh( $r )->get( Tasks::LAST )['ok'] );
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
			$task = Tasks::get( $this->fresh( $r ) );
			$this->assertNotNull( $task, 'The task reached the step before it ended.' );

			if ( $words === Tasks::view( $task )['step_label'] ) {
				break;
			}
		}

		$this->assertSame( $words, Tasks::view( $task )['step_label'] );

		foreach ( $memoed as $name ) {
			$this->assertArrayHasKey( $name, $task['memo'], 'What was done is kept in the task.' );
		}

		// The process is gone: nothing but the stored task is left. A new run goes on, and the steps already done are not asked again.
		$this->drive( $this->fresh( $r ), 'add_site' );

		$this->assertEmpty( Sites::find( $r->id(), 'again' )['flags']['pending'], $op );
		$this->assertTrue( $this->fresh( $r )->get( Tasks::LAST )['ok'], $op );

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
		$this->assertNull( Tasks::get( $this->fresh( $r ) ) );
		$this->assertNotSame( array(), $this->agent->calls_of( 'db.drop' ), 'The database it made is dropped.' );
		$this->assertNotSame( array(), $this->agent->calls_of( 'site.delete' ) );

		$last = $this->fresh( $r )->get( Tasks::LAST );
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

			if ( null !== ( Tasks::get( $this->fresh( $r ) )['fail'] ?? null ) ) {
				break;
			}
		}

		$this->assertNotNull( Tasks::get( $this->fresh( $r ) )['fail'], 'The failure is kept in the task.' );
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

	public function test_the_watchdog_gives_up_a_task_nobody_works_on_and_cleans_up_a_website_that_was_not_made(): void {
		$r = $this->account();
		( new HostingActions( $r ) )->queue( 'add_site', array( 'stuck', '', '', true ) );
		$this->assertNotNull( Sites::find( $r->id(), 'stuck' ) );

		// The job is gone (lost, never run) and the task is old.
		$this->lose_the_job( $r, 'add_site' );
		$fresh = $this->fresh( $r );
		$task  = Tasks::get( $fresh );
		$task['started'] = Jobs::now() - 3600;
		$fresh->set( array( Tasks::META => $task ) );

		$this->assertTrue( Tasks::watchdog( $this->fresh( $r ) ) );

		$last = $this->fresh( $r )->get( Tasks::LAST );
		$this->assertFalse( $last['ok'] );
		$this->assertStringContainsString( 'stopped before it was finished', $last['text'] );
		$this->assertSame( 'abandon_site', Tasks::get( $this->fresh( $r ) )['action'], 'The cleanup of the half made website is a task of its own.' );

		$this->drive( $r, 'abandon_site' );
		$this->assertNull( Sites::find( $r->id(), 'stuck' ), 'The website that was never made is removed, step by step.' );
		$this->assertFalse( Tasks::watchdog( $this->fresh( $r ) ), 'Nothing left to give up.' );
	}

	public function test_a_young_task_without_a_waiting_job_still_holds_the_account_and_an_old_one_does_not(): void {
		$r = $this->account();
		( new HostingActions( $r ) )->queue( 'set_workers', array( 'w' . $r->id(), 3 ) );
		$this->lose_the_job( $r, 'set_workers' );

		$this->assertNotNull( Tasks::busy( $this->fresh( $r ) ) );
		$this->assertFalse( Tasks::watchdog( $this->fresh( $r ) ), 'Not yet half an hour.' );

		$fresh           = $this->fresh( $r );
		$task            = Tasks::get( $fresh );
		$task['started'] = Jobs::now() - Tasks::STALE - 5;
		$fresh->set( array( Tasks::META => $task ) );

		$this->assertNull( Tasks::busy( $this->fresh( $r ) ) );
		$this->assertTrue( Tasks::watchdog( $this->fresh( $r ) ) );
		$this->assertNull( Tasks::get( $this->fresh( $r ) ) );
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
		$this->assertStringNotContainsString( $password, (string) wp_json_encode( $this->fresh( $r )->get( Tasks::LAST ) ) );
	}

	public function test_a_job_for_a_task_that_is_gone_does_nothing(): void {
		$r = $this->account();

		$this->step( $r, 'set_php' );

		$this->assertNull( Tasks::get( $this->fresh( $r ) ) );
		$this->assertNull( Sites::find( $r->id(), 'nothing' ) );
	}

	public function test_the_other_changes_are_tasks_with_their_local_work_done_in_the_request(): void {
		$r    = $this->account();
		$slug = 'w' . $r->id();

		$this->assertSame( 'invalid', ( new HostingActions( $r ) )->queue( 'set_php', array( $slug, '7.0' ) )->get_error_code(), 'The checks stay in the request.' );
		$this->assertNull( Tasks::get( $this->fresh( $r ) ) );
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
}

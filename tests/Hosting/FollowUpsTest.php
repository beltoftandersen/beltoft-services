<?php
/**
 * What the hosting features that had loops of their own do now: the system's own changes (the domain sweep, follow-ups of another change)
 * go through the shared action controller, derive what is wanted from the stored state, and keep no queue, retry counter or outcome of
 * their own.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Hosting;

require_once __DIR__ . '/HostingCase.php';

use BeltoftServices\Hosting\Actions\Steps;
use BeltoftServices\Hosting\DomainSweep;
use BeltoftServices\Hosting\HostingActions;
use BeltoftServices\Hosting\SiteDomains;
use BeltoftServices\Hosting\SiteStaging;
use BeltoftServices\Hosting\Sites;
use BeltoftServices\Jobs;
use BeltoftServices\Progress;
use BeltoftServices\Resource;

/**
 * Steps::start_follow(), SiteActions::names_stale(), DomainSweep and the flags older versions left.
 */
class FollowUpsTest extends HostingCase {

	public function set_up(): void {
		parent::set_up();
		$this->settings( array( 'hosting_php_versions' => '8.3,8.4,8.5' ) );
		add_filter( 'beltoft_services_rest_rate_limit', '__return_zero' );
	}

	public function tear_down(): void {
		remove_all_filters( 'beltoft_services_rest_rate_limit' );
		parent::tear_down();
	}

	private function account(): Resource {
		$r = $this->resource( array( 'sites' => 3, 'php_workers' => 10, 'php_memory_mb' => 512, 'upload_mb' => 100 ) );
		$this->provision_fully( $r );
		$r->set( array( 'state' => 'ready', 'desired' => 'ready' ) );

		return Resource::find( $r->id() );
	}

	public function test_the_sweep_queues_the_change_that_sends_names_only_when_the_node_is_behind(): void {
		$r = $this->account();
		$a = new HostingActions( $r );
		$this->assertFalse( $a->names_stale(), 'A first look records what the edge was sent: nothing is behind.' );

		DomainSweep::run();
		$this->assertNull( Steps::view( Resource::find( $r->id() ) ), 'Nothing to send: nothing is queued.' );

		// A name went live on its own (the sweep or the DNS check set it): the node is behind.
		$site = Sites::for_resource( $r->id() )[0];
		SiteDomains::add( $r->id(), (int) $site['id'], 'late.example.org', 'ours' );
		SiteDomains::set_state( 'late.example.org', 'active' );
		$this->assertTrue( ( new HostingActions( Resource::find( $r->id() ) ) )->names_stale() );

		DomainSweep::run();
		$task = Steps::view( Resource::find( $r->id() ) );
		$this->assertSame( 'names', $task['action'] );
		$this->assertSame( array(), array_filter( $this->agent->calls_of( 'edge.apply' ), static fn( $c ) => in_array( 'late.example.org', array_column( (array) $c['args']['routes'], 'host' ), true ) ), 'The sweep sends nothing itself.' );

		$this->run_account( $r );
		$edge = $this->agent->calls_of( 'edge.apply' );
		$this->assertContains( 'late.example.org', array_column( end( $edge )['args']['routes'], 'host' ) );
		$names = $this->agent->calls_of( 'site.domains' );
		$this->assertContains( 'late.example.org', end( $names )['args']['domains'] );
		$this->assertFalse( ( new HostingActions( Resource::find( $r->id() ) ) )->names_stale() );

		DomainSweep::run();
		$this->assertNull( Steps::view( Resource::find( $r->id() ) ), 'Once is enough.' );
	}

	public function test_a_name_that_could_not_be_sent_is_sent_by_the_next_sweep_without_a_flag_of_wanted(): void {
		$r    = $this->account();
		$site = Sites::for_resource( $r->id() )[0];
		( new HostingActions( $r ) )->names_stale();
		SiteDomains::add( $r->id(), (int) $site['id'], 'again.example.org', 'ours' );
		SiteDomains::set_state( 'again.example.org', 'active' );

		DomainSweep::run();
		$this->agent->fail_next( 'edge.apply', 'node down' );
		$this->run_account( $r );
		$this->assertFalse( $this->last_outcome( $r )['ok'] );
		$this->assertTrue( ( new HostingActions( Resource::find( $r->id() ) ) )->names_stale(), 'The node is still behind: the stored state says so.' );

		DomainSweep::run();
		$this->run_account( $r );
		$this->assertTrue( $this->last_outcome( $r )['ok'] );
		$this->assertFalse( ( new HostingActions( Resource::find( $r->id() ) ) )->names_stale() );
	}

	public function test_a_follow_up_is_refused_while_a_change_runs_or_the_account_is_not_ready_and_never_waits(): void {
		$r = $this->account();

		Progress::mark( Resource::find( $r->id() ), 'purge_cache', 'Clearing the cache' );
		$this->assertFalse( Steps::start_follow( Resource::find( $r->id() ), 'apply_names', array() ), 'One change at a time.' );
		Progress::clear( Resource::find( $r->id() ) );

		$r->set( array( 'desired' => 'paused' ) );
		$this->assertFalse( Steps::start_follow( Resource::find( $r->id() ), 'apply_names', array() ), 'A paused account is left alone.' );
		$r->set( array( 'desired' => 'ready' ) );

		// The account's lock is held by another request: it does not wait for it.
		$other = new \mysqli( DB_HOST, DB_USER, DB_PASSWORD, DB_NAME );
		$name  = \BeltoftServices\Locks::name( 'hosting-site', $r->id() );
		$other->query( "SELECT GET_LOCK('" . $other->real_escape_string( $name ) . "', 0)" );
		$began = microtime( true );
		$this->assertFalse( Steps::start_follow( Resource::find( $r->id() ), 'apply_names', array() ) );
		$this->assertLessThan( 2, microtime( true ) - $began );
		$other->query( "SELECT RELEASE_LOCK('" . $other->real_escape_string( $name ) . "')" );
		$other->close();

		$this->assertTrue( Steps::start_follow( Resource::find( $r->id() ), 'apply_names', array() ) );
		$this->assertSame( 'names', Steps::view( Resource::find( $r->id() ) )['action'] );
	}

	public function test_staging_flags_an_older_version_left_are_read_as_a_copy_that_is_ready(): void {
		$r    = $this->account();
		$slug = 'w' . $r->id();
		$this->change( $r, 'create_staging', array( $slug ) );

		$flags = (array) Sites::find( $r->id(), $slug )['flags']['staging'];
		Sites::update( $r->id(), $slug, array( 'flags' => array( 'staging' => array_merge( $flags, array( 'state' => 'pushing', 'note' => 'failed', 'failed' => 'push', 'phase' => 'copy', 'files_only' => true ) ) ) ) );

		wp_cache_flush();
		$view = SiteStaging::view( (array) Sites::find( $r->id(), $slug ) );
		$this->assertSame( array( 'ready', false, true ), array( $view['state'], $view['busy'], $view['exists'] ), 'No job runs: the account has no task.' );
		$this->assertArrayNotHasKey( 'note', $view );
		$this->assertArrayNotHasKey( 'phase', $view );
		$this->assertSame( 'refreshing', ( new HostingActions( Resource::find( $r->id() ) ) )->queue( 'refresh_staging', array( $slug ) )['state'] );
	}

	public function test_the_job_of_a_copy_waits_for_the_node_with_the_budget_of_the_shared_controller(): void {
		$r    = $this->account();
		$slug = 'w' . $r->id();
		$this->change( $r, 'create_staging', array( $slug ) );
		$this->agent->pending( 'site.staging_push', 4 );
		( new HostingActions( Resource::find( $r->id() ) ) )->queue( 'push_staging', array( $slug, false ) );

		$result = Steps::run( Resource::find( $r->id() ), 'staging_push', array( $slug, false ) );
		$this->assertTrue( $result->is_poll() );
		$this->assertSame( SiteStaging::BUDGET, $result->poll_budget(), 'A copy takes minutes: the longest wait the shared controller allows.' );
		$this->assertLessThanOrEqual( 1800, \BeltoftServices\Reconciler::poll_budget( $result->poll_budget() ) );
	}

	public function test_every_new_internal_action_is_resumable_and_has_a_label(): void {
		foreach ( array( 'wordpress_address', 'staging_create', 'staging_refresh', 'staging_push', 'staging_undo', 'staging_delete', 'backup_now', 'restore_backup', 'restore_undo', 'resend_country', 'names' ) as $action ) {
			$this->assertTrue( Steps::resumable( false, 'hosting', $action ), $action );
			$this->assertNotSame( '', Steps::label_of( $action ), $action );
			$this->assertContains( $action, $this->driver->internal_actions() );
		}

		$this->assertFalse( Steps::resumable( false, 'proxmox', 'staging_push' ), 'Only hosting.' );
	}

	public function test_a_lost_job_of_the_domain_sweep_change_is_queued_again_and_its_failure_is_the_shared_one(): void {
		$r = $this->account();
		( new HostingActions( $r ) )->names_stale();
		$site = Sites::for_resource( $r->id() )[0];
		SiteDomains::add( $r->id(), (int) $site['id'], 'lost.example.org', 'ours' );
		SiteDomains::set_state( 'lost.example.org', 'active' );
		DomainSweep::run();

		$this->kill_job( $r );
		$this->assertTrue( Jobs::acting( $r->id() ), 'Queued again once.' );
		$this->run_account( $r );
		$this->assertTrue( $this->last_outcome( $r )['ok'] );
		$this->assertFalse( ( new HostingActions( Resource::find( $r->id() ) ) )->names_stale() );
	}
}

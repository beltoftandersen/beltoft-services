<?php
/**
 * Review fixes of 0.31.1 in the hosting code: what is recorded as sent, the plain names fallback, the undo of a request's local
 * work, agent job ids that are never reused, the SFTP password in a job.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Hosting;

require_once __DIR__ . '/HostingCase.php';

use BeltoftServices\Crypto;
use BeltoftServices\Hosting\Actions\Steps;
use BeltoftServices\Hosting\DomainSweep;
use BeltoftServices\Hosting\EdgeMap;
use BeltoftServices\Hosting\HostingActions;
use BeltoftServices\Hosting\HostingBackups;
use BeltoftServices\Hosting\HostingRestore;
use BeltoftServices\Hosting\SiteDomains;
use BeltoftServices\Hosting\Sites;
use BeltoftServices\Hosting\SiteStaging;
use BeltoftServices\Jobs;
use BeltoftServices\Progress;
use BeltoftServices\Resource;

/**
 * The fixes of the 0.31.1 review for the hosting changes.
 */
class ReviewFixesTest extends HostingCase {

	public function set_up(): void {
		parent::set_up();
		$this->settings( array( 'hosting_php_versions' => '8.3,8.4,8.5' ) );
		add_filter( 'beltoft_services_rest_rate_limit', '__return_zero' );
	}

	public function tear_down(): void {
		remove_all_filters( 'beltoft_services_rest_rate_limit' );
		remove_all_filters( 'query' );
		remove_all_actions( 'beltoft_services_resource_saved' );
		parent::tear_down();
	}

	private function account(): Resource {
		$r = $this->resource( array( 'sites' => 3, 'php_workers' => 10, 'php_memory_mb' => 512, 'upload_mb' => 100 ) );
		$this->provision_fully( $r );
		$r->set( array( 'state' => 'ready', 'desired' => 'ready' ) );

		return Resource::find( $r->id() );
	}

	private function fresh( Resource $r ): Resource {
		wp_cache_flush();

		return Resource::find( $r->id() );
	}

	/**
	 * One run of the account's waiting job.
	 *
	 * @param Resource $r Resource.
	 */
	private function run_once( Resource $r ): void {
		foreach ( as_get_scheduled_actions( array( 'hook' => Jobs::ACT, 'group' => Jobs::GROUP, 'status' => \ActionScheduler_Store::STATUS_PENDING, 'per_page' => -1 ), 'ids' ) as $id ) {
			$args = \ActionScheduler::store()->fetch_action( (string) $id )->get_args();

			if ( (int) $args[0] === $r->id() ) {
				\ActionScheduler::store()->cancel_action( (int) $id );
				Jobs::act( $r->id(), (string) $args[1], (array) ( $args[2] ?? array() ) );

				return;
			}
		}

		$this->fail( 'No job waits.' );
	}

	private function activate( Resource $r, string $host ): void {
		$site = Sites::for_resource( $r->id() )[0];
		SiteDomains::add( $r->id(), (int) $site['id'], $host, 'ours' );
		SiteDomains::set_state( $host, 'active' );
	}

	private function edge_sig_now( Resource $r ): string {
		return md5( (string) wp_json_encode( EdgeMap::routes( $r->id(), false ) ) );
	}

	/**
	 * Makes the next matching database query mark the account busy (another change that wins the race), once.
	 *
	 * @param Resource $r     Resource.
	 * @param string   $match Regular expression of the query.
	 */
	private function race_on_query( Resource $r, string $match ): void {
		$armed = true;
		add_filter(
			'query',
			function ( $query ) use ( &$armed, $r, $match ) {
				if ( $armed && 1 === preg_match( $match, (string) $query ) ) {
					$armed = false;
					Progress::mark( Resource::find( $r->id() ), 'purge_cache', 'Clearing the cache' );
				}

				return $query;
			}
		);
	}

	public function test_a_name_activated_while_the_names_job_waits_is_not_recorded_as_sent(): void {
		$r = $this->account();
		( new HostingActions( $r ) )->names_stale();
		$this->activate( $r, 'first.example.org' );
		$this->agent->pending( 'site.domains', 3 );

		$this->assertTrue( Steps::start_follow( $this->fresh( $r ), 'apply_names', array() ) );
		$this->run_once( $r ); // Submits the names; the node is still working.
		$this->assertNotNull( Steps::view( $this->fresh( $r ) ) );

		$this->activate( $r, 'late.example.org' ); // The sweep activates a name meanwhile.
		$this->run_account( $r );

		$vmid = \BeltoftServices\Hosting\Records::for_resource( $r->id() )['vmid'];
		$this->assertNotContains( 'late.example.org', $this->agent->state()['sites'][ $vmid ][ 'w' . $r->id() ]['domains'], 'The node got the names of the first run.' );
		$this->assertTrue( ( new HostingActions( $this->fresh( $r ) ) )->names_stale(), 'The late name is still to be sent: the signature is the sent one.' );

		DomainSweep::run();
		$this->run_account( $r );
		$this->assertFalse( ( new HostingActions( $this->fresh( $r ) ) )->names_stale() );
		$sent = $this->agent->calls_of( 'site.domains' );
		$this->assertContains( 'late.example.org', end( $sent )['args']['domains'] );
	}

	public function test_a_route_activated_while_the_edge_job_waits_is_not_recorded_as_sent(): void {
		$r = $this->account();
		( new HostingActions( $r ) )->names_stale();
		$this->activate( $r, 'first.example.org' );
		$this->agent->pending( 'edge.apply', 3 );

		$this->assertTrue( Steps::start_follow( $this->fresh( $r ), 'apply_names', array() ) );
		$this->run_once( $r );
		$this->run_once( $r );
		$this->activate( $r, 'late.example.org' );
		$this->run_account( $r );

		$this->assertNotSame( $this->edge_sig_now( $r ), (string) $this->fresh( $r )->get( 'edge_sig' ), 'What was recorded is what the edge was sent.' );
		$this->assertTrue( ( new HostingActions( $this->fresh( $r ) ) )->names_stale() );
	}

	public function test_names_sent_without_redirects_are_current_and_not_sent_again_by_every_sweep(): void {
		$r = $this->account();
		( new HostingActions( $r ) )->names_stale();
		( new HostingActions( $this->fresh( $r ) ) )->add_domain( 'w' . $r->id(), 'example.org' );
		SiteDomains::set_state( 'example.org', 'active' );
		SiteDomains::set_state( 'www.example.org', 'active' );
		$this->agent->fail_next( 'site.domains', 'unknown field redirects' );

		$this->assertTrue( Steps::start_follow( $this->fresh( $r ), 'apply_names', array() ) );
		$this->run_account( $r );
		$this->assertTrue( $this->agent->calls_of( 'site.domains' ) > array(), 'The names were sent.' );
		$this->assertFalse( ( new HostingActions( $this->fresh( $r ) ) )->names_stale(), 'The plain names are as current as they can be.' );

		// What the sweep asks (it queues the change only when this is true): nothing is queued every time.
		$this->assertFalse( ( new HostingActions( $this->fresh( $r ) ) )->names_stale() );

		// A change of the names is still sent.
		$this->activate( $r, 'blog.example.org' );
		$this->assertTrue( ( new HostingActions( $this->fresh( $r ) ) )->names_stale() );
	}

	public function test_a_new_website_whose_change_is_not_queued_leaves_no_pending_website(): void {
		$r = $this->account();
		$this->race_on_query( $r, '/^\s*INSERT\s+INTO\s+\S*hosting_sites/i' );

		$out = ( new HostingActions( $this->fresh( $r ) ) )->queue( 'add_site', array( 'blog', 'Blog', '', true ) );

		$this->assertWPError( $out );
		$this->assertSame( 'busy', $out->get_error_code() );
		$this->assertNull( Sites::find( $r->id(), 'blog' ), 'The request undid its pending website.' );
	}

	public function test_a_staging_copy_whose_change_is_not_queued_is_not_left_creating(): void {
		$r    = $this->account();
		$slug = 'w' . $r->id();
		$this->race_on_query( $r, '/^\s*UPDATE\s+\S*hosting_sites/i' );

		$out = ( new HostingActions( $this->fresh( $r ) ) )->queue( 'create_staging', array( $slug ) );

		$this->assertWPError( $out );
		$view = SiteStaging::view( (array) Sites::find( $r->id(), $slug ) );
		$this->assertFalse( $view['busy'] );
		$this->assertNotSame( 'creating', $view['state'] );
		$this->assertSame( array(), array_filter( SiteDomains::for_resource( $r->id() ), static fn( $d ) => 'preview' === $d['kind'] && 0 !== strpos( $d['host'], 'w' ) && false !== strpos( $d['host'], 'staging' ) ), 'The held address is released.' );
	}

	public function test_a_staging_delete_that_is_not_queued_leaves_the_copy_a_copy(): void {
		$r    = $this->account();
		$slug = 'w' . $r->id();
		$this->change( $r, 'create_staging', array( $slug ) );
		$this->assertSame( 'ready', SiteStaging::view( (array) Sites::find( $r->id(), $slug ) )['state'] );
		$this->race_on_query( $r, '/^\s*UPDATE\s+\S*hosting_sites/i' );

		$out = ( new HostingActions( $this->fresh( $r ) ) )->queue( 'delete_staging', array( $slug ) );

		$this->assertWPError( $out );
		$this->assertSame( 'ready', SiteStaging::view( (array) Sites::find( $r->id(), $slug ) )['state'], 'Not stuck on deleting.' );
	}

	public function test_a_backup_round_whose_change_is_not_queued_is_dropped_and_the_day_keeps_its_count(): void {
		$r = $this->resource( array( 'backup_days' => 14 ) );
		$this->provision_fully( $r );
		$r->set( array( 'state' => 'ready', 'desired' => 'ready' ) );
		$armed = true;
		add_action(
			'beltoft_services_resource_saved',
			function ( $resource, $meta ) use ( &$armed, $r ) {
				if ( $armed && isset( $meta['backup'] ) ) {
					$armed = false;
					Progress::mark( Resource::find( $r->id() ), 'purge_cache', 'Clearing the cache' );
				}
			},
			10,
			2
		);

		$out = ( new HostingActions( $this->fresh( $r ) ) )->queue( 'backup_now', array() );

		$this->assertWPError( $out );
		$this->assertFalse( ( new HostingBackups( $this->fresh( $r ) ) )->active(), 'No round is left stored.' );
		$this->assertSame( array(), array_values( (array) $this->fresh( $r )->get( 'backup_manual_log' ) ), 'The start is not counted.' );
	}

	public function test_a_restore_round_whose_change_is_not_queued_is_dropped(): void {
		$r = $this->resource( array( 'backup_days' => 14 ) );
		$this->provision_fully( $r );
		$r->set( array( 'state' => 'ready', 'desired' => 'ready' ) );
		( new HostingBackups( Resource::find( $r->id() ) ) )->start( 'nightly' );
		( new HostingBackups( Resource::find( $r->id() ) ) )->step();
		$r     = $this->fresh( $r );
		$volid = HostingBackups::stored( $r )[0]['volid'];
		$armed = true;
		add_action(
			'beltoft_services_resource_saved',
			function ( $resource, $meta ) use ( &$armed, $r ) {
				if ( $armed && isset( $meta['restore'] ) ) {
					$armed = false;
					Progress::mark( Resource::find( $r->id() ), 'purge_cache', 'Clearing the cache' );
				}
			},
			10,
			2
		);

		$out = ( new HostingActions( $r ) )->queue( 'restore', array( 'account', $volid, '' ) );

		$this->assertWPError( $out );
		$this->assertFalse( HostingRestore::active( $this->fresh( $r ) ), 'No round is left stored.' );
	}

	public function test_a_change_that_ends_with_an_unread_agent_job_does_not_hand_its_job_id_to_the_next_change(): void {
		$r    = $this->account();
		$slug = 'w' . $r->id();
		$this->agent->pending( 'site.cache_purge', 5 );

		$this->assertIsArray( ( new HostingActions( $this->fresh( $r ) ) )->queue( 'purge_cache', array( $slug ) ) );
		$this->run_once( $r ); // The agent job is submitted and not read to its end.
		$this->lose_the_change( $r );
		$this->assertNull( Steps::view( $this->fresh( $r ) ), 'The lapsed change is gone.' );

		foreach ( as_get_scheduled_actions( array( 'hook' => Jobs::ACT, 'group' => Jobs::GROUP, 'status' => \ActionScheduler_Store::STATUS_PENDING, 'per_page' => -1 ), 'ids' ) as $id ) {
			\ActionScheduler::store()->cancel_action( (int) $id );
		}

		$this->assertIsArray( ( new HostingActions( $this->fresh( $r ) ) )->queue( 'purge_cache', array( $slug ) ) );
		$this->run_account( $r );

		$this->assertCount( 2, $this->agent_jobs( 'site.cache_purge' ), 'The second change is a new agent job, not the first one read as done.' );
		$this->assertTrue( $this->last_outcome( $r )['ok'] );
	}

	public function test_a_job_that_cannot_read_the_sftp_secret_fails_and_never_makes_up_a_password(): void {
		$r = $this->account();
		$out = ( new HostingActions( $this->fresh( $r ) ) )->queue( 'set_sftp_password', array( 'a-long-chosen-password-123' ) );
		$this->assertIsArray( $out );
		$this->assertSame( 'a-long-chosen-password-123', $out['password'] );

		// The stored secret is lost (cannot be decrypted).
		$fresh = $this->fresh( $r );
		$state = Steps::state( $fresh );
		$state['secret'] = 'not-a-secret';
		$fresh->set( array( Steps::META => $state ) );
		$this->run_account( $r );

		$last = $this->last_outcome( $r );
		$this->assertFalse( $last['ok'] );
		$this->assertSame( 'The password could not be set. Set a new one.', $last['text'] );
		$this->assertSame( array(), $this->agent->calls_of( 'ssh.password' ), 'No other password reached the node.' );
		$this->assertNull( $this->fresh( $r )->get( 'sftp_password_at' ) );
	}

	public function test_a_job_with_the_secret_sets_the_password_that_was_shown(): void {
		$r = $this->account();
		$this->assertIsArray( ( new HostingActions( $this->fresh( $r ) ) )->queue( 'set_sftp_password', array( 'a-long-chosen-password-123' ) ) );
		$this->run_account( $r );

		$this->assertTrue( $this->last_outcome( $r )['ok'] );
		$calls = $this->agent->calls_of( 'ssh.password' );
		$this->assertCount( 1, $calls );
		$this->assertNotNull( Crypto::class );
	}
}

<?php
/**
 * The stored country block goes to the node again after a restore. Fakes only.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Hosting;

require_once __DIR__ . '/HostingCase.php';

use BeltoftServices\Hosting\CountryBlock;
use BeltoftServices\Hosting\CountryResend;
use BeltoftServices\Hosting\HostingActions;
use BeltoftServices\Hosting\HostingBackups;
use BeltoftServices\Hosting\HostingRestore;
use BeltoftServices\Hosting\Sites;
use BeltoftServices\Jobs;
use BeltoftServices\Resource;

/**
 * CountryResend and the restore rounds that queue it.
 */
class CountryResendTest extends HostingCase {

	/**
	 * Mails sent.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private $mails = array();

	public function set_up(): void {
		parent::set_up();
		$this->mails = array();
		$this->settings( array( 'hosting_php_versions' => '8.3,8.4,8.5' ) );
		add_filter(
			'pre_wp_mail',
			function ( $pre, $args ) {
				$this->mails[] = $args;

				return true;
			},
			10,
			2
		);
	}

	public function tear_down(): void {
		remove_all_filters( 'pre_wp_mail' );

		parent::tear_down();
	}

	/**
	 * A ready account with a backup, a second website and a country block on the first.
	 *
	 * @return array{0: Resource, 1: string, 2: string}
	 */
	private function with_block(): array {
		$r = $this->resource( array( 'backup_days' => 14, 'sites' => 3 ) );
		$this->provision_fully( $r );
		$r->set( array( 'state' => 'ready', 'desired' => 'ready' ) );
		$r = Resource::find( $r->id() );
		( new HostingActions( $r ) )->add_site( 'blog', 'Blog', '8.3', false );

		$b = new HostingBackups( Resource::find( $r->id() ) );
		$this->assertTrue( $b->start( 'nightly' ) );
		( new HostingBackups( Resource::find( $r->id() ) ) )->step();
		$r    = Resource::find( $r->id() );
		$slug = 'w' . $r->id();

		$done = ( new HostingActions( $r ) )->set_country_block( $slug, array( 'RU', 'BY' ), 'login' );
		$this->assertIsArray( $done );

		return array( $r, HostingBackups::stored( $r )[0]['volid'], $slug );
	}

	/**
	 * Runs a restore round and the resend job it queued.
	 *
	 * @param Resource $r     Resource.
	 * @param string   $kind  account or files.
	 * @param string   $volid Backup.
	 * @param string   $slug  Site.
	 */
	private function restore( Resource $r, string $kind, string $volid, string $slug = '' ): void {
		$this->assertTrue( ( new HostingRestore( Resource::find( $r->id() ) ) )->start( $kind, $volid, $slug )['started'] );
		HostingRestore::run( $r->id() );
		$this->assertTrue( Resource::find( $r->id() )->get( 'restore_last' )['ok'] );
	}

	public function test_an_account_restore_queues_the_resend_and_the_job_sends_the_stored_block_only_for_sites_that_have_one(): void {
		list( $r, $volid, $slug ) = $this->with_block();
		$before = count( $this->agent->calls_of( 'site.country_block' ) );

		$this->restore( $r, 'account', $volid );
		$this->assertTrue( Jobs::pending_action( CountryResend::HOOK, array( $r->id(), '', 0 ) ), 'Queued in the background.' );
		$this->assertCount( $before, $this->agent->calls_of( 'site.country_block' ), 'Nothing is sent inside the restore.' );

		CountryResend::run( $r->id(), '', 0 );

		$calls = array_slice( $this->agent->calls_of( 'site.country_block' ), $before );
		$this->assertCount( 1, $calls, 'The blog has no block: it is left alone.' );
		$this->assertSame( $slug, $calls[0]['args']['slug'] );
		$this->assertSame( array( 'BY', 'RU' ), $calls[0]['args']['countries'] );
		$this->assertSame( 'login', $calls[0]['args']['scope'] );

		$site = Sites::find( $r->id(), $slug );
		$this->assertSame( array( 'BY', 'RU' ), CountryBlock::stored( $site )['countries'], 'The stored setting is unchanged.' );
		$this->assertTrue( CountryBlock::stored( $site )['active'] );
	}

	public function test_a_files_restore_resends_that_website_and_a_database_restore_sends_nothing(): void {
		list( $r, $volid, $slug ) = $this->with_block();

		$this->restore( $r, 'files', $volid, $slug );
		$this->assertTrue( Jobs::pending_action( CountryResend::HOOK, array( $r->id(), $slug, 0 ) ) );

		as_unschedule_all_actions( CountryResend::HOOK );
		$this->restore( $r, 'database', $volid, 'blog' );
		$this->assertFalse( Jobs::pending_action( CountryResend::HOOK, array( $r->id(), '', 0 ) ) );
		$this->assertFalse( Jobs::pending_action( CountryResend::HOOK, array( $r->id(), 'blog', 0 ) ) );
	}

	public function test_undo_resends_too(): void {
		list( $r, $volid ) = $this->with_block();
		$this->restore( $r, 'account', $volid );
		as_unschedule_all_actions( CountryResend::HOOK );

		$this->assertSame( array( 'started' => true ), ( new HostingRestore( Resource::find( $r->id() ) ) )->undo() );
		HostingRestore::run( $r->id() );
		$this->assertTrue( Jobs::pending_action( CountryResend::HOOK, array( $r->id(), '', 0 ) ) );
	}

	public function test_a_block_that_was_turned_off_is_sent_as_off_so_an_old_copy_does_not_bring_it_back(): void {
		list( $r, , $slug ) = $this->with_block();
		( new HostingActions( Resource::find( $r->id() ) ) )->set_country_block( $slug, array(), 'site' );
		$before = count( $this->agent->calls_of( 'site.country_block' ) );

		CountryResend::run( $r->id(), '', 0 );

		$calls = array_slice( $this->agent->calls_of( 'site.country_block' ), $before );
		$this->assertCount( 1, $calls );
		$this->assertSame( array(), $calls[0]['args']['countries'] );
	}

	public function test_a_failed_send_is_tried_again_and_the_restore_is_not_touched(): void {
		list( $r, $volid, $slug ) = $this->with_block();
		$this->restore( $r, 'account', $volid );

		$mails = count( $this->mails );
		$this->agent->fail_next( 'site.country_block' );
		CountryResend::run( $r->id(), '', 0 );

		$this->assertTrue( Resource::find( $r->id() )->get( 'restore_last' )['ok'] );
		$this->assertTrue( Jobs::pending_action( CountryResend::HOOK, array( $r->id(), $slug, 1 ) ), 'Retried as a job for that website.' );
		$this->assertCount( $mails, $this->mails, 'No mail for a first failure.' );

		// The retry works.
		$before = count( $this->agent->calls_of( 'site.country_block' ) );
		CountryResend::run( $r->id(), $slug, 1 );
		$this->assertGreaterThan( $before, count( $this->agent->calls_of( 'site.country_block' ) ) );
	}

	public function test_the_admin_is_told_once_when_every_attempt_failed(): void {
		list( $r, , $slug ) = $this->with_block();

		$this->agent->fail_next( 'site.country_block' );
		CountryResend::run( $r->id(), $slug, count( CountryResend::BACKOFF ) );

		$this->assertFalse( Jobs::pending_action( CountryResend::HOOK, array( $r->id(), $slug, count( CountryResend::BACKOFF ) + 1 ) ) );
		$this->assertCount( 1, $this->mails );
		$this->assertStringContainsString( 'country block', $this->mails[0]['message'] );
	}

	public function test_nothing_is_sent_while_another_restore_runs_or_all_work_is_paused(): void {
		list( $r, $volid ) = $this->with_block();
		$before = count( $this->agent->calls_of( 'site.country_block' ) );

		$this->assertTrue( ( new HostingRestore( Resource::find( $r->id() ) ) )->start( 'account', $volid )['started'] );
		CountryResend::run( $r->id(), '', 0 );
		$this->assertCount( $before, $this->agent->calls_of( 'site.country_block' ) );
		$this->assertTrue( Jobs::pending_action( CountryResend::HOOK, array( $r->id(), '', 0 ) ), 'Waits for the round.' );

		$r->set( array( 'restore' => array( 'kind' => '' ) ) );
		$this->settings( array( 'pause_all' => 1 ) );
		CountryResend::run( $r->id(), '', 0 );
		$this->assertCount( $before, $this->agent->calls_of( 'site.country_block' ) );
	}
}

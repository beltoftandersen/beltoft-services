<?php
/**
 * The stored country block goes to the node again after a restore: a change of the account on the shared controller, queued when the
 * restore has ended well. Fakes only.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Hosting;

require_once __DIR__ . '/HostingCase.php';

use BeltoftServices\Hosting\Actions\Steps;
use BeltoftServices\Hosting\CountryBlock;
use BeltoftServices\Hosting\HostingActions;
use BeltoftServices\Hosting\HostingBackups;
use BeltoftServices\Hosting\Sites;
use BeltoftServices\Jobs;
use BeltoftServices\Resource;

/**
 * The change `resend_country` and the restores that queue it.
 */
class CountryResendTest extends HostingCase {

	/**
	 * Mails sent.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private $mails = array();

	/**
	 * The clock of the plugin (two restores a second apart would name their safety points alike).
	 *
	 * @var int
	 */
	private $clock = 0;

	public function set_up(): void {
		parent::set_up();
		$this->mails = array();
		$this->clock = time();
		add_filter( 'beltoft_services_now', fn() => $this->clock );
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
		remove_all_filters( 'beltoft_services_now' );

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

		$done = $this->change( $r, 'set_country_block', array( $slug, array( 'RU', 'BY' ), 'login', false ) );
		$this->assertIsArray( $done );
		$this->assertTrue( $this->last_outcome( $r )['ok'] );

		return array( $r, HostingBackups::stored( $r )[0]['volid'], $slug );
	}

	/**
	 * Runs a restore and everything it queued (the resend of the country blocks).
	 *
	 * @param Resource $r     Resource.
	 * @param string   $kind  account, files or database.
	 * @param string   $volid Backup.
	 * @param string   $slug  Site.
	 */
	private function restore( Resource $r, string $kind, string $volid, string $slug = '' ): void {
		$this->assertTrue( $this->change( $r, 'restore', array( $kind, $volid, $slug ) )['started'] );
		$this->assertTrue( Resource::find( $r->id() )->get( 'restore_last' )['ok'], (string) wp_json_encode( Resource::find( $r->id() )->get( 'restore_last' ) ) );
	}

	/**
	 * One run of the account's waiting job.
	 *
	 * @param Resource $r Resource.
	 */
	private function run_once_only( Resource $r ): void {
		foreach ( as_get_scheduled_actions( array( 'hook' => Jobs::ACT, 'group' => Jobs::GROUP, 'status' => \ActionScheduler_Store::STATUS_PENDING, 'per_page' => -1 ), 'ids' ) as $id ) {
			$args = \ActionScheduler::store()->fetch_action( (string) $id )->get_args();

			if ( (int) $args[0] === $r->id() ) {
				\ActionScheduler::store()->cancel_action( (int) $id );
				Jobs::act( $r->id(), (string) $args[1], (array) $args[2] );

				return;
			}
		}
	}

	public function test_an_account_restore_is_followed_by_a_resend_that_sends_the_stored_block_only_for_sites_that_have_one(): void {
		list( $r, $volid, $slug ) = $this->with_block();
		$before = count( $this->agent->calls_of( 'site.country_block' ) );

		$this->assertTrue( $this->change( $r, 'restore', array( 'account', $volid, '' ) )['started'] );

		$calls = array_slice( $this->agent->calls_of( 'site.country_block' ), $before );
		$this->assertCount( 1, $calls, 'The blog has no block: it is left alone.' );
		$this->assertSame( $slug, $calls[0]['args']['slug'] );
		$this->assertSame( array( 'BY', 'RU' ), $calls[0]['args']['countries'] );
		$this->assertSame( 'login', $calls[0]['args']['scope'] );

		$out = $this->last_outcome( $r );
		$this->assertSame( array( 'resend_country', true ), array( $out['action'], $out['ok'] ), 'The change after the restore, through the shared path.' );

		$site = Sites::find( $r->id(), $slug );
		$this->assertSame( array( 'BY', 'RU' ), CountryBlock::stored( $site )['countries'], 'The stored setting is unchanged.' );
		$this->assertTrue( CountryBlock::stored( $site )['active'] );
	}

	public function test_nothing_is_sent_inside_the_restore_and_the_resend_is_queued_after_it(): void {
		list( $r, $volid ) = $this->with_block();
		$before = count( $this->agent->calls_of( 'site.country_block' ) );

		( new HostingActions( $r ) )->queue( 'restore', array( 'account', $volid, '' ) );

		// The restore job runs, and only it: the resend is a change of its own that starts when the restore has ended.
		for ( $i = 0; $i < 60 && 'restore_backup' === ( Steps::view( Resource::find( $r->id() ) )['action'] ?? '' ); $i++ ) {
			$this->assertCount( $before, $this->agent->calls_of( 'site.country_block' ), 'Nothing is sent while the restore runs.' );
			$this->run_once_only( $r );
		}

		$this->assertTrue( Resource::find( $r->id() )->get( 'restore_last' )['ok'] );
		$task = Steps::view( Resource::find( $r->id() ) );
		$this->assertSame( 'resend_country', $task['action'] ?? '', 'Queued when the restore ended.' );
		$this->assertCount( $before, $this->agent->calls_of( 'site.country_block' ) );

		$this->run_account( $r );
		$this->assertCount( $before + 1, $this->agent->calls_of( 'site.country_block' ) );
	}

	public function test_a_files_restore_resends_that_website_and_a_database_restore_sends_nothing(): void {
		list( $r, $volid, $slug ) = $this->with_block();
		$before = count( $this->agent->calls_of( 'site.country_block' ) );

		$this->restore( $r, 'files', $volid, $slug );
		$this->assertCount( $before + 1, $this->agent->calls_of( 'site.country_block' ) );
		$this->assertSame( 'resend_country', $this->last_outcome( $r )['action'] );

		$this->clock += HOUR_IN_SECONDS;
		$this->restore( $r, 'database', $volid, 'blog' );
		$this->assertCount( $before + 1, $this->agent->calls_of( 'site.country_block' ), 'A database restore sends nothing.' );
		$this->assertSame( 'restore_backup', $this->last_outcome( $r )['action'], 'No change followed it.' );
	}

	public function test_undo_resends_too(): void {
		list( $r, $volid ) = $this->with_block();
		$this->restore( $r, 'account', $volid );
		$before = count( $this->agent->calls_of( 'site.country_block' ) );
		$this->clock += HOUR_IN_SECONDS;

		$this->assertTrue( $this->change( $r, 'undo' )['started'] );
		$this->assertCount( $before + 1, $this->agent->calls_of( 'site.country_block' ) );
		$this->assertSame( 'resend_country', $this->last_outcome( $r )['action'] );
	}

	public function test_a_block_that_was_turned_off_is_sent_as_off_so_an_old_copy_does_not_bring_it_back(): void {
		list( $r, , $slug ) = $this->with_block();
		$this->change( $r, 'set_country_block', array( $slug, array(), 'site', false ) );
		$before = count( $this->agent->calls_of( 'site.country_block' ) );

		$this->change( $r, 'resend_country', array( '' ) );

		$calls = array_slice( $this->agent->calls_of( 'site.country_block' ), $before );
		$this->assertCount( 1, $calls );
		$this->assertSame( array(), $calls[0]['args']['countries'] );
	}

	public function test_a_failed_send_does_not_touch_the_restore_tells_staff_once_and_not_the_customer(): void {
		list( $r, $volid ) = $this->with_block();
		$mails = count( $this->mails );
		$this->agent->fail_next( 'site.country_block', 'node said no' );

		$this->restore( $r, 'account', $volid );

		$out = $this->last_outcome( $r );
		$this->assertSame( array( 'resend_country', false ), array( $out['action'], $out['ok'] ) );
		$staff = array_filter( array_slice( $this->mails, $mails ), static fn( $m ) => false !== strpos( (string) $m['message'], 'country block' ) );
		$this->assertCount( 1, $staff, 'One mail for staff, from the shared alert.' );
		$this->assertNull( Steps::last( Resource::find( $r->id() ) ), 'The customer did not ask for it: nothing is said on the page.' );
		$this->assertTrue( Resource::find( $r->id() )->get( 'restore_last' )['ok'] );
	}

	public function test_nothing_is_sent_while_another_change_runs_or_all_work_is_paused(): void {
		list( $r, $volid ) = $this->with_block();
		$before = count( $this->agent->calls_of( 'site.country_block' ) );

		( new HostingActions( $r ) )->queue( 'restore', array( 'account', $volid, '' ) );
		$this->assertFalse( Steps::start_follow( Resource::find( $r->id() ), 'resend_country', array( '' ) ), 'The change that runs holds the account.' );
		$this->assertSame( 'busy', ( new HostingActions( Resource::find( $r->id() ) ) )->queue( 'resend_country', array( '' ) )->get_error_code() );
		$this->assertCount( $before, $this->agent->calls_of( 'site.country_block' ) );

		$this->run_account( $r );
		$sent = count( $this->agent->calls_of( 'site.country_block' ) );
		$this->assertSame( $before + 1, $sent );

		$this->settings( array( 'pause_all' => 1 ) );
		( new HostingActions( Resource::find( $r->id() ) ) )->queue( 'resend_country', array( '' ) );
		$this->run_once_only( $r );
		$this->assertCount( $sent, $this->agent->calls_of( 'site.country_block' ), 'All work is paused: nothing is sent.' );
	}
}

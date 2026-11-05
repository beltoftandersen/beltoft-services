<?php
/**
 * The hosting usage job: stats, database size, storage rules, loaders, PHP end of support.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Hosting;

require_once __DIR__ . '/HostingCase.php';

use BeltoftServices\Emails\Emails;
use BeltoftServices\Hosting\Config;
use BeltoftServices\Hosting\HostingAccount;
use BeltoftServices\Hosting\HostingUsage;
use BeltoftServices\Hosting\Sites;
use BeltoftServices\Resource;

/**
 * HostingUsage.
 */
class UsageTest extends HostingCase {

	private const GB = 1073741824;

	/**
	 * Mails sent.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private $mails = array();

	/**
	 * The clock.
	 *
	 * @var int
	 */
	private $clock;

	public function set_up(): void {
		parent::set_up();
		$this->mails = array();
		$this->clock = strtotime( '2027-01-10 12:00:00 UTC' );
		add_filter( 'pre_wp_mail', function ( $pre, $args ) {
			$this->mails[] = $args;

			return true;
		}, 10, 2 );
		add_filter( 'beltoft_services_now', fn() => $this->clock );
	}

	public function tear_down(): void {
		remove_all_filters( 'pre_wp_mail' );
		remove_all_filters( 'beltoft_services_now' );

		parent::tear_down();
	}

	/**
	 * A ready account on a 10 GB plan.
	 *
	 * @param array<string, mixed> $plan Plan overrides.
	 */
	private function account( array $plan = array() ): Resource {
		$r = $this->resource( array_merge( array( 'disk_gb' => 10 ), $plan ) );
		$this->provision_fully( $r );
		$r->set( array( 'state' => 'ready', 'desired' => 'ready' ) );

		return Resource::find( $r->id() );
	}

	/**
	 * Sets what the node and the agent report, then runs one sample.
	 *
	 * @param Resource $r        Resource.
	 * @param float    $disk_gb  Container disk in GB.
	 * @param float    $db_gb    Database size in GB.
	 */
	private function sample( Resource $r, float $disk_gb, float $db_gb ): Resource {
		$this->node->set_usage( 1000, array( 'cpu' => 0.25, 'mem' => 512 * 1048576, 'maxmem' => 2048 * 1048576, 'disk' => (int) ( $disk_gb * self::GB ), 'maxdisk' => 10 * self::GB ) );
		$this->agent->set_db_size( 'c' . $r->id() . '_w' . $r->id(), (int) ( $db_gb * self::GB ) );
		( new HostingUsage( Resource::find( $r->id() ) ) )->sample();

		return Resource::find( $r->id() );
	}

	/**
	 * Hosting notices sent, as the kinds found in their bodies.
	 *
	 * @return string[]
	 */
	private function last_notice(): string {
		$all = $this->notices();

		return (string) end( $all );
	}

	/**
	 * Hosting notice bodies.
	 *
	 * @return string[]
	 */
	private function notices(): array {

		return array_values( array_map( static fn( $m ) => (string) preg_replace( '/\s+/', ' ', (string) $m['message'] ), array_filter( $this->mails, static fn( $m ) => false !== strpos( (string) $m['subject'], 'About your hosting' ) ) ) );
	}

	public function test_stats_and_database_sizes_are_recorded_with_the_time(): void {
		$r = $this->sample( $this->account(), 3, 1 );

		$usage = $r->get( 'usage' );
		$this->assertSame( 0.25, $usage['cpu'] );
		$this->assertSame( 512, $usage['mem_mb'] );
		$this->assertSame( 3072, $usage['disk_mb'] );
		$this->assertSame( 1024, $usage['db_mb'] );
		$this->assertSame( $this->clock, $usage['at'] );
		$this->assertSame( 'running', $usage['status'] );
		$this->assertEqualsWithDelta( 40.0, HostingUsage::storage_percent( $r ), 0.01, 'Files plus databases over the plan storage.' );

		$this->assertCount( 0, $this->notices() );
		$this->assertNotSame( array(), $this->node->calls_to( '#/status/current$#', 'GET' ), 'The stats come from the node.' );
	}

	public function test_a_failed_call_keeps_the_last_value_and_never_throws(): void {
		$r = $this->sample( $this->account(), 3, 1 );
		$this->node->fail_next( 'GET', '#/status/current$#', array( 'status' => 500 ) );
		$this->agent->fail_next( 'db.size', 'busy' );

		( new HostingUsage( $r ) )->sample();

		$usage = Resource::find( $r->id() )->get( 'usage' );
		$this->assertSame( 3072, $usage['disk_mb'] );
		$this->assertSame( 1024, $usage['db_mb'] );
	}

	public function test_no_reading_means_no_percent_and_no_mail(): void {
		$r = $this->account();
		$this->assertNull( HostingUsage::storage_percent( $r ) );
		$this->assertCount( 0, $this->notices() );
	}

	public function test_ninety_percent_sends_one_warning_and_a_later_climb_warns_again(): void {
		$r = $this->sample( $this->account(), 8, 1.5 ); // 95 percent.
		$this->assertCount( 1, $this->notices() );
		$this->assertStringContainsString( '95% full', $this->notices()[0] );
		$this->assertStringContainsString( 'Nothing has been stopped', $this->notices()[0] );

		$this->sample( $r, 8, 1.6 );
		$this->assertCount( 1, $this->notices(), 'Once while it stays up.' );

		$this->sample( $r, 3, 1 ); // 40 percent: reset.
		$this->sample( $r, 9, 1 ); // 100 percent.
		$this->assertCount( 2, $this->notices() );
	}

	public function test_over_110_percent_tells_about_the_7_days_and_restricts_only_after_them(): void {
		$r = $this->sample( $this->account(), 10, 2 ); // 117 percent.
		$kinds = $this->notices();
		$this->assertCount( 2, $kinds, 'The 90 percent warning and the 7 day notice.' );
		$this->assertStringContainsString( 'after 7 days', $kinds[1] );
		$this->assertSame( array(), $this->agent->state()['readonly'] );

		$this->clock += 6 * DAY_IN_SECONDS;
		$this->sample( $r, 10, 2 );
		$this->assertSame( array(), $this->agent->state()['readonly'], 'Not before 7 days.' );

		$this->clock += 2 * DAY_IN_SECONDS;
		$r = $this->sample( $r, 10, 2 );
		$this->assertTrue( $this->agent->state()['readonly'][ 'c' . $r->id() ] );
		$this->assertStringContainsString( 'can not save new content', $this->last_notice() );
		$this->assertCount( 3, $this->notices() );

		$this->sample( $r, 10, 2 );
		$this->assertCount( 3, $this->notices(), 'Told once.' );
		$this->assertCount( 1, $this->agent->calls_of( 'db.readonly' ) );
	}

	public function test_back_within_the_plan_lifts_the_restriction_and_tells_the_customer(): void {
		$r = $this->sample( $this->account(), 10, 2 );
		$this->clock += 8 * DAY_IN_SECONDS;
		$r = $this->sample( $r, 10, 2 );
		$this->assertTrue( $this->agent->state()['readonly'][ 'c' . $r->id() ] );

		$r = $this->sample( $r, 10.5, 0 ); // 105 percent: still restricted.
		$this->assertTrue( $this->agent->state()['readonly'][ 'c' . $r->id() ] );

		$r = $this->sample( $r, 6, 1 ); // 70 percent.
		$this->assertFalse( $this->agent->state()['readonly'][ 'c' . $r->id() ] );
		$this->assertStringContainsString( 'can save new content again', $this->last_notice() );
		$this->assertSame( 0, (int) $r->get( 'storage' )['restricted'] );
		$this->assertSame( 0, (int) $r->get( 'storage' )['over_since'] );
	}

	public function test_dropping_under_110_before_the_7_days_resets_the_clock(): void {
		$r = $this->sample( $this->account(), 10, 2 );
		$this->clock += 5 * DAY_IN_SECONDS;
		$r = $this->sample( $r, 10, 0.5 ); // 105 percent.
		$this->clock += 5 * DAY_IN_SECONDS;
		$this->sample( $r, 10, 2 ); // Over again: a new 7 days.
		$this->assertSame( array(), $this->agent->state()['readonly'] );
	}

	public function test_loader_states_are_kept_and_refused_ones_show_in_the_panel(): void {
		$r    = $this->account();
		$slug = 'w' . $r->id();
		$this->agent->set_loaders( array( '1000/' . $slug => array( 'state' => 'refused:symlink', 'ts' => 5 ), '1000/other' => array( 'state' => 'ok', 'ts' => 6 ), 'bad key' => array( 'state' => 'error' ) ) );

		$r = $this->sample( $r, 3, 1 );

		$this->assertSame( array( '1000/' . $slug, '1000/other' ), array_keys( $r->get( 'loaders' ) ) );
		$this->assertSame( array( $slug => 'refused:symlink' ), HostingUsage::loader_problems( $r ) );

		ob_start();
		HostingAccount::render( $r );
		$html = (string) ob_get_clean();
		$this->assertStringContainsString( 'needs the hosting loader file', $html );
		$this->assertStringContainsString( 'refused:symlink', $html );

		$this->agent->set_loaders( array() );
		$r = $this->sample( $r, 3, 1 );
		$this->assertSame( array(), HostingUsage::loader_problems( $r ) );
	}

	// -- PHP end of support -----------------------------------------------------------------------

	public function test_the_version_table_is_a_setting(): void {
		$this->assertSame( strtotime( '2027-12-31 23:59:59 UTC' ), Config::php_end( '8.3' ) );
		$this->assertNull( Config::php_end( '7.4' ) );
		$this->settings( array( 'hosting_php_eol' => '8.3=2027-02-01, 8.4=bad' ) );
		$this->assertSame( strtotime( '2027-02-01 23:59:59 UTC' ), Config::php_end( '8.3' ) );
		$this->assertNull( Config::php_end( '8.4' ) );
	}

	public function test_php_notices_come_90_and_30_days_before_once_each(): void {
		$this->settings( array( 'hosting_php_default' => '8.4', 'hosting_php_eol' => '8.3=2027-02-10' ) );
		$r = $this->account();
		Sites::update( $r->id(), 'w' . $r->id(), array( 'php' => '8.3' ) );

		$this->clock = strtotime( '2026-10-01 12:00:00 UTC' );
		$r           = $this->sample( $r, 3, 1 );
		$this->assertCount( 0, $this->notices(), 'More than 90 days away.' );

		$this->clock = strtotime( '2026-11-20 12:00:00 UTC' ); // About 82 days.
		$r           = $this->sample( $r, 3, 1 );
		$this->assertCount( 1, $this->notices() );
		$this->assertStringContainsString( 'runs on PHP 8.3', $this->notices()[0] );
		$this->assertStringContainsString( 'after 10 February 2027', $this->notices()[0] );
		$this->assertStringContainsString( 'change it to PHP 8.4', $this->notices()[0] );

		$r = $this->sample( $r, 3, 1 );
		$this->assertCount( 1, $this->notices(), 'Once.' );

		$this->clock = strtotime( '2027-01-20 12:00:00 UTC' ); // About 21 days.
		$r           = $this->sample( $r, 3, 1 );
		$this->assertCount( 2, $this->notices() );
		$this->sample( $r, 3, 1 );
		$this->assertCount( 2, $this->notices() );
		$this->assertSame( '8.3', Sites::find( $r->id(), 'w' . $r->id() )['php'], 'Not switched yet.' );
	}

	public function test_at_the_end_date_the_site_moves_to_the_default_with_a_rollback_marker(): void {
		$this->settings( array( 'hosting_php_default' => '8.4', 'hosting_php_eol' => '8.3=2027-02-10' ) );
		$r = $this->account();
		Sites::update( $r->id(), 'w' . $r->id(), array( 'php' => '8.3' ) );

		$this->clock = strtotime( '2027-02-11 08:00:00 UTC' );
		$r           = $this->sample( $r, 3, 1 );

		$this->assertSame( '8.4', Sites::find( $r->id(), 'w' . $r->id() )['php'] );
		$this->assertSame( '8.4', $this->agent->state()['sites'][1000][ 'w' . $r->id() ]['php'] );
		$marker = $r->get( 'php_rollback' )[ 'w' . $r->id() ];
		$this->assertSame( '8.3', $marker['from'] );
		$this->assertSame( $this->clock + 7 * DAY_IN_SECONDS, $marker['until'] );
		$this->assertStringContainsString( 'now runs on PHP 8.4', $this->last_notice() );

		$this->sample( $r, 3, 1 );
		$this->assertCount( 1, $this->agent->calls_of( 'site.php' ), 'Switched once.' );
	}

	public function test_a_site_already_on_the_default_or_with_no_date_is_left_alone(): void {
		$this->settings( array( 'hosting_php_default' => '8.4', 'hosting_php_eol' => '8.4=2027-02-10' ) );
		$r = $this->account(); // Runs 8.4, the default.
		$this->clock = strtotime( '2027-02-11 08:00:00 UTC' );
		$this->sample( $r, 3, 1 );
		$this->assertCount( 0, $this->agent->calls_of( 'site.php' ) );
		$this->assertCount( 0, $this->notices() );
	}

	// -- The job ----------------------------------------------------------------------------------

	public function test_the_job_samples_ready_accounts_only_and_respects_the_kill_switch(): void {
		$ready  = $this->account();
		$paused = $this->account();
		$paused->set( array( 'desired' => 'paused' ) );
		$this->node->set_usage( 1000, array( 'disk' => self::GB ) );

		$this->settings( array( 'pause_all' => true ) );
		HostingUsage::run();
		$this->assertNull( Resource::find( $ready->id() )->get( 'usage' ) );

		$this->settings( array( 'pause_all' => false ) );
		HostingUsage::run();
		$this->assertNotNull( Resource::find( $ready->id() )->get( 'usage' ) );
		$this->assertNull( Resource::find( $paused->id() )->get( 'usage' ), 'A paused account is not asked.' );
	}

	public function test_the_job_is_hooked_and_scheduled_hourly_once(): void {
		$this->assertNotFalse( has_action( HostingUsage::HOOK, array( HostingUsage::class, 'run' ) ) );
		as_unschedule_all_actions( HostingUsage::HOOK, array(), 'beltoft-services' );

		HostingUsage::schedule();
		HostingUsage::schedule();

		$this->assertCount( 1, as_get_scheduled_actions( array( 'hook' => HostingUsage::HOOK, 'status' => \ActionScheduler_Store::STATUS_PENDING ), 'ids' ) );
		as_unschedule_all_actions( HostingUsage::HOOK, array(), 'beltoft-services' );
		$this->assertSame( 'beltoft_services_hosting_notice', Emails::get( 'beltoft_services_hosting_notice' )->id );
	}

	public function test_the_nightly_slot_comes_from_the_agent_when_it_has_one(): void {
		$r = $this->account();
		$this->assertSame( 30 + ( $r->id() % 300 ), \BeltoftServices\Hosting\HostingBackups::slot_minute( $r ), 'By account id until the agent says.' );

		$this->agent->set_backup_slots( array( 1000 => array( 'at' => '02:15', 'offset_minutes' => 105, 'disk_mb' => 800 ) ) );
		$r = $this->sample( $r, 3, 1 );
		$this->assertSame( 135, (int) $r->get( 'backup_slot' ) );
		$this->assertSame( 135, \BeltoftServices\Hosting\HostingBackups::slot_minute( $r ) );

		$this->agent->set_backup_slots( array( 1000 => array( 'at' => '07:00', 'offset_minutes' => 0, 'disk_mb' => 1 ) ) );
		$r->set( array( 'backup_slot' => 0 ) );
		$r = $this->sample( $r, 3, 1 );
		$this->assertSame( 30 + ( $r->id() % 300 ), \BeltoftServices\Hosting\HostingBackups::slot_minute( $r ), 'A slot outside the night window is ignored.' );
	}
}

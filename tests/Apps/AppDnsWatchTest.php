<?php
/** Apps on the customer's own domain in the shared DNS watch: the wait as before, and the daily look after the app is live. @package BeltoftServices */

namespace BeltoftServices\Tests\Apps;

use BeltoftServices\Apps\AppDnsWatch;
use BeltoftServices\Apps\AppInstall;
use BeltoftServices\Apps\AppOwnDomain;
use BeltoftServices\Apps\AppTab;
use BeltoftServices\Domains\DnsWatch;
use BeltoftServices\Domains\DnsWatchJobs;
use BeltoftServices\Domains\DnsWatchSettings;
use BeltoftServices\Domains\DnsWatchState;
use BeltoftServices\Domains\FakeDns;
use BeltoftServices\Jobs;
use BeltoftServices\Proxmox\Network\IpPool;
use BeltoftServices\Proxmox\Vm\OsField;
use BeltoftServices\Proxmox\Vm\Schema;
use BeltoftServices\Resource;
use BeltoftServices\Tests\Proxmox\Network\MonitoringTestCase;

require_once __DIR__ . '/../Proxmox/Network/MonitoringTestCase.php';

/**
 * Documentation values only. DNS answers come from a filter, Proxmox is FakeClient, no mail leaves the test.
 */
final class AppDnsWatchTest extends MonitoringTestCase {

	private const NAME = 'shop.elsewhere.test';
	private const IP   = '192.0.2.10';

	/** @var array<string, array<int, string>|\WP_Error> Answers by type. */
	private array $answers = array();

	/** @var array<int, string> Lookups made. */
	private array $lookups = array();

	/** @var array<int, array<string, mixed>> Mails sent. */
	private array $mails = array();

	public function set_up(): void {
		parent::set_up();
		delete_option( FakeDns::OPTION );
		$this->answers = array( 'A' => array(), 'AAAA' => array() );
		$this->lookups = array();
		$this->mails   = array();
		add_filter(
			'beltoft_services_dns_resolve',
			function ( $pre, $name, $type ) {
				$this->lookups[] = $type . ' ' . $name;
				return $this->answers[ $type ];
			},
			20,
			3
		);
		add_filter(
			'pre_wp_mail',
			function ( $pre, $args ) {
				$this->mails[] = $args;
				return true;
			},
			10,
			2
		);
		$this->settings( array( 'domain_nameservers' => 'ns1.example.net,ns2.example.net' ) );
		$this->clear_ips();
		$this->assertTrue( \BeltoftServices\Proxmox\Pve\Clusters::save( 'lab', array(
			'label' => 'Lab', 'api_url' => 'https://pve.example.invalid:8006',
			'token_id' => 'svc@pve!beltoft', 'token_secret' => 'secret-secret-secret-1',
			'client' => 'fake', 'nodes' => 'node-a', 'storage' => 'disk-store',
			'cloudinit_storage' => 'ci-store', 'bridge' => 'vmbr9',
			'gateway' => '192.0.2.1', 'nameservers' => '192.0.2.53', 'prefix' => '24',
			'vmid_min' => '5000', 'vmid_max' => '5099',
			'templates' => "9000 = Debian 12\n9001 = Ubuntu 24.04",
			'vendor_snippet' => 'local:snippets/vm-vendor-cloudinit-v2.yaml',
			'apps_ready' => 1, 'apps_enabled' => array( 'docker', 'wordpress', 'coolify' ),
			'template_families_text' => "9001 = ubuntu-24.04\n9000 = debian-12",
		) ) );
	}

	public function tear_down(): void {
		$this->clear_ips();
		delete_option( FakeDns::OPTION );
		unset( $GLOBALS['wp']->query_vars['vps'] );
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	private function clear_ips(): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- isolated test table reset.
		$wpdb->query( 'DELETE FROM ' . Schema::tables()['ips'] );
		delete_transient( IpPool::FREE_TRANSIENT );
	}

	/**
	 * A ready server with the wordpress app waiting for an own domain.
	 *
	 * @param array<string, mixed> $meta Meta over the defaults.
	 */
	private function waiting( array $meta = array(), int $vmid = 5000 ): Resource {
		$r  = $this->ready_vm( $vmid, 'christian.beltoft@gmail.com' );
		$ip = '192.0.2.' . ( 10 + $vmid - 5000 );
		$this->assertTrue( \BeltoftServices\Proxmox\Vm\VmRecords::move( $r->id(), 'node-a' ) );
		$this->assertTrue( $r->set( array_merge(
			array(
				'ip'          => $ip,
				'app_id'      => 'wordpress',
				'app_version' => '7.1.4',
				'app_state'   => 'pending',
				OsField::META => 9001,
				'plan'        => array( 'cluster' => 'lab', 'template' => 9001, 'cores' => 2, 'memory_mb' => 4096, 'disk_gb' => 50 ),
			),
			array_filter( AppOwnDomain::fresh_wait( self::NAME ), static fn( $v ) => null !== $v ),
			$meta
		) ) );
		IpPool::add( $ip );
		$this->assertNotNull( IpPool::hold( $r->id() ) );
		$this->assertTrue( IpPool::assign( $r->id() ) );

		return Resource::find( $r->id() );
	}

	private function pending( int $id, string $hook ): int {
		return count( as_get_scheduled_actions( array( 'hook' => $hook, 'args' => array( $id ), 'group' => Jobs::GROUP, 'status' => \ActionScheduler_Store::STATUS_PENDING, 'per_page' => 10 ), 'ids' ) );
	}

	private function backdate( Resource $r, string $key, int $seconds ): void {
		$this->assertTrue( $r->set( array( $key => time() - $seconds ) ) );
	}

	private function f(): AppDnsWatch {
		$f = DnsWatch::feature( AppDnsWatch::ID );
		$this->assertInstanceOf( AppDnsWatch::class, $f );

		return $f;
	}

	/** A server whose app was installed on the name and whose name was found. */
	private function live( array $meta = array(), int $vmid = 5000 ): Resource {
		return $this->waiting(
			array_merge(
				array(
					'app_state'            => 'ready',
					'app_domain'           => self::NAME,
					'app_domain_made'      => false,
					'app_domain_dns'       => 'live',
					'app_domain_dns_since' => time() - 30 * DAY_IN_SECONDS,
					'app_installed_at'     => time() - 30 * DAY_IN_SECONDS,
				),
				$meta
			),
			$vmid
		);
	}

	/** Subjects of the mails the customer got from the watch. */
	private function customer_subjects(): array {
		$out = array();
		foreach ( $this->mails as $m ) {
			$subject = (string) ( $m['subject'] ?? '' );
			if ( false !== strpos( $subject, self::NAME ) && 0 !== strpos( $subject, '[' ) ) {
				$out[] = $subject;
			}
		}

		return $out;
	}

	private function fresh( Resource $r ): Resource {
		return Resource::find( $r->id() );
	}

	private function recheck( Resource $r ): void {
		$this->assertTrue( $this->fresh( $r )->set( array( 'app_domain_dns_rechecked' => time() - 2 * DAY_IN_SECONDS ) ) );
		DnsWatch::recheck( $this->f(), $r->id() );
	}

	public function test_the_app_is_a_feature_of_the_watch_with_its_old_hook_and_prefix(): void {
		$f = $this->f();
		$this->assertSame( AppOwnDomain::HOOK, $f->hook( 'look' ) );
		$this->assertSame( 'app_domain_dns', $f->prefix() );
		$this->assertSame( 'live', $f->status_in( 'ok' ) );
		$this->assertSame( 'ok', $f->status_out( 'live' ) );
		$this->assertSame( 'waiting', $f->status_out( 'waiting' ) );
		$this->assertSame( array( 'a' ), $f->needed() );
		$this->assertTrue( has_action( AppOwnDomain::HOOK ) !== false, 'The look hook is hooked by the watch.' );
	}

	// -- Before the install: as today, through the watch -------------------------------------------

	public function test_the_first_wait_sends_no_mail_and_shows_no_dns_word(): void {
		$r = $this->waiting( array( 'app_domain_dns_since' => time() ) );
		AppOwnDomain::run( $r->id() );
		$fresh = $this->fresh( $r );

		$this->assertSame( 'waiting', $fresh->get( 'app_domain_dns' ) );
		$this->assertSame( 'none', $fresh->get( 'app_domain_dns_reason' ) );
		$this->assertSame( array(), $this->mails );
		$this->assertFalse( $this->f()->shows_status( $fresh ) );
		$this->assertFalse( $this->f()->mails( 'reminder', $fresh ) );
		$this->assertTrue( AppOwnDomain::waiting( $fresh ) );
	}

	public function test_found_queues_the_install_once_and_keeps_live_as_the_stored_word(): void {
		$r = $this->waiting( array( 'app_domain_dns_since' => time() ) );
		$this->answers['A'] = array( self::IP );
		AppOwnDomain::run( $r->id() );
		$fresh = $this->fresh( $r );

		$this->assertSame( 'live', $fresh->get( 'app_domain_dns' ) );
		$this->assertSame( self::NAME, $fresh->get( 'app_domain' ) );
		$this->assertFalse( $fresh->get( 'app_domain_made' ) );
		$this->assertNull( $fresh->get( 'app_domain_dns_reason' ) );
		$this->assertSame( 1, $this->pending( $r->id(), AppInstall::HOOK ) );
		$this->assertSame( DnsWatchState::OK, DnsWatchState::of( $this->f(), $fresh ) );
		$this->assertSame( array(), $this->mails, 'Nobody is mailed for a name that is found.' );

		// The found event again (the slow look) never queues a second install.
		as_unschedule_all_actions( AppInstall::HOOK, array( $r->id() ), Jobs::GROUP );
		AppOwnDomain::found( $fresh );
		$this->assertSame( 0, $this->pending( $r->id(), AppInstall::HOOK ) );
	}

	public function test_the_first_wait_that_ends_installs_on_the_address_and_mails_nothing(): void {
		$r = $this->waiting( array( 'app_domain_dns_since' => time() - 14 * DAY_IN_SECONDS - 60 ) );
		AppOwnDomain::run( $r->id() );
		$fresh = $this->fresh( $r );

		$this->assertSame( 'timeout', $fresh->get( 'app_domain_dns' ) );
		$this->assertNull( $fresh->get( 'app_domain' ) );
		$this->assertSame( 1, $this->pending( $r->id(), AppInstall::HOOK ) );
		$this->assertSame( array(), $this->mails );
		$this->assertFalse( $this->f()->active( $fresh ), 'A name that never worked is not looked at again.' );
	}

	public function test_the_wait_days_and_the_check_limits_are_the_shared_settings(): void {
		$r = $this->waiting( array( 'app_domain_dns_since' => time() - 4 * DAY_IN_SECONDS ) );
		$this->settings( array( 'dns_watch_wait_days' => 3, 'dns_watch_check_gap' => 120, 'dns_watch_check_per_hour' => 2 ) );

		$this->assertSame( time() - 4 * DAY_IN_SECONDS + 3 * DAY_IN_SECONDS, AppOwnDomain::deadline( $r ) );
		AppOwnDomain::run( $r->id() );
		$this->assertSame( 'timeout', $this->fresh( $r )->get( 'app_domain_dns' ) );

		$w = $this->waiting( array( 'app_domain_dns_since' => time() ), 5001 );
		$this->assertTrue( $w->set( array( AppOwnDomain::MANUAL_META => array( time() - 100 ) ) ) );
		$this->assertGreaterThan( time(), AppOwnDomain::manual_next( $this->fresh( $w ) ), 'The gap is the shared 120 seconds.' );
	}

	// -- After the app is live: warn, never act -----------------------------------------------------

	public function test_a_live_app_that_points_to_the_server_is_looked_at_and_nobody_is_mailed(): void {
		$r = $this->live();
		$this->answers['A'] = array( self::IP );
		$this->recheck( $r );
		$fresh = $this->fresh( $r );

		$this->assertSame( 'live', $fresh->get( 'app_domain_dns' ) );
		$this->assertSame( array( 'ok' ), array_column( (array) $fresh->get( 'app_domain_dns_rows' ), 'status' ) );
		$this->assertSame( array(), $this->mails );
		$this->assertSame( 0, $this->pending( $r->id(), AppInstall::HOOK ) );
		$this->assertTrue( $this->f()->shows_status( $fresh ) );
	}

	public function test_the_daily_sweep_queues_a_live_app(): void {
		$r = $this->live();
		DnsWatch::recheck_all( $this->f() );
		$this->assertSame( 1, $this->pending( $r->id(), $this->f()->hook( 'recheck' ) ) );
	}

	public function test_a_name_that_moves_away_is_read_twice_then_confirmed_and_the_customer_is_told_once(): void {
		$r = $this->live();
		$this->answers['A'] = array( '198.51.100.7' );
		$this->recheck( $r );
		$fresh = $this->fresh( $r );

		$this->assertSame( 'waiting', $fresh->get( 'app_domain_dns' ), 'Waiting again, as the watch does.' );
		$this->assertGreaterThan( 0, (int) $fresh->get( 'app_domain_dns_lost_pending' ) );
		$this->assertSame( array(), $this->customer_subjects(), 'Nothing is said before the confirmation.' );
		$this->assertSame( self::NAME, $fresh->get( 'app_domain' ), 'The app keeps its name.' );
		$this->assertFalse( AppOwnDomain::waiting( $fresh ), 'It is not the install wait.' );
		$this->assertTrue( AppOwnDomain::lost( $fresh ) );
		$this->assertSame( 1, $this->pending( $r->id(), $this->f()->hook( 'confirm' ) ) );

		DnsWatch::confirm( $this->f(), $r->id() );
		$fresh = $this->fresh( $r );
		$this->assertSame( array( 'DNS records are missing for ' . self::NAME ), $this->customer_subjects() );
		$this->assertSame( DnsWatchState::LOST, DnsWatchState::of( $this->f(), $fresh ) );
		$this->assertSame( 0, $this->pending( $r->id(), AppInstall::HOOK ), 'A loss never queues an install.' );
		$this->assertSame( 'ready', $fresh->get( 'app_state' ) );
		$this->assertSame( 'other', $fresh->get( 'app_domain_dns_reason' ) );

		$tab = AppTab::view( $fresh );
		$this->assertSame( 'lost', $tab['own']['state'] );
		$this->assertStringContainsString( 'DNS records missing', DnsWatchState::word( $this->f(), $fresh ) );
	}

	public function test_a_record_that_is_back_before_the_confirmation_mails_nobody(): void {
		$r = $this->live();
		$this->answers['A'] = array();
		$this->recheck( $r );
		$this->answers['A'] = array( self::IP );
		DnsWatch::confirm( $this->f(), $r->id() );
		$fresh = $this->fresh( $r );

		$this->assertSame( 'live', $fresh->get( 'app_domain_dns' ) );
		$this->assertSame( array(), $this->customer_subjects() );
	}

	public function test_a_lookup_that_fails_learns_nothing_about_a_live_name(): void {
		$r = $this->live();
		$this->answers['A'] = new \WP_Error( 'dns_transport', 'down' );
		$this->recheck( $r );
		$fresh = $this->fresh( $r );

		$this->assertSame( 'live', $fresh->get( 'app_domain_dns' ) );
		$this->assertSame( 0, (int) $fresh->get( 'app_domain_dns_lost_pending' ) );
		$this->assertSame( array(), $this->mails );
	}

	public function test_an_aaaa_record_next_to_the_a_record_counts_as_lost(): void {
		$r = $this->live();
		$this->answers = array( 'A' => array( self::IP ), 'AAAA' => array( '2001:db8::7' ) );
		$this->recheck( $r );
		DnsWatch::confirm( $this->f(), $r->id() );

		$this->assertSame( 'aaaa', $this->fresh( $r )->get( 'app_domain_dns_reason' ) );
		$this->assertCount( 1, $this->customer_subjects() );
	}

	public function test_reminders_follow_the_shared_days_and_back_sends_one_found_again_mail(): void {
		$r = $this->live();
		$this->answers['A'] = array();
		$this->recheck( $r );
		DnsWatch::confirm( $this->f(), $r->id() );
		$this->assertCount( 1, $this->customer_subjects() );

		// Day 3 and day 10 are the shared setting; a look before day 3 sends nothing.
		DnsWatch::lost_reminders( $this->f(), $r->id() );
		$this->assertCount( 1, $this->customer_subjects() );
		$this->assertTrue( $this->fresh( $r )->set( array( 'app_domain_dns_lost_at' => time() - 3 * DAY_IN_SECONDS - 60 ) ) );
		DnsWatch::lost_reminders( $this->f(), $r->id() );
		$this->assertCount( 2, $this->customer_subjects() );
		DnsWatch::lost_reminders( $this->f(), $r->id() );
		$this->assertCount( 2, $this->customer_subjects(), 'Once a day named.' );
		$this->assertTrue( $this->fresh( $r )->set( array( 'app_domain_dns_lost_at' => time() - 10 * DAY_IN_SECONDS - 60 ) ) );
		DnsWatch::lost_reminders( $this->f(), $r->id() );
		$this->assertCount( 3, $this->customer_subjects() );

		// The name comes back (the 24 hour flap gap is over): one mail, nothing installed, nothing changed.
		$this->assertTrue( $this->fresh( $r )->set( array( 'app_domain_dns_back_at' => 0, 'app_domain_dns_checked' => time() - 3600 ) ) );
		$this->answers['A'] = array( self::IP );
		AppOwnDomain::run( $r->id() );
		$fresh = $this->fresh( $r );

		$this->assertSame( 'live', $fresh->get( 'app_domain_dns' ) );
		$this->assertContains( 'The DNS records for ' . self::NAME . ' are found again', $this->customer_subjects() );
		$this->assertCount( 4, $this->customer_subjects() );
		$this->assertSame( 0, $this->pending( $r->id(), AppInstall::HOOK ) );
		$this->assertSame( 'ready', $fresh->get( 'app_state' ) );
		$this->assertSame( self::NAME, $fresh->get( 'app_domain' ) );
		$this->assertSame( DnsWatchState::OK, DnsWatchState::of( $this->f(), $fresh ) );
	}

	public function test_a_loss_that_lasts_past_the_wait_days_stops_the_look_with_one_mail_and_never_installs(): void {
		$r = $this->live();
		$this->answers['A'] = array();
		$this->recheck( $r );
		DnsWatch::confirm( $this->f(), $r->id() );
		$this->assertTrue( $this->fresh( $r )->set( array( 'app_domain_dns_since' => time() - 15 * DAY_IN_SECONDS, 'app_domain_dns_checked' => time() - 3600 ) ) );
		AppOwnDomain::run( $r->id() );
		$fresh = $this->fresh( $r );

		$this->assertSame( 'timeout', $fresh->get( 'app_domain_dns' ) );
		$this->assertContains( 'We have stopped checking the DNS of ' . self::NAME, $this->customer_subjects() );
		$this->assertSame( 0, $this->pending( $r->id(), AppInstall::HOOK ) );
		$this->assertSame( self::NAME, $fresh->get( 'app_domain' ) );
		$this->assertTrue( $this->f()->active( $fresh ), 'The slow weekly look goes on for a name that worked.' );
		$this->assertSame( 'lost', AppTab::view( $fresh )['own']['state'] );
	}

	public function test_check_now_works_for_a_lost_name_and_restarts_a_stopped_look(): void {
		$r = $this->live();
		$this->answers['A'] = array();
		$this->recheck( $r );
		DnsWatch::confirm( $this->f(), $r->id() );

		$result = AppOwnDomain::check_now( $this->fresh( $r ) );
		$this->assertIsArray( $result );
		$this->assertSame( 'none', $result['verdict'] );
		$limited = AppOwnDomain::check_now( $this->fresh( $r ) );
		$this->assertWPError( $limited );
		$this->assertSame( 'rate_limited', $limited->get_error_code() );

		$this->assertTrue( $this->fresh( $r )->set( array( 'app_domain_dns' => 'timeout', AppOwnDomain::MANUAL_META => array() ) ) );
		$this->answers['A'] = array( self::IP );
		$result = AppOwnDomain::check_now( $this->fresh( $r ) );
		$this->assertSame( 'live', $result['verdict'] );
		$this->assertSame( 'live', $this->fresh( $r )->get( 'app_domain_dns' ) );
		$this->assertSame( 0, $this->pending( $r->id(), AppInstall::HOOK ) );
	}

	public function test_check_now_still_refuses_a_live_name_and_a_name_that_never_worked(): void {
		$this->assertWPError( AppOwnDomain::check_now( $this->live() ) );
		$timed = $this->waiting( array( 'app_domain_dns' => 'timeout', 'app_domain_dns_since' => time() ), 5001 );
		$this->assertWPError( AppOwnDomain::check_now( $timed ) );
	}

	public function test_the_status_poll_and_the_page_use_the_same_word_for_a_lost_name(): void {
		$r = $this->live();
		$this->answers['A'] = array();
		$this->recheck( $r );
		DnsWatch::confirm( $this->f(), $r->id() );
		$fresh = $this->fresh( $r );

		$state = apply_filters( 'beltoft_services_area_state', array( 'Ready', 'ok' ), $fresh );
		$this->assertStringStartsWith( 'DNS records missing', $state[0] );
		$words = apply_filters( 'beltoft_services_status_words', array( 'label' => 'Ready', 'message' => '' ), $fresh );
		$this->assertSame( $state[0], $words['label'] );
	}

	public function test_a_server_from_before_the_watch_starts_with_ok_rows_and_a_first_loss_is_confirmed_like_any_other(): void {
		// No rows, no mode, no rechecked: exactly what a live app on production has today.
		$r = $this->live();
		$this->assertNull( $r->get( 'app_domain_dns_rows' ) );
		$this->answers['A'] = array( self::IP );
		$this->recheck( $r );
		$this->assertSame( array(), $this->mails );

		
	}

	public function test_nothing_is_written_to_dns_and_a_deleted_server_is_left_alone(): void {
		$dns_before = get_option( FakeDns::OPTION );
		$r = $this->live();
		$this->answers['A'] = array();
		$this->recheck( $r );
		DnsWatch::confirm( $this->f(), $r->id() );
		$this->assertSame( $dns_before, get_option( FakeDns::OPTION ) );
		$this->assertWPError( $this->f()->publish( 1, self::NAME, array() ) );

		$gone = $this->live( array( 'state' => 'deleted' ), 5001 );
		$gone_lookups = count( $this->lookups );
		DnsWatch::recheck( $this->f(), $gone->id() );
		$this->assertCount( $gone_lookups, $this->lookups );
	}

	public function test_a_resolver_outage_at_the_confirm_step_mails_nobody_and_stays_pending(): void {
		$r = $this->live();
		$this->answers['A'] = array();
		$this->recheck( $r );
		$this->answers['A'] = new \WP_Error( 'dns_transport', 'down' );
		DnsWatch::confirm( $this->f(), $r->id() );
		$fresh = $this->fresh( $r );

		$this->assertSame( array(), $this->customer_subjects() );
		$this->assertGreaterThan( 0, (int) $fresh->get( 'app_domain_dns_lost_pending' ) );
		$this->assertSame( 1, (int) $fresh->get( 'app_domain_dns_unknown_tries' ) );
	}

	public function test_found_with_a_busy_lock_is_retried_by_its_own_job_and_never_installs_on_the_address(): void {
		global $wpdb;
		$r = $this->waiting( array( 'app_domain_dns' => 'live', 'app_domain_dns_since' => time() ) );
		add_filter( 'beltoft_services_desire_lock_wait', '__return_zero' );

		$name  = \BeltoftServices\Locks::name( 'resource', $r->id() );
		$other = new \mysqli( DB_HOST, DB_USER, DB_PASSWORD, DB_NAME ); // phpcs:ignore WordPress.DB.RestrictedClasses.mysql__mysqli -- a second connection holds the lock.
		$other->query( $wpdb->prepare( 'SELECT GET_LOCK( %s, 1 )', $name ) );

		AppOwnDomain::found( $this->fresh( $r ) );
		$this->assertSame( '', (string) $this->fresh( $r )->get( 'app_domain' ), 'The lock was busy.' );
		$this->assertSame( 0, $this->pending( $r->id(), AppInstall::HOOK ), 'No install on the address.' );
		$this->assertNotFalse( as_next_scheduled_action( AppOwnDomain::FOUND_HOOK, array( $r->id(), 1 ), Jobs::GROUP ), 'found() queued itself again.' );

		// The status is live already, so the look would return at once: only the retry can hand the name over.
		AppOwnDomain::run( $r->id() );
		$this->assertSame( '', (string) $this->fresh( $r )->get( 'app_domain' ) );

		$other->close();
		do_action( AppOwnDomain::FOUND_HOOK, $r->id(), 1 );
		$fresh = $this->fresh( $r );
		$this->assertSame( self::NAME, $fresh->get( 'app_domain' ) );
		$this->assertFalse( $fresh->get( 'app_domain_made' ) );
		$this->assertSame( 1, $this->pending( $r->id(), AppInstall::HOOK ) );
	}

	public function test_the_install_job_never_installs_on_the_address_while_a_found_name_is_not_handed_over(): void {
		$r = $this->waiting( array( 'app_domain_dns' => 'live', 'app_domain_dns_since' => time() ) );
		$this->assertTrue( AppOwnDomain::handing_over( $r ) );
		AppInstall::run( $r->id() );
		$fresh = $this->fresh( $r );

		$this->assertNotSame( 'installing', $fresh->get( 'app_state' ) );
		$this->assertSame( self::NAME, $fresh->get( 'app_domain' ), 'The install job handed the name over first.' );
		$this->assertSame( 1, $this->pending( $r->id(), AppInstall::HOOK ) );
	}

	public function test_reinstall_keeping_a_lost_name_keeps_the_loss_and_the_warning(): void {
		foreach ( array( 'waiting', 'timeout' ) as $i => $status ) {
			$r = $this->live( array( 'app_domain_dns' => $status, 'app_domain_dns_lost_at' => time() - 3600, 'app_domain_dns_lost_mails' => 1, 'app_domain_dns_since' => time() - DAY_IN_SECONDS ), 5000 + $i );
			$this->assertTrue( AppOwnDomain::lost( $r ) );

			$meta = \BeltoftServices\Apps\AppReinstall::meta( array(), $r, array( 'app_version' => 'keep', 'app_domain_mode' => 'keep' ) );
			$this->assertArrayNotHasKey( 'app_domain_dns', $meta, "$status: the watch state is not reset." );
			$this->assertArrayNotHasKey( 'app_domain_dns_lost_at', $meta );
			$this->assertArrayNotHasKey( 'app_domain_dns_since', $meta );
			$this->assertTrue( $r->set( $meta ) );
			$fresh = $this->fresh( $r );
			$this->assertSame( self::NAME, $fresh->get( 'app_domain' ), 'The reinstall keeps the name.' );
			$this->assertSame( 'waiting' === $status ? DnsWatchState::LOST : DnsWatchState::STOPPED, DnsWatchState::of( $this->f(), $fresh ), 'The warning stays on the page.' );
		}
	}
}

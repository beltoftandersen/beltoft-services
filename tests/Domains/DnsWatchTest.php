<?php
/**
 * The DNS watch engine, with a feature that is not email: every rule of the shared order, and that every number is a setting.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Domains;

use BeltoftServices\Domains\DnsWatch;
use BeltoftServices\Domains\DnsWatchSettings;
use BeltoftServices\Domains\DnsWatchState;
use BeltoftServices\Resource;
use BeltoftServices\Tests\TestCase;

require_once __DIR__ . '/DnsWatchFakeFeature.php';

final class DnsWatchTest extends TestCase {

	/** @var array<int, array<string, mixed>> */
	private array $mails = array();

	/** @var array<int, string> Events seen by an observer of the action. */
	private array $observed = array();

	private DnsWatchFakeFeature $feature;

	/** @var array<int, string>|\WP_Error What the health probe name (beltoft.net) answers. */
	private $probe = array( '192.0.2.1' );

	public function set_up(): void {
		parent::set_up();

		DnsWatchFakeFeature::$present = array();
		DnsWatchFakeFeature::$failing = false;
		DnsWatchFakeFeature::$events  = array();
		DnsWatchFakeFeature::$before  = true;
		$this->mails                  = array();
		$this->observed               = array();
		$this->feature                = new DnsWatchFakeFeature();
		$this->probe                  = array( '192.0.2.1' );
		add_filter( 'beltoft_services_dns_resolve', fn( $pre, $name ) => 'beltoft.net' === $name ? $this->probe : $pre, 10, 2 );

		add_filter( 'beltoft_services_dns_watches', fn( $f ) => $f + array( 'fake' => $this->feature ) );
		add_filter(
			'pre_wp_mail',
			function ( $pre, $args ) {
				$this->mails[] = $args;
				return true;
			},
			10,
			2
		);
		add_action(
			DnsWatch::EVENT,
			function ( $event, $feature ) {
				$this->observed[] = $feature . ':' . $event;
			},
			10,
			2
		);
		$this->settings( array( 'admin_email' => 'christian.beltoft@gmail.com' ) );
	}

	public function tear_down(): void {
		remove_all_filters( 'pre_wp_mail' );
		parent::tear_down();
	}

	/**
	 * A ready service of the fake kind with three records.
	 *
	 * @param string               $status waiting, ok or timeout.
	 * @param array<string, mixed> $meta   More meta.
	 */
	private function service( string $status = 'waiting', array $meta = array() ): Resource {
		$driver  = $this->fake_driver();
		$product = $this->service_product( 'fake', array(), array( 'max' => 3 ) );
		$license = $this->service_license( $product, array( 'fire' => false ) );
		\BeltoftLicenses\Licenses::update( (int) $license['id'], array( 'email' => 'christian.beltoft@gmail.com' ) );
		$ok = 'ok' === $status ? 'ok' : 'missing';
		$r  = Resource::create(
			$license,
			$driver,
			array(),
			array_merge(
				array(
					'fk_domain'    => 'watched.example',
					'fk_dns'       => $status,
					'fk_dns_since' => time(),
					'fk_mode'      => 'external',
					'fk_records'   => array(
						array( 'key' => 'a', 'name' => 'a', 'type' => 'TXT', 'value' => 'one', 'status' => $ok, 'reason' => '' ),
						array( 'key' => 'b', 'name' => 'b', 'type' => 'TXT', 'value' => 'two', 'status' => $ok, 'reason' => '' ),
						array( 'key' => 'c', 'name' => 'c', 'type' => 'TXT', 'value' => 'three', 'status' => $ok, 'reason' => '' ),
					),
				),
				$meta
			)
		);
		$this->assertInstanceOf( Resource::class, $r );
		$this->assertTrue( $r->set( array( 'state' => 'ready' ) ) );

		return Resource::find( $r->id() );
	}

	/**
	 * @return string[]
	 */
	private function subjects(): array {
		return array_map( 'strval', array_column( $this->mails, 'subject' ) );
	}

	private function fresh( Resource $r ): Resource {
		return Resource::find( $r->id() );
	}

	public function test_a_feature_is_found_by_its_id_and_by_its_resource(): void {
		$r = $this->service();

		$this->assertSame( $this->feature, DnsWatch::feature( 'fake' ) );
		$this->assertSame( $this->feature, DnsWatch::feature_of( $r ) );
		$this->assertNull( DnsWatch::feature( 'nothing' ) );

		add_filter( 'beltoft_services_dns_watches', fn( $f ) => $f + array( 'wrong-key' => new DnsWatchFakeFeature() ) );
		$this->assertArrayNotHasKey( 'wrong-key', DnsWatch::features(), 'The key must be the id of the object.' );
	}

	public function test_the_needed_records_decide_and_the_recommended_one_never_does(): void {
		$r = $this->service();
		DnsWatchFakeFeature::$present = array( 'a' );
		DnsWatch::run( $this->feature, $r->id() );
		$this->assertSame( 'waiting', $this->fresh( $r )->get( 'fk_dns' ), 'B is still missing.' );
		$this->assertNotFalse( as_next_scheduled_action( 'beltoft_services_test_fake_look', array( $r->id() ), \BeltoftServices\Jobs::GROUP ), 'The next look is queued.' );

		DnsWatchFakeFeature::$present = array( 'a', 'b' );
		$this->fresh( $r )->set( array( 'fk_dns_checked' => time() - DnsWatch::MIN_GAP - 1 ) );
		DnsWatch::run( $this->feature, $r->id() );

		$this->assertSame( 'ok', $this->fresh( $r )->get( 'fk_dns' ), 'C is only recommended.' );
		$this->assertContains( 'changed', DnsWatchFakeFeature::$events );
		$this->assertContains( 'fake:found', $this->observed );
	}

	public function test_a_lookup_that_fails_learns_nothing(): void {
		$r = $this->service();
		DnsWatchFakeFeature::$failing = true;
		DnsWatch::run( $this->feature, $r->id() );

		$rows = (array) $this->fresh( $r )->get( 'fk_records' );
		$this->assertSame( 'missing', $rows[0]['status'] );
		$this->assertSame( 'waiting', $this->fresh( $r )->get( 'fk_dns' ) );
	}

	public function test_the_days_of_the_wait_are_a_setting_and_the_customer_is_told_once(): void {
		$this->settings( array( 'dns_watch_wait_days' => 3 ) );
		$r = $this->service( 'waiting', array( 'fk_dns_since' => time() - 3 * DAY_IN_SECONDS - 5 ) );

		DnsWatch::run( $this->feature, $r->id() );

		$this->assertSame( 'timeout', $this->fresh( $r )->get( 'fk_dns' ) );
		$this->assertSame( array( 'We have stopped checking the DNS of watched.example' ), $this->subjects() );
		$text = wp_strip_all_tags( (string) $this->mails[0]['message'] );
		$this->assertStringContainsString( 'after 3 days', $text );
		$this->assertStringContainsString( 'The fake thing stops.', $text );
		$this->assertStringContainsString( 'press Check now in the fake area', $text );
		$this->assertContains( 'fake:timeout', $this->observed );

		DnsWatch::timeout_notice( $this->feature, $r->id() );
		$this->assertCount( 1, $this->mails, 'Once.' );
	}

	public function test_a_lost_record_is_read_again_confirmed_later_and_mailed_once(): void {
		$r = $this->service( 'ok', array( 'fk_dns_since' => time() - 5 * DAY_IN_SECONDS ) );
		DnsWatchFakeFeature::$present = array( 'a', 'c' );

		DnsWatch::recheck( $this->feature, $r->id() );
		$r = $this->fresh( $r );

		$this->assertSame( 'waiting', $r->get( 'fk_dns' ) );
		$this->assertGreaterThan( 0, (int) $r->get( 'fk_dns_lost_pending' ) );
		$this->assertSame( array( 'b' ), $r->get( 'fk_dns_lost_keys' ) );
		$this->assertSame( array(), $this->mails, 'Nothing is said before the second read.' );
		$this->assertSame( DnsWatchState::OK, DnsWatchState::of( $this->feature, $r ), 'The page says nothing either (nothing is held).' );
		$this->assertNotFalse( as_next_scheduled_action( 'beltoft_services_test_fake_confirm', array( $r->id() ), \BeltoftServices\Jobs::GROUP ) );

		DnsWatch::confirm( $this->feature, $r->id() );
		$r = $this->fresh( $r );

		$this->assertGreaterThan( 0, (int) $r->get( 'fk_dns_lost_at' ) );
		$this->assertSame( DnsWatchState::LOST, DnsWatchState::of( $this->feature, $r ) );
		$this->assertContains( 'DNS records are missing for watched.example', $this->subjects() );
		$this->assertCount( 1, array_filter( $this->subjects(), static fn( $s ) => str_contains( $s, 'Fake domain lost DNS records: watched.example' ) ), 'The staff are told too.' );
		$this->assertContains( 'fake:lost_confirmed', $this->observed );
		$text = wp_strip_all_tags( (string) $this->mails[0]['message'] );
		$this->assertStringContainsString( 'These are not found now: B.', $text );
		$this->assertStringContainsString( 'b.watched.example', $text );
	}

	public function test_a_record_that_is_back_before_the_second_read_is_no_event(): void {
		$r = $this->service( 'ok' );
		DnsWatchFakeFeature::$present = array( 'a' );
		DnsWatch::recheck( $this->feature, $r->id() );

		DnsWatchFakeFeature::$present = array( 'a', 'b', 'c' );
		DnsWatch::confirm( $this->feature, $r->id() );
		$r = $this->fresh( $r );

		$this->assertSame( 'ok', $r->get( 'fk_dns' ) );
		$this->assertSame( array(), $this->mails );
		$this->assertSame( 0, (int) $r->get( 'fk_dns_lost_at' ) );
	}

	public function test_a_recommended_record_that_goes_is_not_a_loss(): void {
		$r = $this->service( 'ok' );
		DnsWatchFakeFeature::$present = array( 'a', 'b' );
		DnsWatch::recheck( $this->feature, $r->id() );
		$r = $this->fresh( $r );

		$this->assertSame( 'ok', $r->get( 'fk_dns' ) );
		$this->assertSame( 'missing', $r->get( 'fk_records' )[2]['status'], 'It is shown as missing.' );
		$this->assertSame( array(), $this->mails );
	}

	public function test_the_found_mail_goes_once_after_a_told_loss_and_a_flapping_record_waits_for_the_gap(): void {
		$r = $this->service( 'ok' );
		DnsWatchFakeFeature::$present = array( 'a' );
		DnsWatch::recheck( $this->feature, $r->id() );
		DnsWatch::confirm( $this->feature, $r->id() );
		$this->mails = array();

		DnsWatchFakeFeature::$present = array( 'a', 'b', 'c' );
		$this->fresh( $r )->set( array( 'fk_dns_checked' => time() - DnsWatch::MIN_GAP - 1 ) );
		DnsWatch::run( $this->feature, $r->id() );

		$this->assertSame( array( 'The DNS records for watched.example are found again' ), $this->subjects() );
		$this->assertSame( 'ok', $this->fresh( $r )->get( 'fk_dns' ) );
		$this->assertContains( 'fake:back', $this->observed );

		// It goes missing again at once: inside the gap the customer is not mailed a second time.
		$this->mails = array();
		DnsWatchFakeFeature::$present = array( 'a', 'c' );
		$this->fresh( $r )->set( array( 'fk_dns_rechecked' => 0 ) );
		DnsWatch::recheck( $this->feature, $r->id() );
		DnsWatch::confirm( $this->feature, $r->id() );

		$this->assertSame( array(), array_filter( $this->subjects(), static fn( $s ) => str_starts_with( $s, 'DNS records are missing' ) ) );
		$this->assertSame( DnsWatchState::LOST, DnsWatchState::of( $this->feature, $this->fresh( $r ) ), 'The page follows the real state.' );
	}

	public function test_reminders_follow_the_days_of_the_setting_and_never_exceed_them(): void {
		$this->settings( array( 'dns_watch_remind_days' => '2, 5' ) );
		$r = $this->service(
			'waiting',
			array(
				'fk_dns_lost_at'    => time() - 3 * DAY_IN_SECONDS,
				'fk_dns_lost_mails' => 1,
				'fk_dns_lost_keys'  => array( 'b' ),
			)
		);

		DnsWatch::lost_reminders( $this->feature, $r->id() );
		$this->assertCount( 1, $this->mails, 'Day 2 has passed.' );

		DnsWatch::lost_reminders( $this->feature, $r->id() );
		$this->assertCount( 1, $this->mails, 'Day 5 has not.' );

		$this->fresh( $r )->set( array( 'fk_dns_lost_at' => time() - 6 * DAY_IN_SECONDS ) );
		DnsWatch::lost_reminders( $this->feature, $r->id() );
		DnsWatch::lost_reminders( $this->feature, $r->id() );
		$this->assertCount( 2, $this->mails, 'The first mail and two reminders at most: three in all.' . wp_json_encode( $this->subjects() ) );
	}

	public function test_the_first_reminder_hour_is_a_setting_and_only_an_outside_domain_is_reminded(): void {
		$r = $this->service( 'waiting', array( 'fk_dns_since' => time() - 5 * HOUR_IN_SECONDS ) );
		DnsWatch::remind( $this->feature, $r->id() );
		$this->assertCount( 0, $this->mails );

		$this->settings( array( 'dns_watch_first_remind_hours' => 4 ) );
		DnsWatch::remind( $this->feature, $r->id() );
		$this->assertCount( 1, $this->mails );
		$this->assertSame( 'DNS records are still missing for watched.example', $this->subjects()[0] );

		DnsWatch::remind( $this->feature, $r->id() );
		$this->assertCount( 1, $this->mails, 'Once.' );
	}

	public function test_the_sweep_queues_one_look_per_ok_service_and_a_slow_look_for_a_stopped_one(): void {
		$ok      = $this->service( 'ok' );
		$stopped = $this->service( 'timeout', array( 'fk_dns_rechecked' => time() - 8 * DAY_IN_SECONDS, 'fk_dns_since' => time() - 20 * DAY_IN_SECONDS ) );

		DnsWatch::recheck_all( $this->feature );

		$this->assertNotFalse( as_next_scheduled_action( 'beltoft_services_test_fake_recheck', array( $ok->id() ), \BeltoftServices\Jobs::GROUP ) );
		$this->assertNotFalse( as_next_scheduled_action( 'beltoft_services_test_fake_recheck', array( $stopped->id() ), \BeltoftServices\Jobs::GROUP ) );

		$this->settings( array( 'dns_watch_stop_days' => 15 ) );
		foreach ( array( 'beltoft_services_test_fake_recheck' ) as $hook ) {
			as_unschedule_all_actions( $hook );
		}
		$this->fresh( $stopped )->set( array( 'fk_dns_since' => time() - 16 * DAY_IN_SECONDS ) );
		DnsWatch::recheck_all( $this->feature );
		$this->assertFalse( as_next_scheduled_action( 'beltoft_services_test_fake_recheck', array( $stopped->id() ), \BeltoftServices\Jobs::GROUP ), 'No look after the stop days.' );
	}

	public function test_a_feature_may_have_its_own_value_without_a_code_change(): void {
		$this->assertSame( 10 * MINUTE_IN_SECONDS, DnsWatchSettings::confirm_seconds( 'fake' ) );

		$stored = (array) get_option( \BeltoftServices\Settings::OPTION, array() );
		update_option( \BeltoftServices\Settings::OPTION, $stored + array( 'fake_dns_watch_confirm_minutes' => 1 ) );

		$this->assertSame( MINUTE_IN_SECONDS, DnsWatchSettings::confirm_seconds( 'fake' ) );
		$this->assertSame( 10 * MINUTE_IN_SECONDS, DnsWatchSettings::confirm_seconds( 'mail' ), 'Others keep the shared value.' );
	}

	public function test_the_words_and_tones_of_the_state(): void {
		$r = $this->service();
		$this->assertSame( DnsWatchState::SETTING_UP, DnsWatchState::of( $this->feature, $r ) );
		$this->assertSame( 'DNS records needed', DnsWatchState::word( $this->feature, $r ) );
		$this->assertSame( 'busy', DnsWatchState::tone( $this->feature, $r ) );

		$r->set( array( 'fk_dns_lost_at' => time() - 4 * DAY_IN_SECONDS, 'fk_dns_lost_keys' => array( 'b' ) ) );
		$r = $this->fresh( $r );
		$this->assertSame( DnsWatchState::LOST, DnsWatchState::of( $this->feature, $r ) );
		$this->assertSame( 'bad', DnsWatchState::tone( $this->feature, $r ), 'Red after the days of the setting.' );

		$r->set( array( 'fk_dns' => 'timeout' ) );
		$this->assertSame( 'We stopped checking DNS', DnsWatchState::word( $this->feature, $this->fresh( $r ) ) );

		$r->set( array( 'fk_dns' => 'ok' ) );
		$this->assertSame( '', DnsWatchState::word( $this->feature, $this->fresh( $r ) ) );
		$this->assertSame( 'ok', DnsWatchState::tone( $this->feature, $this->fresh( $r ) ) );
	}

	public function test_the_words_and_tones_of_a_state_kept_outside_one_resource(): void {
		$this->assertSame( '', DnsWatchState::state_of( '', true, 0, 0, 0 ), 'No status, no word.' );
		$this->assertSame( '', DnsWatchState::state_of( 'waiting', false, 0, 0, 0 ), 'No records known yet.' );
		$this->assertSame( DnsWatchState::OK, DnsWatchState::state_of( 'ok', true, 0, 0, 0 ) );
		$this->assertSame( DnsWatchState::SETTING_UP, DnsWatchState::state_of( 'waiting', true, 0, 0, 0 ) );
		$this->assertSame( DnsWatchState::OK, DnsWatchState::state_of( 'waiting', true, 0, 0, time() ), 'A loss is not said before it is confirmed.' );
		$this->assertSame( DnsWatchState::LOST, DnsWatchState::state_of( 'waiting', true, 0, time(), 0 ) );
		$this->assertSame( DnsWatchState::MOVED, DnsWatchState::state_of( 'waiting', true, time(), time(), 0 ) );
		$this->assertSame( DnsWatchState::STOPPED, DnsWatchState::state_of( 'timeout', true, 0, 0, 0 ) );

		$this->assertSame( 'DNS records needed', DnsWatchState::word_of( DnsWatchState::SETTING_UP, 0 ) );
		$this->assertSame( 'DNS records missing', DnsWatchState::word_of( DnsWatchState::LOST, 0 ) );
		$this->assertStringStartsWith( 'DNS records missing since', DnsWatchState::word_of( DnsWatchState::LOST, time() ) );
		$this->assertSame( '', DnsWatchState::word_of( DnsWatchState::OK, 0 ) );
		$this->assertSame( 'busy', DnsWatchState::tone_of( DnsWatchState::LOST, time() - HOUR_IN_SECONDS, 'fake' ) );
		$this->assertSame( 'bad', DnsWatchState::tone_of( DnsWatchState::LOST, time() - 4 * DAY_IN_SECONDS, 'fake' ) );
		$this->assertSame( 'ok', DnsWatchState::tone_of( DnsWatchState::OK, 0, 'fake' ) );
	}

	public function test_the_status_poll_and_the_list_row_take_the_word_from_the_engine(): void {
		$r = $this->service();
		$r->set( array( 'fk_dns_lost_at' => time() - 3600, 'fk_dns_lost_keys' => array( 'b' ) ) );
		$r = $this->fresh( $r );

		$state = apply_filters( 'beltoft_services_area_state', array( 'Ready', 'ok' ), $r );
		$this->assertStringStartsWith( 'DNS records missing since', $state[0] );

		$words = apply_filters( 'beltoft_services_status_words', array( 'label' => 'Ready', 'message' => '' ), $r );
		$this->assertStringStartsWith( 'DNS records missing since', $words['label'] );
	}

	public function test_hooking_the_jobs_twice_never_doubles_them_and_a_fired_hook_finds_its_feature(): void {
		$count = static fn( string $hook ): int => count( $GLOBALS['wp_filter'][ $hook ]->callbacks[10] ?? array() );

		\BeltoftServices\Domains\DnsWatchJobs::register();
		$once = $count( 'beltoft_services_test_fake_look' );
		\BeltoftServices\Domains\DnsWatchJobs::register();

		$this->assertSame( 1, $once );
		$this->assertSame( $once, $count( 'beltoft_services_test_fake_look' ) );

		$r = $this->service();
		DnsWatchFakeFeature::$present = array( 'a', 'b' );
		do_action( 'beltoft_services_test_fake_look', $r->id() );

		$this->assertSame( 'ok', $this->fresh( $r )->get( 'fk_dns' ), 'The hook that fired found the fake feature and ran its look.' );
	}

	public function test_a_service_with_no_stored_mode_is_never_reported_as_moved_away(): void {
		DnsWatchFakeFeature::$present = array( 'a', 'b', 'c' );
		$r = $this->service( 'ok', array( 'fk_mode' => null, 'fk_dns_rechecked' => time() - 2 * DAY_IN_SECONDS ) );

		DnsWatch::recheck( $this->feature, $r->id() );

		$this->assertNotContains( 'moved_away', DnsWatchFakeFeature::$events );
		$this->assertSame( array(), $this->subjects() );
	}

	// -- A lookup that fails is unknown, never a loss ------------------------------------------------

	private function pending_loss( array $meta = array() ): Resource {
		return $this->service( 'waiting', array_merge( array( 'fk_dns_lost_pending' => time(), 'fk_dns_lost_keys' => array( 'b' ), 'fk_dns_since' => time() - DAY_IN_SECONDS ), $meta ) );
	}

	private function failing_subjects(): array {
		return array_values( array_filter( $this->subjects(), static fn( $s ) => str_contains( $s, 'DNS lookups failing' ) ) );
	}

	public function test_a_resolver_outage_at_the_confirm_step_sends_no_mail_and_stays_pending(): void {
		$r = $this->pending_loss();
		DnsWatchFakeFeature::$failing = true;

		DnsWatch::confirm( $this->feature, $r->id() );
		$r = $this->fresh( $r );

		$this->assertSame( array(), $this->mails );
		$this->assertGreaterThan( 0, (int) $r->get( 'fk_dns_lost_pending' ), 'Still pending.' );
		$this->assertSame( 0, (int) $r->get( 'fk_dns_lost_at' ) );
		$this->assertSame( 1, (int) $r->get( 'fk_dns_unknown_tries' ) );
		$this->assertNotFalse( as_next_scheduled_action( 'beltoft_services_test_fake_confirm', array( $r->id() ), \BeltoftServices\Jobs::GROUP ), 'Looked at again later.' );
	}

	public function test_after_the_retries_one_admin_alert_is_raised_and_the_loss_stays_unconfirmed(): void {
		$this->settings( array( 'dns_watch_unknown_retries' => 2 ) );
		$r = $this->pending_loss();
		DnsWatchFakeFeature::$failing = true;

		for ( $i = 0; $i < 5; $i++ ) {
			DnsWatch::confirm( $this->feature, $r->id() );
		}
		$r = $this->fresh( $r );

		$this->assertCount( 1, $this->failing_subjects(), 'One alert, however many reads fail.' );
		$this->assertCount( 1, $this->mails, 'Only that alert: no customer mail.' );
		$this->assertGreaterThan( 0, (int) $r->get( 'fk_dns_lost_pending' ) );
		$this->assertSame( 0, (int) $r->get( 'fk_dns_lost_at' ) );

		// The resolver is back and the record is still gone: now it is a loss.
		DnsWatchFakeFeature::$failing = false;
		DnsWatch::confirm( $this->feature, $r->id() );
		$this->assertContains( 'DNS records are missing for watched.example', $this->subjects() );
	}

	public function test_a_single_failed_lookup_never_starts_a_loss_but_a_missing_or_wrong_record_does(): void {
		$r = $this->service( 'ok' );
		DnsWatchFakeFeature::$failing = true;
		DnsWatch::recheck( $this->feature, $r->id() );
		$r = $this->fresh( $r );
		$this->assertSame( 'ok', $r->get( 'fk_dns' ) );
		$this->assertSame( 0, (int) $r->get( 'fk_dns_lost_pending' ) );
		$this->assertSame( array(), $this->mails );

		DnsWatchFakeFeature::$failing = false;
		DnsWatchFakeFeature::$present = array( 'a', 'c' );
		$this->assertTrue( $r->set( array( 'fk_dns_rechecked' => 0 ) ) );
		DnsWatch::recheck( $this->feature, $r->id() );
		$this->assertGreaterThan( 0, (int) $this->fresh( $r )->get( 'fk_dns_lost_pending' ), 'A record that is not there is a loss.' );
	}

	public function test_a_reminder_is_not_sent_on_a_look_that_failed(): void {
		$r = $this->service( 'waiting', array( 'fk_dns_lost_at' => time() - 4 * DAY_IN_SECONDS, 'fk_dns_lost_mails' => 1, 'fk_dns_last_mail' => time() - 4 * DAY_IN_SECONDS ) );
		DnsWatchFakeFeature::$failing = true;
		$this->fresh( $r )->set( array( 'fk_dns_checked' => time() - 3600 ) );

		DnsWatch::run( $this->feature, $r->id() );

		$this->assertSame( array(), $this->mails );
	}

	public function test_a_sweep_in_which_most_lookups_fail_starts_no_loss_and_alerts_once_a_day(): void {
		\BeltoftServices\Domains\DnsWatchHealth::reset( $this->feature );
		$this->probe = new \WP_Error( 'dns_transport', 'down' );
		$this->note_domains( 9, 1 );
		$this->assertTrue( \BeltoftServices\Domains\DnsWatchHealth::tripped( $this->feature ) );

		$r = $this->service( 'ok' );
		DnsWatchFakeFeature::$present = array( 'a', 'c' );
		DnsWatch::recheck( $this->feature, $r->id() );
		$r = $this->fresh( $r );

		$this->assertSame( 'ok', $r->get( 'fk_dns' ), 'Nothing is stored as lost.' );
		$this->assertSame( 0, (int) $r->get( 'fk_dns_lost_pending' ) );

		$r2 = $this->service( 'ok' );
		$this->fresh( $r2 )->set( array( 'fk_dns_rechecked' => 0 ) );
		DnsWatch::recheck( $this->feature, $r2->id() );
		$this->assertCount( 1, $this->failing_subjects(), 'One alert per day.' );

		\BeltoftServices\Domains\DnsWatchHealth::reset( $this->feature );
		$this->assertFalse( \BeltoftServices\Domains\DnsWatchHealth::tripped( $this->feature ) );
	}

	public function test_the_sweep_percent_and_the_retries_are_settings(): void {
		$this->settings( array( 'dns_watch_sweep_fail_percent' => 80, 'dns_watch_health_probe_name' => '', 'dns_watch_health_min_domains' => 3 ) );
		\BeltoftServices\Domains\DnsWatchHealth::reset( $this->feature );
		$this->note_domains( 3, 1 );
		$this->assertFalse( \BeltoftServices\Domains\DnsWatchHealth::tripped( $this->feature ), '75 percent is under 80.' );
		\BeltoftServices\Domains\DnsWatchHealth::reset( $this->feature );
		$this->note_domains( 2, 0 );
		$this->assertFalse( \BeltoftServices\Domains\DnsWatchHealth::tripped( $this->feature ), 'Two failing domains are under the minimum of 3, even though all failed.' );
		$this->assertSame( 6, DnsWatchSettings::unknown_retries() );
		$this->assertSame( 80, DnsWatchSettings::sweep_fail_percent() );
	}

	public function test_the_lookups_failing_alert_comes_again_after_the_record_was_back(): void {
		$this->settings( array( 'dns_watch_unknown_retries' => 2 ) );
		$r = $this->pending_loss();
		DnsWatchFakeFeature::$failing = true;

		for ( $i = 0; $i < 5; $i++ ) {
			DnsWatch::confirm( $this->feature, $r->id() );
		}
		$this->assertCount( 1, $this->failing_subjects() );

		// The record is back: every mark of the failed lookups is cleared.
		DnsWatchFakeFeature::$failing = false;
		DnsWatchFakeFeature::$present = array( 'a', 'b', 'c' );
		DnsWatch::confirm( $this->feature, $r->id() );
		$this->assertSame( 'ok', $this->fresh( $r )->get( 'fk_dns' ) );
		$this->assertSame( 0, (int) $this->fresh( $r )->get( 'fk_dns_unknown_alerted' ) );

		// A new loss, and the lookups fail again (the day's throttle of the alert is over).
		DnsWatchFakeFeature::$present = array( 'a', 'c' );
		$this->assertTrue( $this->fresh( $r )->set( array( 'fk_dns_rechecked' => 0 ) ) );
		DnsWatch::recheck( $this->feature, $r->id() );
		$this->assertGreaterThan( 0, (int) $this->fresh( $r )->get( 'fk_dns_lost_pending' ) );
		delete_transient( 'beltoft_services_fake_lookups_' . md5( 'watched.example|' . $r->id() ) );
		DnsWatchFakeFeature::$failing = true;
		for ( $i = 0; $i < 5; $i++ ) {
			DnsWatch::confirm( $this->feature, $r->id() );
		}

		$this->assertCount( 2, $this->failing_subjects(), 'The second outage alerts again.' );
	}

	/** Notes lookups of distinct domains: $bad failing ones, $good healthy ones. */
	private function note_domains( int $bad, int $good, string $tag = 'd' ): void {
		for ( $i = 0; $i < $bad; $i++ ) {
			\BeltoftServices\Domains\DnsWatchHealth::note( $this->feature, "bad{$i}.{$tag}.example", 1, 1 );
		}
		for ( $i = 0; $i < $good; $i++ ) {
			\BeltoftServices\Domains\DnsWatchHealth::note( $this->feature, "good{$i}.{$tag}.example", 0, 1 );
		}
	}

	public function test_a_few_broken_customer_domains_do_not_hide_a_real_loss(): void {
		\BeltoftServices\Domains\DnsWatchHealth::reset( $this->feature );
		// Two domains whose own name servers fail, counted by distinct domain, in a small sweep: two thirds of the domains fail.
		$this->note_domains( 2, 0 );
		$real = $this->service( 'ok' );
		DnsWatchFakeFeature::$present = array( 'a', 'c' );
		DnsWatch::recheck( $this->feature, $real->id() );
		$real = $this->fresh( $real );

		$this->assertFalse( \BeltoftServices\Domains\DnsWatchHealth::tripped( $this->feature ), 'The probe name resolves: the resolver is fine.' );
		$this->assertSame( 'waiting', $real->get( 'fk_dns' ), 'The real loss is stored.' );
		$this->assertGreaterThan( 0, (int) $real->get( 'fk_dns_lost_pending' ) );
		$this->assertSame( array(), array_filter( $this->subjects(), static fn( $s ) => str_contains( $s, 'lookups failing' ) ), 'No false alert.' );
	}

	public function test_the_resolver_counts_as_down_only_when_the_probe_name_fails_too(): void {
		\BeltoftServices\Domains\DnsWatchHealth::reset( $this->feature );
		$this->note_domains( 1, 0 );
		$this->assertFalse( \BeltoftServices\Domains\DnsWatchHealth::tripped( $this->feature ) );

		\BeltoftServices\Domains\DnsWatchHealth::reset( $this->feature );
		$this->probe = new \WP_Error( 'dns_transport', 'down' );
		$this->note_domains( 1, 0 );
		$this->assertTrue( \BeltoftServices\Domains\DnsWatchHealth::tripped( $this->feature ) );

		// The same domain counted twice is one domain.
		\BeltoftServices\Domains\DnsWatchHealth::reset( $this->feature );
		$this->settings( array( 'dns_watch_health_probe_name' => '', 'dns_watch_health_min_domains' => 2, 'dns_watch_sweep_fail_percent' => 10 ) );
		\BeltoftServices\Domains\DnsWatchHealth::note( $this->feature, 'one.example', 1, 3 );
		\BeltoftServices\Domains\DnsWatchHealth::note( $this->feature, 'one.example', 1, 3 );
		$this->assertFalse( \BeltoftServices\Domains\DnsWatchHealth::tripped( $this->feature ) );
		$this->assertSame( array( 1, 1 ), array_slice( \BeltoftServices\Domains\DnsWatchHealth::counts( $this->feature ), 0, 2 ) );
		$this->assertSame( '', DnsWatchSettings::health_probe_name() );
	}

	public function test_the_watchdog_queues_only_services_that_a_look_would_work_on(): void {
		$ready = $this->service();
		$gone  = $this->service();
		$this->assertTrue( $gone->set( array( 'state' => 'deleted' ) ) );
		$later = $this->service();
		$this->assertTrue( $later->set( array( 'state' => 'paused' ) ) );

		$due = array_column( \BeltoftServices\Domains\DnsWatchJobs::due( 'fake' ), 0 );
		$this->assertContains( $ready->id(), $due );
		$this->assertNotContains( $gone->id(), $due, 'A deleted service is not queued.' );
		$this->assertNotContains( $later->id(), $due, 'A service the feature does not look at is not queued.' );

		DnsWatchFakeFeature::$before = false;
		$this->assertSame( array(), \BeltoftServices\Domains\DnsWatchJobs::due( 'fake' ), 'A service that is not ready for a look (before_look) is not queued.' );
	}
}

<?php
/**
 * An email domain loses DNS records that were found before: the states, the emails and the weekly look afterwards.
 * Fakes only (public DNS answers come from $this->dns).
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Mail;

use BeltoftServices\Jobs;
use BeltoftServices\Domains\DnsWatchSettings;
use BeltoftServices\Mail\MailDns;
use BeltoftServices\Mail\MailDnsState;
use BeltoftServices\Mail\MailHold;
use BeltoftServices\Mail\MailView;
use BeltoftServices\Resource;

require_once __DIR__ . '/MailTestCase.php';
require_once __DIR__ . '/DnsFixtures.php';

final class MailDnsLostTest extends MailTestCase {

	use DnsFixtures;

	public function test_a_new_domain_is_setting_up_not_lost(): void {
		$r = $this->ready_mail( 'new.example' );

		$this->assertSame( MailDnsState::SETTING_UP, MailDnsState::of( $r ) );
		$this->assertSame( 'DNS records needed', MailDnsState::word( $r ) );
		$this->assertSame( 'busy', MailDnsState::tone( $r ) );
		$this->assertStringContainsString( 'Email works on new.example when the DNS records below are in place', MailDnsState::notice( $r, 'new.example' ) );
	}

	public function test_a_domain_with_mx_but_no_spf_or_dkim_says_sending_is_held(): void {
		$r = $this->ready_mail( 'half.example' );
		$this->found( $r, array( 'mx' ) );
		$r->set( array( 'mail_dns_checked' => time() - 2 * DnsWatchSettings::look_seconds() ) );
		MailDns::run( $r->id() );
		$r = Resource::find( $r->id() );

		$this->assertSame( MailDnsState::PARTIAL_SEND, MailDnsState::of( $r ) );
		$this->assertSame( 'Sending is held', MailDnsState::word( $r ) );
		$this->assertStringContainsString( 'Sending from your mailboxes is paused until the SPF and DKIM records are found', MailDnsState::notice( $r, 'half.example' ) );
	}

	public function test_a_lost_record_is_mailed_only_after_the_second_read_ten_minutes_later(): void {
		$r = $this->found_once( 'lost.example' );
		$this->found( $r, array( 'mx', 'dkim' ) );

		MailDns::recheck( $r->id() );
		$r = Resource::find( $r->id() );

		$this->assertSame( 'waiting', $r->get( 'mail_dns' ) );
		$this->assertTrue( MailHold::held( $r ), 'Sending is held at once: it protects the sending name.' );
		$this->assertSame( 'held', $r->get( 'mail_hold' ) );
		$this->assertSame( 0, (int) $r->get( 'mail_dns_lost_at' ), 'Not confirmed yet.' );
		$this->assertGreaterThan( 0, (int) $r->get( 'mail_dns_lost_pending' ) );
		$this->assertTrue( Jobs::pending_action( MailDns::CONFIRM, array( $r->id() ) ), 'The second read is queued.' );
		$this->assertCount( 0, $this->mails, 'No mail before the second read.' );
		$this->assertSame( MailDnsState::PARTIAL_SEND, MailDnsState::of( $r ) );

		MailDns::confirm( $r->id() );
		$r = Resource::find( $r->id() );

		$this->assertSame( MailDnsState::LOST, MailDnsState::of( $r ) );
		$this->assertSame( array( 'spf' ), MailDnsState::lost_keys( $r ) );
		$this->assertSame( 1, $this->count_mails( 'DNS records are missing for lost.example' ) );
		$this->assertCount( 1, $this->customer_mails(), 'One mail.' );
		$mail = $this->customer_mails()[0];
		$this->assertSame( array( 'christian.beltoft@gmail.com' ), (array) $mail['to'] );
		$plain = (string) preg_replace( '/\s+/', ' ', (string) $mail['message'] );
		$this->assertStringContainsString( 'We found the DNS records of lost.example before. These are not found now: SPF.', $plain );
		$this->assertStringContainsString( 'Nothing is deleted', $plain );
		$this->assertStringContainsString( 'v=spf1', $plain );
		$this->assertStringContainsString( 'We keep checking', $plain );
		$this->assertStringContainsString( 'DNS records missing since', MailDnsState::word( $r ) );
		$this->assertStringContainsString( 'These DNS records were found before but are not found now: SPF.', MailDnsState::notice( $r, 'lost.example' ) );

		MailDns::confirm( $r->id() );
		$this->assertCount( 1, $this->customer_mails(), 'The confirmation runs once.' );
	}

	public function test_a_record_that_comes_back_within_the_ten_minutes_is_never_mailed(): void {
		$r = $this->found_once( 'flaky.example' );
		$this->found( $r, array( 'mx', 'dkim' ) );
		MailDns::recheck( $r->id() );

		$this->found( $r, array( 'mx', 'spf', 'dkim' ) );
		MailDns::confirm( $r->id() );
		$r = Resource::find( $r->id() );

		$this->assertSame( 'ok', $r->get( 'mail_dns' ) );
		$this->assertFalse( MailHold::held( $r ) );
		$this->assertSame( 'lifted', $r->get( 'mail_hold' ) );
		$this->assertSame( 0, (int) $r->get( 'mail_dns_lost_pending' ) );
		$this->assertCount( 0, $this->mails );
	}

	public function test_the_confirm_wait_is_a_setting(): void {
		$this->settings( array( 'dns_watch_confirm_minutes' => 30 ) );
		$r = $this->found_once( 'wait.example' );
		$this->found( $r, array( 'mx', 'dkim' ) );
		MailDns::recheck( $r->id() );

		$times = array_values( array_map( static fn( $a ) => $a->get_schedule()->get_date()->getTimestamp(), as_get_scheduled_actions( array( 'hook' => MailDns::CONFIRM, 'status' => \ActionScheduler_Store::STATUS_PENDING, 'per_page' => 5 ) ) ) );
		$this->assertCount( 1, $times );
		$this->assertEqualsWithDelta( time() + 1800, (int) $times[0], 5 );
	}

	public function test_reminders_come_at_day_3_and_day_10_and_never_more_than_three_mails(): void {
		$r = $this->confirmed_loss( 'remind.example' );
		$this->assertSame( 1, $this->count_mails( 'DNS records are missing for' ) );

		MailDns::lost_reminders( $r->id() );
		$this->assertCount( 1, $this->customer_mails(), 'Day 0: nothing more.' );

		$r->set( array( 'mail_dns_lost_at' => time() - 3 * DAY_IN_SECONDS - 60 ) );
		MailDns::lost_reminders( $r->id() );
		MailDns::lost_reminders( $r->id() );
		$this->assertCount( 2, $this->customer_mails(), 'Day 3: one reminder, only one.' );

		$r->set( array( 'mail_dns_lost_at' => time() - 10 * DAY_IN_SECONDS - 60 ) );
		MailDns::lost_reminders( $r->id() );
		$this->assertCount( 3, $this->customer_mails(), 'Day 10: the last one.' );

		$r->set( array( 'mail_dns_lost_at' => time() - 40 * DAY_IN_SECONDS ) );
		MailDns::lost_reminders( $r->id() );
		$this->assertCount( 3, $this->customer_mails(), 'Never a fourth.' );
		$this->assertSame( 3, $this->count_mails( 'DNS records are missing for remind.example' ) );
	}

	public function test_the_reminder_days_are_a_setting(): void {
		$this->settings( array( 'dns_watch_remind_days' => '' ) );
		$r = $this->confirmed_loss( 'single.example' );
		$r->set( array( 'mail_dns_lost_at' => time() - 20 * DAY_IN_SECONDS ) );
		MailDns::lost_reminders( $r->id() );

		$this->assertCount( 1, $this->customer_mails() );
	}

	public function test_when_the_records_are_found_again_the_customer_gets_one_short_mail(): void {
		$r = $this->confirmed_loss( 'back.example' );
		$this->mails = array();

		$this->found( $r, array( 'mx', 'spf', 'dkim' ) );
		$r->set( array( 'mail_dns_checked' => time() - 2 * DnsWatchSettings::look_seconds() ) );
		MailDns::run( $r->id() );
		$r = Resource::find( $r->id() );

		$this->assertSame( 'ok', $r->get( 'mail_dns' ) );
		$this->assertSame( MailDnsState::OK, MailDnsState::of( $r ) );
		$this->assertFalse( MailHold::held( $r ) );
		$this->assertSame( 0, (int) $r->get( 'mail_dns_lost_at' ) );
		$this->assertSame( array(), MailDnsState::lost_keys( $r ) );
		$this->assertCount( 1, $this->mails );
		$this->assertSame( 'The DNS records for back.example are found again', $this->mails[0]['subject'] );
		$plain = (string) preg_replace( '/\s+/', ' ', (string) $this->mails[0]['message'] );
		$this->assertStringContainsString( 'The DNS records of back.example are found again. Email works as before.', $plain );
		$this->assertStringNotContainsString( 'v=spf1', $plain, 'No table.' );

		MailDns::run( $r->id() );
		$this->assertCount( 1, $this->mails, 'Once.' );
	}

	public function test_no_back_mail_when_no_lost_mail_was_sent(): void {
		$r = $this->ready_mail( 'first.example' );
		$this->found( $r, array( 'mx', 'spf', 'dkim' ) );
		$r->set( array( 'mail_dns_checked' => time() - 2 * DnsWatchSettings::look_seconds() ) );
		MailDns::run( $r->id() );

		$this->assertSame( 'ok', Resource::find( $r->id() )->get( 'mail_dns' ) );
		$this->assertCount( 0, $this->mails );
	}

	public function test_after_14_days_the_customer_is_told_once_that_we_stopped_looking(): void {
		$r = $this->ready_mail( 'late.example' );
		\BeltoftLicenses\Licenses::update( (int) $r->license()['id'], array( 'email' => 'christian.beltoft@gmail.com' ) );
		$r->set( array( 'mail_dns_since' => time() - 15 * DAY_IN_SECONDS, 'mail_dns_checked' => time() - 2 * DnsWatchSettings::look_seconds(), 'mail_dns_reminded' => time() ) );
		$this->dns = array();

		MailDns::run( $r->id() );
		$r = Resource::find( $r->id() );

		$this->assertSame( 'timeout', $r->get( 'mail_dns' ) );
		$this->assertSame( MailDnsState::STOPPED, MailDnsState::of( $r ) );
		$this->assertSame( 'We stopped checking DNS', MailDnsState::word( $r ) );
		$this->assertCount( 1, $this->mails );
		$this->assertSame( 'We have stopped checking the DNS of late.example', $this->mails[0]['subject'] );
		$plain = (string) preg_replace( '/\s+/', ' ', (string) $this->mails[0]['message'] );
		$this->assertStringContainsString( 'after 14 days. Your mailboxes and mail are kept.', $plain );
		$this->assertStringContainsString( 'press Check now', $plain );

		MailDns::run( $r->id() );
		MailDns::recheck( $r->id() );
		$this->assertCount( 1, $this->mails, 'Once.' );
	}

	public function test_after_the_timeout_a_weekly_look_goes_on_until_day_90(): void {
		$r = $this->ready_mail( 'weekly.example' );
		$r->set( array( 'mail_dns' => 'timeout', 'mail_dns_since' => time() - 20 * DAY_IN_SECONDS, 'mail_dns_rechecked' => 0 ) );

		MailDns::recheck_all();
		$this->assertTrue( Jobs::pending_action( MailDns::RECHECK, array( $r->id() ) ), 'Due: never looked at since the timeout.' );

		$this->dns = array();
		MailDns::recheck( $r->id() );
		$r = Resource::find( $r->id() );
		$this->assertSame( 'timeout', $r->get( 'mail_dns' ), 'Nothing found: still stopped, but looked at.' );
		$this->assertGreaterThan( 0, (int) $r->get( 'mail_dns_rechecked' ) );

		as_unschedule_all_actions( MailDns::RECHECK );
		MailDns::recheck_all();
		$this->assertFalse( Jobs::pending_action( MailDns::RECHECK, array( $r->id() ) ), 'Not due again for a week.' );

		$r->set( array( 'mail_dns_rechecked' => time() - 8 * DAY_IN_SECONDS ) );
		MailDns::recheck_all();
		$this->assertTrue( Jobs::pending_action( MailDns::RECHECK, array( $r->id() ) ) );

		as_unschedule_all_actions( MailDns::RECHECK );
		$r->set( array( 'mail_dns_since' => time() - 91 * DAY_IN_SECONDS ) );
		MailDns::recheck_all();
		$this->assertFalse( Jobs::pending_action( MailDns::RECHECK, array( $r->id() ) ), 'Day 90: the look stops for good.' );
	}

	public function test_the_weekly_look_finds_the_records_and_the_domain_is_ok_again(): void {
		$r = $this->confirmed_loss( 'revive.example' );
		$r->set( array( 'mail_dns' => 'timeout', 'mail_dns_rechecked' => 0 ) );
		$this->mails = array();
		$this->found( $r, array( 'mx', 'spf', 'dkim' ) );

		MailDns::recheck( $r->id() );
		$r = Resource::find( $r->id() );

		$this->assertSame( 'ok', $r->get( 'mail_dns' ) );
		$this->assertFalse( MailHold::held( $r ) );
		$this->assertSame( 1, $this->count_mails( 'The DNS records for revive.example are found again' ) );
	}

	public function test_the_admin_gets_one_alert_at_day_60_and_one_per_week_for_a_loss(): void {
		$r = $this->ready_mail( 'sixty.example' );
		$r->set( array( 'mail_dns' => 'timeout', 'mail_dns_since' => time() - 61 * DAY_IN_SECONDS, 'mail_dns_rechecked' => 0, 'mail_dns_timeout_mailed' => time() ) );
		$this->dns = array();

		MailDns::recheck( $r->id() );
		$alerts = array_filter( $this->subjects(), static fn( $s ) => str_contains( $s, 'no email DNS for 60 days' ) );
		$this->assertCount( 1, $alerts );

		$r->set( array( 'mail_dns_rechecked' => 0 ) );
		MailDns::recheck( $r->id() );
		$this->assertCount( 1, array_filter( $this->subjects(), static fn( $s ) => str_contains( $s, 'no email DNS for 60 days' ) ), 'Only once.' );
	}

	public function test_a_confirmed_loss_alerts_the_admin_once_a_week(): void {
		$r = $this->confirmed_loss( 'alert.example' );
		$this->assertCount( 1, array_filter( $this->subjects(), static fn( $s ) => str_contains( $s, 'Mail domain lost DNS records: alert.example' ) ) );
	}

	public function test_the_list_row_and_the_page_use_the_dns_word(): void {
		$r = $this->confirmed_loss( 'words.example' );
		$view = MailView::data( $r, array() );

		$this->assertStringStartsWith( 'DNS records missing since', $view['status'] );
		$this->assertSame( 'lost', $view['dns']['state'] );
		$this->assertStringContainsString( 'found before but are not found now: SPF', $view['dns']['notice'] );

		$r->set( array( 'mail_dns_lost_at' => time() - 4 * DAY_IN_SECONDS ) );
		$this->assertSame( 'bad', MailDnsState::tone( Resource::find( $r->id() ) ), 'Red after three days.' );
	}

	public function test_a_record_that_flaps_mails_one_lost_and_one_back_within_the_gap(): void {
		$r = $this->found_once( 'flap.example' );

		// Lost, back, lost, back within the hour.
		for ( $i = 0; $i < 2; $i++ ) {
			$this->found( $r, array( 'mx', 'dkim' ) );
			Resource::find( $r->id() )->set( array( 'mail_dns_rechecked' => 0 ) );
			MailDns::recheck( $r->id() );
			MailDns::confirm( $r->id() );
			$lost = Resource::find( $r->id() );

			$this->assertSame( MailDnsState::LOST, MailDnsState::of( $lost ), 'The page follows the real state.' );
			$this->assertTrue( MailHold::held( $lost ), 'So does the sending hold.' );

			$this->found( $r, array( 'mx', 'spf', 'dkim' ) );
			$lost->set( array( 'mail_dns_checked' => time() - 2 * DnsWatchSettings::look_seconds() ) );
			MailDns::run( $r->id() );
			$this->assertSame( 'ok', Resource::find( $r->id() )->get( 'mail_dns' ) );
			$this->assertFalse( MailHold::held( Resource::find( $r->id() ) ) );
		}

		$this->assertSame( 1, $this->count_mails( 'DNS records are missing for flap.example' ), 'One lost mail.' );
		$this->assertSame( 1, $this->count_mails( 'The DNS records for flap.example are found again' ), 'One back mail.' );
	}

	public function test_a_quiet_loss_that_lasts_past_the_gap_is_mailed_then(): void {
		$r = $this->found_once( 'quiet.example' );
		$r->set( array( 'mail_dns_last_mail' => time() - HOUR_IN_SECONDS ) );
		$this->found( $r, array( 'mx', 'dkim' ) );
		MailDns::recheck( $r->id() );
		MailDns::confirm( $r->id() );
		$this->assertSame( 0, $this->count_mails( 'DNS records are missing for' ), 'Inside the gap: nothing.' );

		MailDns::lost_reminders( $r->id() );
		$this->assertSame( 0, $this->count_mails( 'DNS records are missing for' ) );

		Resource::find( $r->id() )->set( array( 'mail_dns_last_mail' => time() - 25 * HOUR_IN_SECONDS ) );
		MailDns::lost_reminders( $r->id() );
		MailDns::lost_reminders( $r->id() );
		$this->assertSame( 1, $this->count_mails( 'DNS records are missing for quiet.example' ), 'Past the gap, still lost: told once.' );
	}

	public function test_the_gap_is_a_setting_and_zero_turns_it_off(): void {
		$this->settings( array( 'dns_watch_lost_mail_gap_hours' => 0 ) );
		$r = $this->found_once( 'nogap.example' );
		$r->set( array( 'mail_dns_last_mail' => time() ) );
		$this->found( $r, array( 'mx', 'dkim' ) );
		MailDns::recheck( $r->id() );
		MailDns::confirm( $r->id() );

		$this->assertSame( 1, $this->count_mails( 'DNS records are missing for nogap.example' ) );
	}

	public function test_the_staff_alert_of_a_loss_follows_the_mail_flag(): void {
		$r = $this->found_once( 'flag.example' );
		$f = new \BeltoftServices\Mail\MailDnsWatch();

		$this->assertStringContainsString( 'The customer was emailed.', implode( ' ', $f->alert_lines( 'lost', $r, array( 'mailed' => true ) ) ) );
		$quiet = implode( ' ', $f->alert_lines( 'lost', $r, array( 'mailed' => false ) ) );
		$this->assertStringContainsString( 'The customer was not emailed again: inside the mail gap.', $quiet );
		$this->assertStringNotContainsString( 'The customer was emailed.', $quiet );
	}
}

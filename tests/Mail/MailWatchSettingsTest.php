<?php
/**
 * The settings that steer the DNS watching of email domains: defaults, reading and the schedule they give.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Mail;

use BeltoftServices\Jobs;
use BeltoftServices\Mail\MailDns;
use BeltoftServices\Mail\MailSettings;
use BeltoftServices\Settings;

require_once __DIR__ . '/MailTestCase.php';

final class MailWatchSettingsTest extends MailTestCase {

	public function test_the_defaults_are_the_ones_the_owner_chose(): void {
		$this->assertSame( 10 * MINUTE_IN_SECONDS, MailSettings::dns_confirm_seconds() );
		$this->assertSame( array( 3, 10 ), MailSettings::dns_remind_days() );
		$this->assertSame( 2, MailSettings::dns_recheck_per_day() );
		$this->assertSame( 7, MailSettings::dns_weekly_days() );
		$this->assertSame( 90, MailSettings::dns_stop_days() );
		$this->assertSame( 60, MailSettings::dns_admin_days() );
		$this->assertSame( WEEK_IN_SECONDS, MailSettings::dns_admin_throttle() );
		$this->assertSame( 60, MailSettings::dns_check_gap() );
		$this->assertSame( 10, MailSettings::dns_check_per_hour() );
		$this->assertSame( 3 * DAY_IN_SECONDS, MailSettings::dns_red_after() );
		$this->assertTrue( MailSettings::dns_mx_strict() );
		$this->assertTrue( MailSettings::dns_cleanup_on_delete() );
		$this->assertSame( 30 * DAY_IN_SECONDS, MailSettings::retry_seconds() );
		$this->assertSame( 7, (int) Settings::get( 'dns_delegation_recheck_days' ) );
	}

	public function test_the_reminder_days_are_read_from_text(): void {
		$this->settings( array( 'mail_dns_remind_days' => ' 10, 3; x, 0, 3 ' ) );
		$this->assertSame( array( 3, 10 ), MailSettings::dns_remind_days() );

		$this->settings( array( 'mail_dns_remind_days' => '' ) );
		$this->assertSame( array(), MailSettings::dns_remind_days() );
	}

	public function test_the_looks_a_day_give_the_cron_the_guard_and_the_spread(): void {
		$this->assertSame( '40 3,15 * * *', MailSettings::dns_recheck_cron() );
		$this->assertSame( 10 * HOUR_IN_SECONDS, MailSettings::dns_recheck_guard() );
		$this->assertSame( 11 * HOUR_IN_SECONDS, MailSettings::dns_recheck_spread() );

		$this->settings( array( 'mail_dns_recheck_per_day' => '1' ) );
		$this->assertSame( '40 3 * * *', MailSettings::dns_recheck_cron() );
		$this->assertSame( 22 * HOUR_IN_SECONDS, MailSettings::dns_recheck_guard() );

		$this->settings( array( 'mail_dns_recheck_per_day' => '4' ) );
		$this->assertSame( '40 3,9,15,21 * * *', MailSettings::dns_recheck_cron() );
		$this->assertSame( 4 * HOUR_IN_SECONDS, MailSettings::dns_recheck_guard() );

		$this->settings( array( 'mail_dns_recheck_per_day' => '3' ) );
		$this->assertSame( 2, MailSettings::dns_recheck_per_day(), 'A value that is not offered is the default.' );
	}

	public function test_the_sweep_runs_at_the_times_of_the_setting(): void {
		Jobs::force_recurring();
		$declared = array_values( array_filter( Jobs::declared(), static fn( $d ) => MailDns::RECHECK_ALL === $d['hook'] ) );

		$this->assertSame( '40 3,15 * * *', $declared[0]['cron'] );
	}

	public function test_the_fields_are_on_the_email_tab(): void {
		$fields = Settings::fields();

		foreach ( array( 'mail_dns_confirm_minutes', 'mail_dns_remind_days', 'mail_dns_recheck_per_day', 'mail_dns_weekly_days', 'mail_dns_stop_days', 'mail_dns_admin_days', 'mail_dns_admin_throttle_days', 'mail_dns_check_gap', 'mail_dns_check_per_hour', 'mail_dns_lost_red_days', 'mail_dns_mx_strict', 'mail_dns_cleanup_on_delete', 'mail_retry_days' ) as $key ) {
			$this->assertSame( 'mail', $fields[ $key ]['tab'], $key );
			$this->assertSame( 'mail_watch', $fields[ $key ]['section'], $key );
		}
	}
}

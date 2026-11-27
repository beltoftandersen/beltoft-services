<?php
/**
 * The shared numbers of the DNS watch: defaults, the tab they live on, and the one-time copy of what the owner saved under Email.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Domains;

use BeltoftServices\Domains\DnsWatchSettings;
use BeltoftServices\Settings;
use BeltoftServices\Tests\TestCase;

final class DnsWatchSettingsTest extends TestCase {

	public function test_every_number_has_a_field_in_the_domains_tab_with_its_default(): void {
		$fields   = Settings::fields();
		$expected = array(
			'dns_watch_wait_days'           => 14,
			'dns_watch_look_minutes'        => 10,
			'dns_watch_first_remind_hours'  => 24,
			'dns_watch_confirm_minutes'     => 10,
			'dns_watch_remind_days'         => '3,10',
			'dns_watch_recheck_per_day'     => '2',
			'dns_watch_weekly_days'         => 7,
			'dns_watch_stop_days'           => 90,
			'dns_watch_admin_days'          => 60,
			'dns_watch_admin_throttle_days' => 7,
			'dns_watch_lost_mail_gap_hours' => 24,
			'dns_watch_check_gap'           => 60,
			'dns_watch_check_per_hour'      => 10,
			'dns_watch_lost_red_days'       => 3,
			'dns_watch_cleanup_on_delete'   => 1,
		);

		foreach ( $expected as $key => $default ) {
			$this->assertSame( 'domains', $fields[ $key ]['tab'], $key );
			$this->assertSame( 'dns_watch', $fields[ $key ]['section'], $key );
			$this->assertSame( $default, $fields[ $key ]['default'], $key );
		}

		$this->assertArrayHasKey( 'dns_watch', Settings::sections(), 'The section has a heading.' );
	}

	public function test_the_getters_give_seconds_and_days_and_fall_back_to_safe_values(): void {
		$this->assertSame( 14, DnsWatchSettings::wait_days() );
		$this->assertSame( 600, DnsWatchSettings::look_seconds() );
		$this->assertSame( DAY_IN_SECONDS, DnsWatchSettings::first_remind_seconds() );

		$this->settings( array( 'dns_watch_wait_days' => 0, 'dns_watch_look_minutes' => 1, 'dns_watch_stop_days' => 3 ) );
		$this->assertSame( 1, DnsWatchSettings::wait_days() );
		$this->assertSame( 300, DnsWatchSettings::look_seconds(), 'Never faster than the guard between two reads.' );
		$this->assertSame( 15, DnsWatchSettings::stop_days() );
	}

	public function test_what_the_owner_saved_under_email_is_copied_once_and_the_old_keys_go(): void {
		update_option(
			Settings::OPTION,
			array(
				'mail_host'                    => 'mail.example.net',
				'mail_dns_confirm_minutes'     => '25',
				'mail_dns_remind_days'         => '2,4',
				'mail_dns_check_gap'           => '90',
				'mail_dns_cleanup_on_delete'   => '0',
				'mail_dns_mx_strict'           => '0',
				'mail_retry_days'              => '12',
				'dns_watch_admin_days'         => '33',
				'mail_dns_admin_days'          => '44',
			)
		);

		$this->assertSame( 4, DnsWatchSettings::migrate(), 'Four values copied; the fifth was already saved under the new key.' );
		$stored = (array) get_option( Settings::OPTION );

		$this->assertSame( '25', $stored['dns_watch_confirm_minutes'] );
		$this->assertSame( '2,4', $stored['dns_watch_remind_days'] );
		$this->assertSame( '90', $stored['dns_watch_check_gap'] );
		$this->assertSame( '0', $stored['dns_watch_cleanup_on_delete'] );
		$this->assertSame( '33', $stored['dns_watch_admin_days'], 'A value already saved under the new key is never overwritten.' );
		$this->assertSame( 'mail.example.net', $stored['mail_host'] );
		$this->assertSame( '0', $stored['mail_dns_mx_strict'], 'The MX rule stays with email.' );
		$this->assertSame( '12', $stored['mail_retry_days'], 'So does the retry.' );

		foreach ( array_keys( DnsWatchSettings::MOVED ) as $old ) {
			$this->assertArrayNotHasKey( $old, $stored );
		}

		$this->assertSame( 25 * MINUTE_IN_SECONDS, DnsWatchSettings::confirm_seconds() );
		$this->assertSame( 0, DnsWatchSettings::migrate(), 'A second run copies nothing.' );
	}

	public function test_the_copy_runs_once_on_init_and_a_site_with_nothing_saved_keeps_its_defaults(): void {
		delete_option( DnsWatchSettings::MIGRATED );
		update_option( Settings::OPTION, array( 'mail_dns_stop_days' => '120' ) );

		DnsWatchSettings::maybe_migrate();

		$this->assertNotSame( '', (string) get_option( DnsWatchSettings::MIGRATED, '' ) );
		$this->assertSame( 120, DnsWatchSettings::stop_days() );

		update_option( Settings::OPTION, array() );
		delete_option( DnsWatchSettings::MIGRATED );
		DnsWatchSettings::maybe_migrate();
		$this->assertSame( 90, DnsWatchSettings::stop_days() );
	}
}

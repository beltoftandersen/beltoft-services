<?php
/** Outgoing traffic volume alerts: sustained and jump, admin only. @package BeltoftServices */
namespace BeltoftServices\Tests\Proxmox;

use BeltoftServices\Proxmox\ProxmoxSettings;
use BeltoftServices\Proxmox\Traffic;
use BeltoftServices\Resource;
use BeltoftServices\Settings;

require_once __DIR__ . '/MonitoringTestCase.php';

class TrafficTest extends MonitoringTestCase {
	private const OWNER = 'christian.beltoft@gmail.com';
	private const END = 1700000000;

	private array $mails = array();

	public function set_up(): void {
		parent::set_up();
		$this->mails = array();
		add_filter( 'pre_wp_mail', function ( $pre, $args ) { $this->mails[] = $args; return true; }, 10, 2 );
		$this->settings( array( 'traffic_alerts' => 'on', 'abuse_mode' => 'off', 'monitor_alerts' => 'off', 'admin_email' => self::OWNER ) );
	}

	/** Bytes per second of an Mbit/s rate. */
	private function bps( float $mbit ): float {
		return $mbit * 1000000 / 8;
	}

	/** One hourly window of 70 samples a minute apart, ending at $end. */
	private function window( int $end, callable $mbit ): array {
		$rows = array();
		for ( $m = 0; $m < 70; $m++ ) {
			$row = array( 'time' => $end - ( 69 - $m ) * 60, 'cpu' => 0.01 );
			$v = $mbit( $m );
			if ( null !== $v ) { $row['netout'] = $this->bps( $v ); }
			$rows[] = $row;
		}
		return $rows;
	}

	/** Hourly runs $first..$last (hour numbers) at a constant rate. */
	private function feed( Resource $r, int $first, int $last, float $mbit ): void {
		for ( $h = $first; $h <= $last; $h++ ) {
			$at = self::END + $h * 3600;
			Traffic::check( $r, $this->window( $at, fn() => $mbit ), $at );
		}
	}

	private function traffic_mails(): array {
		return array_values( array_filter( $this->mails, static fn( $m ) => false !== strpos( $m['subject'], 'High outgoing traffic' ) ) );
	}

	public function test_sustained_above_the_default_threshold_fires_once_to_the_admin_and_rearms_after_six_quiet_hours(): void {
		$r = $this->ready_vm( 5000, 'customer@example.com' );
		$this->feed( $r, 0, 3, 350.0 ); // No plan speed: 300 Mbit/s default.
		$mails = $this->traffic_mails();
		$this->assertCount( 1, $mails, 'Once per episode.' );
		$this->assertSame( self::OWNER, $mails[0]['to'] );
		$this->assertStringContainsString( 'sustained', $mails[0]['message'] );
		$this->assertStringContainsString( '350.0 Mbit/s', $mails[0]['message'] );
		$this->assertStringNotContainsString( '—', $mails[0]['message'] );
		$this->assertCount( 1, $this->mails, 'Nobody else is mailed, the customer included.' );

		$this->feed( $r, 4, 8, 10.0 ); // Five quiet hours: still armed off.
		$this->feed( $r, 9, 9, 350.0 );
		$this->assertCount( 1, $this->traffic_mails(), 'The signal came back before six quiet hours: the same episode.' );

		$this->feed( $r, 10, 16, 10.0 ); // Seven quiet runs.
		$this->feed( $r, 17, 17, 350.0 );
		$this->assertCount( 2, $this->traffic_mails(), 'Re-armed after six hours without the signal.' );
	}

	public function test_a_short_burst_is_not_sustained(): void {
		$r = $this->ready_vm();
		$at = self::END;
		Traffic::check( $r, $this->window( $at, fn( $m ) => $m >= 60 ? 900.0 : 5.0 ), $at );
		$this->assertCount( 0, $this->traffic_mails(), 'Ten minutes of burst average well under the limit over 30 minutes.' );
	}

	public function test_the_plan_speed_sets_the_threshold(): void {
		$r = $this->ready_vm();
		$this->assertTrue( $r->set( array( 'plan' => array( 'net_mbit' => 100 ) ) ) );
		$r = Resource::find( $r->id() );
		$this->feed( $r, 0, 0, 65.0 );
		$this->assertCount( 0, $this->traffic_mails(), '65 is under 70 percent of 100 Mbit/s.' );
		$this->feed( $r, 1, 1, 75.0 );
		$this->assertCount( 1, $this->traffic_mails(), '75 is over 70 percent of 100 Mbit/s, though far under the 300 default.' );
		$this->assertStringContainsString( 'limit of 70.0 Mbit/s', $this->traffic_mails()[0]['message'] );

		$this->settings( array( 'traffic_alert_percent' => 90 ) );
		$other = $this->ready_vm( 5001 );
		$this->assertTrue( $other->set( array( 'plan' => array( 'net_mbit' => 100 ) ) ) );
		$other = Resource::find( $other->id() );
		$this->feed( $other, 2, 2, 85.0 );
		$this->assertCount( 1, $this->traffic_mails(), 'The percent is a setting: 85 is under 90 percent.' );
	}

	public function test_a_jump_fires_once_against_the_week_median(): void {
		$r = $this->ready_vm();
		$this->feed( $r, 0, 29, 5.0 ); // Thirty quiet hours of 5 Mbit/s.
		$this->assertCount( 0, $this->traffic_mails() );
		$this->feed( $r, 30, 30, 80.0 );
		$mails = $this->traffic_mails();
		$this->assertCount( 1, $mails );
		$this->assertStringContainsString( 'jump', $mails[0]['message'] );
		$this->assertStringContainsString( 'median 5.0 Mbit/s', $mails[0]['message'] );
		$this->feed( $r, 31, 32, 80.0 );
		$this->assertCount( 1, $this->traffic_mails(), 'Deduped within the episode.' );
	}

	public function test_a_jump_under_the_floor_does_not_alert(): void {
		$r = $this->ready_vm();
		$this->feed( $r, 0, 29, 0.1 );
		$this->feed( $r, 30, 30, 2.0 ); // Twenty times the median, but under 20 Mbit/s.
		$this->assertCount( 0, $this->traffic_mails() );
		$this->feed( $r, 31, 31, 25.0 );
		$this->assertCount( 1, $this->traffic_mails(), 'Over the floor it fires.' );
	}

	public function test_no_jump_is_judged_before_a_day_of_hours_is_known(): void {
		$r = $this->ready_vm();
		$this->feed( $r, 0, 5, 1.0 );
		$this->feed( $r, 6, 6, 200.0 );
		$this->assertCount( 0, $this->traffic_mails(), '200 is under the 300 default and there is too little history for a jump.' );
	}

	public function test_off_adopted_and_gappy_readings_are_skipped(): void {
		$r = $this->ready_vm();
		$this->settings( array( 'traffic_alerts' => 'off' ) );
		$this->feed( $r, 0, 1, 500.0 );
		$this->assertCount( 0, $this->traffic_mails() );

		$this->settings( array( 'traffic_alerts' => 'on' ) );
		$linked = $this->ready_vm( 5001 );
		$this->assertTrue( $linked->set( array( 'linked' => true ) ) );
		$this->feed( Resource::find( $linked->id() ), 0, 1, 500.0 );
		$this->assertCount( 0, $this->traffic_mails(), 'Adopted machines are not judged.' );

		$gappy = $this->ready_vm( 5002 );
		$at = self::END;
		Traffic::check( $gappy, $this->window( $at, fn( $m ) => $m % 2 ? 500.0 : null ), $at );
		$this->assertCount( 0, $this->traffic_mails(), 'Too few usable samples is no verdict.' );
	}

	public function test_the_settings_are_defaulted_and_sanitised(): void {
		delete_option( Settings::OPTION );
		$this->assertSame( 'on', Settings::get( 'traffic_alerts' ) );
		$this->assertSame( 70, Settings::get( 'traffic_alert_percent' ) );
		$this->assertSame( 300, Settings::get( 'traffic_alert_mbit' ) );
		$this->assertSame( 10, Settings::get( 'traffic_jump_factor' ) );
		$this->assertSame( 20, Settings::get( 'traffic_jump_floor_mbit' ) );

		$out = ProxmoxSettings::sanitize( array( 'traffic_alert_percent' => 500, 'traffic_alert_mbit' => -4, 'traffic_jump_factor' => 0, 'traffic_jump_floor_mbit' => 'x', 'traffic_alerts' => 'maybe' ) );
		$this->assertSame( 100, $out['traffic_alert_percent'] );
		$this->assertSame( 1, $out['traffic_alert_mbit'] );
		$this->assertSame( 2, $out['traffic_jump_factor'] );
		$this->assertSame( 1, $out['traffic_jump_floor_mbit'] );
		$this->assertSame( 'off', $out['traffic_alerts'] );
	}
}

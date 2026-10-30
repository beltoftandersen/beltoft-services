<?php
/** Customer usage alerts: sustained CPU, the urgent disk level, the per-server switch. @package BeltoftServices */
namespace BeltoftServices\Tests\Proxmox;

use BeltoftServices\Proxmox\GuestDisk;
use BeltoftServices\Proxmox\Monitor;
use BeltoftServices\Proxmox\Usage;
use BeltoftServices\Resource;

require_once __DIR__ . '/MonitoringTestCase.php';

class MonitorUsageTest extends MonitoringTestCase {
	private const OWNER = 'christian.beltoft+usage-alerts@gmail.com';
	private const END = 1700000000;

	private array $mails = array();

	public function set_up(): void {
		parent::set_up();
		$this->mails = array();
		add_filter( 'pre_wp_mail', function ( $pre, $args ) { $this->mails[] = $args; return true; }, 10, 2 );
		$this->settings( array( 'monitor_alerts' => 'on', 'monitor_cpu_percent' => 90, 'monitor_cpu_hours' => 3, 'monitor_cooldown_days' => 7 ) );
	}

	/** One hourly window of 70 samples a minute apart, ending at $end; cpu as PVE's fraction. */
	private function window( int $end, callable $cpu ): array {
		$rows = array();
		for ( $m = 0; $m < 70; $m++ ) {
			$row = array( 'time' => $end - ( 69 - $m ) * 60 );
			$v = $cpu( $m );
			if ( null !== $v ) { $row['cpu'] = $v; }
			$rows[] = $row;
		}
		return $rows;
	}

	/** Feeds hourly readings $first .. $last (hour numbers) of a constant CPU. */
	private function feed( Resource $r, int $first, int $last, float $cpu ): void {
		for ( $h = $first; $h <= $last; $h++ ) {
			$at = self::END + $h * 3600;
			Monitor::usage( $r, $this->window( $at, fn() => $cpu ), $at );
		}
	}

	private function vm(): Resource {
		return $this->ready_vm( 5000, self::OWNER );
	}

	public function test_three_sustained_hourly_readings_send_one_cpu_email_to_the_owner(): void {
		$r = $this->vm();
		$this->feed( $r, 0, 1, 0.95 );
		$this->assertCount( 0, $this->mails, 'About two hours: under three.' );
		$this->feed( $r, 2, 2, 0.95 );
		$this->assertCount( 1, $this->mails );
		$this->assertSame( self::OWNER, $this->mails[0]['to'] );
		$this->assertStringContainsString( 'CPU', $this->mails[0]['subject'] );
		$this->assertStringContainsString( '3 hours', $this->mails[0]['subject'] );
		$this->assertStringNotContainsString( '—', $this->mails[0]['message'] );
		$this->feed( $r, 3, 6, 0.95 );
		$this->assertCount( 1, $this->mails, 'One email per episode.' );
		$fresh = Resource::find( $r->id() );
		$this->assertNotEmpty( $fresh->get( 'monitor_cpu_notified' ) );
		$this->assertNotEmpty( $fresh->get( 'monitor_cpu_since' ) );
	}

	public function test_a_spike_a_gap_or_one_low_reading_does_not_send(): void {
		$r = $this->vm();
		// One high hour, never sustained.
		$this->feed( $r, 0, 0, 0.99 );
		// A missed hour breaks the continuity.
		$this->feed( $r, 2, 3, 0.99 );
		$this->assertCount( 0, $this->mails );
		// A reading that ends low resets.
		$at = self::END + 4 * 3600;
		Monitor::usage( $r, $this->window( $at, fn( $m ) => $m < 69 ? 0.99 : 0.1 ), $at );
		$this->feed( $r, 5, 6, 0.99 );
		$this->assertCount( 0, $this->mails, 'The run starts again from zero after the low reading.' );
	}

	public function test_cooldown_blocks_a_second_episode_and_allows_it_after_seven_days(): void {
		$r = $this->vm();
		$this->feed( $r, 0, 2, 0.95 );
		$this->assertCount( 1, $this->mails );
		// Falls below 70 percent: re-armed silently.
		$at = self::END + 3 * 3600;
		Monitor::usage( $r, $this->window( $at, fn() => 0.2 ), $at );
		$this->assertNull( Resource::find( $r->id() )->get( 'monitor_cpu_notified' ) );
		$this->assertCount( 1, $this->mails, 'No resolved email.' );
		// A new sustained episode two days later is inside the cool-down.
		$base = 2 * 24;
		$this->feed( $r, $base, $base + 2, 0.95 );
		$this->assertCount( 1, $this->mails );
		// After seven days a new episode sends.
		$base = 8 * 24;
		$this->feed( $r, $base, $base + 2, 0.95 );
		$this->assertCount( 2, $this->mails );
	}

	public function test_switch_off_paused_busy_adopted_and_site_off_do_not_send(): void {
		$r = $this->vm();
		$r->set( array( 'usage_alerts' => 'off' ) );
		$this->feed( $r, 0, 3, 0.95 );
		$this->assertCount( 0, $this->mails );
		$r->set( array( 'usage_alerts' => null, 'state' => 'paused' ) );
		$this->feed( $r, 10, 13, 0.95 );
		$r->set( array( 'state' => 'ready', 'task' => array( 'upid' => 'busy' ) ) );
		$this->feed( $r, 20, 23, 0.95 );
		$r->set( array( 'task' => null, 'linked' => true ) );
		$this->feed( $r, 30, 33, 0.95 );
		$r->set( array( 'linked' => null ) );
		$this->settings( array( 'monitor_alerts' => 'off' ) );
		$this->feed( $r, 40, 43, 0.95 );
		$this->assertCount( 0, $this->mails );
	}

	public function test_failed_mail_is_retried_and_unusable_samples_change_nothing(): void {
		$r = $this->vm();
		Monitor::usage( $r, array( array( 'time' => self::END ) ), self::END );
		add_filter( 'pre_wp_mail', static fn() => false, 20 );
		$this->feed( $r, 0, 2, 0.95 );
		$this->assertNull( Resource::find( $r->id() )->get( 'monitor_cpu_notified' ) );
		$this->assertNull( Resource::find( $r->id() )->get( 'monitor_cpu_sent_at' ) );
	}

	public function test_urgent_disk_level_sends_once_and_rearms_below_ninety_two(): void {
		$this->settings( array( 'monitor_disk_percent' => 90, 'monitor_disk_urgent_percent' => 97 ) );
		$r = $this->vm();
		Monitor::disk( $r, array( 'used' => 90, 'total' => 100 ), 1000 );
		$this->assertCount( 1, $this->mails );
		$this->assertStringContainsString( '90 percent full', $this->mails[0]['subject'] );
		Monitor::disk( $r, array( 'used' => 97, 'total' => 100 ), 1100 );
		$this->assertCount( 2, $this->mails );
		$this->assertStringContainsString( 'almost completely full', $this->mails[1]['subject'] );
		Monitor::disk( $r, array( 'used' => 99, 'total' => 100 ), 1200 );
		$this->assertCount( 2, $this->mails );
		Monitor::disk( $r, array( 'used' => 93, 'total' => 100 ), 1300 );
		Monitor::disk( $r, array( 'used' => 97, 'total' => 100 ), 1400 );
		$this->assertCount( 2, $this->mails, 'Not re-armed at 93.' );
		Monitor::disk( $r, array( 'used' => 91, 'total' => 100 ), 1500 );
		Monitor::disk( $r, array( 'used' => 98, 'total' => 100 ), 1600 );
		$this->assertCount( 3, $this->mails );
		$this->assertSame( self::OWNER, $this->mails[2]['to'] );
	}

	public function test_a_disk_first_seen_above_urgent_sends_one_email_and_both_levels_are_marked(): void {
		$r = $this->vm();
		Monitor::disk( $r, array( 'used' => 98, 'total' => 100 ), 1000 );
		$this->assertCount( 1, $this->mails );
		Monitor::disk( $r, array( 'used' => 98, 'total' => 100 ), 1100 );
		$this->assertCount( 1, $this->mails );
		$fresh = Resource::find( $r->id() );
		$this->assertNotEmpty( $fresh->get( 'monitor_disk_notified' ) );
		$this->assertNotEmpty( $fresh->get( 'monitor_disk_urgent_notified' ) );
		Monitor::disk( $r, array( 'used' => 80, 'total' => 100 ), 1200 );
		$fresh = Resource::find( $r->id() );
		$this->assertNull( $fresh->get( 'monitor_disk_notified' ) );
		$this->assertNull( $fresh->get( 'monitor_disk_urgent_notified' ) );
	}

	public function test_customer_switch_stops_disk_mail_but_not_the_down_mail(): void {
		$r = $this->vm();
		$r->set( array( 'usage_alerts' => 'off' ) );
		Monitor::disk( $r, array( 'used' => 99, 'total' => 100 ), 1000 );
		$this->assertCount( 0, $this->mails );
		Monitor::power( $r, 'stopped', 1000 );
		Monitor::power( $r, 'stopped', 3000 );
		$this->assertCount( 1, $this->mails, 'The server down email is always sent.' );
	}

	public function test_disk_reads_are_hourly_only_at_or_above_eighty_five_percent(): void {
		$r = $this->vm();
		$now = time();
		$r->set( array( 'disk_used' => 50, 'disk_total' => 100, 'disk_read_at' => $now - 2 * HOUR_IN_SECONDS ) );
		$this->assertFalse( GuestDisk::due( Resource::find( $r->id() ) ) );
		$r->set( array( 'disk_used' => 86, 'disk_total' => 100, 'disk_read_at' => $now - 2 * HOUR_IN_SECONDS ) );
		$this->assertTrue( GuestDisk::due( Resource::find( $r->id() ) ) );
		$r->set( array( 'disk_read_at' => $now - 30 * MINUTE_IN_SECONDS ) );
		$this->assertFalse( GuestDisk::due( Resource::find( $r->id() ) ) );
		$r->set( array( 'disk_used' => 50, 'disk_read_at' => $now - 6 * HOUR_IN_SECONDS + 300 ) );
		$this->assertTrue( GuestDisk::due( Resource::find( $r->id() ) ), 'A normal reading is read when it is a little under six hours old.' );
	}

	public function test_a_server_without_a_root_mount_gets_no_disk_mail(): void {
		$r = $this->vm();
		$this->assertNull( GuestDisk::root( array( 'result' => array( array( 'mountpoint' => 'C:\\', 'used-bytes' => 99, 'total-bytes' => 100 ) ) ) ) );
	}
}

<?php
/** Customer memory alert: sustained high memory in use, one email per episode, re-arm, cool-down, quiet cases. @package BeltoftServices */
namespace BeltoftServices\Tests\Proxmox\Network;

use BeltoftServices\Drivers\Registry;
use BeltoftServices\Product;
use BeltoftServices\Monitoring\GuestMemory;
use BeltoftServices\Proxmox\Network\Monitor;
use BeltoftServices\Proxmox\Vm\Schema;
use BeltoftServices\Resource;

require_once __DIR__ . '/MonitoringTestCase.php';

class MonitorMemoryTest extends MonitoringTestCase {
	private const OWNER = 'christian.beltoft+memory-alerts@gmail.com';

	private array $mails = array();
	private int $base;

	public function set_up(): void {
		parent::set_up();
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Test fixture reset.
		$wpdb->query( 'DELETE FROM ' . Schema::tables()['guest_memory'] );
		$this->mails = array();
		$this->base  = intdiv( time(), 300 ) * 300;
		add_filter( 'pre_wp_mail', function ( $pre, $args ) { $this->mails[] = $args; return true; }, 10, 2 );
		$this->settings( array( 'monitor_alerts' => 'on', 'monitor_mem_percent' => 90, 'monitor_mem_minutes' => 15, 'monitor_cooldown_days' => 7 ) );
	}

	public function tear_down(): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Test fixture reset.
		$wpdb->query( 'DELETE FROM ' . Schema::tables()['guest_memory'] );
		parent::tear_down();
	}

	private function vm(): Resource {
		return $this->ready_vm( 5000, self::OWNER );
	}

	/** A reading in the slot $slots_back slots before the current one, as percent of 2000 MB. */
	private function reading( Resource $r, int $slots_back, int $percent ): void {
		GuestMemory::store( $r->id(), array( 'used_mb' => (int) ( $percent * 20 ), 'total_mb' => 2000 ), $this->base - $slots_back * 300 + 10 );
	}

	private function check( Resource $r, ?int $uptime = 7200 ): void {
		Monitor::memory( Resource::find( $r->id() ), $uptime, $this->base + 20 );
	}

	public function test_three_readings_in_a_row_at_the_threshold_send_one_email_to_the_owner(): void {
		$r = $this->vm();
		$this->reading( $r, 2, 95 );
		$this->reading( $r, 1, 90 );
		$this->check( $r );
		$this->assertCount( 0, $this->mails, 'Two readings are not 15 minutes.' );
		$this->reading( $r, 0, 96 );
		$this->check( $r );
		$this->assertCount( 1, $this->mails );
		$this->assertSame( self::OWNER, $this->mails[0]['to'] );
		$this->assertStringContainsString( 'memory', $this->mails[0]['subject'] );
		$this->assertStringContainsString( 'free -h', $this->mails[0]['message'] );
		$this->assertStringContainsString( 'file cache', $this->mails[0]['message'] );
		$this->assertStringNotContainsString( '—', $this->mails[0]['message'] );
		$this->assertStringNotContainsString( '·', $this->mails[0]['message'] );
		$this->check( $r );
		$this->assertCount( 1, $this->mails, 'One email per episode.' );
		$fresh = Resource::find( $r->id() );
		$this->assertNotEmpty( $fresh->get( 'monitor_mem_notified' ) );
		$this->assertNotEmpty( $fresh->get( 'monitor_mem_since' ) );
		$this->assertStringContainsString( 'Memory high since', implode( ' ', \BeltoftServices\Proxmox\Vm\Panels::usage_states( $fresh ) ) );
	}

	public function test_one_low_reading_or_a_gap_breaks_the_run(): void {
		$r = $this->vm();
		$this->reading( $r, 2, 95 );
		$this->reading( $r, 1, 85 );
		$this->reading( $r, 0, 96 );
		$this->check( $r );
		$this->assertCount( 0, $this->mails );

		$gap = $this->ready_vm( 5001, self::OWNER );
		$this->reading( $gap, 6, 95 );
		$this->reading( $gap, 1, 95 );
		$this->reading( $gap, 0, 95 );
		$this->check( $gap );
		$this->assertCount( 0, $this->mails, 'A long gap in the readings is not a run.' );
	}

	public function test_the_alert_waits_fifteen_minutes_after_a_boot_and_ignores_readings_from_before_it(): void {
		$r = $this->vm();
		foreach ( array( 2, 1, 0 ) as $back ) { $this->reading( $r, $back, 97 ); }
		$this->check( $r, 600 );
		$this->assertCount( 0, $this->mails, 'Ten minutes after a boot or reinstall: memory is still settling.' );
		$this->check( $r, 3600 );
		$this->assertCount( 1, $this->mails );

		$this->settings( array( 'monitor_mem_minutes' => 30 ) );
		$long = $this->ready_vm( 5001, self::OWNER );
		foreach ( array( 5, 4, 3, 2, 1, 0 ) as $back ) { $this->reading( $long, $back, 97 ); }
		$this->check( $long, 1000 );
		$this->assertCount( 1, $this->mails, 'Readings from before the boot do not count: 30 minutes needed, 16 minutes of uptime.' );
	}

	public function test_a_database_server_gets_no_memory_alert(): void {
		$product = $this->service_product( 'proxmox-db', array( 'cluster' => 'lab', 'template' => 9100, 'engine' => 'postgres', 'major' => 18, 'cores' => 2, 'memory_mb' => 4096, 'disk_gb' => 20, 'public_ip' => 0 ), array( 'max' => 3 ) );
		$license = $this->service_license( $product, array( 'fire' => false, 'email' => self::OWNER ) );
		$db      = Resource::create( $license, Registry::get( 'proxmox-db' ), Product::plan( $product->get_id() ), array() );
		$db->set( array( 'state' => 'ready', 'desired' => 'ready' ) );
		foreach ( array( 2, 1, 0 ) as $back ) { $this->reading( $db, $back, 99 ); }
		$this->check( $db );
		$this->assertCount( 0, $this->mails, 'Databases reserve memory on purpose.' );
	}

	public function test_off_switch_adopted_machine_and_customer_switch_send_nothing(): void {
		$r = $this->vm();
		foreach ( array( 2, 1, 0 ) as $back ) { $this->reading( $r, $back, 97 ); }
		$this->settings( array( 'monitor_alerts' => 'off' ) );
		$this->check( $r );
		$this->settings( array( 'monitor_alerts' => 'on' ) );
		$r->set( array( 'usage_alerts' => 'off' ) );
		$this->check( $r );
		$r->set( array( 'usage_alerts' => null, 'linked' => true ) );
		$this->check( $r );
		$this->assertCount( 0, $this->mails );
		$r->set( array( 'linked' => null ) );
		$this->check( $r );
		$this->assertCount( 1, $this->mails );
	}

	public function test_it_re_arms_ten_points_lower_and_the_cooldown_holds_a_second_email(): void {
		$r = $this->vm();
		foreach ( array( 2, 1, 0 ) as $back ) { $this->reading( $r, $back, 95 ); }
		$this->check( $r );
		$this->assertCount( 1, $this->mails );

		// 85 is not ten points under 90: still the same episode.
		$this->base += 300;
		$this->reading( $r, 0, 85 );
		$this->check( $r );
		$this->assertNotEmpty( Resource::find( $r->id() )->get( 'monitor_mem_notified' ) );

		// 80 is: the alert is armed again.
		$this->base += 300;
		$this->reading( $r, 0, 80 );
		$this->check( $r );
		$this->assertEmpty( Resource::find( $r->id() )->get( 'monitor_mem_notified' ) );

		// A new episode within the cool-down sends nothing.
		foreach ( array( 2, 1, 0 ) as $back ) { $this->base += 300; $this->reading( $r, 0, 96 ); }
		$this->check( $r );
		$this->assertCount( 1, $this->mails, 'Seven days between two emails for one server.' );

		// After the cool-down it does.
		$r->set( array( 'monitor_mem_sent_at' => time() - 8 * DAY_IN_SECONDS ) );
		$this->check( $r );
		$this->assertCount( 2, $this->mails );
	}

	public function test_the_threshold_and_minutes_settings_are_used(): void {
		$this->settings( array( 'monitor_mem_percent' => 70, 'monitor_mem_minutes' => 5 ) );
		$r = $this->vm();
		$this->reading( $r, 0, 75 );
		$this->check( $r );
		$this->assertCount( 1, $this->mails, 'One 5-minute reading at 75 percent with a threshold of 70.' );
	}
}

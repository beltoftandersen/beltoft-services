<?php
/** Customer VPS alert rules. @package BeltoftServices */
namespace BeltoftServices\Tests\Proxmox;

use BeltoftServices\Proxmox\Monitor;
use BeltoftServices\Resource;

require_once __DIR__ . '/MonitoringTestCase.php';

class MonitorTest extends MonitoringTestCase {
	private array $mails = array();

	public function set_up(): void {
		parent::set_up();
		$this->mails = array();
		add_filter( 'pre_wp_mail', function ( $pre, $args ) { $this->mails[] = $args; return true; }, 10, 2 );
	}

	public function test_down_needs_two_readings_thirty_minutes_apart_and_sends_once(): void {
		$this->settings( array( 'monitor_alerts' => 'on' ) );
		$r = $this->ready_vm();
		Monitor::power( $r, 'stopped', 1000 );
		Monitor::power( $r, 'stopped', 2000 );
		$this->assertCount( 0, $this->mails );
		Monitor::power( $r, 'stopped', 2800 );
		$this->assertCount( 1, $this->mails );
		Monitor::power( $r, 'stopped', 5000 );
		$this->assertCount( 1, $this->mails );
		Monitor::power( $r, 'running', 5100 );
		$this->assertNull( Resource::find( $r->id() )->get( 'monitor_down_notified' ) );
		Monitor::power( $r, 'stopped', 5200 );
		Monitor::power( $r, 'stopped', 7000 );
		$this->assertCount( 2, $this->mails );
	}

	public function test_customer_stop_and_off_setting_do_not_send(): void {
		$r = $this->ready_vm();
		$r->set( array( 'stopped_by_customer' => 900 ) );
		$this->settings( array( 'monitor_alerts' => 'on' ) );
		Monitor::power( $r, 'stopped', 1000 );
		Monitor::power( $r, 'stopped', 3000 );
		$this->assertCount( 0, $this->mails );
		Monitor::power( $r, 'running', 3100 );
		$this->assertNull( Resource::find( $r->id() )->get( 'stopped_by_customer' ) );
		$this->settings( array( 'monitor_alerts' => 'off' ) );
		Monitor::power( $r, 'stopped', 3200 );
		Monitor::power( $r, 'stopped', 5200 );
		$this->assertCount( 0, $this->mails );
	}

	public function test_paused_or_busy_vm_is_not_marked_down(): void {
		$this->settings( array( 'monitor_alerts' => 'on' ) );
		$r = $this->ready_vm();
		$r->set( array( 'state' => 'paused' ) );
		Monitor::power( $r, 'stopped', 1000 );
		$this->assertNull( Resource::find( $r->id() )->get( 'monitor_down_since' ) );
		$r->set( array( 'state' => 'ready', 'task' => array( 'upid' => 'busy' ) ) );
		Monitor::power( $r, 'stopped', 2000 );
		$this->assertNull( Resource::find( $r->id() )->get( 'monitor_down_since' ) );
	}

	public function test_disk_alert_rearms_only_after_usage_drops_five_points(): void {
		$this->settings( array( 'monitor_alerts' => 'on', 'monitor_disk_percent' => 90 ) );
		$r = $this->ready_vm();
		Monitor::disk( $r, array( 'used' => 90, 'total' => 100 ), 1000 );
		$this->assertCount( 1, $this->mails );
		$this->assertStringNotContainsString( 'Proxmox', print_r( $this->mails[0], true ) );
		Monitor::disk( $r, array( 'used' => 86, 'total' => 100 ), 1100 );
		Monitor::disk( $r, array( 'used' => 92, 'total' => 100 ), 1200 );
		$this->assertCount( 1, $this->mails );
		Monitor::disk( $r, array( 'used' => 84, 'total' => 100 ), 1300 );
		Monitor::disk( $r, array( 'used' => 90, 'total' => 100 ), 1400 );
		$this->assertCount( 2, $this->mails );
	}
}

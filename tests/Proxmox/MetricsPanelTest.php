<?php
/** Customer usage panel tests. @package BeltoftServices */
namespace BeltoftServices\Tests\Proxmox;

use BeltoftServices\Proxmox\Panels;

require_once __DIR__ . '/MonitoringTestCase.php';

class MetricsPanelTest extends MonitoringTestCase {
	private function output( $r ): string {
		ob_start();
		Panels::account( $r );
		return (string) ob_get_clean();
	}

	public function test_ready_vm_shows_four_usage_charts_and_range_buttons(): void {
		$html = $this->output( $this->ready_vm() );
		$this->assertStringContainsString( 'data-vm-metrics', $html );
		$this->assertSame( 4, substr_count( $html, 'data-chart=' ) );
		$this->assertStringContainsString( 'data-range="month"', $html );
		$this->assertStringContainsString( 'Disk: 30 GB', $html );
		$this->assertStringNotContainsString( 'Proxmox', $html );
	}

	public function test_disk_reading_is_displayed_and_paused_vm_has_no_charts(): void {
		$r = $this->ready_vm();
		$r->set( array( 'disk_used' => 12 * 1073741824, 'disk_total' => 30 * 1073741824, 'disk_read_at' => time() ) );
		$this->assertStringContainsString( 'Disk used: 12.0 of 30.0 GB', $this->output( $r ) );
		$r->set( array( 'disk_read_at' => time() - 6 * HOUR_IN_SECONDS - 1 ) );
		$this->assertStringContainsString( 'Disk: 30 GB', $this->output( $r ) );
		$r->set( array( 'state' => 'paused' ) );
		$this->assertStringNotContainsString( 'data-vm-metrics', $this->output( $r ) );
	}

	public function test_admin_panel_shows_monitoring_state(): void {
		$r = $this->ready_vm();
		$r->set( array( 'disk_used' => 12, 'disk_total' => 30, 'disk_read_at' => 1700000000, 'monitor_down_since' => 1700000000, 'monitor_down_notified' => 1700001800 ) );
		ob_start();
		Panels::admin( $r );
		$html = (string) ob_get_clean();
		$this->assertStringContainsString( 'Disk reading', $html );
		$this->assertStringContainsString( '12 / 30 bytes', $html );
		$this->assertStringContainsString( 'Down since', $html );
		$this->assertStringContainsString( 'Down alert sent', $html );
	}
}

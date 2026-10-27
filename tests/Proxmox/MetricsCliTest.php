<?php
/** Read-only VPS metrics CLI tests. @package BeltoftServices */
namespace BeltoftServices\Tests\Proxmox;

use BeltoftServices\Cli\Commands;

require_once __DIR__ . '/MonitoringTestCase.php';
require_once dirname( __DIR__ ) . '/Support/WpCliStub.php';

class MetricsCliTest extends MonitoringTestCase {
	public function test_command_prints_latest_point_without_guest_agent_call(): void {
		$r = $this->ready_vm();
		\WP_CLI::reset();
		$this->fake->set_rrd( 5000, array( array( 'time' => 100, 'cpu' => 0.4 ) ) );
		( new Commands() )->vm_metrics( array( (string) $r->license()['id'] ), array( 'range' => 'hour' ) );
		$this->assertStringContainsString( 'points: 1', \WP_CLI::text() );
		$this->assertStringContainsString( '"cpu":40', \WP_CLI::text() );
		$this->assertCount( 0, array_filter( $this->fake->calls(), static fn( $call ) => str_contains( $call['path'], 'get-fsinfo' ) ) );
	}

	public function test_disk_option_reads_guest_agent_and_prints_root_usage(): void {
		$r = $this->ready_vm();
		\WP_CLI::reset();
		$reply = json_decode( (string) file_get_contents( dirname( __DIR__ ) . '/fixtures/proxmox/get-fsinfo.json' ), true );
		$this->fake->set_fsinfo( 5000, $reply );
		( new Commands() )->vm_metrics( array( (string) $r->license()['id'] ), array( 'disk' => true ) );
		$this->assertStringContainsString( '"used":884944896', \WP_CLI::text() );
		$this->assertCount( 1, array_filter( $this->fake->calls(), static fn( $call ) => str_contains( $call['path'], 'get-fsinfo' ) ) );
	}
}

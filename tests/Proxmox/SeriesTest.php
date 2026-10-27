<?php
/** VM metrics source tests. @package BeltoftServices */
namespace BeltoftServices\Tests\Proxmox;

use BeltoftServices\Proxmox\Operations;

require_once __DIR__ . '/MonitoringTestCase.php';

class SeriesTest extends MonitoringTestCase {
	public function test_owned_vm_series_uses_expected_endpoint_and_range(): void {
		$r = $this->ready_vm();
		$rows = json_decode( (string) file_get_contents( dirname( __DIR__ ) . '/fixtures/proxmox/rrddata-hour.json' ), true );
		$this->fake->set_rrd( 5000, $rows );
		$this->assertSame( $rows, ( new Operations( $r ) )->series( 'hour' ) );
		$calls = array_values( array_filter( $this->fake->calls(), static fn( $call ) => str_ends_with( $call['path'], '/rrddata' ) ) );
		$this->assertCount( 1, $calls );
		$this->assertSame( array( 'timeframe' => 'hour', 'cf' => 'AVERAGE' ), $calls[0]['params'] );
	}

	public function test_wrong_tag_and_unknown_range_make_no_series_read(): void {
		$r = $this->ready_vm();
		$this->assertNull( ( new Operations( $r ) )->series( 'week' ) );
		$vm = $this->fake->vm( 5000 );
		$vm['config']['description'] = 'somebody else';
		$this->fake->add_vm( $vm );
		$this->assertNull( ( new Operations( $r ) )->series( 'hour' ) );
		$calls = array_filter( $this->fake->calls(), static fn( $call ) => str_ends_with( $call['path'], '/rrddata' ) );
		$this->assertCount( 0, $calls );
	}
}

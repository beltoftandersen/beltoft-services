<?php
/** Usage sample shaping tests. @package BeltoftServices */
namespace BeltoftServices\Tests\Proxmox;

use BeltoftServices\Proxmox\Metrics;
use BeltoftServices\Tests\TestCase;

class MetricsTest extends TestCase {
	public function test_cpu_is_percent_and_missing_values_stay_gaps(): void {
		$points = Metrics::shape( array(
			array( 'time' => 100, 'cpu' => 0.5, 'mem' => 1073741824 ),
			array( 'time' => 160, 'cpu' => null, 'mem' => 'unknown' ),
			array( 'time' => 'invalid', 'cpu' => 1 ),
		), 'hour' );
		$this->assertSame( 50.0, $points['points'][0]['cpu'] );
		$this->assertSame( 1073741824.0, $points['points'][0]['mem'] );
		$this->assertNull( $points['points'][1]['cpu'] );
		$this->assertNull( $points['points'][1]['mem'] );
		$this->assertCount( 2, $points['points'] );
	}

	public function test_large_series_is_averaged_to_at_most_120_points(): void {
		$rows = array();
		for ( $i = 0; $i < 1440; $i++ ) {
			$rows[] = array( 'time' => 1000 + $i * 60, 'cpu' => 0.25 );
		}
		$series = Metrics::shape( $rows, 'day' );
		$this->assertCount( 120, $series['points'] );
		$this->assertSame( 720, $series['step'] );
		$this->assertSame( 25.0, $series['points'][0]['cpu'] );
	}

	public function test_unknown_range_is_refused(): void {
		$this->expectException( \InvalidArgumentException::class );
		Metrics::shape( array(), 'week' );
	}
}

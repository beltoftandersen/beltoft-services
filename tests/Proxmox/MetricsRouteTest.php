<?php
/** Owner-only metrics route tests. @package BeltoftServices */
namespace BeltoftServices\Tests\Proxmox;

use BeltoftServices\Proxmox\MetricsRoute;
use BeltoftServices\Resource;

require_once __DIR__ . '/MonitoringTestCase.php';

class MetricsRouteTest extends MonitoringTestCase {
	private function call_route( int $id, string $range = 'hour' ): \WP_REST_Response {
		$request = new \WP_REST_Request( 'GET', '/beltoft-services/v1/resources/' . $id . '/metrics' );
		$request->set_param( 'id', $id );
		$request->set_param( 'range', $range );
		return MetricsRoute::get( $request );
	}

	public function test_owner_gets_series_and_second_request_uses_cache(): void {
		$r = $this->ready_vm();
		wp_set_current_user( (int) $r->license()['user_id'] );
		$this->fake->set_rrd( 5000, array( array( 'time' => 100, 'cpu' => 0.25 ) ) );
		$first = $this->call_route( $r->id() );
		$second = $this->call_route( $r->id() );
		$this->assertSame( 200, $first->get_status() );
		$this->assertSame( 25.0, $first->get_data()['points'][0]['cpu'] );
		$this->assertSame( $first->get_data(), $second->get_data() );
		$calls = array_filter( $this->fake->calls(), static fn( $call ) => str_ends_with( $call['path'], '/rrddata' ) );
		$this->assertCount( 1, $calls );
	}

	public function test_other_user_and_paused_resource_are_refused(): void {
		$r = $this->ready_vm();
		wp_set_current_user( self::factory()->user->create() );
		$this->assertSame( 403, $this->call_route( $r->id() )->get_status() );
		wp_set_current_user( (int) $r->license()['user_id'] );
		$r->set( array( 'state' => 'paused' ) );
		$this->assertSame( 403, $this->call_route( $r->id() )->get_status() );
	}

	public function test_logged_out_dispatch_is_refused_before_reading_a_vm(): void {
		$r = $this->ready_vm();
		wp_set_current_user( 0 );
		$request = new \WP_REST_Request( 'GET', '/beltoft-services/v1/resources/' . $r->id() . '/metrics' );
		$response = rest_do_request( $request );
		$this->assertSame( 401, $response->get_status() );
		$this->assertCount( 0, array_filter( $this->fake->calls(), static fn( $call ) => str_ends_with( $call['path'], '/rrddata' ) ) );
	}

	public function test_bad_range_and_unavailable_series(): void {
		$r = $this->ready_vm();
		wp_set_current_user( (int) $r->license()['user_id'] );
		$this->assertSame( 400, $this->call_route( $r->id(), 'week' )->get_status() );
		$this->fake->fail_next( 'GET', '#/rrddata$#', new \WP_Error( 'offline', 'Offline.' ) );
		$failed = $this->call_route( $r->id() );
		$this->assertSame( false, $failed->get_data()['available'] );
		$this->fake->set_rrd( 5000, array( array( 'time' => 100, 'cpu' => 0.5 ) ) );
		$rrd = static fn( $fake ) => count( array_filter( $fake->calls(), static fn( $call ) => str_ends_with( $call['path'], '/rrddata' ) ) );
		$before = $rrd( $this->fake );
		$this->assertSame( false, $this->call_route( $r->id() )->get_data()['available'], 'A failed read is kept briefly.' );
		$this->assertSame( $before, $rrd( $this->fake ), 'No new cluster read while the failure is kept.' );
		$this->assertLessThanOrEqual( 20, (int) get_option( '_transient_timeout_beltoft_services_proxmox_metrics_' . $r->id() . '_hour' ) - time(), 'Kept for 20 seconds, not a minute.' );
		delete_transient( 'beltoft_services_proxmox_metrics_' . $r->id() . '_hour' );
		$this->assertSame( true, $this->call_route( $r->id() )->get_data()['available'], 'After the short window the cluster is read again.' );
	}
}

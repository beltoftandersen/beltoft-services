<?php
/** Guest memory: parsing, live and background reads, bar states, history, retention, metrics route, MCP. @package BeltoftServices */
namespace BeltoftServices\Tests\Proxmox\Network;

use BeltoftLicenses\Account\View;
use BeltoftServices\Proxmox\Vm\Servers;
use BeltoftServices\Mcp\Tools;
use BeltoftServices\Monitoring\GuestMemory;
use BeltoftServices\Monitoring\LiveUsage;
use BeltoftServices\Monitoring\MetricsRoute;
use BeltoftServices\Proxmox\Pve\Clusters;
use BeltoftServices\Proxmox\Pve\FakeClient;
use BeltoftServices\Proxmox\Pve\Ownership;
use BeltoftServices\Proxmox\Vm\Operations;
use BeltoftServices\Proxmox\Vm\Schema;
use BeltoftServices\Proxmox\Vm\VmRecords;
use BeltoftServices\Resource;

require_once __DIR__ . '/MonitoringTestCase.php';

class GuestMemoryTest extends MonitoringTestCase {
	private const MEMINFO = "MemTotal:        2097152 kB\nMemFree:          100000 kB\nMemAvailable:    1048576 kB\nBuffers:           1000 kB\n";

	public function set_up(): void {
		parent::set_up();
		// The jobs only look at a fake cluster that keeps its state (as staging does).
		delete_option( FakeClient::OPTION );
		$this->fake = new FakeClient( true );
		$this->fake->add_node( 'node-a' );
		$stored        = get_option( Clusters::OPTION );
		$stored['lab'] = array_merge( $stored['lab'], array( 'persist' => 1 ) );
		update_option( Clusters::OPTION, $stored, false );
		$this->wipe();
	}

	public function tear_down(): void {
		$this->wipe();
		delete_option( FakeClient::OPTION );
		parent::tear_down();
	}

	private function wipe(): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Test fixture reset.
		$wpdb->query( 'DELETE FROM ' . Schema::tables()['guest_memory'] );
		wp_cache_flush();
	}

	private function rows( int $resource_id, int $span = 0 ): int {
		global $wpdb;
		$sql = 'SELECT COUNT(*) FROM ' . Schema::tables()['guest_memory'] . ' WHERE resource_id = %d' . ( $span > 0 ? ' AND span = ' . (int) $span : '' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- Test read of the plugin's table.
		return (int) $wpdb->get_var( $wpdb->prepare( $sql, $resource_id ) );
	}

	private function calls( string $suffix ): int {
		return count( array_filter( $this->fake->calls(), static fn( $call ) => str_ends_with( $call['path'], $suffix ) ) );
	}

	private function route(): \WP_REST_Response {
		return rest_do_request( new \WP_REST_Request( 'GET', '/beltoft-services/v1/usage' ) );
	}

	private function insert( int $resource_id, int $span, int $bucket, int $used, int $total, ?int $max = null ): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Test fixture.
		$wpdb->query( $wpdb->prepare( 'INSERT INTO %i (resource_id, span, bucket, used_mb, total_mb, used_max_mb) VALUES (%d, %d, %d, %d, %d, %d)', Schema::tables()['guest_memory'], $resource_id, $span, $bucket, $used, $total, $max ?? $used ) );
	}

	public function test_meminfo_gives_used_and_total_in_mb_without_the_file_cache(): void {
		$this->assertSame( array( 'used_mb' => 1024, 'total_mb' => 2048 ), GuestMemory::parse( self::MEMINFO ) );
		$this->assertSame( 50, LiveUsage::meminfo( self::MEMINFO ) );
		$this->assertNull( GuestMemory::parse( "MemTotal: 100 kB\n" ) );
		$this->assertNull( GuestMemory::parse( "MemTotal: 100 kB\nMemAvailable: 200 kB\n" ) );
		$this->assertNull( GuestMemory::parse( '' ) );
	}

	public function test_the_job_reads_only_tagged_running_vms_and_writes_one_row_per_slot(): void {
		$tagged  = $this->ready_vm( 5000 );
		$other   = $this->ready_vm( 5001 );
		$stopped = $this->ready_vm( 5002 );
		$this->fake->set_guest_file( 5000, '/proc/meminfo', self::MEMINFO );
		$this->fake->set_guest_file( 5001, '/proc/meminfo', self::MEMINFO );
		$this->fake->set_guest_file( 5002, '/proc/meminfo', self::MEMINFO );
		$this->fake->request( 'PUT', '/nodes/node-a/qemu/5001/config', array( 'description' => 'someone else' ) );
		$this->fake->request( 'POST', '/nodes/node-a/qemu/5002/status/stop' );

		GuestMemory::run();

		$this->assertSame( 1, $this->calls( '/qemu/5000/agent/file-read' ) );
		$this->assertSame( 0, $this->calls( '/qemu/5001/agent/file-read' ), 'A VM without our tag is never read.' );
		$this->assertSame( 0, $this->calls( '/qemu/5002/agent/file-read' ), 'A stopped VM is not read.' );
		$this->assertSame( 1, $this->rows( $tagged->id() ) );
		$this->assertSame( 0, $this->rows( $other->id() ) + $this->rows( $stopped->id() ) );

		// A second round in the same slot, even with the latest value gone, adds no row.
		delete_transient( GuestMemory::LATEST_PREFIX . $tagged->id() );
		GuestMemory::run();
		$this->assertSame( 1, $this->rows( $tagged->id() ), 'One row per five minutes and server.' );
		$this->assertSame( 50, LiveUsage::cached_memory( $tagged->id() ) );
	}

	public function test_a_server_read_live_in_the_previous_slot_a_moment_ago_still_gets_a_row_in_the_new_slot(): void {
		$r = $this->ready_vm( 5000 );
		$this->fake->set_guest_file( 5000, '/proc/meminfo', self::MEMINFO );
		$slot = intdiv( time(), 300 ) * 300;
		GuestMemory::store( $r->id(), array( 'used_mb' => 500, 'total_mb' => 2048 ), $slot - 30 );
		GuestMemory::run( $slot + 10 );
		$this->assertSame( 1, $this->calls( '/agent/file-read' ), 'Read 40 s ago, but in the previous slot.' );
		$this->assertSame( 2, $this->rows( $r->id() ) );
	}

	public function test_the_peak_of_a_slot_is_its_highest_reading_not_the_first(): void {
		global $wpdb;
		$r    = $this->ready_vm( 5000 );
		$slot = intdiv( time(), 300 ) * 300;

		GuestMemory::store( $r->id(), array( 'used_mb' => 500, 'total_mb' => 2048 ), $slot + 5 );
		GuestMemory::store( $r->id(), array( 'used_mb' => 1500, 'total_mb' => 2048 ), $slot + 15 );
		GuestMemory::store( $r->id(), array( 'used_mb' => 700, 'total_mb' => 2048 ), $slot + 25 );

		$this->assertSame( 1, $this->rows( $r->id() ), 'Still one row per slot.' );
		$peak = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT used_max_mb FROM %i WHERE resource_id = %d', Schema::tables()['guest_memory'], $r->id() ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$this->assertSame( 1500, $peak );
	}

	public function test_a_server_with_a_row_in_the_current_slot_is_not_asked_again_by_the_job(): void {
		$r = $this->ready_vm( 5000 );
		$this->fake->set_guest_file( 5000, '/proc/meminfo', self::MEMINFO );
		GuestMemory::store( $r->id(), array( 'used_mb' => 500, 'total_mb' => 2048 ) );
		GuestMemory::run();
		$this->assertSame( 0, $this->calls( '/agent/file-read' ) );
	}

	public function test_a_failed_read_is_marked_and_asked_again_next_round(): void {
		$r = $this->ready_vm( 5000 );
		GuestMemory::run();
		$view = GuestMemory::view( $r->id() );
		$this->assertSame( 'no_agent', $view['state'] );
		$this->assertSame( 0, $this->rows( $r->id() ) );

		$this->fake->set_guest_file( 5000, '/proc/meminfo', self::MEMINFO );
		GuestMemory::run();
		$this->assertSame( 2, $this->calls( '/agent/file-read' ), 'The next round asks again.' );
		$this->assertSame( 'ok', GuestMemory::view( $r->id() )['state'] );
	}

	public function test_servers_without_the_agent_do_not_stop_the_round_for_the_others_on_their_node(): void {
		// Four servers without the guest agent (never read, so first in the queue), then one with it, all on node-a.
		foreach ( array( 5000, 5001, 5002, 5003 ) as $vmid ) {
			$this->ready_vm( $vmid );
		}
		$with = $this->ready_vm( 5004 );
		$this->fake->set_guest_file( 5004, '/proc/meminfo', self::MEMINFO );

		GuestMemory::run();

		$this->assertSame( 'ok', GuestMemory::view( $with->id() )['state'], 'A missing agent is no node failure.' );
		$this->assertSame( 1, $this->rows( $with->id() ) );
	}

	public function test_the_live_route_keeps_a_reading_ten_seconds_and_asks_only_for_the_servers_on_screen(): void {
		$this->assertSame( 10, LiveUsage::MEM_TTL );
		$this->assertSame( 10, LiveUsage::INTERVAL );
		$this->assertSame( 60, LiveUsage::SLOW_INTERVAL );
		$first  = $this->ready_vm( 5000 );
		$second = $this->ready_vm( 5001 );
		$this->fake->set_guest_file( 5000, '/proc/meminfo', self::MEMINFO );
		$this->fake->set_guest_file( 5001, '/proc/meminfo', self::MEMINFO );
		$first->set( array( 'disk_used' => 1, 'disk_total' => 2, 'disk_read_at' => time() ) );
		wp_set_current_user( (int) $first->license()['user_id'] );

		$this->route();
		$this->route();
		$this->assertSame( 1, $this->calls( '/agent/file-read' ), 'Two page loads within ten seconds share one reading.' );
		$timeout = (int) get_option( '_transient_timeout_' . LiveUsage::MEM_PREFIX . $first->id() );
		$this->assertLessThanOrEqual( time() + 10, $timeout );
		$this->assertGreaterThan( time() + 5, $timeout );

		delete_transient( LiveUsage::MEM_PREFIX . $first->id() );
		$request = new \WP_REST_Request( 'GET', '/beltoft-services/v1/usage' );
		$request->set_param( 'ids', (string) $first->id() );
		$data = (array) rest_do_request( $request )->get_data()['resources'];
		$this->assertArrayHasKey( $first->id(), $data );
		$this->assertArrayNotHasKey( $second->id(), $data );
		$this->assertSame( 2, $this->calls( '/agent/file-read' ) );
		$this->assertSame( 'ok', $data[ $first->id() ]['memory']['state'] );
		$this->assertSame( 1024, $data[ $first->id() ]['memory']['used_mb'] );
		$this->assertSame( 2048, $data[ $first->id() ]['memory']['total_mb'] );
	}

	public function test_the_first_render_shows_the_stored_reading_then_dots_then_the_hint_and_stopped(): void {
		$r = $this->ready_vm( 5000 );
		set_transient( Operations::STATUS_PREFIX . $r->id(), array( 'power' => 'running', 'cpu' => 0.1, 'mem' => 0 ), HOUR_IN_SECONDS );
		$bar = static function ( Resource $r ): array {
			$bars = array_column( Servers::rows( array( View::license( $r->license() ) ) )[0]['bars'], null, 'key' );
			return $bars['mem'] ?? array();
		};

		$none = $bar( $r );
		$this->assertSame( 'waiting', $none['state'] );
		$this->assertSame( "\u{2026}", $none['text'] );
		$this->assertSame( '', $none['sub'] );

		GuestMemory::failed( $r->id() );
		$failed = $bar( $r );
		$this->assertSame( 'no_agent', $failed['state'] );
		$this->assertSame( "\u{2013}", $failed['text'] );
		$this->assertSame( 'Memory needs the guest agent (qemu-guest-agent) running in your server.', $failed['sub'] );

		GuestMemory::store( $r->id(), array( 'used_mb' => 410, 'total_mb' => 1946 ) );
		$ok = $bar( $r );
		$this->assertSame( 'ok', $ok['state'] );
		$this->assertSame( 21, $ok['percent'] );
		$this->assertSame( '', $ok['sub'], 'The list shows the percent only, no GB line.' );
		$this->assertSame( 'Memory', $ok['title'] );

		set_transient( Operations::STATUS_PREFIX . $r->id(), array( 'power' => 'stopped', 'cpu' => 0.0, 'mem' => 0 ), HOUR_IN_SECONDS );
		$rows = Servers::rows( array( View::license( $r->license() ) ) );
		$this->assertSame( 'Stopped', $rows[0]['state'] );
		$this->assertSame( array(), $rows[0]['bars'], 'A stopped server has no bar.' );
	}

	public function test_a_reading_older_than_fifteen_minutes_is_none_and_the_table_is_the_fallback(): void {
		$r   = $this->ready_vm( 5000 );
		$now = 1800000000;
		$this->insert( $r->id(), GuestMemory::SPAN_RAW, $now - 600, 700, 1000 );
		$this->assertSame( 70, GuestMemory::latest( $r->id(), $now )['percent'], 'Without the transient the newest row is used.' );
		$this->assertNull( GuestMemory::latest( $r->id(), $now + 1200 ), 'Older than 15 minutes counts as none.' );
	}

	public function test_hourly_rollup_keeps_average_and_peak_and_the_retention_is_two_days_and_thirty_days(): void {
		$r    = $this->ready_vm( 5000 );
		$id   = $r->id();
		$now  = 1810001234; // Not on the hour.
		$hour = intdiv( $now - 3 * DAY_IN_SECONDS, 3600 ) * 3600;
		// Three days ago: two readings in one hour (used 400 and 800 of 1000).
		$this->insert( $id, 300, $hour + 300, 400, 1000 );
		$this->insert( $id, 300, $hour + 900, 800, 1000 );
		// One day ago: stays a 5-minute row.
		$recent = intdiv( $now - DAY_IN_SECONDS, 300 ) * 300;
		$this->insert( $id, 300, $recent, 500, 1000 );
		// An hourly row 31 days old goes, one 10 days old stays.
		$this->insert( $id, 3600, intdiv( $now - 31 * DAY_IN_SECONDS, 3600 ) * 3600, 100, 1000 );
		$this->insert( $id, 3600, intdiv( $now - 10 * DAY_IN_SECONDS, 3600 ) * 3600, 200, 1000 );

		GuestMemory::cleanup( $now );

		$this->assertSame( 1, $this->rows( $id, 300 ), 'Only the 5-minute row of the last two days is left.' );
		$this->assertSame( 2, $this->rows( $id, 3600 ), 'The rolled up hour and the ten day old hour.' );
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Test read.
		$rolled = $wpdb->get_row( $wpdb->prepare( 'SELECT used_mb, total_mb, used_max_mb FROM %i WHERE resource_id = %d AND span = 3600 AND bucket = %d', Schema::tables()['guest_memory'], $id, $hour ), ARRAY_A );
		$this->assertSame( array( 'used_mb' => '600', 'total_mb' => '1000', 'used_max_mb' => '800' ), $rolled, 'Average 600, peak 800.' );

		// Running it again changes nothing.
		GuestMemory::cleanup( $now );
		$this->assertSame( 2, $this->rows( $id, 3600 ) );

		// The month view gives the hour its peak.
		$series = GuestMemory::series( $id, 'month', $now );
		$points = array_column( $series['points'], null, 't' );
		$this->assertSame( 60, $points[ $hour ]['percent'] );
		$this->assertSame( 80, $points[ $hour ]['peak_percent'] );
		$this->assertNull( $points[ $hour + 3600 ]['percent'], 'A slot without a reading is a gap.' );
		$this->assertSame( 3600, $series['step'] );
	}

	public function test_a_whole_hour_is_rolled_up_only_when_it_is_older_than_two_days(): void {
		$r   = $this->ready_vm( 5000 );
		$now = 1810001234;
		$cut = intdiv( $now - 2 * DAY_IN_SECONDS, 3600 ) * 3600;
		$this->insert( $r->id(), 300, $cut - 300, 300, 1000 );
		$this->insert( $r->id(), 300, $cut + 300, 900, 1000 );
		GuestMemory::cleanup( $now );
		$this->assertSame( 1, $this->rows( $r->id(), 300 ), 'The hour that starts at the cut stays as 5-minute points.' );
		$this->assertSame( 1, $this->rows( $r->id(), 3600 ) );
	}

	public function test_deleting_a_server_removes_its_rows_and_the_daily_job_removes_orphans(): void {
		$one = $this->ready_vm( 5000 );
		$two = $this->ready_vm( 5001 );
		GuestMemory::store( $one->id(), array( 'used_mb' => 100, 'total_mb' => 1000 ) );
		GuestMemory::store( $two->id(), array( 'used_mb' => 100, 'total_mb' => 1000 ) );
		$this->assertSame( 1, $this->rows( $one->id() ) );

		VmRecords::remove( $one->id() );
		$this->assertSame( 0, $this->rows( $one->id() ), 'The rows go with the server.' );
		$this->assertNull( GuestMemory::latest( $one->id() ) );
		$this->assertSame( 1, $this->rows( $two->id() ) );

		// A row left behind (no record) is removed by the daily job.
		$this->insert( 999999, 300, 1800000000, 1, 2 );
		GuestMemory::cleanup( time() );
		$this->assertSame( 0, $this->rows( 999999 ) );
		$this->assertSame( 1, $this->rows( $two->id() ) );
	}

	public function test_the_metrics_route_returns_the_memory_series_from_our_table_never_from_proxmox(): void {
		$r = $this->ready_vm( 5000 );
		wp_set_current_user( (int) $r->license()['user_id'] );
		$this->fake->set_rrd( 5000, array( array( 'time' => 100, 'cpu' => 0.25, 'mem' => 3221225472, 'maxmem' => 4294967296 ) ) );
		$base = intdiv( time(), 300 ) * 300;
		$this->insert( $r->id(), 300, $base - 300, 512, 2048 );
		$this->insert( $r->id(), 300, $base, 1024, 2048 );

		$request = new \WP_REST_Request( 'GET', '/beltoft-services/v1/resources/' . $r->id() . '/metrics' );
		$request->set_param( 'id', $r->id() );
		$request->set_param( 'range', 'hour' );
		$data = MetricsRoute::get( $request )->get_data();

		$this->assertSame( 300, $data['memory']['step'] );
		$points = array_column( $data['memory']['points'], null, 't' );
		$this->assertSame( 25, $points[ $base - 300 ]['percent'] );
		$this->assertSame( 50, $points[ $base ]['percent'] );
		$this->assertSame( 1024, $points[ $base ]['used_mb'] );
		$this->assertSame( 2048, $points[ $base ]['total_mb'] );
		$this->assertCount( 12, $data['memory']['points'] );
		$this->assertArrayNotHasKey( 'mem', $data['points'][0], 'No Proxmox memory in the CPU series.' );
		$this->assertStringNotContainsString( '3221225472', (string) wp_json_encode( $data ), 'The Proxmox figure is not in the reply.' );
		$this->assertStringNotContainsString( '4294967296', (string) wp_json_encode( $data ) );
	}

	public function test_mcp_server_usage_gives_memory_from_our_table(): void {
		$r = $this->ready_vm( 5000 );
		wp_set_current_user( (int) $r->license()['user_id'] );
		$this->fake->set_rrd( 5000, array( array( 'time' => 100, 'cpu' => 0.25, 'mem' => 3221225472 ) ) );
		$base = intdiv( time(), 300 ) * 300;
		$this->insert( $r->id(), 300, $base - 300, 1000, 2000 );
		$this->insert( $r->id(), 300, $base, 1500, 2000 );

		$running = new \ReflectionProperty( Tools::class, 'running' );
		$running->setAccessible( true );
		$running->setValue( null, 'server_usage' );
		$method = new \ReflectionMethod( \BeltoftServices\Proxmox\Vm\McpTools::class, 'server_usage' );
		$method->setAccessible( true );
		$out = $method->invoke( null, $r->id(), 'hour' );
		$running->setValue( null, '' );

		$this->assertIsArray( $out );
		$this->assertSame( 62.5, $out['mem_percent']['average'] );
		$this->assertSame( 75.0, $out['mem_percent']['latest'] );
		$this->assertSame( 75.0, $out['mem_percent']['highest'] );
		$this->assertArrayHasKey( 'mem_percent', $out['units'] );
		$this->assertStringNotContainsString( '3221225472', (string) wp_json_encode( $out ) );
	}

	public function test_the_agent_is_asked_for_one_fixed_path_only_after_the_tag_check(): void {
		$r = $this->ready_vm( 5000 );
		$this->fake->set_guest_file( 5000, '/proc/meminfo', self::MEMINFO );
		GuestMemory::run();
		$reads = array_values( array_filter( $this->fake->calls(), static fn( $c ) => str_ends_with( $c['path'], '/agent/file-read' ) ) );
		$this->assertCount( 1, $reads );
		$this->assertSame( '/proc/meminfo', $reads[0]['params']['file'] ?? '' );
		$this->assertNotNull( Resource::find( $r->id() ) );
		$this->assertNotSame( '', Ownership::tag( $r ) );
	}
}

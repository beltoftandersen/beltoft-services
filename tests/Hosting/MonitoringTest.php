<?php
/**
 * Monitoring of a hosting account: the same shared code as a server (Monitoring folder) through the hosting adapter.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Hosting;

require_once __DIR__ . '/HostingCase.php';

use BeltoftServices\Hosting\HostingGuest;
use BeltoftServices\Hosting\HostingUsage;
use BeltoftServices\Hosting\Records;
use BeltoftServices\Monitoring\GuestMemory;
use BeltoftServices\Monitoring\Guests;
use BeltoftServices\Monitoring\LiveUsage;
use BeltoftServices\Monitoring\MetricsRoute;
use BeltoftServices\Monitoring\UsageRules;
use BeltoftServices\Resource;

/**
 * HostingGuest, GuestMemory, LiveUsage, MetricsRoute and UsageRules for a container.
 */
class MonitoringTest extends HostingCase {

	private const GB = 1073741824;

	/**
	 * Mails sent.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private $mails = array();

	public function set_up(): void {
		parent::set_up();
		$this->mails = array();
		add_filter( 'pre_wp_mail', function ( $pre, $args ) {
			$this->mails[] = $args;

			return true;
		}, 10, 2 );
		$this->settings( array( 'monitor_alerts' => 'on', 'monitor_mem_percent' => 90, 'monitor_mem_minutes' => 15, 'monitor_cooldown_days' => 7 ) );
		$this->clear_history();
	}

	public function tear_down(): void {
		remove_all_filters( 'pre_wp_mail' );
		$this->clear_history();
		parent::tear_down();
	}

	private function clear_history(): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- test fixture reset.
		$wpdb->query( 'DELETE FROM ' . $wpdb->prefix . 'bs_guest_memory' );
	}

	/**
	 * A ready, running account whose container reports 25 percent CPU.
	 */
	private function account(): Resource {
		$r = $this->resource( array( 'disk_gb' => 10, 'memory_mb' => 2048 ) );
		$this->provision_fully( $r );
		$r->set( array( 'state' => 'ready', 'desired' => 'ready' ) );
		$vmid = (int) Records::for_resource( $r->id() )['vmid'];
		$this->node->set_usage( $vmid, array( 'cpu' => 0.25, 'uptime' => 7200 ) );
		delete_transient( HostingGuest::ROWS_PREFIX . 'n1' );

		return Resource::find( $r->id() );
	}

	private function vmid( Resource $r ): int {
		return (int) Records::for_resource( $r->id() )['vmid'];
	}

	private function owner( Resource $r ): void {
		wp_set_current_user( (int) $r->license()['user_id'] );
	}

	public function test_the_hosting_adapter_is_the_guest_of_a_hosting_account(): void {
		$r = $this->account();

		$this->assertInstanceOf( HostingGuest::class, Guests::for( $r ) );
	}

	public function test_the_memory_round_reads_the_agent_and_stores_it_in_the_shared_table(): void {
		$r = $this->account();
		$this->agent->set_memory( $this->vmid( $r ), 700, 2048 );
		$before = count( $this->node->calls() );

		GuestMemory::run();

		$latest = GuestMemory::latest( $r->id() );
		$this->assertSame( 700, $latest['used_mb'] );
		$this->assertSame( 2048, $latest['total_mb'] );
		$this->assertSame( 34, $latest['percent'] );
		$this->assertCount( 1, $this->agent->calls_of( 'ct.memory' ) );
		$this->assertSame( array(), $this->agent->calls_of( 'ct.stats' ), 'The cache-inclusive figure of ct.stats is never used.' );
		$this->assertSame( array(), $this->node->calls_to( '#/status/current$#', 'GET' ), 'No Proxmox memory figure is read.' );
		$this->assertSame( array(), $this->writes_since( $before ), 'Reads only.' );
	}

	/**
	 * The calls to the node after a count that were not reads.
	 *
	 * @param int $before Calls made before.
	 * @return array<int, array<string, mixed>>
	 */
	private function writes_since( int $before ): array {
		return array_values( array_filter( array_slice( $this->node->calls(), $before ), static fn( $c ) => 'GET' !== $c['method'] ) );
	}

	public function test_a_container_without_our_tag_is_not_read(): void {
		$r    = $this->account();
		$vmid = $this->vmid( $r );
		$this->node->request( 'PUT', '/nodes/pve1/lxc/' . $vmid . '/config', array( 'description' => 'somebody else' ) );
		$this->agent->set_memory( $vmid, 700, 2048 );

		GuestMemory::run();

		$this->assertNull( GuestMemory::latest( $r->id() ) );
		$this->assertSame( array(), $this->agent->calls_of( 'ct.memory' ) );
		$this->assertSame( 'no_agent', GuestMemory::view( $r->id() )['state'] );
	}

	public function test_a_stopped_container_is_not_read(): void {
		$r = $this->account();
		$this->node->request( 'POST', '/nodes/pve1/lxc/' . $this->vmid( $r ) . '/status/stop' );

		GuestMemory::run();

		$this->assertSame( array(), $this->agent->calls_of( 'ct.memory' ) );
	}

	public function test_a_failing_agent_marks_the_account_and_keeps_going(): void {
		$r = $this->account();
		$this->agent->fail_next( 'ct.memory', 'busy' );

		GuestMemory::run();

		$this->assertNull( GuestMemory::latest( $r->id() ) );
		$this->assertSame( 'no_agent', GuestMemory::view( $r->id() )['state'] );
	}

	public function test_the_daily_cleanup_keeps_hosting_rows_and_removes_orphans(): void {
		$r   = $this->account();
		$now = time();
		GuestMemory::store( $r->id(), array( 'used_mb' => 100, 'total_mb' => 1000 ), $now );
		GuestMemory::store( 999999, array( 'used_mb' => 100, 'total_mb' => 1000 ), $now );

		GuestMemory::cleanup( $now );

		$series = GuestMemory::series( $r->id(), 'hour', $now );
		$this->assertNotEmpty( array_filter( $series['points'], static fn( $p ) => null !== $p['used_mb'] ) );
		$this->assertNotEmpty( array_filter( GuestMemory::series( $r->id(), 'day', $now )['points'], static fn( $p ) => null !== $p['used_mb'] ) );
		$orphan = array_filter( GuestMemory::series( 999999, 'day', $now )['points'], static fn( $p ) => null !== $p['used_mb'] );
		$this->assertSame( array(), $orphan, 'A resource no record table knows loses its rows.' );
	}

	public function test_removing_the_record_removes_the_history(): void {
		$r = $this->account();
		GuestMemory::store( $r->id(), array( 'used_mb' => 100, 'total_mb' => 1000 ) );

		Records::remove( $r->id() );

		$this->assertNull( GuestMemory::latest( $r->id() ) );
	}

	public function test_the_live_route_gives_power_cpu_memory_and_storage(): void {
		$r = $this->account();
		$r->set( array( 'usage' => array( 'disk_mb' => 3072, 'db_mb' => 1024, 'status' => 'running', 'at' => time() ) ) );
		$this->agent->set_memory( $this->vmid( $r ), 1024, 2048 );

		$data  = LiveUsage::for_user( (int) $r->license()['user_id'], array( $r->id() ) );
		$entry = $data[ $r->id() ];

		$this->assertSame( 'running', $entry['power'] );
		$this->assertSame( 25, $entry['cpu'] );
		$this->assertSame( 50, $entry['mem'] );
		$this->assertSame( 40, $entry['disk'], 'Files plus databases over the plan storage.' );
		$this->assertSame( 'ok', $entry['memory']['state'] );
		$this->assertSame( 50, $entry['memory']['percent'] );

		LiveUsage::for_user( (int) $r->license()['user_id'], array( $r->id() ) );
		$this->assertCount( 1, $this->agent->calls_of( 'ct.memory' ), 'The memory is read once in ten seconds, however many tabs.' );
		$this->assertSame( array(), LiveUsage::for_user( self::factory()->user->create(), array( $r->id() ) ), 'Another user gets nothing.' );
	}

	public function test_the_charts_route_gives_cpu_network_memory_and_storage_for_the_owner_only(): void {
		$r = $this->account();
		$r->set( array( 'usage' => array( 'disk_mb' => 1024, 'db_mb' => 0, 'status' => 'running', 'at' => time() ) ) );
		$this->node->set_rrd( $this->vmid( $r ), array( array( 'time' => 100, 'cpu' => 0.5, 'netin' => 2048, 'netout' => 1024, 'diskread' => 0, 'diskwrite' => 4096 ) ) );
		GuestMemory::store( $r->id(), array( 'used_mb' => 512, 'total_mb' => 1024 ) );

		$this->owner( $r );
		$before  = count( $this->node->calls() );
		$request = new \WP_REST_Request( 'GET', '/beltoft-services/v1/resources/' . $r->id() . '/metrics' );
		$request->set_param( 'id', $r->id() );
		$request->set_param( 'range', 'hour' );
		$response = MetricsRoute::get( $request );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 50.0, $data['points'][0]['cpu'] );
		$this->assertSame( 2048.0, $data['points'][0]['netin'] );
		$this->assertSame( 1024 * 1048576, $data['disk']['used'] );
		$this->assertSame( 10 * self::GB, $data['disk']['total'] );
		$this->assertTrue( $data['available'] );
		$this->assertNotEmpty( array_filter( $data['memory']['points'], static fn( $p ) => 50 === $p['percent'] ) );
		$this->assertSame( array(), $this->writes_since( $before ), 'Reads only.' );

		wp_set_current_user( self::factory()->user->create() );
		$this->assertSame( 403, MetricsRoute::get( $request )->get_status() );
	}

	public function test_a_container_that_is_not_ours_gives_no_series(): void {
		$r = $this->account();
		$this->node->request( 'PUT', '/nodes/pve1/lxc/' . $this->vmid( $r ) . '/config', array( 'description' => 'somebody else' ) );
		$this->node->set_rrd( $this->vmid( $r ), array( array( 'time' => 100, 'cpu' => 0.5 ) ) );

		$this->owner( $r );
		$request = new \WP_REST_Request( 'GET', '/beltoft-services/v1/resources/' . $r->id() . '/metrics' );
		$request->set_param( 'id', $r->id() );
		$request->set_param( 'range', 'hour' );

		$this->assertFalse( MetricsRoute::get( $request )->get_data()['available'] );
	}

	public function test_the_usage_email_switch_answers_for_hosting(): void {
		$r = $this->account();
		$this->owner( $r );
		$request = new \WP_REST_Request( 'POST', '/beltoft-services/v1/resources/' . $r->id() . '/usage-alerts' );
		$request->set_param( 'id', $r->id() );
		$request->set_param( 'on', false );

		$response = MetricsRoute::switch_alerts( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertFalse( UsageRules::wanted( Resource::find( $r->id() ) ) );
	}

	/**
	 * Three 5-minute readings at 95 percent, the newest in the current slot.
	 *
	 * @param Resource $r    Resource.
	 * @param int      $base Slot start.
	 */
	private function high_memory( Resource $r, int $base ): void {
		foreach ( array( 2, 1, 0 ) as $back ) {
			GuestMemory::store( $r->id(), array( 'used_mb' => 1945, 'total_mb' => 2048 ), $base - $back * 300 + 10 );
		}
	}

	public function test_sustained_high_memory_mails_the_owner_once_in_hosting_words(): void {
		$r    = $this->account();
		$base = intdiv( time(), 300 ) * 300;
		$this->high_memory( $r, $base );

		UsageRules::memory( Resource::find( $r->id() ), 7200, $base + 20 );
		UsageRules::memory( Resource::find( $r->id() ), 7200, $base + 40 );

		$this->assertCount( 1, $this->mails );
		$this->assertStringContainsString( 'hosting', strtolower( (string) $this->mails[0]['subject'] ) );
		$this->assertStringNotContainsString( 'server', strtolower( (string) $this->mails[0]['subject'] ) );
		$this->assertStringNotContainsString( 'top or htop', (string) $this->mails[0]['message'] );
		$this->assertStringNotContainsString( '—', (string) $this->mails[0]['message'] );
	}

	public function test_no_memory_email_in_the_first_quarter_hour_after_a_start(): void {
		$r    = $this->account();
		$base = intdiv( time(), 300 ) * 300;
		$this->high_memory( $r, $base );

		UsageRules::memory( Resource::find( $r->id() ), 600, $base + 20 );

		$this->assertCount( 0, $this->mails );
	}

	public function test_the_customer_switch_stops_the_memory_email(): void {
		$r = $this->account();
		$r->set( array( 'usage_alerts' => 'off' ) );
		$base = intdiv( time(), 300 ) * 300;
		$this->high_memory( $r, $base );

		UsageRules::memory( Resource::find( $r->id() ), 7200, $base + 20 );

		$this->assertCount( 0, $this->mails );
	}

	public function test_the_round_ends_in_the_memory_rule_with_the_uptime_of_the_container(): void {
		$r    = $this->account();
		$base = intdiv( time(), 300 ) * 300;

		foreach ( array( 3, 2, 1 ) as $back ) {
			GuestMemory::store( $r->id(), array( 'used_mb' => 1945, 'total_mb' => 2048 ), $base - $back * 300 + 10 );
		}

		$this->agent->set_memory( $this->vmid( $r ), 1945, 2048 );
		GuestMemory::run( $base + 20 );

		$this->assertCount( 1, $this->mails );

		$this->clear_history();
		$this->mails = array();
		$r2          = Resource::find( $r->id() );
		$r2->set( array( 'monitor_mem_notified' => null, 'monitor_mem_sent_at' => null ) );
		$this->node->set_usage( $this->vmid( $r ), array( 'cpu' => 0.25, 'uptime' => 120 ) );
		delete_transient( HostingGuest::ROWS_PREFIX . 'n1' );

		foreach ( array( 3, 2, 1 ) as $back ) {
			GuestMemory::store( $r->id(), array( 'used_mb' => 1945, 'total_mb' => 2048 ), $base - $back * 300 + 10 );
		}

		GuestMemory::run( $base + 20 );
		$this->assertCount( 0, $this->mails, 'A container that started two minutes ago is still settling.' );
	}

	public function test_the_hourly_job_feeds_the_cpu_rule_with_the_rrd_hour_and_reads_nothing_when_emails_are_off(): void {
		$r = $this->account();
		$this->node->set_usage( $this->vmid( $r ), array( 'cpu' => 0.95, 'uptime' => 7200 ) );
		$rows = array();

		for ( $m = 0; $m < 60; $m++ ) {
			$rows[] = array( 'time' => time() - ( 59 - $m ) * 60, 'cpu' => 0.95 );
		}

		$this->node->set_rrd( $this->vmid( $r ), $rows );
		( new HostingUsage( $r ) )->sample();

		$this->assertNotSame( array(), $this->node->calls_to( '#/rrddata$#', 'GET' ) );
		$this->assertNotFalse( get_transient( \BeltoftServices\Monitoring\Samples::CPU_PREFIX . $r->id() ), 'The run of high CPU is remembered by the shared rule.' );
		$this->assertCount( 0, $this->mails, 'Under three hours.' );

		$this->settings( array( 'monitor_alerts' => 'off' ) );
		$before = count( $this->node->calls_to( '#/rrddata$#', 'GET' ) );
		( new HostingUsage( Resource::find( $r->id() ) ) )->sample();
		$this->assertCount( $before, $this->node->calls_to( '#/rrddata$#', 'GET' ) );
	}

	public function test_the_storage_warning_honours_the_usage_email_switch_but_the_over_notice_does_not(): void {
		$r = $this->account();
		$r->set( array( 'usage_alerts' => 'off' ) );
		$this->node->set_usage( $this->vmid( $r ), array( 'cpu' => 0.1, 'disk' => (int) ( 9.5 * self::GB ) ) );

		( new HostingUsage( Resource::find( $r->id() ) ) )->sample();
		$this->assertCount( 0, $this->mails, 'The 90 percent warning is one of the usage emails.' );

		$this->node->set_usage( $this->vmid( $r ), array( 'cpu' => 0.1, 'disk' => (int) ( 11.5 * self::GB ) ) );
		( new HostingUsage( Resource::find( $r->id() ) ) )->sample();
		$this->assertCount( 1, $this->mails, 'Over the plan, the customer is told whatever the switch says.' );
	}

	private function page( Resource $r ): string {
		ob_start();
		\BeltoftServices\Hosting\HostingAccount::render( Resource::find( $r->id() ) );

		return (string) ob_get_clean();
	}

	public function test_the_page_has_the_same_monitoring_panel_as_a_server_and_live_bars(): void {
		$r = $this->account();
		$r->set( array( 'usage' => array( 'disk_mb' => 3072, 'db_mb' => 1024, 'status' => 'running', 'at' => time() ) ) );
		GuestMemory::store( $r->id(), array( 'used_mb' => 512, 'total_mb' => 2048 ) );

		$html = $this->page( $r );

		$this->assertStringContainsString( 'data-vm-metrics', $html );
		foreach ( array( 'data-chart="cpu"', 'data-chart="mem"', 'data-chart="net"', 'data-chart="io"', 'data-range="hour"', 'data-range="day"', 'data-range="month"', 'Memory in use (excluding cache)', 'Network and disk activity', 'Storage used: 4.0 of 10.0 GB', 'data-usage-alerts' ) as $needle ) {
			$this->assertStringContainsString( $needle, $html, $needle );
		}
		$this->assertStringContainsString( 'data-usage-resource="' . $r->id() . '"', $html );
		$this->assertStringContainsString( 'data-usage-bar="cpu"', $html );
		$this->assertStringContainsString( 'data-usage-bar="mem"', $html );
		$this->assertStringContainsString( '25%', $html, 'The newest memory reading is the first value of the memory bar.' );
		$this->assertStringNotContainsString( 'PHP workers', strtolower( substr( $html, (int) strpos( $html, 'data-vm-metrics' ), 4000 ) ), 'No PHP-workers metric.' );
		$this->assertStringNotContainsString( "\u{2014}", $html );
		$this->assertStringNotContainsString( "\u{00B7}", $html );
		$this->assertTrue( wp_script_is( 'beltoft-services-metrics', 'enqueued' ) );
		$this->assertTrue( wp_script_is( 'beltoft-services-usage-live', 'enqueued' ) );
	}

	public function test_the_switch_is_hidden_when_the_site_has_the_usage_emails_off(): void {
		$r = $this->account();
		$this->settings( array( 'monitor_alerts' => 'off' ) );

		$this->assertStringNotContainsString( 'data-usage-alerts', $this->page( $r ) );
	}

	public function test_a_hosting_account_that_is_not_ready_shows_no_monitoring(): void {
		$r = $this->resource();

		$this->assertStringNotContainsString( 'data-vm-metrics', $this->page( $r ) );
	}

	public function test_the_hosting_usage_route_gives_the_memory_in_use_without_the_cache(): void {
		$r = $this->account();
		GuestMemory::store( $r->id(), array( 'used_mb' => 512, 'total_mb' => 2048 ) );
		$this->owner( $r );
		$request = new \WP_REST_Request( 'GET', '/beltoft-services/v1/resources/' . $r->id() . '/hosting/usage' );
		$request->set_param( 'id', $r->id() );

		$data = \BeltoftServices\Hosting\HostingRoutes::usage( $request )->get_data();

		$this->assertSame( 25, $data['memory_percent'] );
		$this->assertSame( 512, $data['memory_used_mb'] );
		$this->assertSame( 2048, $data['memory_total_mb'] );
	}
}

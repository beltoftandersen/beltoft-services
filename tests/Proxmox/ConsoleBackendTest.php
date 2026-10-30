<?php
/** The console backend uses only FakeClient and an isolated WordPress test database. @package BeltoftServices */

namespace BeltoftServices\Tests\Proxmox;

use BeltoftServices\Proxmox\Console;
use BeltoftServices\Proxmox\ConsoleAuth;
use BeltoftServices\Proxmox\ConsoleRoute;
use BeltoftServices\Proxmox\ConsoleSessions;
use BeltoftServices\Proxmox\FakeClient;
use BeltoftServices\Proxmox\Clusters;
use BeltoftServices\Proxmox\Operations;
use BeltoftServices\Proxmox\VmRecords;
use BeltoftServices\Crypto;
use BeltoftServices\Resource;

require_once __DIR__ . '/MonitoringTestCase.php';

class ConsoleBackendTest extends MonitoringTestCase {
	public function set_up(): void {
		parent::set_up();
		$this->settings( array( 'console' => 1 ) );
		$_SERVER['REMOTE_ADDR'] = '192.0.2.30';
		$_SERVER['HTTP_ORIGIN'] = home_url();
		global $wp_rest_server;
		$wp_rest_server = new \WP_REST_Server();
		do_action( 'rest_api_init', $wp_rest_server );
	}

	public function tear_down(): void {
		global $wp_rest_server, $wpdb;
		$wp_rest_server = null;
		unset( $_SERVER['REMOTE_ADDR'] );
		unset( $_SERVER['HTTP_ORIGIN'] );
		wp_set_current_user( 0 );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- test-only cleanup of the one-use option namespace.
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE option_name LIKE %s', $wpdb->options, $wpdb->esc_like( ConsoleSessions::PREFIX ) . '%' ) );
		parent::tear_down();
	}

	private function server( int $vmid = 5000 ): Resource {
		return $this->ready_vm( $vmid );
	}

	private function open( Resource $r, int $user_id ): \WP_REST_Response {
		wp_set_current_user( $user_id );
		return rest_get_server()->dispatch( new \WP_REST_Request( 'POST', '/beltoft-services/v1/resources/' . $r->id() . '/console' ) );
	}

	public function test_console_route_requires_owner_ready_state_and_running_vm(): void {
		$r = $this->server();
		$owner = (int) $r->license()['user_id'];
		$this->assertSame( 401, $this->open( $r, 0 )->get_status() );
		$foreign = $this->open( $r, self::factory()->user->create() );
		$this->assertSame( 403, $foreign->get_status() );
		wp_set_current_user( $owner );
		$gone = rest_get_server()->dispatch( new \WP_REST_Request( 'POST', '/beltoft-services/v1/resources/99999999/console' ) );
		$this->assertSame( 403, $gone->get_status() );
		$this->assertSame( $foreign->get_data(), $gone->get_data() );
		$r->set( array( 'state' => 'paused' ) );
		$this->assertSame( 403, $this->open( $r, $owner )->get_status() );
		$r->set( array( 'state' => 'ready', 'breach_at' => time() ) );
		$this->assertSame( 403, $this->open( $r, $owner )->get_status() );
		$r->set( array( 'breach_at' => null, 'state' => 'deleting' ) );
		$this->assertSame( 403, $this->open( $r, $owner )->get_status() );
		$r->set( array( 'state' => 'ready' ) );
		$this->fake->request( 'POST', '/nodes/node-a/qemu/5000/status/stop' );
		$this->assertSame( 409, $this->open( $r, $owner )->get_status() );
	}

	public function test_open_returns_one_use_path_password_and_no_store_without_leaking_secrets(): void {
		$r = $this->server();
		$owner = (int) $r->license()['user_id'];
		$reply = $this->open( $r, $owner );
		$this->assertSame( 200, $reply->get_status() );
		$this->assertSame( 'no-store', $reply->get_headers()['Cache-Control'] );
		$data = $reply->get_data();
		$this->assertSame( 'demo', $data['transport'] );
		$this->assertSame( 30, $data['expires_in'] );
		$this->assertMatchesRegularExpression( '#^/beltoft-console/ws/[a-f0-9]{64}$#', $data['path'] );
		$this->assertStringStartsWith( 'fake-vnc-', $data['password'] );
		$token = basename( $data['path'] );
		$entry = ConsoleSessions::peek( $token, $owner, '192.0.2.30' );
		$this->assertIsArray( $entry );
		$this->assertSame( $r->id(), $entry['resource'] );
		$this->assertStringContainsString( ':PVEVNC:fake-', Crypto::decrypt( $entry['ticket'] ) );
		$this->assertStringNotContainsString( 'PVEVNC:', $data['password'] );
		$this->assertStringNotContainsString( $data['password'], $entry['_raw'] );
		$this->assertStringNotContainsString( $token, $entry['_raw'] );
		$this->assertStringNotContainsString( $data['password'], wp_json_encode( $this->fake->calls() ) );
		$this->assertStringNotContainsString( 'secret-secret-secret-1', wp_json_encode( $this->fake->calls() ) );
		$this->assertSame( 409, $this->open( $r, $owner )->get_status(), 'Only one pending ticket per server.' );
	}

	public function test_open_refuses_an_upstream_reply_without_a_separate_password(): void {
		$r = $this->server();
		$without_password = new class( $this->fake ) extends FakeClient {
			private FakeClient $source;
			public function __construct( FakeClient $source ) {
				$this->source = $source;
			}
			public function request( string $method, string $path, array $params = array() ) {
				$reply = $this->source->request( $method, $path, $params );
				if ( str_ends_with( $path, '/vncproxy' ) && is_array( $reply ) ) {
					unset( $reply['password'] );
				}
				return $reply;
			}
		};
		add_filter( 'beltoft_services_proxmox_client', static fn() => $without_password, 20 );
		try {
			$reply = $this->open( $r, (int) $r->license()['user_id'] );
			$this->assertSame( 503, $reply->get_status() );
			$this->assertStringNotContainsString( 'PVEVNC:', wp_json_encode( $reply->get_data() ) );
		} finally {
			remove_all_filters( 'beltoft_services_proxmox_client', 20 );
		}
	}

	public function test_ticket_checks_user_ip_expiry_and_atomic_single_use(): void {
		$r = $this->server();
		$owner = (int) $r->license()['user_id'];
		$token = ConsoleSessions::create( $owner, $r->id(), '192.0.2.30', 'lab', 'node-a', 5000, 5900, 'PVEVNC:private' );
		$this->assertMatchesRegularExpression( '/^[a-f0-9]{64}$/', $token );
		$this->assertNull( ConsoleSessions::peek( $token, $owner + 1, '192.0.2.30' ) );
		$this->assertNull( ConsoleSessions::peek( $token, $owner, '192.0.2.31' ) );
		$entry = ConsoleSessions::peek( $token, $owner, '192.0.2.30' );
		$this->assertSame( 'PVEVNC:private', ConsoleSessions::consume( $entry ) );
		$this->assertNull( ConsoleSessions::consume( $entry ), 'The same stale read cannot consume twice.' );
		$this->assertNull( ConsoleSessions::peek( $token, $owner, '192.0.2.30' ) );
		$other = ConsoleSessions::create( $owner, $r->id(), '192.0.2.30', 'lab', 'node-a', 5000, 5900, 'PVEVNC:expiring' );
		$stale = ConsoleSessions::peek( $other, $owner, '192.0.2.30' );
		$raw = json_decode( $stale['_raw'], true );
		$raw['created'] = time() - 31;
		update_option( $stale['_name'], wp_json_encode( $raw ), false );
		$this->assertNull( ConsoleSessions::peek( $other, $owner, '192.0.2.30' ) );
		$this->assertSame( 1, ConsoleSessions::purge() );
	}

	public function test_auth_refuses_bad_marker_and_changed_state_then_consumes_once(): void {
		$r = $this->server();
		$owner = (int) $r->license()['user_id'];
		$this->assertFalse( ConsoleAuth::internal( '' ) );
		$this->assertFalse( ConsoleAuth::internal( 'made-up' ) );
		$reply = $this->open( $r, $owner )->get_data();
		$token = basename( $reply['path'] );
		$r->set( array( 'state' => 'paused' ) );
		$this->assertWPError( ConsoleAuth::authorize( $token, $owner, '192.0.2.30' ) );
		$this->assertNotNull( ConsoleSessions::peek( $token, $owner, '192.0.2.30' ), 'Denied authorization keeps the ticket.' );
		$r->set( array( 'state' => 'ready', 'breach_at' => time() ) );
		$this->assertWPError( ConsoleAuth::authorize( $token, $owner, '192.0.2.30' ), 'A new breach hold blocks connection.' );
		$this->assertNotNull( ConsoleSessions::peek( $token, $owner, '192.0.2.30' ) );
		$r->set( array( 'state' => 'ready', 'breach_at' => null ) );
		$headers = ConsoleAuth::authorize( $token, $owner, '192.0.2.30' );
		$this->assertIsArray( $headers );
		$this->assertSame( 'pve.example.invalid:8006', $headers['X-Console-Target'] );
		$this->assertStringContainsString( 'nodes/node-a/qemu/5000/vncwebsocket?port=5900&vncticket=', $headers['X-Console-Uri'] );
		$this->assertSame( '', $headers['X-Console-Auth'], 'A fake cluster has no proxy credential.' );
		$this->assertSame( (string) $r->id(), $headers['X-Console-Resource'] );
		$this->assertSame( (string) $owner, $headers['X-Console-User'] );
		$this->assertWPError( ConsoleAuth::authorize( $token, $owner, '192.0.2.30' ) );
	}

	public function test_auth_rejects_missing_or_foreign_origin_without_consuming_ticket(): void {
		$r = $this->server();
		$owner = (int) $r->license()['user_id'];
		$token = basename( $this->open( $r, $owner )->get_data()['path'] );
		$_SERVER['HTTP_ORIGIN'] = '';
		$this->assertWPError( ConsoleAuth::authorize( $token, $owner, '192.0.2.30' ) );
		$_SERVER['HTTP_ORIGIN'] = 'https://elsewhere.example';
		$this->assertWPError( ConsoleAuth::authorize( $token, $owner, '192.0.2.30' ) );
		$_SERVER['HTTP_ORIGIN'] = home_url() . '/path';
		$this->assertWPError( ConsoleAuth::authorize( $token, $owner, '192.0.2.30' ) );
		$this->assertNotNull( ConsoleSessions::peek( $token, $owner, '192.0.2.30' ) );
		$_SERVER['HTTP_ORIGIN'] = home_url();
		$this->assertIsArray( ConsoleAuth::authorize( $token, $owner, '192.0.2.30' ) );
	}

	public function test_state_change_purges_pending_ticket_and_feature_is_off_by_default(): void {
		$r = $this->server();
		$owner = (int) $r->license()['user_id'];
		Operations::remember_power( $r->id(), 'running' );
		$this->assertTrue( Console::available( $r ) );
		$reply = $this->open( $r, $owner )->get_data();
		$token = basename( $reply['path'] );
		ConsoleSessions::state_changed( $r, 'ready', 'paused' );
		$this->assertNull( ConsoleSessions::peek( $token, $owner, '192.0.2.30' ) );
		$second = ConsoleSessions::create( $owner, $r->id(), '192.0.2.30', 'lab', 'node-a', 5000, 5900, 'PVEVNC:delete' );
		ConsoleSessions::state_changed( $r, 'ready', 'deleting' );
		$this->assertNull( ConsoleSessions::peek( $second, $owner, '192.0.2.30' ) );
		$this->settings( array( 'console' => 0 ) );
		$this->assertFalse( Console::available( $r ) );
		$this->assertSame( 403, $this->open( $r, $owner )->get_status() );
	}

	public function test_fake_client_validates_console_parameters_and_refuses_stopped_vm(): void {
		$this->server();
		$path = '/nodes/node-a/qemu/5000/vncproxy';
		$this->assertWPError( $this->fake->request( 'POST', $path, array() ) );
		$this->assertIsArray( $this->fake->request( 'POST', $path, array( 'websocket' => 1, 'generate-password' => 1 ) ) );
		$this->fake->request( 'POST', '/nodes/node-a/qemu/5000/status/stop' );
		$this->assertWPError( $this->fake->request( 'POST', $path, array( 'websocket' => 1, 'generate-password' => 1 ) ) );
	}

	public function test_missing_ownership_tag_and_out_of_range_vm_are_refused(): void {
		$r = $this->server();
		$owner = (int) $r->license()['user_id'];
		$this->fake->add_vm( array( 'vmid' => 5000, 'node' => 'node-a', 'status' => 'running', 'config' => array( 'description' => 'another tenant' ) ) );
		$this->assertSame( 503, $this->open( $r, $owner )->get_status() );
		$this->assertSame( array(), array_filter( $this->fake->calls(), static fn( $call ) => str_contains( $call['path'], 'vncproxy' ) ) );
		$outside = $this->server( 5100 );
		$this->assertSame( 503, $this->open( $outside, (int) $outside->license()['user_id'] )->get_status() );
	}

	public function test_route_rate_limits_and_floor_cannot_be_disabled_by_filter(): void {
		$r = $this->server();
		$owner = (int) $r->license()['user_id'];
		for ( $i = 0; $i < ConsoleRoute::RATE_LIMIT; $i++ ) {
			$this->assertNotSame( 429, $this->open( $r, $owner )->get_status() );
		}
		$this->assertSame( 429, $this->open( $r, $owner )->get_status() );
		add_filter( 'beltoft_services_rest_rate_limit', '__return_zero' );
		try {
			$next = $this->server( 5001 );
			$user = (int) $next->license()['user_id'];
			for ( $i = 0; $i < ConsoleRoute::FLOOR_LIMIT; $i++ ) {
				$this->assertNotSame( 429, $this->open( $next, $user )->get_status() );
			}
			$this->assertSame( 429, $this->open( $next, $user )->get_status() );
		} finally {
			remove_filter( 'beltoft_services_rest_rate_limit', '__return_zero' );
		}
	}

	public function test_pending_ticket_limits_are_atomic_for_one_server_and_two_per_user(): void {
		$first = ConsoleSessions::create( 47, 81, '192.0.2.30', 'lab', 'node-a', 5000, 5900, 'first' );
		$this->assertIsString( $first );
		$this->assertWPError( ConsoleSessions::create( 47, 81, '192.0.2.30', 'lab', 'node-a', 5000, 5900, 'repeat' ) );
		$this->assertIsString( ConsoleSessions::create( 47, 82, '192.0.2.30', 'lab', 'node-a', 5001, 5900, 'second' ) );
		$this->assertWPError( ConsoleSessions::create( 47, 83, '192.0.2.30', 'lab', 'node-a', 5002, 5900, 'third' ) );
	}

	public function test_auth_keeps_ticket_when_cluster_target_is_unavailable(): void {
		$r = $this->server();
		$owner = (int) $r->license()['user_id'];
		$token = basename( $this->open( $r, $owner )->get_data()['path'] );
		$cluster = Clusters::all()['lab'];
		$this->assertIsArray( $cluster );
		$cluster['api_url'] = 'https://bad.example.invalid/path';
		update_option( Clusters::OPTION, array( 'lab' => $cluster ) );
		$auth = ConsoleAuth::authorize( $token, $owner, '192.0.2.30' );
		$this->assertWPError( $auth );
		$this->assertSame( 503, $auth->get_error_data()['status'] );
		$this->assertNotNull( ConsoleSessions::peek( $token, $owner, '192.0.2.30' ) );
	}

	public function test_open_failure_does_not_log_or_return_console_secrets(): void {
		$r = $this->server();
		$owner = (int) $r->license()['user_id'];
		$log = tempnam( sys_get_temp_dir(), 'console-log-' );
		$previous = ini_set( 'error_log', $log );
		try {
			$data = $this->open( $r, $owner )->get_data();
			$token = basename( $data['path'] );
			$entry = ConsoleSessions::peek( $token, $owner, '192.0.2.30' );
			$ticket = Crypto::decrypt( $entry['ticket'] );
			$refused = $this->open( $r, $owner );
			$this->assertSame( 409, $refused->get_status() );
			$visible = wp_json_encode( $refused->get_data() ) . (string) file_get_contents( $log ) . wp_json_encode( $this->fake->calls() );
			foreach ( array( $token, $ticket, $data['password'] ) as $secret ) {
				$this->assertStringNotContainsString( $secret, $visible );
				$this->assertStringNotContainsString( $secret, $entry['_raw'] );
			}
		} finally {
			ini_set( 'error_log', $previous );
			unlink( $log );
		}
	}
}

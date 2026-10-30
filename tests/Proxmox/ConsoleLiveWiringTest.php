<?php
/** Live wiring is tested in a separate process with every HTTP call intercepted. @package BeltoftServices */

namespace BeltoftServices\Tests\Proxmox;

use BeltoftServices\Proxmox\Clients;
use BeltoftServices\Proxmox\Clusters;
use BeltoftServices\Proxmox\ConsoleAuth;
use BeltoftServices\Proxmox\ConsoleSessions;
use BeltoftServices\Proxmox\VmRecords;

require_once __DIR__ . '/MonitoringTestCase.php';

class ConsoleLiveWiringTest extends MonitoringTestCase {
	public function set_up(): void {
		define( 'BELTOFT_SERVICES_PROXMOX_LIVE', true );
		parent::set_up();
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_live_client_is_gated_and_no_real_http_request_can_leave(): void {
		$this->settings( array( 'console' => 1 ) );
		$r = $this->ready_vm();
		VmRecords::move( $r->id(), 'node-a' );
		$owner = (int) $r->license()['user_id'];
		$stored = get_option( Clusters::OPTION );
		$stored['lab']['client'] = 'live';
		update_option( Clusters::OPTION, $stored, false );
		remove_all_filters( 'beltoft_services_proxmox_client' );
		$this->assertTrue( Clients::live_allowed() );
		$this->assertSame( 'live', Clusters::get( 'lab' )['client'] );
		$attempts = array();
		$block = static function ( $pre, $args, $url ) use ( &$attempts ) {
			$attempts[] = $url;
			return new \WP_Error( 'blocked', 'No network request is allowed in this test.' );
		};
		add_filter( 'pre_http_request', $block, 1, 3 );
		global $wp_rest_server;
		$wp_rest_server = new \WP_REST_Server();
		do_action( 'rest_api_init', $wp_rest_server );
		wp_set_current_user( $owner );
		try {
			$reply = rest_get_server()->dispatch( new \WP_REST_Request( 'POST', '/beltoft-services/v1/resources/' . $r->id() . '/console' ) );
			$this->assertSame( 503, $reply->get_status() );
			$this->assertNotEmpty( $attempts, 'The live client attempted its ownership read, which the test intercepted.' );
			$this->assertSame( array(), $this->fake->calls(), 'No fake fallback after the cluster became live.' );
			$token = ConsoleSessions::create( $owner, $r->id(), '192.0.2.30', 'lab', 'node-a', 5000, 5900, 'PVEVNC:test' );
			$this->assertIsString( $token );
			$auth = ConsoleAuth::authorize( $token, $owner, '192.0.2.30' );
			$this->assertWPError( $auth );
			$this->assertSame( 503, $auth->get_error_data()['status'], 'No configured CA or pin: ticket is kept.' );
			$this->assertNotNull( ConsoleSessions::peek( $token, $owner, '192.0.2.30' ) );
		} finally {
			remove_filter( 'pre_http_request', $block, 1 );
			$wp_rest_server = null;
			wp_set_current_user( 0 );
		}
	}
}

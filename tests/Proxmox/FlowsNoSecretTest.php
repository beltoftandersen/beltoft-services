<?php
/** The flows route without the shared secret. @package BeltoftServices */
namespace BeltoftServices\Tests\Proxmox;

use BeltoftServices\Tests\TestCase;

/**
 * Runs in its own process: BELTOFT_SERVICES_FLOW_SECRET is a constant and the other tests define it.
 */
class FlowsNoSecretTest extends TestCase {

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_without_the_constant_the_route_refuses_everything(): void {
		$this->assertFalse( defined( 'BELTOFT_SERVICES_FLOW_SECRET' ) );
		$_SERVER['REMOTE_ADDR'] = '10.10.10.1';

		$body    = (string) wp_json_encode( array( 'v' => 1, 'at' => time(), 'sources' => new \stdClass() ) );
		$request = new \WP_REST_Request( 'POST', '/beltoft-services/v1/flows' );
		$request->set_header( 'content-type', 'application/json' );
		$request->set_header( 'x-beltoft-signature', hash_hmac( 'sha256', $body, '' ) );
		$request->set_body( $body );

		$this->assertSame( 403, rest_get_server()->dispatch( $request )->get_status() );
		unset( $_SERVER['REMOTE_ADDR'] );
	}
}

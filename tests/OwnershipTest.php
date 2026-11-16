<?php
/**
 * The "this service belongs to the signed-in customer" rule at every place that applies it: another customer is refused,
 * the owner is let through, and a signed-out caller is refused.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests;

use BeltoftLicenses\Events;
use BeltoftServices\Api\Routes;
use BeltoftServices\Mcp\DeleteService;
use BeltoftServices\Mcp\Told;
use BeltoftServices\Mcp\Tools;
use BeltoftServices\Resource;
use BeltoftServices\Tests\Mcp\McpTestCase;

require_once __DIR__ . '/Mcp/McpTestCase.php';

/**
 * Ownership checks.
 */
final class OwnershipTest extends McpTestCase {

	/**
	 * @var int
	 */
	private $owner;

	/**
	 * @var int
	 */
	private $other;

	/**
	 * @var Resource
	 */
	private $svc;

	public function set_up(): void {
		parent::set_up();
		add_filter( 'beltoft_services_rest_rate_limit', '__return_zero' );
		$this->owner = self::factory()->user->create();
		$this->other = self::factory()->user->create();
		$this->svc   = $this->server( $this->owner );
	}

	public function tear_down(): void {
		remove_filter( 'beltoft_services_rest_rate_limit', '__return_zero' );
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	private function request( string $route ): \WP_REST_Request {
		$request = new \WP_REST_Request( 'POST', $route );
		$request->set_param( 'id', $this->svc->id() );
		$request->set_param( 'confirm', 'x' );

		return $request;
	}

	private function forbidden( $response ): bool {
		$data = $response instanceof \WP_REST_Response ? $response->get_data() : array();

		return $response instanceof \WP_REST_Response && 403 === $response->get_status() && 'beltoft_services_forbidden' === ( $data['code'] ?? '' );
	}

	/**
	 * @return array<string, array{0: callable}>
	 */
	public static function users(): array {
		return array(
			'owner' => array( 'owner' ),
			'other' => array( 'other' ),
			'none'  => array( 'none' ),
		);
	}

	private function sign_in( string $who ): bool {
		wp_set_current_user( 'owner' === $who ? $this->owner : ( 'other' === $who ? $this->other : 0 ) );

		return 'owner' === $who;
	}

	/**
	 * @dataProvider users
	 */
	public function test_routes_guard( string $who ): void {
		$allowed = $this->sign_in( $who );
		$r       = Routes::guard( $this->request( '/beltoft-services/v1/services/1/reboot' ), true, 10 );

		if ( $allowed ) {
			$this->assertInstanceOf( Resource::class, $r );
		} else {
			$this->assertTrue( $this->forbidden( $r ) );
		}
	}

	/**
	 * @dataProvider users
	 */
	public function test_routes_delete( string $who ): void {
		$allowed = $this->sign_in( $who );
		$r       = Routes::delete( $this->request( '/beltoft-services/v1/services/1' ) );

		$this->assertSame( ! $allowed, $this->forbidden( $r ) );
	}

	/**
	 * @dataProvider users
	 */
	public function test_mcp_owned( string $who ): void {
		$allowed = $this->sign_in( $who );
		$r       = Tools::owned( $this->svc->id(), true );

		if ( $allowed ) {
			$this->assertInstanceOf( Resource::class, $r );
		} else {
			$this->assertWPError( $r );
			$this->assertSame( 'beltoft_services_forbidden', $r->get_error_code() );
		}
	}

	/**
	 * @dataProvider users
	 */
	public function test_mcp_delete_service( string $who ): void {
		$allowed = $this->sign_in( $who );
		$m       = new \ReflectionMethod( DeleteService::class, 'service' );
		$m->setAccessible( true );
		$r = $m->invoke( null, 'delete_server', $this->svc->id(), get_current_user_id() );

		if ( $allowed ) {
			$this->assertNotSame( 'beltoft_services_forbidden', $r instanceof \WP_Error ? $r->get_error_code() : '' );
		} else {
			$this->assertWPError( $r );
			$this->assertSame( 'beltoft_services_forbidden', $r->get_error_code() );
		}
	}

	/**
	 * @dataProvider users
	 */
	public function test_mcp_log( string $who ): void {
		$allowed = $this->sign_in( $who );
		Tools::log( $this->svc->id(), 'ownership probe', true );
		$lines = array_column( Events::for_license( (int) $this->svc->license()['id'] ), 'message' );

		$this->assertSame( $allowed, in_array( 'Claude: ownership probe', $lines, true ) );
	}

	/**
	 * @dataProvider users
	 */
	public function test_mcp_told_service_of( string $who ): void {
		$allowed = $this->sign_in( $who );
		$r       = Told::service_of( get_current_user_id(), array( 'id' => $this->svc->id() ) );

		$this->assertSame( $allowed, $r instanceof Resource );
	}
}

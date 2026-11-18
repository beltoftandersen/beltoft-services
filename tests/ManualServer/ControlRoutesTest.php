<?php
/**
 * The REST routes of the dedicated server controls: owner only, the emailed code where it is needed, the typed name for a reinstall.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\ManualServer;

use BeltoftServices\Api\Tokens;
use BeltoftServices\Jobs;
use BeltoftServices\ManualServer\DedicatedReinstall;
use BeltoftServices\ManualServer\FakeRescueShell;
use BeltoftServices\ManualServer\FakeRobotClient;
use BeltoftServices\ManualServer\ImageCatalog;
use BeltoftServices\ManualServer\ServerControls;
use BeltoftServices\Resource;

require_once __DIR__ . '/ControlsTestCase.php';

final class ControlRoutesTest extends ControlsTestCase {

	private const BASE = '/beltoft-services/v1/resources/';

	public function set_up(): void {
		parent::set_up();
		global $wp_rest_server;
		$wp_rest_server = new \WP_REST_Server();
		do_action( 'rest_api_init', $wp_rest_server );
		add_filter( 'beltoft_services_step_up_verify', static fn( $ok, $user, $secret, $kind, $purpose ) => 0 === strpos( (string) $purpose, 'dedicated_' ) ? ( '123456' === $secret ) : $ok, 10, 5 );
		add_filter( 'beltoft_services_rest_rate_limit', static fn() => 0 );
		$this->settings( array( 'dedicated_images' => implode( "\n", FakeRescueShell::IMAGES ) ) );
	}

	public function tear_down(): void {
		global $wp_rest_server;
		$wp_rest_server = null;
		remove_all_filters( 'beltoft_services_step_up_verify' );
		remove_all_filters( 'beltoft_services_rest_rate_limit' );
		parent::tear_down();
	}

	/**
	 * @param string               $method Method.
	 * @param Resource             $r      Resource.
	 * @param string               $path   Path after /dedicated.
	 * @param array<string, mixed> $params Body.
	 */
	private function call( string $method, Resource $r, string $path, array $params = array() ): \WP_REST_Response {
		$request = new \WP_REST_Request( $method, self::BASE . $r->id() . '/dedicated' . $path );
		$request->set_header( 'content-type', 'application/json' );
		$request->set_body( (string) wp_json_encode( $params ) );

		return rest_get_server()->dispatch( $request );
	}

	private function owner_server( bool $with_key = false ): array {
		$user = $this->new_user();
		wp_set_current_user( $user );

		return array( $this->controlled( $with_key, $user ), $user );
	}

	public function test_only_the_owner_reaches_a_route_and_a_token_reaches_none(): void {
		list( $r ) = $this->owner_server();

		foreach ( array( array( 'GET', '' ), array( 'POST', '/reset' ), array( 'POST', '/wol' ), array( 'POST', '/reinstall' ), array( 'PUT', '/firewall' ) ) as $case ) {
			$this->assertFalse( Tokens::reachable( '/beltoft-services/v1/resources/' . $r->id() . '/dedicated' . $case[1] ), $case[1] . ' is not for a token.' );
		}

		wp_set_current_user( $this->new_user() );
		$this->assertSame( 403, $this->call( 'GET', $r, '' )->get_status() );
		$this->assertSame( 403, $this->call( 'POST', $r, '/reset', array( 'type' => 'sw' ) )->get_status() );
		$this->assertSame( array(), FakeRobotClient::calls(), 'Nothing reached the supplier.' );

		wp_set_current_user( 0 );
		$this->assertContains( $this->call( 'GET', $r, '' )->get_status(), array( 401, 403 ) );
	}

	public function test_a_restart_needs_no_code_but_a_hardware_reset_does(): void {
		list( $r ) = $this->owner_server();

		$this->assertSame( 200, $this->call( 'POST', $r, '/reset', array( 'type' => 'sw' ) )->get_status() );

		$refused = $this->call( 'POST', $r, '/reset', array( 'type' => 'hw' ) );
		$this->assertSame( 403, $refused->get_status() );
		$this->assertSame( 'step_up', $refused->get_data()['code'] );
		$this->assertCount( 1, array_filter( FakeRobotClient::calls(), static fn( $c ) => 'reset' === $c['call'] ), 'The refused hardware reset was not sent.' );

		$this->assertSame( 200, $this->call( 'POST', $r, '/reset', array( 'type' => 'hw', 'step_code' => '123456' ) )->get_status() );
		$this->assertSame( 400, $this->call( 'POST', $r, '/reset', array( 'type' => 'rm -rf' ) )->get_status() );
	}

	public function test_the_overview_answer_has_no_password_and_no_supplier_name(): void {
		list( $r ) = $this->owner_server();
		$this->call( 'POST', $r, '/rescue', array( 'boot' => false ) );

		$answer = $this->call( 'GET', $r, '' );
		$this->assertSame( 200, $answer->get_status() );
		$this->assertSame( 'no-store', $answer->get_headers()['Cache-Control'] );
		$json = (string) wp_json_encode( $answer->get_data() );
		$this->assertStringNotContainsString( FakeRobotClient::rescue_password( self::NUMBER ), $json );
		$this->assertStringNotContainsStringIgnoringCase( 'hetzner', $json );
		$this->assertTrue( $answer->get_data()['rescue']['password'] );
	}

	public function test_the_rescue_password_needs_a_code_and_exists_only_without_a_key(): void {
		list( $r ) = $this->owner_server();
		$this->assertSame( 409, $this->call( 'POST', $r, '/rescue-password' )->get_status(), 'Rescue is off: nothing to show.' );

		$this->call( 'POST', $r, '/rescue', array( 'boot' => false ) );
		$this->assertSame( 403, $this->call( 'POST', $r, '/rescue-password' )->get_status() );

		$shown = $this->call( 'POST', $r, '/rescue-password', array( 'step_code' => '123456' ) );
		$this->assertSame( 200, $shown->get_status() );
		$this->assertSame( FakeRobotClient::rescue_password( self::NUMBER ), $shown->get_data()['password'] );

		$this->assertSame( 200, $this->call( 'DELETE', $r, '/rescue' )->get_status() );
		$this->assertSame( 409, $this->call( 'POST', $r, '/rescue-password', array( 'step_code' => '123456' ) )->get_status(), 'Gone when switched off.' );
	}

	public function test_a_firewall_that_leaves_ssh_open_needs_no_code_and_one_that_closes_it_does(): void {
		list( $r ) = $this->owner_server();
		$ssh = array( array( 'ip_version' => 'ipv4', 'protocol' => 'tcp', 'dst_port' => '22', 'action' => 'accept', 'name' => 'ssh' ) );
		$web = array( array( 'ip_version' => 'ipv4', 'protocol' => 'tcp', 'dst_port' => '443', 'action' => 'accept', 'name' => 'web' ) );

		$this->assertSame( 200, $this->call( 'PUT', $r, '/firewall', array( 'status' => 'active', 'input' => $ssh ) )->get_status() );

		$refused = $this->call( 'PUT', $r, '/firewall', array( 'status' => 'active', 'input' => $web ) );
		$this->assertSame( 403, $refused->get_status() );
		$this->assertSame( 'ssh', ServerControls::overview( Resource::find( $r->id() ) )['firewall']['input'][0]['name'], 'The old rules stay.' );

		$this->assertSame( 200, $this->call( 'PUT', $r, '/firewall', array( 'status' => 'active', 'input' => $web, 'step_code' => '123456' ) )->get_status() );
		$this->assertSame( 200, $this->call( 'PUT', $r, '/firewall', array( 'status' => 'disabled', 'input' => array() ) )->get_status(), 'Switching it off never locks anyone out.' );
		$this->assertSame( 400, $this->call( 'PUT', $r, '/firewall', array( 'status' => 'active', 'input' => array( array( 'action' => 'bogus' ) ) ) )->get_status() );
	}

	public function test_reverse_dns_routes_check_the_address(): void {
		list( $r ) = $this->owner_server();

		$this->assertSame( 200, $this->call( 'PUT', $r, '/rdns', array( 'ip' => $this->ipv4(), 'ptr' => 'srv.example.com' ) )->get_status() );
		$this->assertSame( 400, $this->call( 'PUT', $r, '/rdns', array( 'ip' => '8.8.8.8', 'ptr' => 'srv.example.com' ) )->get_status() );
		$this->assertSame( 200, $this->call( 'DELETE', $r, '/rdns', array( 'ip' => $this->ipv4() ) )->get_status() );
	}

	public function test_a_reinstall_needs_the_typed_name_and_the_code_and_then_runs_as_a_job(): void {
		list( $r ) = $this->owner_server();
		$image = 'Debian-1201-bookworm-amd64-base.tar.gz';
		$name  = (string) $r->get( 'server_name' );

		$this->assertSame( 400, $this->call( 'POST', $r, '/reinstall', array( 'image' => $image, 'step_code' => '123456' ) )->get_status(), 'No name typed.' );
		$this->assertSame( 400, $this->call( 'POST', $r, '/reinstall', array( 'image' => $image, 'confirm' => 'wrong', 'step_code' => '123456' ) )->get_status() );
		$this->assertSame( 403, $this->call( 'POST', $r, '/reinstall', array( 'image' => $image, 'confirm' => $name ) )->get_status(), 'No code.' );
		$this->assertSame( 400, $this->call( 'POST', $r, '/reinstall', array( 'image' => 'x.tar.gz', 'confirm' => $name, 'step_code' => '123456' ) )->get_status(), 'Not an offered system.' );
		$this->assertNull( Resource::find( $r->id() )->get( DedicatedReinstall::META ), 'Nothing started yet.' );

		$started = $this->call( 'POST', $r, '/reinstall', array( 'image' => $image, 'confirm' => $name, 'step_code' => '123456' ) );
		$this->assertSame( 202, $started->get_status() );
		$this->assertSame( 'running', Resource::find( $r->id() )->get( DedicatedReinstall::META )['state'] );
		$this->assertSame( 409, $this->call( 'POST', $r, '/reinstall', array( 'image' => $image, 'confirm' => $name, 'step_code' => '123456' ) )->get_status(), 'Not twice.' );
		$this->assertSame( array(), $this->robot_calls_named( 'reset' ), 'The job has not run yet.' );
	}

	/** @return array<int, array<string, mixed>> */
	private function robot_calls_named( string $call ): array {
		return array_values( array_filter( FakeRobotClient::calls(), static fn( array $c ): bool => $call === $c['call'] ) );
	}

	public function test_the_images_route_lists_the_cached_names(): void {
		list( $r ) = $this->owner_server();
		$answer = $this->call( 'GET', $r, '/images' );

		$this->assertSame( 200, $answer->get_status() );
		$this->assertEqualsCanonicalizing( FakeRescueShell::IMAGES, $answer->get_data()['images'] );
		$this->assertSame( ImageCatalog::names(), $answer->get_data()['images'] );
	}

	public function test_a_server_delivered_by_hand_is_not_controllable(): void {
		$user = $this->new_user();
		wp_set_current_user( $user );
		$v = $this->server_product();
		$r = $this->ready_server( $v['u'], $user );

		$this->assertSame( 409, $this->call( 'POST', $r, '/reset', array( 'type' => 'sw' ) )->get_status() );
		$this->assertSame( 409, $this->call( 'POST', $r, '/wol' )->get_status() );
	}
}

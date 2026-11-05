<?php
/** A certificate through the MCP tools and API tokens. @package BeltoftServices */
namespace BeltoftServices\Tests\Ssl;

use BeltoftLicenses\Events;
use BeltoftServices\Api\Tokens;
use BeltoftServices\Mcp\ActionMap;
use BeltoftServices\Mcp\Definitions;
use BeltoftServices\Resource;
use BeltoftServices\Ssl\Keys;
use BeltoftServices\Tests\Mcp\CallsTools;

require_once __DIR__ . '/SslTestCase.php';
require_once dirname( __DIR__ ) . '/Mcp/CallsTools.php';

final class SslToolsTest extends SslTestCase {

	use CallsTools;

	public function set_up(): void {
		parent::set_up();
		$this->mcp_set_up();
	}

	public function tear_down(): void {
		$this->mcp_tear_down();
		parent::tear_down();
	}

	/** @return array{0: Resource, 1: int} */
	private function certificate( string $name = 'example.com' ): array {
		$r = $this->built( array( 'meta' => array( 'ssl_name' => $name ) ) );
		\BeltoftLicenses\Licenses::update( (int) $r->license()['id'], array( 'email' => 'christian.beltoft@gmail.com' ) );

		return array( Resource::find( $r->id() ), (int) $r->license()['user_id'] );
	}

	/** @return string[] */
	private function history( Resource $r ): array {
		return array_column( Events::for_license( (int) $r->license()['id'] ), 'message' );
	}

	public function test_the_tools_are_at_the_right_levels_and_the_key_has_no_tool(): void {
		$tools = Definitions::all();
		$this->assertSame( Tokens::READ, $tools['ssl_info']['level'] );
		$this->assertSame( Tokens::READ, $tools['get_ssl_certificate']['level'] );
		$this->assertSame( Tokens::ACTIONS, $tools['ssl_reissue']['level'] );
		$this->assertSame( Tokens::ACTIONS, $tools['ssl_resend_email']['level'] );
		$this->assertArrayNotHasKey( 'ssl_cancel', $tools, 'Cancelling has no tool yet.' );

		foreach ( array_keys( $tools ) as $name ) {
			$this->assertSame( 0, preg_match( '/ssl.*(key|private)|(key|private).*ssl/', $name ), $name );
		}

		$this->assertSame( 'ssl_reissue', ActionMap::ACTIONS['ssl_reissue'] );
		$this->assertArrayHasKey( 'ssl_cancel', ActionMap::EXCLUDED );
		$this->assertTrue( ActionMap::permits( 'get_ssl_certificate', 'GET', '/resources/5/ssl-certificate' ) );
		$this->assertFalse( ActionMap::permits( 'ssl_info', 'GET', '/resources/5/ssl-certificate' ) );
		$this->assertFalse( ActionMap::permits( 'ssl_info', 'POST', '/resources/5/ssl-key' ), 'No tool reaches the key route.' );
		foreach ( array_keys( $tools ) as $name ) {
			$this->assertFalse( ActionMap::permits( $name, 'POST', '/resources/5/ssl-key' ), $name );
		}
	}

	public function test_ssl_info_says_what_validation_waits_for_and_holds_no_secret(): void {
		list( $r, $user ) = $this->certificate();
		$read = $this->oauth( $user, Tokens::READ );
		$data = $this->json( $this->tool( 'ssl_info', array( 'id' => $r->id() ), $read ) );
		$this->assertSame( 'example.com', $data['name'] );
		$this->assertSame( 'ACT', $data['registrar_status'] );
		$this->assertTrue( $data['private_key_waiting'] );
		$this->assertContains( 'ssl_reissue', $data['dashboard_offers'] );
		$this->assertStringNotContainsString( 'PRIVATE', (string) wp_json_encode( $data ) );
		$this->assertStringNotContainsString( 'BEGIN CERTIFICATE', (string) wp_json_encode( $data ) );
		$this->assertNotContains( 'Claude: SSL certificate read', $this->history( $r ), 'Reads leave no row in the history.' );
		$this->assertTrue( $this->tool( 'ssl_info', array( 'id' => $this->ready_mail( 'm.example', array(), $user )->id() ), $read )['isError'], 'Not a certificate.' );
		$stranger = $this->oauth( (int) self::factory()->user->create(), Tokens::READ );
		$this->assertTrue( $this->tool( 'ssl_info', array( 'id' => $r->id() ), $stranger )['isError'] );
	}

	public function test_a_waiting_certificate_shows_the_values_to_add(): void {
		$r = $this->ssl_resource();
		( new \BeltoftServices\Ssl\SslDriver() )->provision( $r );
		$r = Resource::find( $r->id() );
		$r->set( array( 'state' => 'ready' ) );
		$data = $this->json( $this->tool( 'ssl_info', array( 'id' => $r->id() ), $this->oauth( (int) $r->license()['user_id'], Tokens::READ ) ) );
		$this->assertSame( 'CNAME', $data['validation_waits_for'][0]['type'] );
		$this->assertStringEndsWith( '.example.com', $data['validation_waits_for'][0]['name'] );
	}

	public function test_get_ssl_certificate_gives_the_public_chain_and_never_the_key(): void {
		list( $r, $user ) = $this->certificate();
		$data = $this->json( $this->tool( 'get_ssl_certificate', array( 'id' => $r->id() ), $this->oauth( $user, Tokens::READ ) ) );
		$this->assertSame( 'example.com', $data['name'] );
		$this->assertSame( 2, substr_count( $data['certificate_with_chain'], 'BEGIN CERTIFICATE' ) );
		$this->assertStringNotContainsString( 'PRIVATE', (string) wp_json_encode( $data ) );
		$this->assertNotNull( Keys::existing( Resource::find( $r->id() ) ), 'The key was not touched.' );
	}

	public function test_reissue_and_resend_run_at_actions_level_only(): void {
		list( $r, $user ) = $this->certificate();
		$read    = $this->oauth( $user, Tokens::READ );
		$actions = $this->oauth( $user, Tokens::ACTIONS );
		$this->assertTrue( $this->tool( 'ssl_reissue', array( 'id' => $r->id() ), $read )['isError'], 'Read cannot reissue.' );
		$done = $this->json( $this->tool( 'ssl_reissue', array( 'id' => $r->id() ), $actions ) );
		$this->assertTrue( $done['requested'] );
		$this->assertContains( 'Claude: SSL certificate reissue requested', $this->history( $r ) );
		$this->assertCount( 0, $this->calls( 'POST', '#/reissue$#' ), 'It is queued, not done in the request.' );
		$this->assertTrue( $this->tool( 'ssl_resend_email', array( 'id' => $r->id() ), $actions )['isError'], 'Not offered for a certificate that is not waiting for an email.' );
	}

	public function test_a_token_cannot_cancel_or_reach_the_key_over_rest(): void {
		list( $r, $user ) = $this->certificate();
		list( , $actions ) = $this->personal( $user, Tokens::ACTIONS );
		wp_set_current_user( 0 );
		$_SERVER['PHP_AUTH_USER'] = (string) get_userdata( $user )->user_login;
		$_SERVER['PHP_AUTH_PW']   = $actions;
		wp_set_current_user( (int) apply_filters( 'determine_current_user', false ) );
		$request = new \WP_REST_Request( 'POST', '/beltoft-services/v1/resources/' . $r->id() . '/actions/ssl_cancel' );
		$request->set_header( 'content-type', 'application/json' );
		$request->set_body( (string) wp_json_encode( array( 'args' => array( 'confirm' => 'CANCEL' ) ) ) );
		$this->assertSame( 403, rest_get_server()->dispatch( $request )->get_status() );
		$key = new \WP_REST_Request( 'POST', '/beltoft-services/v1/resources/' . $r->id() . '/ssl-key' );
		$this->assertSame( 403, rest_get_server()->dispatch( $key )->get_status() );
		$this->assertNotNull( Keys::existing( Resource::find( $r->id() ) ) );
	}
}

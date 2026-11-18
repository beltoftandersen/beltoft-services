<?php
/**
 * The root password of a manual server: encrypted at rest, shown only behind the emailed code, in no mail and no page.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\ManualServer;

use BeltoftServices\Api\Tokens;
use BeltoftServices\Jobs;
use BeltoftServices\ManualServer\ManualServerDelivery;
use BeltoftServices\ManualServer\ManualServerDriver;
use BeltoftServices\ManualServer\ManualServerPanel;
use BeltoftServices\Resource;

require_once __DIR__ . '/ManualServerTestCase.php';

final class ManualServerPasswordTest extends ManualServerTestCase {

	protected const PW = 'Xq7-sample-root-pw';
	private const BASE   = '/beltoft-services/v1/resources/';

	public function set_up(): void {
		parent::set_up();
		$key = base64_encode( str_repeat( 'k', 32 ) );
		add_filter( 'beltoft_services_key', static fn() => $key );
		global $wp_rest_server;
		$wp_rest_server = new \WP_REST_Server();
		do_action( 'rest_api_init', $wp_rest_server );
		add_filter( 'beltoft_services_step_up_verify', static fn( $ok, $user, $secret, $kind, $purpose ) => 'manual_password' === $purpose ? ( '123456' === $secret ) : $ok, 10, 5 );
	}

	public function tear_down(): void {
		global $wp_rest_server;
		$wp_rest_server = null;
		remove_all_filters( 'beltoft_services_key' );
		remove_all_filters( 'beltoft_services_step_up_verify' );
		remove_all_filters( 'beltoft_services_rest_rate_limit' );
		parent::tear_down();
	}

	/** @param array<string, mixed> $params */
	private function reveal( Resource $r, int $user, array $params = array() ): \WP_REST_Response {
		wp_set_current_user( $user );
		$request = new \WP_REST_Request( 'POST', self::BASE . $r->id() . '/manual-password' );
		$request->set_header( 'content-type', 'application/json' );
		$request->set_body( (string) wp_json_encode( $params ) );

		return rest_get_server()->dispatch( $request );
	}

	private function delivered( ?string $password = self::PW ): Resource {
		$v    = $this->server_product();
		$user = $this->new_user();
		$r    = $this->waiting_server( $v['u'], $user );
		Jobs::run( $r->id() );
		$done = ManualServerDelivery::save( Resource::find( $r->id() ), '203.0.113.7', 'Log in as root.', 'S-1', 'tester', true, (string) $password );
		$this->assertTrue( $done, is_wp_error( $done ) ? $done->get_error_message() : '' );
		Jobs::run( $r->id() );

		return Resource::find( $r->id() );
	}

	public function test_the_password_is_encrypted_at_rest(): void {
		$r = $this->delivered();

		$this->assertNotSame( '', (string) $r->get( ManualServerDelivery::PASSWORD ) );
		$this->assertStringNotContainsString( self::PW, (string) $r->get( ManualServerDelivery::PASSWORD ) );
		$this->assertStringNotContainsString( self::PW, (string) wp_json_encode( \BeltoftLicenses\Licenses::activation( $r->id() ) ), 'Not in the stored row.' );
		$this->assertSame( self::PW, ManualServerDelivery::root_password( $r ) );
	}

	public function test_a_blank_password_keeps_the_stored_one_and_a_new_one_replaces_it(): void {
		$r = $this->delivered();

		$this->assertTrue( ManualServerDelivery::save( $r, '203.0.113.7', 'Note.', 'S-1', 'tester', false, '' ) );
		$this->assertSame( self::PW, ManualServerDelivery::root_password( Resource::find( $r->id() ) ) );
		$this->assertTrue( ManualServerDelivery::save( Resource::find( $r->id() ), '203.0.113.7', 'Note.', 'S-1', 'tester', false, 'New-pw-123' ) );
		$this->assertSame( 'New-pw-123', ManualServerDelivery::root_password( Resource::find( $r->id() ) ) );
		$this->assertWPError( ManualServerDelivery::save( Resource::find( $r->id() ), '203.0.113.7', '', '', 'tester', false, str_repeat( 'a', 201 ) ) );
		$this->assertWPError( ManualServerDelivery::save( Resource::find( $r->id() ), '203.0.113.7', '', '', 'tester', false, "bad\npw" ) );
	}

	public function test_the_reveal_needs_the_owner_and_the_emailed_code(): void {
		$r    = $this->delivered();
		$user = (int) $r->license()['user_id'];

		$no = $this->reveal( $r, $user );
		$this->assertSame( 403, $no->get_status() );
		$this->assertStringNotContainsString( self::PW, (string) wp_json_encode( $no->get_data() ) );
		$this->assertSame( 403, $this->reveal( $r, $user, array( 'step_code' => '000000' ) )->get_status() );
		$this->assertSame( 403, $this->reveal( $r, $this->new_user(), array( 'step_code' => '123456' ) )->get_status(), 'Not the owner.' );
		$this->assertSame( 401, $this->reveal( $r, 0, array( 'step_code' => '123456' ) )->get_status() );

		$good = $this->reveal( $r, $user, array( 'step_code' => '123456' ) );
		$this->assertSame( 200, $good->get_status() );
		$this->assertSame( self::PW, $good->get_data()['password'] );
		$this->assertStringContainsString( 'no-store', (string) $good->get_headers()['Cache-Control'] );
		$this->assertFalse( Tokens::reachable( self::BASE . $r->id() . '/manual-password' ), 'An API token never reaches it.' );
	}

	public function test_no_password_set_answers_409_and_the_reveal_is_rate_limited(): void {
		$none = $this->delivered( '' );
		$this->assertSame( 409, $this->reveal( $none, (int) $none->license()['user_id'], array( 'step_code' => '123456' ) )->get_status() );

		$r    = $this->delivered();
		$user = (int) $r->license()['user_id'];
		$last = 0;
		for ( $i = 0; $i < 8; $i++ ) {
			$last = $this->reveal( $r, $user )->get_status();
		}
		$this->assertSame( 429, $last );
	}

	public function test_the_password_is_in_no_mail_no_page_and_no_admin_form(): void {
		$r    = $this->delivered();
		$user = (int) $r->license()['user_id'];
		$r->desire( 'paused', 'lapse' );
		Jobs::run( $r->id() );
		$r = Resource::find( $r->id() );
		$r->desire( 'deleted', 'cancel' );
		Jobs::run( $r->id() );

		$this->assertNotEmpty( $this->staff_mails );
		foreach ( $this->staff_mails as $mail ) {
			$this->assertStringNotContainsString( self::PW, $mail['subject'] . $mail['body'] );
		}
		$this->assertNotEmpty( $this->mails_with( 'is ready' ), 'The ready email was sent.' );

		$fresh = $this->delivered();
		wp_set_current_user( (int) $fresh->license()['user_id'] );
		ob_start();
		ManualServerPanel::account( $fresh );
		$card = (string) ob_get_clean();
		$this->assertStringContainsString( 'data-manual-reveal', $card );
		$this->assertStringNotContainsString( self::PW, $card );

		ob_start();
		( new ManualServerDriver() )->render_admin( $fresh );
		$admin = (string) ob_get_clean();
		$this->assertStringContainsString( 'A password is set', $admin );
		$this->assertStringNotContainsString( self::PW, $admin );
		$this->assertStringNotContainsString( self::PW, (string) wp_json_encode( ( new ManualServerDriver() )->status( $fresh ) ) );
		unset( $user );
	}
}

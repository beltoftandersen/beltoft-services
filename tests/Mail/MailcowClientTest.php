<?php
/**
 * The live mailcow client (through the HTTP recorder, never the network), the fake, and the factory.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Mail;

use BeltoftServices\Crypto;
use BeltoftServices\Mail\FakeMailcowClient;
use BeltoftServices\Mail\LiveMailcowClient;
use BeltoftServices\Mail\MailClients;
use BeltoftServices\Tests\TestCase;

final class MailcowClientTest extends TestCase {

	private const KEY = 'SECRET-MAILCOW-KEY-123';

	private function json( $body, int $code = 200 ): array {
		return array( 'headers' => array(), 'body' => (string) wp_json_encode( $body ), 'response' => array( 'code' => $code, 'message' => '' ), 'cookies' => array(), 'filename' => null );
	}

	private function live(): LiveMailcowClient {
		return new LiveMailcowClient( 'https://mail.example.net/', self::KEY );
	}

	public function test_the_live_client_wants_an_https_address_and_a_key(): void {
		foreach ( array( array( 'http://mail.example.net', 'k' ), array( 'mail.example.net', 'k' ), array( '', 'k' ), array( 'https://mail.example.net', '' ) ) as [ $url, $key ] ) {
			try {
				new LiveMailcowClient( $url, $key );
				$this->fail( 'Built with ' . $url );
			} catch ( \InvalidArgumentException $e ) {
				$this->assertStringContainsString( 'https', $e->getMessage() );
			}
		}
	}

	public function test_a_write_posts_json_with_the_key_in_the_header_only(): void {
		$this->respond_with( fn() => $this->json( array( array( 'type' => 'success', 'msg' => array( 'domain_added', 'a.example' ) ) ) ) );
		$reply = $this->live()->add_domain( array( 'domain' => 'a.example', 'mailboxes' => '5' ) );

		$this->assertIsArray( $reply );
		$call = $this->http_calls()[0];
		$this->assertSame( 'https://mail.example.net/api/v1/add/domain', $call['url'] );
		$this->assertSame( 'POST', $call['args']['method'] );
		$this->assertSame( self::KEY, $call['args']['headers']['X-API-Key'] );
		$this->assertSame( array( 'domain' => 'a.example', 'mailboxes' => '5' ), json_decode( $call['args']['body'], true ) );
		$this->assertStringNotContainsString( self::KEY, $call['url'] . $call['args']['body'] );
		$this->assertSame( 0, $call['args']['redirection'] );
	}

	public function test_the_endpoints_and_payloads(): void {
		$this->respond_with( fn() => $this->json( array( array( 'type' => 'success', 'msg' => array( 'ok' ) ) ) ) );
		$c = $this->live();

		$c->edit_domain( 'a.example', array( 'active' => '0' ) );
		$c->delete_domain( 'a.example' );
		$c->add_dkim( 'a.example', 'dkim', 2048 );
		$c->edit_mailbox( 'x@a.example', array( 'quota' => '100' ) );
		$c->delete_mailbox( 'x@a.example' );
		$c->set_mailbox_rate_limit( 'x@a.example', 500, 'd' );
		$c->set_domain_rate_limit( 'a.example', 30, 'm' );
		$c->delete_alias( 6 );

		$seen = array_map( static fn( $call ) => array( str_replace( 'https://mail.example.net/api/v1/', '', $call['url'] ), json_decode( $call['args']['body'], true ) ), $this->http_calls() );

		$this->assertSame(
			array(
				array( 'edit/domain', array( 'items' => array( 'a.example' ), 'attr' => array( 'active' => '0' ) ) ),
				array( 'delete/domain', array( 'a.example' ) ),
				array( 'add/dkim', array( 'domain' => 'a.example', 'dkim_selector' => 'dkim', 'key_size' => '2048' ) ),
				array( 'edit/mailbox', array( 'items' => array( 'x@a.example' ), 'attr' => array( 'quota' => '100' ) ) ),
				array( 'delete/mailbox', array( 'x@a.example' ) ),
				array( 'edit/rl-mbox', array( 'items' => array( 'x@a.example' ), 'attr' => array( 'rl_value' => '500', 'rl_frame' => 'd' ) ) ),
				array( 'edit/rl-domain', array( 'items' => array( 'a.example' ), 'attr' => array( 'rl_value' => '30', 'rl_frame' => 'm' ) ) ),
				array( 'delete/alias', array( '6' ) ),
			),
			$seen
		);
	}

	public function test_reads(): void {
		$this->respond_with(
			function ( $url ) {
				if ( str_contains( $url, 'get/alias/all' ) ) {
					return $this->json( array( array( 'id' => 6, 'address' => 'a@a.example', 'goto' => 'b@x.example', 'domain' => 'a.example' ), array( 'id' => 7, 'address' => 'a@b.example', 'goto' => 'c@x.example', 'domain' => 'b.example' ) ) );
				}
				if ( str_contains( $url, 'get/domain/gone.example' ) || str_contains( $url, 'get/dkim/' ) ) {
					return $this->json( array() );
				}
				if ( str_contains( $url, 'get/mailbox/all/' ) ) {
					return $this->json( array( array( 'username' => 'x@a.example', 'quota' => 1048576, 'quota_used' => 10 ) ) );
				}
				return $this->json( array( 'domain' => 'a.example' ) );
			}
		);
		$c = $this->live();

		$this->assertNull( $c->get_domain( 'gone.example' ) );
		$this->assertSame( 'a.example', $c->get_domain( 'a.example' )['domain'] );
		$this->assertNull( $c->get_dkim( 'a.example' ) );
		$this->assertSame( 'x@a.example', $c->list_mailboxes( 'a.example' )[0]['username'] );
		$this->assertSame( array( 6 ), array_column( $c->list_aliases( 'a.example' ), 'id' ) );
		$this->assertStringContainsString( 'get/mailbox/all/a.example', $this->http_calls()[3]['url'] );
		$this->assertSame( 'GET', $this->http_calls()[0]['args']['method'] );
	}

	public function test_add_alias_returns_the_id_mailcow_reports(): void {
		$this->respond_with( fn() => $this->json( array( array( 'type' => 'success', 'msg' => array( 'alias_added', 'a@a.example', '42' ) ) ) ) );
		$this->assertSame( 42, $this->live()->add_alias( 'a@a.example', 'b@x.example' ) );
		$this->respond_with( fn() => $this->json( array( array( 'type' => 'success', 'msg' => array( 'alias_added' ) ) ) ) );
		$this->assertSame( 0, $this->live()->add_alias( 'a@a.example', 'b@x.example' ) );
	}

	public function test_errors_are_classified_and_never_hold_the_key(): void {
		$this->respond_with( fn() => $this->json( array( array( 'type' => 'danger', 'msg' => array( 'domain_exists', 'a.example' ) ) ) ) );
		$e = $this->live()->add_domain( array( 'domain' => 'a.example' ) );
		$this->assertInstanceOf( \WP_Error::class, $e );
		$this->assertSame( 'mailcow_exists', $e->get_error_code() );
		$this->assertSame( 'domain_exists', $e->get_error_data()['key'] );

		$this->respond_with( fn() => $this->json( array( 'type' => 'error', 'msg' => 'bad ' . self::KEY ) ) );
		$e = $this->live()->add_domain( array() );
		$this->assertSame( 'mailcow_error', $e->get_error_code() );
		$this->assertStringNotContainsString( self::KEY, $e->get_error_message() );

		$this->respond_with( fn() => $this->json( array(), 502 ) );
		$e = $this->live()->get_mailbox( 'x@a.example' );
		$this->assertSame( 'mailcow_http', $e->get_error_code() );
		$this->assertSame( 502, $e->get_error_data()['status'] );

		$this->respond_with( fn() => new \WP_Error( 'http_request_failed', 'cURL error 28 for ' . self::KEY ) );
		$e = $this->live()->get_mailbox( 'x@a.example' );
		$this->assertSame( 'mailcow_transport', $e->get_error_code() );
		$this->assertStringNotContainsString( self::KEY, $e->get_error_message() );

		$this->respond_with( fn() => array( 'headers' => array(), 'body' => '<html>', 'response' => array( 'code' => 200, 'message' => '' ), 'cookies' => array(), 'filename' => null ) );
		$this->assertSame( 'mailcow_error', $this->live()->get_mailbox( 'x@a.example' )->get_error_code() );
	}

	public function test_the_factory_builds_a_live_client_only_when_everything_allows_it(): void {
		$key = base64_encode( str_repeat( 'k', 32 ) );
		add_filter( 'beltoft_services_key', static fn() => $key );
		$this->settings( array( 'mail_client' => 'live', 'mail_api_url' => 'https://mail.example.net', 'mail_api_key' => Crypto::encrypt( self::KEY ) ) );

		$this->assertFalse( MailClients::live_allowed(), 'The constant is not defined here.' );
		$this->assertInstanceOf( FakeMailcowClient::class, MailClients::for() );
		$this->assertInstanceOf( FakeMailcowClient::class, MailClients::build( false ) );
		$this->assertInstanceOf( LiveMailcowClient::class, MailClients::build( true ) );

		$this->settings( array( 'mail_client' => 'fake' ) );
		$this->assertInstanceOf( FakeMailcowClient::class, MailClients::build( true ), 'Allowed but not chosen.' );

		$this->settings( array( 'mail_client' => 'live', 'mail_api_url' => 'http://mail.example.net' ) );
		$this->assertInstanceOf( FakeMailcowClient::class, MailClients::build( true ), 'A plain http address is never used.' );

		$this->settings( array( 'mail_api_url' => 'https://mail.example.net', 'mail_api_key' => '' ) );
		$this->assertInstanceOf( FakeMailcowClient::class, MailClients::build( true ), 'No key.' );
		$this->assertSame( array(), $this->http_calls(), 'Nothing was called.' );
		remove_all_filters( 'beltoft_services_key' );
	}

	public function test_a_filter_cannot_bring_in_a_live_client_while_live_is_off(): void {
		add_filter( 'beltoft_services_mail_client', fn() => new LiveMailcowClient( 'https://mail.example.net', 'k' ) );
		$this->assertInstanceOf( FakeMailcowClient::class, MailClients::build( false ) );
		$this->assertInstanceOf( FakeMailcowClient::class, MailClients::build( true ) );
	}

	public function test_the_fake_answers_like_mailcow_and_keeps_passwords_out_of_its_log(): void {
		$f = new FakeMailcowClient();
		$this->assertSame( 'success', $f->add_domain( array( 'domain' => 'A.example', 'mailboxes' => '1', 'aliases' => '1', 'maxquota' => '100' ) )[0]['type'] );
		$this->assertSame( 'mailcow_exists', $f->add_domain( array( 'domain' => 'a.example' ) )->get_error_code() );
		$this->assertNull( $f->get_dkim( 'a.example' ) );
		$f->add_dkim( 'a.example', 'dkim', 2048 );
		$this->assertStringStartsWith( 'v=DKIM1;k=rsa;t=s;s=email;p=', $f->get_dkim( 'a.example' )['dkim_txt'] );

		$box = array( 'local_part' => 'x', 'domain' => 'a.example', 'quota' => '50', 'password' => 'pw-secret', 'password2' => 'pw-secret' );
		$this->assertIsArray( $f->add_mailbox( $box ) );
		$this->assertSame( 'object_exists', $f->add_mailbox( $box )->get_error_data()['key'] );
		$e = $f->add_mailbox( array_merge( $box, array( 'local_part' => 'y' ) ) );
		$this->assertSame( 'max_mailbox_exceeded', $e->get_error_data()['key'] );
		$this->assertSame( 'mailbox_quota_exceeds_domain_quota', $f->edit_mailbox( 'x@a.example', array( 'quota' => '101' ) )->get_error_data()['key'] );
		$f->edit_mailbox( 'x@a.example', array( 'password' => 'pw-secret-2', 'password2' => 'pw-secret-2' ) );
		$this->assertStringNotContainsString( 'pw-secret', (string) wp_json_encode( $f->calls() ) );

		$id = $f->add_alias( 'q@a.example', 'z@x.example' );
		$this->assertSame( 1, $id );
		$this->assertSame( 'max_alias_exceeded', $f->add_alias( 'r@a.example', 'z@x.example' )->get_error_data()['key'] );
		$this->assertSame( 'domain_not_empty', $f->delete_domain( 'a.example' )->get_error_data()['key'] );
		$f->delete_mailbox( 'x@a.example' );
		$f->delete_alias( $id );
		$this->assertIsArray( $f->delete_domain( 'a.example' ) );
		$this->assertNull( $f->get_domain( 'a.example' ) );
	}

	public function test_the_fake_can_keep_its_state_between_requests(): void {
		delete_option( FakeMailcowClient::OPTION );
		$a = new FakeMailcowClient( true );
		$a->add_domain( array( 'domain' => 'p.example' ) );
		$b = new FakeMailcowClient( true );
		$this->assertSame( 'p.example', $b->get_domain( 'p.example' )['domain'] );
		delete_option( FakeMailcowClient::OPTION );
	}
}

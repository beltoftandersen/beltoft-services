<?php
/**
 * Website mail: the hidden system mailbox, the mail.smtp job, the plan caps and the fallback sender.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Hosting;

require_once __DIR__ . '/HostingCase.php';

use BeltoftServices\Api\Routes;
use BeltoftServices\Crypto;
use BeltoftServices\Drivers\Registry;
use BeltoftServices\Hosting\HostingActions;
use BeltoftServices\Hosting\LiteMail;
use BeltoftServices\Hosting\SiteMail;
use BeltoftServices\Mail\FakeMailcowClient;
use BeltoftServices\Mail\MailDriver;
use BeltoftServices\Mail\MailGuard;
use BeltoftServices\Mail\Mailboxes;
use BeltoftServices\Mail\MailView;
use BeltoftServices\Resource;
use BeltoftServices\Settings;

/**
 * SiteMail, and the mail code's handling of a system mailbox.
 */
class SiteMailTest extends HostingCase {

	/**
	 * The fake mail server.
	 *
	 * @var FakeMailcowClient
	 */
	private $mailcow;

	public function set_up(): void {
		parent::set_up();

		delete_option( FakeMailcowClient::OPTION );
		$this->mailcow = new FakeMailcowClient();
		add_filter( 'beltoft_services_mail_client', fn() => $this->mailcow );
		add_filter( 'beltoft_services_rest_rate_limit', '__return_zero' );
		$this->settings(
			array(
				'domain_nameservers' => 'ns1.example.net,ns2.example.net',
				'mail_host'          => 'mail.example.net',
				'mail_spf_include'   => '_spf.mail.example.net',
				'mail_rl_minute'     => 30,
				'mail_rl_day'        => 500,
			)
		);
		$proof = array(
			'delegation' => array( 'status' => 'ok', 'reason' => 'delegated', 'ns' => array( 'ns1.host.example' ), 'ips' => array(), 'ds' => null, 'ours' => false ),
			'check_txt'  => array( 'status' => 'found', 'servers' => array(), 'reason' => 'found' ),
		);
		add_filter( 'beltoft_services_dns_proof_result', fn( $pre, $call ) => $proof[ $call ] ?? $pre, 10, 2 );
		add_filter( 'beltoft_services_mail_dns_lookup', fn() => array(), 10, 3 );
		add_filter( 'pre_wp_mail', '__return_true' );
	}

	public function tear_down(): void {
		remove_all_filters( 'beltoft_services_mail_client' );
		remove_all_filters( 'beltoft_services_dns_proof_result' );
		remove_all_filters( 'beltoft_services_mail_dns_lookup' );
		remove_all_filters( 'beltoft_services_rest_rate_limit' );
		remove_all_filters( 'pre_wp_mail' );
		delete_option( FakeMailcowClient::OPTION );
		wp_set_current_user( 0 );
		global $wp_rest_server;
		$wp_rest_server = null;

		parent::tear_down();
	}

	/**
	 * Saves the fallback mailbox password setting, encrypted like the settings page does.
	 *
	 * @param string $password Password.
	 */
	private function fallback_password( string $password ): void {
		$this->settings( array( 'hosting_noreply_password' => Crypto::encrypt( $password ) ) );
	}

	/**
	 * A ready account with a ready Email lite domain.
	 *
	 * @param array<string, mixed> $plan Plan overrides.
	 * @return array{0: Resource, 1: Resource}
	 */
	private function account_with_mail( array $plan = array() ): array {
		$r = $this->resource( array_merge( array( 'mail_mailboxes' => 2, 'mail_quota_mb' => 1024, 'mail_aliases' => 3, 'mail_per_minute' => 60, 'site_mail_per_day' => 200 ), $plan ) );
		$this->provision_fully( $r );
		$r->set( array( 'state' => 'ready', 'desired' => 'ready' ) );
		$r = Resource::find( $r->id() );

		$child  = LiteMail::enable( $r, 'shop.example' );
		$result = Registry::get( 'mail' )->provision( Resource::find( $child->id() ) );
		$this->assertTrue( $result->is_done(), $result->error() );
		$child->set( array_merge( $result->meta(), array( 'state' => 'ready', 'desired' => 'ready' ) ) );

		return array( $r, Resource::find( $child->id() ) );
	}

	public function test_the_system_mailbox_gets_the_plan_cap_a_random_password_and_the_agent_job(): void {
		list( $r, $child ) = $this->account_with_mail();

		$done = ( new SiteMail( $r ) )->enable_system_mailbox();

		$this->assertSame( array( 'mail_sender' => array( 'mode' => 'system', 'address' => 'wordpress@shop.example' ) ), $done );
		$box = $this->mailcow->mailbox( 'wordpress@shop.example' );
		$this->assertNotNull( $box );
		$this->assertSame( array( 'value' => 200, 'frame' => 'd' ), $box['rl'], 'The plan cap per day, not the global 500.' );

		$smtp = $this->agent->calls_of( 'mail.smtp' );
		$this->assertCount( 1, $smtp );
		$this->assertSame( 'mail.example.net', $smtp[0]['args']['host'] );
		$this->assertSame( 'wordpress@shop.example', $smtp[0]['args']['user'] );
		$this->assertSame( '***', $smtp[0]['args']['password'] );
		$this->assertSame( 'wordpress@shop.example', $this->agent->state()['smtp'][1000]['user'] );

		// The password is encrypted at rest and appears nowhere in logs or state.
		$fresh  = Resource::find( $r->id() );
		$secret = (string) $fresh->get( 'smtp_secret' );
		$plain  = (string) Crypto::decrypt( $secret );
		$this->assertGreaterThanOrEqual( 16, strlen( $plain ) );
		$this->assertStringNotContainsString( $plain, $secret );
		$this->assertStringNotContainsString( $plain, (string) wp_json_encode( $this->agent->calls() ) );
		$this->assertStringNotContainsString( $plain, (string) wp_json_encode( $this->agent->state() ) );
		$this->assertSame( array( 'mode' => 'system', 'address' => 'wordpress@shop.example' ), $fresh->get( 'mail_sender' ) );
	}

	public function test_running_it_again_keeps_one_mailbox_and_the_same_password(): void {
		list( $r ) = $this->account_with_mail();
		( new SiteMail( $r ) )->enable_system_mailbox();
		$first = (string) Resource::find( $r->id() )->get( 'smtp_secret' );

		$again = ( new SiteMail( Resource::find( $r->id() ) ) )->enable_system_mailbox();

		$this->assertSame( 'system', $again['mail_sender']['mode'] );
		$this->assertSame( $first, (string) Resource::find( $r->id() )->get( 'smtp_secret' ) );
		$this->assertCount( 1, array_filter( array_keys( $this->mailcow_mailboxes() ), static fn( $e ) => 'wordpress@shop.example' === $e ) );
		$this->assertCount( 2, $this->agent->calls_of( 'mail.smtp' ), 'A new job each time (the agent keeps the last).' );
	}

	/**
	 * Mailboxes of the domain on the fake mail server.
	 *
	 * @return array<string, mixed>
	 */
	private function mailcow_mailboxes(): array {
		$out = array();

		foreach ( (array) $this->mailcow->list_mailboxes( 'shop.example' ) as $row ) {
			$out[ $row['username'] ] = $row;
		}

		return $out;
	}

	public function test_the_system_mailbox_is_not_counted_listed_or_changed_by_the_customer(): void {
		list( $r, $child ) = $this->account_with_mail( array( 'mail_mailboxes' => 2 ) );
		( new SiteMail( $r ) )->enable_system_mailbox();
		$child = Resource::find( $child->id() );

		$this->assertSame( 'wordpress@shop.example', MailGuard::system_address( $child ) );
		$this->assertSame( '3', (string) $this->mailcow->domain( 'shop.example' )['mailboxes'], 'The domain has room for the plan and the system mailbox.' );

		$this->assertSame( array(), Mailboxes::all( $child ), 'Not listed.' );
		$this->assertFalse( is_wp_error( Mailboxes::create( $child, 'info', 'Info' ) ) );
		$this->assertFalse( is_wp_error( Mailboxes::create( $child, 'sales', 'Sales' ) ), 'The plan has 2 mailboxes: both fit next to the system one.' );
		$third = Mailboxes::create( $child, 'third', 'Third' );
		$this->assertWPError( $third );
		$this->assertSame( 'limit', $third->get_error_code() );

		$this->assertSame( array( 'info@shop.example', 'sales@shop.example' ), array_column( Mailboxes::all( $child ), 'email' ) );
		$this->assertSame( 2, MailView::data( $child )['counts']['mailboxes'] );

		$this->assertSame( 'invalid', Mailboxes::delete( $child, 'wordpress@shop.example' )->get_error_code() );
		$this->assertSame( 'invalid', Mailboxes::reset_password( $child, 'wordpress@shop.example' )->get_error_code() );
		$this->assertNotNull( $this->mailcow->mailbox( 'wordpress@shop.example' ), 'Still there.' );

		$this->assertTrue( Mailboxes::delete( $child, 'info@shop.example' ) );
		$this->assertSame( 1, (int) Resource::find( $child->id() )->get( 'mail_boxes' ), 'The count of the customer does not include the system mailbox.' );
	}

	public function test_a_plan_change_keeps_the_room_for_the_system_mailbox(): void {
		list( $r, $child ) = $this->account_with_mail( array( 'mail_mailboxes' => 2 ) );
		( new SiteMail( $r ) )->enable_system_mailbox();
		$child = Resource::find( $child->id() );
		$child->set( array( 'plan' => array_merge( (array) $child->get( 'plan' ), array( 'mailboxes' => 5 ) ) ) );

		$result = Registry::get( 'mail' )->update_plan( Resource::find( $child->id() ) );

		$this->assertTrue( $result->is_done(), $result->error() );
		$this->assertSame( '6', (string) $this->mailcow->domain( 'shop.example' )['mailboxes'] );
	}

	public function test_without_ready_email_lite_nothing_is_made(): void {
		$r = $this->resource( array( 'mail_mailboxes' => 2 ) );
		$this->provision_fully( $r );
		$r->set( array( 'state' => 'ready', 'desired' => 'ready' ) );
		$r = Resource::find( $r->id() );

		$none = ( new SiteMail( $r ) )->enable_system_mailbox();
		$this->assertTrue( $none->is_failed() );

		$child = LiteMail::enable( $r, 'later.example' );
		$this->assertTrue( ( new SiteMail( Resource::find( $r->id() ) ) )->enable_system_mailbox()->is_failed(), 'The child is not ready yet.' );
		$this->assertNull( $this->mailcow->mailbox( 'wordpress@later.example' ) );
		$this->assertNotNull( $child );
		$this->assertCount( 0, $this->agent->calls_of( 'mail.smtp' ) );
	}

	public function test_a_mail_server_error_is_a_retry_and_nothing_is_recorded_as_done(): void {
		list( $r ) = $this->account_with_mail();
		$this->mailcow->fail_next( 'add_mailbox', new \WP_Error( 'mailcow_transport', 'down' ) );

		$result = ( new SiteMail( $r ) )->enable_system_mailbox();

		$this->assertTrue( $result->is_retry() );
		$this->assertNull( Resource::find( $r->id() )->get( 'mail_sender' ) );
		$this->assertCount( 0, $this->agent->calls_of( 'mail.smtp' ) );

		$ok = ( new SiteMail( Resource::find( $r->id() ) ) )->enable_system_mailbox();
		$this->assertSame( 'system', $ok['mail_sender']['mode'] );
	}

	public function test_a_rate_limit_error_is_a_retry_too(): void {
		list( $r ) = $this->account_with_mail();
		$this->mailcow->fail_next( 'set_mailbox_rate_limit', new \WP_Error( 'mailcow_transport', 'down' ) );

		$this->assertTrue( ( new SiteMail( $r ) )->enable_system_mailbox()->is_retry() );
	}

	// -- The fallback sender --------------------------------------------------------------------

	public function test_websites_without_email_lite_send_from_noreply_on_the_preview_zone(): void {
		$this->fallback_password( 'fallback-mailbox-pass-1' );
		$r = $this->resource();
		$this->provision_fully( $r );

		$this->assertSame( 'noreply@preview.example.test', SiteMail::fallback_address() );
		$smtp = $this->agent->calls_of( 'mail.smtp' );
		$this->assertCount( 1, $smtp );
		$this->assertSame( 'noreply@preview.example.test', $smtp[0]['args']['user'] );
		$this->assertSame( '***', $smtp[0]['args']['password'] );
		$this->assertStringNotContainsString( 'fallback-mailbox-pass-1', (string) wp_json_encode( $this->agent->state() ) );
		$this->assertSame( array( 'mode' => 'fallback', 'address' => 'noreply@preview.example.test' ), Resource::find( $r->id() )->get( 'mail_sender' ) );
	}

	public function test_the_fallback_is_skipped_quietly_without_a_password_a_zone_or_a_mail_host(): void {
		$r = $this->resource();
		$this->provision_fully( $r );
		$this->assertSame( '', SiteMail::fallback_address() );
		$this->assertCount( 0, $this->agent->calls_of( 'mail.smtp' ), 'No password: nothing is sent and provisioning still finishes.' );

		$this->fallback_password( 'fallback-mailbox-pass-1' );
		$this->settings( array( 'mail_host' => '' ) );
		$other = $this->resource();
		$this->provision_fully( $other );
		$this->assertCount( 0, $this->agent->calls_of( 'mail.smtp' ), 'No mail host.' );

		$this->settings( array( 'mail_host' => 'mail.example.net', 'hosting_preview_zone' => '' ) );
		$this->assertSame( '', SiteMail::fallback_address(), 'No preview zone.' );
	}

	public function test_the_fallback_password_is_a_secret_setting_never_stored_in_clear(): void {
		$field = Settings::fields()['hosting_noreply_password'];
		$this->assertSame( 'secret', $field['type'] );

		$clean = Settings::sanitize( array( 'hosting_noreply_password' => 'a-clear-password-0001' ) );
		$this->assertStringStartsWith( 'bs1:', $clean['hosting_noreply_password'] );
		$this->assertStringNotContainsString( 'a-clear-password', $clean['hosting_noreply_password'] );
		update_option( Settings::OPTION, $clean );
		$this->assertSame( 'a-clear-password-0001', SiteMail::fallback_password() );
	}

	// -- The account action and route -----------------------------------------------------------

	public function test_the_account_action_and_route_switch_the_websites_to_the_email_lite_sender(): void {
		list( $r, $child ) = $this->account_with_mail();
		$a = new HostingActions( Resource::find( $r->id() ) );

		$out = $a->use_email_for_sites();
		$this->assertSame( array( 'sender' => 'wordpress@shop.example' ), $out );

		$user = (int) $r->license()['user_id'];
		wp_set_current_user( $user );
		$request = new \WP_REST_Request( 'POST', '/' . Routes::NAMESPACE . '/resources/' . $r->id() . '/hosting/mail/sites' );
		$response = rest_get_server()->dispatch( $request );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'wordpress@shop.example', $response->get_data()['sender'] );

		$plain = $this->resource();
		$this->provision_fully( $plain );
		$plain->set( array( 'state' => 'ready', 'desired' => 'ready' ) );
		$err = ( new HostingActions( Resource::find( $plain->id() ) ) )->use_email_for_sites();
		$this->assertWPError( $err );
		$this->assertSame( 'not_ready', $err->get_error_code() );
	}

	public function test_the_new_strings_have_no_dash_or_middle_dot(): void {
		$files = array( 'SiteMail.php', 'HostingSettings.php', 'HostingActions.php' );

		foreach ( $files as $file ) {
			$text = (string) file_get_contents( BELTOFT_SERVICES_PATH . 'src/Hosting/' . $file );
			preg_match_all( "/__\\(\\s*'([^']*)'/", $text, $m );

			foreach ( $m[1] as $string ) {
				$this->assertStringNotContainsString( "\u{2014}", $string, $file );
				$this->assertStringNotContainsString( "\u{00B7}", $string, $file );
			}
		}

		$this->assertSame( MailDriver::ID, Registry::get( 'mail' )->id() );
	}
}

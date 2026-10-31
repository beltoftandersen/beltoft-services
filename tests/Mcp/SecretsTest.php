<?php
/**
 * The tools that show a secret: only with Full access and the customer's confirmation, once, with a history line and an
 * e-mail, and never a secret in the result of any other tool.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Mcp;

use BeltoftLicenses\Events;
use BeltoftServices\Api\Tokens;
use BeltoftServices\Crypto;
use BeltoftServices\Mcp\ActionMap;
use BeltoftServices\Mcp\Secrets;
use BeltoftServices\Mcp\Tools;
use BeltoftServices\Proxmox\Access;
use BeltoftServices\Resource;

require_once __DIR__ . '/McpTestCase.php';

/**
 * Secret tool tests.
 */
final class SecretsTest extends McpTestCase {

	private const SERVER_PASSWORD = 'ServerPw-Plain-93f1Zk';

	/**
	 * Mails caught.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private $mails = array();

	public function set_up(): void {
		parent::set_up();
		add_filter( 'beltoft_services_mcp_rate_limit', '__return_zero' );
		add_filter( 'pre_wp_mail', array( $this, 'catch_mail' ), 10, 2 );
		$this->mails = array();
	}

	public function tear_down(): void {
		remove_filter( 'pre_wp_mail', array( $this, 'catch_mail' ), 10 );
		parent::tear_down();
	}

	/**
	 * Records a mail instead of sending it.
	 *
	 * @param mixed                $pre  Short-circuit.
	 * @param array<string, mixed> $atts Mail.
	 */
	public function catch_mail( $pre, $atts ) {
		$this->mails[] = $atts;

		return true;
	}

	/**
	 * A server that has a password waiting to be revealed.
	 */
	private function server_with_password(): Resource {
		$r = $this->server();
		$r->set( array( Access::META_REVEAL => Crypto::encrypt( self::SERVER_PASSWORD ), Access::META_UNTIL => time() + DAY_IN_SECONDS ) );
		$this->email_to( $r );

		return Resource::find( $r->id() );
	}

	/**
	 * @return string[]
	 */
	private function history( Resource $r ): array {
		return array_column( Events::for_license( (int) $r->license()['id'] ), 'message' );
	}

	/**
	 * @return string The text of every mail caught, with its subject and recipient.
	 */
	private function mail_text(): string {
		return (string) wp_json_encode( $this->mails );
	}

	public function test_a_server_password_is_shown_once_after_the_confirmation_and_is_recorded(): void {
		$r     = $this->server_with_password();
		$user  = $this->owner( $r );
		$full  = $this->oauth( $user, Tokens::FULL );
		$args  = array( 'id' => $r->id() );

		// The first call describes: no password in it, nothing taken, no mail.
		$first = $this->json( $this->tool( 'reveal_server_password', $args, $full ) );
		$this->assertTrue( $first['requires_confirmation'] );
		$this->assertStringContainsString( 'shown once', $first['summary'] );
		$this->assertStringContainsString( 'in this conversation', $first['summary'] );
		$this->assertStringNotContainsString( self::SERVER_PASSWORD, wp_json_encode( $first ) );
		$this->assertTrue( Access::revealable( Resource::find( $r->id() ) ), 'Nothing was taken.' );
		$this->assertSame( array(), $this->mails );
		$this->assertSame( 120, $first['expires_in_seconds'], 'A secret confirmation lives two minutes.' );

		// With the token: the password, once, with what to do next.
		$done = $this->json( $this->tool( 'reveal_server_password', $args + array( 'confirm' => $first['confirm'] ), $full ) );
		$this->assertSame( self::SERVER_PASSWORD, $done['password'] );
		$this->assertTrue( $done['shown_once'] );
		$this->assertStringContainsString( 'now in this conversation', $done['note'] );
		$this->assertStringContainsString( 'reset_server_password', $done['note'] );
		$this->assertFalse( Access::revealable( Resource::find( $r->id() ) ), 'The stored value is wiped, as the dashboard does.' );

		// History: what happened, never the value.
		$history = $this->history( $r );
		$this->assertContains( 'Claude: server password shown', $history );
		$this->assertContains( 'Claude: asked to reveal server password (waiting for confirmation)', $history );
		$this->assertContains( 'Claude: reveal server password confirmed by the customer', $history );
		$this->assertStringNotContainsString( self::SERVER_PASSWORD, wp_json_encode( $history ) );

		// The customer is told, by e-mail, at their own address.
		$this->assertCount( 1, $this->mails );
		$this->assertSame( 'christian.beltoft@gmail.com', $this->mails[0]['to'] );
		$this->assertSame( 'A connected app showed your server password', $this->mails[0]['subject'] );
		$body = (string) preg_replace( '/\s+/', ' ', (string) $this->mails[0]['message'] );
		$this->assertStringContainsString( 'showed the password of your server', $body );
		$this->assertStringContainsString( 'If this was not you, disconnect the app', $body );
		$this->assertStringContainsString( 'It is now in your conversation with the app', $body );
		$this->assertStringNotContainsString( self::SERVER_PASSWORD, $this->mail_text() );

		// Once: there is nothing left to show, and no token is made for it.
		$again = $this->tool( 'reveal_server_password', $args, $full );
		$this->assertTrue( $again['isError'] );
		$this->assertStringContainsString( 'no password to show', $this->text( $again ) );
		$this->assertStringNotContainsString( self::SERVER_PASSWORD, $this->text( $again ) );
		$this->assertCount( 1, $this->mails );
	}

	public function test_a_password_taken_in_the_dashboard_meanwhile_is_not_shown_again(): void {
		$r     = $this->server_with_password();
		$user  = $this->owner( $r );
		$full  = $this->oauth( $user, Tokens::FULL );
		$args  = array( 'id' => $r->id() );
		$first = $this->json( $this->tool( 'reveal_server_password', $args, $full ) );

		$this->assertSame( self::SERVER_PASSWORD, Access::take_reveal( Resource::find( $r->id() ), $user ) );

		$late = $this->tool( 'reveal_server_password', $args + array( 'confirm' => $first['confirm'] ), $full );
		$this->assertTrue( $late['isError'] );
		$this->assertStringContainsString( 'no password to show', $this->text( $late ) );
		$this->assertSame( array(), $this->mails, 'Nothing was shown, so nobody is told it was.' );
	}

	public function test_only_full_access_and_only_the_owner_can_reveal(): void {
		$r     = $this->server_with_password();
		$user  = $this->owner( $r );
		$args  = array( 'id' => $r->id() );

		foreach ( array( $this->oauth( $user, Tokens::READ ), $this->oauth( $user, Tokens::ACTIONS ), $this->personal( $user, Tokens::ACTIONS ) ) as $low ) {
			foreach ( array( 'reveal_server_password', 'reveal_database_password', 'reveal_app_login', 'get_database_ca_certificate' ) as $name ) {
				$result = $this->tool( $name, $args, $low );
				$this->assertTrue( $result['isError'], $name );
				$this->assertStringNotContainsString( self::SERVER_PASSWORD, $this->text( $result ) );
			}
		}

		$thief = $this->oauth( self::factory()->user->create(), Tokens::FULL );
		$stolen = $this->tool( 'reveal_server_password', $args, $thief );
		$this->assertTrue( $stolen['isError'] );
		$this->assertStringNotContainsString( self::SERVER_PASSWORD, $this->text( $stolen ) );
		$this->assertTrue( Access::revealable( Resource::find( $r->id() ) ) );
		$this->assertSame( array(), $this->mails );
	}

	public function test_the_database_password_and_the_ca_certificate(): void {
		$db = $this->database();
		$this->email_to( $db );
		$user = $this->owner( $db );
		$full = $this->oauth( $user, Tokens::FULL );
		$args = array( 'id' => $db->id() );

		$first = $this->json( $this->tool( 'reveal_database_password', $args, $full ) );
		$this->assertStringContainsString( 'Show the password of database', $first['summary'] );
		$this->assertStringNotContainsString( self::PASSWORD, wp_json_encode( $first ) );

		$done = $this->json( $this->tool( 'reveal_database_password', $args + array( 'confirm' => $first['confirm'] ), $full ) );
		$this->assertSame( self::PASSWORD, $done['password'] );
		$this->assertStringContainsString( 'reset_database_password', $done['note'] );
		$this->assertContains( 'Claude: database password shown', $this->history( $db ) );
		$this->assertCount( 1, $this->mails );
		$this->assertSame( 'A connected app showed your database password', $this->mails[0]['subject'] );
		$this->assertTrue( $this->tool( 'reveal_database_password', $args, $full )['isError'], 'Once.' );

		// The certificate is public: shown on the same terms, noted, and no e-mail about it.
		$ca = $this->json( $this->tool( 'get_database_ca_certificate', $args, $full ) );
		$this->assertStringContainsString( 'public certificate', $ca['summary'] );
		$shown = $this->json( $this->tool( 'get_database_ca_certificate', $args + array( 'confirm' => $ca['confirm'] ), $full ) );
		$this->assertSame( self::PEM, $shown['certificate'] );
		$this->assertContains( 'Claude: database CA certificate shown', $this->history( $db ) );
		$this->assertCount( 1, $this->mails );

		// A server is not a database.
		$server = $this->server( $user );
		$this->assertTrue( $this->tool( 'get_database_ca_certificate', array( 'id' => $server->id() ), $full )['isError'] );
		$this->assertTrue( $this->tool( 'reveal_database_password', array( 'id' => $server->id() ), $full )['isError'] );
	}

	public function test_the_first_login_of_an_app(): void {
		$r = $this->server();
		$this->email_to( $r );
		$r->set( array( 'app_reveal' => Crypto::encrypt( (string) wp_json_encode( array( 'username' => 'admin', 'password' => 'AppPw-77xQ' ) ) ), 'app_reveal_until' => time() + DAY_IN_SECONDS ) );
		$full  = $this->oauth( $this->owner( $r ), Tokens::FULL );
		$args  = array( 'id' => $r->id() );
		$first = $this->json( $this->tool( 'reveal_app_login', $args, $full ) );
		$this->assertStringNotContainsString( 'AppPw-77xQ', wp_json_encode( $first ) );

		$done = $this->json( $this->tool( 'reveal_app_login', $args + array( 'confirm' => $first['confirm'] ), $full ) );
		$this->assertSame( 'AppPw-77xQ', $done['login']['password'] );
		$this->assertContains( 'Claude: app login shown', $this->history( $r ) );
		$this->assertSame( 'A connected app showed your app login', $this->mails[0]['subject'] );
		$this->assertStringNotContainsString( 'AppPw-77xQ', $this->mail_text() );
		$this->assertTrue( $this->tool( 'reveal_app_login', $args, $full )['isError'], 'Once.' );
	}

	public function test_no_other_tool_ever_returns_a_secret(): void {
		$server = $this->server_with_password();
		$user   = $this->owner( $server );
		$db     = $this->database( $user );
		$server->set( array( 'app_reveal' => Crypto::encrypt( 'AppSecret-zz' ), 'app_reveal_until' => time() + DAY_IN_SECONDS ) );
		$full    = $this->oauth( $user, Tokens::FULL );
		$secrets = array( self::SERVER_PASSWORD, self::PASSWORD, 'AppSecret-zz', 'BEGIN CERTIFICATE', 'MIIBszCCAVmg', (string) Resource::find( $server->id() )->get( Access::META_REVEAL ), (string) Resource::find( $db->id() )->get( 'db_reveal' ) );

		foreach ( Tools::definitions() as $name => $tool ) {
			if ( in_array( $name, Secrets::NAMES, true ) ) {
				continue;
			}

			foreach ( array( $server->id(), $db->id() ) as $id ) {
				$text = $this->text( $this->tool( $name, $this->dummy( $tool['schema'], $id ), $full ) );

				foreach ( $secrets as $secret ) {
					$this->assertStringNotContainsString( $secret, $text, $name . ' on ' . $id . ' returned a secret.' );
				}
			}
		}

		// The service views in particular.
		foreach ( array( 'service_details', 'server_status', 'database_info', 'list_services' ) as $name ) {
			$text = $this->text( $this->tool( $name, 'list_services' === $name ? array() : array( 'id' => $db->id() ), $full ) );

			foreach ( $secrets as $secret ) {
				$this->assertStringNotContainsString( $secret, $text, $name );
			}
		}

		// Nor does the summary of a tool that shows one, and nor does the history of the services.
		$this->assertStringNotContainsString( self::SERVER_PASSWORD, $this->text( $this->tool( 'reveal_server_password', array( 'id' => $server->id() ), $full ) ) );

		foreach ( array( $server, $db ) as $r ) {
			foreach ( $secrets as $secret ) {
				$this->assertStringNotContainsString( $secret, wp_json_encode( $this->history( $r ) ) );
			}
		}
	}

	public function test_a_domain_transfer_code_and_unlock_cannot_be_reached_at_any_level(): void {
		$user   = self::factory()->user->create();
		$domain = $this->domain( $user );

		foreach ( array( Tokens::READ, Tokens::ACTIONS, Tokens::FULL ) as $level ) {
			$token  = $this->oauth( $user, $level );
			$listed = (string) wp_json_encode( array_column( $this->rpc( 'tools/list', array(), $token )['result']['tools'], 'name' ) );
			$this->assertDoesNotMatchRegularExpression( '/transfer|authcode|auth_code|unlock|epp|ticket/', $listed, $level );

			foreach ( array( 'get_transfer_code', 'unlock_domain', 'domain_authcode', 'reveal_transfer_code', 'transfer_domain', 'console_ticket' ) as $name ) {
				$this->assertSame( -32602, $this->rpc( 'tools/call', array( 'name' => $name, 'arguments' => array( 'id' => $domain->id() ) ), $token )['error']['code'], $name . ' at ' . $level );
			}

			$result = $this->tool( 'domain_settings', array( 'id' => $domain->id(), 'action' => 'unlock' ), $token );
			$this->assertTrue( $result['isError'], $level );
			$this->assertTrue( $this->tool( 'domain_settings', array( 'id' => $domain->id(), 'action' => 'lock', 'confirm' => 'bsc_x', 'raw_action' => 'unlock' ), $token )['isError'] );
		}

		// And no route or action of any tool leads there.
		foreach ( array_keys( Tools::definitions() ) as $tool ) {
			$this->assertFalse( ActionMap::permits( $tool, 'POST', '/resources/' . $domain->id() . '/domain-authcode' ), $tool );
			$this->assertFalse( ActionMap::permits( $tool, 'POST', '/resources/' . $domain->id() . '/actions/unlock' ), $tool );
			$this->assertFalse( ActionMap::permits( $tool, 'POST', '/resources/' . $domain->id() . '/console' ), $tool );
		}

		$this->assertArrayNotHasKey( 'unlock', ActionMap::ACTIONS );
		$this->assertArrayHasKey( 'domain_transfer_code', ActionMap::CAPABILITIES_EXCLUDED );
	}
}

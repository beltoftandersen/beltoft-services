<?php
/**
 * The email domain through API tokens and the MCP tools: what is reachable at which level, the confirmations, and that a
 * password is shown only by the one tool that is for it.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Mail;

use BeltoftLicenses\Events;
use BeltoftServices\Api\Tokens;
use BeltoftServices\Mail\MailAccess;
use BeltoftServices\Mcp\ActionMap;
use BeltoftServices\Mcp\Definitions;
use BeltoftServices\Mcp\Secrets;
use BeltoftServices\Resource;
use BeltoftServices\Tests\Mcp\CallsTools;

require_once __DIR__ . '/MailTestCase.php';
require_once dirname( __DIR__ ) . '/Mcp/CallsTools.php';

final class MailToolsTest extends MailTestCase {

	use CallsTools;

	public function set_up(): void {
		parent::set_up();

		$this->mcp_set_up();
		$this->settings( array( 'mail_allow_external_forward' => 1 ) );
	}

	public function tear_down(): void {
		$this->mcp_tear_down();
		parent::tear_down();
	}

	/**
	 * A ready email domain of a new customer, with the customer's own e-mail address on the license.
	 *
	 * @return array{0: Resource, 1: int}
	 */
	private function domain( string $name = 'tool.example' ): array {
		$user = self::factory()->user->create();
		$r    = $this->ready_mail( $name, array(), $user );
		\BeltoftLicenses\Licenses::update( (int) $r->license()['id'], array( 'email' => 'christian.beltoft@gmail.com' ) );

		return array( Resource::find( $r->id() ), $user );
	}

	/** @return string[] */
	private function history( Resource $r ): array {
		return array_column( Events::for_license( (int) $r->license()['id'] ), 'message' );
	}

	private function dispatch( string $method, string $route, int $user, string $password, array $params = array() ): \WP_REST_Response {
		wp_set_current_user( 0 );
		$_SERVER['PHP_AUTH_USER'] = (string) get_userdata( $user )->user_login;
		$_SERVER['PHP_AUTH_PW']   = $password;
		wp_set_current_user( (int) apply_filters( 'determine_current_user', false ) );
		$request = new \WP_REST_Request( $method, '/beltoft-services/v1/resources/' . $route );

		if ( in_array( $method, array( 'GET', 'DELETE' ), true ) ) {
			$request->set_query_params( $params );
		} else {
			$request->set_header( 'content-type', 'application/json' );
			$request->set_body( (string) wp_json_encode( $params ) );
		}

		return rest_get_server()->dispatch( $request );
	}

	// -- API tokens ------------------------------------------------------------------------------

	public function test_a_token_reads_and_makes_things_but_cannot_delete_a_mailbox_change_or_show_a_password(): void {
		list( $r, $user ) = $this->domain();
		list( , $actions ) = $this->personal( $user, Tokens::ACTIONS );
		list( , $read )    = $this->personal( $user, Tokens::READ );
		$id                = $r->id();

		$this->assertSame( 200, $this->dispatch( 'GET', $id . '/mail', $user, $read )->get_status() );
		$this->assertSame( 403, $this->dispatch( 'POST', $id . '/mail/mailboxes', $user, $read, array( 'local' => 'a' ) )->get_status(), 'Read cannot make one.' );

		$made = $this->dispatch( 'POST', $id . '/mail/mailboxes', $user, $actions, array( 'local' => 'a' ) );
		$this->assertSame( 201, $made->get_status() );
		$this->assertArrayNotHasKey( 'password', $made->get_data() );
		$this->assertSame( 201, $this->dispatch( 'POST', $id . '/mail/aliases', $user, $actions, array( 'address' => 'x', 'goto' => array( 'a@tool.example' ) ) )->get_status() );
		$this->assertSame( 200, $this->dispatch( 'POST', $id . '/mail/dns/check', $user, $actions )->get_status() );
		$this->assertSame( 200, $this->dispatch( 'DELETE', $id . '/mail/aliases/x%40tool.example', $user, $actions )->get_status() );

		$this->assertSame( 403, $this->dispatch( 'DELETE', $id . '/mail/mailboxes/a%40tool.example', $user, $actions, array( 'confirm' => 'DELETE' ) )->get_status() );
		$this->assertSame( 403, $this->dispatch( 'POST', $id . '/mail/mailboxes/a%40tool.example/password', $user, $actions )->get_status() );
		$this->assertSame( 403, $this->dispatch( 'POST', $id . '/mail-reveal', $user, $actions, array( 'email' => 'a@tool.example' ) )->get_status() );
		$this->assertNotNull( $this->mailcow->mailbox( 'a@tool.example' ) );
		$this->assertTrue( MailAccess::revealable( Resource::find( $id ), 'a@tool.example' ), 'Nothing was shown.' );
	}

	public function test_the_token_gate_names_cover_delete_and_password_actions(): void {
		$blocked = ( new \ReflectionClassConstant( Tokens::class, 'BLOCKED_ACTION' ) )->getValue();

		foreach ( array( 'mail_delete_mailbox', 'delete_mailbox', 'mail_reset_password', 'reset_mailbox_password', 'reveal_mailbox_password' ) as $name ) {
			$this->assertSame( 1, preg_match( $blocked, $name ), $name );
		}

		foreach ( array( '/resources/5/mail/mailboxes/a@b.example', '/resources/5/mail/mailboxes/a@b.example/password', '/resources/5/mail-reveal' ) as $path ) {
			$this->assertFalse( Tokens::reachable( '/beltoft-services/v1' . $path ), $path );
		}

		foreach ( array( '/resources/5/mail', '/resources/5/mail/mailboxes', '/resources/5/mail/aliases', '/resources/5/mail/aliases/a@b.example', '/resources/5/mail/dns/check' ) as $path ) {
			$this->assertTrue( Tokens::reachable( '/beltoft-services/v1' . $path ), $path );
		}
	}

	// -- The map -----------------------------------------------------------------------------------

	public function test_the_tools_are_in_the_map_at_the_right_levels(): void {
		$tools = Definitions::all();

		$this->assertSame( Tokens::READ, $tools['email_domain_info']['level'] );

		foreach ( array( 'create_mailbox', 'reset_mailbox_password', 'add_email_alias', 'remove_email_alias', 'email_dns_check' ) as $name ) {
			$this->assertSame( Tokens::ACTIONS, $tools[ $name ]['level'], $name );
			$this->assertFalse( $tools[ $name ]['confirm'], $name );
		}

		foreach ( array( 'delete_mailbox', 'reveal_mailbox_password' ) as $name ) {
			$this->assertSame( Tokens::FULL, $tools[ $name ]['level'], $name );
			$this->assertTrue( $tools[ $name ]['confirm'], $name );
			$this->assertSame( $name, ActionMap::CAPABILITIES[ $name ] );
		}

		$this->assertTrue( $tools['reveal_mailbox_password']['secret'] );
		$this->assertContains( 'reveal_mailbox_password', Secrets::NAMES );

		// A route belongs to the one tool it is named for.
		$routes = array(
			'email_domain_info'       => array( 'GET', '/resources/1/mail' ),
			'create_mailbox'          => array( 'POST', '/resources/1/mail/mailboxes' ),
			'reset_mailbox_password'  => array( 'POST', '/resources/1/mail/mailboxes/a@b.example/password' ),
			'delete_mailbox'          => array( 'DELETE', '/resources/1/mail/mailboxes/a@b.example' ),
			'add_email_alias'         => array( 'POST', '/resources/1/mail/aliases' ),
			'remove_email_alias'      => array( 'DELETE', '/resources/1/mail/aliases/a@b.example' ),
			'email_dns_check'         => array( 'POST', '/resources/1/mail/dns/check' ),
			'reveal_mailbox_password' => array( 'POST', '/resources/1/mail-reveal' ),
		);

		foreach ( $routes as $owner => $route ) {
			foreach ( array_keys( $tools ) as $tool ) {
				$this->assertSame( $tool === $owner, ActionMap::permits( $tool, $route[0], $route[1] ), $tool . ' and ' . $route[1] );
			}
		}
	}

	public function test_levels_decide_who_sees_which_email_tool(): void {
		$read    = array_column( \BeltoftServices\Mcp\Tools::listing( Tokens::READ ), 'name' );
		$actions = array_column( \BeltoftServices\Mcp\Tools::listing( Tokens::ACTIONS ), 'name' );
		$full    = array_column( \BeltoftServices\Mcp\Tools::listing( Tokens::FULL ), 'name' );

		$this->assertContains( 'email_domain_info', $read );
		$this->assertNotContains( 'create_mailbox', $read );
		$this->assertContains( 'create_mailbox', $actions );
		$this->assertNotContains( 'delete_mailbox', $actions );
		$this->assertNotContains( 'reveal_mailbox_password', $actions );
		$this->assertContains( 'delete_mailbox', $full );
		$this->assertContains( 'reveal_mailbox_password', $full );
	}

	// -- Reads and everyday actions ----------------------------------------------------------------

	public function test_email_domain_info_lists_the_domain_without_a_password(): void {
		list( $r, $user ) = $this->domain();
		$read             = $this->oauth( $user, Tokens::READ );
		$actions          = $this->oauth( $user, Tokens::ACTIONS );

		$this->tool( 'create_mailbox', array( 'id' => $r->id(), 'name' => 'info', 'display_name' => 'Info' ), $actions );

		$data = $this->json( $this->tool( 'email_domain_info', array( 'id' => $r->id() ), $read ) );

		$this->assertSame( 'tool.example', $data['domain'] );
		$this->assertSame( 'info@tool.example', $data['mailboxes'][0]['email'] );
		$this->assertArrayNotHasKey( 'delete_word', $data );
		$this->assertStringContainsString( 'data from the customer', Definitions::all()['email_domain_info']['description'] );

		$stored = (array) Resource::find( $r->id() )->get( MailAccess::META );
		$plain  = (string) \BeltoftServices\Crypto::decrypt( (string) $stored['info@tool.example']['pw'] );
		$this->assertStringNotContainsString( $plain, wp_json_encode( $data ) );

		$list = $this->json( $this->tool( 'list_services', array( 'type' => 'mail' ), $read ) );
		$this->assertSame( array( $r->id() ), array_column( $list['services'], 'id' ) );
	}

	public function test_the_everyday_tools_do_what_the_dashboard_does_and_leave_a_trail(): void {
		list( $r, $user ) = $this->domain();
		$actions          = $this->oauth( $user, Tokens::ACTIONS );
		$read             = $this->oauth( $user, Tokens::READ );
		$id               = $r->id();

		$this->assertTrue( $this->tool( 'create_mailbox', array( 'id' => $id, 'name' => 'a' ), $read )['isError'], 'Read cannot.' );

		$made = $this->json( $this->tool( 'create_mailbox', array( 'id' => $id, 'name' => 'A' ), $actions ) );
		$this->assertSame( 'a@tool.example', $made['created'] );
		$this->assertStringContainsString( 'not shown here', $made['message'] );
		$this->assertNotNull( $this->mailcow->mailbox( 'a@tool.example' ) );
		$this->assertTrue( $this->tool( 'create_mailbox', array( 'id' => $id, 'name' => 'a' ), $actions )['isError'], 'Taken.' );
		$this->assertTrue( $this->tool( 'create_mailbox', array( 'id' => $id, 'name' => 'bad name' ), $actions )['isError'] );

		$reset = $this->json( $this->tool( 'reset_mailbox_password', array( 'id' => $id, 'email' => 'a@tool.example' ), $actions ) );
		$this->assertStringContainsString( 'not shown here', $reset['message'] );
		$this->assertTrue( MailAccess::revealable( Resource::find( $id ), 'a@tool.example' ) );

		$alias = $this->json( $this->tool( 'add_email_alias', array( 'id' => $id, 'name' => 'sales', 'deliver_to' => array( 'a@tool.example' ) ), $actions ) );
		$this->assertSame( 'sales@tool.example', $alias['created'] );
		$this->assertSame( 'sales@tool.example', $this->json( $this->tool( 'remove_email_alias', array( 'id' => $id, 'address' => 'sales@tool.example' ), $actions ) )['deleted'] );

		$dns = $this->json( $this->tool( 'email_dns_check', array( 'id' => $id ), $actions ) );
		$this->assertArrayHasKey( 'rows', $dns );

		$history = $this->history( $r );
		foreach ( array( 'Claude: mailbox created', 'Claude: new mailbox password made', 'Claude: alias created', 'Claude: alias deleted', 'Claude: email DNS checked' ) as $line ) {
			$this->assertContains( $line, $history, $line );
		}

		// Someone else's domain is refused, and nothing is touched.
		$thief = $this->oauth( self::factory()->user->create(), Tokens::ACTIONS );
		$this->assertTrue( $this->tool( 'create_mailbox', array( 'id' => $id, 'name' => 'x' ), $thief )['isError'] );
		$this->assertTrue( $this->tool( 'email_domain_info', array( 'id' => $id ), $thief )['isError'] );
		$this->assertNull( $this->mailcow->mailbox( 'x@tool.example' ) );
	}

	// -- Delete, with the customer's yes -----------------------------------------------------------------

	public function test_delete_mailbox_needs_full_access_and_the_customers_confirmation(): void {
		list( $r, $user ) = $this->domain();
		$id               = $r->id();
		$actions          = $this->oauth( $user, Tokens::ACTIONS );
		$full             = $this->oauth( $user, Tokens::FULL );
		$this->tool( 'create_mailbox', array( 'id' => $id, 'name' => 'a' ), $actions );
		$args = array( 'id' => $id, 'email' => 'a@tool.example' );

		$this->assertTrue( $this->tool( 'delete_mailbox', $args, $actions )['isError'], 'Everyday level cannot.' );
		$this->assertNotNull( $this->mailcow->mailbox( 'a@tool.example' ) );

		$first = $this->json( $this->tool( 'delete_mailbox', $args, $full ) );
		$this->assertTrue( $first['requires_confirmation'] );
		$this->assertStringContainsString( 'Delete the mailbox a@tool.example', $first['summary'] );
		$this->assertStringContainsString( 'for good', $first['summary'] );
		$this->assertNotNull( $this->mailcow->mailbox( 'a@tool.example' ), 'The summary changed nothing.' );

		$done = $this->json( $this->tool( 'delete_mailbox', $args + array( 'confirm' => $first['confirm'] ), $full ) );
		$this->assertSame( 'a@tool.example', $done['deleted'] );
		$this->assertNull( $this->mailcow->mailbox( 'a@tool.example' ) );
		$this->assertContains( 'Claude: mailbox deleted', $this->history( $r ) );
		$this->assertNotEmpty( $this->tool_mails );
		$this->assertStringContainsString( 'deleted the mailbox a@tool.example', (string) end( $this->tool_mails )['message'] );
		$to = (array) end( $this->tool_mails )['to'];
		$this->assertSame( 'christian.beltoft@gmail.com', reset( $to ) );

		$this->assertTrue( $this->tool( 'delete_mailbox', $args + array( 'confirm' => $first['confirm'] ), $full )['isError'], 'A confirmation is used once.' );
	}

	public function test_delete_mailbox_refuses_what_the_dashboard_would_refuse_before_a_token_is_made(): void {
		list( $r, $user ) = $this->domain();
		list( $other )    = $this->domain( 'other.example' );
		$full             = $this->oauth( $user, Tokens::FULL );
		$this->mailcow->add_mailbox( array( 'local_part' => 'a', 'domain' => 'other.example', 'quota' => '100' ) );

		$gone = $this->tool( 'delete_mailbox', array( 'id' => $r->id(), 'email' => 'nobody@tool.example' ), $full );
		$this->assertTrue( $gone['isError'] );
		$this->assertStringContainsString( 'does not exist', $this->text( $gone ) );

		$outside = $this->tool( 'delete_mailbox', array( 'id' => $r->id(), 'email' => 'a@other.example' ), $full );
		$this->assertTrue( $outside['isError'] );
		$this->assertNotNull( $this->mailcow->mailbox( 'a@other.example' ) );

		$this->assertTrue( $this->tool( 'delete_mailbox', array( 'id' => $other->id(), 'email' => 'a@other.example' ), $full )['isError'], 'Not the customer\'s domain.' );
		$this->assertNotNull( $this->mailcow->mailbox( 'a@other.example' ) );
	}

	// -- Show a password, with the customer's yes ----------------------------------------------------------

	public function test_reveal_mailbox_password_is_the_only_way_a_password_reaches_a_tool(): void {
		list( $r, $user ) = $this->domain();
		$id               = $r->id();
		$actions          = $this->oauth( $user, Tokens::ACTIONS );
		$full             = $this->oauth( $user, Tokens::FULL );
		$this->tool( 'create_mailbox', array( 'id' => $id, 'name' => 'a' ), $actions );
		$args  = array( 'id' => $id, 'email' => 'a@tool.example' );
		$stored = (array) Resource::find( $id )->get( MailAccess::META );
		$plain  = (string) \BeltoftServices\Crypto::decrypt( (string) $stored['a@tool.example']['pw'] );

		$this->assertTrue( $this->tool( 'reveal_mailbox_password', $args, $actions )['isError'], 'Everyday level cannot.' );

		$first = $this->json( $this->tool( 'reveal_mailbox_password', $args, $full ) );
		$this->assertStringContainsString( 'Show the password of the mailbox a@tool.example', $first['summary'] );
		$this->assertStringNotContainsString( $plain, wp_json_encode( $first ) );

		$done = $this->json( $this->tool( 'reveal_mailbox_password', $args + array( 'confirm' => $first['confirm'] ), $full ) );
		$this->assertSame( $plain, $done['password'] );
		$this->assertTrue( $done['shown_once'] );
		$this->assertStringContainsString( 'reset_mailbox_password', $done['note'] );
		$this->assertContains( 'Claude: mailbox password shown', $this->history( $r ) );

		$mails = array_filter( $this->tool_mails, static fn( $m ) => 'A connected app showed a mailbox password' === $m['subject'] );
		$this->assertCount( 1, $mails );
		foreach ( $this->tool_mails as $mail ) {
			$this->assertStringNotContainsString( $plain, (string) wp_json_encode( $mail ) );
		}

		$this->assertTrue( $this->tool( 'reveal_mailbox_password', $args, $full )['isError'], 'Once: there is nothing left to show.' );
	}

	public function test_a_password_is_not_shown_for_a_stranger_or_a_name_outside_the_domain(): void {
		list( $r, $user ) = $this->domain();
		$id               = $r->id();
		$this->tool( 'create_mailbox', array( 'id' => $id, 'name' => 'a' ), $this->oauth( $user, Tokens::ACTIONS ) );

		$thief = $this->oauth( self::factory()->user->create(), Tokens::FULL );
		$this->assertTrue( $this->tool( 'reveal_mailbox_password', array( 'id' => $id, 'email' => 'a@tool.example' ), $thief )['isError'] );

		$full = $this->oauth( $user, Tokens::FULL );
		$this->assertTrue( $this->tool( 'reveal_mailbox_password', array( 'id' => $id, 'email' => 'a@elsewhere.example' ), $full )['isError'] );
		$this->assertTrue( MailAccess::revealable( Resource::find( $id ), 'a@tool.example' ) );
	}

	public function test_no_other_tool_result_holds_the_password(): void {
		list( $r, $user ) = $this->domain();
		$id               = $r->id();
		$actions          = $this->oauth( $user, Tokens::ACTIONS );
		$results          = array(
			$this->text( $this->tool( 'create_mailbox', array( 'id' => $id, 'name' => 'a' ), $actions ) ),
		);
		$stored  = (array) Resource::find( $id )->get( MailAccess::META );
		$plain   = (string) \BeltoftServices\Crypto::decrypt( (string) $stored['a@tool.example']['pw'] );
		$results[] = $this->text( $this->tool( 'email_domain_info', array( 'id' => $id ), $actions ) );
		$results[] = $this->text( $this->tool( 'service_details', array( 'id' => $id ), $actions ) );
		$results[] = $this->text( $this->tool( 'reset_mailbox_password', array( 'id' => $id, 'email' => 'a@tool.example' ), $actions ) );

		foreach ( $results as $text ) {
			$this->assertStringNotContainsString( $plain, $text );
		}
	}

	public function test_the_api_document_describes_what_a_token_can_do_and_leaves_the_rest_out(): void {
		$paths = json_decode( (string) wp_json_encode( \BeltoftServices\Api\OpenApi::document() ), true )['paths'];

		foreach ( array( '/resources/{id}/mail' => 'get', '/resources/{id}/mail/mailboxes' => 'post', '/resources/{id}/mail/aliases' => 'post', '/resources/{id}/mail/aliases/{address}' => 'delete', '/resources/{id}/mail/dns/check' => 'post' ) as $path => $method ) {
			$this->assertArrayHasKey( $path, $paths, $path );
			$this->assertArrayHasKey( $method, $paths[ $path ], $path );
		}

		foreach ( array_keys( $paths ) as $path ) {
			$this->assertStringNotContainsString( 'mail-reveal', $path );
			$this->assertStringNotContainsString( '/mail/mailboxes/{email}', $path );
		}

		$html = \BeltoftServices\Api\Reference::html();
		$this->assertStringContainsString( 'email_domain_info', $html, 'The tool is listed beside its route.' );
		$this->assertStringNotContainsString( 'mail-reveal', $html );
	}
}

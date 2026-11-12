<?php
/**
 * The links to the file manager and the database tool: the actions, the routes, the buttons and the guards.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Hosting;

require_once __DIR__ . '/HostingCase.php';

use BeltoftServices\Api\Routes;
use BeltoftServices\Hosting\HostingAccount;
use BeltoftServices\Hosting\HostingActions;
use BeltoftServices\Hosting\HostingRoutes;
use BeltoftServices\Resource;

/**
 * ToolActions through HostingActions, HostingRoutes files-link and database-link, and the buttons of HostingAccount.
 */
class ToolLinkTest extends HostingCase {

	private const BASE = '/' . Routes::NAMESPACE . '/resources/';

	public function set_up(): void {
		parent::set_up();
		$this->settings( array( 'hosting_php_versions' => '8.3,8.4,8.5' ) );
		add_filter( 'beltoft_services_rest_rate_limit', '__return_zero' );
	}

	public function tear_down(): void {
		remove_all_filters( 'beltoft_services_rest_rate_limit' );
		wp_set_current_user( 0 );
		global $wp_rest_server;
		$wp_rest_server = null;

		parent::tear_down();
	}

	private function account(): Resource {
		$r = $this->resource( array( 'sites' => 3, 'php_workers' => 10, 'php_memory_mb' => 512, 'upload_mb' => 100 ) );
		$this->provision_fully( $r );
		$r->set( array( 'state' => 'ready', 'desired' => 'ready' ) );

		return Resource::find( $r->id() );
	}

	private function call( string $path, int $user ): \WP_REST_Response {
		wp_set_current_user( $user );
		$request = new \WP_REST_Request( 'POST', self::BASE . $path );
		$request->set_header( 'content-type', 'application/json' );
		$request->set_body( '{}' );

		return rest_get_server()->dispatch( $request );
	}

	public function test_a_link_for_each_tool_goes_to_the_agent_with_the_tool_and_returns_only_the_address(): void {
		$r    = $this->account();
		$a    = new HostingActions( $r );
		$slug = 'w' . $r->id();

		$files = $a->files_link( $slug );
		$this->assertSame( array( 'url' ), array_keys( $files ) );
		$this->assertMatchesRegularExpression( '#^https://' . $slug . '\.preview\.example\.test/\.hosting/files/\?t=[A-Za-z0-9_-]+$#D', $files['url'] );

		$database = $a->database_link( $slug );
		$this->assertStringContainsString( '/.hosting/database/?t=', $database['url'] );

		$calls = $this->agent->calls_of( 'site.tool_link' );
		$this->assertCount( 2, $calls );
		$this->assertSame( 'files', $calls[0]['args']['tool'] );
		$this->assertSame( 'database', $calls[1]['args']['tool'] );
		$this->assertSame( $slug, $calls[0]['args']['slug'] );
		$this->assertArrayNotHasKey( 'host', $calls[0]['args'], 'The node picks the address that serves the website.' );
	}

	public function test_an_unknown_website_is_refused_and_the_agent_is_not_asked(): void {
		$r = $this->account();
		$a = new HostingActions( $r );

		$err = $a->files_link( 'nothere' );
		$this->assertWPError( $err );
		$this->assertSame( 'not_found', $err->get_error_code() );
		$this->assertSame( array(), $this->agent->calls_of( 'site.tool_link' ) );
	}

	public function test_a_refusal_of_the_node_is_told_plainly_and_its_words_are_not_passed_on(): void {
		$r    = $this->account();
		$a    = new HostingActions( $r );
		$slug = 'w' . $r->id();

		$this->agent->fail_next( 'site.tool_link', 'secret internal detail 10.200.1.5' );
		$err = $a->database_link( $slug );
		$this->assertWPError( $err );
		$this->assertSame( 'The database tool could not be opened.', $err->get_error_message() );
		$this->assertStringNotContainsString( '10.200', $err->get_error_message() );
	}

	public function test_an_address_that_is_not_the_tools_own_is_not_passed_on(): void {
		$r    = $this->account();
		$a    = new HostingActions( $r );
		$slug = 'w' . $r->id();

		foreach ( array( 'http://x.example/.hosting/files/?t=abc', 'https://x.example/wp-admin/?t=abc', 'https://x.example/.hosting/database/?t=abc', 'https://x.example/.hosting/files/?t=abc&next=https://evil.example' ) as $bad ) {
			$this->agent->script_tool_url( $bad );
			$this->assertWPError( $a->files_link( $slug ), $bad );
		}
	}

	public function test_the_routes_need_the_owner_are_not_cached_and_have_their_own_limit(): void {
		$r    = $this->account();
		$slug = 'w' . $r->id();
		$user = (int) $r->license()['user_id'];

		foreach ( array( 'files-link', 'database-link' ) as $route ) {
			$path = $r->id() . '/hosting/sites/' . $slug . '/' . $route;
			$ok   = $this->call( $path, $user );
			$this->assertSame( 200, $ok->get_status(), $route );
			$this->assertStringContainsString( 'no-store', (string) $ok->get_headers()['Cache-Control'] );
			$this->assertSame( 403, $this->call( $path, self::factory()->user->create() )->get_status(), 'A stranger gets nothing.' );
			$this->assertContains( $this->call( $path, 0 )->get_status(), array( 401, 403 ) );
			$this->assertSame( 404, $this->call( $r->id() . '/hosting/sites/nothere/' . $route, $user )->get_status() );
		}

		$status = 200;
		$path   = $r->id() . '/hosting/sites/' . $slug . '/files-link';

		for ( $i = 0; $i < HostingRoutes::LOGIN_LIMIT + 1 && 429 !== $status; $i++ ) {
			$status = $this->call( $path, $user )->get_status();
		}

		$this->assertSame( 429, $status );
	}

	public function test_the_tool_routes_are_not_open_to_api_tokens(): void {
		$route = '/' . Routes::NAMESPACE . '/resources/1/hosting/sites/blog/files-link';
		$this->assertFalse( \BeltoftServices\Api\Tokens::reachable( $route ), 'The links are for the dashboard only.' );
		$this->assertFalse( \BeltoftServices\Api\Tokens::reachable( str_replace( 'files-link', 'database-link', $route ) ) );
	}

	public function test_the_page_has_the_two_buttons_and_the_script_opens_the_address_in_a_tab(): void {
		$r = $this->account();

		ob_start();
		HostingAccount::render( Resource::find( $r->id() ) );
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'data-hosting-action="tool_files"', $html );
		$this->assertStringContainsString( 'data-hosting-action="tool_database"', $html );
		$this->assertStringContainsString( 'Open file manager', $html );
		$this->assertStringContainsString( 'Open database', $html );

		$js = (string) file_get_contents( BELTOFT_SERVICES_PATH . 'assets/hosting.js' );
		$this->assertStringContainsString( "'/files-link'", $js );
		$this->assertStringContainsString( "'/database-link'", $js );
		$this->assertMatchesRegularExpression( "/var opens = [^;]*'tool_files'[^;]*'tool_database'/", $js, 'Both open a tab from the click.' );
	}

	public function test_a_site_that_is_not_ready_shows_no_buttons(): void {
		$r = $this->account();
		$r->set( array( 'state' => 'paused', 'desired' => 'paused' ) );

		ob_start();
		HostingAccount::render( Resource::find( $r->id() ) );
		$html = (string) ob_get_clean();

		$this->assertStringNotContainsString( 'data-hosting-action="tool_files"', $html );
		$this->assertStringNotContainsString( 'data-hosting-action="tool_database"', $html );
	}

	public function test_the_new_strings_have_no_dash_or_middle_dot(): void {
		foreach ( array( 'src/Hosting/Actions/ToolActions.php', 'src/Hosting/HostingAccount.php' ) as $file ) {
			preg_match_all( "/__\\(\\s*'([^']*)'/", (string) file_get_contents( BELTOFT_SERVICES_PATH . $file ), $m );

			foreach ( $m[1] as $string ) {
				$this->assertStringNotContainsString( "\u{2014}", $string );
				$this->assertStringNotContainsString( "\u{00B7}", $string );
			}
		}
	}
}

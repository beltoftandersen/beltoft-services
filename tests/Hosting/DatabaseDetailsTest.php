<?php
/**
 * The database of a website on its card: name, user and host as plain facts, and the password shown once after the emailed code.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Hosting;

require_once __DIR__ . '/HostingCase.php';

use BeltoftServices\Api\Routes;
use BeltoftServices\Crypto;
use BeltoftServices\Hosting\HostingActions;
use BeltoftServices\Hosting\HostingAccount;
use BeltoftServices\Hosting\HostingRoutes;
use BeltoftServices\Hosting\Records;
use BeltoftServices\Mcp\ActionMap;
use BeltoftServices\Resource;
use BeltoftServices\StepUp;

/**
 * Facts, the reveal route and what it never touches.
 */
class DatabaseDetailsTest extends HostingCase {

	private const BASE = '/' . Routes::NAMESPACE . '/resources/';

	/**
	 * Captured mails (the step-up code).
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $mails = array();

	public function set_up(): void {
		parent::set_up();
		$this->settings( array( 'hosting_php_versions' => '8.3,8.4,8.5' ) );
		add_filter( 'beltoft_services_rest_rate_limit', '__return_zero' );
		$this->mails = array();
		add_filter(
			'pre_wp_mail',
			function ( $pre, $atts ) {
				$this->mails[] = $atts;
				return true;
			},
			10,
			2
		);
	}

	public function tear_down(): void {
		remove_all_filters( 'beltoft_services_rest_rate_limit' );
		remove_all_filters( 'pre_wp_mail' );
		wp_set_current_user( 0 );
		global $wp_rest_server;
		$wp_rest_server = null;

		parent::tear_down();
	}

	private function account( array $site = array() ): Resource {
		$r = $this->resource( array( 'sites' => 3, 'php_workers' => 10, 'php_memory_mb' => 512, 'upload_mb' => 100 ), array( 'site' => array_merge( array( 'slug' => 'first', 'install' => true ), $site ) ) );
		$this->provision_fully( $r );
		$r->set( array( 'state' => 'ready', 'desired' => 'ready' ) );

		return Resource::find( $r->id() );
	}

	private function call( string $method, string $path, int $user, array $params = array() ): \WP_REST_Response {
		wp_set_current_user( $user );
		$request = new \WP_REST_Request( $method, self::BASE . $path );
		$request->set_header( 'content-type', 'application/json' );
		$request->set_body( (string) wp_json_encode( $params ) );

		return rest_get_server()->dispatch( $request );
	}

	private function user( Resource $r ): int {
		return (int) $r->license()['user_id'];
	}

	private function code( int $user ): string {
		$this->assertTrue( StepUp::send( $user, HostingRoutes::DB_PURPOSE ) );
		$mail = (string) end( $this->mails )['message'];
		$this->assertSame( 1, preg_match( '/\b(\d{6})\b/', wp_strip_all_tags( $mail ), $m ) );

		return $m[1];
	}

	private function password( Resource $r ): string {
		return (string) Crypto::decrypt( (string) Resource::find( $r->id() )->get( 'db_secret' ) );
	}

	public function test_the_card_shows_name_user_and_host_for_every_website_and_never_the_password(): void {
		foreach ( array( true, false ) as $wordpress ) {
			$r      = $this->account( array( 'install' => $wordpress ) );
			$id     = $r->id();
			$sites  = ( new HostingActions( $r ) )->sites();
			$record = Records::for_resource( $id );

			$slug   = (string) $sites[0]['slug'];
			$this->assertSame( array( 'name' => 'c' . $id . '_' . str_replace( '-', '_', $slug ), 'user' => 'c' . $id, 'host' => $record['db_host'] ), $sites[0]['database'] );
			$this->assertNotSame( '', $sites[0]['database']['host'] );
			$this->assertStringNotContainsString( $this->password( $r ), (string) wp_json_encode( $sites ) );

			$actions = new HostingActions( $r );
			$node    = (array) ( \BeltoftServices\Hosting\Nodes::get( (string) $record['node'] ) ?? array() );
			$html    = \BeltoftServices\Hosting\HostingView::capture( 'site', \BeltoftServices\Hosting\HostingView::site( $r, $actions->sites()[0], false, true, array(), $node, $actions->limits() ) );

			$this->assertStringContainsString( 'c' . $id . '_' . str_replace( '-', '_', $slug ), $html );
			$this->assertStringContainsString( 'Database name', $html );
			$this->assertStringContainsString( 'Database user', $html );
			$this->assertStringContainsString( 'Database host', $html );
			$this->assertStringContainsString( 'data-hosting-action="db_password"', $html );
			$this->assertStringContainsString( 'data-purpose="hosting_database"', $html );
			$this->assertStringNotContainsString( $this->password( $r ), $html );
			$this->assertStringNotContainsString( 'new password', strtolower( $html ), 'There is no way to set a new database password in this version.' );
		}
	}

	public function test_the_password_needs_the_emailed_code_and_is_shown_once(): void {
		$r    = $this->account();
		$user = $this->user( $r );
		$path = $r->id() . '/hosting/database/password';

		$this->assertSame( 403, $this->call( 'POST', $path, $user, array( 'code' => '' ) )->get_status(), 'No code.' );
		$this->assertSame( 403, $this->call( 'POST', $path, $user, array( 'code' => '000000' ) )->get_status(), 'A wrong code.' );

		$code = $this->code( $user );
		$ok   = $this->call( 'POST', $path, $user, array( 'code' => $code ) );
		$this->assertSame( 200, $ok->get_status() );
		$this->assertSame( array( 'password' => $this->password( $r ) ), $ok->get_data() );
		$this->assertNotSame( '', $ok->get_data()['password'] );
		$this->assertStringContainsString( 'no-store', (string) $ok->get_headers()['Cache-Control'] );

		$this->assertSame( 403, $this->call( 'POST', $path, $user, array( 'code' => $code ) )->get_status(), 'The code works once.' );
	}

	public function test_a_code_for_another_purpose_does_not_show_the_password(): void {
		$r    = $this->account();
		$user = $this->user( $r );
		$this->assertTrue( StepUp::send( $user, HostingRoutes::STEP_PURPOSE ) );
		preg_match( '/\b(\d{6})\b/', wp_strip_all_tags( (string) end( $this->mails )['message'] ), $m );

		$this->assertSame( 403, $this->call( 'POST', $r->id() . '/hosting/database/password', $user, array( 'code' => $m[1] ) )->get_status() );
	}

	public function test_the_code_email_says_what_it_is_for(): void {
		$r = $this->account();
		$this->code( $this->user( $r ) );

		$this->assertStringContainsString( 'showing the database password of your hosting', wp_strip_all_tags( (string) end( $this->mails )['message'] ) );
	}

	public function test_another_customer_and_a_visitor_are_refused(): void {
		$r        = $this->account();
		$stranger = self::factory()->user->create();
		$code     = $this->code( $stranger );

		$this->assertSame( 403, $this->call( 'POST', $r->id() . '/hosting/database/password', $stranger, array( 'code' => $code ) )->get_status() );
		$this->assertSame( 401, $this->call( 'POST', $r->id() . '/hosting/database/password', 0, array( 'code' => '123456' ) )->get_status() );
	}

	public function test_wrong_codes_are_counted_by_the_hourly_limit(): void {
		remove_all_filters( 'beltoft_services_rest_rate_limit' );
		$r    = $this->account();
		$user = $this->user( $r );
		$last = 0;

		for ( $i = 0; $i < HostingRoutes::DB_LIMIT + 2; $i++ ) {
			$last = $this->call( 'POST', $r->id() . '/hosting/database/password', $user, array( 'code' => '00000' . ( $i % 10 ) ) )->get_status();
		}

		$this->assertSame( 429, $last );
		$this->assertSame( 429, $this->call( 'POST', $r->id() . '/hosting/database/password', $user, array( 'code' => $this->code( $user ) ) )->get_status(), 'A right code does not get past the limit either.' );
	}

	public function test_an_account_that_is_not_ready_does_not_show_it(): void {
		$r = $this->account();
		$r->set( array( 'state' => 'paused' ) );
		$user = $this->user( $r );
		$code = $this->code( $user );

		$this->assertGreaterThanOrEqual( 400, $this->call( 'POST', $r->id() . '/hosting/database/password', $user, array( 'code' => $code ) )->get_status() );
	}

	public function test_the_password_is_never_in_a_job_a_result_the_step_memory_the_agent_log_or_a_queue(): void {
		$r        = $this->account();
		$user     = $this->user( $r );
		$password = $this->password( $r );
		$this->call( 'POST', $r->id() . '/hosting/database/password', $user, array( 'code' => $this->code( $user ) ) );

		// A change after the reveal, so the jobs and the results are there to look at.
		$this->change( $r, 'add_site', array( 'second', '', '8.3', true ) );
		$this->change( $r, 'set_php', array( 'second', '8.4' ) );

		$fresh = Resource::find( $r->id() );
		$this->assertStringNotContainsString( $password, (string) wp_json_encode( $fresh->get( \BeltoftServices\Jobs::RESULT ) ) );
		$this->assertStringNotContainsString( $password, (string) wp_json_encode( $fresh->get( \BeltoftServices\Hosting\Actions\Steps::META ) ) );

		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- test inspection of the queue.
		$args = (string) $wpdb->get_var( 'SELECT GROUP_CONCAT(args SEPARATOR " ") FROM ' . $wpdb->prefix . 'actionscheduler_actions' );
		// phpcs:enable
		$this->assertStringNotContainsString( $password, $args );

		$this->assertStringNotContainsString( $password, (string) wp_json_encode( $this->agent->calls() ), 'The agent log has the account password masked.' );
		$this->assertStringNotContainsString( $password, (string) wp_json_encode( ( new HostingActions( $fresh ) )->sites() ) );
		$this->assertStringNotContainsString( $password, (string) wp_json_encode( HostingAccount::checklist( $fresh ) ) );
	}

	public function test_no_tool_of_the_mcp_server_reaches_the_reveal_route(): void {
		$route = 'POST /resources/12/hosting/database/password';

		foreach ( ActionMap::routes() as $tool => $patterns ) {
			foreach ( $patterns as $pattern ) {
				list( $method, $regex ) = explode( ' ', $pattern, 2 );
				$this->assertFalse( 0 === strpos( $route, $method . ' ' ) && 1 === preg_match( $regex, substr( $route, strlen( $method ) + 1 ) ), $tool . ' must not reach the database password.' );
			}
		}

		$this->assertStringStartsWith( 'never', ActionMap::capabilities_excluded()['reveal_hosting_database_password'] );
	}
}

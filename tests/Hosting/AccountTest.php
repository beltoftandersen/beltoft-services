<?php
/**
 * The hosting account actions, their REST routes, the panel and the Email lite line.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Hosting;

require_once __DIR__ . '/HostingCase.php';

use BeltoftServices\Api\Routes;
use BeltoftServices\Hosting\HostingAccount;
use BeltoftServices\Hosting\HostingActions;
use BeltoftServices\Hosting\Sites;
use BeltoftServices\Hosting\SiteDomains;
use BeltoftServices\Hosting\Records;
use BeltoftServices\Mail\FakeMailcowClient;
use BeltoftServices\Mail\MailView;
use BeltoftServices\Resource;

/**
 * HostingActions, HostingRoutes, HostingAccount.
 */
class AccountTest extends HostingCase {

	private const BASE = '/' . Routes::NAMESPACE . '/resources/';

	public function set_up(): void {
		parent::set_up();
		$this->settings( array( 'hosting_php_versions' => '8.3,8.4,8.5' ) );
		delete_option( FakeMailcowClient::OPTION );
		add_filter( 'beltoft_services_mail_client', fn() => new FakeMailcowClient() );
		add_filter( 'beltoft_services_rest_rate_limit', '__return_zero' );
	}

	public function tear_down(): void {
		remove_all_filters( 'beltoft_services_mail_client' );
		remove_all_filters( 'beltoft_services_rest_rate_limit' );
		wp_set_current_user( 0 );
		global $wp_rest_server;
		$wp_rest_server = null;

		parent::tear_down();
	}

	/**
	 * A ready account.
	 *
	 * @param array<string, mixed> $plan Plan overrides.
	 */
	private function account( array $plan = array() ): Resource {
		$r = $this->resource( array_merge( array( 'sites' => 3, 'php_workers' => 10, 'php_memory_mb' => 512, 'upload_mb' => 100 ), $plan ) );
		$this->provision_fully( $r );
		$r->set( array( 'state' => 'ready', 'desired' => 'ready' ) );

		return Resource::find( $r->id() );
	}

	/**
	 * A REST call as a user.
	 *
	 * @param array<string, mixed> $params Parameters.
	 */
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

	public function test_a_failed_add_leaves_no_half_made_site_and_the_same_name_works_again(): void {
		$r = $this->account();
		$a = new HostingActions( $r );

		$this->agent->fail_next( 'site.wp_install' );
		$err = $a->add_site( 'again', 'Again' );
		$this->assertWPError( $err );
		$this->assertNull( Sites::find( $r->id(), 'again' ), 'The name is free for a second try.' );

		$this->assertSame( 'again', $a->add_site( 'again', 'Again' )['slug'] );
	}

	public function test_a_failed_add_drops_the_database_it_made(): void {
		$r = $this->account();
		$a = new HostingActions( $r );

		$this->agent->fail_next( 'site.wp_install' );
		$this->assertWPError( $a->add_site( 'dbgone', 'Dbgone' ) );

		$this->assertNotContains( 'c' . $r->id() . '_dbgone', $this->agent->state()['dbs'][ 'c' . $r->id() ]['databases'], 'The database of the failed site was dropped.' );
		$this->assertArrayNotHasKey( 'dbgone', (array) ( $this->agent->state()['sites'][ \BeltoftServices\Hosting\Records::for_resource( $r->id() )['vmid'] ] ?? array() ) );
	}

	public function test_when_the_cleanup_fails_too_the_same_names_retry_carries_on_and_the_row_stays_hidden(): void {
		$r = $this->account();
		$a = new HostingActions( $r );

		$this->agent->fail_next( 'site.wp_install' );
		$this->agent->fail_next( 'db.drop' );
		$this->assertWPError( $a->add_site( 'resume', 'Resume' ) );

		$row = Sites::find( $r->id(), 'resume' );
		$this->assertNotNull( $row, 'The record stays, marked pending, because the cleanup did not work.' );
		$this->assertTrue( $row['flags']['pending'] );
		$this->assertNotContains( 'resume', array_column( ( new HostingActions( Resource::find( $r->id() ) ) )->sites(), 'slug' ), 'The customer does not see an unfinished site.' );
		$this->assertNotContains( 'resume.preview.example.test', array_column( \BeltoftServices\Hosting\EdgeMap::routes( $r->id(), false ), 'host' ) );

		$done = ( new HostingActions( Resource::find( $r->id() ) ) )->add_site( 'resume', 'Resume' );
		$this->assertSame( 'resume', $done['slug'], 'The retry of the same name carries on.' );
		$this->assertArrayNotHasKey( 'pending', array_filter( Sites::find( $r->id(), 'resume' )['flags'] ) );
		$this->assertContains( 'resume', array_column( ( new HostingActions( Resource::find( $r->id() ) ) )->sites(), 'slug' ) );
		$this->assertCount( 2, Sites::for_resource( $r->id() ), 'No second record.' );
	}

	public function test_a_preview_address_that_could_not_be_written_is_written_by_the_sweep(): void {
		$r = $this->account();
		$a = new HostingActions( $r );

		$saved = $this->dns->zone( 'preview.example.test' );
		$this->dns->delete_zone( 'preview.example.test' );
		$err = $a->add_site( 'later', 'Later' );
		$this->assertWPError( $err );
		$this->assertSame( 'pending', SiteDomains::find( 'later.preview.example.test' )['state'] );

		$this->dns->create_zone( 'preview.example.test', array( 'ns1.example.test.' ) );
		$this->assertSame( array( 'later.preview.example.test' => 'active' ), \BeltoftServices\Hosting\DomainSweep::run() );
		$this->assertSame( 'active', SiteDomains::find( 'later.preview.example.test' )['state'] );
		unset( $saved );
	}

	public function test_a_second_change_of_the_account_while_one_runs_is_refused_not_interleaved(): void {
		$r = $this->account();

		// Another connection (another request) holds the account's site lock.
		$other = new \mysqli( DB_HOST, DB_USER, DB_PASSWORD, DB_NAME );
		$name  = \BeltoftServices\Locks::name( 'hosting-site', $r->id() );
		$other->query( "SELECT GET_LOCK('" . $other->real_escape_string( $name ) . "', 0)" );
		add_filter( 'beltoft_services_hosting_lock_wait', '__return_zero' );

		$a = new HostingActions( $r );
		foreach ( array( $a->add_site( 'busy1' ), $a->set_php( 'w' . $r->id(), '8.4' ), $a->remove_site( 'x', 'x' ) ) as $result ) {
			$this->assertWPError( $result );
			$this->assertSame( 'busy', $result->get_error_code() );
		}

		$this->assertNull( Sites::find( $r->id(), 'busy1' ), 'Nothing was started.' );

		$other->query( "SELECT RELEASE_LOCK('" . $other->real_escape_string( $name ) . "')" );
		$other->close();
		remove_all_filters( 'beltoft_services_hosting_lock_wait' );
		$this->assertIsArray( $a->add_site( 'busy1' ), 'Free again.' );
	}

	public function test_an_action_waits_for_a_job_the_agent_answers_queued_first(): void {
		$r = $this->account();
		$this->agent->pending( 'site.cache_purge', 2 );
		$this->assertSame( array( 'purged' => 'w' . $r->id() ), ( new HostingActions( $r ) )->purge_cache( 'w' . $r->id() ) );

		// With no time to wait the customer is asked to try again, and the same job is read next time.
		add_filter( 'beltoft_services_hosting_action_wait', '__return_zero' );
		$this->agent->pending( 'site.cache_purge', 2 );
		$busy = ( new HostingActions( Resource::find( $r->id() ) ) )->purge_cache( 'w' . $r->id() );
		remove_all_filters( 'beltoft_services_hosting_action_wait' );
		$this->assertWPError( $busy );
		$this->assertSame( 'busy', $busy->get_error_code() );
	}

	public function test_add_a_site_within_the_plan_and_refusals(): void {
		$r = $this->account();
		$a = new HostingActions( $r );

		$done = $a->add_site( 'blog', 'Blog', '8.4' );
		$this->assertSame( 'blog.preview.example.test', $done['host'] );
		$this->assertSame( '8.4', Sites::find( $r->id(), 'blog' )['php'] );
		$this->assertContains( 'c' . $r->id() . '_blog', $this->agent->state()['dbs'][ 'c' . $r->id() ]['databases'], 'The site has its database before the install (the real agent refuses an install without one).' );
		$this->assertContains( 'c' . $r->id() . '_w' . $r->id(), $this->agent->state()['dbs'][ 'c' . $r->id() ]['databases'], 'The first site keeps its database.' );
		$this->assertArrayHasKey( 'blog', $this->agent->state()['sites'][1000] );
		$this->assertSame( 'active', SiteDomains::find( 'blog.preview.example.test' )['state'] );
		$this->assertContains( 'blog.preview.example.test', array_column( $this->agent->state()['edge'][ 'c' . $r->id() ], 'host' ) );
		$this->assertCount( 1, $this->contents( 'blog.preview.example.test' ) );

		foreach ( array( array( 'Bad Slug', 'invalid' ), array( 'x', 'invalid', '7.0' ), array( 'blog', 'exists' ) ) as $case ) {
			$err = $a->add_site( $case[0], '', $case[2] ?? '' );
			$this->assertWPError( $err );
			$this->assertSame( $case[1], $err->get_error_code(), $case[0] );
		}

		$this->assertTrue( is_array( $a->add_site( 'third', '', '', false ) ) );
		$this->assertCount( 2, $this->agent->calls_of( 'site.wp_install' ), 'The first site and blog; the third was made without an install.' );
		$limit = $a->add_site( 'fourth' );
		$this->assertSame( 'limit', $limit->get_error_code(), 'The plan has 3 websites.' );
	}

	/**
	 * Addresses of a name in the preview zone of the fake DNS.
	 *
	 * @param string $host Name.
	 * @return string[]
	 */
	private function contents( string $host ): array {
		foreach ( (array) $this->dns->zone( 'preview.example.test' )['rrsets'] as $set ) {
			if ( rtrim( $set['name'], '.' ) === $host && 'A' === $set['type'] ) {
				return array_column( $set['records'], 'content' );
			}
		}

		return array();
	}

	public function test_a_site_name_taken_by_another_account_is_refused_with_no_leak(): void {
		$a = $this->account();
		$b = $this->account();
		( new HostingActions( $a ) )->add_site( 'shared' );

		$err = ( new HostingActions( $b ) )->add_site( 'shared' );

		$this->assertSame( 'exists', $err->get_error_code() );
		$this->assertStringNotContainsString( (string) $a->id(), $err->get_error_message() );
	}

	public function test_remove_a_site_needs_confirmation_and_keeps_the_last_one(): void {
		$r = $this->account();
		$a = new HostingActions( $r );
		$a->add_site( 'blog' );
		$first = 'w' . $r->id();

		$this->assertSame( 'invalid', $a->remove_site( 'blog', 'nope' )->get_error_code() );
		$this->assertSame( 'not_found', $a->remove_site( 'ghost', 'ghost' )->get_error_code() );

		$this->assertSame( array( 'removed' => 'blog' ), $a->remove_site( 'blog', 'blog' ) );
		$this->assertNull( Sites::find( $r->id(), 'blog' ) );
		$this->assertNull( SiteDomains::find( 'blog.preview.example.test' ) );
		$this->assertSame( array(), $this->contents( 'blog.preview.example.test' ) );
		$this->assertArrayNotHasKey( 'blog', $this->agent->state()['sites'][1000] );
		$this->assertNotContains( 'blog.preview.example.test', array_column( $this->agent->state()['edge'][ 'c' . $r->id() ], 'host' ) );

		$this->assertSame( 'limit', $a->remove_site( $first, $first )->get_error_code(), 'The last site stays.' );
	}

	public function test_domains_ours_and_external_and_the_preview_name_stays(): void {
		$r = $this->account();
		$a = new HostingActions( $r );
		$slug = 'w' . $r->id();

		$ext = $a->add_domain( $slug, 'www.outside.example' );
		$this->assertSame( 'external', $ext['kind'] );
		$this->assertSame( 'A', $ext['point_at'][0]['type'] );
		$this->assertSame( '198.51.100.7', $ext['point_at'][0]['value'] );
		$this->assertNotContains( 'www.outside.example', array_column( $this->agent->state()['edge'][ 'c' . $r->id() ], 'host' ), 'A waiting name is not routed.' );

		$this->assertSame( 'invalid', $a->add_domain( $slug, 'not valid' )->get_error_code() );
		$this->assertSame( 'invalid', $a->remove_domain( $slug . '.preview.example.test' )->get_error_code() );
		$this->assertSame( 'not_found', $a->remove_domain( 'unknown.example' )->get_error_code() );
		$this->assertSame( array( 'removed' => 'www.outside.example' ), $a->remove_domain( 'www.outside.example' ) );
		$this->assertNull( SiteDomains::find( 'www.outside.example' ) );
	}

	public function test_php_version_and_limits_stay_within_the_plan_ceilings(): void {
		$r    = $this->account( array( 'php_memory_mb' => 512, 'upload_mb' => 100 ) );
		$a    = new HostingActions( $r );
		$slug = 'w' . $r->id();

		$this->assertSame( array( 'slug' => $slug, 'php' => '8.5', 'memory_mb' => 256, 'upload_mb' => 50 ), $a->set_php( $slug, '8.5', 256, 50 ) );
		$this->assertSame( '8.5', Sites::find( $r->id(), $slug )['php'] );
		$this->assertSame( '8.5', $this->agent->state()['sites'][1000][ $slug ]['php'] );
		$this->assertSame( 256, $this->agent->state()['sites'][1000][ $slug ]['memory_mb'] );

		$this->assertSame( 'limit', $a->set_php( $slug, '8.4', 1024, null )->get_error_code(), 'Above the plan ceiling.' );
		$this->assertSame( 'limit', $a->set_php( $slug, '8.4', null, 101 )->get_error_code() );
		$this->assertSame( 'limit', $a->set_php( $slug, '8.4', 10, null )->get_error_code() );
		$this->assertSame( 'invalid', $a->set_php( $slug, '5.6' )->get_error_code() );
		$this->assertSame( 'not_found', $a->set_php( 'ghost', '8.4' )->get_error_code() );

		$kept = $a->set_php( $slug, '8.4' );
		$this->assertSame( 256, $kept['memory_mb'], 'A value that is not sent is kept.' );
	}

	public function test_workers_are_moved_within_the_account_ceiling(): void {
		$r = $this->account( array( 'php_workers' => 10 ) );
		$a = new HostingActions( $r );
		$a->add_site( 'blog' );
		$slug = 'w' . $r->id();

		$limits = $a->limits();
		$this->assertSame( 10, $limits['php_workers'] );
		$this->assertLessThanOrEqual( 10, $limits['workers_used'] );

		$this->assertSame( 'limit', $a->set_workers( $slug, 1 )->get_error_code() );
		$this->assertSame( 'limit', $a->set_workers( $slug, 10 )->get_error_code(), 'The other site keeps its workers.' );
		$blog = Sites::find( $r->id(), 'blog' )['workers'];
		$this->assertSame( array( 'slug' => $slug, 'workers' => 10 - $blog ), $a->set_workers( $slug, 10 - $blog ) );
		$this->assertSame( 10 - $blog, $this->agent->state()['sites'][1000][ $slug ]['workers'] );
	}

	public function test_cache_purge_and_login_link(): void {
		$r    = $this->account();
		$a    = new HostingActions( $r );
		$slug = 'w' . $r->id();

		$this->assertSame( array( 'purged' => $slug ), $a->purge_cache( $slug ) );
		$link = $a->login_link( $slug );
		$this->assertStringStartsWith( 'https://' . $slug . '.preview.example.test/', $link['url'] );
		$this->assertSame( 'not_found', $a->login_link( 'ghost' )->get_error_code() );

		$this->agent->fail_next( 'site.cache_purge', 'secret internal detail' );
		$err = $a->purge_cache( $slug );
		$this->assertSame( 'failed', $err->get_error_code() );
		$this->assertStringNotContainsString( 'secret internal detail', $err->get_error_message(), 'Nothing the agent says reaches the customer.' );

		$this->assertTrue( is_array( $a->purge_cache( $slug ) ), 'A later try is a new job.' );
	}

	public function test_zero_websites_in_the_plan_means_unlimited(): void {
		$r = $this->account( array( 'sites' => 0 ) );
		$a = new HostingActions( $r );

		for ( $i = 1; $i <= 6; $i++ ) {
			$this->assertIsArray( $a->add_site( 'site' . $i, '', '', false ), 'site' . $i );
		}

		$this->assertSame( 0, $a->limits()['sites'] );
		$this->assertCount( 7, \BeltoftServices\Hosting\Sites::for_resource( $r->id() ) );
		wp_set_current_user( (int) $r->license()['user_id'] );
		ob_start();
		HostingAccount::render( Resource::find( $r->id() ) );
		$html = (string) ob_get_clean();
		wp_set_current_user( 0 );
		$this->assertStringContainsString( '7 websites. Unlimited websites and subdomains.', $html );
		$this->assertStringContainsString( 'data-hosting-action="add_site"', $html );
	}

	public function test_a_positive_website_count_still_limits(): void {
		$r = $this->account( array( 'sites' => 1 ) );

		$this->assertSame( 'limit', ( new HostingActions( $r ) )->add_site( 'two' )->get_error_code() );
		$this->assertSame( 1, ( new HostingActions( $r ) )->limits()['sites'] );
	}

	public function test_nothing_can_change_unless_the_account_is_ready(): void {
		$r = $this->account();
		$r->set( array( 'desired' => 'paused' ) );
		$a = new HostingActions( Resource::find( $r->id() ) );

		$this->assertSame( 'not_ready', $a->add_site( 'blog' )->get_error_code() );
		$this->assertSame( 'not_ready', $a->purge_cache( 'w' . $r->id() )->get_error_code() );

		$unbuilt = new HostingActions( $this->resource() );
		$this->assertSame( 'not_ready', $unbuilt->add_site( 'blog' )->get_error_code() );
	}

	public function test_email_lite_shows_included_with_the_hosting_product(): void {
		$r     = $this->account();
		$a     = new HostingActions( $r );
		$this->connect_domain( $r, 'mine.example' );
		$added = $a->enable_mail( 'Mine.Example' );

		$this->assertSame( 'mine.example', $added['domain'] );
		$this->assertSame( 'exists', $a->enable_mail( 'other.example' )->get_error_code() );
		$this->assertSame( 'invalid', ( new HostingActions( $this->account() ) )->enable_mail( 'not a domain' )->get_error_code() );

		$child = Resource::find( $added['mail_resource'] );
		$label = HostingAccount::included_label( $child );
		$this->assertStringStartsWith( 'Included with Service ', $label );
		$this->assertSame( '', HostingAccount::included_label( $r ) );
		$this->assertSame( $label, MailView::data( $child, array() )['included'] );
	}

	public function test_the_panel_lists_sites_names_and_the_email_line_escaped(): void {
		$r = $this->account();
		$a = new HostingActions( $r );
		$a->add_site( 'blog', '<b>Blog</b>' ); // Tags are stripped when the title is saved.
		$a->add_domain( 'w' . $r->id(), 'www.outside.example' );

		ob_start();
		HostingAccount::render( Resource::find( $r->id() ) );
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( '2 of 3 websites', $html );
		$this->assertStringContainsString( 'blog.preview.example.test', $html );
		$this->assertStringContainsString( 'www.outside.example (waiting for DNS)', $html );
		$this->assertStringContainsString( 'Blog (blog)', $html );
		$this->assertStringNotContainsString( '<b>', $html );
		$this->assertStringContainsString( 'Connect your own domain to a website to set up Email lite', $html );
		$this->assertStringNotContainsString( "\u{2014}", $html );

		$this->connect_domain( $r, 'mine.example' );
		$a->enable_mail( 'mine.example' );
		ob_start();
		HostingAccount::render( Resource::find( $r->id() ) );
		$this->assertStringContainsString( 'Included with Service', (string) ob_get_clean() );
	}

	public function test_rest_routes_work_for_the_owner_and_hide_the_account_from_others(): void {
		$r    = $this->account();
		$user = $this->user( $r );
		$id   = $r->id();

		$overview = $this->call( 'GET', $id . '/hosting', $user );
		$this->assertSame( 200, $overview->get_status() );
		$this->assertSame( 3, $overview->get_data()['limits']['sites'] );
		$this->assertSame( '198.51.100.7', $overview->get_data()['point_at'][0]['value'] );
		$this->assertStringNotContainsString( self::NODE_SECRET, (string) wp_json_encode( $overview->get_data() ) );

		$created = $this->call( 'POST', $id . '/hosting/sites', $user, array( 'slug' => 'blog', 'label' => 'Blog', 'php' => '8.4', 'install' => false ) );
		$this->assertSame( 201, $created->get_status(), (string) wp_json_encode( $created->get_data() ) );
		$this->assertSame( 422, $this->call( 'POST', $id . '/hosting/sites', $user, array( 'slug' => 'Bad Slug' ) )->get_status() );
		$this->assertSame( 409, $this->call( 'POST', $id . '/hosting/sites', $user, array( 'slug' => 'blog' ) )->get_status() );

		$this->assertSame( 200, $this->call( 'POST', $id . '/hosting/sites/blog/php', $user, array( 'php' => '8.5', 'memory_mb' => 128 ) )->get_status() );
		$this->assertSame( 409, $this->call( 'POST', $id . '/hosting/sites/blog/php', $user, array( 'php' => '8.5', 'memory_mb' => 4096 ) )->get_status() );
		$this->assertSame( 200, $this->call( 'POST', $id . '/hosting/sites/blog/workers', $user, array( 'workers' => 2 ) )->get_status() );
		$this->assertSame( 200, $this->call( 'POST', $id . '/hosting/sites/blog/cache', $user )->get_status() );
		$login = $this->call( 'POST', $id . '/hosting/sites/blog/login', $user );
		$this->assertStringStartsWith( 'https://blog.preview', $login->get_data()['url'] );
		$this->assertSame( 'private, no-store', $login->get_headers()['Cache-Control'] );

		$domain = $this->call( 'POST', $id . '/hosting/sites/blog/domains', $user, array( 'host' => 'www.outside.example' ) );
		$this->assertSame( 200, $domain->get_status() );
		$this->assertSame( 200, $this->call( 'DELETE', $id . '/hosting/domains/www.outside.example', $user )->get_status() );
		$this->assertSame( 422, $this->call( 'DELETE', $id . '/hosting/sites/blog', $user, array( 'confirm' => 'wrong' ) )->get_status() );
		$this->assertSame( 200, $this->call( 'DELETE', $id . '/hosting/sites/blog', $user, array( 'confirm' => 'blog' ) )->get_status() );
		$this->connect_domain( Resource::find( (int) $r->id() ), 'mine.example' );
		$this->assertSame( 201, $this->call( 'POST', $id . '/hosting/mail', $user, array( 'domain' => 'mine.example' ) )->get_status() );

		$stranger = self::factory()->user->create();
		foreach ( array( array( 'GET', '/hosting' ), array( 'POST', '/hosting/sites' ), array( 'POST', '/hosting/sites/' . 'w' . $id . '/login' ) ) as $case ) {
			$this->assertSame( 403, $this->call( $case[0], $id . $case[1], $stranger, array( 'slug' => 'zzz' ) )->get_status(), $case[1] );
		}

		$this->assertContains( $this->call( 'GET', $id . '/hosting', 0 )->get_status(), array( 401, 403 ), 'A visitor gets nothing.' );
	}

	public function test_rest_refuses_a_resource_that_is_not_hosting_and_a_paused_one(): void {
		$r = $this->account();
		$r->set( array( 'state' => 'paused', 'desired' => 'paused' ) );

		$this->assertSame( 403, $this->call( 'POST', $r->id() . '/hosting/sites', $this->user( $r ), array( 'slug' => 'blog' ) )->get_status(), 'A paused service accepts no actions.' );

		$http = $this->service_license( $this->service_product( 'fake', array(), array( 'slug' => 'f-' . wp_generate_password( 4, false ) ) ), array( 'fire' => false ) );
		$fake = Resource::create( $http, $this->fake_driver(), array() );
		$this->assertInstanceOf( Resource::class, $fake );
		$fake->set( array( 'state' => 'ready' ) );
		$this->assertSame( 409, $this->call( 'GET', $fake->id() . '/hosting', (int) $http['user_id'] )->get_status() );
	}

	public function test_the_hourly_change_limit_counts_changes(): void {
		$r    = $this->account();
		$user = $this->user( $r );

		for ( $i = 0; $i < \BeltoftServices\Hosting\HostingRoutes::CHANGE_LIMIT; $i++ ) {
			$this->call( 'POST', $r->id() . '/hosting/sites/w' . $r->id() . '/cache', $user );
		}

		$this->assertSame( 429, $this->call( 'POST', $r->id() . '/hosting/sites/w' . $r->id() . '/cache', $user )->get_status() );
	}
}

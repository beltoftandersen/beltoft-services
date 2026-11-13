<?php
/**
 * WordPress moves to the customer's own domain when it goes live: when, how, what the page says, what happens when the
 * domain stops pointing here, is removed, or the agent is older. Everything runs against the FakeAgent; no job waits for
 * Action Scheduler, the tests call the job's own entry point.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Hosting;

require_once __DIR__ . '/HostingCase.php';

use BeltoftServices\Api\Routes;
use BeltoftServices\Hosting\DomainSweep;
use BeltoftServices\Hosting\EdgeMap;
use BeltoftServices\Hosting\HostingAccount;
use BeltoftServices\Hosting\HostingActions;
use BeltoftServices\Hosting\Records;
use BeltoftServices\Hosting\SiteAddresses;
use BeltoftServices\Hosting\SiteDns;
use BeltoftServices\Hosting\SiteDomains;
use BeltoftServices\Hosting\SiteWordPress;
use BeltoftServices\Hosting\Sites;
use BeltoftServices\Jobs;
use BeltoftServices\Resource;

/**
 * SiteWordPress, its place in SiteActions (names, redirects, removal), DomainSweep and the page.
 */
class WordPressAddressTest extends HostingCase {

	private const EDGE = '198.51.100.7';

	/**
	 * Mails to the operator.
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
		$this->dns_shows( self::EDGE );
		$this->clear_queue();
	}

	public function tear_down(): void {
		$this->clear_queue();
		remove_all_filters( 'beltoft_services_dns_resolve' );
		remove_all_filters( 'beltoft_services_rest_rate_limit' );
		remove_all_filters( 'pre_wp_mail' );
		wp_set_current_user( 0 );
		global $wp_rest_server;
		$wp_rest_server = null;

		parent::tear_down();
	}

	/**
	 * What a public lookup of a name answers (the A record).
	 *
	 * @param string ...$addresses Addresses.
	 */
	private function dns_shows( string ...$addresses ): void {
		remove_all_filters( 'beltoft_services_dns_resolve' );
		add_filter( 'beltoft_services_dns_resolve', static fn( $pre, $name, $type ) => 'A' === $type ? $addresses : array(), 10, 3 );
	}

	private function account(): Resource {
		$r = $this->resource( array( 'sites' => 3, 'php_workers' => 10, 'php_memory_mb' => 512, 'upload_mb' => 100 ) );
		$this->provision_fully( $r );
		$r->set( array( 'state' => 'ready', 'desired' => 'ready' ) );

		return Resource::find( $r->id() );
	}

	private function activate( string ...$hosts ): void {
		foreach ( $hosts as $host ) {
			SiteDomains::set_state( $host, 'active' );
		}
	}

	private function vmid( Resource $r ): int {
		return (int) Records::for_resource( $r->id() )['vmid'];
	}

	private function site( Resource $r, string $slug ): array {
		return (array) Sites::find( $r->id(), $slug );
	}

	/**
	 * What the fake agent says about a site.
	 *
	 * @return array<string, mixed>
	 */
	private function node_site( Resource $r, string $slug ): array {
		return $this->agent->state()['sites'][ $this->vmid( $r ) ][ $slug ];
	}

	/**
	 * Runs the queued move the way Action Scheduler would.
	 */
	private function run_job( Resource $r, string $slug, string $to, int $manual = 0 ): void {
		$this->clear_queue(); // The queue handed it over: nothing is waiting any more.
		SiteWordPress::run( $r->id(), $slug, $to, $manual, 0 );
	}

	/**
	 * Cancels every queued move (whatever its arguments).
	 */
	private function clear_queue(): void {
		foreach ( as_get_scheduled_actions( array( 'hook' => SiteWordPress::HOOK, 'status' => \ActionScheduler_Store::STATUS_PENDING, 'per_page' => -1 ), 'ids' ) as $id ) {
			\ActionScheduler::store()->cancel_action( (int) $id );
		}
	}

	/**
	 * Is the move to a host queued?
	 */
	private function queued( Resource $r, string $slug, string $to, int $manual = 0 ): bool {
		return Jobs::pending_action( SiteWordPress::HOOK, array( $r->id(), $slug, $to, $manual, 0 ) );
	}

	/**
	 * An account whose website has the domain connected (both names of the pair when $pair), the names active.
	 *
	 * @return array{0: Resource, 1: string}
	 */
	private function with_domain( bool $pair = true, bool $live = true ): array {
		$r    = $this->account();
		$slug = 'w' . $r->id();
		( new HostingActions( $r ) )->add_domain( $slug, 'example.org' );

		if ( $live ) {
			$this->activate( 'example.org' );

			if ( $pair ) {
				$this->activate( 'www.example.org' );
			}

			( new HostingActions( Resource::find( $r->id() ) ) )->sync_edge(); // The names reach the node before anything else looks at them, as in the sweep.
		}

		return array( Resource::find( $r->id() ), $slug );
	}

	/**
	 * The calls of an operation made after a mark.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function since( int $mark, string $op ): array {
		return array_values( array_filter( array_slice( $this->agent->calls(), $mark ), static fn( $c ) => $c['op'] === $op ) );
	}

	/**
	 * The last of a list.
	 *
	 * @param array<int, array<string, mixed>> $list List.
	 * @return array<string, mixed>
	 */
	private function last( array $list ): array {
		return (array) end( $list );
	}

	private function page( Resource $r ): string {
		ob_start();
		HostingAccount::render( Resource::find( $r->id() ) );

		return (string) ob_get_clean();
	}

	// -- When it moves by itself -----------------------------------------------------------------------

	public function test_the_first_live_domain_moves_wordpress_and_the_temporary_address_redirects(): void {
		list( $r, $slug ) = $this->with_domain();
		$preview          = EdgeMap::preview_host( $slug );
		$this->assertSame( $preview, $this->node_site( $r, $slug )['host'] );
		$this->assertFalse( $this->queued( $r, $slug, 'example.org' ), 'Nothing is queued until the sweep or the check has looked.' );

		SiteWordPress::consider_all( $r );
		$this->assertTrue( $this->queued( $r, $slug, 'example.org' ), 'The main address (the form typed) is the target.' );

		$mark = count( $this->agent->calls() );
		$this->run_job( $r, $slug, 'example.org' );

		$calls = $this->since( $mark, 'site.wp_address' );
		$this->assertCount( 1, $calls );
		$this->assertSame( array( 'vmid' => $this->vmid( $r ), 'slug' => $slug, 'to' => 'example.org' ), $calls[0]['args'] );
		$this->assertSame( 'example.org', $this->node_site( $r, $slug )['wp_address'] );

		$site = $this->site( $r, $slug );
		$this->assertSame( 'example.org', $site['flags']['wp_address'] );
		$this->assertSame( $preview, $site['flags']['wp_prev'] );
		$this->assertSame( '', $site['flags']['wp_moving'] );
		$this->assertSame( '', $site['flags']['wp_note'] );

		// The names went again with the redirect of the temporary address, next to the redirect of the pair.
		$names = $this->since( $mark, 'site.domains' );
		$this->assertNotEmpty( $names );
		$this->assertEqualsCanonicalizing(
			array( array( 'from' => 'www.example.org', 'to' => 'example.org' ), array( 'from' => $preview, 'to' => 'example.org' ) ),
			end( $names )['args']['redirects']
		);
		$this->assertSame( $site['flags']['wp_address'], ( new HostingActions( Resource::find( $r->id() ) ) )->sites()[0]['wp']['address'] );
	}

	public function test_nothing_moves_before_both_names_of_a_pair_are_live(): void {
		list( $r, $slug ) = $this->with_domain( false );
		SiteWordPress::consider_all( $r );
		$this->assertFalse( $this->queued( $r, $slug, 'example.org' ), 'www is not live: the pair is not settled.' );
		$this->assertSame( 'example.org', ( new HostingActions( $r ) )->sites()[0]['main'], 'The page still shows the working name as the main address.' );

		$this->activate( 'www.example.org' );
		SiteWordPress::consider_all( Resource::find( $r->id() ) );
		$this->assertTrue( $this->queued( $r, $slug, 'example.org' ) );
	}

	public function test_a_subdomain_moves_as_soon_as_it_is_live_and_a_domain_that_is_not_live_never(): void {
		$r    = $this->account();
		$slug = 'w' . $r->id();
		( new HostingActions( $r ) )->add_domain( $slug, 'shop.example.org' );
		SiteWordPress::consider_all( Resource::find( $r->id() ) );
		$this->assertFalse( $this->queued( $r, $slug, 'shop.example.org' ), 'It waits for DNS.' );
		$this->activate( 'shop.example.org' );
		SiteWordPress::consider_all( Resource::find( $r->id() ) );
		$this->assertTrue( $this->queued( $r, $slug, 'shop.example.org' ) );
	}

	public function test_the_sweep_queues_the_move_when_a_name_becomes_active(): void {
		$r    = $this->account();
		$slug = 'w' . $r->id();
		$host = 'www.sweep.example';
		SiteDns::add_domain( Resource::find( $r->id() ), $slug, $host, \BeltoftServices\Hosting\Nodes::get( 'n1' ) );
		remove_all_filters( 'beltoft_services_dns_resolve' );
		add_filter( 'beltoft_services_dns_resolve', fn( $pre, $name, $type ) => 'TXT' === $type ? array( SiteDns::proof_token( $r->id(), $host ) ) : array( self::EDGE ), 10, 3 );

		$this->assertSame( array( $host => 'active' ), DomainSweep::run() );
		$this->assertTrue( $this->queued( $r, $slug, $host ) );
	}

	public function test_the_job_waits_for_a_public_lookup_that_shows_our_address(): void {
		list( $r, $slug ) = $this->with_domain();
		$this->dns_shows( '203.0.113.9' );
		SiteWordPress::consider_all( $r );
		$mark = count( $this->agent->calls() );
		$this->run_job( $r, $slug, 'example.org' );

		$this->assertSame( array(), $this->since( $mark, 'site.wp_address' ), 'The name does not reach us yet: WordPress would lock the customer out.' );
		$this->assertTrue( Jobs::pending_action( SiteWordPress::HOOK, array( $r->id(), $slug, 'example.org', 0, 1 ) ), 'It looks again later.' );
		$this->assertSame( '', (string) ( $this->site( $r, $slug )['flags']['wp_address'] ?? '' ) );
		$this->assertSame( '', (string) ( $this->site( $r, $slug )['flags']['wp_moving'] ?? '' ), 'Nothing is shown as under way while it only waits.' );
	}

	public function test_a_job_whose_reason_is_gone_does_nothing(): void {
		list( $r, $slug ) = $this->with_domain();
		SiteWordPress::consider_all( $r );
		SiteDomains::set_state( 'example.org', 'waiting' );
		$mark = count( $this->agent->calls() );
		$this->run_job( $r, $slug, 'example.org' );
		$this->assertSame( array(), $this->since( $mark, 'site.wp_address' ) );
	}

	// -- Foreign, failed -----------------------------------------------------------------------------------

	public function test_an_address_the_customer_set_is_left_alone_and_the_page_says_so(): void {
		list( $r, $slug ) = $this->with_domain();
		$this->agent->set_wp_address( $this->vmid( $r ), $slug, 'mine.example.com' );
		SiteWordPress::consider_all( $r );
		$mark = count( $this->agent->calls() );
		$this->run_job( $r, $slug, 'example.org' );

		$site = $this->site( $r, $slug );
		$this->assertSame( 'foreign', $site['flags']['wp_note'] );
		$this->assertSame( 'mine.example.com', $site['flags']['wp_seen'] );
		$this->assertSame( '', (string) ( $site['flags']['wp_address'] ?? '' ) );
		$this->assertSame( 'mine.example.com', $this->node_site( $r, $slug )['wp_address'], 'Nothing was changed on the node.' );
		foreach ( $this->since( $mark, 'site.domains' ) as $call ) {
			$this->assertNotContains( EdgeMap::preview_host( $slug ), array_column( (array) ( $call['args']['redirects'] ?? array() ), 'from' ), 'WordPress did not move: the temporary address does not redirect.' );
		}

		// Not tried again by itself.
		SiteWordPress::consider_all( Resource::find( $r->id() ) );
		$this->assertFalse( $this->queued( $r, $slug, 'example.org' ) );

		$html = $this->page( $r );
		$this->assertStringContainsString( 'WordPress is set to mine.example.com, an address you chose yourself. It was left as it is.', $html );
		$this->assertStringContainsString( 'Make example.org the WordPress address', $html );
		$this->assertStringContainsString( 'Replace mine.example.com with example.org in this website?', $html );
	}

	public function test_the_customer_can_replace_an_address_of_her_own_after_confirming(): void {
		list( $r, $slug ) = $this->with_domain();
		$this->agent->set_wp_address( $this->vmid( $r ), $slug, 'mine.example.com' );
		$this->run_job( $r, $slug, 'example.org' );
		$this->assertSame( 'foreign', $this->site( $r, $slug )['flags']['wp_note'] );

		$this->assertSame( 'example.org', ( new HostingActions( Resource::find( $r->id() ) ) )->wordpress_address( $slug, '' )['moving'] );
		$mark = count( $this->agent->calls() );
		$this->run_job( $r, $slug, 'example.org', 1 );

		$call = $this->since( $mark, 'site.wp_address' )[0]['args'];
		$this->assertSame( 'mine.example.com', $call['from'], 'The address the customer agreed to replace is named, so the node may touch it.' );
		$this->assertSame( 'example.org', $this->node_site( $r, $slug )['wp_address'] );
		$this->assertSame( '', $this->site( $r, $slug )['flags']['wp_note'] );
		$this->assertSame( '', $this->site( $r, $slug )['flags']['wp_from'] );
	}

	public function test_a_move_that_failed_and_was_put_back_says_so_and_is_not_repeated_by_itself(): void {
		list( $r, $slug ) = $this->with_domain();
		SiteWordPress::consider_all( $r );
		$this->agent->script_wp_address( array( 'failed' => true, 'restored' => true ) );
		$this->run_job( $r, $slug, 'example.org' );

		$this->assertSame( 'failed', $this->site( $r, $slug )['flags']['wp_note'] );
		$this->assertArrayNotHasKey( 'wp_address', $this->node_site( $r, $slug ), 'Nothing changed on the node.' );
		SiteWordPress::consider_all( Resource::find( $r->id() ) );
		$this->assertFalse( $this->queued( $r, $slug, 'example.org' ) );

		$html = $this->page( $r );
		$this->assertStringContainsString( 'Moving WordPress did not work. Your website was not changed.', $html );
		$this->assertStringContainsString( 'Make example.org the WordPress address', $html, 'The customer can try again.' );
	}

	public function test_a_job_that_fails_is_tried_again_later_and_a_dump_that_could_not_be_put_back_is_not(): void {
		list( $r, $slug ) = $this->with_domain();
		SiteWordPress::consider_all( $r );
		$this->agent->fail_next( 'site.wp_address', 'a safety copy of the database could not be made; nothing was changed' );
		$this->run_job( $r, $slug, 'example.org' );
		$this->assertTrue( Jobs::pending_action( SiteWordPress::HOOK, array( $r->id(), $slug, 'example.org', 0, 1 ) ) );

		$this->agent->fail_next( 'site.wp_address', 'the address change failed and the safety copy could not be put back' );
		SiteWordPress::run( $r->id(), $slug, 'example.org', 0, 1 );
		$this->assertFalse( Jobs::pending_action( SiteWordPress::HOOK, array( $r->id(), $slug, 'example.org', 0, 2 ) ) );
		$this->assertSame( 'failed', $this->site( $r, $slug )['flags']['wp_note'] );
		$this->assertNotEmpty( $this->mails, 'The operator is told.' );
	}

	// -- Manual, way back, change of main address --------------------------------------------------------------

	public function test_the_customers_button_moves_wordpress_in_the_background_and_the_way_back_is_offered(): void {
		list( $r, $slug ) = $this->with_domain();
		$a                = new HostingActions( $r );
		$out              = $a->wordpress_address( $slug, '' );

		$this->assertSame( 'example.org', $out['moving'] );
		$this->assertSame( 'WordPress is being moved to example.org. This can take a few minutes.', $out['message'] );
		$this->assertTrue( $this->queued( $r, $slug, 'example.org', 1 ) );
		$this->assertSame( 'busy', $a->wordpress_address( $slug, '' )->get_error_code(), 'One move at a time.' );
		$this->assertStringContainsString( 'WordPress is being moved to example.org. This can take a few minutes.', $this->page( $r ) );

		$this->run_job( $r, $slug, 'example.org', 1 );
		$this->assertSame( 'invalid', $a->wordpress_address( $slug, 'example.org' )->get_error_code(), 'It is there already.' );

		$html = $this->page( $r );
		$this->assertStringContainsString( 'WordPress now uses example.org.', $html );
		$this->assertStringContainsString( 'Go back to ' . EdgeMap::preview_host( $slug ), $html );
		$this->assertStringContainsString( 'Move WordPress back to ' . EdgeMap::preview_host( $slug ) . '? A copy of the database is made first, and put back if the change fails.', $html );

		// The way back is the same move to the old address; the redirect of the temporary address goes with it.
		$mark = count( $this->agent->calls() );
		$a->wordpress_address( $slug, EdgeMap::preview_host( $slug ) );
		$this->run_job( $r, $slug, EdgeMap::preview_host( $slug ), 1 );
		$this->assertSame( EdgeMap::preview_host( $slug ), $this->node_site( $r, $slug )['wp_address'] );
		$this->assertSame( '', (string) $this->site( $r, $slug )['flags']['wp_address'] );
		$last = $this->last( $this->since( $mark, 'site.domains' ) );
		$this->assertEqualsCanonicalizing( array( array( 'from' => 'www.example.org', 'to' => 'example.org' ) ), $last['args']['redirects'] );
	}

	public function test_the_way_back_lasts_seven_days(): void {
		list( $r, $slug ) = $this->with_domain();
		$this->run_job( $r, $slug, 'example.org', 1 );
		$site = $this->site( $r, $slug );
		$rows = array_values( array_filter( SiteDomains::for_resource( $r->id() ), static fn( $d ) => $d['site_id'] === $site['id'] ) );
		$prev = EdgeMap::preview_host( $slug );

		$this->assertSame( $prev, SiteWordPress::view( $site, $rows, $prev )['prev'] );
		$site['flags']['wp_at'] = Jobs::now() - 8 * DAY_IN_SECONDS;
		$this->assertSame( '', SiteWordPress::view( $site, $rows, $prev )['prev'] );
	}

	public function test_only_working_addresses_of_the_website_can_be_chosen_and_the_route_has_its_own_limit(): void {
		list( $r, $slug ) = $this->with_domain( true, false );
		$a                = new HostingActions( $r );

		$this->assertSame( 'invalid', $a->wordpress_address( $slug, 'example.org' )->get_error_code(), 'Not live yet.' );
		$this->assertSame( 'invalid', $a->wordpress_address( $slug, 'evil.example.com' )->get_error_code() );
		$this->assertSame( 'not_found', $a->wordpress_address( 'nosuch', '' )->get_error_code() );

		wp_set_current_user( (int) $r->license()['user_id'] );
		remove_all_filters( 'beltoft_services_rest_rate_limit' );
		global $wp_rest_server;
		$wp_rest_server = null;
		$statuses       = array();

		for ( $i = 0; $i < SiteWordPress::LIMIT + 1; $i++ ) {
			$request = new \WP_REST_Request( 'POST', '/' . Routes::NAMESPACE . '/resources/' . $r->id() . '/hosting/sites/' . $slug . '/wordpress-address' );
			$request->set_header( 'content-type', 'application/json' );
			$request->set_body( (string) wp_json_encode( array( 'host' => 'evil.example.com' ) ) );
			$statuses[] = rest_get_server()->dispatch( $request )->get_status();
		}

		$this->assertSame( 429, end( $statuses ), 'Every move copies the database: five an hour.' );
		$this->assertSame( 422, $statuses[0] );
	}

	public function test_the_route_queues_the_move_and_answers_202(): void {
		list( $r, $slug ) = $this->with_domain();
		wp_set_current_user( (int) $r->license()['user_id'] );
		global $wp_rest_server;
		$wp_rest_server = null;
		$request        = new \WP_REST_Request( 'POST', '/' . Routes::NAMESPACE . '/resources/' . $r->id() . '/hosting/sites/' . $slug . '/wordpress-address' );
		$request->set_header( 'content-type', 'application/json' );
		$request->set_body( (string) wp_json_encode( array( 'host' => 'example.org' ) ) );
		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 202, $response->get_status() );
		$this->assertSame( 'example.org', $response->get_data()['moving'] );
		$this->assertTrue( $this->queued( $r, $slug, 'example.org', 1 ) );
	}

	public function test_choosing_the_other_name_of_a_pair_moves_wordpress_first_and_the_redirects_follow(): void {
		list( $r, $slug ) = $this->with_domain();
		$this->run_job( $r, $slug, 'example.org', 1 );
		$a    = new HostingActions( Resource::find( $r->id() ) );
		$mark = count( $this->agent->calls() );

		$this->assertSame( array( 'main' => 'www.example.org', 'redirect' => true ), $a->set_main_address( 'www.example.org' ) );
		$this->assertSame( array(), $this->since( $mark, 'site.domains' ), 'No redirect is sent while WordPress is still on the other name: it would send the visitor back.' );
		$this->assertTrue( $this->queued( $r, $slug, 'www.example.org' ) );

		$this->run_job( $r, $slug, 'www.example.org' );
		$this->assertSame( 'www.example.org', $this->node_site( $r, $slug )['wp_address'] );
		$last = $this->last( $this->since( $mark, 'site.domains' ) );
		$this->assertEqualsCanonicalizing(
			array( array( 'from' => 'example.org', 'to' => 'www.example.org' ), array( 'from' => EdgeMap::preview_host( $slug ), 'to' => 'www.example.org' ) ),
			$last['args']['redirects']
		);
		$this->assertStringContainsString( 'WordPress moves to the main address too.', $this->page( $r ) );
	}

	public function test_a_choice_when_wordpress_is_on_the_temporary_address_sends_the_redirect_at_once_as_before(): void {
		list( $r, $slug ) = $this->with_domain();
		$a                = new HostingActions( $r );
		$mark             = count( $this->agent->calls() );

		$this->assertSame( array( 'main' => 'www.example.org', 'redirect' => true ), $a->set_main_address( 'www.example.org' ) );
		$this->assertSame( array( array( 'from' => 'example.org', 'to' => 'www.example.org' ) ), $this->since( $mark, 'site.domains' )[0]['args']['redirects'] );
		$this->assertStringContainsString( 'This does not change the address set in WordPress.', $this->page( $r ) );
	}

	// -- The domain stops, is removed ------------------------------------------------------------------------------

	public function test_the_temporary_address_is_a_way_in_again_when_the_domain_stops_pointing_here(): void {
		list( $r, $slug ) = $this->with_domain();
		$this->run_job( $r, $slug, 'example.org', 1 );
		$preview = EdgeMap::preview_host( $slug );
		$mark    = count( $this->agent->calls() );

		$this->activate( 'example.org', 'www.example.org' );
		SiteDomains::set_state( 'example.org', 'waiting' );
		SiteDomains::set_state( 'www.example.org', 'waiting' );
		( new HostingActions( Resource::find( $r->id() ) ) )->sync_edge();

		$last = $this->last( $this->since( $mark, 'site.domains' ) );
		$this->assertArrayNotHasKey( 'redirects', $last['args'], 'The redirect of the temporary address is gone.' );
		$this->assertContains( 'example.org', $last['args']['domains'], 'The node still knows the name WordPress is on, so it can be moved back.' );

		// WordPress is not moved back by itself; the page says what it uses and offers the way.
		$this->assertSame( 'example.org', $this->node_site( $r, $slug )['wp_address'] );
		$html = $this->page( $r );
		$this->assertStringContainsString( 'WordPress uses example.org, which does not point to your hosting now.', $html );
		$this->assertStringContainsString( 'Make ' . $preview . ' the WordPress address', $html );

		$a = new HostingActions( Resource::find( $r->id() ) );
		$this->assertSame( $preview, $a->wordpress_address( $slug, '' )['moving'] );
		$this->run_job( $r, $slug, $preview, 1 );
		$this->assertSame( $preview, $this->node_site( $r, $slug )['wp_address'] );
	}

	public function test_the_domain_wordpress_is_on_is_not_removed_by_the_customer_but_leaves_with_its_add_on(): void {
		list( $r, $slug ) = $this->with_domain();
		$this->run_job( $r, $slug, 'example.org', 1 );
		$a = new HostingActions( Resource::find( $r->id() ) );

		$refused = $a->remove_domain( 'example.org' );
		$this->assertWPError( $refused );
		$this->assertSame( 'invalid', $refused->get_error_code() );
		$this->assertSame( 'WordPress uses this address. Make another address the WordPress address first, then remove this one.', $refused->get_error_message() );
		$this->assertNotNull( SiteDomains::find( 'example.org' ) );

		// The add-on ended: the names go, WordPress is moved back to the temporary address first.
		$mark = count( $this->agent->calls() );
		$this->assertSame( array( 'removed' => 'example.org' ), $a->remove_domain( 'example.org', true ) );
		$this->assertSame( EdgeMap::preview_host( $slug ), $this->node_site( $r, $slug )['wp_address'] );
		$this->assertSame( '', (string) $this->site( $r, $slug )['flags']['wp_address'] );
		$ops = array_column( array_slice( $this->agent->calls(), $mark ), 'op' );
		$this->assertLessThan( array_search( 'site.domains', $ops, true ), array_search( 'site.wp_address', $ops, true ), 'Moved back while the node still knows the name.' );
		$this->assertEmpty( $this->mails );
	}

	public function test_a_name_that_leaves_before_wordpress_could_move_back_tells_the_operator(): void {
		list( $r, $slug ) = $this->with_domain();
		$this->run_job( $r, $slug, 'example.org', 1 );
		$this->agent->fail_next( 'site.wp_address', 'scripted failure' );

		$this->assertSame( array( 'removed' => 'example.org' ), ( new HostingActions( Resource::find( $r->id() ) ) )->remove_domain( 'example.org', true ) );
		$this->assertNotEmpty( $this->mails );
		$this->assertStringContainsString( 'left on a removed domain', (string) end( $this->mails )['subject'] );
	}

	public function test_a_name_wordpress_is_not_on_is_removed_as_before(): void {
		list( $r, $slug ) = $this->with_domain();
		$this->run_job( $r, $slug, 'example.org', 1 );
		$mark = count( $this->agent->calls() );

		$this->assertSame( array( 'removed' => 'www.example.org' ), ( new HostingActions( Resource::find( $r->id() ) ) )->remove_domain( 'www.example.org' ) );
		$this->assertSame( array(), $this->since( $mark, 'site.wp_address' ) );
	}

	// -- Login, page, older agent --------------------------------------------------------------------------------------

	public function test_the_login_links_and_the_page_follow_the_wordpress_address(): void {
		list( $r, $slug ) = $this->with_domain();
		$a                = new HostingActions( $r );

		$this->assertStringContainsString( 'https://' . EdgeMap::preview_host( $slug ) . '/', $a->login_link( $slug )['url'] );
		$this->assertStringContainsString( 'https://' . EdgeMap::preview_host( $slug ) . '/wp-login.php', $this->page( $r ) );

		$this->run_job( $r, $slug, 'example.org', 1 );
		$this->assertStringStartsWith( 'https://example.org/', $a->login_link( $slug )['url'] );
		$this->assertStringStartsWith( 'https://example.org/', $a->password_link( $slug )['url'] );
		$html = $this->page( $r );
		$this->assertStringContainsString( 'log in at https://example.org/wp-login.php.', $html );
		$this->assertStringNotContainsString( 'https://' . EdgeMap::preview_host( $slug ) . '/wp-login.php', $html );
		$this->assertMatchesRegularExpression( '/example\.org<\/span> <span class="beltoft-hosting-note">Main address, WordPress address/', $html );
		$this->assertMatchesRegularExpression( '/' . preg_quote( EdgeMap::preview_host( $slug ), '/' ) . '<\/span> <span class="beltoft-hosting-note">Temporary address/', $html );
	}

	public function test_a_website_without_a_domain_of_its_own_says_nothing_new(): void {
		$r    = $this->account();
		$slug = 'w' . $r->id();
		$html = $this->page( $r );

		$this->assertStringNotContainsString( 'WordPress address', $html );
		$this->assertStringNotContainsString( 'data-wp-address', $html );
		$this->assertStringContainsString( 'https://' . EdgeMap::preview_host( $slug ) . '/wp-login.php', $html );
		$this->assertFalse( $this->queued( $r, $slug, EdgeMap::preview_host( $slug ) ) );
	}

	public function test_an_older_agent_gets_nothing_automatic_and_the_page_says_nothing_new(): void {
		list( $r, $slug ) = $this->with_domain();
		$this->agent->set_unsupported( array( 'site.wp_address' ) );
		SiteWordPress::consider_all( $r );
		$this->assertTrue( $this->queued( $r, $slug, 'example.org' ) );
		$this->run_job( $r, $slug, 'example.org' );

		$site = $this->site( $r, $slug );
		$this->assertSame( '', (string) ( $site['flags']['wp_note'] ?? '' ), 'No error, no note.' );
		$this->assertSame( '', (string) ( $site['flags']['wp_moving'] ?? '' ) );
		$this->assertGreaterThan( 0, (int) Resource::find( $r->id() )->get( 'wp_address_unsupported' ) );

		// It is not asked again for a few hours, and nothing is queued by the next sweep.
		SiteWordPress::consider_all( Resource::find( $r->id() ) );
		$this->assertFalse( Jobs::pending_action( SiteWordPress::HOOK, array( $r->id(), $slug, 'example.org', 0, 1 ) ) );
		$mark = count( $this->agent->calls() );
		SiteWordPress::consider_all( Resource::find( $r->id() ) );
		$this->assertSame( array(), array_slice( $this->agent->calls(), $mark ) );

		// The names and the pair's redirect still work as before.
		( new HostingActions( Resource::find( $r->id() ) ) )->sync_edge();
		$this->assertSame( array( array( 'from' => 'www.example.org', 'to' => 'example.org' ) ), $this->agent->state()['sites'][ $this->vmid( $r ) ][ $slug ]['redirects'] );
		$this->assertStringNotContainsString( 'cannot move WordPress', $this->page( $r ) );
	}

	public function test_a_customer_who_asks_on_an_older_agent_is_told_plainly(): void {
		list( $r, $slug ) = $this->with_domain();
		$this->agent->set_unsupported( array( 'site.wp_address' ) );
		( new HostingActions( $r ) )->wordpress_address( $slug, '' );
		$this->run_job( $r, $slug, 'example.org', 1 );

		$this->assertSame( 'unsupported', $this->site( $r, $slug )['flags']['wp_note'] );
		$this->assertStringContainsString( 'Your hosting cannot move WordPress to another address yet. Contact support.', $this->page( $r ) );
	}

	public function test_the_pure_rules(): void {
		$preview = 'w1.preview.example.test';
		$row     = static fn( string $host, string $state, string $mode = '' ) => array( 'id' => 1, 'site_id' => 1, 'host' => $host, 'kind' => 'external', 'state' => $state, 'www_mode' => $mode );
		$site    = array( 'id' => 1, 'flags' => array() );
		$pair    = array( $row( 'a.org', 'active', 'apex' ), $row( 'www.a.org', 'active' ) );

		$this->assertSame( 'a.org', SiteWordPress::auto_target( $site, $pair, $preview, false ) );
		$this->assertSame( '', SiteWordPress::auto_target( $site, array( $row( 'a.org', 'active', 'apex' ), $row( 'www.a.org', 'waiting' ) ), $preview, false ) );
		$this->assertSame( '', SiteWordPress::auto_target( $site, array( $row( 'a.org', 'active', '' ), $row( 'www.a.org', 'active' ) ), $preview, false ), 'A pair without a chosen main address is not settled.' );
		$this->assertSame( 'sub.a.org', SiteWordPress::auto_target( $site, array( $row( 'sub.a.org', 'active' ) ), $preview, false ) );
		$this->assertSame( '', SiteWordPress::auto_target( $site, array(), $preview, false ) );

		// On an own name it follows only the customer's choice inside the same pair.
		$on_www = array( 'id' => 1, 'flags' => array( 'wp_address' => 'www.a.org' ) );
		$this->assertSame( '', SiteWordPress::auto_target( $on_www, $pair, $preview, false ) );
		$this->assertSame( 'a.org', SiteWordPress::auto_target( $on_www, $pair, $preview, true ) );
		$other = array( 'id' => 1, 'flags' => array( 'wp_address' => 'b.org' ) );
		$this->assertSame( '', SiteWordPress::auto_target( $other, $pair, $preview, true ), 'Another domain is the customer\'s button.' );
		$this->assertSame( '', SiteWordPress::auto_target( array( 'id' => 1, 'flags' => array( 'wp_note' => 'foreign' ) ), $pair, $preview, false ) );
		$this->assertSame( '', SiteWordPress::auto_target( array( 'id' => 1, 'flags' => array( 'wp_moving' => 'a.org' ) ), $pair, $preview, false ) );

		$this->assertSame( array( 'from' => $preview, 'to' => 'a.org' ), SiteWordPress::preview_redirect( array( 'flags' => array( 'wp_address' => 'a.org' ) ), $pair, $preview ) );
		$this->assertNull( SiteWordPress::preview_redirect( array( 'flags' => array( 'wp_address' => 'www.a.org' ) ), $pair, $preview ), 'WordPress is not on the main address.' );
		$this->assertNull( SiteWordPress::preview_redirect( array( 'flags' => array() ), $pair, $preview ) );
		$this->assertNull( SiteWordPress::preview_redirect( array( 'flags' => array( 'wp_address' => 'a.org', 'wp_moving' => 'www.a.org' ) ), $pair, $preview ), 'Not while a move is under way.' );
		$this->assertSame( array( 'a.org', 'www.a.org' ), array( SiteAddresses::base_of( 'a.org' ), 'www.' . SiteAddresses::base_of( 'www.a.org' ) ) );
	}
}

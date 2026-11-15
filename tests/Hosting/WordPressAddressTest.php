<?php
/**
 * WordPress moves to the customer's own domain when it goes live: when, how, what the page says, what happens when the
 * domain stops pointing here, is removed, or the agent is older. The move is a change of the account on the shared action
 * controller (the action `wordpress_address`). Everything runs against the FakeAgent; the jobs are run the way the runner runs
 * them (HostingCase::run_account()).
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Hosting;

require_once __DIR__ . '/HostingCase.php';

use BeltoftServices\Api\Routes;
use BeltoftServices\Hosting\Actions\Steps;
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
use BeltoftServices\Progress;
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

	/**
	 * What public lookups answer for the connected names: our address, and the ownership proof the sweep asks for.
	 *
	 * @param Resource $r Resource.
	 */
	private function dns_proves( Resource $r ): void {
		remove_all_filters( 'beltoft_services_dns_resolve' );
		add_filter(
			'beltoft_services_dns_resolve',
			static fn( $pre, $name, $type ) => 'TXT' === $type ? array( SiteDns::proof_token( $r->id(), 'example.org' ), SiteDns::proof_token( $r->id(), 'www.example.org' ) ) : array( self::EDGE ),
			10,
			3
		);
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
	 * Runs what the account has queued, the way the runner does.
	 */
	private function run_job( Resource $r ): void {
		$this->run_account( $r );
	}

	/**
	 * Cancels every queued action (whatever its arguments).
	 */
	private function clear_queue(): void {
		foreach ( as_get_scheduled_actions( array( 'hook' => Jobs::ACT, 'status' => \ActionScheduler_Store::STATUS_PENDING, 'per_page' => -1 ), 'ids' ) as $id ) {
			\ActionScheduler::store()->cancel_action( (int) $id );
		}
	}

	/**
	 * Is the move to a host queued (or running) for the account?
	 */
	private function queued( Resource $r, string $slug, string $to ): bool {
		wp_cache_flush();
		$task = Steps::view( Resource::find( $r->id() ) );

		return null !== $task && 'wordpress_address' === $task['action'] && $slug === $task['slug'] && $to === (string) ( $task['args'][1] ?? '' );
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
		$this->assertTrue( $this->queued( $r, $slug, 'example.org' ), 'The main address (the form typed) is the target, queued through the shared controller.' );
		$this->assertTrue( Jobs::acting( $r->id() ) );

		$mark = count( $this->agent->calls() );
		$this->run_job( $r );

		$calls = $this->since( $mark, 'site.wp_address' );
		$this->assertCount( 1, $calls );
		$this->assertSame( array( 'vmid' => $this->vmid( $r ), 'slug' => $slug, 'to' => 'example.org' ), $calls[0]['args'] );
		$this->assertSame( 'example.org', $this->node_site( $r, $slug )['wp_address'] );

		$site = $this->site( $r, $slug );
		$this->assertSame( 'example.org', $site['flags']['wp_address'] );
		$this->assertSame( $preview, $site['flags']['wp_prev'] );
		$this->assertSame( '', $site['flags']['wp_note'] );
		$this->assertArrayNotHasKey( 'wp_moving', $site['flags'], 'What is under way is the account\'s task, not a flag of the website.' );
		$this->assertSame( array( 'wordpress_address', true ), array( $this->last_outcome( $r )['action'], $this->last_outcome( $r )['ok'] ) );

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

	public function test_the_sweep_sends_the_names_and_then_moves_wordpress_when_a_name_becomes_active(): void {
		$r    = $this->account();
		$slug = 'w' . $r->id();
		$host = 'www.sweep.example';
		SiteDns::add_domain( Resource::find( $r->id() ), $slug, $host, \BeltoftServices\Hosting\Nodes::get( 'n1' ) );
		remove_all_filters( 'beltoft_services_dns_resolve' );
		add_filter( 'beltoft_services_dns_resolve', fn( $pre, $name, $type ) => 'TXT' === $type ? array( SiteDns::proof_token( $r->id(), $host ) ) : array( self::EDGE ), 10, 3 );

		$this->assertSame( array( $host => 'active' ), DomainSweep::run() );
		$task = Steps::view( Resource::find( $r->id() ) );
		$this->assertSame( 'names', $task['action'], 'The sweep queues the change that sends the names; it sends nothing itself.' );
		$this->assertSame( array(), $this->agent->calls_of( 'site.wp_address' ) );

		// That change ends, and the address of WordPress is looked at: the move is queued after it and runs.
		$this->run_job( $r );
		$this->assertSame( $host, $this->node_site( $r, $slug )['wp_address'] );
		$this->assertSame( 'wordpress_address', $this->last_outcome( $r )['action'] );
	}

	public function test_nothing_is_queued_while_a_public_lookup_does_not_show_our_address(): void {
		list( $r, $slug ) = $this->with_domain();
		$this->dns_shows( '203.0.113.9' );
		SiteWordPress::consider_all( $r );
		$mark = count( $this->agent->calls() );

		$this->assertFalse( $this->queued( $r, $slug, 'example.org' ), 'The name does not reach us yet: WordPress would lock the customer out.' );
		$this->run_job( $r );
		$this->assertSame( array(), $this->since( $mark, 'site.wp_address' ) );
		$this->assertSame( '', (string) ( $this->site( $r, $slug )['flags']['wp_address'] ?? '' ) );
		$this->assertNull( Steps::view( Resource::find( $r->id() ) ), 'Nothing is shown as under way while it only waits.' );

		// The sweep looks again, and once the lookup shows our address the move is queued.
		$this->dns_proves( $r );
		DomainSweep::run();
		$this->assertTrue( $this->queued( $r, $slug, 'example.org' ) );
	}

	public function test_a_job_whose_reason_is_gone_does_nothing(): void {
		list( $r, $slug ) = $this->with_domain();
		SiteWordPress::consider_all( $r );
		SiteDomains::set_state( 'example.org', 'waiting' );
		$mark = count( $this->agent->calls() );
		$this->run_job( $r );
		$this->assertSame( array(), $this->since( $mark, 'site.wp_address' ) );
		$this->assertTrue( $this->last_outcome( $r )['ok'], 'It ended well: there was nothing to do.' );
	}

	public function test_a_move_the_account_is_too_busy_for_is_looked_at_again_by_the_sweep(): void {
		list( $r, $slug ) = $this->with_domain();
		Progress::mark( Resource::find( $r->id() ), 'purge_cache', 'Clearing the cache' );

		SiteWordPress::consider_all( Resource::find( $r->id() ) );
		$this->assertFalse( $this->queued( $r, $slug, 'example.org' ), 'One change at a time: the move does not wait in a queue of its own.' );
		$this->assertSame( 'purge_cache', Steps::view( Resource::find( $r->id() ) )['action'] ?? 'purge_cache' );

		Progress::clear( Resource::find( $r->id() ) );
		$this->dns_proves( $r );
		DomainSweep::run();
		$this->assertTrue( $this->queued( $r, $slug, 'example.org' ) );
	}

	// -- Foreign, failed -----------------------------------------------------------------------------------

	public function test_an_address_the_customer_set_is_left_alone_and_the_page_says_so(): void {
		list( $r, $slug ) = $this->with_domain();
		$this->agent->set_wp_address( $this->vmid( $r ), $slug, 'mine.example.com' );
		SiteWordPress::consider_all( $r );
		$mark = count( $this->agent->calls() );
		$this->run_job( $r );

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
		SiteWordPress::consider_all( $r );
		$this->run_job( $r );
		$this->assertSame( 'foreign', $this->site( $r, $slug )['flags']['wp_note'] );

		$out = ( new HostingActions( Resource::find( $r->id() ) ) )->queue( 'wordpress_address', array( $slug, '' ) );
		$this->assertSame( 'example.org', $out['moving'] );
		$this->assertSame( 'mine.example.com', Steps::view( Resource::find( $r->id() ) )['args'][2], 'The address she agreed to replace is the job\'s argument.' );
		$mark = count( $this->agent->calls() );
		$this->run_job( $r );

		$call = $this->since( $mark, 'site.wp_address' )[0]['args'];
		$this->assertSame( 'mine.example.com', $call['from'], 'The address the customer agreed to replace is named, so the node may touch it.' );
		$this->assertSame( 'example.org', $this->node_site( $r, $slug )['wp_address'] );
		$this->assertSame( '', $this->site( $r, $slug )['flags']['wp_note'] );
		$this->assertArrayNotHasKey( 'wp_from', $this->site( $r, $slug )['flags'] );
	}

	public function test_a_move_that_failed_and_was_put_back_says_so_and_is_not_repeated_by_itself(): void {
		list( $r, $slug ) = $this->with_domain();
		SiteWordPress::consider_all( $r );
		$this->agent->script_wp_address( array( 'failed' => true, 'restored' => true ) );
		$this->run_job( $r );

		$this->assertSame( 'failed', $this->site( $r, $slug )['flags']['wp_note'] );
		$this->assertArrayNotHasKey( 'wp_address', $this->node_site( $r, $slug ), 'Nothing changed on the node.' );
		$out = $this->last_outcome( $r );
		$this->assertFalse( $out['ok'] );
		$this->assertSame( 'Moving WordPress did not work. Your website was not changed.', $out['text'] );
		$this->assertSame( array(), $this->mails, 'The node put the database back: nothing for staff to do.' );
		SiteWordPress::consider_all( Resource::find( $r->id() ) );
		$this->assertFalse( $this->queued( $r, $slug, 'example.org' ) );

		$html = $this->page( $r );
		$this->assertSame( 1, substr_count( $html, 'Moving WordPress did not work. Your website was not changed.' ), 'Said once, in the website\'s own block.' );
		$this->assertStringContainsString( 'Make example.org the WordPress address', $html, 'The customer can try again.' );
	}

	public function test_a_job_that_fails_is_not_repeated_by_a_loop_of_its_own_and_staff_are_told(): void {
		list( $r, $slug ) = $this->with_domain();
		SiteWordPress::consider_all( $r );
		$this->agent->fail_next( 'site.wp_address', 'a safety copy of the database could not be made; nothing was changed' );
		$this->run_job( $r );

		$this->assertCount( 1, $this->agent_jobs( 'site.wp_address' ), 'One try: the shared controller does not start a failed agent job again.' );
		$this->assertSame( 'failed', $this->site( $r, $slug )['flags']['wp_note'] );
		$this->assertFalse( $this->last_outcome( $r )['ok'] );
		$this->assertCount( 1, $this->mails, 'Staff are told once by the shared alert.' );
		$this->assertFalse( Jobs::acting( $r->id() ) );

		// A dump that could not be put back is a person's job: the same, and the operator is told.
		$this->mails = array();
		$this->site( $r, $slug );
		Sites::update( $r->id(), $slug, array( 'flags' => array( 'wp_note' => '' ) ) );
		$this->agent->fail_next( 'site.wp_address', 'the address change failed and the safety copy could not be put back' );
		$this->change( $r, 'wordpress_address', array( $slug, '' ) );
		$this->assertSame( 'failed', $this->site( $r, $slug )['flags']['wp_note'] );
		$this->assertNotEmpty( $this->mails, 'The operator is told.' );
	}

	// -- Crash, resume, one change at a time -----------------------------------------------------------------------------

	public function test_a_lost_move_goes_on_where_it_stopped_and_starts_nothing_twice(): void {
		list( $r, $slug ) = $this->with_domain();
		$this->agent->pending( 'site.wp_address', 3 );
		SiteWordPress::consider_all( $r );

		$this->assertTrue( $this->run_one( $r ), 'The first run asks the node.' );
		$this->kill_job( $r );

		$this->assertTrue( Jobs::acting( $r->id() ), 'Queued again.' );
		$this->assertArrayNotHasKey( 'ok', $this->last_outcome( $r ), 'No failed result.' );
		$this->assertSame( 'example.org', (string) Steps::view( Resource::find( $r->id() ) )['args'][1], 'The page still says where it goes.' );

		$this->run_job( $r );
		$this->assertSame( 'example.org', $this->node_site( $r, $slug )['wp_address'] );
		$this->assertCount( 1, $this->agent_jobs( 'site.wp_address' ), 'The node was asked once.' );
		$this->assertTrue( $this->last_outcome( $r )['ok'] );
	}

	public function test_a_move_nobody_works_on_any_more_ends_failed_and_the_website_is_as_it_was(): void {
		list( $r, $slug ) = $this->with_domain();
		SiteWordPress::consider_all( $r );
		$this->lose_the_change( $r );
		Jobs::sweep();

		$this->assertFalse( $this->last_outcome( $r )['ok'] );
		$this->assertNull( Steps::view( Resource::find( $r->id() ) ) );
		$this->assertSame( '', (string) ( $this->site( $r, $slug )['flags']['wp_address'] ?? '' ) );
		$this->assertCount( 1, $this->mails );
		$this->assertFalse( $this->queued( $r, $slug, 'example.org' ) );
	}

	public function test_the_move_and_the_other_changes_wait_for_each_other(): void {
		list( $r, $slug ) = $this->with_domain();
		$a = new HostingActions( $r );
		$a->queue( 'wordpress_address', array( $slug, '' ) );

		foreach ( array( $a->queue( 'purge_cache', array( $slug ) ), $a->queue( 'create_staging', array( $slug ) ), $a->queue( 'backup_now' ), $a->queue( 'wordpress_address', array( $slug, '' ) ) ) as $out ) {
			$this->assertWPError( $out );
			$this->assertSame( 'busy', $out->get_error_code() );
			$this->assertSame( 'Wait until moving WordPress to another address is done.', $out->get_error_message() );
		}

		$this->run_job( $r );
		$this->change( $r, 'create_staging', array( $slug ) );
		( new HostingActions( Resource::find( $r->id() ) ) )->queue( 'refresh_staging', array( $slug ) );
		$this->assertSame( 'busy', ( new HostingActions( Resource::find( $r->id() ) ) )->queue( 'wordpress_address', array( $slug, EdgeMap::preview_host( $slug ) ) )->get_error_code() );
	}

	// -- Manual, way back, change of main address --------------------------------------------------------------

	public function test_the_customers_button_moves_wordpress_in_the_background_and_the_way_back_is_offered(): void {
		list( $r, $slug ) = $this->with_domain();
		$a                = new HostingActions( $r );
		$out              = $a->queue( 'wordpress_address', array( $slug, '' ) );

		$this->assertSame( 'example.org', $out['moving'] );
		$this->assertSame( 'WordPress is being moved to example.org. This can take a few minutes.', $out['message'] );
		$this->assertSame( 'pending', $out['status'] );
		$this->assertSame( 'wordpress_address', $out['task']['action'] );
		$this->assertTrue( $this->queued( $r, $slug, 'example.org' ) );
		$this->assertSame( array(), $this->agent->calls_of( 'site.wp_address' ), 'Nothing reaches the node inside the request.' );
		$this->assertSame( 'busy', ( new HostingActions( Resource::find( $r->id() ) ) )->queue( 'wordpress_address', array( $slug, '' ) )->get_error_code(), 'One move at a time.' );
		$this->assertStringContainsString( 'WordPress is being moved to example.org. This can take a few minutes.', $this->page( $r ) );
		$this->assertSame( 'example.org', ( new HostingActions( Resource::find( $r->id() ) ) )->sites()[0]['wp']['moving'] );

		$this->run_job( $r );
		$this->assertSame( 'invalid', ( new HostingActions( Resource::find( $r->id() ) ) )->queue( 'wordpress_address', array( $slug, 'example.org' ) )->get_error_code(), 'It is there already.' );

		$html = $this->page( $r );
		$this->assertStringContainsString( 'WordPress now uses example.org.', $html );
		$this->assertStringContainsString( 'Go back to ' . EdgeMap::preview_host( $slug ), $html );
		$this->assertStringContainsString( 'Move WordPress back to ' . EdgeMap::preview_host( $slug ) . '? A copy of the database is made first, and put back if the change fails.', $html );

		// The way back is the same move to the old address; the redirect of the temporary address goes with it.
		$mark = count( $this->agent->calls() );
		$this->change( $r, 'wordpress_address', array( $slug, EdgeMap::preview_host( $slug ) ) );
		$this->assertSame( EdgeMap::preview_host( $slug ), $this->node_site( $r, $slug )['wp_address'] );
		$this->assertSame( '', (string) $this->site( $r, $slug )['flags']['wp_address'] );
		$last = $this->last( $this->since( $mark, 'site.domains' ) );
		$this->assertEqualsCanonicalizing( array( array( 'from' => 'www.example.org', 'to' => 'example.org' ) ), $last['args']['redirects'] );
	}

	public function test_the_way_back_lasts_seven_days(): void {
		list( $r, $slug ) = $this->with_domain();
		$this->change( $r, 'wordpress_address', array( $slug, 'example.org' ) );
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

		$this->assertSame( 'invalid', $a->queue( 'wordpress_address', array( $slug, 'example.org' ) )->get_error_code(), 'Not live yet.' );
		$this->assertSame( 'invalid', $a->queue( 'wordpress_address', array( $slug, 'evil.example.com' ) )->get_error_code() );
		$this->assertSame( 'not_found', $a->queue( 'wordpress_address', array( 'nosuch', '' ) )->get_error_code() );
		$this->assertFalse( Jobs::acting( $r->id() ), 'A refusal queues nothing.' );

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

	public function test_the_route_queues_the_move_and_answers_202_with_the_task(): void {
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
		$this->assertSame( 'wordpress_address', $response->get_data()['task']['action'] );
		$this->assertTrue( $this->queued( $r, $slug, 'example.org' ) );

		// A second request while it runs is refused with the shared words.
		$again = rest_get_server()->dispatch( $request );
		$this->assertSame( 409, $again->get_status() );
		$this->assertStringContainsString( 'Wait until moving WordPress to another address is done.', $again->get_data()['message'] );
	}

	public function test_choosing_the_other_name_of_a_pair_moves_wordpress_first_and_the_redirects_follow(): void {
		list( $r, $slug ) = $this->with_domain();
		$this->change( $r, 'wordpress_address', array( $slug, 'example.org' ) );
		$a    = new HostingActions( Resource::find( $r->id() ) );
		$mark = count( $this->agent->calls() );

		$this->assertSame( array( 'main' => 'www.example.org', 'redirect' => true ), $a->queue( 'set_main_address', array( 'www.example.org' ) ) );
		$this->assertSame( array(), $this->since( $mark, 'site.domains' ), 'No redirect is sent while WordPress is still on the other name: it would send the visitor back.' );
		$this->assertTrue( $this->queued( $r, $slug, 'www.example.org' ) );

		$this->run_job( $r );
		$this->assertSame( 'www.example.org', $this->node_site( $r, $slug )['wp_address'] );
		$last = $this->last( $this->since( $mark, 'site.domains' ) );
		$this->assertEqualsCanonicalizing(
			array( array( 'from' => 'example.org', 'to' => 'www.example.org' ), array( 'from' => EdgeMap::preview_host( $slug ), 'to' => 'www.example.org' ) ),
			$last['args']['redirects']
		);
		$this->assertStringContainsString( 'WordPress moves to the main address too.', $this->page( $r ) );
	}

	public function test_a_choice_when_wordpress_is_on_the_temporary_address_sends_the_redirect_as_before(): void {
		list( $r, $slug ) = $this->with_domain();
		$mark             = count( $this->agent->calls() );

		$out = ( new HostingActions( $r ) )->queue( 'set_main_address', array( 'www.example.org' ) );
		$this->assertSame( array( 'main' => 'www.example.org', 'redirect' => true ), array( 'main' => $out['main'], 'redirect' => $out['redirect'] ) );
		$this->assertSame( 'pending', $out['status'] );
		$this->assertSame( array(), $this->since( $mark, 'site.domains' ), 'Nothing reaches the node inside the request.' );
		$this->assertStringContainsString( 'This does not change the address set in WordPress.', $this->page( $r ) );

		// The change that sends the names runs first; the redirect of the pair goes out with it.
		$this->assertTrue( $this->run_one( $r ) );
		$this->assertSame( array( array( 'from' => 'example.org', 'to' => 'www.example.org' ) ), $this->since( $mark, 'site.domains' )[0]['args']['redirects'] );
	}

	// -- The domain stops, is removed ------------------------------------------------------------------------------

	public function test_the_temporary_address_is_a_way_in_again_when_the_domain_stops_pointing_here(): void {
		list( $r, $slug ) = $this->with_domain();
		$this->change( $r, 'wordpress_address', array( $slug, 'example.org' ) );
		$preview = EdgeMap::preview_host( $slug );
		$mark    = count( $this->agent->calls() );

		$this->activate( 'example.org', 'www.example.org' );
		SiteDomains::set_state( 'example.org', 'waiting' );
		SiteDomains::set_state( 'www.example.org', 'waiting' );

		// The sweep sees that the node is behind and queues the change that sends the names.
		$this->assertTrue( ( new HostingActions( Resource::find( $r->id() ) ) )->names_stale() );
		DomainSweep::run();
		$this->run_job( $r );

		$last = $this->last( $this->since( $mark, 'site.domains' ) );
		$this->assertArrayNotHasKey( 'redirects', $last['args'], 'The redirect of the temporary address is gone.' );
		$this->assertContains( 'example.org', $last['args']['domains'], 'The node still knows the name WordPress is on, so it can be moved back.' );
		$this->assertFalse( ( new HostingActions( Resource::find( $r->id() ) ) )->names_stale(), 'The node is as the shop says.' );

		// WordPress is not moved back by itself; the page says what it uses and offers the way.
		$this->assertSame( 'example.org', $this->node_site( $r, $slug )['wp_address'] );
		$html = $this->page( $r );
		$this->assertStringContainsString( 'WordPress uses example.org, which does not point to your hosting now.', $html );
		$this->assertStringContainsString( 'Make ' . $preview . ' the WordPress address', $html );

		$a = new HostingActions( Resource::find( $r->id() ) );
		$this->assertSame( $preview, $a->queue( 'wordpress_address', array( $slug, '' ) )['moving'] );
		$this->run_job( $r );
		$this->assertSame( $preview, $this->node_site( $r, $slug )['wp_address'] );
	}

	public function test_the_domain_wordpress_is_on_is_not_removed_by_the_customer_but_leaves_with_its_add_on(): void {
		list( $r, $slug ) = $this->with_domain();
		$this->change( $r, 'wordpress_address', array( $slug, 'example.org' ) );
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
		$this->assertNull( SiteDomains::find( 'www.example.org' ), 'Every name of the domain goes with its add-on, in one change.' );
		$this->assertEmpty( $this->mails );
	}

	public function test_a_name_that_leaves_before_wordpress_could_move_back_tells_the_operator(): void {
		list( $r, $slug ) = $this->with_domain();
		$this->change( $r, 'wordpress_address', array( $slug, 'example.org' ) );
		$this->agent->fail_next( 'site.wp_address', 'scripted failure' );

		$this->assertSame( array( 'removed' => 'example.org' ), ( new HostingActions( Resource::find( $r->id() ) ) )->remove_domain( 'example.org', true ) );
		$this->assertNotEmpty( $this->mails );
		$this->assertStringContainsString( 'left on a removed domain', (string) end( $this->mails )['subject'] );
	}

	public function test_a_name_wordpress_is_not_on_is_removed_as_before(): void {
		list( $r, $slug ) = $this->with_domain();
		$this->change( $r, 'wordpress_address', array( $slug, 'example.org' ) );
		$mark = count( $this->agent->calls() );

		$this->assertSame( array( 'removed' => 'www.example.org' ), ( new HostingActions( Resource::find( $r->id() ) ) )->remove_domain( 'www.example.org' ) );
		$this->assertSame( array(), $this->since( $mark, 'site.wp_address' ) );
	}

	public function test_a_paid_domain_that_ends_while_wordpress_is_on_it_is_removed_by_one_job_that_moves_it_back_first(): void {
		list( $r, $slug ) = $this->with_domain();
		$this->change( $r, 'wordpress_address', array( $slug, 'example.org' ) );
		$mark = count( $this->agent->calls() );

		$out = ( new HostingActions( Resource::find( $r->id() ) ) )->queue( 'remove_domain', array( 'example.org', true ) );
		$this->assertSame( 'pending', $out['status'] );
		$this->assertSame( 'remove_domain', $out['task']['action'], 'The whole removal is the job\'s: the node must still know the name while WordPress moves back.' );
		$this->assertNotNull( SiteDomains::find( 'example.org' ), 'Nothing is removed inside the request.' );
		$this->assertSame( array(), array_slice( $this->agent->calls(), $mark ) );

		$this->agent->pending( 'site.wp_address', 2 );
		$this->assertTrue( $this->run_one( $r ) );
		$this->kill_job( $r ); // The process dies in the middle: it goes on, and starts nothing twice.
		$this->run_job( $r );

		$this->assertSame( EdgeMap::preview_host( $slug ), $this->node_site( $r, $slug )['wp_address'] );
		$this->assertSame( '', (string) $this->site( $r, $slug )['flags']['wp_address'] );
		$this->assertNull( SiteDomains::find( 'example.org' ) );
		$this->assertNull( SiteDomains::find( 'www.example.org' ) );
		$ops = array_column( array_slice( $this->agent->calls(), $mark ), 'op' );
		$this->assertLessThan( array_search( 'site.domains', $ops, true ), array_search( 'site.wp_address', $ops, true ), 'Moved back while the node still knows the name.' );
		$this->assertCount( 1, array_unique( array_column( $this->since( $mark, 'site.wp_address' ), 'job_id' ) ), 'The move back was asked of the node once.' );
		$this->assertTrue( $this->last_outcome( $r )['ok'] );
	}

	public function test_a_paid_domain_that_ends_leaves_in_one_queued_change(): void {
		list( $r, $slug ) = $this->with_domain();

		$out = ( new HostingActions( $r ) )->queue( 'remove_domain', array( 'example.org', true ) );
		$this->assertSame( 'pending', $out['status'] );
		$this->assertSame( 'names', $out['task']['action'] );
		$this->assertNull( SiteDomains::find( 'example.org' ) );
		$this->assertNull( SiteDomains::find( 'www.example.org' ) );

		$this->run_job( $r );
		$last = $this->agent->calls_of( 'edge.apply' );
		$this->assertNotContains( 'example.org', array_column( end( $last )['args']['routes'], 'host' ) );
	}

	// -- Login, page, older agent --------------------------------------------------------------------------------------

	public function test_the_login_links_and_the_page_follow_the_wordpress_address(): void {
		list( $r, $slug ) = $this->with_domain();
		$a                = new HostingActions( $r );

		$this->assertStringContainsString( 'https://' . EdgeMap::preview_host( $slug ) . '/', $a->login_link( $slug )['url'] );
		$this->assertStringContainsString( 'https://' . EdgeMap::preview_host( $slug ) . '/wp-login.php', $this->page( $r ) );

		$this->change( $r, 'wordpress_address', array( $slug, 'example.org' ) );
		$this->assertStringStartsWith( 'https://example.org/', $a->login_link( $slug )['url'] );
		$this->assertStringStartsWith( 'https://example.org/', $a->password_link( $slug )['url'] );
		$html = $this->page( $r );
		$this->assertStringContainsString( 'log in at https://example.org/wp-login.php.', $html );
		$this->assertStringNotContainsString( 'https://' . EdgeMap::preview_host( $slug ) . '/wp-login.php', $html );
		$this->assertMatchesRegularExpression( '/example\.org<\/span> <span class="bs-state[^"]*">[^<]*<\/span><\/p><p class="bs-item__meta">Main address, WordPress address/', $html );
		$this->assertMatchesRegularExpression( '/' . preg_quote( EdgeMap::preview_host( $slug ), '/' ) . '<\/span> <span class="bs-state[^"]*">[^<]*<\/span><\/p><p class="bs-item__meta">Temporary address/', $html );
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
		$this->run_job( $r );

		$site = $this->site( $r, $slug );
		$this->assertSame( '', (string) ( $site['flags']['wp_note'] ?? '' ), 'No error, no note.' );
		$this->assertTrue( $this->last_outcome( $r )['ok'], 'No failure for staff either.' );
		$this->assertSame( array(), $this->mails );
		$this->assertGreaterThan( 0, (int) Resource::find( $r->id() )->get( 'wp_address_unsupported' ) );

		// It is not asked again for a few hours, and nothing is queued by the next sweep.
		SiteWordPress::consider_all( Resource::find( $r->id() ) );
		$this->assertFalse( $this->queued( $r, $slug, 'example.org' ) );
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
		$this->change( $r, 'wordpress_address', array( $slug, '' ) );

		$this->assertSame( 'unsupported', $this->site( $r, $slug )['flags']['wp_note'] );
		$out = $this->last_outcome( $r );
		$this->assertFalse( $out['ok'] );
		$this->assertSame( 'Your hosting cannot move WordPress to another address yet. Contact support.', $out['text'] );
		$this->assertStringContainsString( 'Your hosting cannot move WordPress to another address yet. Contact support.', $this->page( $r ) );
		$this->assertSame( array(), $this->mails, 'An expected refusal tells no one.' );
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
		$this->assertSame( '', SiteWordPress::auto_target( array( 'id' => 1, 'flags' => array( 'wp_note' => 'failed' ) ), $pair, $preview, false ), 'A failed move is not tried again by itself.' );

		$this->assertSame( array( 'from' => $preview, 'to' => 'a.org' ), SiteWordPress::preview_redirect( array( 'flags' => array( 'wp_address' => 'a.org' ) ), $pair, $preview ) );
		$this->assertNull( SiteWordPress::preview_redirect( array( 'flags' => array( 'wp_address' => 'www.a.org' ) ), $pair, $preview ), 'WordPress is not on the main address.' );
		$this->assertNull( SiteWordPress::preview_redirect( array( 'flags' => array() ), $pair, $preview ) );
		$this->assertSame( array( 'a.org', 'www.a.org' ), array( SiteAddresses::base_of( 'a.org' ), 'www.' . SiteAddresses::base_of( 'www.a.org' ) ) );
	}

	/**
	 * One run of the account's waiting job.
	 *
	 * @param Resource $r Resource.
	 * @return bool Whether a job ran.
	 */
	private function run_one( Resource $r ): bool {
		foreach ( as_get_scheduled_actions( array( 'hook' => Jobs::ACT, 'group' => Jobs::GROUP, 'status' => \ActionScheduler_Store::STATUS_PENDING, 'per_page' => -1 ), 'ids' ) as $id ) {
			$args = \ActionScheduler::store()->fetch_action( (string) $id )->get_args();

			if ( (int) $args[0] === $r->id() ) {
				\ActionScheduler::store()->cancel_action( (int) $id );
				Jobs::act( $r->id(), (string) $args[1], (array) $args[2] );

				return true;
			}
		}

		return false;
	}
}

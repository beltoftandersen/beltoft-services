<?php
/**
 * The cache facts and set-up: the status route, the account page, the set-up of every website, and the best-effort
 * object cache step of a new shop website. The agent is the fake one; nothing here reaches a node.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Hosting;

require_once __DIR__ . '/HostingCase.php';
require_once dirname( __DIR__ ) . '/Support/WpCliStub.php';

use BeltoftServices\Api\Routes;
use BeltoftServices\Cli\HostingCommand;
use BeltoftServices\Hosting\CacheSetup;
use BeltoftServices\Hosting\HostingAccount;
use BeltoftServices\Hosting\HostingActions;
use BeltoftServices\Hosting\Records;
use BeltoftServices\Resource;

/**
 * Cache status, cache set-up.
 */
class CacheTest extends HostingCase {

	private const BASE = '/' . Routes::NAMESPACE . '/resources/';

	public function set_up(): void {
		parent::set_up();
		add_filter( 'beltoft_services_rest_rate_limit', '__return_zero' );
	}

	public function tear_down(): void {
		remove_all_filters( 'beltoft_services_rest_rate_limit' );
		wp_set_current_user( 0 );
		global $wp_rest_server;
		$wp_rest_server = null;
		\WP_CLI::reset();

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

	private function shop_plan(): array {
		return array( 'woocommerce_default' => true, 'valkey_mb' => 64 );
	}

	public function test_a_new_site_reports_its_caches_and_an_unknown_site_is_refused(): void {
		$r    = $this->account();
		$a    = new HostingActions( $r );
		$slug = 'w' . $r->id();

		$this->assertSame(
			array(
				'page_cache'   => 'on',
				'object_cache' => 'off',
				'lifetime'     => 600,
			),
			$a->cache_status( $slug )
		);
		$this->assertSame( 'not_found', $a->cache_status( 'ghost' )->get_error_code() );
	}

	public function test_an_agent_without_the_operation_gives_a_refusal_and_nothing_else_breaks(): void {
		$r    = $this->account();
		$a    = new HostingActions( $r );
		$slug = 'w' . $r->id();

		$this->agent->fail_next( 'site.cache_status', 'The operation is not in the list.' );
		$err = $a->cache_status( $slug );

		$this->assertWPError( $err );
		$this->assertStringNotContainsString( 'not in the list', $err->get_error_message(), 'Nothing the agent says reaches the customer.' );
		$this->assertSame( array( 'purged' => $slug ), $a->purge_cache( $slug ), 'The clear button works without it.' );
	}

	public function test_the_status_route_is_read_only_and_for_the_owner(): void {
		$r    = $this->account();
		$slug = 'w' . $r->id();

		wp_set_current_user( (int) $r->license()['user_id'] );
		$request  = new \WP_REST_Request( 'GET', self::BASE . $r->id() . '/hosting/sites/' . $slug . '/cache' );
		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'on', $response->get_data()['data']['page_cache'] ?? $response->get_data()['page_cache'] );

		wp_set_current_user( self::factory()->user->create() );
		$other = rest_get_server()->dispatch( new \WP_REST_Request( 'GET', self::BASE . $r->id() . '/hosting/sites/' . $slug . '/cache' ) );
		$this->assertGreaterThanOrEqual( 400, $other->get_status() );

		$this->assertCount( 0, $this->agent->calls_of( 'site.cache_setup' ), 'Reading the status never changes anything.' );
	}

	public function test_the_account_page_has_a_facts_line_and_its_words(): void {
		$r = $this->account();
		wp_set_current_user( (int) $r->license()['user_id'] );
		ob_start();
		HostingAccount::render( Resource::find( $r->id() ) );
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'data-cache-facts data-slug="w' . $r->id() . '" hidden', $html );
		$this->assertStringContainsString( 'Another caching plugin needs the page cache off first.', $html );
		$this->assertStringContainsString( 'data-hosting-action="cache"', $html );
		$this->assertStringNotContainsString( "\u{2014}", $html );
		$this->assertStringNotContainsString( "\u{00B7}", $html );
	}

	public function test_the_setup_command_lists_with_a_dry_run_and_changes_nothing(): void {
		$r    = $this->account();
		$rows = CacheSetup::run( true );

		$this->assertSame(
			array(
				array(
					'resource' => $r->id(),
					'slug'     => 'w' . $r->id(),
					'result'   => 'would set up',
				),
			),
			$rows
		);
		$this->assertCount( 0, $this->agent->calls_of( 'site.cache_setup' ) );
	}

	public function test_the_setup_switches_the_object_cache_on_for_a_shop_plan_only(): void {
		$shop  = $this->account( $this->shop_plan() );
		$plain = $this->account();
		$rows  = CacheSetup::run( false );

		$by = array();
		foreach ( $rows as $row ) {
			$by[ $row['resource'] ] = $row['result'];
		}

		$this->assertStringContainsString( 'object_cache=enabled', $by[ $shop->id() ] );
		$this->assertStringContainsString( 'object_cache=installed', $by[ $plain->id() ] );
		$this->assertStringContainsString( 'nginx=rendered', $by[ $plain->id() ] );

		$args = array_column( $this->agent->calls_of( 'site.cache_setup' ), 'args' );
		$this->assertCount( 3, $args, 'One from the shop site being made, then one for each of the two accounts.' );
		$this->assertContains( true, array_column( $args, 'object_cache' ) );
		$this->assertContains( false, array_column( $args, 'object_cache' ) );
		$this->assertSame( 'on', ( new HostingActions( Resource::find( $shop->id() ) ) )->cache_status( 'w' . $shop->id() )['object_cache'] );
	}

	public function test_the_setup_is_safe_to_repeat_and_can_be_limited_to_one_account(): void {
		$one = $this->account();
		$two = $this->account();

		CacheSetup::run( false, $one->id() );
		$this->assertCount( 1, $this->agent->calls_of( 'site.cache_setup' ) );

		$again = CacheSetup::run( false, $one->id() );
		$this->assertStringContainsString( 'page_plugin=present', $again[0]['result'] );
		unset( $two );
	}

	public function test_a_node_whose_agent_lacks_the_operation_is_reported_and_the_run_goes_on(): void {
		$a = $this->account();
		$b = $this->account();

		$this->agent->fail_next( 'site.cache_setup', 'The operation is not in the list.' );
		$rows = CacheSetup::run( false );

		$results = array_column( $rows, 'result', 'resource' );
		$this->assertStringStartsWith( 'not done', $results[ $a->id() ] );
		$this->assertStringContainsString( 'page_plugin=', $results[ $b->id() ] );
	}

	public function test_the_command_prints_one_line_per_website(): void {
		$r = $this->account();
		( new HostingCommand() )->cache( array( 'setup' ), array( 'dry-run' => true ) );
		$text = \WP_CLI::text();

		$this->assertStringContainsString( 'account ' . $r->id() . '  w' . $r->id() . '  would set up', $text );
		$this->assertStringContainsString( 'Dry run: 1 website(s).', $text );
		$this->assertCount( 0, $this->agent->calls_of( 'site.cache_setup' ) );
	}

	public function test_a_new_shop_site_asks_for_the_object_cache_and_a_plain_one_does_not(): void {
		$shop = $this->account( $this->shop_plan() );
		$this->assertCount( 1, $this->agent->calls_of( 'site.cache_setup' ), 'A shop plan gets the object cache switched on while the first site is made.' );
		$this->assertTrue( $this->agent->calls_of( 'site.cache_setup' )[0]['args']['object_cache'] );

		$before = count( $this->agent->calls_of( 'site.cache_setup' ) );
		$this->account();
		$this->assertCount( $before, $this->agent->calls_of( 'site.cache_setup' ), 'A plan that does not preselect a shop has no extra step.' );
		unset( $shop );
	}

	public function test_a_failed_object_cache_step_does_not_stop_a_new_site(): void {
		$this->agent->fail_next( 'site.cache_setup', 'The operation is not in the list.' );
		$r = $this->resource( $this->shop_plan() );
		$this->provision_fully( $r );

		$this->assertNotNull( Records::for_resource( $r->id() ) );
		$this->assertCount( 1, $this->agent->calls_of( 'site.cache_setup' ) );
	}

	public function test_the_plugin_names_nothing_of_the_cache_plugin(): void {
		$root = dirname( __DIR__, 2 ) . '/src';
		$hits = array();

		foreach ( new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $root ) ) as $file ) {
			if ( $file->isFile() && 'php' === $file->getExtension() && preg_match( '/lightweight[-_ ]cache|beltoft_lwc/i', (string) file_get_contents( $file->getPathname() ) ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
				$hits[] = $file->getPathname();
			}
		}

		$this->assertSame( array(), $hits, 'The services plugin only talks to the agent operations.' );
	}
}

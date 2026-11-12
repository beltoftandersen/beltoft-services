<?php
/**
 * The staging copy of a hosting website: the rules (plan limit, storage), the jobs (create, refresh, push, undo, delete),
 * what the page and the edge map say, and what happens when the node fails. Everything runs against the FakeAgent; no job
 * waits for Action Scheduler, the tests call the job's own entry point.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Hosting;

require_once __DIR__ . '/HostingCase.php';

use BeltoftServices\Api\Routes;
use BeltoftServices\Hosting\EdgeMap;
use BeltoftServices\Hosting\HostingAccount;
use BeltoftServices\Hosting\HostingActions;
use BeltoftServices\Hosting\Records;
use BeltoftServices\Hosting\SiteDomains;
use BeltoftServices\Hosting\SiteStaging;
use BeltoftServices\Hosting\Sites;
use BeltoftServices\Jobs;
use BeltoftServices\Resource;

/**
 * SiteStaging and StagingActions with the routes and the page.
 */
class StagingTest extends HostingCase {

	public function set_up(): void {
		parent::set_up();
		$this->settings( array( 'hosting_php_versions' => '8.3,8.4,8.5' ) );
		add_filter( 'beltoft_services_rest_rate_limit', '__return_zero' );
		$this->clear_queue();
	}

	public function tear_down(): void {
		$this->clear_queue();
		remove_all_filters( 'beltoft_services_rest_rate_limit' );
		wp_set_current_user( 0 );
		global $wp_rest_server;
		$wp_rest_server = null;

		parent::tear_down();
	}

	private function clear_queue(): void {
		foreach ( as_get_scheduled_actions( array( 'hook' => SiteStaging::HOOK, 'status' => \ActionScheduler_Store::STATUS_PENDING, 'per_page' => -1 ), 'ids' ) as $id ) {
			\ActionScheduler::store()->cancel_action( (int) $id );
		}
	}

	/**
	 * @param array<string, mixed> $plan Plan overrides.
	 * @return array{0: Resource, 1: string}
	 */
	private function account( array $plan = array() ): array {
		$r = $this->resource( array_merge( array( 'sites' => 3, 'php_workers' => 10, 'php_memory_mb' => 512, 'upload_mb' => 100 ), $plan ) );
		$this->provision_fully( $r );
		$r->set( array( 'state' => 'ready', 'desired' => 'ready' ) );

		return array( Resource::find( $r->id() ), 'w' . $r->id() );
	}

	private function vmid( Resource $r ): int {
		return (int) Records::for_resource( $r->id() )['vmid'];
	}

	private function view( Resource $r, string $slug ): array {
		return SiteStaging::view( (array) Sites::find( $r->id(), $slug ) );
	}

	/**
	 * Runs the queued job the way Action Scheduler would.
	 */
	private function run_job( Resource $r, string $slug, string $kind, int $files_only = 0, int $try = 0 ): void {
		$this->clear_queue();
		SiteStaging::run( $r->id(), $slug, $kind, $files_only, $try );
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	private function calls( string $op ): array {
		return $this->agent->calls_of( $op );
	}

	/**
	 * A website with a staging copy that is ready.
	 *
	 * @param array<string, mixed> $plan Plan overrides.
	 * @return array{0: Resource, 1: string}
	 */
	private function with_copy( array $plan = array() ): array {
		list( $r, $slug ) = $this->account( $plan );
		$out = ( new HostingActions( $r ) )->create_staging( $slug );
		$this->assertSame( 'creating', $out['state'] );
		$this->run_job( $r, $slug, 'create' );

		return array( Resource::find( $r->id() ), $slug );
	}

	private function page( Resource $r ): string {
		ob_start();
		HostingAccount::render( Resource::find( $r->id() ) );

		return (string) ob_get_clean();
	}

	// -- The copy is made -------------------------------------------------------------------------------------

	public function test_creating_records_the_start_holds_the_address_and_queues_the_job(): void {
		list( $r, $slug ) = $this->account();
		$out = ( new HostingActions( $r ) )->create_staging( $slug );

		$this->assertSame( 'creating', $out['state'] );
		$view = $this->view( $r, $slug );
		$this->assertTrue( $view['busy'] );
		$this->assertSame( $slug . '-stg', $view['copy'] );
		$this->assertSame( $slug . '-stg.preview.example.test', $view['host'] );
		$this->assertTrue( Jobs::pending_action( SiteStaging::HOOK, array( $r->id(), $slug, 'create', 0, 0 ) ) );
		$this->assertSame( array(), $this->calls( 'site.staging_create' ), 'Nothing is sent to the node inside the request.' );
		// The name is held at once: no other website can take it, and it is nobody's website.
		$row = SiteDomains::find( $view['host'] );
		$this->assertSame( array( 'preview', 0, $r->id() ), array( $row['kind'], $row['site_id'], $row['resource_id'] ) );
		$this->assertTrue( Sites::taken( $slug . '-stg' ) );
	}

	public function test_the_job_writes_dns_asks_the_node_and_opens_the_edge_route(): void {
		list( $r, $slug ) = $this->account();
		( new HostingActions( $r ) )->create_staging( $slug );
		$this->run_job( $r, $slug, 'create' );

		$view = $this->view( $r, $slug );
		$this->assertSame( array( 'ready', true, '' ), array( $view['state'], $view['routed'], $view['note'] ) );
		$call = $this->calls( 'site.staging_create' );
		$this->assertCount( 1, $call );
		$this->assertSame( array( 'vmid' => $this->vmid( $r ), 'slug' => $slug, 'copy' => $slug . '-stg', 'host' => $slug . '-stg.preview.example.test' ), $call[0]['args'] );
		$this->assertSame( 'active', SiteDomains::find( $view['host'] )['state'] );
		$this->assertNotNull( $this->agent->state()['sites'][ $this->vmid( $r ) ][ $slug . '-stg' ] );

		$hosts = array_column( EdgeMap::routes( $r->id(), false ), 'host' );
		$this->assertContains( $view['host'], $hosts );
		$edge = $this->calls( 'edge.apply' );
		$this->assertContains( $view['host'], array_column( end( $edge )['args']['routes'], 'host' ) );
		$zone = (string) wp_json_encode( $this->dns->zone( 'preview.example.test' ) );
		$this->assertStringContainsString( $slug . '-stg', $zone, 'The temporary address of the copy is in DNS.' );
	}

	public function test_the_copy_is_not_one_of_the_websites_and_does_not_show_among_its_addresses(): void {
		list( $r, $slug ) = $this->with_copy();
		$a     = new HostingActions( $r );
		$sites = $a->sites();

		$this->assertCount( 1, $sites );
		$this->assertSame( array( $slug . '.preview.example.test' ), array_column( $sites[0]['domains'], 'host' ) );
		$this->assertSame( 'ready', $sites[0]['staging']['state'] );
		$this->assertSame( 1, $a->limits()['sites_used'], 'A staging copy does not count as a website.' );
		$this->assertSame( 1, $a->limits()['staging_used'] );
	}

	public function test_a_plan_with_one_copy_gives_it_to_one_website_and_per_site_gives_one_to_each(): void {
		list( $r, $slug ) = $this->with_copy();
		$a = new HostingActions( $r );
		$a->add_site( 'second', '', '', false );

		$refused = $a->create_staging( 'second' );
		$this->assertWPError( $refused );
		$this->assertSame( 'limit', $refused->get_error_code() );
		$this->assertSame( 'This website has a staging copy already.', ( new HostingActions( $r ) )->create_staging( $slug )->get_error_message() );

		list( $q, $first ) = $this->account( array( 'staging' => 'per_site' ) );
		$b = new HostingActions( $q );
		$b->add_site( 'other', '', '', false );
		$this->assertSame( 'creating', $b->create_staging( $first )['state'] );
		$this->assertSame( 'creating', $b->create_staging( 'other' )['state'] );
		$this->assertWPError( $b->create_staging( $first ), 'One copy for each website.' );
	}

	public function test_no_copy_is_made_when_more_than_half_of_the_storage_is_used(): void {
		list( $r, $slug ) = $this->account();
		$r->set( array( 'usage' => array( 'disk_mb' => 6000, 'db_mb' => 100 ) ) );

		$out = ( new HostingActions( Resource::find( $r->id() ) ) )->create_staging( $slug );
		$this->assertWPError( $out );
		$this->assertSame( 'limit', $out->get_error_code() );
		$this->assertStringContainsString( 'not enough storage', $out->get_error_message() );
		$this->assertSame( array(), $this->calls( 'site.staging_create' ) );
		$this->assertFalse( $this->view( $r, $slug )['exists'] );
	}

	public function test_a_failed_create_takes_back_the_address_and_says_so(): void {
		list( $r, $slug ) = $this->account();
		( new HostingActions( $r ) )->create_staging( $slug );
		$host = $this->view( $r, $slug )['host'];

		for ( $try = 0; $try < SiteStaging::TRIES; $try++ ) {
			$this->agent->fail_next( 'site.staging_create', 'broke' );
			$this->run_job( $r, $slug, 'create', 0, $try );
		}

		$view = $this->view( $r, $slug );
		$this->assertSame( array( false, 'failed', 'create' ), array( $view['exists'], $view['note'], $view['failed'] ) );
		$this->assertNull( SiteDomains::find( $host ), 'The copy\'s address is free again.' );
		$this->assertNotContains( $host, array_column( EdgeMap::routes( $r->id(), false ), 'host' ) );
		$this->assertStringContainsString( 'The staging copy could not be made. Your website was not changed.', $this->page( $r ) );
		$this->assertStringContainsString( 'Create staging copy', $this->page( $r ), 'It can be tried again.' );
		$this->assertCount( SiteStaging::TRIES, $this->calls( 'site.staging_create' ) );
	}

	public function test_an_agent_without_the_operation_is_told_to_the_customer_and_nothing_is_retried(): void {
		list( $r, $slug ) = $this->account();
		$this->agent->set_unsupported( array( 'site.staging_create' ) );
		( new HostingActions( $r ) )->create_staging( $slug );
		$this->run_job( $r, $slug, 'create' );

		$this->assertSame( 'unsupported', $this->view( $r, $slug )['note'] );
		$this->assertStringContainsString( 'Your hosting cannot do this yet.', $this->page( $r ) );
		$this->assertFalse( Jobs::pending_action( SiteStaging::HOOK, array( $r->id(), $slug, 'create', 0, 1 ) ) );
	}

	// -- Refresh, push, undo ------------------------------------------------------------------------------------

	public function test_refresh_asks_the_node_and_keeps_the_time(): void {
		list( $r, $slug ) = $this->with_copy();
		$this->assertSame( 'refreshing', ( new HostingActions( $r ) )->refresh_staging( $slug )['state'] );
		$this->assertTrue( $this->view( $r, $slug )['busy'] );
		$this->run_job( $r, $slug, 'refresh' );

		$view = $this->view( $r, $slug );
		$this->assertSame( 'ready', $view['state'] );
		$this->assertGreaterThan( 0, $view['refreshed'] );
		$this->assertSame( array( 'vmid' => $this->vmid( $r ), 'slug' => $slug ), $this->calls( 'site.staging_refresh' )[0]['args'] );
	}

	public function test_a_push_of_files_and_database_and_one_of_files_only_send_what_was_chosen_and_keep_the_way_back(): void {
		list( $r, $slug ) = $this->with_copy();
		$a = new HostingActions( $r );

		$this->assertSame( 'pushing', $a->push_staging( $slug, false )['state'] );
		$this->run_job( $r, $slug, 'push', 0 );
		$this->assertArrayNotHasKey( 'files_only', $this->calls( 'site.staging_push' )[0]['args'] );
		$view = $this->view( $r, $slug );
		$this->assertSame( array( 'ready', 'full' ), array( $view['state'], $view['undo']['scope'] ) );

		$a->push_staging( $slug, true );
		$this->run_job( $r, $slug, 'push', 1 );
		$this->assertTrue( $this->calls( 'site.staging_push' )[1]['args']['files_only'] );
		$this->assertSame( 'files', $this->view( $r, $slug )['undo']['scope'] );
	}

	public function test_a_push_the_node_put_back_leaves_no_way_back_and_says_so(): void {
		list( $r, $slug ) = $this->with_copy();
		$this->agent->script_staging( 'site.staging_push', array( 'pushed' => false, 'failed' => true, 'restored' => true ) );
		( new HostingActions( $r ) )->push_staging( $slug, false );
		$this->run_job( $r, $slug, 'push' );

		$view = $this->view( $r, $slug );
		$this->assertSame( array( 'ready', 'failed', 'push', null ), array( $view['state'], $view['note'], $view['failed'], $view['undo'] ) );
		$this->assertStringContainsString( 'The push did not work. Your live website was put back as it was.', $this->page( $r ) );
	}

	public function test_a_push_that_could_not_be_put_back_tells_the_operator(): void {
		list( $r, $slug ) = $this->with_copy();
		$mails = array();
		add_filter(
			'pre_wp_mail',
			static function ( $pre, $atts ) use ( &$mails ) {
				$mails[] = $atts;
				return true;
			},
			10,
			2
		);
		$this->agent->fail_next( 'site.staging_push', 'the push failed and the safety point could not be put back' );
		( new HostingActions( $r ) )->push_staging( $slug, false );
		$this->run_job( $r, $slug, 'push' );
		remove_all_filters( 'pre_wp_mail' );

		$this->assertSame( 'unsafe', $this->view( $r, $slug )['note'] );
		$this->assertCount( 1, $mails );
		$this->assertStringContainsString( 'The push did not work and the live website could not be put back automatically.', $this->page( $r ) );
	}

	public function test_undo_goes_back_to_the_safety_point_and_is_offered_for_seven_days(): void {
		list( $r, $slug ) = $this->with_copy();
		$a = new HostingActions( $r );

		$refused = $a->undo_staging( $slug );
		$this->assertSame( 'invalid', $refused->get_error_code(), 'No push, nothing to undo.' );

		$a->push_staging( $slug, false );
		$this->run_job( $r, $slug, 'push' );
		$html = $this->page( $r );
		$this->assertStringContainsString( 'Undo last push', $html );
		$this->assertStringContainsString( 'Orders, comments and other changes made on the live website since the push are lost.', $html );

		$this->assertSame( 'undoing', $a->undo_staging( $slug )['state'] );
		$this->run_job( $r, $slug, 'undo' );
		$this->assertNull( $this->view( $r, $slug )['undo'] );
		$this->assertCount( 1, $this->calls( 'site.staging_undo' ) );

		// Seven days after a push the way back is not offered, whatever the node still holds.
		$a->push_staging( $slug, true );
		$this->run_job( $r, $slug, 'push', 1 );
		$flags = (array) Sites::find( $r->id(), $slug )['flags']['staging'];
		$flags['undo']['at'] = Jobs::now() - SiteStaging::KEEP - 10;
		Sites::update( $r->id(), $slug, array( 'flags' => array( 'staging' => $flags ) ) );
		$this->assertNull( $this->view( $r, $slug )['undo'] );
		$this->assertStringNotContainsString( 'Undo last push', $this->page( $r ) );
		$this->assertSame( 'invalid', ( new HostingActions( Resource::find( $r->id() ) ) )->undo_staging( $slug )->get_error_code() );
	}

	public function test_one_change_at_a_time(): void {
		list( $r, $slug ) = $this->with_copy();
		$a = new HostingActions( $r );
		$a->refresh_staging( $slug );

		foreach ( array( $a->push_staging( $slug, false ), $a->refresh_staging( $slug ), $a->delete_staging( $slug ), $a->undo_staging( $slug ), $a->create_staging( $slug ) ) as $out ) {
			$this->assertWPError( $out );
			$this->assertSame( 'busy', $out->get_error_code() );
		}
	}

	public function test_no_staging_change_while_a_restore_round_runs(): void {
		list( $r, $slug ) = $this->with_copy();
		$r->set( array( 'restore' => array( 'kind' => 'account', 'phase' => 'restore' ) ) );
		$a = new HostingActions( Resource::find( $r->id() ) );

		foreach ( array( $a->push_staging( $slug, false ), $a->refresh_staging( $slug ), $a->delete_staging( $slug ) ) as $out ) {
			$this->assertWPError( $out );
			$this->assertSame( 'busy', $out->get_error_code() );
		}

		$this->assertFalse( $this->view( $r, $slug )['busy'] );
	}

	public function test_a_job_that_is_no_longer_the_one_the_flags_wait_for_does_nothing(): void {
		list( $r, $slug ) = $this->with_copy();
		$before = count( $this->agent->calls() );
		$this->run_job( $r, $slug, 'push' );
		$this->run_job( $r, $slug, 'delete' );

		$this->assertCount( $before, $this->agent->calls() );
		$this->assertSame( 'ready', $this->view( $r, $slug )['state'] );
	}

	// -- Delete -------------------------------------------------------------------------------------------------

	public function test_deleting_the_copy_removes_it_from_the_node_dns_and_the_edge_and_keeps_the_way_back(): void {
		list( $r, $slug ) = $this->with_copy();
		$a = new HostingActions( $r );
		$a->push_staging( $slug, false );
		$this->run_job( $r, $slug, 'push' );
		$host = $this->view( $r, $slug )['host'];

		$this->assertSame( 'deleting', $a->delete_staging( $slug )['state'] );
		$this->run_job( $r, $slug, 'delete' );

		$view = $this->view( $r, $slug );
		$this->assertFalse( $view['exists'] );
		$this->assertNull( SiteDomains::find( $host ) );
		$this->assertNotContains( $host, array_column( EdgeMap::routes( $r->id(), false ), 'host' ) );
		$this->assertArrayNotHasKey( $slug . '-stg', $this->agent->state()['sites'][ $this->vmid( $r ) ] );
		$edge = $this->calls( 'edge.apply' );
		$this->assertNotContains( $host, array_column( end( $edge )['args']['routes'], 'host' ) );
		$this->assertFalse( Sites::taken( $slug . '-stg' ) );
		$this->assertSame( 'full', $view['undo']['scope'], 'The way back of the push belongs to the website, not to the copy.' );
		$this->assertNotNull( ( new HostingActions( Resource::find( $r->id() ) ) )->undo_staging( $slug )['state'] ?? null );
	}

	public function test_removing_the_website_removes_its_copy_and_its_address(): void {
		list( $r, $slug ) = $this->with_copy();
		$a = new HostingActions( $r );
		$a->add_site( 'second', '', '', false );
		$host = $this->view( $r, $slug )['host'];

		$out = $a->remove_site( $slug, $slug );
		$this->assertSame( array( 'removed' => $slug ), $out );
		$this->assertNull( SiteDomains::find( $host ) );
		$this->assertArrayNotHasKey( $slug . '-stg', $this->agent->state()['sites'][ $this->vmid( $r ) ] );
		$this->assertNotContains( $host, array_column( EdgeMap::routes( $r->id(), false ), 'host' ) );

		list( $q, $first ) = $this->with_copy();
		( new HostingActions( $q ) )->add_site( 'other', '', '', false );
		( new HostingActions( $q ) )->refresh_staging( $first );
		$this->assertSame( 'busy', ( new HostingActions( Resource::find( $q->id() ) ) )->remove_site( $first, $first )->get_error_code(), 'Not while the copy is being changed.' );
	}

	// -- Open ----------------------------------------------------------------------------------------------------

	public function test_opening_the_copy_gives_a_one_time_link_on_the_copys_own_address(): void {
		list( $r, $slug ) = $this->with_copy();
		$a   = new HostingActions( $r );
		$out = $a->staging_login( $slug );

		$this->assertSame( 'https://' . $slug . '-stg.preview.example.test/?fake-login=1', $out['url'] );
		$this->assertSame( $slug . '-stg', $this->calls( 'site.wp_login_link' )[0]['args']['slug'] );

		$a->refresh_staging( $slug );
		$this->assertSame( 'not_found', $a->staging_login( $slug )->get_error_code(), 'Not while it is being changed.' );
		$this->assertSame( 'not_found', $a->staging_login( 'nosuch' )->get_error_code() );
	}

	// -- The page and the routes ---------------------------------------------------------------------------------

	public function test_the_page_offers_the_buttons_of_each_state(): void {
		list( $r, $slug ) = $this->account();
		$html = $this->page( $r );
		$this->assertStringContainsString( 'data-hosting-action="staging_create"', $html );
		$this->assertStringContainsString( 'Create staging copy', $html );
		$this->assertStringContainsString( 'It sends no email, is not shown to search engines and does not count as a website.', $html );
		$this->assertStringNotContainsString( 'data-staging-busy', $html );

		( new HostingActions( $r ) )->create_staging( $slug );
		$html = $this->page( $r );
		$this->assertStringContainsString( 'data-staging-busy', $html );
		$this->assertStringContainsString( 'The staging copy is being made. This can take a few minutes.', $html );
		$this->assertStringNotContainsString( 'data-hosting-action="staging_create"', $html );

		$this->run_job( $r, $slug, 'create' );
		$html = $this->page( $r );

		foreach ( array( 'staging_open', 'staging_edit', 'staging_refresh', 'staging_delete', 'staging_push', 'staging_cancel' ) as $action ) {
			$this->assertStringContainsString( 'data-hosting-action="' . $action . '"', $html, $action );
		}

		foreach ( array( 'Open staging', 'Push to live', 'Refresh from live', 'Delete staging copy', 'Files and database', 'Files only' ) as $label ) {
			$this->assertStringContainsString( '>' . $label . '<', $html, $label );
		}

		$this->assertStringContainsString( 'Files and database: orders, customers, comments and other changes made on the live website since the staging copy was made are replaced.', $html, 'The database warning is on the page before the push.' );
		$this->assertStringContainsString( 'https://' . $slug . '-stg.preview.example.test/', $html );
		$this->assertStringNotContainsString( 'Undo last push', $html );
	}

	public function test_the_page_says_when_another_website_has_the_one_copy_of_the_plan(): void {
		list( $r, $slug ) = $this->with_copy();
		( new HostingActions( $r ) )->add_site( 'second', '', '', false );
		$html = $this->page( $r );

		$this->assertStringContainsString( 'Your plan includes one staging copy, and another website has it.', $html );
		$this->assertSame( 1, substr_count( $html, 'data-hosting-action="staging_create"' ) + substr_count( $html, 'data-hosting-action="staging_open"' ), 'Only the website with the copy has a button.' );
		$this->assertStringContainsString( $slug, $html );
	}

	private function dispatch( string $method, Resource $r, string $slug, string $tail = '', array $body = array() ): \WP_REST_Response {
		$request = new \WP_REST_Request( $method, '/' . Routes::NAMESPACE . '/resources/' . $r->id() . '/hosting/sites/' . $slug . '/staging' . $tail );

		if ( array() !== $body ) {
			$request->set_header( 'content-type', 'application/json' );
			$request->set_body( (string) wp_json_encode( $body ) );
		}

		return rest_get_server()->dispatch( $request );
	}

	public function test_the_routes_queue_the_jobs_answer_202_and_report_the_state(): void {
		list( $r, $slug ) = $this->account();
		wp_set_current_user( (int) $r->license()['user_id'] );
		global $wp_rest_server;
		$wp_rest_server = null;

		$response = $this->dispatch( 'POST', $r, $slug );
		$this->assertSame( 202, $response->get_status() );
		$this->assertSame( 'creating', $response->get_data()['state'] );
		$this->assertSame( 'private, no-store', $response->get_headers()['Cache-Control'] ?? '', 'No reply is cached.' );

		$state = $this->dispatch( 'GET', $r, $slug );
		$this->assertSame( 200, $state->get_status() );
		$this->assertTrue( $state->get_data()['busy'] );

		$this->run_job( $r, $slug, 'create' );
		$this->assertFalse( $this->dispatch( 'GET', $r, $slug )->get_data()['busy'] );
		$this->assertSame( 202, $this->dispatch( 'POST', $r, $slug, '/refresh' )->get_status() );
		$this->assertSame( 409, $this->dispatch( 'POST', $r, $slug, '/push', array( 'files_only' => true ) )->get_status(), 'Busy: one change at a time.' );
		$this->run_job( $r, $slug, 'refresh' );
		$this->assertSame( 202, $this->dispatch( 'POST', $r, $slug, '/push', array( 'files_only' => true ) )->get_status() );
		$this->assertTrue( Jobs::pending_action( SiteStaging::HOOK, array( $r->id(), $slug, 'push', 1, 0 ) ) );
		$this->run_job( $r, $slug, 'push', 1 );
		$this->assertSame( 202, $this->dispatch( 'POST', $r, $slug, '/undo' )->get_status() );
		$this->run_job( $r, $slug, 'undo' );
		$login = $this->dispatch( 'POST', $r, $slug, '/login' );
		$this->assertSame( 200, $login->get_status() );
		$this->assertStringStartsWith( 'https://' . $slug . '-stg.', $login->get_data()['url'] );
		$this->assertSame( 202, $this->dispatch( 'DELETE', $r, $slug )->get_status() );
		$this->assertSame( 404, $this->dispatch( 'POST', $r, 'nosuch', '/refresh' )->get_status() );
	}

	public function test_the_routes_belong_to_the_owner_and_staging_changes_have_an_hourly_limit(): void {
		list( $r, $slug ) = $this->with_copy();
		wp_set_current_user( self::factory()->user->create() );
		global $wp_rest_server;
		$wp_rest_server = null;
		$this->assertContains( $this->dispatch( 'POST', $r, $slug, '/refresh' )->get_status(), array( 403, 404 ) );
		$this->assertContains( $this->dispatch( 'GET', $r, $slug )->get_status(), array( 403, 404 ) );

		wp_set_current_user( (int) $r->license()['user_id'] );
		remove_all_filters( 'beltoft_services_rest_rate_limit' );
		$wp_rest_server = null;
		$last           = 0;

		for ( $i = 0; $i < SiteStaging::LIMIT + 1; $i++ ) {
			$last = $this->dispatch( 'POST', $r, 'nosuch', '/refresh' )->get_status();
		}

		$this->assertSame( 429, $last );
	}

	public function test_a_copy_name_cannot_be_taken_by_a_new_website(): void {
		list( $r, $slug ) = $this->with_copy();
		$a = new HostingActions( $r );

		$out = $a->add_site( $slug . '-stg', '', '', false );
		$this->assertWPError( $out );
		$this->assertSame( 'exists', $out->get_error_code() );
	}
}

<?php
/**
 * A website without WordPress: how it is made (checkout, first website, added website), how it is remembered (the site flag `wp`) and read
 * again from the node (SiteFacts), and what the account page and the actions do for it. Everything runs against the FakeAgent.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Hosting;

require_once __DIR__ . '/HostingCase.php';

use BeltoftServices\Hosting\HostingActions;
use BeltoftServices\Hosting\Records;
use BeltoftServices\Hosting\SiteFacts;
use BeltoftServices\Hosting\SiteField;
use BeltoftServices\Hosting\Sites;
use BeltoftServices\Resource;

/**
 * The plain website: install kind, flag, facts.
 */
class PlainHostingTest extends HostingCase {

	public function set_up(): void {
		parent::set_up();
		$this->settings( array( 'hosting_php_versions' => '8.3,8.4,8.5' ) );
		add_filter( 'beltoft_services_rest_rate_limit', '__return_zero' );
	}

	public function tear_down(): void {
		remove_all_filters( 'beltoft_services_rest_rate_limit' );
		wp_set_current_user( 0 );

		parent::tear_down();
	}

	/**
	 * A ready account whose first website was ordered with or without WordPress.
	 *
	 * @param array<string, mixed> $site The ordered site (meta `site`).
	 */
	private function account( array $site = array() ): Resource {
		$r = $this->resource( array( 'sites' => 3, 'php_workers' => 10, 'php_memory_mb' => 512, 'upload_mb' => 100 ), array( 'site' => array_merge( array( 'slug' => 'first', 'label' => 'First', 'install' => true ), $site ) ) );
		$this->provision_fully( $r );
		$r->set( array( 'state' => 'ready', 'desired' => 'ready' ) );

		return Resource::find( $r->id() );
	}

	private function vmid( Resource $r ): int {
		return (int) Records::for_resource( $r->id() )['vmid'];
	}

	public function test_the_checkout_takes_an_empty_website_and_refuses_what_is_not_offered(): void {
		$empty = SiteField::resolve( array( 'slug' => 'plainshop', 'install' => 'empty' ), 0 );
		$this->assertIsArray( $empty );
		$this->assertSame( 'empty', $empty['install'] );

		$bad = SiteField::resolve( array( 'slug' => 'plainshop2', 'install' => 'drupal' ), 0 );
		$this->assertWPError( $bad );
		$this->assertSame( 'site_install', $bad->get_error_code() );
		$this->assertSame( 'Choose what to install.', $bad->get_error_message() );
	}

	public function test_an_empty_first_website_is_made_without_wordpress_and_remembered(): void {
		$r = $this->account( array( 'install' => false, 'install_kind' => 'empty' ) );

		$this->assertSame( array(), $this->agent->calls_of( 'site.wp_install' ), 'No WordPress is installed.' );
		$this->assertFalse( Sites::has_wordpress( Sites::find( $r->id(), 'first' ) ) );
		$this->assertFalse( Sites::find( $r->id(), 'first' )['flags']['wp'] );
		$this->assertArrayNotHasKey( 'admin_user', Sites::find( $r->id(), 'first' )['flags'], 'No administrator was made.' );
	}

	public function test_a_wordpress_first_website_is_remembered_as_wordpress(): void {
		$r = $this->account();

		$this->assertTrue( Sites::find( $r->id(), 'first' )['flags']['wp'] );
		$this->assertTrue( Sites::has_wordpress( Sites::find( $r->id(), 'first' ) ) );
		$this->assertCount( 1, $this->agent->calls_of( 'site.wp_install' ) );
	}

	/**
	 * @dataProvider verify_statuses
	 */
	public function test_an_empty_first_website_gets_ready_whether_it_answers_200_or_403( int $status ): void {
		$this->agent->set_verify_status( $status );
		$r = $this->resource( array(), array( 'site' => array( 'slug' => 'plain', 'install' => false ) ) );
		$this->provision_fully( $r );

		$this->assertNotEmpty( $this->agent->calls_of( 'site.verify' ), 'The website was verified.' );
		$this->assertFalse( Sites::has_wordpress( Sites::find( $r->id(), 'plain' ) ) );
	}

	public static function verify_statuses(): array {
		return array( 'a placeholder page' => array( 200 ), 'an empty folder' => array( 403 ) );
	}

	public function test_a_wordpress_website_that_answers_403_is_not_ready(): void {
		$this->agent->set_verify_status( 403 );
		$r = $this->resource( array(), array( 'site' => array( 'slug' => 'wp403', 'install' => true ) ) );

		$result = null;

		for ( $i = 0; $i < 14; $i++ ) {
			$result = $this->provision( $r );

			if ( ! $result->is_retry() ) {
				break;
			}
		}

		$this->assertFalse( $result->is_done(), 'A WordPress website that shows 403 is not verified.' );
	}

	public function test_a_website_with_no_flag_has_wordpress(): void {
		$this->assertTrue( Sites::has_wordpress( array( 'flags' => array() ) ) );
		$this->assertTrue( Sites::has_wordpress( array( 'flags' => array( 'admin_user' => 'x' ) ) ) );
		$this->assertTrue( Sites::has_wordpress( array( 'flags' => array( 'wp' => true ) ) ) );
		$this->assertFalse( Sites::has_wordpress( array( 'flags' => array( 'wp' => false ) ) ) );
	}

	public function test_an_added_website_without_wordpress_is_remembered_and_installs_nothing(): void {
		$r = $this->account();

		$out = $this->change( $r, 'add_site', array( 'second', 'Second', '8.3', false ) );
		$this->assertIsArray( $out );

		$this->assertFalse( Sites::find( $r->id(), 'second' )['flags']['wp'] );
		$this->assertCount( 1, $this->agent->calls_of( 'site.wp_install' ), 'Only the first website got WordPress.' );

		$this->change( $r, 'add_site', array( 'third', 'Third', '8.3', true ) );
		$this->assertTrue( Sites::find( $r->id(), 'third' )['flags']['wp'] );
		$this->assertCount( 2, $this->agent->calls_of( 'site.wp_install' ) );
	}

	public function test_the_rest_route_keeps_install_a_boolean_that_defaults_to_true(): void {
		$r    = $this->account();
		$user = (int) $r->license()['user_id'];
		wp_set_current_user( $user );

		$request = new \WP_REST_Request( 'POST', '/beltoft-services/v1/resources/' . $r->id() . '/hosting/sites' );
		$request->set_param( 'slug', 'viarest' );
		$request->set_param( 'install', false );
		$response = rest_do_request( $request );
		$this->assertSame( 202, $response->get_status(), wp_json_encode( $response->get_data() ) );
		$this->run_account( $r );

		$this->assertFalse( Sites::find( $r->id(), 'viarest' )['flags']['wp'] );

		$request = new \WP_REST_Request( 'POST', '/beltoft-services/v1/resources/' . $r->id() . '/hosting/sites' );
		$request->set_param( 'slug', 'viarest2' );
		$this->assertSame( 202, rest_do_request( $request )->get_status() );
		$this->run_account( $r );
		$this->assertTrue( Sites::find( $r->id(), 'viarest2' )['flags']['wp'], 'No install parameter means WordPress, as before.' );
	}

	public function test_the_node_is_asked_and_a_wordpress_that_appears_or_goes_is_followed(): void {
		$r    = $this->account( array( 'install' => false ) );
		$vmid = $this->vmid( $r );

		// The customer installed WordPress by hand.
		$this->agent->set_wordpress( $vmid, 'first', true );
		$this->assertSame( array( 'first' => true ), SiteFacts::refresh( $r ) );
		$this->assertTrue( Sites::has_wordpress( Sites::find( $r->id(), 'first' ) ) );

		// And deleted it again.
		$this->agent->set_wordpress( $vmid, 'first', false );
		$this->assertSame( array( 'first' => false ), SiteFacts::refresh( $r ) );
		$this->assertFalse( Sites::has_wordpress( Sites::find( $r->id(), 'first' ) ) );
		$this->assertGreaterThan( 0, (int) Sites::find( $r->id(), 'first' )['flags']['wp_checked'] );
	}

	public function test_an_old_agent_that_does_not_know_site_facts_changes_nothing(): void {
		$r = $this->account();
		$this->agent->set_unsupported( array( 'site.facts' ) );

		$this->assertSame( array(), SiteFacts::refresh( $r ) );
		$this->assertTrue( Sites::find( $r->id(), 'first' )['flags']['wp'], 'The flag stays as it was.' );
	}

	public function test_a_site_with_no_flag_gets_one_from_the_first_reading(): void {
		$r    = $this->account();
		$vmid = $this->vmid( $r );
		global $wpdb;
		// A website made before the flag existed: its flags hold no `wp`.
		$wpdb->update( \BeltoftServices\Hosting\Schema::tables()['hosting_sites'], array( 'flags' => '{}' ), array( 'resource_id' => $r->id(), 'slug' => 'first' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- test fixture.
		$this->assertTrue( Sites::has_wordpress( Sites::find( $r->id(), 'first' ) ), 'Without a flag the page is today\'s page.' );

		$this->agent->set_wordpress( $vmid, 'first', false );
		SiteFacts::refresh( $r );
		$this->assertFalse( Sites::has_wordpress( Sites::find( $r->id(), 'first' ) ) );
	}

	public function test_a_website_that_is_still_being_made_is_not_read(): void {
		$r = $this->account();
		Sites::add( $r->id(), 'building', 'B', '8.3', 2, array( 'pending' => true, 'wp' => true ) );

		$answers = SiteFacts::refresh( $r );

		$this->assertArrayNotHasKey( 'building', $answers );
		$this->assertCount( 1, $this->agent->calls_of( 'site.facts' ), 'Only the finished website was asked about.' );
	}

	public function test_the_reading_is_due_after_an_hour_and_after_a_day_for_the_tab(): void {
		$r = $this->account();
		$this->assertTrue( SiteFacts::due( $r ), 'Never read.' );

		SiteFacts::refresh( $r );
		$this->assertFalse( SiteFacts::due( $r ) );
		$this->assertFalse( SiteFacts::due( $r, SiteFacts::TAB_AFTER ) );

		( Resource::find( $r->id() ) )->set( array( SiteFacts::META => time() - 2 * HOUR_IN_SECONDS ) );
		$this->assertTrue( SiteFacts::due( $r ) );
		$this->assertFalse( SiteFacts::due( $r, SiteFacts::TAB_AFTER ) );
	}

	public function test_a_restore_asks_for_a_new_reading_in_the_background(): void {
		$r = $this->account();
		as_unschedule_all_actions( SiteFacts::HOOK );

		\BeltoftServices\Hosting\Actions\Steps::done( $r, 'restore_backup', array( 'files', 'x', 'first' ) );
		$this->assertTrue( as_has_scheduled_action( SiteFacts::HOOK, array( $r->id() ) ) );

		as_unschedule_all_actions( SiteFacts::HOOK );
		\BeltoftServices\Hosting\Actions\Steps::done( $r, 'staging_push', array( 'first', false ) );
		$this->assertTrue( as_has_scheduled_action( SiteFacts::HOOK, array( $r->id() ) ) );
	}

	public function test_the_sweep_reads_the_account_once_an_hour(): void {
		$r    = $this->account();
		$vmid = $this->vmid( $r );
		$this->agent->set_wordpress( $vmid, 'first', false );

		\BeltoftServices\Hosting\DomainSweep::run();
		$this->assertFalse( Sites::has_wordpress( Sites::find( $r->id(), 'first' ) ) );

		$this->agent->set_wordpress( $vmid, 'first', true );
		\BeltoftServices\Hosting\DomainSweep::run();
		$this->assertFalse( Sites::has_wordpress( Sites::find( $r->id(), 'first' ) ), 'A second round within the hour does not read again.' );
	}

	// -- What a website without WordPress does and shows ---------------------------------------------------

	private const EDGE = '198.51.100.7';

	/**
	 * A plain account with a domain whose names are live and whose DNS shows our address.
	 *
	 * @return array{0: Resource, 1: string}
	 */
	private function plain_with_live_domain(): array {
		$r = $this->account( array( 'install' => false ) );
		( new HostingActions( $r ) )->add_domain( 'first', 'example.org' );
		\BeltoftServices\Hosting\SiteDomains::set_state( 'example.org', 'active' );
		\BeltoftServices\Hosting\SiteDomains::set_state( 'www.example.org', 'active' );
		( new HostingActions( Resource::find( $r->id() ) ) )->sync_edge();
		remove_all_filters( 'beltoft_services_dns_resolve' );
		add_filter( 'beltoft_services_dns_resolve', static fn( $pre, $name, $type ) => 'A' === $type ? array( self::EDGE ) : array(), 10, 3 );

		return array( Resource::find( $r->id() ), 'first' );
	}

	public function test_no_wordpress_address_is_queued_for_a_plain_website_when_a_domain_goes_live(): void {
		list( $r, $slug ) = $this->plain_with_live_domain();

		$this->assertFalse( \BeltoftServices\Hosting\SiteWordPress::consider( $r, $slug, true ) );
		$this->assertFalse( \BeltoftServices\Hosting\SiteWordPress::consider( $r, $slug, false ) );
		\BeltoftServices\Hosting\Actions\Steps::done( $r, 'names', array( $slug ) );
		\BeltoftServices\Hosting\DomainSweep::run();
		$this->run_account( $r );

		$this->assertSame( array(), $this->agent->calls_of( 'site.wp_address' ), 'The node was never asked to move WordPress.' );
		$this->assertNull( \BeltoftServices\Hosting\Actions\Steps::view( Resource::find( $r->id() ) ) );
	}

	public function test_a_wordpress_website_still_gets_its_address_moved(): void {
		$r = $this->account();
		( new HostingActions( $r ) )->add_domain( 'first', 'example.org' );
		\BeltoftServices\Hosting\SiteDomains::set_state( 'example.org', 'active' );
		\BeltoftServices\Hosting\SiteDomains::set_state( 'www.example.org', 'active' );
		( new HostingActions( Resource::find( $r->id() ) ) )->sync_edge();
		remove_all_filters( 'beltoft_services_dns_resolve' );
		add_filter( 'beltoft_services_dns_resolve', static fn( $pre, $name, $type ) => 'A' === $type ? array( self::EDGE ) : array(), 10, 3 );

		$this->assertTrue( \BeltoftServices\Hosting\SiteWordPress::consider( Resource::find( $r->id() ), 'first', true ) );
	}

	public function test_the_actions_that_need_wordpress_refuse_a_plain_website(): void {
		$r    = $this->account( array( 'install' => false ) );
		$a    = new HostingActions( $r );
		$mark = count( $this->agent->calls() );

		foreach ( array( 'login_link', 'password_link', 'cache_status' ) as $method ) {
			$out = $a->{$method}( 'first' );
			$this->assertWPError( $out, $method );
			$this->assertSame( 'no_wordpress', $out->get_error_code(), $method );
			$this->assertSame( 'This website has no WordPress.', $out->get_error_message() );
		}

		foreach ( array( array( 'purge_cache', array( 'first' ) ), array( 'create_staging', array( 'first' ) ), array( 'refresh_staging', array( 'first' ) ), array( 'push_staging', array( 'first', false ) ), array( 'wordpress_address', array( 'first', 'example.org' ) ) ) as $change ) {
			$out = $a->queue( $change[0], $change[1] );
			$this->assertWPError( $out, $change[0] );
			$this->assertSame( 'no_wordpress', $out->get_error_code(), $change[0] );
		}

		$a = new HostingActions( $r );
		$this->assertWPError( $a->staging_login( 'first' ) );
		$this->assertCount( 0, array_filter( array_slice( $this->agent->calls(), $mark ), static fn( $c ) => in_array( $c['op'], array( 'site.wp_login_link', 'site.wp_password_link', 'site.cache_purge', 'site.cache_status', 'site.staging_create', 'site.wp_address' ), true ) ), 'Nothing was sent to the node.' );
	}

	public function test_the_routes_say_why_with_a_conflict(): void {
		$r = $this->account( array( 'install' => false ) );
		wp_set_current_user( (int) $r->license()['user_id'] );
		$request  = new \WP_REST_Request( 'POST', '/beltoft-services/v1/resources/' . $r->id() . '/hosting/sites/first/login' );
		$response = rest_do_request( $request );

		$this->assertSame( 409, $response->get_status() );
		$this->assertSame( 'beltoft_services_hosting_no_wordpress', $response->get_data()['code'] );
	}

	public function test_a_login_block_is_refused_for_a_plain_website_and_a_whole_website_block_is_not(): void {
		$r = $this->account( array( 'install' => false ) );
		$a = new HostingActions( $r );

		$login = $a->queue( 'set_country_block', array( 'first', array( 'RU' ), 'login', true ) );
		$this->assertWPError( $login );
		$this->assertSame( 'invalid', $login->get_error_code() );

		$this->assertIsArray( $this->change( $r, 'set_country_block', array( 'first', array( 'RU' ), 'site', true ) ) );
	}

	/**
	 * The website panel as the account page prints it.
	 */
	private function panel( Resource $r, string $slug = 'first' ): string {
		$actions = new HostingActions( Resource::find( $r->id() ) );
		$site    = array();

		foreach ( $actions->sites() as $one ) {
			if ( $slug === $one['slug'] ) {
				$site = $one;
			}
		}

		$node = (array) ( \BeltoftServices\Hosting\Nodes::get( (string) Records::for_resource( $r->id() )['node'] ) ?? array() );
		$view = \BeltoftServices\Hosting\HostingView::site( Resource::find( $r->id() ), $site, false, true, array(), $node, $actions->limits() );

		return \BeltoftServices\Hosting\HostingView::capture( 'site', $view );
	}

	public function test_the_panel_of_a_plain_website_has_no_wordpress_part_and_no_wordpress_text(): void {
		$r = $this->account( array( 'install' => false ) );
		( new HostingActions( $r ) )->add_domain( 'first', 'example.org' );
		$html = $this->panel( $r );

		$this->assertStringNotContainsString( 'WordPress', $html );
		$this->assertStringNotContainsString( 'wp-login', $html );
		$this->assertStringNotContainsString( 'data-hosting-action="login"', $html );
		$this->assertStringNotContainsString( 'data-hosting-action="password"', $html );
		$this->assertStringNotContainsString( 'data-hosting-action="cache"', $html );
		$this->assertStringNotContainsString( 'data-sec="login"', $html );
		$this->assertStringNotContainsString( 'data-sec="cache"', $html );
		$this->assertStringNotContainsString( 'data-sec="staging"', $html );
		$this->assertStringNotContainsString( 'data-wp-address', $html );
		$this->assertStringNotContainsString( 'value="login"', $html, 'The login scope of the country block is not offered.' );

		// What is neutral is there, and the file manager is the first button.
		$this->assertStringContainsString( 'data-hosting-action="tool_files"', $html );
		$this->assertStringContainsString( 'data-hosting-action="tool_database"', $html );
		$this->assertStringContainsString( 'data-sec="domains"', $html );
		$this->assertStringContainsString( 'data-sec="php"', $html );
		$this->assertStringContainsString( 'data-sec="country"', $html );
		$this->assertMatchesRegularExpression( '/beltoft-hosting-btn--primary" data-hosting-action="tool_files"/', $html );
		$this->assertStringContainsString( 'This website runs on PHP', $html );
	}

	public function test_the_panel_of_a_wordpress_website_is_as_it_was_and_a_site_with_no_flag_gets_the_same_page(): void {
		$r = $this->account();
		( new HostingActions( $r ) )->add_domain( 'first', 'example.org' );
		$with = $this->panel( $r );

		$this->assertStringContainsString( 'data-hosting-action="login"', $with );
		$this->assertStringContainsString( 'Log in to WordPress', $with );
		$this->assertStringContainsString( 'data-hosting-action="cache"', $with );
		$this->assertStringContainsString( 'data-sec="login"', $with );
		$this->assertStringContainsString( 'data-sec="cache"', $with );
		$this->assertStringContainsString( 'data-sec="staging"', $with );
		$this->assertStringContainsString( 'value="login"', $with );

		global $wpdb;
		$row = (array) Sites::find( $r->id(), 'first' );
		$flags = $row['flags'];
		unset( $flags['wp'] );
		$wpdb->update( \BeltoftServices\Hosting\Schema::tables()['hosting_sites'], array( 'flags' => wp_json_encode( $flags ) ), array( 'resource_id' => $r->id(), 'slug' => 'first' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- test fixture.
		$this->assertSame( $with, $this->panel( $r ), 'A website made before the flag existed shows the page it always had.' );
	}

	public function test_a_plain_website_that_has_a_staging_copy_can_still_delete_it(): void {
		$r = $this->account();
		$this->change( $r, 'create_staging', array( 'first' ) );
		$this->agent->set_wordpress( $this->vmid( $r ), 'first', false );
		SiteFacts::refresh( Resource::find( $r->id() ) );
		$html = $this->panel( $r );

		$this->assertStringContainsString( 'data-sec="staging"', $html, 'The copy is still there to see.' );
		$this->assertStringContainsString( 'data-hosting-action="staging_delete"', $html );
		$this->assertStringNotContainsString( 'data-hosting-action="staging_open"', $html );
		$this->assertStringNotContainsString( 'data-hosting-action="staging_push"', $html );
		$this->assertStringNotContainsString( 'data-hosting-action="staging_refresh"', $html );
		$this->assertIsArray( $this->change( $r, 'delete_staging', array( 'first' ) ) );
	}

	public function test_the_checklist_drops_the_wordpress_password_for_plain_websites_only(): void {
		$r      = $this->account( array( 'install' => false ) );
		$sites  = array_values( array_filter( ( new HostingActions( $r ) )->sites(), static fn( $s ) => empty( $s['pending'] ) ) );
		$plain  = \BeltoftServices\Hosting\HostingView::checklist( $r, true, $sites );
		$labels = array_column( $plain['rows'], 'label' );

		$this->assertNotContains( 'Set a WordPress password', $labels );
		$this->assertContains( 'Build or upload your site', $labels );

		$sites[0]['wordpress'] = true;
		$this->assertContains( 'Set a WordPress password', array_column( \BeltoftServices\Hosting\HostingView::checklist( $r, true, $sites )['rows'], 'label' ) );
	}

	public function test_the_ready_email_leaves_the_wordpress_parts_out_for_a_plain_website(): void {
		$r      = $this->account( array( 'install' => false ) );
		$values = \BeltoftServices\Hosting\HostingEmail::values( Resource::find( $r->id() ) );
		$this->assertFalse( $values['wordpress'] );

		foreach ( array( 'templates/emails/beltoft-services-hosting-ready.php', 'templates/emails/plain/beltoft-services-hosting-ready.php' ) as $file ) {
			$text = $this->email( $file, $values );
			$this->assertStringNotContainsString( 'WordPress', $text, $file );
			$this->assertStringContainsString( 'Upload your files with SFTP', $text, $file );
			$this->assertStringContainsString( 'SFTP', $text );
		}

		$r2     = $this->account();
		$wp     = \BeltoftServices\Hosting\HostingEmail::values( Resource::find( $r2->id() ) );
		$this->assertTrue( $wp['wordpress'] );
		$this->assertStringContainsString( 'Log in to WordPress', $this->email( 'templates/emails/plain/beltoft-services-hosting-ready.php', $wp ) );
	}

	/**
	 * Renders an email template with the values.
	 *
	 * @param string               $file   Template path.
	 * @param array<string, mixed> $hosting Values.
	 */
	private function email( string $file, array $hosting ): string {
		$email_heading      = 'Ready';
		$customer_name      = 'Christian';
		$account_url        = 'https://example.test/my-account/';
		$additional_content = '';
		$email              = new class() extends \WC_Email {
			public function __construct() {
				$this->id = 'beltoft_services_hosting_ready';
			}
		};

		ob_start();
		include BELTOFT_SERVICES_PATH . $file;

		return wp_strip_all_tags( (string) ob_get_clean() );
	}

	public function test_the_build_steps_of_a_plain_account_say_creating_a_website(): void {
		$plain = $this->resource( array(), array( 'site' => array( 'slug' => 'plainsteps', 'install' => false ) ) );
		$work  = \BeltoftServices\Hosting\Actions\Steps::build_steps( null, $plain, 'build', 'hosting' );
		$this->assertSame( 'Creating your website', $work['labels']['site'] );

		$wp = $this->resource( array(), array( 'site' => array( 'slug' => 'wpsteps', 'install' => true ) ) );
		$this->assertNull( \BeltoftServices\Hosting\Actions\Steps::build_steps( null, $wp, 'build', 'hosting' ), 'A WordPress account keeps the driver\'s own steps.' );
		$this->assertNull( \BeltoftServices\Hosting\Actions\Steps::build_steps( null, $plain, 'reinstall', 'hosting' ) );
	}

	public function test_the_overview_says_per_website_whether_it_has_wordpress(): void {
		$r = $this->account( array( 'install' => false ) );
		wp_set_current_user( (int) $r->license()['user_id'] );
		$data = rest_do_request( new \WP_REST_Request( 'GET', '/beltoft-services/v1/resources/' . $r->id() . '/hosting' ) )->get_data();

		$this->assertFalse( $data['sites'][0]['wordpress'] );
		$this->change( $r, 'add_site', array( 'withwp', '', '8.3', true ) );
		$data = rest_do_request( new \WP_REST_Request( 'GET', '/beltoft-services/v1/resources/' . $r->id() . '/hosting' ) )->get_data();
		$this->assertSame( array( false, true ), array_column( $data['sites'], 'wordpress' ) );
	}
}

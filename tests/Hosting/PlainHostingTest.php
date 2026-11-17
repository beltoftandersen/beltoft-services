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
}

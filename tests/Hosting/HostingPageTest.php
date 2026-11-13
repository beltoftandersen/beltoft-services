<?php
/**
 * The hosting page of My Account, second pass: panels, a domain with and without www and its redirect, which administrator
 * the WordPress login uses, the WordPress password link, the Files (SFTP) panel and its routes.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Hosting;

require_once __DIR__ . '/HostingCase.php';

use BeltoftServices\Api\Routes;
use BeltoftServices\Hosting\Actions\FilesActions;
use BeltoftServices\Hosting\HostingAccount;
use BeltoftServices\Hosting\HostingActions;
use BeltoftServices\Hosting\HostingRoutes;
use BeltoftServices\Hosting\SiteAddresses;
use BeltoftServices\Hosting\SiteDns;
use BeltoftServices\Hosting\SiteDomains;
use BeltoftServices\Hosting\Sites;
use BeltoftServices\Resource;
use BeltoftServices\StepUp;

/**
 * HostingAccount (panels), SiteActions (pairs, main address), PhpActions (login and password links), FilesActions, HostingRoutes.
 */
class HostingPageTest extends HostingCase {

	private const BASE = '/' . Routes::NAMESPACE . '/resources/';
	private const KEY  = 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIOMqqnkVzrm0SdG6UOoqKLsabgH5C9okWi0dh2l9GKJl user@host';

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

	private function account( array $plan = array() ): Resource {
		$r = $this->resource( array_merge( array( 'sites' => 3, 'php_workers' => 10, 'php_memory_mb' => 512, 'upload_mb' => 100 ), $plan ) );
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

	/**
	 * Makes both names of a domain active, as when the customer's DNS is in place.
	 */
	private function activate( string ...$hosts ): void {
		foreach ( $hosts as $host ) {
			SiteDomains::set_state( $host, 'active' );
		}
	}

	/**
	 * The calls of an operation made after a mark, for the slug.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function since( int $mark, string $op ): array {
		return array_values( array_filter( array_slice( $this->agent->calls(), $mark ), static fn( $c ) => $c['op'] === $op ) );
	}

	/**
	 * A step-up code for the file access purpose, from the captured mail.
	 */
	private function code( int $user ): string {
		$this->assertTrue( StepUp::send( $user, HostingRoutes::STEP_PURPOSE ) );
		$mail = (string) end( $this->mails )['message'];
		$this->assertSame( 1, preg_match( '/\b(\d{6})\b/', wp_strip_all_tags( $mail ), $m ) );

		return $m[1];
	}

	// -- Names ---------------------------------------------------------------------------------------------

	public function test_the_names_to_connect_for_what_was_typed(): void {
		$this->assertSame( array( 'example.org', 'www.example.org' ), SiteAddresses::names_for( 'example.org' ) );
		$this->assertSame( array( 'www.example.org', 'example.org' ), SiteAddresses::names_for( ' WWW.Example.ORG. ' ) );
		$this->assertSame( array( 'shop.example.org' ), SiteAddresses::names_for( 'shop.example.org' ), 'A subdomain is connected only as typed.' );
		$this->assertSame( array( 'www.shop.example.org' ), SiteAddresses::names_for( 'www.shop.example.org' ) );
		$this->assertSame( array( 'example.co.uk', 'www.example.co.uk' ), SiteAddresses::names_for( 'example.co.uk' ), 'The public suffix list decides what a registrable domain is.' );
		$this->assertSame( array( 'not valid' ), SiteAddresses::names_for( 'not valid' ) );
	}

	public function test_a_domain_connects_with_and_without_www_and_the_typed_form_is_the_main_address(): void {
		$r    = $this->account();
		$a    = new HostingActions( $r );
		$slug = 'w' . $r->id();

		$added = $a->add_domain( $slug, 'example.org' );
		$this->assertSame( 'example.org', $added['host'] );
		$this->assertSame( 'www.example.org', $added['also'] );
		$this->assertSame( 'external', $added['kind'] );
		$this->assertNotNull( SiteDomains::find( 'example.org' ) );
		$this->assertNotNull( SiteDomains::find( 'www.example.org' ) );
		$this->assertSame( 'apex', SiteDomains::find( 'example.org' )['www_mode'] );
		$this->assertSame( '', SiteDomains::find( 'www.example.org' )['www_mode'] );

		// A different form of the same domain keeps the choice already made.
		$a->add_domain( $slug, 'www.example.org' );
		$this->assertSame( 'apex', SiteDomains::find( 'example.org' )['www_mode'] );
	}

	public function test_a_www_typed_domain_makes_www_the_main_address(): void {
		$r = $this->account();
		( new HostingActions( $r ) )->add_domain( 'w' . $r->id(), 'www.example.org' );

		$this->assertSame( 'www', SiteDomains::find( 'example.org' )['www_mode'] );
		$this->assertNotNull( SiteDomains::find( 'www.example.org' ) );
	}

	public function test_a_subdomain_is_connected_alone_and_has_no_pair(): void {
		$r = $this->account();
		( new HostingActions( $r ) )->add_domain( 'w' . $r->id(), 'shop.example.org' );

		$this->assertNotNull( SiteDomains::find( 'shop.example.org' ) );
		$this->assertNull( SiteDomains::find( 'www.shop.example.org' ) );
		$this->assertSame( '', SiteDomains::find( 'shop.example.org' )['www_mode'] );
	}

	public function test_the_other_name_of_a_pair_that_another_website_has_is_left_alone_and_said(): void {
		$r = $this->account();
		$a = new HostingActions( $r );
		$a->add_site( 'blog', 'Blog' );
		$a->add_domain( 'blog', 'www.example.org' );
		$added = $a->add_domain( 'w' . $r->id(), 'example.org' );

		$this->assertSame( '', $added['also'] );
		$this->assertStringContainsString( 'already used by another website', $added['notice'] );
		$this->assertSame( (int) Sites::find( $r->id(), 'blog' )['id'], SiteDomains::find( 'www.example.org' )['site_id'], 'It stays on the website that had it.' );
	}

	public function test_names_reach_the_node_only_when_active_and_the_redirect_only_when_both_names_are_active(): void {
		$r    = $this->account();
		$a    = new HostingActions( $r );
		$slug = 'w' . $r->id();
		$mark = count( $this->agent->calls() );

		$a->add_domain( $slug, 'example.org' );
		$this->assertSame( array(), $this->since( $mark, 'site.domains' ), 'Names that do not point here yet are not sent.' );

		$this->activate( 'example.org' );
		$a->sync_edge();
		$calls = $this->since( $mark, 'site.domains' );
		$this->assertCount( 1, $calls );
		$this->assertSame( array( 'example.org' ), $calls[0]['args']['domains'] );
		$this->assertArrayNotHasKey( 'redirects', $calls[0]['args'], 'The other name does not point here: nothing redirects, the working name keeps working.' );

		$this->activate( 'www.example.org' );
		$a->sync_edge();
		$calls = $this->since( $mark, 'site.domains' );
		$this->assertCount( 2, $calls );
		$this->assertSame( array( 'example.org', 'www.example.org' ), $calls[1]['args']['domains'] );
		$this->assertSame( array( array( 'from' => 'www.example.org', 'to' => 'example.org' ) ), $calls[1]['args']['redirects'] );

		$a->sync_edge();
		$this->assertCount( 2, $this->since( $mark, 'site.domains' ), 'The same names are not sent twice.' );

		$vmid = \BeltoftServices\Hosting\Records::for_resource( $r->id() )['vmid'];
		$this->assertSame( array( array( 'from' => 'www.example.org', 'to' => 'example.org' ) ), $this->agent->state()['sites'][ $vmid ][ $slug ]['redirects'] );
		$this->assertContains( 'www.example.org', array_column( $this->agent->state()['edge'][ 'c' . $r->id() ], 'host' ), 'Both names are routed at the edge once active.' );
	}

	public function test_the_main_address_is_switched_and_only_the_redirect_changes(): void {
		$r    = $this->account();
		$a    = new HostingActions( $r );
		$slug = 'w' . $r->id();
		$a->add_domain( $slug, 'example.org' );
		$this->activate( 'example.org', 'www.example.org' );
		$a->sync_edge();
		$mark = count( $this->agent->calls() );

		$this->assertSame( array( 'main' => 'www.example.org', 'redirect' => true ), $a->set_main_address( 'www.example.org' ) );
		$this->assertSame( 'www', SiteDomains::find( 'example.org' )['www_mode'] );
		$calls = $this->since( $mark, 'site.domains' );
		$this->assertSame( array( array( 'from' => 'example.org', 'to' => 'www.example.org' ) ), $calls[0]['args']['redirects'] );

		// Nothing else touched the website: no install, no command that could rewrite its database, only names and routes.
		$ops = array_unique( array_column( array_slice( $this->agent->calls(), $mark ), 'op' ) );
		$this->assertSame( array( 'site.domains' ), $ops );

		$sites = ( new HostingActions( Resource::find( $r->id() ) ) )->sites();
		$this->assertSame( 'www.example.org', $sites[0]['main'] );

		$this->assertSame( array( 'main' => 'example.org', 'redirect' => true ), $a->set_main_address( 'example.org' ) );
		$this->assertSame( 'apex', SiteDomains::find( 'example.org' )['www_mode'] );
	}

	public function test_set_main_address_refuses_a_name_that_is_not_a_pair_of_the_account(): void {
		$r = $this->account();
		$a = new HostingActions( $r );
		$a->add_domain( 'w' . $r->id(), 'shop.example.org' );

		$this->assertSame( 'not_found', $a->set_main_address( 'shop.example.org' )->get_error_code(), 'No www pair.' );
		$this->assertSame( 'not_found', $a->set_main_address( 'unknown.example' )->get_error_code() );
		$this->assertSame( 'not_found', $a->set_main_address( 'w' . $r->id() . '.preview.example.test' )->get_error_code() );
	}

	public function test_a_name_that_does_not_point_here_yet_is_chosen_without_a_redirect_and_the_page_says_so(): void {
		$r    = $this->account();
		$a    = new HostingActions( $r );
		$slug = 'w' . $r->id();
		$a->add_domain( $slug, 'example.org' );
		$this->activate( 'example.org' );
		$mark = count( $this->agent->calls() );

		$this->assertSame( array( 'main' => 'www.example.org', 'redirect' => false ), $a->set_main_address( 'www.example.org' ) );
		$this->assertArrayNotHasKey( 'redirects', $this->since( $mark, 'site.domains' )[0]['args'] ?? array(), 'The working name is not redirected to one that does not work.' );

		ob_start();
		HostingAccount::render( Resource::find( $r->id() ) );
		$html = (string) ob_get_clean();
		$this->assertStringContainsString( 'The redirect starts when both addresses point here.', $html );
		$this->assertMatchesRegularExpression( '/www\.example\.org<\/span> <span class="beltoft-hosting-note">waiting for DNS/', $html );
		$this->assertSame( 'example.org', $a->sites()[0]['main'], 'The main address shown is a name that works.' );
	}

	public function test_an_older_agent_gets_the_names_and_the_choice_is_refused_plainly(): void {
		$r    = $this->account();
		$a    = new HostingActions( $r );
		$slug = 'w' . $r->id();
		$a->add_domain( $slug, 'example.org' );
		$this->activate( 'example.org', 'www.example.org' );

		$this->agent->fail_next( 'site.domains', 'The argument "redirects" is not valid for site.domains.' );
		$err = $a->set_main_address( 'www.example.org' );

		$this->assertWPError( $err );
		$this->assertSame( 'failed', $err->get_error_code() );
		$this->assertStringContainsString( 'not available on your server yet', $err->get_error_message() );
		$this->assertStringNotContainsString( 'argument', $err->get_error_message(), 'Nothing the agent says reaches the customer.' );
		$this->assertSame( 'apex', SiteDomains::find( 'example.org' )['www_mode'], 'The choice is not kept when it cannot be applied.' );
		$vmid = \BeltoftServices\Hosting\Records::for_resource( $r->id() )['vmid'];
		$this->assertSame( array( 'example.org', 'www.example.org' ), $this->agent->state()['sites'][ $vmid ][ $slug ]['domains'], 'The names still went to the node.' );
	}

	public function test_removing_the_www_name_ends_the_pair_and_a_name_leaves_the_node(): void {
		$r    = $this->account();
		$a    = new HostingActions( $r );
		$slug = 'w' . $r->id();
		$a->add_domain( $slug, 'example.org' );
		$this->activate( 'example.org', 'www.example.org' );
		$a->sync_edge();

		$this->assertSame( array( 'removed' => 'www.example.org' ), $a->remove_domain( 'www.example.org' ) );
		$this->assertSame( '', SiteDomains::find( 'example.org' )['www_mode'] );
		$vmid = \BeltoftServices\Hosting\Records::for_resource( $r->id() )['vmid'];
		$this->assertSame( array( 'example.org' ), $this->agent->state()['sites'][ $vmid ][ $slug ]['domains'] );
		$this->assertSame( array(), $this->agent->state()['sites'][ $vmid ][ $slug ]['redirects'] );
	}

	public function test_new_dns_records_use_a_one_hour_ttl(): void {
		$this->assertSame( 3600, SiteDns::TTL );
		$r = $this->account();
		$a = new HostingActions( $r );
		$a->add_site( 'blog', 'Blog' );
		$ttls = array();

		foreach ( $this->dns->zone( 'preview.example.test' )['rrsets'] as $set ) {
			if ( 'blog.preview.example.test.' === $set['name'] ) {
				$ttls[] = $set['ttl'];
			}
		}

		$this->assertNotSame( array(), $ttls );
		$this->assertSame( array( 3600 ), array_values( array_unique( $ttls ) ) );
	}

	// -- Logging in ----------------------------------------------------------------------------------------

	public function test_the_install_remembers_the_administrator_and_the_login_says_who_it_signed_in(): void {
		$r    = $this->account();
		$a    = new HostingActions( $r );
		$slug = 'w' . $r->id();
		$site = $a->sites()[0];

		$this->assertSame( 'owner', $site['admin_user'] );
		$this->assertSame( (string) $r->license()['email'], $site['admin_email'] );

		$link = $a->login_link( $slug );
		$this->assertSame( 'owner', $link['user'] );
		$this->assertSame( (string) $r->license()['email'], $link['email'] );
		$this->assertStringStartsWith( 'https://' . $slug . '.preview.example.test/', $link['url'] );
		$this->assertSame( 'owner', $this->agent->calls_of( 'site.wp_login_link' )[0]['args']['admin_user'], 'The administrator the install made is asked for by name.' );
	}

	public function test_an_agent_that_does_not_know_the_administrator_argument_is_asked_again_without_it(): void {
		$r    = $this->account();
		$a    = new HostingActions( $r );
		$slug = 'w' . $r->id();

		$this->agent->fail_next( 'site.wp_login_link', 'The argument "admin_user" is not valid for site.wp_login_link.' );
		$link = $a->login_link( $slug );

		$this->assertStringStartsWith( 'https://', $link['url'] );
		$calls = $this->agent->calls_of( 'site.wp_login_link' );
		$this->assertCount( 2, $calls );
		$this->assertArrayNotHasKey( 'admin_user', $calls[1]['args'] );
	}

	public function test_a_website_without_a_stored_administrator_works_and_the_page_does_not_guess_one(): void {
		$r    = $this->account();
		$slug = 'w' . $r->id();
		Sites::update( $r->id(), $slug, array( 'flags' => array( 'admin_user' => '', 'admin_email' => '' ) ) );
		$a = new HostingActions( $r );

		$this->assertArrayNotHasKey( 'admin_user', $a->login_link( $slug ) === null ? array() : $this->agent->calls_of( 'site.wp_login_link' )[0]['args'] );
		$this->assertSame( 'Signs you in as the administrator of this website. No password is needed.', HostingAccount::login_facts( '', '' ) );
		$this->assertSame( 'Signs you in as owner. No password is needed.', HostingAccount::login_facts( 'owner', '' ) );
		$this->assertSame( 'Signs you in as owner (a@example.org). No password is needed.', HostingAccount::login_facts( 'owner', 'a@example.org' ) );
	}

	public function test_the_password_link_is_a_one_time_address_that_is_never_stored(): void {
		global $wpdb;

		$r    = $this->account();
		$a    = new HostingActions( $r );
		$slug = 'w' . $r->id();

		$link = $a->password_link( $slug );
		$this->assertSame( 'https://' . $slug . '.preview.example.test/?fake-password=1', $link['url'] );
		$this->assertSame( 'owner', $link['user'] );
		$this->assertSame( 'not_found', $a->password_link( 'ghost' )->get_error_code() );

		// Not in the options, not in any table of the plugin, not in the resource.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$this->assertSame( '0', (string) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_value LIKE '%fake-password%'" ) );
		$this->assertStringNotContainsString( 'fake-password', (string) wp_json_encode( array_map( static fn( $k ) => Resource::find( $r->id() )->get( $k ), array( 'steps', 'plan', 'usage', 'checklist' ) ) ) );
	}

	public function test_an_older_agent_without_the_password_operation_gets_a_plain_message(): void {
		$r = $this->account();
		$a = new HostingActions( $r );

		$this->agent->fail_next( 'site.wp_password_link', 'The operation is not in the list.' );
		$this->agent->fail_next( 'site.wp_password_link', 'The operation is not in the list.' );
		$err = $a->password_link( 'w' . $r->id() );

		$this->assertSame( 'failed', $err->get_error_code() );
		$this->assertStringContainsString( 'not available on your server yet', $err->get_error_message() );
		$this->assertStringContainsString( 'Lost your password?', $err->get_error_message() );
		$this->assertStringNotContainsString( 'operation is not in the list', $err->get_error_message() );
	}

	public function test_the_password_route_is_for_the_owner_and_has_its_own_hourly_limit(): void {
		$r    = $this->account();
		$slug = 'w' . $r->id();
		$path = $r->id() . '/hosting/sites/' . $slug . '/password';

		$ok = $this->call( 'POST', $path, $this->user( $r ) );
		$this->assertSame( 200, $ok->get_status() );
		$this->assertSame( 'owner', $ok->get_data()['user'] );
		$this->assertStringContainsString( 'no-store', (string) $ok->get_headers()['Cache-Control'] );

		$this->assertSame( 403, $this->call( 'POST', $path, self::factory()->user->create() )->get_status() );
		$this->assertSame( 401 === $this->call( 'POST', $path, 0 )->get_status() ? 401 : 403, $this->call( 'POST', $path, 0 )->get_status() );

		$status = 200;

		for ( $i = 0; $i < HostingRoutes::LOGIN_LIMIT + 1 && 429 !== $status; $i++ ) {
			$status = $this->call( 'POST', $path, $this->user( $r ) )->get_status();
		}

		$this->assertSame( 429, $status );
		$this->assertSame( 200, $this->call( 'POST', $r->id() . '/hosting/sites/' . $slug . '/login', $this->user( $r ) )->get_status(), 'The sign-in link has its own group.' );
	}

	public function test_a_resource_that_is_not_hosting_refuses_the_new_routes(): void {
		$fake = $this->fake_driver();
		$p    = $this->service_product( 'fake', array(), array( 'slug' => 'f-' . wp_generate_password( 4, false ) ) );
		$l    = $this->service_license( $p, array( 'fire' => false ) );
		$res  = Resource::create( $l, $fake, array(), array() );
		$res->set( array( 'state' => 'ready', 'desired' => 'ready' ) );
		$user = (int) $l['user_id'];

		$this->assertSame( 409, $this->call( 'POST', $res->id() . '/hosting/sites/x/password', $user )->get_status() );
		$this->assertSame( 409, $this->call( 'POST', $res->id() . '/hosting/domains/example.org/main', $user )->get_status() );
		$this->assertSame( 409, $this->call( 'POST', $res->id() . '/hosting/files/password', $user, array( 'code' => '123456' ) )->get_status() );
	}

	public function test_the_main_address_route(): void {
		$r = $this->account();
		( new HostingActions( $r ) )->add_domain( 'w' . $r->id(), 'example.org' );
		$this->activate( 'example.org', 'www.example.org' );

		$res = $this->call( 'POST', $r->id() . '/hosting/domains/www.example.org/main', $this->user( $r ) );
		$this->assertSame( 200, $res->get_status() );
		$this->assertSame( 'www.example.org', $res->get_data()['main'] );
		$this->assertSame( 403, $this->call( 'POST', $r->id() . '/hosting/domains/example.org/main', self::factory()->user->create() )->get_status() );
		$this->assertSame( 404, $this->call( 'POST', $r->id() . '/hosting/domains/shop.example.net/main', $this->user( $r ) )->get_status() );
	}

	// -- Files (SFTP) ---------------------------------------------------------------------------------------

	public function test_the_files_facts_are_the_nodes_address_the_port_and_the_user(): void {
		$r     = $this->account();
		$files = ( new HostingActions( $r ) )->files();

		$this->assertSame( '198.51.100.7', $files['host'] );
		$this->assertSame( (int) \BeltoftServices\Hosting\Records::for_resource( $r->id() )['ssh_port'], $files['port'] );
		$this->assertSame( 'web', $files['user'] );
		$this->assertSame( array(), $files['keys'] );
		$this->assertFalse( $files['password_set'] );
	}

	public function test_a_password_needs_the_emailed_code_and_is_returned_once_and_never_stored(): void {
		$r    = $this->account();
		$user = $this->user( $r );
		$path = $r->id() . '/hosting/files/password';

		$no = $this->call( 'POST', $path, $user, array( 'code' => '000000' ) );
		$this->assertSame( 403, $no->get_status() );
		$this->assertSame( array(), $this->agent->calls_of( 'ssh.password' ), 'Nothing reaches the node without the code.' );

		$ok = $this->call( 'POST', $path, $user, array( 'code' => $this->code( $user ) ) );
		$this->assertSame( 200, $ok->get_status() );
		$data = $ok->get_data();
		$this->assertSame( 24, strlen( $data['password'] ) );
		$this->assertSame( 'web', $data['user'] );
		$this->assertStringContainsString( 'no-store', (string) $ok->get_headers()['Cache-Control'] );

		$sent = $this->agent->calls_of( 'ssh.password' )[0]['args']['password'];
		$this->assertNotSame( $data['password'], $sent, 'The call log has the password masked.' );
		$this->assertTrue( ( new HostingActions( Resource::find( $r->id() ) ) )->files()['password_set'] );

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$this->assertSame( '0', (string) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_value LIKE %s", '%' . $wpdb->esc_like( $data['password'] ) . '%' ) ) );
		$this->assertStringNotContainsString( $data['password'], (string) wp_json_encode( Resource::find( $r->id() )->get( 'sftp_password_at' ) ) );

		$this->assertSame( 403, $this->call( 'POST', $path, $user, array( 'code' => '123456' ) )->get_status(), 'A code works once.' );
	}

	public function test_a_chosen_password_is_checked_and_used(): void {
		$r    = $this->account();
		$user = $this->user( $r );
		$path = $r->id() . '/hosting/files/password';

		$short = $this->call( 'POST', $path, $user, array( 'code' => $this->code( $user ), 'password' => 'short' ) );
		$this->assertSame( 422, $short->get_status() );

		$long = 'a-long-password-of-mine-1';
		$ok   = $this->call( 'POST', $path, $user, array( 'code' => $this->code( $user ), 'password' => $long ) );
		$this->assertSame( 200, $ok->get_status() );
		$this->assertSame( $long, $ok->get_data()['password'] );
	}

	public function test_file_access_is_for_the_owner_only_and_the_code_of_another_purpose_does_not_work(): void {
		$r     = $this->account();
		$user  = $this->user( $r );
		$other = self::factory()->user->create();

		$this->assertTrue( StepUp::send( $user, \BeltoftServices\Account\ApiTokens::PURPOSE ) );
		$mail = (string) end( $this->mails )['message'];
		preg_match( '/\b(\d{6})\b/', wp_strip_all_tags( $mail ), $m );

		$this->assertSame( 403, $this->call( 'POST', $r->id() . '/hosting/files/password', $user, array( 'code' => $m[1] ) )->get_status() );
		$this->assertSame( 403, $this->call( 'POST', $r->id() . '/hosting/files/password', $other, array( 'code' => '123456' ) )->get_status() );
		$this->assertSame( 403, $this->call( 'POST', $r->id() . '/hosting/files/keys', $other, array( 'code' => '123456', 'key' => self::KEY ) )->get_status() );
		$this->assertSame( 403, $this->call( 'DELETE', $r->id() . '/hosting/files/keys', $other, array( 'fingerprint' => 'SHA256:x' ) )->get_status() );
		$this->assertSame( array(), $this->agent->calls_of( 'ssh.password' ) );
		$this->assertSame( array(), $this->agent->calls_of( 'ssh.keys' ) );
	}

	public function test_file_access_changes_have_their_own_hourly_limit(): void {
		$r    = $this->account();
		$user = $this->user( $r );
		$last = 0;

		for ( $i = 0; $i < HostingRoutes::FILES_LIMIT + 1 && 429 !== $last; $i++ ) {
			$last = $this->call( 'POST', $r->id() . '/hosting/files/password', $user, array( 'code' => '000000' ) )->get_status();
		}

		$this->assertSame( 429, $last );
	}

	public function test_a_key_is_added_listed_and_removed_with_the_whole_list_sent_to_the_node(): void {
		$r    = $this->account();
		$user = $this->user( $r );
		$base = $r->id() . '/hosting/files/keys';

		$this->assertSame( 403, $this->call( 'POST', $base, $user, array( 'code' => '000000', 'key' => self::KEY ) )->get_status() );

		$ok = $this->call( 'POST', $base, $user, array( 'code' => $this->code( $user ), 'key' => self::KEY ) );
		$this->assertSame( 200, $ok->get_status() );
		$this->assertCount( 1, $this->agent->calls_of( 'ssh.keys' ), 'The node got the whole list (the call log masks the keys).' );

		$files = ( new HostingActions( Resource::find( $r->id() ) ) )->files();
		$this->assertSame( FilesActions::fingerprint( self::KEY ), $files['keys'][0]['fingerprint'] );
		$this->assertSame( 'ssh-ed25519', $files['keys'][0]['type'] );
		$this->assertSame( 'user@host', $files['keys'][0]['comment'] );
		$this->assertStringStartsWith( 'SHA256:', $files['keys'][0]['fingerprint'] );

		// The codes are limited to three a ten minutes, so the refusals of the key itself are checked on the action.
		$actions = new HostingActions( Resource::find( $r->id() ) );
		$this->assertSame( 'exists', $actions->add_ssh_key( self::KEY )->get_error_code(), 'The same key twice.' );
		$this->assertSame( 'invalid', $actions->add_ssh_key( 'not a key' )->get_error_code() );
		$this->assertSame( 'invalid', $actions->add_ssh_key( 'ssh-ed25519 AAAA' )->get_error_code(), 'Too short to be a key.' );

		$gone = $this->call( 'DELETE', $base, $user, array( 'fingerprint' => $files['keys'][0]['fingerprint'] ) );
		$this->assertSame( 200, $gone->get_status() );
		$this->assertCount( 2, $this->agent->calls_of( 'ssh.keys' ) );
		$this->assertSame( array(), ( new HostingActions( Resource::find( $r->id() ) ) )->files()['keys'] );
		$this->assertSame( 404, $this->call( 'DELETE', $base, $user, array( 'fingerprint' => 'SHA256:nothing' ) )->get_status() );
	}

	public function test_a_failed_node_call_keeps_the_stored_keys_as_they_were(): void {
		$r = $this->account();
		$a = new HostingActions( $r );

		$this->agent->fail_next( 'ssh.keys' );
		$this->assertWPError( $a->add_ssh_key( self::KEY ) );
		$this->assertSame( array(), $a->files()['keys'] );
		$this->assertSame( 1, $a->add_ssh_key( self::KEY )['keys'] );
	}

	public function test_the_code_email_says_what_it_is_for(): void {
		$r    = $this->account();
		$user = $this->user( $r );
		$this->code( $user );

		$this->assertStringContainsString( 'setting a password or an SSH key for your hosting files', (string) end( $this->mails )['message'] );
	}

	// -- The page -----------------------------------------------------------------------------------------------

	private function page( Resource $r ): string {
		ob_start();
		HostingAccount::render( Resource::find( $r->id() ) );

		return (string) ob_get_clean();
	}

	public function test_the_page_is_panels_with_headings_and_no_site_select_in_the_domain_form(): void {
		$r = $this->account();
		( new HostingActions( $r ) )->add_domain( 'w' . $r->id(), 'example.org' );
		$this->activate( 'example.org', 'www.example.org' );
		( new HostingActions( Resource::find( $r->id() ) ) )->sync_edge();
		$html = $this->page( $r );

		foreach ( array( 'Get started', 'Websites', 'Add a website', 'Files (SFTP)', 'Backups', 'Email' ) as $heading ) {
			$this->assertMatchesRegularExpression( '/<h3 class="beltoft-hosting-(panel|section)__title">' . preg_quote( $heading, '/' ) . '<\/h3>/', $html, $heading );
		}

		$this->assertStringNotContainsString( 'data-hosting-field="domain_slug"', $html, 'The domain form sits inside the website, so no site is chosen.' );
		$this->assertStringNotContainsString( '<table class="beltoft-hosting-sites"', $html );
		$this->assertStringContainsString( 'id="beltoft-site-w' . $r->id() . '-connect"', $html );
		$this->assertStringContainsString( 'Log in to WordPress', $html );
		$this->assertStringContainsString( 'Set a WordPress password', $html );
		$this->assertStringContainsString( '<dt>WordPress user</dt><dd>owner</dd>', $html );
		$this->assertStringContainsString( '<dt>Email address</dt><dd>' . esc_html( $r->license()['email'] ) . '</dd>', $html );
		$this->assertStringContainsString( 'Signs you in to WordPress as this user, in a new tab. No password is needed.', $html );
		$this->assertStringContainsString( 'Clears the page cache and the object cache of this website.', $html );
		$this->assertStringContainsString( 'data-hosting-action="php_save"', $html );
		$this->assertStringContainsString( 'This website runs on PHP 8.3.', $html );
		$this->assertStringContainsString( 'PHP 8.3', $html );
		$this->assertStringContainsString( 'type="radio"', $html );
		$this->assertStringContainsString( 'The other address redirects to the main address. This does not change the address set in WordPress.', $html );
		$this->assertStringContainsString( 'Main address', $html );
		$this->assertStringNotContainsString( "\u{2014}", $html );
		$this->assertStringNotContainsString( "\u{00B7}", $html );
	}

	public function test_there_is_at_most_one_primary_button_in_a_website_panel_and_no_remove_for_the_last_website(): void {
		$r    = $this->account();
		$html = $this->page( $r );

		foreach ( explode( '<section ', $html ) as $section ) {
			if ( false !== strpos( $section, 'beltoft-hosting-site' ) ) {
				$this->assertLessThanOrEqual( 1, substr_count( $section, 'beltoft-hosting-btn--primary' ) );
			}
		}

		$this->assertStringNotContainsString( 'data-hosting-action="remove_site"', $html );
		( new HostingActions( $r ) )->add_site( 'second', 'Second' );
		$this->assertStringContainsString( 'data-hosting-action="remove_site"', $this->page( $r ) );
		$this->assertStringContainsString( 'Remove website', $this->page( $r ) );
	}

	public function test_the_checklist_actions_point_to_where_the_item_is_done_and_the_password_item_is_optional(): void {
		$r    = $this->account();
		$html = $this->page( $r );

		$this->assertStringContainsString( '0 of 5 done', $html );
		$this->assertStringContainsString( 'href="#beltoft-site-w' . $r->id() . '-connect">Connect a domain</a>', $html );
		$this->assertStringContainsString( 'href="#beltoft-hosting-email">Set up email</a>', $html );
		$this->assertStringContainsString( 'data-hosting-action="check_item" data-item="password"', $html );
		$this->assertStringContainsString( 'data-hosting-action="dismiss_checklist"', $html );
	}

	public function test_set_up_email_says_it_leads_to_dns_records_for_a_domain_at_another_provider(): void {
		global $wpdb;
		$r = $this->account();
		$this->connect_domain( $r, 'ownmail.example' );
		$html = $this->page( $r );
		$this->assertStringNotContainsString( 'After you press the button', $html, 'A domain in our own zone gets no such warning.' );

		$wpdb->update( \BeltoftServices\Hosting\Schema::tables()['hosting_domains'], array( 'kind' => 'external' ), array( 'host' => 'ownmail.example' ) );
		$html = $this->page( $r );
		$this->assertStringContainsString( 'After you press the button, you get a list of DNS records to add where the DNS of ownmail.example is managed. Three are needed: MX for receiving, SPF and DKIM for sending. Three more are recommended.', $html );
		$this->assertStringContainsString( 'data-hosting-action="enable_mail"', $html );
	}

	public function test_the_add_a_website_panel_only_shows_while_the_plan_has_room(): void {
		$r = $this->account( array( 'sites' => 1 ) );

		$this->assertStringNotContainsString( 'id="beltoft-hosting-add"', $this->page( $r ) );

		$r2 = $this->account( array( 'sites' => 3 ) );
		$this->assertStringContainsString( 'id="beltoft-hosting-add"', $this->page( $r2 ) );
		$this->assertStringContainsString( 'data-hosting-field="label"', $this->page( $r2 ) );
	}

	public function test_the_files_panel_has_the_details_the_password_and_key_forms_and_the_code_field(): void {
		$r    = $this->account();
		$html = $this->page( $r );

		$this->assertStringContainsString( '<code>198.51.100.7</code>', $html );
		$this->assertStringContainsString( 'data-copy="web"', $html );
		$this->assertStringContainsString( 'data-hosting-action="files_password"', $html );
		$this->assertStringContainsString( 'data-hosting-action="files_key"', $html );
		$this->assertStringContainsString( 'data-hosting-action="send_code"', $html );
		$this->assertStringContainsString( 'It is shown once and is not stored here.', $html );
		$this->assertStringContainsString( 'No password is set yet.', $html );

		$r->set( array( 'state' => 'paused' ) );
		$this->assertStringNotContainsString( 'files_password', $this->page( $r ), 'No form while the account takes no changes.' );
	}

	public function test_the_script_knows_the_new_actions_and_stays_free_of_dashes(): void {
		$js = (string) file_get_contents( BELTOFT_SERVICES_PATH . 'assets/hosting.js' );

		foreach ( array( "'password'", "'php_save'", "'set_main'", "'remove_domain'", "'files_password'", "'files_key'", "'remove_key'", "'send_code'", "step-up/send", "hosting_files", 'data-files-secret' ) as $needle ) {
			$this->assertStringContainsString( $needle, $js, $needle );
		}

		$this->assertStringNotContainsString( "\u{2014}", $js );
		$this->assertStringNotContainsString( "\u{00B7}", $js );
		$this->assertStringNotContainsString( 'localStorage', $js, 'The password is not kept anywhere in the browser.' );
	}

	public function test_the_thank_you_row_and_the_email_say_how_to_log_in(): void {
		$r   = $this->account();
		$row = \BeltoftServices\ThankYou::row( $r );

		$this->assertStringContainsString( 'It signs you in as owner without a password', $row['message'] );
		$this->assertStringContainsString( 'You can also set a WordPress password there', $row['message'] );
		$this->assertSame( 'owner', \BeltoftServices\Hosting\HostingEmail::values( $r )['admin_user'] );
	}

	// -- The DNS guide ------------------------------------------------------------------------------------------

	public function test_the_dns_guide_is_one_table_for_both_names_with_copy_buttons_and_a_check(): void {
		$r = $this->account();
		( new HostingActions( $r ) )->add_domain( 'w' . $r->id(), 'example.org' );
		$html = $this->page( $r );

		$this->assertSame( 1, substr_count( $html, 'data-dnsg="' ), 'One guide for the name with and without www.' );
		$this->assertStringContainsString( 'Point example.org to this website', $html );
		$this->assertStringNotContainsString( 'To use ', $html );
		$this->assertStringContainsString( 'data-dns-copy="@"', $html );
		$this->assertStringContainsString( 'data-dns-copy="www"', $html );
		$this->assertStringContainsString( 'data-dns-copy="_beltoft-verify"', $html );
		$this->assertStringContainsString( 'data-dns-copy="198.51.100.7"', $html );
		$this->assertStringContainsString( 'data-dns-copy="' . \BeltoftServices\Domains\Ownership::token( (int) $r->license()['user_id'], 'example.org' ) . '"', $html );
		$this->assertSame( 1, substr_count( $html, 'data-dns-copy="_beltoft-verify' ), 'The www name needs no proof of its own.' );
		$this->assertStringContainsString( 'data-hosting-action="dns_check" data-host="example.org"', $html );
		$this->assertStringContainsString( 'Not checked yet', $html );

		$this->assertSame( '@', \BeltoftServices\Hosting\DnsGuide::label( 'example.org', 'example.org' ) );
		$this->assertSame( 'shop', \BeltoftServices\Hosting\DnsGuide::label( 'shop.example.org', 'example.org' ) );
		$this->assertSame( '_beltoft-verify.shop', \BeltoftServices\Hosting\DnsGuide::label( '_beltoft-verify.shop.example.org', 'example.org' ) );
	}

	public function test_check_now_looks_the_records_up_says_what_it_found_and_puts_the_names_live(): void {
		$r    = $this->account();
		$user = (int) $r->license()['user_id'];
		( new HostingActions( $r ) )->add_domain( 'w' . $r->id(), 'example.org' );
		$token = \BeltoftServices\Hosting\SiteDns::proof_token( $r->id(), 'example.org' );
		add_filter( 'beltoft_services_hosting_dns_nameservers', static fn() => array( 'ns01.one.com', 'ns02.one.com' ) );

		// The address points somewhere else and there is no proof yet.
		remove_all_filters( 'beltoft_services_dns_resolve' );
		add_filter( 'beltoft_services_dns_resolve', static fn( $pre, $name, $type ) => 'A' === $type ? array( '203.0.113.9' ) : array(), 10, 3 );
		$res = $this->call( 'POST', $r->id() . '/hosting/domains/example.org/check', $user );
		$this->assertSame( 200, $res->get_status() );
		$this->assertSame( array( 'example.org', 'www.example.org' ), $res->get_data()['waiting'] );
		$html = $this->page( $r );
		$this->assertStringContainsString( 'Points to 203.0.113.9 now', $html );
		$this->assertStringContainsString( 'Not found yet', $html );
		$this->assertStringContainsString( 'it looks like one.com', $html );
		$this->assertStringContainsString( 'Last checked', $html );

		// Everything is in place: both names go live and the guide is gone.
		remove_all_filters( 'beltoft_services_dns_resolve' );
		add_filter( 'beltoft_services_dns_resolve', static fn( $pre, $name, $type ) => 'TXT' === $type ? array( $token ) : ( 'A' === $type ? array( '198.51.100.7' ) : array( '2001:db8::7' ) ), 10, 3 );
		$res = $this->call( 'POST', $r->id() . '/hosting/domains/www.example.org/check', $user );
		$this->assertSame( array( 'example.org', 'www.example.org' ), $res->get_data()['live'] );
		$this->assertSame( 'active', SiteDomains::find( 'www.example.org' )['state'] );
		$this->assertStringNotContainsString( 'data-dnsg="', $this->page( $r ) );

		// Somebody else's account, and a name that is not on the account.
		$this->assertNotSame( 200, $this->call( 'POST', $r->id() . '/hosting/domains/example.org/check', self::factory()->user->create() )->get_status() );
		$this->assertNotSame( 200, $this->call( 'POST', $r->id() . '/hosting/domains/other.example/check', $user )->get_status() );
	}

	public function test_the_mail_panel_says_the_steps_before_a_domain_is_connected(): void {
		$r    = $this->account();
		$html = $this->page( $r );

		$this->assertStringContainsString( 'Email lite gives you mailboxes on your own domain', $html );
		$this->assertStringContainsString( 'href="#beltoft-site-w' . $r->id() . '-connect"', $html );

		( new HostingActions( $r ) )->add_domain( 'w' . $r->id(), 'example.org' );
		$this->assertStringContainsString( 'example.org is connected and waits for its DNS', $this->page( $r ) );
	}

	public function test_the_move_panel_shows_only_when_the_shop_has_a_request_page(): void {
		$r = $this->account();
		$this->assertStringNotContainsString( 'beltoft-hosting-move', $this->page( $r ) );

		$this->settings( array( 'hosting_move_url' => '/contact/?about=Website move', 'hosting_move_free' => 1 ) );
		$html = $this->page( $r );
		$this->assertStringContainsString( 'id="beltoft-hosting-move"', $html );
		$this->assertStringContainsString( 'We move it here for you, free of charge.', $html );
		$this->assertStringContainsString( 'Request a move', $html );
		$this->assertStringContainsString( esc_url( home_url( '/contact/?about=Website move' ) ), $html );

		$this->settings( array( 'hosting_move_url' => '/contact/', 'hosting_move_free' => 0 ) );
		$this->assertStringContainsString( 'Have a website somewhere else? We can move it here for you.', $this->page( $r ) );
	}
}

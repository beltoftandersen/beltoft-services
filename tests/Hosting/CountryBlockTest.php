<?php
/**
 * The country block of a website: the action, the routes, the page and the guards.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Hosting;

require_once __DIR__ . '/HostingCase.php';

use BeltoftServices\Api\Routes;
use BeltoftServices\Hosting\CountryBlock;
use BeltoftServices\Hosting\HostingAccount;
use BeltoftServices\Hosting\HostingActions;
use BeltoftServices\Resource;

/**
 * CountryActions through HostingActions, the country-block routes, CountryBlock and the Protection row of HostingAccount.
 */
class CountryBlockTest extends HostingCase {

	private const BASE = '/' . Routes::NAMESPACE . '/resources/';

	public function set_up(): void {
		parent::set_up();
		$this->settings( array( 'hosting_php_versions' => '8.3,8.4,8.5' ) );
		add_filter( 'beltoft_services_rest_rate_limit', '__return_zero' );
	}

	public function tear_down(): void {
		remove_all_filters( 'beltoft_services_rest_rate_limit' );
		remove_all_filters( 'beltoft_services_visitor_country' );
		remove_all_filters( 'beltoft_services_hosting_country_lists' );
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

	private function rest( string $method, string $path, int $user, array $body = array() ): \WP_REST_Response {
		wp_set_current_user( $user );
		$request = new \WP_REST_Request( $method, self::BASE . $path );

		if ( 'GET' !== $method ) {
			$request->set_header( 'content-type', 'application/json' );
			$request->set_body( (string) wp_json_encode( $body ) );
		}

		return rest_get_server()->dispatch( $request );
	}

	private function page( Resource $r ): string {
		ob_start();
		HostingAccount::render( Resource::find( $r->id() ) );

		return (string) ob_get_clean();
	}

	public function test_the_block_goes_to_the_agent_with_sorted_countries_and_the_scope_and_is_kept(): void {
		$r    = $this->account();
		$a    = new HostingActions( $r );
		$slug = 'w' . $r->id();

		$done = $a->set_country_block( $slug, array( 'ru', 'BY', 'RU' ), 'login' );
		$this->assertIsArray( $done );
		$this->assertTrue( $done['enabled'] );
		$this->assertSame( 'login', $done['scope'] );
		$this->assertSame( array( 'BY', 'RU' ), array_column( $done['countries'], 'code' ) );
		$this->assertTrue( $done['active'] );

		$calls = $this->agent->calls_of( 'site.country_block' );
		$this->assertCount( 1, $calls );
		$this->assertSame( array( 'BY', 'RU' ), $calls[0]['args']['countries'] );
		$this->assertSame( 'login', $calls[0]['args']['scope'] );
		$this->assertSame( $slug, $calls[0]['args']['slug'] );

		$again = ( new HostingActions( Resource::find( $r->id() ) ) )->country_block( $slug );
		$this->assertSame( array( 'BY', 'RU' ), array_column( $again['countries'], 'code' ) );
		$this->assertSame( 'login', $again['scope'] );
		$this->assertSame( array( 'sanctioned', 'login_risk' ), array_keys( $again['lists'] ) );

		$sites = $a->sites();
		$this->assertTrue( $sites[0]['country']['enabled'] );
	}

	public function test_an_empty_list_turns_it_off(): void {
		$r    = $this->account();
		$a    = new HostingActions( $r );
		$slug = 'w' . $r->id();

		$a->set_country_block( $slug, array( 'CN' ), 'site' );
		$off = $a->set_country_block( $slug, array(), 'site' );

		$this->assertFalse( $off['enabled'] );
		$this->assertSame( array(), $off['countries'] );
		$this->assertNull( $off['active'] );
		$last = array_values( $this->agent->calls_of( 'site.country_block' ) );
		$this->assertSame( array(), end( $last )['args']['countries'] );
	}

	public function test_a_value_that_is_not_a_country_or_a_scope_is_refused_and_the_agent_is_not_asked(): void {
		$r    = $this->account();
		$a    = new HostingActions( $r );
		$slug = 'w' . $r->id();

		foreach ( array( array( 'ZZ' ), array( 'Denmark' ), array( 'DK; id' ), array( '' ), array( array( 'DK' ) ) ) as $bad ) {
			$err = $a->set_country_block( $slug, $bad, 'site' );
			$this->assertWPError( $err, wp_json_encode( $bad ) );
			$this->assertSame( 'invalid', $err->get_error_code() );
		}

		$this->assertWPError( $a->set_country_block( $slug, array( 'RU' ), 'everything' ) );
		$this->assertSame( array(), $this->agent->calls_of( 'site.country_block' ) );
		$this->assertSame( 'not_found', $a->set_country_block( 'nothere', array( 'RU' ), 'site' )->get_error_code() );
	}

	public function test_the_persons_own_country_on_the_list_needs_a_confirmation_first(): void {
		$r    = $this->account();
		$a    = new HostingActions( $r );
		$slug = 'w' . $r->id();
		add_filter( 'beltoft_services_visitor_country', static fn() => 'dk' );

		$ask = $a->set_country_block( $slug, array( 'DK', 'RU' ), 'site' );
		$this->assertWPError( $ask );
		$this->assertSame( 'country_confirm', $ask->get_error_code() );
		$this->assertStringContainsString( 'Denmark', $ask->get_error_message() );
		$this->assertStringContainsString( 'whole website', $ask->get_error_message() );
		$this->assertSame( array(), $this->agent->calls_of( 'site.country_block' ), 'Nothing is changed before the answer.' );

		$login = $a->set_country_block( $slug, array( 'DK' ), 'login' );
		$this->assertStringContainsString( 'login and admin area', $login->get_error_message() );

		$this->assertIsArray( $a->set_country_block( $slug, array( 'DK', 'RU' ), 'site', true ) );
		$this->assertCount( 1, $this->agent->calls_of( 'site.country_block' ) );

		// Another country on the list, or an unknown own country, asks nothing.
		remove_all_filters( 'beltoft_services_visitor_country' );
		$this->assertIsArray( $a->set_country_block( $slug, array( 'DK' ), 'site' ) );
	}

	public function test_without_a_country_database_on_the_node_the_list_is_saved_and_the_page_says_nothing_is_blocked(): void {
		$r    = $this->account();
		$a    = new HostingActions( $r );
		$slug = 'w' . $r->id();
		$this->agent->set_geo_missing( true );

		$done = $a->set_country_block( $slug, array( 'RU' ), 'site' );
		$this->assertTrue( $done['enabled'] );
		$this->assertFalse( $done['active'] );
		$this->assertStringContainsString( 'nothing is blocked until it is', $this->page( $r ) );
	}

	public function test_a_refusal_of_the_node_is_told_plainly_and_nothing_is_saved(): void {
		$r    = $this->account();
		$a    = new HostingActions( $r );
		$slug = 'w' . $r->id();

		$this->agent->fail_next( 'site.country_block', 'nginx -t failed 10.200.1.5' );
		$err = $a->set_country_block( $slug, array( 'RU' ), 'site' );
		$this->assertWPError( $err );
		$this->assertStringNotContainsString( '10.200', $err->get_error_message() );
		$this->assertFalse( $a->country_block( $slug )['enabled'] );
	}

	public function test_the_routes_need_the_owner_read_and_write_and_are_not_cached(): void {
		$r    = $this->account();
		$slug = 'w' . $r->id();
		$user = (int) $r->license()['user_id'];
		$path = $r->id() . '/hosting/sites/' . $slug . '/country-block';

		$set = $this->rest( 'POST', $path, $user, array( 'countries' => array( 'RU', 'CN' ), 'scope' => 'login' ) );
		$this->assertSame( 200, $set->get_status() );
		$this->assertStringContainsString( 'no-store', (string) $set->get_headers()['Cache-Control'] );

		$get = $this->rest( 'GET', $path, $user );
		$this->assertSame( 200, $get->get_status() );
		$this->assertSame( array( 'CN', 'RU' ), array_column( $get->get_data()['countries'], 'code' ) );
		$this->assertSame( 'login', $get->get_data()['scope'] );

		$this->assertSame( 403, $this->rest( 'GET', $path, self::factory()->user->create() )->get_status(), 'A stranger sees nothing.' );
		$this->assertSame( 403, $this->rest( 'POST', $path, self::factory()->user->create(), array( 'countries' => array( 'RU' ) ) )->get_status() );
		$this->assertSame( 404, $this->rest( 'GET', $r->id() . '/hosting/sites/nothere/country-block', $user )->get_status() );
		$this->assertSame( 400, $this->rest( 'POST', $path, $user, array( 'scope' => 'site' ) )->get_status(), 'The list is required.' );
		$this->assertSame( 400, $this->rest( 'POST', $path, $user, array( 'countries' => array( 'RU' ), 'scope' => 'nowhere' ) )->get_status() );
		$this->assertSame( 422, $this->rest( 'POST', $path, $user, array( 'countries' => array( 'ZZ' ), 'scope' => 'site' ) )->get_status() );
	}

	public function test_the_lock_out_question_is_an_answer_the_page_can_ask_and_repeat(): void {
		$r    = $this->account();
		$slug = 'w' . $r->id();
		$user = (int) $r->license()['user_id'];
		$path = $r->id() . '/hosting/sites/' . $slug . '/country-block';
		add_filter( 'beltoft_services_visitor_country', static fn() => 'RU' );

		$ask = $this->rest( 'POST', $path, $user, array( 'countries' => array( 'RU' ), 'scope' => 'site' ) );
		$this->assertSame( 409, $ask->get_status() );
		$this->assertSame( 'beltoft_services_hosting_country_confirm', $ask->get_data()['code'] );
		$this->assertSame( 200, $this->rest( 'POST', $path, $user, array( 'countries' => array( 'RU' ), 'scope' => 'site', 'confirm' => true ) )->get_status() );
	}

	public function test_the_routes_are_for_the_dashboard_only(): void {
		$route = '/' . Routes::NAMESPACE . '/resources/1/hosting/sites/blog/country-block';
		$this->assertFalse( \BeltoftServices\Api\Tokens::reachable( $route ), 'A token cannot lock a customer out.' );
	}

	public function test_the_ready_lists_are_data_with_names_from_the_shop_and_the_filter_changes_them(): void {
		$lists = CountryBlock::lists();
		$this->assertSame( array( 'RU', 'BY', 'IR', 'KP', 'SY', 'CU' ), $lists['sanctioned']['countries'] );
		$this->assertSame( array( 'CN', 'VN', 'IN', 'PK', 'TH', 'KZ', 'SC', 'SG', 'MA' ), $lists['login_risk']['countries'] );
		$this->assertSame( 'Denmark', CountryBlock::name( 'DK' ) );
		$this->assertSame( 'ZZ', CountryBlock::name( 'ZZ' ) );

		add_filter(
			'beltoft_services_hosting_country_lists',
			static fn( $l ) => array( 'mine' => array( 'label' => 'Mine', 'countries' => array( 'se', 'ZZ' ) ) )
		);
		$this->assertSame( array( 'mine' => array( 'label' => 'Mine', 'countries' => array( 'SE' ) ) ), CountryBlock::lists() );
	}

	public function test_every_country_the_shop_lists_is_one_the_agent_accepts(): void {
		$file = '/var/www/wordpress/workspaces/hosting-agent-cb/hosting_agent/ops/geo.py';

		if ( ! is_readable( $file ) ) {
			$this->markTestSkipped( 'The agent sources are not next to this plugin.' );
		}

		preg_match( '/COUNTRY_CODES = frozenset\("""(.*?)"""/s', (string) file_get_contents( $file ), $m );
		$accepted = preg_split( '/\s+/', trim( $m[1] ) );
		$missing  = array_diff( array_keys( CountryBlock::names() ), $accepted );

		$this->assertSame( array(), array_values( $missing ), 'Codes the shop offers that the agent would refuse.' );
	}

	public function test_the_page_has_the_protection_row_the_form_and_the_attribution(): void {
		$r    = $this->account();
		$slug = 'w' . $r->id();

		$html = $this->page( $r );
		$this->assertStringContainsString( 'Visitors from every country can reach this website.', $html );
		$this->assertStringContainsString( 'data-hosting-action="country_edit"', $html );
		$this->assertStringContainsString( 'data-hosting-action="country_save"', $html );
		$this->assertStringContainsString( 'data-codes="RU BY IR KP SY CU"', $html );
		$this->assertStringContainsString( 'Select: Sanctioned countries', $html );
		$this->assertStringContainsString( 'Login and admin only', $html );
		$this->assertStringContainsString( 'a VPN gets around it', $html );
		$this->assertStringContainsString( 'This product includes GeoLite2 data created by MaxMind, available from https://www.maxmind.com', $html );
		$this->assertStringNotContainsString( 'data-hosting-action="country_off"', $html );

		( new HostingActions( $r ) )->set_country_block( $slug, array( 'RU', 'BY' ), 'login' );
		$html = $this->page( $r );
		$this->assertStringContainsString( '<dt>Applies to</dt><dd>Login and admin only</dd></div><div><dt>Blocked</dt><dd>Belarus, Russia</dd>', $html );
		$this->assertMatchesRegularExpression( '/<input type="checkbox" data-country value="RU" checked/', $html );
		$this->assertDoesNotMatchRegularExpression( '/value="DK" checked/', $html );
		$this->assertStringContainsString( 'data-hosting-action="country_off"', $html );
	}

	public function test_a_site_that_is_not_ready_shows_the_state_and_no_form(): void {
		$r = $this->account();
		$r->set( array( 'state' => 'paused', 'desired' => 'paused' ) );

		$html = $this->page( $r );
		$this->assertStringContainsString( 'Visitors from every country can reach this website.', $html );
		$this->assertStringNotContainsString( 'data-hosting-action="country_save"', $html );
	}

	public function test_the_script_posts_the_choice_asks_again_on_the_lock_out_answer_and_the_filter_does_not_submit(): void {
		$js = (string) file_get_contents( BELTOFT_SERVICES_PATH . 'assets/hosting.js' );
		$this->assertStringContainsString( "'/country-block'", $js );
		$this->assertStringContainsString( "'beltoft_services_hosting_country_confirm'", $js );
		$this->assertStringContainsString( 'again.confirm = true', $js );
		$this->assertStringContainsString( "hasAttribute( 'data-hosting-nosubmit' )", $js );
		$this->assertStringContainsString( 'country_cancel', $js, 'Escape closes the form.' );
	}

	public function test_the_new_strings_have_no_dash_or_middle_dot(): void {
		foreach ( array( 'src/Hosting/Actions/CountryActions.php', 'src/Hosting/CountryBlock.php', 'src/Hosting/HostingAccount.php' ) as $file ) {
			preg_match_all( "/__\\(\\s*'((?:[^'\\\\]|\\\\.)*)'/", (string) file_get_contents( BELTOFT_SERVICES_PATH . $file ), $m );

			foreach ( $m[1] as $string ) {
				$this->assertStringNotContainsString( "\u{2014}", $string );
				$this->assertStringNotContainsString( "\u{00B7}", $string );
			}
		}
	}
}

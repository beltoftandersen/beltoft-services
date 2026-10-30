<?php
/**
 * The firewall route and the Firewall tab. Fake client only.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Proxmox;

use BeltoftLicenses\Account\View;
use BeltoftServices\Account\Servers;
use BeltoftServices\Jobs;
use BeltoftServices\Proxmox\Firewall;
use BeltoftServices\Proxmox\FirewallTab;
use BeltoftServices\Resource;

require_once __DIR__ . '/FirewallTestCase.php';

class FirewallRouteTest extends FirewallTestCase {

	public function tear_down(): void {
		unset( $GLOBALS['wp']->query_vars['vps'] );
		remove_all_filters( 'beltoft_services_rest_rate_limit' );
		parent::tear_down();
	}

	/**
	 * A ready server with the feature on.
	 *
	 * @return array{0: Resource, 1: int}
	 */
	private function on(): array {
		list( $r, $user ) = $this->ready();
		$this->save_cluster( array( 'fw_enabled' => '1' ) );

		return array( Resource::find( $r->id() ), $user );
	}

	private function put( Resource $r, int $user, array $rules, array $more = array() ): \WP_REST_Response {
		return $this->route( 'PUT', $r->id(), $user, array_merge( array( 'on' => true, 'rules' => $rules ), $more ) );
	}

	public function test_get_of_an_unmanaged_server_says_what_proxmox_shows_and_is_not_cached_by_browsers(): void {
		list( $r, $user ) = $this->on();
		$response         = $this->route( 'GET', $r->id(), $user );
		$data             = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertStringContainsString( 'no-store', (string) $response->get_headers()['Cache-Control'] );
		$this->assertFalse( $data['managed'] );
		$this->assertSame( 'unknown', $data['live'], 'No inbound policy is set on a server nobody took over.' );
		$this->assertSame( '203.0.113.9', $data['my_ip'] );
		$this->assertNotEmpty( $data['defaults'] );
		$this->assertArrayNotHasKey( 'fw_error', $data );
	}

	public function test_the_live_read_is_kept_for_a_minute_and_a_proxmox_error_does_not_break_the_page(): void {
		list( $r, $user ) = $this->on();
		$base             = '/nodes/node-a/qemu/' . (int) $r->get( 'vmid' ) . '/firewall/options';
		$count            = fn() => count( array_filter( $this->fake->calls(), static fn( $c ) => 'GET' === $c['method'] && $c['path'] === $base ) );

		$before = $count();
		$this->route( 'GET', $r->id(), $user );
		$this->route( 'GET', $r->id(), $user );
		$this->assertSame( $before + 1, $count(), 'One read for two page loads.' );

		delete_transient( 'beltoft_services_fw_live_' . $r->id() );
		$this->fake->fail_next( 'GET', '#/firewall/options$#', array( 'status' => 500 ) );
		$response = $this->route( 'GET', $r->id(), $user );
		$this->assertSame( 200, $response->get_status() );
		$this->assertNull( $response->get_data()['live'] );
	}

	public function test_another_customer_a_missing_id_and_a_switched_off_cluster_get_the_same_answer(): void {
		list( $r ) = $this->on();
		$other     = self::factory()->user->create();

		$foreign = $this->route( 'GET', $r->id(), $other );
		$missing = $this->route( 'GET', 999999, $other );

		$this->assertSame( 403, $foreign->get_status() );
		$this->assertSame( $foreign->get_data(), $missing->get_data() );
		$this->assertSame( 403, $this->put( $r, $other, array() )->get_status() );
		$this->assertSame( $foreign->get_data(), $this->put( $r, $other, array() )->get_data() );

		list( $own, $owner ) = $this->ready();
		$this->save_cluster( array( 'fw_enabled' => '0' ) );
		$this->assertSame( 403, $this->route( 'GET', $own->id(), $owner )->get_status() );
		$this->assertSame( 403, $this->put( $own, $owner, array( $this->rule( 'tcp', 22 ) ) )->get_status() );
	}

	public function test_put_stores_the_wanted_state_queues_one_job_and_the_job_applies_it(): void {
		list( $r, $user ) = $this->on();
		$response         = $this->put( $r, $user, array( $this->rule( 'tcp', 22 ), $this->rule( 'tcp', 8080, 0, array(), 'My app' ) ) );

		$this->assertSame( 202, $response->get_status() );
		$this->assertTrue( $response->get_data()['queued'] );

		$fresh = Resource::find( $r->id() );
		$this->assertTrue( Firewall::managed( $fresh ) );
		$this->assertSame( 'applying', $fresh->get( 'fw_state' ) );
		$this->assertCount( 2, Firewall::rules_of( $fresh ) );

		$this->run_jobs();
		$vmid = (int) $r->get( 'vmid' );
		$this->assertTrue( $this->fake->fake_inbound_allowed( $vmid, 'tcp', 8080, '198.51.100.1' ) );
		$this->assertFalse( $this->fake->fake_inbound_allowed( $vmid, 'tcp', 8081, '198.51.100.1' ) );

		$data = $this->route( 'GET', $r->id(), $user )->get_data();
		$this->assertTrue( $data['managed'] );
		$this->assertTrue( $data['on'] );
		$this->assertTrue( $data['applied'] );
		$this->assertSame( 'idle', $data['state'] );
		$this->assertSame( 'My app', $data['rules'][1]['label'] );
		$this->assertSame( 'TCP 8080', $data['rules'][1]['port'] );
		$this->assertNull( $data['live'] );
		$this->assertArrayNotHasKey( 'fw_error', $data );
	}

	public function test_refusals_are_422_with_a_plain_message_and_the_rule_that_is_wrong(): void {
		list( $r, $user ) = $this->on();
		$response         = $this->put( $r, $user, array( $this->rule( 'tcp', 22 ), $this->rule( 'tcp', 99999 ) ) );

		$this->assertSame( 422, $response->get_status() );
		$this->assertSame( 1, $response->get_data()['data']['rule'] );
		$this->assertStringContainsString( '1 to 65535', $response->get_data()['message'] );
		$this->assertFalse( Firewall::managed( Resource::find( $r->id() ) ), 'Nothing was stored.' );
		$this->assertSame( 422, $this->route( 'PUT', $r->id(), $user, array( 'on' => true, 'rules' => 'x' ) )->get_status() );
	}

	public function test_a_state_without_ssh_is_refused_unless_confirmed(): void {
		list( $r, $user ) = $this->on();

		$refused = $this->put( $r, $user, array( $this->rule( 'tcp', 80 ) ) );
		$this->assertSame( 409, $refused->get_status() );
		$this->assertStringContainsString( 'SSH', $refused->get_data()['message'] );

		$this->assertSame( 409, $this->put( $r, $user, array( $this->rule( 'tcp', 22, 22, array( '198.51.100.7' ) ) ) )->get_status(), 'SSH limited to another address.' );
		$this->assertSame( 202, $this->put( $r, $user, array( $this->rule( 'tcp', 22, 22, array( '203.0.113.0/24' ) ) ) )->get_status(), 'SSH limited to a network that has the customer.' );
		$this->assertSame( 202, $this->put( $r, $user, array( $this->rule( 'tcp', 80 ) ), array( 'ack_no_ssh' => true ) )->get_status() );
		$this->assertTrue( Resource::find( $r->id() )->get( 'fw_ssh_ack' ) );
	}

	public function test_turning_a_firewall_that_is_on_off_needs_a_confirmation(): void {
		list( $r, $user ) = $this->on();
		$this->assertSame( 202, $this->put( $r, $user, array( $this->rule( 'tcp', 22 ) ) )->get_status() );
		$this->run_jobs();

		$refused = $this->route( 'PUT', $r->id(), $user, array( 'on' => false, 'rules' => array( $this->rule( 'tcp', 22 ) ) ) );
		$this->assertSame( 422, $refused->get_status() );
		$this->assertStringContainsString( 'confirm', $refused->get_data()['message'] );

		$this->assertSame( 202, $this->route( 'PUT', $r->id(), $user, array( 'on' => false, 'confirm_off' => true, 'rules' => array( $this->rule( 'tcp', 22 ) ) ) )->get_status() );
		$this->run_jobs();
		$this->assertSame( 'ACCEPT', $this->fake->vm( (int) $r->get( 'vmid' ) )['firewall_options']['policy_in'] );
	}

	public function test_an_unmanaged_server_can_start_open_without_confirmation(): void {
		list( $r, $user ) = $this->on();
		$this->assertSame( 202, $this->route( 'PUT', $r->id(), $user, array( 'on' => false, 'rules' => array( $this->rule( 'tcp', 22 ) ) ) )->get_status() );
		$this->run_jobs();

		$vmid = (int) $r->get( 'vmid' );
		$this->assertSame( 'ACCEPT', $this->fake->vm( $vmid )['firewall_options']['policy_in'], 'Written explicitly: nothing depends on the default.' );
		$this->assertTrue( $this->fake->fake_inbound_allowed( $vmid, 'tcp', 3306, '198.51.100.1' ) );
	}

	public function test_the_rate_limits_answer_429(): void {
		list( $r, $user ) = $this->on();
		add_filter( 'beltoft_services_rest_rate_limit', static fn() => 2 );

		$codes = array();

		for ( $i = 0; $i < 4; $i++ ) {
			$codes[] = $this->put( $r, $user, array( $this->rule( 'tcp', 22 ) ) )->get_status();
		}

		$this->assertContains( 429, $codes );
	}

	public function test_the_floor_of_twenty_applies_per_hour_per_server(): void {
		list( $r, $user ) = $this->on();
		add_filter( 'beltoft_services_rest_rate_limit', static fn() => 0 );

		$codes = array();

		for ( $i = 0; $i < 22; $i++ ) {
			$codes[] = $this->put( $r, $user, array( $this->rule( 'tcp', 22 ), $this->rule( 'tcp', 9000 + $i ) ) )->get_status();
		}

		$this->assertSame( 202, $codes[0] );
		$this->assertSame( 429, $codes[21] );
	}

	public function test_reset_works_through_the_generic_action_route(): void {
		list( $r, $user ) = $this->on();
		wp_set_current_user( $user );
		$request  = new \WP_REST_Request( 'POST', '/beltoft-services/v1/resources/' . $r->id() . '/actions/fw_reset' );
		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 202, $response->get_status() );
		$this->run_jobs();
		$vmid = (int) $r->get( 'vmid' );
		$this->assertTrue( $this->fake->fake_inbound_allowed( $vmid, 'tcp', 443, '198.51.100.1' ) );
		$this->assertSame( 'DROP', $this->fake->vm( $vmid )['firewall_options']['policy_in'] );
	}

	public function test_a_change_that_no_job_works_on_reads_as_failed(): void {
		list( $r, $user ) = $this->on();
		Firewall::store( $r, true, Firewall::defaults( $r ), true, $user );
		as_unschedule_all_actions( Jobs::ACT, array(), Jobs::GROUP );

		$this->assertSame( 'failed', $this->route( 'GET', $r->id(), $user )->get_data()['state'] );
	}

	// -- The tab ---------------------------------------------------------------------------------------------

	private function page( Resource $r, string $tab = '' ): string {
		wp_set_current_user( (int) $r->license()['user_id'] );
		$view                             = View::license( $r->license() );
		$GLOBALS['wp']->query_vars['vps'] = (string) $r->license()['id'] . ( '' === $tab ? '' : '/' . $tab );
		ob_start();
		Servers::render( array( $view ) );

		return (string) ob_get_clean();
	}

	public function test_the_tab_sits_between_access_and_reinstall_and_only_for_a_server_the_customer_manages(): void {
		list( $r ) = $this->ready();
		$this->assertStringNotContainsString( 'data-tab-link="firewall"', $this->page( $r ), 'The cluster switch is off.' );

		$this->save_cluster( array( 'fw_enabled' => '1' ) );
		$html = $this->page( Resource::find( $r->id() ) );
		$this->assertMatchesRegularExpression( '#data-tab-link="access"[^>]*>Access</a>\s*<a[^>]*data-tab-link="firewall"[^>]*>Firewall</a>\s*<a[^>]*data-tab-link="reinstall"#', $html );
		$this->assertContains( 'firewall', Servers::tabs() );

		Resource::find( $r->id() )->set( array( 'fw_locked' => true ) );
		$this->assertStringNotContainsString( 'data-tab-link="firewall"', $this->page( Resource::find( $r->id() ) ), 'A database server is not editable here.' );
	}

	public function test_the_pane_prints_the_fixed_lines_and_the_ufw_note_only_for_an_app_server(): void {
		list( $r ) = $this->ready();
		$this->save_cluster( array( 'fw_enabled' => '1' ) );
		$plain = $this->page( Resource::find( $r->id() ), 'firewall' );

		$this->assertStringContainsString( 'data-fw', $plain );
		$this->assertStringContainsString( 'Outgoing mail on port 25 is blocked', $plain );
		$this->assertStringContainsString( 'address spoofing', $plain );
		$this->assertStringContainsString( 'in front of your server', $plain );
		$this->assertStringNotContainsString( 'Copy button', $plain );
		$this->assertStringNotContainsString( "\u{2014}", $plain );

		Resource::find( $r->id() )->set( array( 'app_id' => 'wordpress' ) );
		$this->assertStringContainsString( 'Copy button', $this->page( Resource::find( $r->id() ), 'firewall' ) );
	}

	public function test_the_mail_line_follows_the_cluster_setting(): void {
		list( $r ) = $this->ready();
		$this->save_cluster( array( 'fw_enabled' => '1', 'block_smtp' => '' ) );
		$this->assertStringNotContainsString( 'Outgoing mail', $this->page( Resource::find( $r->id() ), 'firewall' ) );
	}

	public function test_ufw_commands_are_given_for_app_servers_only(): void {
		list( $r ) = $this->ready( array( 'app_id' => 'wordpress' ) );
		$this->save_cluster( array( 'fw_enabled' => '1' ) );
		$r = Resource::find( $r->id() );
		Firewall::store( $r, true, Firewall::normalise( array( $this->rule( 'tcp', 8080 ), $this->rule( 'tcp', 9000, 9010, array( '203.0.113.7' ) ), $this->rule( 'icmp', 0 ) ), $r ), true, 1 );

		$rules = Firewall::view( Resource::find( $r->id() ) )['rules'];
		$this->assertSame( 'sudo ufw allow 8080/tcp', $rules[0]['ufw'] );
		$this->assertSame( 'sudo ufw allow from 203.0.113.7 to any port 9000:9010 proto tcp', $rules[1]['ufw'] );
		$this->assertSame( '', $rules[2]['ufw'] );

		$r->set( array( 'app_id' => null ) );
		$this->assertSame( '', Firewall::view( Resource::find( $r->id() ) )['rules'][0]['ufw'] );
	}

	public function test_the_account_buttons_do_not_draw_the_firewall_actions(): void {
		list( $r ) = $this->on();
		$buttons   = \BeltoftServices\Proxmox\Actions::buttons( ( new \BeltoftServices\Proxmox\ProxmoxDriver() )->customer_actions( $r ), $r, 'page' );

		$this->assertArrayNotHasKey( 'fw_apply', $buttons );
		$this->assertArrayNotHasKey( 'fw_reset', $buttons );
	}

	public function test_the_assets_load_only_where_the_customer_manages_the_server(): void {
		list( $r ) = $this->ready();
		$r         = Resource::find( $r->id() );
		wp_set_current_user( (int) $r->license()['user_id'] );
		$GLOBALS['wp']->query_vars['vps'] = (string) $r->license()['id'];
		wp_dequeue_script( FirewallTab::HANDLE );

		FirewallTab::assets( 'handle', Servers::slug() );
		$this->assertFalse( wp_script_is( FirewallTab::HANDLE, 'enqueued' ), 'The switch is off.' );

		$this->save_cluster( array( 'fw_enabled' => '1' ) );
		FirewallTab::assets( 'handle', 'orders' );
		$this->assertFalse( wp_script_is( FirewallTab::HANDLE, 'enqueued' ), 'Another endpoint.' );

		wp_set_current_user( self::factory()->user->create() );
		FirewallTab::assets( 'handle', Servers::slug() );
		$this->assertFalse( wp_script_is( FirewallTab::HANDLE, 'enqueued' ), 'Someone else\'s server.' );

		wp_set_current_user( (int) $r->license()['user_id'] );
		FirewallTab::assets( 'handle', Servers::slug() );
		$this->assertTrue( wp_script_is( FirewallTab::HANDLE, 'enqueued' ) );
		$this->assertTrue( wp_style_is( FirewallTab::HANDLE, 'enqueued' ) );
	}

	public function test_every_string_of_the_script_is_plain_text(): void {
		foreach ( FirewallTab::strings() as $name => $text ) {
			$this->assertStringNotContainsString( "\u{2014}", $text, $name );
			$this->assertDoesNotMatchRegularExpression( '/hetzner/i', $text, $name );
		}
	}

	public function test_a_pilot_server_gets_the_firewall_while_the_switch_is_off_and_others_do_not(): void {
		list( $pilot, $user ) = $this->ready();
		list( $other, $them ) = $this->ready();
		$this->save_cluster( array( 'fw_enabled' => '0', 'fw_pilot' => ' ' . $pilot->license()['id'] . ', x, 0' ) );

		$this->assertSame( array( (int) $pilot->license()['id'] ), \BeltoftServices\Proxmox\Clusters::get( 'lab' )['fw_pilot'], 'Only real license IDs are kept.' );
		$this->assertTrue( \BeltoftServices\Proxmox\Firewall::editable( Resource::find( $pilot->id() ) ) );
		$this->assertFalse( \BeltoftServices\Proxmox\Firewall::editable( Resource::find( $other->id() ) ) );
		$this->assertSame( 200, $this->route( 'GET', $pilot->id(), $user )->get_status() );
		$this->assertNotSame( 200, $this->route( 'GET', $other->id(), $them )->get_status() );
	}
}

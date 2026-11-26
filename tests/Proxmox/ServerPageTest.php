<?php
/** The server page, its tabs and the servers list. @package BeltoftServices */
namespace BeltoftServices\Tests\Proxmox;

use BeltoftLicenses\Account\Endpoint;
use BeltoftLicenses\Account\View;
use BeltoftServices\Account\ServerLinks;
use BeltoftServices\Proxmox\Vm\Servers;
use BeltoftServices\Proxmox\Vm\Operations;
use BeltoftServices\Tests\Proxmox\Network\MonitoringTestCase;

require_once __DIR__ . '/Network/MonitoringTestCase.php';

class ServerPageTest extends MonitoringTestCase {
	private function as_owner( $r ): array {
		wp_set_current_user( (int) $r->license()['user_id'] );
		return View::license( $r->license() );
	}

	private function route( string $value ): void {
		$GLOBALS['wp']->query_vars['vps'] = $value;
	}

	public function tear_down(): void {
		unset( $GLOBALS['wp']->query_vars['vps'] );
		parent::tear_down();
	}

	private function page( $r, string $value ): string {
		$view = $this->as_owner( $r );
		$this->route( $value );
		ob_start();
		Servers::render( array( $view ) );
		return (string) ob_get_clean();
	}

	public function test_urls_carry_the_license_and_the_tab(): void {
		$r = $this->ready_vm();
		$id = \BeltoftServices\Account\Slugs::for_license( $r->license() );
		$this->assertNotSame( '', $id );
		$this->assertMatchesRegularExpression( '#vps(/|=)' . preg_quote( $id, '#' ) . '/?$#', rawurldecode( ServerLinks::url( $r->license() ) ) );
		$this->assertStringContainsString( $id . '/snapshots', rawurldecode( ServerLinks::url( $r->license(), 'snapshots' ) ) );
		$this->assertSame( ServerLinks::url( $r->license() ), ServerLinks::url( $r->license(), 'nonsense' ) );
	}

	public function test_the_address_value_is_read_safely(): void {
		$this->route( '12/snapshots' );
		$this->assertSame( array( 'id' => 12, 'tab' => 'snapshots' ), ServerLinks::requested() );
		$this->route( '12/<b>x' );
		$this->assertSame( array( 'id' => 12, 'tab' => '' ), ServerLinks::requested() );
		$this->route( 'abc/snapshots' );
		$this->assertSame( 0, ServerLinks::requested()['id'] );
		$this->route( '' );
		$this->assertSame( array( 'id' => 0, 'tab' => '' ), ServerLinks::requested() );
	}

	public function test_only_the_licenses_template_on_the_servers_endpoint_is_taken_over(): void {
		$this->route( '' );
		$this->assertSame( 'x.php', Servers::template( 'x.php', 'myaccount/orders.php', array( 'licenses' => array() ) ) );
		$this->assertStringEndsWith( 'servers.php', (string) Servers::template( 'x.php', 'myaccount/licenses.php', array( 'licenses' => array() ) ) );
		unset( $GLOBALS['wp']->query_vars['vps'] );
		$this->assertSame( 'x.php', Servers::template( 'x.php', 'myaccount/licenses.php', array( 'licenses' => array() ) ) );
	}

	public function test_a_server_page_has_a_head_tabs_and_panes(): void {
		$r = $this->ready_vm();
		$r->set( array( 'server_name' => 'web-01', 'ip' => '192.0.2.10' ) );
		Operations::remember_power( $r->id(), 'running' );
		$html = $this->page( $r, (string) $r->license()['id'] );
		$this->assertStringContainsString( 'beltoft-services-page__rename-name">web-01</span>', $html );
		$this->assertStringContainsString( 'data-copy="ssh root@192.0.2.10"', $html );
		$this->assertStringNotContainsString( 'Copy SSH command', $html, 'The copy sits beside the Log in line, not among the power buttons.' );
		$this->assertStringContainsString( 'class="beltoft-services-copy" data-copy="ssh root@192.0.2.10"', $html );
		// owner 2026-11-26: the IP address has its own Copy button
		$this->assertStringContainsString( '<code>192.0.2.10</code> <button type="button" class="beltoft-services-copy" data-copy="192.0.2.10"', $html );
		$this->assertStringContainsString( 'data-power-action="reboot"', $html );
		$this->assertStringContainsString( 'data-power-action="shutdown"', $html );
		$this->assertStringNotContainsString( 'Console', $html );
		$this->assertStringContainsString( '<nav class="beltoft-services-tabs"', $html );
		foreach ( array( 'access', 'reinstall', 'overview' ) as $tab ) {
			$this->assertStringContainsString( 'data-tab-link="' . $tab . '"', $html );
			$this->assertStringContainsString( 'data-tab="' . $tab . '"', $html );
		}
		$this->assertSame( 1, preg_match_all( '/aria-current="page"/', $html ) );
		// Facts row.
		foreach ( array( 'IP address', 'Log in', 'Plan', 'ssh root@192.0.2.10' ) as $text ) {
			$this->assertStringContainsString( $text, $html );
		}
	}

	public function test_each_tab_opens_from_its_own_address_and_the_others_are_hidden(): void {
		$r = $this->ready_vm();
		$html = $this->page( $r, $r->license()['id'] . '/access' );
		$this->assertMatchesRegularExpression( '/data-tab="access"><\/section>|<section[^>]*data-tab="access">/', $html );
		$this->assertDoesNotMatchRegularExpression( '/data-tab="access" hidden/', $html );
		$this->assertMatchesRegularExpression( '/data-tab="overview" hidden/', $html );
		$this->assertMatchesRegularExpression( '/data-tab="reinstall" hidden/', $html );
		$this->assertMatchesRegularExpression( '/data-tab-link="access" aria-current="page"/', $html );
	}

	public function test_an_unavailable_tab_shows_the_overview(): void {
		$r = $this->ready_vm();
		// No snapshots or backups on this plan: those tabs do not exist.
		$html = $this->page( $r, $r->license()['id'] . '/backups' );
		$this->assertStringNotContainsString( 'data-tab="backups"', $html );
		$this->assertMatchesRegularExpression( '/data-tab-link="overview" aria-current="page"/', $html );
		$this->assertDoesNotMatchRegularExpression( '/data-tab="overview" hidden/', $html );
	}

	public function test_without_script_the_tabs_are_plain_links_and_the_copy_button_waits_for_script(): void {
		$r = $this->ready_vm();
		$r->set( array( 'ip' => '192.0.2.10' ) );
		$html = $this->page( $r, (string) $r->license()['id'] );
		preg_match_all( '#<a href="([^"]+)" data-tab-link="(\w+)"#', $html, $m );
		$this->assertNotEmpty( $m[1] );
		foreach ( $m[1] as $i => $href ) {
			$this->assertStringContainsString( 'vps', $href );
			$this->assertStringContainsString( \BeltoftServices\Account\Slugs::for_license( $r->license() ), rawurldecode( $href ) );
		}
		$this->assertMatchesRegularExpression( '/data-copy="[^"]+" data-copied="[^"]+" hidden/', $html );
		// The command is printed in the facts, so nothing is lost without script.
		$this->assertStringContainsString( '<code>ssh root@<wbr>192.0.2.10</code>', $html );
	}

	public function test_the_panel_keeps_what_the_scripts_bind_to(): void {
		$r = $this->ready_vm();
		$html = $this->page( $r, (string) $r->license()['id'] );
		foreach ( array( 'data-resource="' . $r->id() . '"', 'data-bs-scope="' . $r->id() . '"', 'data-badge', 'data-message', 'data-vm-metrics', 'data-range="hour"', 'data-confirm-dialog', 'data-progress' ) as $needle ) {
			$this->assertStringContainsString( $needle, $html, $needle );
		}
		// Every dialog is after the last pane, so a hidden tab never holds one.
		$last = strrpos( $html, '</section>' );
		$this->assertNotFalse( $last );
		$this->assertGreaterThan( $last, (int) strpos( $html, 'data-confirm-dialog' ) );
		$this->assertStringNotContainsString( 'data-flash', $html );
		$this->assertStringNotContainsString( 'data-notice', $html );
		$this->assertStringNotContainsString( 'data-dialog-notice', $html );
		// Reset password asks first (the shared question), so it is not a plain action button.
		$this->assertStringNotContainsString( 'data-action="reset_password"', $html );
	}

	public function test_a_foreign_or_unknown_id_shows_the_list_with_a_notice(): void {
		$r = $this->ready_vm();
		$html = $this->page( $r, '999999' );
		$this->assertStringContainsString( 'That server was not found', $html );
		$this->assertStringContainsString( 'beltoft-services-srv', $html );
		$this->assertStringNotContainsString( 'beltoft-services-tabs', $html );
	}

	public function test_the_old_address_shows_the_list_and_keeps_the_card_anchor(): void {
		$r = $this->ready_vm();
		$html = $this->page( $r, '' );
		$this->assertStringContainsString( 'id="beltoft-service-' . $r->id() . '"', $html );
		$this->assertStringContainsString( 'href="' . esc_url( ServerLinks::url( $r->license() ) ) . '"', $html );
	}

	public function test_a_single_server_still_shows_the_list(): void {
		$r = $this->ready_vm();
		$html = $this->page( $r, '' );
		$this->assertSame( 1, substr_count( $html, 'class="beltoft-services-srv"' ) );
	}

	public function test_list_bars_come_from_the_cached_status_with_no_proxmox_call(): void {
		$r = $this->ready_vm();
		$r->set( array( 'disk_used' => 15 * 1073741824, 'disk_total' => 30 * 1073741824, 'disk_read_at' => time() ) );
		set_transient( Operations::STATUS_PREFIX . $r->id(), array( 'power' => 'running', 'cpu' => 0.25, 'mem' => 1073741824 ), HOUR_IN_SECONDS );
		$before = count( $this->fake->calls() );
		$html = $this->page( $r, '' );
		$this->assertSame( $before, count( $this->fake->calls() ), 'The list makes no Proxmox call.' );
		$rows = Servers::rows( array( View::license( $r->license() ) ) );
		// The Proxmox 'mem' (1 GB above) is never used: without a guest reading the memory bar is empty ("...") and still shown.
		$this->assertSame( array( 'CPU' => 25, 'Memory' => 0, 'Disk' => 50 ), array_column( $rows[0]['bars'], 'percent', 'label' ) );
		$this->assertSame( 'waiting', array_column( $rows[0]['bars'], 'state', 'label' )['Memory'] );
		\BeltoftServices\Monitoring\GuestMemory::store( $r->id(), array( 'used_mb' => 1352, 'total_mb' => 4096 ) );
		$rows = Servers::rows( array( View::license( $r->license() ) ) );
		$this->assertSame( array( 'CPU' => 25, 'Memory' => 33, 'Disk' => 50 ), array_column( $rows[0]['bars'], 'percent', 'label' ) );
		$this->assertStringContainsString( 'Running', $html );
		$this->assertStringContainsString( 'style="width:25%"', $html );
	}

	public function test_a_stopped_server_or_a_missing_status_has_no_bars(): void {
		$r = $this->ready_vm();
		delete_transient( Operations::STATUS_PREFIX . $r->id() );
		$this->assertSame( array(), Servers::rows( array( View::license( $r->license() ) ) )[0]['bars'] );
		set_transient( Operations::STATUS_PREFIX . $r->id(), array( 'power' => 'stopped', 'cpu' => 0.0, 'mem' => 0 ), HOUR_IN_SECONDS );
		$rows = Servers::rows( array( View::license( $r->license() ) ) );
		$this->assertSame( array(), $rows[0]['bars'] );
		$this->assertSame( 'Stopped', $rows[0]['state'] );
	}

	public function test_names_are_escaped(): void {
		$r = $this->ready_vm();
		$r->set( array( 'server_name' => '<script>alert(1)</script>' ) );
		$this->assertStringNotContainsString( '<script>alert', $this->page( $r, '' ) );
		$this->assertStringNotContainsString( '<script>alert', $this->page( $r, (string) $r->license()['id'] ) );
	}

	public function test_the_slot_strip_shows_used_against_free_squares(): void {
		$beltoft_services_strip = array( 'used' => 2, 'slots' => 5 );
		ob_start();
		include BELTOFT_SERVICES_PATH . 'templates/account/slots.php';
		$html = (string) ob_get_clean();
		$this->assertStringContainsString( '<i style="width: 40%"></i>', $html, 'Two of five slots fill 40% of the bar.' );
		$this->assertStringNotContainsString( 'is-full', $html );
		$this->assertStringContainsString( 'aria-hidden="true"', $html );
	}

	public function test_the_page_prints_the_power_buttons_itself_and_a_card_keeps_the_generic_ones(): void {
		$r = $this->ready_vm();
		$offered = \BeltoftServices\Proxmox\Vm\Actions::offered( $r );
		$page = \BeltoftServices\Proxmox\Vm\Actions::buttons( $offered, $r, 'page' );
		$this->assertArrayNotHasKey( 'reboot', $page );
		$this->assertArrayNotHasKey( 'shutdown', $page );
		$card = \BeltoftServices\Proxmox\Vm\Actions::buttons( $offered, $r, 'card' );
		$this->assertArrayHasKey( 'reboot', $card );
	}

	public function test_a_card_outside_the_servers_tab_has_no_tabs(): void {
		$r = $this->ready_vm();
		wp_set_current_user( (int) $r->license()['user_id'] );
		ob_start();
		\BeltoftServices\Proxmox\Vm\Panels::account( $r );
		$html = (string) ob_get_clean();
		$this->assertStringNotContainsString( 'beltoft-services-tabs', $html );
		$this->assertStringNotContainsString( 'class="beltoft-services-tab"', $html );
		$this->assertStringContainsString( 'data-vm-metrics', $html );
		$this->assertStringContainsString( 'Disk used', preg_replace( '#\b30 GB\b#', 'Disk used', $html ) );
	}

	public function test_the_disk_is_a_bar_with_its_reading_cadence(): void {
		$r = $this->ready_vm();
		$r->set( array( 'disk_used' => 15 * 1073741824, 'disk_total' => 30 * 1073741824, 'disk_read_at' => time() ) );
		ob_start();
		\BeltoftServices\Proxmox\Vm\Panels::account( \BeltoftServices\Resource::find( $r->id() ) );
		$html = (string) ob_get_clean();
		$this->assertStringContainsString( 'beltoft-vm-usage__bar', $html );
		$this->assertStringContainsString( 'Read every 6 hours', $html );
		$this->assertStringContainsString( 'style="width:50%"', $html );
	}

	public function test_the_ipv6_address_shows_only_when_the_server_has_one_and_says_when_it_waits_for_a_restart(): void {
		$r = $this->ready_vm();
		$r->set( array( 'server_name' => 'web-01', 'ip' => '192.0.2.10' ) );
		Operations::remember_power( $r->id(), 'running' );

		$html = $this->page( $r, (string) $r->license()['id'] );
		$this->assertStringNotContainsString( 'IPv6', $html );

		$r->set( array( 'ip6' => '2001:DB8:430c:1:1388:0:0:1' ) );
		$html = $this->page( $r, (string) $r->license()['id'] );
		$this->assertStringContainsString( '<dt>IPv6 address</dt><dd><code>2001:db8:430c:1:1388::1</code> <button type="button" class="beltoft-services-copy" data-copy="2001:db8:430c:1:1388::1"', $html );
		$this->assertStringNotContainsString( 'Works after the next time', $html );
		$this->assertStringContainsString( 'data-copy="ssh root@192.0.2.10"', $html, 'The log in line stays IPv4.' );
	}

	public function test_a_junk_ip6_meta_is_not_shown(): void {
		$r = $this->ready_vm();
		$r->set( array( 'ip' => '192.0.2.10', 'ip6' => '<b>x</b>' ) );
		$this->assertSame( '', \BeltoftServices\Proxmox\Vm\Panels::ip6( \BeltoftServices\Resource::find( $r->id() ) ) );
		$this->assertSame( '', \BeltoftServices\Proxmox\Vm\McpDetails::vm( \BeltoftServices\Resource::find( $r->id() ) )['ip6'] );

		$r->set( array( 'ip6' => '2001:db8::5' ) );
		$this->assertSame( '2001:db8::5', \BeltoftServices\Proxmox\Vm\McpDetails::vm( \BeltoftServices\Resource::find( $r->id() ) )['ip6'] );
	}

	public function test_the_ready_email_carries_the_ipv6_address_line_only_when_set(): void {
		$r = $this->ready_vm();
		$r->set( array( 'ip' => '192.0.2.10' ) );
		$none = \BeltoftServices\Proxmox\Vm\EmailDetails::details( array(), 'beltoft_services_ready', \BeltoftServices\Resource::find( $r->id() ) );
		$this->assertArrayNotHasKey( 'IPv6 address', $none );
		$this->assertSame( '192.0.2.10', $none['IP address'] );

		$r->set( array( 'ip6' => '2001:db8::5' ) );
		$some = \BeltoftServices\Proxmox\Vm\EmailDetails::details( array(), 'beltoft_services_ready', \BeltoftServices\Resource::find( $r->id() ) );
		$this->assertSame( '2001:db8::5', $some['IPv6 address'] );
		$this->assertSame( array( 'IP address', 'IPv6 address' ), array_slice( array_keys( array_intersect_key( $some, array_flip( array( 'IP address', 'IPv6 address' ) ) ) ), 0, 2 ) );
	}
}

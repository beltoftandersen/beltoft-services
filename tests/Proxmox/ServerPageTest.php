<?php
/** The server page, its tabs and the servers list. @package BeltoftServices */
namespace BeltoftServices\Tests\Proxmox;

use BeltoftLicenses\Account\Endpoint;
use BeltoftLicenses\Account\View;
use BeltoftServices\Account\Servers;
use BeltoftServices\Proxmox\Operations;

require_once __DIR__ . '/MonitoringTestCase.php';

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
		$id = (int) $r->license()['id'];
		$this->assertMatchesRegularExpression( '#vps(/|=)' . $id . '/?$#', rawurldecode( Servers::url( $r->license() ) ) );
		$this->assertStringContainsString( $id . '/snapshots', rawurldecode( Servers::url( $r->license(), 'snapshots' ) ) );
		$this->assertSame( Servers::url( $r->license() ), Servers::url( $r->license(), 'nonsense' ) );
	}

	public function test_the_address_value_is_read_safely(): void {
		$this->route( '12/snapshots' );
		$this->assertSame( array( 'id' => 12, 'tab' => 'snapshots' ), Servers::requested() );
		$this->route( '12/<b>x' );
		$this->assertSame( array( 'id' => 12, 'tab' => '' ), Servers::requested() );
		$this->route( 'abc/snapshots' );
		$this->assertSame( 0, Servers::requested()['id'] );
		$this->route( '' );
		$this->assertSame( array( 'id' => 0, 'tab' => '' ), Servers::requested() );
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
		$this->assertStringContainsString( '>web-01</h2>', $html );
		$this->assertStringContainsString( 'data-copy="ssh root@192.0.2.10"', $html );
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
			$this->assertStringContainsString( (string) $r->license()['id'], rawurldecode( $href ) );
		}
		$this->assertMatchesRegularExpression( '/data-copy="[^"]+" data-copied="[^"]+" hidden/', $html );
		// The command is printed in the facts, so nothing is lost without script.
		$this->assertStringContainsString( '<code>ssh root@192.0.2.10</code>', $html );
	}

	public function test_the_panel_keeps_what_the_scripts_bind_to(): void {
		$r = $this->ready_vm();
		$html = $this->page( $r, (string) $r->license()['id'] );
		foreach ( array( 'data-resource="' . $r->id() . '"', 'data-flash', 'data-badge', 'data-message', 'data-notice', 'data-vm-metrics', 'data-range="hour"', 'data-confirm-dialog', 'data-progress' ) as $needle ) {
			$this->assertStringContainsString( $needle, $html, $needle );
		}
		// Every dialog is after the last pane, so a hidden tab never holds one.
		$last = strrpos( $html, '</section>' );
		$this->assertNotFalse( $last );
		$this->assertGreaterThan( $last, (int) strpos( $html, 'data-confirm-dialog' ) );
		$this->assertSame( 1, substr_count( $html, 'data-flash' ) );
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
		$this->assertStringContainsString( 'href="' . esc_url( Servers::url( $r->license() ) ) . '"', $html );
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
		$this->assertSame( array( 'CPU' => 25, 'Memory' => 25, 'Disk' => 50 ), array_column( $rows[0]['bars'], 'percent', 'label' ) );
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
		$this->assertSame( 5, substr_count( $html, '<i' ) );
		$this->assertSame( 2, substr_count( $html, 'is-used' ) );
		$this->assertStringContainsString( 'aria-hidden="true"', $html );
	}

	public function test_the_page_prints_the_power_buttons_itself_and_a_card_keeps_the_generic_ones(): void {
		$r = $this->ready_vm();
		$offered = \BeltoftServices\Proxmox\Actions::offered( $r );
		$page = \BeltoftServices\Proxmox\Actions::buttons( $offered, $r, 'page' );
		$this->assertArrayNotHasKey( 'reboot', $page );
		$this->assertArrayNotHasKey( 'shutdown', $page );
		$card = \BeltoftServices\Proxmox\Actions::buttons( $offered, $r, 'card' );
		$this->assertArrayHasKey( 'reboot', $card );
	}

	public function test_a_card_outside_the_servers_tab_has_no_tabs(): void {
		$r = $this->ready_vm();
		wp_set_current_user( (int) $r->license()['user_id'] );
		ob_start();
		\BeltoftServices\Proxmox\Panels::account( $r );
		$html = (string) ob_get_clean();
		$this->assertStringNotContainsString( 'beltoft-services-tabs', $html );
		$this->assertStringNotContainsString( 'class="beltoft-services-tab"', $html );
		$this->assertStringContainsString( 'data-vm-metrics', $html );
		$this->assertStringContainsString( 'Disk used', str_replace( 'Disk: 30 GB', 'Disk used', $html ) );
	}

	public function test_the_disk_is_a_bar_with_its_reading_cadence(): void {
		$r = $this->ready_vm();
		$r->set( array( 'disk_used' => 15 * 1073741824, 'disk_total' => 30 * 1073741824, 'disk_read_at' => time() ) );
		ob_start();
		\BeltoftServices\Proxmox\Panels::account( \BeltoftServices\Resource::find( $r->id() ) );
		$html = (string) ob_get_clean();
		$this->assertStringContainsString( 'beltoft-vm-usage__bar', $html );
		$this->assertStringContainsString( 'Read every 6 hours', $html );
		$this->assertStringContainsString( 'style="width:50%"', $html );
	}
}

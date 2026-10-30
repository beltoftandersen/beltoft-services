<?php
/** Customer console tab. @package BeltoftServices */

namespace BeltoftServices\Tests\Proxmox;

use BeltoftLicenses\Account\View;
use BeltoftServices\Account\Servers;
use BeltoftServices\Proxmox\Console;
use BeltoftServices\Proxmox\ConsoleTab;
use BeltoftServices\Proxmox\Operations;
use BeltoftServices\Resource;

require_once __DIR__ . '/MonitoringTestCase.php';

class ConsoleTabTest extends MonitoringTestCase {
	public function set_up(): void {
		parent::set_up();
		$this->settings( array( 'console' => 1 ) );
	}

	public function tear_down(): void {
		unset( $GLOBALS['wp']->query_vars['vps'] );
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	private function server( bool $running = true ): Resource {
		$r = $this->ready_vm();
		if ( $running ) { Operations::remember_power( $r->id(), 'running' ); }
		return $r;
	}

	private function page( Resource $r ): string {
		wp_set_current_user( (int) $r->license()['user_id'] );
		$GLOBALS['wp']->query_vars['vps'] = (string) $r->license()['id'];
		ob_start();
		Servers::render( array( View::license( $r->license() ) ) );
		return (string) ob_get_clean();
	}

	public function test_console_tab_is_available_only_for_enabled_running_server(): void {
		$r = $this->server();
		$this->assertTrue( Console::eligible( $r ) );
		$html = $this->page( $r );
		$this->assertMatchesRegularExpression( '#data-tab-link="overview"[^>]*>Overview</a>\s*<a[^>]*data-tab-link="console"[^>]*>Console</a>#', $html );
		$this->assertContains( 'console', Servers::tabs() );
		$this->settings( array( 'console' => 0 ) );
		$this->assertFalse( Console::eligible( $r ) );
		$this->assertStringNotContainsString( 'data-tab-link="console"', $this->page( $r ) );
	}

	public function test_stopped_server_has_no_console_tab_and_direct_pane_is_plain(): void {
		$r = $this->server( false );
		$this->assertStringNotContainsString( 'data-tab-link="console"', $this->page( $r ) );
		ob_start();
		ConsoleTab::pane( 'console', $r->id() );
		$html = (string) ob_get_clean();
		$this->assertStringContainsString( 'stopped or paused', $html );
	}

	public function test_assets_load_only_for_an_owned_server_endpoint(): void {
		$r = $this->server();
		wp_set_current_user( (int) $r->license()['user_id'] );
		$GLOBALS['wp']->query_vars['vps'] = (string) $r->license()['id'];
		ConsoleTab::assets( 'account', 'orders' );
		$this->assertFalse( wp_script_is( ConsoleTab::HANDLE, 'enqueued' ) );
		ConsoleTab::assets( 'account', Servers::slug() );
		$this->assertTrue( wp_script_is( ConsoleTab::HANDLE, 'enqueued' ) );
		$this->assertTrue( wp_style_is( ConsoleTab::HANDLE, 'enqueued' ) );
		wp_set_current_user( self::factory()->user->create() );
		wp_dequeue_script( ConsoleTab::HANDLE );
		wp_dequeue_style( ConsoleTab::HANDLE );
		ConsoleTab::assets( 'account', Servers::slug() );
		$this->assertFalse( wp_script_is( ConsoleTab::HANDLE, 'enqueued' ) );
	}

	public function test_customer_text_and_no_vendor_name(): void {
		$r = $this->server();
		$html = $this->page( $r );
		$this->assertStringContainsString( 'Open console', $html );
		$this->assertStringContainsString( 'same screen as a monitor', $html );
		$this->assertStringNotContainsString( 'Proxmox', $html );
		$this->assertStringNotContainsString( 'noVNC', $html );
		$this->assertStringNotContainsString( 'border-radius: 999', (string) file_get_contents( BELTOFT_SERVICES_PATH . 'assets/console.css' ) );
	}

	public function test_browser_script_uses_demo_and_noVnc_transport_without_external_host(): void {
		$js = (string) file_get_contents( BELTOFT_SERVICES_PATH . 'assets/console.js' );
		$this->assertStringContainsString( "data.transport === 'demo'", $js );
		$this->assertStringContainsString( 'import( cfg.novnc )', $js );
		$this->assertStringContainsString( 'sendCtrlAltDel()', $js );
		$this->assertStringNotContainsString( 'https://', $js );
	}
}

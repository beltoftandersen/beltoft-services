<?php
/** Customer console tab. @package BeltoftServices */

namespace BeltoftServices\Tests\Proxmox\Console;

use BeltoftLicenses\Account\View;
use BeltoftServices\Account\ServerLinks;
use BeltoftServices\Account\Servers;
use BeltoftServices\Proxmox\Console\Console;
use BeltoftServices\Proxmox\Console\ConsoleTab;
use BeltoftServices\Proxmox\Vm\Operations;
use BeltoftServices\Resource;
use BeltoftServices\Tests\Proxmox\Network\MonitoringTestCase;

require_once dirname( __DIR__ ) . '/Network/MonitoringTestCase.php';

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
		$this->assertContains( 'console', ServerLinks::tabs() );
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
		ConsoleTab::assets( 'account', ServerLinks::slug() );
		$this->assertTrue( wp_script_is( ConsoleTab::HANDLE, 'enqueued' ) );
		$this->assertTrue( wp_style_is( ConsoleTab::HANDLE, 'enqueued' ) );
		wp_set_current_user( self::factory()->user->create() );
		wp_dequeue_script( ConsoleTab::HANDLE );
		wp_dequeue_style( ConsoleTab::HANDLE );
		ConsoleTab::assets( 'account', ServerLinks::slug() );
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

	public function test_toolbar_buttons_use_the_action_style_and_status_goes_to_the_bar(): void {
		ob_start();
		ConsoleTab::pane( 'console', $this->server()->id() );
		$html = (string) ob_get_clean();
		foreach ( array( 'fullscreen', 'cad', 'keyboard', 'disconnect' ) as $key ) {
			$this->assertMatchesRegularExpression( '#<button type="button" class="beltoft-services-btn beltoft-services-console__action" data-console-' . $key . '>#', $html );
		}
		$this->assertStringNotContainsString( 'data-console-status', $html, 'The console reports in the shared status bar.' );
		$css = (string) file_get_contents( BELTOFT_SERVICES_PATH . 'assets/account.css' );
		$this->assertStringContainsString( '.beltoft-services-console__action', $css, 'The console buttons take the shared secondary button style.' );
		$this->assertStringNotContainsString( 'box-shadow: inset 0 0 0 1px', (string) file_get_contents( BELTOFT_SERVICES_PATH . 'assets/console.css' ), 'A real 1px border, not an inset shadow.' );
	}

	public function test_keyboard_field_is_visible_and_paste_types_the_clipboard(): void {
		ob_start();
		ConsoleTab::pane( 'console', $this->server()->id() );
		$html = (string) ob_get_clean();
		$this->assertMatchesRegularExpression( '#data-console-paste hidden>Paste</button>#', $html, 'Paste is shown by the script only where the clipboard can be read.' );
		$this->assertStringContainsString( 'placeholder="Type or paste text here, then press Enter"', $html );
		$css = (string) file_get_contents( BELTOFT_SERVICES_PATH . 'assets/console.css' );
		$this->assertStringNotContainsString( 'left: -10000px', $css, 'The keyboard field is not hidden off screen any more.' );
		$js = (string) file_get_contents( BELTOFT_SERVICES_PATH . 'assets/console.js' );
		$this->assertStringContainsString( 'navigator.clipboard.readText().then( function ( text ) { typed( typeText( text )', $js );
		// Capitals and shifted symbols are typed with Shift held, or a generated password never matches.
		$this->assertStringContainsString( "rfb.sendKey( 0xffe1, 'ShiftLeft', true )", $js );
		$this->assertStringContainsString( "rfb.sendKey( 0xffe1, 'ShiftLeft', false )", $js );
	}

	public function test_open_failure_is_mapped_by_route_code_and_busy_differs_from_stopped(): void {
		$js = (string) file_get_contents( BELTOFT_SERVICES_PATH . 'assets/console.js' );
		foreach ( array( 'beltoft_services_console_busy', 'beltoft_services_console_stopped', 'beltoft_services_console_unavailable', 'beltoft_services_rate_limited' ) as $code ) {
			$this->assertStringContainsString( $code, $js );
		}
		$this->assertStringNotContainsString( 'status === 409', $js );
		$r = $this->server();
		wp_set_current_user( (int) $r->license()['user_id'] );
		$GLOBALS['wp']->query_vars['vps'] = (string) $r->license()['id'];
		ConsoleTab::assets( 'account', ServerLinks::slug() );
		$data = wp_scripts()->get_data( ConsoleTab::HANDLE, 'data' );
		$this->assertStringContainsString( 'A console is already being opened', (string) $data );
	}
}

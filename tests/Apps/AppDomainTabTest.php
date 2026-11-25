<?php
/**
 * The Domain block of the App tab, its form and the words of the dialog, the MCP tool and the script wiring.
 * The flow behind it (AppDomainChange) is another builder's: these tests feed the template a view of their own.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Apps;

use BeltoftLicenses\Account\View;
use BeltoftServices\Apps\AppTab;
use BeltoftServices\Apps\McpTools;
use BeltoftServices\Proxmox\Pve\Clusters;
use BeltoftServices\Proxmox\Vm\OsField;
use BeltoftServices\Proxmox\Vm\Operations;
use BeltoftServices\Proxmox\Vm\Servers;
use BeltoftServices\Resource;
use BeltoftServices\Tests\Proxmox\Network\MonitoringTestCase;

require_once __DIR__ . '/../Proxmox/Network/MonitoringTestCase.php';

class AppDomainTabTest extends MonitoringTestCase {

	public function tear_down(): void {
		unset( $GLOBALS['wp']->query_vars['vps'] );
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	private static int $next = 5100;

	private function app( array $meta = array(), int $vmid = 0 ): Resource {
		$vmid = $vmid > 0 ? $vmid : self::$next++;
		$this->assertTrue( Clusters::save( 'lab', array( 'label' => 'Lab', 'api_url' => 'https://pve.example.invalid:8006', 'token_id' => 'svc@pve!beltoft', 'token_secret' => 'secret-secret-secret-1', 'client' => 'fake', 'nodes' => 'node-a', 'storage' => 'disk-store', 'cloudinit_storage' => 'ci-store', 'bridge' => 'vmbr9', 'gateway' => '192.0.2.1', 'nameservers' => '192.0.2.53', 'prefix' => '24', 'vmid_min' => '5000', 'vmid_max' => '5199', 'templates' => "9000 = Debian 12\n9001 = Ubuntu 24.04", 'vendor_snippet' => 'local:snippets/vm-vendor-cloudinit-v2.yaml', 'apps_ready' => 1, 'apps_enabled' => array( 'docker', 'wordpress', 'coolify' ), 'template_families_text' => "9001 = ubuntu-24.04\n9000 = debian-12" ) ) );
		$r = $this->ready_vm( $vmid );
		$r->set( array_merge( array( 'ip' => '192.0.2.10', 'app_id' => 'wordpress', 'app_version' => '7.1.4', 'app_state' => 'ready', 'app_step' => 'complete', OsField::META => 9001, 'plan' => array( 'cluster' => 'lab', 'template' => 9001, 'cores' => 2, 'memory_mb' => 4096, 'disk_gb' => 50 ) ), $meta ) );
		Operations::remember_power( $r->id(), 'running' );

		return Resource::find( $r->id() );
	}

	/**
	 * The pane with the Domain block as the flow would describe it.
	 *
	 * @param array<string, mixed> $change Keys of AppDomainChange::view().
	 * @param array<string, mixed> $meta   Resource meta.
	 */
	private function block( array $change, array $meta = array() ): string {
		$r    = $this->app( $meta );
		$view = AppTab::view( $r );

		$this->assertIsArray( $view );
		$view['change'] = array_merge( $view['change'], $change );
		ob_start();
		( static function ( array $view ): void {
			include BELTOFT_SERVICES_PATH . 'templates/account/app.php';
		} )( $view );
		$html = (string) ob_get_clean();

		return $html;
	}

	private function text( string $html ): string {
		return html_entity_decode( wp_strip_all_tags( preg_replace( '#<wbr>#', '', $html ) ), ENT_QUOTES );
	}

	// -- The view -------------------------------------------------------------------------------

	public function test_the_view_carries_the_change_for_an_app_with_domain_support_only(): void {
		$wp = AppTab::view( $this->app() );
		$this->assertTrue( $wp['domain_support'] );
		foreach ( array( 'supported', 'why', 'state', 'mode', 'name', 'reason', 'ip', 'deadline', 'zones', 'current' ) as $key ) {
			$this->assertArrayHasKey( $key, $wp['change'] );
		}

		$coolify = AppTab::view( $this->app( array( 'app_id' => 'coolify', 'app_version' => '4.5.1' ), 5101 ) );
		$this->assertFalse( $coolify['domain_support'] );
		$this->assertFalse( $coolify['change']['supported'] );
	}

	// -- The block ------------------------------------------------------------------------------

	public function test_the_block_offers_the_action_for_a_ready_app_with_domain_support_only(): void {
		$html = $this->block( array( 'supported' => true, 'zones' => array( 'mine.example' ) ) );
		$this->assertStringContainsString( 'data-app-domain-block', $html );
		$this->assertStringContainsString( 'data-app-domain-open', $html );
		$this->assertStringContainsString( 'Add a domain', $html );
		$this->assertStringContainsString( 'data-action="app_domain"', $html );

		$with = $this->block( array( 'supported' => true, 'current' => 'shop.mine.example', 'zones' => array( 'mine.example' ) ), array( 'app_domain' => 'shop.mine.example' ) );
		$this->assertStringContainsString( 'Change domain', $with );
		$this->assertStringContainsString( 'shop.<wbr>mine.<wbr>example', $with, 'A name wraps only after a dot.' );

		$not = $this->block( array( 'supported' => false, 'why' => 'The server is not running.' ) );
		$this->assertStringContainsString( 'The server is not running.', $not );
		$this->assertStringNotContainsString( 'data-app-domain-open', $not );
		$this->assertStringNotContainsString( 'data-app-domain-form', $not );

		$coolify = $this->block( array( 'supported' => true ), array( 'app_id' => 'coolify', 'app_version' => '4.5.1' ) );
		$this->assertStringNotContainsString( 'data-app-domain-block', $coolify );

		$failed = $this->block( array( 'supported' => true ), array( 'app_state' => 'failed', 'app_step' => 'download' ) );
		$this->assertStringNotContainsString( 'data-app-domain-block', $failed );
	}

	public function test_the_form_has_the_two_choices_and_the_dialog_text_names_the_consequences(): void {
		$html = $this->block( array( 'supported' => true, 'zones' => array( 'mine.example', 'other.example' ), 'ip' => '192.0.2.10' ) );

		$this->assertStringContainsString( 'name="app_domain_mode" value="change" checked', $html );
		$this->assertStringContainsString( 'name="app_domain_mode" value="own"', $html );
		$this->assertStringContainsString( 'name="app_zone"', $html );
		$this->assertStringContainsString( 'name="app_sub"', $html );
		$this->assertStringContainsString( 'name="app_own"', $html );
		$this->assertStringNotContainsString( 'value="none"', $html, 'No domain is not offered on a running app.' );
		$this->assertStringNotContainsString( 'value="keep"', $html );
		$this->assertStringNotContainsString( 'name="confirm"', $html, 'Nothing is erased, so no word is typed.' );
		$this->assertStringContainsString( 'data-confirm-source', $html );
		$this->assertStringContainsString( 'data-for="own"', $html );
		$this->assertStringContainsString( 'data-for="change"', $html );

		$text = $this->text( $html );
		$this->assertStringContainsString( 'keeps answering on the current address until the new one works', $text );
		$this->assertStringContainsString( 'the current address stops working', $text );
		$this->assertStringContainsString( 'Links saved elsewhere or inside the app', $text );
		$this->assertStringContainsString( 'Update them yourself', $text );
		$this->assertStringContainsString( 'We change no DNS records. Add an A record that points the name to 192.0.2.10.', $text );
		$this->assertStringContainsString( 'up to 14 days', $text );

		$this->assertDoesNotMatchRegularExpression( '/\x{2014}|\x{00B7}|\x{2013}/u', $html, 'No em dash, en dash or middle dot.' );
		$this->assertDoesNotMatchRegularExpression( '/\b(guest|agent|caddy|recipe)\b/i', $text );
	}

	public function test_without_a_zone_of_ours_only_the_own_domain_is_offered(): void {
		$html = $this->block( array( 'supported' => true, 'zones' => array() ) );
		$this->assertStringNotContainsString( 'value="change"', $html );
		$this->assertStringContainsString( 'name="app_domain_mode" value="own" checked', $html );
		$this->assertStringNotContainsString( 'data-app-domain-own-fields hidden', $html );
	}

	public function test_an_own_name_that_waits_shows_the_record_to_add_and_the_date(): void {
		$until = strtotime( '2026-12-09 12:00:00 UTC' );
		$html  = $this->block( array( 'supported' => false, 'state' => 'waiting', 'mode' => 'own', 'name' => 'shop.mine.example', 'reason' => 'none', 'ip' => '192.0.2.10', 'deadline' => wp_date( get_option( 'date_format' ), $until ) ) );
		$text  = $this->text( $html );

		$this->assertStringContainsString( 'data-app-domain-state="waiting"', $html );
		$this->assertStringContainsString( 'Waiting for your DNS record', $text );
		$this->assertStringContainsString( 'Point an A record for shop.mine.example to 192.0.2.10.', $text );
		$this->assertStringContainsString( 'The name has no A record yet.', $text );
		$this->assertStringContainsString( 'until December 9, 2026', $text );
		$this->assertStringContainsString( 'Until then nothing changes and the app keeps answering on the current address.', $text );
		$this->assertStringNotContainsString( 'data-app-domain-open', $html, 'No second change while one waits.' );

		$www = $this->block( array( 'state' => 'waiting', 'mode' => 'own', 'name' => 'mine.example', 'www' => 'www.mine.example', 'reason' => 'other', 'ip' => '192.0.2.10', 'deadline' => 'December 9, 2026' ) );
		$this->assertStringContainsString( 'Point A records for mine.example and www.mine.example to 192.0.2.10.', $this->text( $www ) );
	}

	public function test_a_change_that_runs_says_the_old_address_keeps_answering(): void {
		$html = $this->block( array( 'state' => 'applying', 'mode' => 'change', 'name' => 'app.mine.example', 'current' => 'old.mine.example' ), array( 'app_domain' => 'old.mine.example' ) );
		$text = $this->text( $html );

		$this->assertStringContainsString( 'data-app-domain-state="applying"', $html );
		$this->assertStringContainsString( 'Changing the address to app.mine.example', $text );
		$this->assertStringContainsString( 'keeps answering on the current address until the new one works', $text );
		$this->assertStringNotContainsString( 'data-app-domain-open', $html );
	}

	public function test_a_failed_change_says_why_and_that_the_old_address_works(): void {
		$html = $this->block( array( 'supported' => true, 'state' => 'failed', 'mode' => 'change', 'name' => 'app.mine.example', 'reason' => 'certificate', 'current' => 'old.mine.example', 'zones' => array( 'mine.example' ) ), array( 'app_domain' => 'old.mine.example' ) );
		$text = $this->text( $html );

		$this->assertStringContainsString( 'is-bad', $html );
		$this->assertStringContainsString( 'The address was not changed to app.mine.example', $text );
		$this->assertStringContainsString( 'A certificate could not be issued for the name.', $text );
		$this->assertStringContainsString( 'The app still answers on the current address. You can try again.', $text );
		$this->assertStringContainsString( 'data-app-domain-open', $html, 'The customer can try again.' );
		$this->assertDoesNotMatchRegularExpression( '/\b(guest|agent|caddy|recipe)\b/i', $text );

		foreach ( array( 'timeout', 'agent', 'busy', 'caddy', 'app', 'probe', 'other' ) as $reason ) {
			$one = $this->text( $this->block( array( 'state' => 'failed', 'name' => 'app.mine.example', 'reason' => $reason ) ) );
			$this->assertStringContainsString( 'You can try again.', $one, $reason );
			$this->assertDoesNotMatchRegularExpression( '/\b(guest|agent|caddy|recipe)\b/i', $one, $reason );
		}
	}

	public function test_a_done_change_says_that_stored_links_are_not_changed(): void {
		$html = $this->block( array( 'supported' => true, 'current' => 'app.mine.example', 'zones' => array() ), array( 'app_domain' => 'app.mine.example' ) );
		$this->assertStringNotContainsString( 'data-app-domain-done', $html, 'Nothing was changed lately.' );

		$r = $this->app( array( 'app_domain' => 'app.mine.example', 'app_domain_changed_at' => time() - 3600 ), 5110 );
		$view = AppTab::view( $r );
		$this->assertNotSame( '', $view['change']['changed'] );
		$view['change']['supported'] = true;
		ob_start();
		( static function ( array $view ): void {
			include BELTOFT_SERVICES_PATH . 'templates/account/app.php';
		} )( $view );
		$done = $this->text( (string) ob_get_clean() );
		$this->assertStringContainsString( 'The address was changed on', $done );
		$this->assertStringContainsString( 'still name the old address. Update them yourself.', $done );
	}

	// -- Scripts and the tool -------------------------------------------------------------------

	public function test_the_scripts_know_the_action_and_the_panel_has_its_words(): void {
		$vm  = (string) file_get_contents( BELTOFT_SERVICES_PATH . 'assets/vm.js' );
		$tab = (string) file_get_contents( BELTOFT_SERVICES_PATH . 'assets/app-tab.js' );
		$this->assertStringContainsString( "case 'app_domain':", $vm );
		$this->assertStringContainsString( "'app_domain' === action", $vm );
		$this->assertStringContainsString( 'data-for', $vm );
		$this->assertStringContainsString( 'data-app-domain-open', $tab );
		$this->assertStringContainsString( 'data-app-domain-close', $tab );

		$panels = (string) file_get_contents( BELTOFT_SERVICES_PATH . 'src/Proxmox/Vm/Panels.php' );
		foreach ( array( 'domainStart', 'domainDone' ) as $key ) {
			$this->assertStringContainsString( "'" . $key . "'", $panels );
			$this->assertStringContainsString( $key, $vm );
		}
	}

	public function test_the_tool_offers_the_two_choices_only_and_sends_the_form_names(): void {
		$tools = McpTools::tools();
		$this->assertArrayHasKey( 'change_app_domain', $tools );
		$this->assertSame( array( 'change', 'own' ), $tools['change_app_domain']['schema']['properties']['mode']['enum'] );
		$this->assertSame( array( 'id', 'mode' ), $tools['change_app_domain']['schema']['required'] );
		$this->assertSame( 'change_app_domain', McpTools::actions()['app_domain'] );
		$this->assertStringNotContainsString( "\u{2014}", $tools['change_app_domain']['description'] );
	}
}

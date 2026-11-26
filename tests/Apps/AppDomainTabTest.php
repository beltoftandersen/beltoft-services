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
		$this->assertStringContainsString( '<span class="beltoft-services-app-domain__label">shop</span>.<wbr><span class="beltoft-services-app-domain__label">mine</span>.<wbr><span class="beltoft-services-app-domain__label">example</span>', $with, 'A name wraps only after a dot.' );
		$hyphen = $this->block( array( 'supported' => true, 'current' => 'my-shop.mine.example', 'zones' => array() ), array( 'app_domain' => 'my-shop.mine.example' ) );
		$this->assertStringContainsString( '<span class="beltoft-services-app-domain__label">my-shop</span>.<wbr>', $hyphen, 'A hyphen stays inside one unbreakable label.' );
		$evil = $this->block( array( 'supported' => true, 'current' => '"><b>x</b>.example', 'zones' => array() ), array( 'app_domain' => 'x.example' ) );
		$this->assertStringNotContainsString( '<b>x</b>', $evil, 'The name is escaped.' );

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

		$own_first = 'The app keeps answering on the current address until the new one works. Then the app switches to the new address, and the current address stops working.';
		$this->assertMatchesRegularExpression( '#<p data-for="own">' . preg_quote( $own_first, '#' ) . '</p>#', $html, 'An own domain: no minute in the first paragraph.' );
		$this->assertMatchesRegularExpression( '#<p data-for="change">[^<]*This can take up to a minute#', $html );

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
		$this->assertStringContainsString( 'A certificate could not be issued for the name. Try again in a few minutes. If it fails again, contact support.', $text, 'A name in one of our zones: we wrote the A record, so no advice about records.' );
		$this->assertStringNotContainsString( 'CAA', $text );
		$this->assertStringContainsString( 'The app still answers on the current address.', $text );
		$this->assertStringContainsString( 'data-app-domain-open', $html, 'The customer can try again.' );
		$own = $this->text( $this->block( array( 'supported' => true, 'state' => 'failed', 'mode' => 'own', 'name' => 'shop.example.org', 'reason' => 'certificate', 'current' => 'old.mine.example', 'zones' => array() ), array( 'app_domain' => 'old.mine.example' ) ) );
		$this->assertStringContainsString( 'Check that its A record points to this server and that no CAA record blocks it.', $own );
		$this->assertStringContainsString( 'The app still answers on the current address. You can try again.', $own );
		$late = $this->text( $this->block( array( 'supported' => true, 'state' => 'failed', 'mode' => 'own', 'name' => 'shop.example.org', 'reason' => 'late', 'current' => 'old.mine.example', 'zones' => array() ), array( 'app_domain' => 'old.mine.example' ) ) );
		$this->assertStringContainsString( 'The change did not finish in time, so everything was put back.', $late );
		$this->assertStringNotContainsString( 'The server did not answer', $late );
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

	// -- Cancel button, style rules and the script ---------------------------------------------------

	public function test_a_waiting_change_offers_cancel_the_change_as_a_form_of_the_action_plumbing(): void {
		$html = $this->block( array( 'state' => 'waiting', 'mode' => 'own', 'name' => 'shop.example.org', 'ip' => '192.0.2.10', 'deadline' => 'December 9, 2026' ) );
		$this->assertMatchesRegularExpression( '#<form class="beltoft-services-vm-form beltoft-services-app-domain__cancel" data-action="app_domain_cancel"#', $html );
		$this->assertStringContainsString( 'Cancel the change', $html );

		foreach ( array( 'applying', 'failed', '' ) as $state ) {
			$other = $this->block( array( 'supported' => true, 'state' => $state, 'mode' => 'change', 'name' => 'app.mine.example' ) );
			$this->assertStringNotContainsString( 'app_domain_cancel', $other, 'state ' . $state );
		}
	}

	public function test_the_script_knows_the_cancel_action_and_builds_the_name_from_nodes(): void {
		$vm  = (string) file_get_contents( BELTOFT_SERVICES_PATH . 'assets/vm.js' );
		$tab = (string) file_get_contents( BELTOFT_SERVICES_PATH . 'assets/app-tab.js' );
		$this->assertStringContainsString( "case 'app_domain_cancel':", $vm );
		$this->assertStringContainsString( 'function appendDomain( parent, name )', $vm );
		$this->assertStringContainsString( "document.createElement( 'wbr' )", $vm );
		$this->assertStringContainsString( "piece.className = 'beltoft-services-app-domain__label';", $vm );
		$this->assertStringContainsString( 'piece.textContent = label;', $vm );
		$this->assertStringContainsString( 'domain: true,', $vm );
		$this->assertStringNotContainsString( 'innerHTML', substr( $vm, (int) strpos( $vm, 'function appendDomain' ), 700 ) );
		$this->assertStringContainsString( "'failed' === block.getAttribute( 'data-app-domain-state' )", $tab, 'Opening the form clears the failure message.' );
	}

	/** The selector blocks of account.css that carry a Domain block rule. @return array<int, array{0: string, 1: string}> selector, declarations */
	private function css_rules(): array {
		$css = (string) file_get_contents( BELTOFT_SERVICES_PATH . 'assets/account.css' );
		$css = (string) preg_replace( '#/\*.*?\*/#s', '', $css );
		preg_match_all( '#([^{}]+)\{([^{}]*)\}#', $css, $m, PREG_SET_ORDER );
		$out = array();
		foreach ( $m as $one ) {
			$out[] = array( trim( (string) preg_replace( '#\s+#', ' ', $one[1] ) ), trim( $one[2] ) );
		}
		return $out;
	}

	private function rule( string $needle, string $must = '' ): string {
		foreach ( $this->css_rules() as list( $selector, $body ) ) {
			if ( substr( $selector, -strlen( $needle ) ) === $needle && ( '' === $must || false !== strpos( $body, $must ) ) ) {
				return $selector . ' { ' . $body . ' }';
			}
		}
		return '';
	}

	public function test_the_hidden_form_and_button_of_the_domain_block_are_not_displayed(): void {
		// The flat form of the tab sets display grid at 0,4,3; the hidden rule of the block has to be stronger.
		$this->assertNotSame( '', $this->rule( 'section.beltoft-services-tab form.beltoft-services-vm-form', 'display: grid' ) );
		$hidden = $this->rule( 'form.beltoft-services-vm-form.beltoft-services-app-domain__form[hidden]', 'display: none' );
		$this->assertNotSame( '', $hidden, 'The hidden form has a rule that beats the flat form.' );
		$this->assertStringContainsString( 'section.beltoft-services-tab', $hidden );
		$this->assertNotSame( '', $this->rule( '.beltoft-services-app-domain [hidden]', 'display: none' ), 'The hidden row button is not displayed (.bs-btn sets inline-flex).' );
		$this->assertNotSame( '', $this->rule( 'section.beltoft-services-tab form.beltoft-services-vm-form.beltoft-services-app-domain__form', 'margin: var(--bs-space-4) 0 0' ), 'The shown form keeps its top margin.' );
		$this->assertStringNotContainsString( '!important', $hidden );
	}

	public function test_the_action_paragraph_of_the_domain_row_has_no_extra_margin(): void {
		$this->assertNotSame( '', $this->rule( ':not(.beltoft-services-app-domain__action)', 'margin-top: var(--bs-space-3)' ), 'The price-line rule leaves the action paragraph of the Domain row alone.' );
		$this->assertNotSame( '', $this->rule( '.beltoft-services-app-domain__row > p', 'margin: 0' ) );
		$this->assertNotSame( '', $this->rule( '.beltoft-services-app-domain__row', 'row-gap: var(--bs-space-2)' ), 'On a phone the stacked parts are 8px apart.' );
	}

	public function test_a_domain_label_never_breaks(): void {
		$this->assertNotSame( '', $this->rule( '.beltoft-services-app-domain__label', 'white-space: nowrap' ) );
	}

	public function test_the_buttons_of_the_domain_block_are_32px_on_desktop_and_40px_on_phones(): void {
		$this->assertNotSame( '', $this->rule( 'section.beltoft-services-tab .beltoft-services-app-domain .bs-btn--sm', 'min-height: var(--bs-btn-h-sm)' ) );
		$css   = (string) file_get_contents( BELTOFT_SERVICES_PATH . 'assets/account.css' );
		$media = (string) substr( $css, (int) strpos( $css, '@media (max-width: 600px) {', (int) strpos( $css, '.beltoft-services-app-domain__buttons {' ) ) );
		$media = substr( $media, 0, (int) strpos( $media, "\n}\n" ) );
		$this->assertStringContainsString( '.beltoft-services-app-domain .bs-btn--sm { min-height: var(--bs-btn-h); }', $media );
	}

	public function test_the_form_buttons_have_no_block_padding_and_the_fields_start_on_the_edge_of_the_choice(): void {
		$this->assertNotSame( '', $this->rule( 'section.beltoft-services-tab .beltoft-services-app-domain .bs-btn--sm', 'padding-block: 0' ), 'Block padding made the 32px buttons 40px.' );
		$this->assertNotSame( '', $this->rule( '.beltoft-services-app-domain__fields', 'margin: 0 0 var(--bs-space-4);' ), 'The fields are not pushed in.' );
		$this->assertNotSame( '', $this->rule( '.beltoft-services-app-domain__choice .description', 'margin: var(--bs-space-1) 0 0;' ) );
	}

	public function test_a_slow_change_and_a_change_that_could_not_be_put_back_say_so_plainly(): void {
		$slow = $this->text( $this->block( array( 'supported' => true, 'state' => 'applying', 'mode' => 'change', 'name' => 'shop.example.org', 'reason' => 'slow', 'current' => 'old.mine.example', 'zones' => array() ), array( 'app_domain' => 'old.mine.example' ) ) );
		$this->assertStringContainsString( 'The change is taking longer than expected. We have been told and are looking at it.', $slow );
		$this->assertStringNotContainsString( 'put back', $slow );

		$bad = $this->text( $this->block( array( 'supported' => true, 'state' => 'failed', 'mode' => 'change', 'name' => 'shop.example.org', 'reason' => 'rollback', 'current' => 'old.mine.example', 'zones' => array() ), array( 'app_domain' => 'old.mine.example' ) ) );
		$this->assertStringContainsString( 'The change failed and could not be put back. We are looking at it.', $bad );
		$this->assertStringNotContainsString( 'still answers on the current address', $bad );
	}
}

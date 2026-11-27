<?php
/**
 * The app as part of a server's progress and status words.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Apps;

use BeltoftLicenses\Account\View;
use BeltoftServices\Account\Overview;
use BeltoftServices\Account\Panel;
use BeltoftServices\Proxmox\Vm\Servers;
use BeltoftServices\Progress;
use BeltoftServices\Proxmox\Pve\Clusters;
use BeltoftServices\Proxmox\Vm\Operations;
use BeltoftServices\Proxmox\Vm\OsField;
use BeltoftServices\Resource;
use BeltoftServices\Tests\Proxmox\Network\MonitoringTestCase;
use BeltoftServices\ThankYou;

require_once __DIR__ . '/../Proxmox/Network/MonitoringTestCase.php';

class AppProgressTest extends MonitoringTestCase {

	public function set_up(): void {
		parent::set_up();
		$this->assertTrue( Clusters::save( 'lab', array(
			'label' => 'Lab', 'api_url' => 'https://pve.example.invalid:8006', 'token_id' => 'svc@pve!beltoft', 'token_secret' => 'secret-secret-secret-1',
			'client' => 'fake', 'nodes' => 'node-a', 'storage' => 'disk-store', 'cloudinit_storage' => 'ci-store', 'bridge' => 'vmbr9',
			'gateway' => '192.0.2.1', 'nameservers' => '192.0.2.53', 'prefix' => '24', 'vmid_min' => '5000', 'vmid_max' => '5099',
			'templates' => "9000 = Debian 12\n9001 = Ubuntu 24.04", 'vendor_snippet' => 'local:snippets/vm-vendor-cloudinit-v2.yaml',
			'apps_ready' => 1, 'apps_enabled' => array( 'docker', 'wordpress' ), 'template_families_text' => "9001 = ubuntu-24.04\n9000 = debian-12",
		) ) );
	}

	public function tear_down(): void {
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	private function server( array $meta, int $vmid = 5000 ): Resource {
		$r = $this->ready_vm( $vmid );
		$r->set( array_merge( array( 'ip' => '192.0.2.10', 'app_id' => 'wordpress', 'app_version' => '7.1.4', OsField::META => 9001, 'steps' => array( 'ip', 'vmid', 'clone', 'configure', 'start', 'ready' ) ), $meta ) );
		Operations::remember_power( $r->id(), 'running' );

		return Resource::find( $r->id() );
	}

	private function statuses( array $progress ): array {
		return array_column( $progress['steps'], 'status', 'key' );
	}

	public function test_an_installing_app_is_part_of_the_progress_and_nothing_says_ready(): void {
		$r = $this->server( array( 'app_state' => 'installing', 'app_step' => 'database' ) );
		$p = Progress::build( $r );

		$this->assertSame( 'app', $p['kind'] );
		$this->assertTrue( $p['active'] );
		$this->assertSame( 'Installing WordPress', $p['title'] );
		$this->assertStringStartsWith( 'Installing WordPress: Setting up the database', $p['text'] );
		$st = $this->statuses( $p );
		$this->assertSame( 'done', $st['ready'], 'The server steps are done.' );
		$this->assertSame( 'done', $st['app_download'] );
		$this->assertSame( 'current', $st['app_database'] );
		$this->assertSame( 'todo', $st['app_firewall'] );
		$this->assertSame( 'database', $p['current'] === 'app_database' ? 'database' : '' );

		$this->assertSame( 'getting-ready', Panel::group( 'ready', $r ) );
		$this->assertSame( 'Installing WordPress: Setting up the database', Panel::label( 'ready', $r ) );
		$this->assertStringNotContainsString( 'ready', strtolower( Panel::message( 'ready', $r ) ) );
	}

	public function test_a_pending_app_shows_the_first_step_and_the_surfaces_never_say_ready(): void {
		$r = $this->server( array( 'app_state' => 'pending' ) );
		$this->assertSame( 'Installing WordPress: Downloading the installer', Panel::label( 'ready', $r ) );
		$this->assertTrue( Progress::build( $r )['active'] );

		// The Servers list row (also the Overview's) and the thank-you row.
		$view = View::license( $r->license() );
		$row  = Servers::rows( array( $view ), array( (int) $view['id'] => $r ) )[0];
		$this->assertSame( 'Installing WordPress: Downloading the installer', $row['state'] );
		$this->assertSame( 'busy', $row['tone'] );
		$this->assertSame( array(), $row['bars'] );

		$thanks = ThankYou::row( $r );
		$this->assertFalse( $thanks['ready'], 'No "Open your server" as if it were done.' );
		$this->assertSame( 'getting-ready', $thanks['group'] );
		$this->assertSame( 'Installing WordPress: Downloading the installer', $thanks['label'] );
		$this->assertTrue( $thanks['progress']['active'] );
	}

	/** Owner 2026-11-27: an install that waits for the customer's own domain said "Downloading the installer"; it must say what to do. */
	/** A look made before the addresses were stored (reason other, no seen list) must not claim the name has no A record. */
	public function test_an_old_look_without_stored_addresses_does_not_say_no_a_record(): void {
		$r   = $this->server( array( 'app_state' => 'pending', 'app_domain_own' => 'example.dk', 'app_domain_dns' => 'waiting', 'app_domain_dns_reason' => 'other', 'app_domain_dns_since' => time(), 'app_domain_dns_checked' => time() ) );
		$box = \BeltoftServices\Apps\AppProgress::dns_view( $r );
		$this->assertStringNotContainsString( 'has no A record', (string) $box['now'] );
		$this->assertStringContainsString( 'does not point to this server only', (string) $box['now'] );
	}

	/** Owner 2026-11-27: the overview banner said "Working / Follow it" while the install waited for the customer. */
	public function test_the_overview_banner_says_it_waits_for_the_customer_during_a_dns_wait(): void {
		$r = $this->server( array( 'app_state' => 'pending', 'app_domain_own' => 'example.dk', 'app_domain_dns' => 'waiting', 'app_domain_dns_reason' => 'other', 'app_domain_dns_since' => time() ) );
		wp_set_current_user( (int) $r->license()['user_id'] );
		\BeltoftServices\Account\OverviewData::reset();
		$items = array_values( array_filter( \BeltoftServices\Account\OverviewData::data()['attention'], static fn( $i ) => (int) ( $i['license'] ?? 0 ) === (int) $r->license()['id'] ) );

		$this->assertCount( 1, $items );
		$this->assertSame( 'Waiting for you', $items[0]['state'] );
		$this->assertSame( 'See how', $items[0]['label'] );
		$this->assertSame( 'Point example.dk to 192.0.2.10', $items[0]['text'] );
	}

	public function test_an_install_waiting_for_the_customers_own_domain_says_what_to_do_everywhere(): void {
		$r = $this->server( array( 'app_state' => 'pending', 'app_domain_own' => 'example.dk', 'app_domain_dns' => 'waiting', 'app_domain_dns_reason' => 'other', 'app_domain_dns_since' => time() ) );
		$p = Progress::build( $r );

		$this->assertTrue( $p['active'] );
		$this->assertSame( 'Point your domain at this server', $p['title'] );
		$this->assertSame( '', $p['text'], 'The box says it all: the same words are not shown twice.' );
		$this->assertStringContainsString( 'example.dk', $p['box'] );
		$this->assertStringContainsString( '192.0.2.10', $p['box'], 'The address to point the record at.' );
		$this->assertStringNotContainsString( 'Downloading the installer', $p['box'] );
		$st = $this->statuses( $p );
		$this->assertSame( 'current', $st['app_dns'], 'The DNS wait is the step in progress.' );
		$this->assertSame( 'todo', $st['app_download'], 'Nothing is downloading.' );

		$this->assertSame( 'Point example.dk to 192.0.2.10', Panel::label( 'ready', $r ), 'The short line of every list says what to do.' );
		$this->assertSame( 'WordPress is waiting for your domain. Follow the steps below.', Panel::message( 'ready', $r ), 'One short sentence under the status.' );
		$this->assertSame( 'Point example.dk to 192.0.2.10', ThankYou::row( $r )['label'] );
		$view = View::license( $r->license() );
		$this->assertSame( 'Point example.dk to 192.0.2.10', Servers::rows( array( $view ), array( (int) $view['id'] => $r ) )[0]['state'] );
	}

	private function box( array $meta, int $vmid = 5000 ): string {
		return Progress::build( $this->server( array_merge( array( 'app_state' => 'pending', 'app_domain_own' => 'example.dk', 'app_domain_dns' => 'waiting', 'app_domain_dns_since' => time() ), $meta ), $vmid ) )['box'];
	}

	/** Owner 2026-11-27: one box with the steps, the records to make and what DNS says now, not a paragraph twice. */
	public function test_the_dns_box_lists_steps_records_what_dns_says_and_what_happens_next(): void {
		$checked = (int) strtotime( 'today 09:41' );
		$html    = $this->box( array( 'app_domain_dns_reason' => 'other', 'app_domain_dns_seen' => array( '192.0.2.99' ), 'app_domain_dns_checked' => $checked, 'app_domain_dns_since' => $checked ) );

		$this->assertStringContainsString( 'Point example.dk to your server', $html );
		$this->assertStringContainsString( 'Missing', $html );
		$this->assertStringContainsString( 'WordPress is installed as soon as your domain points to the server.', $html );
		$this->assertStringContainsString( 'Log in to your DNS provider (where example.dk is set up).', $html );
		$this->assertSame( 2, substr_count( $html, 'data-copy="192.0.2.10"' ), 'A copy button for each record.' );
		$this->assertStringContainsString( '<code>example.dk (@)</code>', $html );
		$this->assertStringContainsString( '<code>www</code>', $html, 'The www record.' );
		$this->assertStringContainsString( 'Delete any other A records for these names.', $html );
		$this->assertStringNotContainsString( 'Delete the AAAA record.', $html );
		$this->assertStringContainsString( 'Using Cloudflare?', $html );
		$this->assertStringContainsString( 'Right now example.dk points to 192.0.2.99.', $html );
		$this->assertStringContainsString( 'Last checked at ' . wp_date( get_option( 'time_format' ), $checked ), $html );
		$this->assertStringContainsString( 'We check every 10 minutes and start the installation ourselves.', $html );
		$this->assertStringContainsString( 'If this has not happened by ' . wp_date( get_option( 'date_format' ), $checked + 14 * DAY_IN_SECONDS ) . ', WordPress is installed on the server address instead.', $html );
		$this->assertStringNotContainsString( "\u{2014}", $html );
		$this->assertStringNotContainsString( "\u{00B7}", $html );
	}

	public function test_the_dns_box_says_no_record_nothing_looked_and_the_aaaa_step(): void {
		$this->assertStringContainsString( 'We have not looked yet.', $this->box( array() ) );
		$this->assertStringNotContainsString( 'Last checked', $this->box( array(), 5001 ) );

		$none = $this->box( array( 'app_domain_dns_reason' => 'none', 'app_domain_dns_seen' => array(), 'app_domain_dns_checked' => time() ), 5002 );
		$this->assertStringContainsString( 'Right now example.dk has no A record.', $none );

		$aaaa = $this->box( array( 'app_domain_dns_reason' => 'aaaa', 'app_domain_dns_seen' => array( '192.0.2.10' ), 'app_domain_dns_checked' => time() ), 5003 );
		$this->assertStringContainsString( 'Delete the AAAA record.', $aaaa );
	}

	public function test_the_dns_box_has_no_www_row_for_a_subdomain_and_other_work_has_no_box(): void {
		$sub = Progress::build( $this->server( array( 'app_state' => 'pending', 'app_domain_own' => 'shop.example.dk', 'app_domain_dns' => 'waiting', 'app_domain_dns_since' => time() ), 5004 ) )['box'];
		$this->assertSame( 1, substr_count( $sub, 'data-copy=' ) );
		$this->assertStringNotContainsString( '<code>www</code>', $sub );

		$this->assertArrayNotHasKey( 'box', Progress::build( $this->server( array( 'app_state' => 'installing', 'app_step' => 'database' ), 5005 ) ) );
	}

	public function test_the_progress_block_prints_the_box_outside_the_text_and_the_steps_stay(): void {
		$r = $this->server( array( 'app_state' => 'pending', 'app_domain_own' => 'example.dk', 'app_domain_dns' => 'waiting', 'app_domain_dns_since' => time() ), 5006 );
		ob_start();
		Progress::render( Progress::build( $r ) );
		$html = (string) ob_get_clean();

		$this->assertMatchesRegularExpression( '#data-progress-text></p>#', $html, 'The progress sentence is empty for this state.' );
		$this->assertMatchesRegularExpression( '#<div class="beltoft-services-progress__box" data-progress-box><section class="beltoft-services-dnsbox"#', $html );
		$this->assertSame( 1, substr_count( $html, 'Point example.dk to your server' ), 'Said once.' );
		$this->assertStringContainsString( 'Waiting for your DNS record', $html, 'The step stays in the list.' );
	}

	public function test_the_server_card_says_installing_not_running_and_not_ready(): void {
		$r = $this->server( array( 'app_state' => 'installing', 'app_step' => 'packages' ) );
		wp_set_current_user( (int) $r->license()['user_id'] );
		$GLOBALS['wp']->query_vars['vps'] = (string) $r->license()['id'];
		ob_start();
		Servers::render( array( View::license( $r->license() ) ) );
		$html = (string) ob_get_clean();
		unset( $GLOBALS['wp']->query_vars['vps'] );

		$this->assertMatchesRegularExpression( '#data-badge>Installing WordPress: Installing packages</span>#', $html );
		$this->assertStringNotContainsString( 'data-power-line', $html );
		$this->assertDoesNotMatchRegularExpression( '#data-badge[^>]*>Ready<#', $html );
		$this->assertDoesNotMatchRegularExpression( '#data-message hidden#', $html );
		$this->assertStringContainsString( 'data-step="app_packages"', $html );
	}

	public function test_the_app_steps_are_appended_to_the_build_steps(): void {
		$r = $this->server( array( 'app_state' => 'pending', 'steps' => array( 'ip', 'vmid' ) ) );
		$r->set( array( 'state' => 'provisioning' ) );
		$p = Progress::build( Resource::find( $r->id() ) );

		$this->assertSame( 'build', $p['kind'] );
		$st = $this->statuses( $p );
		$this->assertSame( 'done', $st['vmid'] );
		$this->assertSame( 'current', $st['clone'] );
		$this->assertSame( 'todo', $st['app_download'] );
		$this->assertSame( 'todo', $st['app_verify'] );
		$keys = array_keys( $st );
		$this->assertLessThan( array_search( 'app_download', $keys, true ), array_search( 'ready', $keys, true ), 'The app steps come after the server steps.' );
	}

	public function test_a_server_without_an_app_has_no_app_steps(): void {
		$plain = $this->ready_vm( 5001 );
		$plain->set( array( 'state' => 'provisioning', 'steps' => array( 'ip' ) ) );
		$this->assertSame( array( 'ip', 'vmid', 'clone', 'configure', 'start', 'ready' ), array_column( Progress::build( Resource::find( $plain->id() ) )['steps'], 'key' ) );
		$this->assertSame( 'Ready', Panel::label( 'ready', Resource::find( $plain->id() ) ) );
	}

	public function test_ready_shows_ready_only_when_the_app_is_ready(): void {
		$r = $this->server( array( 'app_state' => 'ready', 'app_step' => 'complete' ) );
		$this->assertSame( 'Ready', Panel::label( 'ready', $r ) );
		$this->assertSame( 'ready', Panel::group( 'ready', $r ) );
		$this->assertSame( 'idle', Progress::build( $r )['phase'] );
	}

	public function test_a_failed_app_says_server_ready_and_app_failed_and_stops_the_progress(): void {
		$r = $this->server( array( 'app_state' => 'failed', 'app_step' => 'packages' ) );
		$this->assertSame( 'Server ready, WordPress failed', Panel::label( 'ready', $r ) );
		$this->assertStringContainsString( 'try again', Panel::message( 'ready', $r ) );
		$this->assertFalse( Progress::build( $r )['active'] );

		$view = View::license( $r->license() );
		$row  = Servers::rows( array( $view ), array( (int) $view['id'] => $r ) )[0];
		$this->assertSame( 'Server ready, WordPress failed', $row['state'] );
		$this->assertSame( 'bad', $row['tone'] );
		$this->assertTrue( ThankYou::row( $r )['ready'], 'The server can be opened to try again.' );

		wp_set_current_user( (int) $r->license()['user_id'] );
		$GLOBALS['wp']->query_vars['vps'] = (string) $r->license()['id'];
		ob_start();
		Servers::render( array( $view ) );
		$html = (string) ob_get_clean();
		unset( $GLOBALS['wp']->query_vars['vps'] );
		$this->assertMatchesRegularExpression( '#data-badge>Server ready, WordPress failed</span>#', $html );
		$this->assertStringNotContainsString( 'data-power-line', $html );
		$this->assertStringContainsString( 'data-app-retry', $html );
	}

	public function test_an_app_that_stays_silent_is_not_called_ready_either(): void {
		$r = $this->server( array( 'app_state' => 'unknown', 'app_started_at' => time() - 4000 ) );
		$this->assertSame( 'Server ready, WordPress is taking longer than usual', Panel::label( 'ready', $r ) );
		$this->assertFalse( Progress::build( $r )['active'], 'No endless polling.' );
	}

	public function test_the_overview_attention_names_the_step(): void {
		$r = $this->server( array( 'app_state' => 'installing', 'app_step' => 'web' ) );
		wp_set_current_user( (int) $r->license()['user_id'] );
		$items = Overview::data()['attention'] ?? array();
		$this->assertNotEmpty( $items );
		$this->assertSame( 'Installing WordPress: Setting up the web server', $items[0]['text'] );
	}
}

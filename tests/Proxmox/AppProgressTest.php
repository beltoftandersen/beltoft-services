<?php
/**
 * The app as part of a server's progress and status words.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Proxmox;

use BeltoftLicenses\Account\View;
use BeltoftServices\Account\Overview;
use BeltoftServices\Account\Panel;
use BeltoftServices\Account\Servers;
use BeltoftServices\Progress;
use BeltoftServices\Proxmox\Clusters;
use BeltoftServices\Proxmox\Operations;
use BeltoftServices\Proxmox\OsField;
use BeltoftServices\Resource;
use BeltoftServices\ThankYou;

require_once __DIR__ . '/MonitoringTestCase.php';

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

<?php
/**
 * The App tab of the server page, the reinstall choices and the cluster controls.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Proxmox;

use BeltoftLicenses\Account\View;
use BeltoftServices\Account\Servers;
use BeltoftServices\Crypto;
use BeltoftServices\Proxmox\Actions;
use BeltoftServices\Proxmox\AppReinstall;
use BeltoftServices\Proxmox\AppTab;
use BeltoftServices\Proxmox\Apps;
use BeltoftServices\Proxmox\Clusters;
use BeltoftServices\Proxmox\ClustersScreen;
use BeltoftServices\Proxmox\Operations;
use BeltoftServices\Proxmox\OsField;
use BeltoftServices\Resource;

require_once __DIR__ . '/MonitoringTestCase.php';

/**
 * Documentation values only; the fake client answers every call.
 */
class AppTabTest extends MonitoringTestCase {

	public function set_up(): void {
		parent::set_up();
		$this->cluster( array() );
	}

	public function tear_down(): void {
		unset( $GLOBALS['wp']->query_vars['vps'] );
		remove_all_filters( 'beltoft_services_apps' );
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	/**
	 * @param array<string, mixed> $over Form overrides.
	 */
	private function cluster( array $over ): void {
		$this->assertTrue(
			Clusters::save(
				'lab',
				array_merge(
					array(
						'label'                  => 'Lab',
						'api_url'                => 'https://pve.example.invalid:8006',
						'token_id'               => 'svc@pve!beltoft',
						'token_secret'           => 'secret-secret-secret-1',
						'client'                 => 'fake',
						'nodes'                  => 'node-a',
						'storage'                => 'disk-store',
						'cloudinit_storage'      => 'ci-store',
						'bridge'                 => 'vmbr9',
						'gateway'                => '192.0.2.1',
						'nameservers'            => '192.0.2.53',
						'prefix'                 => '24',
						'vmid_min'               => '5000',
						'vmid_max'               => '5099',
						'templates'              => "9000 = Debian 12\n9001 = Ubuntu 24.04",
						'vendor_snippet'         => 'local:snippets/vm-vendor-cloudinit-v2.yaml',
						'apps_ready'             => 1,
						'apps_enabled'           => array( 'docker', 'wordpress', 'coolify' ),
						'template_families_text' => "9001 = ubuntu-24.04\n9000 = debian-12",
					),
					$over
				)
			)
		);
	}

	/**
	 * A ready server with an app.
	 *
	 * @param array<string, mixed> $meta App meta.
	 */
	private function with_app( array $meta = array(), int $vmid = 5000 ): Resource {
		$r = $this->ready_vm( $vmid );
		$r->set( array_merge( array( 'ip' => '192.0.2.10', 'app_id' => 'wordpress', 'app_version' => '7.1.4', OsField::META => 9001, 'plan' => array( 'cluster' => 'lab', 'template' => 9001, 'cores' => 2, 'memory_mb' => 4096, 'disk_gb' => 50 ) ), $meta ) );
		Operations::remember_power( $r->id(), 'running' );

		return Resource::find( $r->id() );
	}

	private function page( Resource $r, string $tab = '' ): string {
		wp_set_current_user( (int) $r->license()['user_id'] );
		$view = View::license( $r->license() );
		$GLOBALS['wp']->query_vars['vps'] = (string) $r->license()['id'] . ( '' === $tab ? '' : '/' . $tab );
		ob_start();
		Servers::render( array( $view ) );

		return (string) ob_get_clean();
	}

	private function pane( string $html ): string {
		$this->assertMatchesRegularExpression( '#<section[^>]*data-tab="app"[^>]*>(.*?)</section>#s', $html );
		preg_match( '#<section[^>]*data-tab="app"[^>]*>(.*?)</section>#s', $html, $m );

		return $m[1];
	}

	// -- The tab -------------------------------------------------------------------------------

	public function test_the_tab_exists_only_for_a_server_with_an_app_and_comes_after_the_overview(): void {
		$plain = $this->ready_vm( 5001 );
		$this->assertStringNotContainsString( 'data-tab-link="app"', $this->page( $plain ) );

		$html = $this->page( $this->with_app() );
		$this->assertMatchesRegularExpression( '#data-tab-link="overview"[^>]*>Overview</a>\s*<a[^>]*data-tab-link="app"[^>]*>App</a>#', $html );
		$this->assertStringContainsString( 'data-tab="app"', $html );
	}

	public function test_the_tab_has_its_own_address_and_opens_from_it(): void {
		$r = $this->with_app();
		$this->assertStringContainsString( $r->license()['id'] . '/app', rawurldecode( Servers::url( $r->license(), 'app' ) ) );
		$GLOBALS['wp']->query_vars['vps'] = $r->license()['id'] . '/app';
		$this->assertSame( 'app', Servers::requested()['tab'] );

		$html = $this->page( $r, 'app' );
		$this->assertMatchesRegularExpression( '/data-tab-link="app" aria-current="page"/', $html );
		$this->assertDoesNotMatchRegularExpression( '/data-tab="app" hidden/', $html );
		$this->assertMatchesRegularExpression( '/data-tab="overview" hidden/', $html );
	}

	public function test_the_built_in_tabs_are_untouched(): void {
		$html = $this->page( $this->with_app() );

		foreach ( array( 'overview', 'access', 'reinstall' ) as $tab ) {
			$this->assertStringContainsString( 'data-tab-link="' . $tab . '"', $html );
		}

		$this->assertSame( array( 'snapshots', 'backups', 'disks', 'access', 'reinstall', 'app' ), Servers::tabs() );
	}

	// -- The states ----------------------------------------------------------------------------

	public function test_installing_shows_the_steps_and_where_it_is(): void {
		$pane = $this->pane( $this->page( $this->with_app( array( 'app_state' => 'installing', 'app_step' => 'database' ) ), 'app' ) );

		$this->assertStringContainsString( 'WordPress 7.1.4', $pane );
		$this->assertStringContainsString( '>Installing</dd>', $pane );
		$this->assertStringContainsString( 'Step 4 of 9: Setting up the database', $pane );
		$this->assertMatchesRegularExpression( '#is-done[^>]*>Downloading the installer#', $pane );
		$this->assertMatchesRegularExpression( '#is-current"[^>]*aria-current="step">Setting up the database#', $pane );
		$this->assertMatchesRegularExpression( '#is-todo[^>]*>Setting up the firewall#', $pane );
		$this->assertStringNotContainsString( 'data-app-reveal', $pane );
		$this->assertStringContainsString( 'data-app-state="installing"', $pane );
	}

	public function test_a_step_the_guest_made_up_is_never_shown(): void {
		$r = $this->with_app( array( 'app_state' => 'installing', 'app_step' => '<script>alert(1)</script>' ) );
		$pane = $this->pane( $this->page( $r, 'app' ) );
		$this->assertStringNotContainsString( 'alert(1)', $pane );
		$this->assertStringNotContainsString( 'Step ', $pane );
	}

	public function test_a_server_that_is_not_ready_yet_says_the_app_waits(): void {
		$pane = $this->pane( $this->page( $this->with_app( array( 'app_state' => 'pending' ) ), 'app' ) );
		$this->assertStringContainsString( 'Waiting for the server', $pane );
		$this->assertStringContainsString( 'data-app-state="waiting"', $pane );
	}

	public function test_ready_shows_the_address_the_login_once_and_the_getting_started_text(): void {
		$key = base64_encode( str_repeat( 'k', 32 ) );
		remove_all_filters( 'beltoft_services_key' );
		add_filter( 'beltoft_services_key', fn() => $key );
		$r = $this->with_app(
			array(
				'app_state'        => 'ready',
				'app_step'         => 'complete',
				'app_installed_at' => strtotime( '2026-10-29 12:00:00 UTC' ),
				'app_reveal'       => Crypto::encrypt( wp_json_encode( array( 'admin_user' => 'admin-x1', 'admin_password' => 'Secret-Pass-1' ) ) ),
				'app_reveal_until' => time() + DAY_IN_SECONDS,
			)
		);
		$pane = $this->pane( $this->page( $r, 'app' ) );

		$this->assertStringContainsString( '>Ready</dd>', $pane );
		$this->assertStringContainsString( 'WordPress 7.1.4', $pane );
		$this->assertStringContainsString( 'October 29, 2026', $pane );
		$this->assertStringContainsString( 'href="http://192.0.2.10/wp-admin/"', $pane );
		$this->assertStringContainsString( 'This address is not encrypted', $pane );
		$this->assertStringContainsString( 'Show login once', $pane );
		$this->assertStringContainsString( 'data-app-reveal', $pane );
		$this->assertStringContainsString( '/root/.beltoft-app/credentials.json', $pane );
		$this->assertStringContainsString( 'href="https://wordpress.org/documentation/', $pane );
		$this->assertStringNotContainsString( 'Secret-Pass-1', $pane, 'The login is never printed in the page.' );
		$this->assertStringNotContainsString( 'admin-x1', $pane );
		$this->assertStringContainsString( 'You keep the app up to date yourself', $pane );
	}

	public function test_with_a_domain_the_address_is_https_and_there_is_no_warning(): void {
		$r = $this->with_app( array( 'app_state' => 'ready', 'app_step' => 'complete', 'app_domain' => 'shop.mine.example' ) );
		$pane = $this->pane( $this->page( $r, 'app' ) );
		$this->assertStringContainsString( 'href="https://shop.mine.example/wp-admin/"', $pane );
		$this->assertStringNotContainsString( 'This address is not encrypted', $pane );
	}

	public function test_a_domain_that_could_not_be_set_is_said_plainly(): void {
		$r = $this->with_app( array( 'app_state' => 'ready', 'app_step' => 'complete', 'app_domain_want' => 'shop.mine.example', 'app_domain_error' => 'The name already has a DNS record.' ) );
		$pane = $this->pane( $this->page( $r, 'app' ) );
		$this->assertStringContainsString( 'The domain shop.mine.example could not be pointed at this server', $pane );
		$this->assertStringContainsString( 'The name already has a DNS record.', $pane );
		$this->assertStringContainsString( 'href="http://192.0.2.10/wp-admin/"', $pane );
	}

	public function test_pending_domain_and_paused_dns_reason_are_shown_on_app_tab(): void {
		$pending = $this->with_app( array( 'app_state' => 'pending', 'app_domain_want' => 'shop.mine.example', 'app_domain' => null ) );
		$this->assertStringContainsString( 'We will point shop.mine.example at this server when the server is ready.', $this->pane( $this->page( $pending, 'app' ) ) );
		$failed = $this->with_app( array( 'app_state' => 'ready', 'app_domain_want' => 'shop.mine.example', 'app_domain_error' => 'DNS hosting is paused for this domain.' ), 5001 );
		$pane = $this->pane( $this->page( $failed, 'app' ) );
		$this->assertStringContainsString( 'DNS hosting is paused for this domain.', $pane );
		$this->assertStringNotContainsString( 'data-app-domain-waiting', $pane );
	}

	public function test_ready_without_a_login_left_has_no_button_and_coolify_uses_its_port(): void {
		$pane = $this->pane( $this->page( $this->with_app( array( 'app_id' => 'coolify', 'app_version' => '4.5.1', 'app_state' => 'ready', 'app_step' => 'complete' ) ), 'app' ) );
		$this->assertStringNotContainsString( 'data-app-reveal', $pane );
		$this->assertStringContainsString( 'href="http://192.0.2.10:8000/"', $pane );
		$this->assertStringContainsString( '/root/.beltoft-app/credentials.json', $pane );

		$docker = $this->pane( $this->page( $this->with_app( array( 'app_id' => 'docker', 'app_version' => '29', 'app_state' => 'ready', 'app_step' => 'complete' ), 5002 ), 'app' ) );
		$this->assertStringNotContainsString( 'Address', $docker, 'Docker has no web address.' );
		$this->assertStringContainsString( 'Ports you publish with Docker are open to the internet', $docker );
	}

	public function test_an_expired_login_has_no_button(): void {
		$key = base64_encode( str_repeat( 'k', 32 ) );
		remove_all_filters( 'beltoft_services_key' );
		add_filter( 'beltoft_services_key', fn() => $key );
		$r = $this->with_app( array( 'app_state' => 'ready', 'app_step' => 'complete', 'app_reveal' => Crypto::encrypt( '{"admin_password":"x"}' ), 'app_reveal_until' => time() - 5 ) );
		$this->assertStringNotContainsString( 'data-app-reveal', $this->pane( $this->page( $r, 'app' ) ) );
	}

	public function test_failed_shows_a_plain_message_and_a_support_link_and_keeps_the_server_running(): void {
		$r = $this->with_app( array( 'app_state' => 'failed', 'app_step' => 'packages', 'app_installed_at' => 5 ) );
		$pane = $this->pane( $this->page( $r, 'app' ) );

		$this->assertStringContainsString( 'The app could not be installed', $pane );
		$this->assertStringContainsString( 'Your server is running', $pane );
		$this->assertMatchesRegularExpression( '#<a href="[^"]*/support/">Contact support</a>#', $pane );
		$this->assertStringNotContainsString( 'packages', $pane, 'Nothing the guest said is repeated.' );
		$this->assertStringNotContainsString( 'data-app-reveal', $pane );
		$this->assertStringNotContainsString( 'Getting started', $pane );
		$this->assertStringNotContainsString( 'Installed', $pane );
		$this->assertSame( 'ready', Resource::find( $r->id() )->get( 'state' ) );

		add_filter( 'beltoft_services_support_url', fn() => 'https://example.invalid/help/' );
		$this->assertStringContainsString( 'href="https://example.invalid/help/"', $this->pane( $this->page( $r, 'app' ) ) );
	}

	public function test_taking_longer_than_usual_asks_for_support_after_an_hour(): void {
		$pane = $this->pane( $this->page( $this->with_app( array( 'app_state' => 'unknown', 'app_started_at' => time() - 4000 ) ), 'app' ) );
		$this->assertStringContainsString( 'Taking longer than usual', $pane );
		$this->assertStringContainsString( 'Contact support', $pane );
	}

	// -- Try again ------------------------------------------------------------------------------

	private function retry_route( Resource $r, int $user ): \WP_REST_Response {
		wp_set_current_user( $user );
		$request = new \WP_REST_Request( 'POST', '/beltoft-services/v1/resources/' . $r->id() . '/actions/app_retry' );
		$request->set_param( 'args', array() );

		return rest_get_server()->dispatch( $request );
	}

	public function test_a_failed_app_on_a_ready_server_offers_try_again_and_only_then(): void {
		foreach ( array( 'failed', 'unknown' ) as $state ) {
			$r = $this->with_app( array( 'app_state' => $state, 'app_started_at' => time() - 4000 ), 5010 + strlen( $state ) );
			$this->assertArrayHasKey( 'app_retry', Actions::offered( $r ), $state );
			$this->assertStringContainsString( 'data-app-retry', $this->pane( $this->page( $r, 'app' ) ), $state );
		}
		foreach ( array( 'pending', 'installing', 'ready' ) as $i => $state ) {
			$r = $this->with_app( array( 'app_state' => $state ), 5020 + $i );
			$this->assertArrayNotHasKey( 'app_retry', Actions::offered( $r ), $state );
			$this->assertStringNotContainsString( 'data-app-retry', $this->pane( $this->page( $r, 'app' ) ), $state );
		}
		$r = $this->with_app( array( 'app_state' => 'failed' ), 5030 );
		$r->set( array( 'state' => 'building' ) );
		$this->assertArrayNotHasKey( 'app_retry', Actions::offered( Resource::find( $r->id() ) ) );
		$this->assertNotNull( Actions::check( Resource::find( $r->id() ), 'app_retry', array() ) );
		$plain = $this->ready_vm( 5031 );
		$this->assertArrayNotHasKey( 'app_retry', Actions::offered( $plain ) );
	}

	public function test_try_again_from_silent_waits_an_hour_and_never_follows_a_credentials_failure(): void {
		$fresh = $this->with_app( array( 'app_state' => 'unknown', 'app_started_at' => time() - 1800 ), 5040 );
		$this->assertFalse( \BeltoftServices\Proxmox\AppInstall::can_retry( $fresh ) );
		$this->assertStringNotContainsString( 'data-app-retry', $this->pane( $this->page( $fresh, 'app' ) ) );
		$old = $this->with_app( array( 'app_state' => 'unknown', 'app_started_at' => time() - 3700 ), 5041 );
		$this->assertTrue( \BeltoftServices\Proxmox\AppInstall::can_retry( $old ) );
		$failed = $this->with_app( array( 'app_state' => 'failed', 'app_step' => 'download' ), 5042 );
		$this->assertTrue( \BeltoftServices\Proxmox\AppInstall::can_retry( $failed ) );
		$login = $this->with_app( array( 'app_state' => 'failed', 'app_step' => 'credentials' ), 5043 );
		$this->assertFalse( \BeltoftServices\Proxmox\AppInstall::can_retry( $login ) );
		$pane = $this->pane( $this->page( $login, 'app' ) );
		$this->assertStringNotContainsString( 'data-app-retry', $pane );
		$this->assertStringContainsString( 'Contact support', $pane );
		$pending = $this->with_app( array( 'app_state' => 'pending', 'app_started_at' => time() - 9000 ), 5044 );
		$this->assertFalse( \BeltoftServices\Proxmox\AppInstall::can_retry( $pending ) );
	}

	public function test_try_again_resets_the_app_to_pending_with_a_new_request_and_queues_the_install_without_a_reinstall(): void {
		$r = $this->with_app( array( 'app_state' => 'failed', 'app_step' => 'download', 'app_request' => 'old', 'app_started_at' => 5, 'app_alerted' => 'x', 'last_reinstall_at' => time() ) );
		$user = (int) $r->license()['user_id'];

		$this->assertSame( 202, $this->retry_route( $r, $user )->get_status() );
		\BeltoftServices\Jobs::act( $r->id(), 'app_retry', array() );

		$fresh = Resource::find( $r->id() );
		$this->assertSame( 'pending', $fresh->get( 'app_state' ) );
		$this->assertEmpty( $fresh->get( 'app_request' ) );
		$this->assertEmpty( $fresh->get( 'app_step' ) );
		$this->assertEmpty( $fresh->get( 'app_alerted' ) );
		$this->assertSame( 'ready', $fresh->get( 'state' ), 'The server is not touched.' );
		$this->assertNotEmpty( as_get_scheduled_actions( array( 'hook' => \BeltoftServices\Proxmox\AppInstall::HOOK, 'args' => array( $r->id() ), 'status' => \ActionScheduler_Store::STATUS_PENDING ), 'ids' ) );
	}

	public function test_try_again_is_for_the_owner_only_and_three_times_an_hour(): void {
		$r = $this->with_app( array( 'app_state' => 'failed' ) );
		$other = self::factory()->user->create();
		$this->assertContains( $this->retry_route( $r, $other )->get_status(), array( 403, 404 ) );

		$user = (int) $r->license()['user_id'];
		for ( $i = 0; $i < 3; $i++ ) {
			$this->assertSame( 202, $this->retry_route( $r, $user )->get_status(), 'Try ' . ( $i + 1 ) );
		}
		$this->assertSame( 429, $this->retry_route( $r, $user )->get_status() );
	}

	public function test_try_again_on_an_app_that_is_not_failed_is_refused(): void {
		$r = $this->with_app( array( 'app_state' => 'installing' ) );
		$this->assertContains( $this->retry_route( $r, (int) $r->license()['user_id'] )->get_status(), array( 403, 422 ) );
	}

	public function test_the_tiles_assets_load_on_a_server_page_only(): void {
		$r = $this->with_app();
		wp_set_current_user( (int) $r->license()['user_id'] );
		wp_dequeue_script( \BeltoftServices\Proxmox\Tiles::HANDLE );
		wp_dequeue_style( \BeltoftServices\Proxmox\Tiles::HANDLE );

		unset( $GLOBALS['wp']->query_vars['vps'] );
		do_action( 'beltoft_services_account_assets', 'parent-handle', Servers::slug() );
		$this->assertFalse( wp_script_is( \BeltoftServices\Proxmox\Tiles::HANDLE, 'enqueued' ), 'Not on the list.' );

		$GLOBALS['wp']->query_vars['vps'] = (string) $r->license()['id'];
		do_action( 'beltoft_services_account_assets', 'parent-handle', 'licenses' );
		$this->assertFalse( wp_script_is( \BeltoftServices\Proxmox\Tiles::HANDLE, 'enqueued' ), 'Not on another endpoint.' );

		do_action( 'beltoft_services_account_assets', 'parent-handle', Servers::slug() );
		$this->assertTrue( wp_script_is( \BeltoftServices\Proxmox\Tiles::HANDLE, 'enqueued' ) );
		$this->assertTrue( wp_style_is( \BeltoftServices\Proxmox\Tiles::HANDLE, 'enqueued' ) );
	}

	public function test_the_reinstall_tiles_lock_the_system_and_offer_the_version_select(): void {
		$r    = $this->plain();
		$html = $this->reinstall_html( $r );
		$this->assertMatchesRegularExpression( '#<p[^>]*data-tiles-version-row hidden>.*?<select[^>]*data-tiles-version>#s', $html );
		$this->assertStringNotContainsString( 'name="app_version_pick"', $html );
		$this->assertDoesNotMatchRegularExpression( '#<select[^>]*data-tiles-version[^>]*name=#', $html, 'The version select has no name: its choice is written into the checked tile.' );
		$this->assertStringContainsString( 'data-tiles-note hidden', $this->page( $r, 'reinstall' ), 'The system tiles carry the note about the forced Ubuntu.' );

		$js = (string) file_get_contents( BELTOFT_SERVICES_PATH . 'assets/tiles.js' );
		$this->assertStringContainsString( 'radio.disabled = on && radio.value !== want', $js );
		$this->assertStringContainsString( 'radio.value = select.value', $js );
	}

	public function test_a_newer_version_and_a_retired_one_are_noted(): void {
		$dir = BELTOFT_SERVICES_PATH . 'apps/docker/28-test';
		mkdir( $dir );
		copy( BELTOFT_SERVICES_PATH . 'apps/docker/29/install.script', $dir . '/install.script' );
		$status = 'available';
		$filter = static function ( $data, $id ) use ( &$status ) {
			if ( 'docker' === $id && is_array( $data ) ) {
				$old                = $data['versions'][0];
				$old['id']          = '28-test';
				$old['label']       = 'Docker Engine 28';
				$old['status']      = $status;
				$data['versions'][] = $old;
			}

			return $data;
		};
		add_filter( 'beltoft_services_apps', $filter, 10, 2 );

		try {
			$r = $this->with_app( array( 'app_id' => 'docker', 'app_version' => '28-test', 'app_state' => 'ready', 'app_step' => 'complete' ) );
			$pane = $this->pane( $this->page( $r, 'app' ) );
			$this->assertStringContainsString( 'Docker Engine 28', $pane );
			$this->assertStringContainsString( 'A newer version is available: Docker Engine 29', $pane );
			$this->assertStringNotContainsString( 'no longer offered', $pane );

			$status = 'retired';
			$pane   = $this->pane( $this->page( Resource::find( $r->id() ), 'app' ) );
			$this->assertStringContainsString( 'Docker Engine 28', $pane, 'A retired version still shows on a server that has it.' );
			$this->assertStringContainsString( 'This version is no longer offered for new servers.', $pane );

			$current = $this->pane( $this->page( $this->with_app( array( 'app_id' => 'docker', 'app_version' => '29', 'app_state' => 'ready', 'app_step' => 'complete' ), 5003 ), 'app' ) );
			$this->assertStringNotContainsString( 'newer version', $current );
		} finally {
			remove_filter( 'beltoft_services_apps', $filter, 10 );
			unlink( $dir . '/install.script' );
			rmdir( $dir );
		}
	}

	// -- Assets --------------------------------------------------------------------------------

	public function test_the_tab_assets_load_only_on_the_page_of_a_server_with_an_app(): void {
		$with  = $this->with_app();
		$plain = $this->ready_vm( 5001 );
		wp_set_current_user( (int) $with->license()['user_id'] );
		wp_dequeue_script( AppTab::HANDLE );
		wp_dequeue_style( AppTab::HANDLE );

		AppTab::assets( 'handle', 'orders' );
		$this->assertFalse( wp_script_is( AppTab::HANDLE, 'enqueued' ), 'Another endpoint.' );

		$GLOBALS['wp']->query_vars['vps'] = '';
		AppTab::assets( 'handle', Servers::slug() );
		$this->assertFalse( wp_script_is( AppTab::HANDLE, 'enqueued' ), 'The list of servers.' );

		$GLOBALS['wp']->query_vars['vps'] = (string) $plain->license()['id'];
		wp_set_current_user( (int) $plain->license()['user_id'] );
		AppTab::assets( 'handle', Servers::slug() );
		$this->assertFalse( wp_script_is( AppTab::HANDLE, 'enqueued' ), 'A server without an app.' );

		$GLOBALS['wp']->query_vars['vps'] = (string) $with->license()['id'];
		wp_set_current_user( self::factory()->user->create() );
		AppTab::assets( 'handle', Servers::slug() );
		$this->assertFalse( wp_script_is( AppTab::HANDLE, 'enqueued' ), 'Someone else\'s server.' );

		wp_set_current_user( (int) $with->license()['user_id'] );
		AppTab::assets( 'handle', Servers::slug() );
		$this->assertTrue( wp_script_is( AppTab::HANDLE, 'enqueued' ) );
		$this->assertTrue( wp_style_is( AppTab::HANDLE, 'enqueued' ) );
	}

	// -- Reinstall -----------------------------------------------------------------------------

	private function reinstall_html( Resource $r ): string {
		ob_start();
		AppReinstall::fields( $r->id() );

		return (string) ob_get_clean();
	}

	private function plain( array $plan = array(), int $vmid = 5000 ): Resource {
		$r = $this->ready_vm( $vmid );
		$r->set( array( 'ip' => '192.0.2.10', OsField::META => 9000, 'plan' => array_merge( array( 'cluster' => 'lab', 'template' => 9000, 'cores' => 2, 'memory_mb' => 4096, 'disk_gb' => 50 ), $plan ) ) );

		return Resource::find( $r->id() );
	}

	public function test_a_server_without_an_app_is_offered_apps_with_no_app_as_the_default(): void {
		$r    = $this->plain();
		$html = $this->reinstall_html( $r );

		$this->assertMatchesRegularExpression( '#<input[^>]*type="radio" name="app_version" value="none" checked=\'checked\'[^>]*>.*?>No app</span>#s', $html );
		$this->assertMatchesRegularExpression( '#<input[^>]*name="app_version" value="wordpress/7.1.4"(?![^>]*disabled=)#', $html );
		$this->assertMatchesRegularExpression( '#<input[^>]*name="app_version" value="docker/29"(?![^>]*disabled=)#', $html );
		$this->assertMatchesRegularExpression( '#<input[^>]*name="app_version" value="coolify/4.5.1" disabled#', $html );
		$this->assertStringContainsString( '>Needs Performance</span>', $html );
		$this->assertStringContainsString( 'name="app_version"', $html );
		$this->assertStringContainsString( 'data-os="9001"', $html );
		$this->assertStringContainsString( 'Ubuntu 24.04', $html, 'The forced system is said plainly.' );
		$this->assertStringContainsString( 'disabled', $html, 'Coolify is too big for this plan.' );
		$this->assertStringContainsString( 'needs the Performance plan or larger', $html );
		$this->assertStringNotContainsString( 'Docker Engine 27', $html );
		$this->assertStringNotContainsString( 'name="domain', $html, 'No domain picker on a reinstall.' );
	}

	public function test_no_apps_are_offered_when_the_cluster_is_not_ready_or_the_app_is_not_enabled(): void {
		$this->cluster( array( 'apps_ready' => 0 ) );
		$this->assertSame( '', $this->reinstall_html( $this->plain() ) );

		$this->cluster( array( 'apps_enabled' => array( 'docker' ) ) );
		$html = $this->reinstall_html( $this->plain( array(), 5001 ) );
		$this->assertStringContainsString( 'docker/29', $html );
		$this->assertStringNotContainsString( 'wordpress/', $html );
	}

	public function test_no_app_keeps_a_plain_reinstall_unchanged_and_an_app_sets_ubuntu_and_a_fresh_install(): void {
		$r    = $this->plain();
		$meta = array( 'wipe' => true );

		foreach ( array( array(), array( 'app_version' => 'none' ), array( 'app_version' => 'keep' ), array( 'app_version' => '' ) ) as $args ) {
			$this->assertNull( AppReinstall::check( null, $r, $args ) );
			$this->assertSame( $meta, AppReinstall::meta( $meta, $r, $args ) );
		}

		$args = array( 'app_version' => 'wordpress/7.1.4', 'os' => '9001' );
		$this->assertNull( AppReinstall::check( null, $r, $args ) );
		$this->assertNull( AppReinstall::check( null, $r, array( 'app_version' => 'wordpress/7.1.4' ) ), 'No system posted: the app sets it.' );
		$out = AppReinstall::meta( $meta, $r, array( 'app_version' => 'wordpress/7.1.4', 'os' => '9000' ) );
		$this->assertTrue( $out['wipe'] );
		$this->assertSame( 'wordpress', $out['app_id'] );
		$this->assertSame( '7.1.4', $out['app_version'] );
		$this->assertSame( 9001, $out[ OsField::META ], 'Ubuntu 24.04 whatever was posted.' );
		$this->assertNull( $out['app_state'] );
		$this->assertNull( $out['app_request'] );
	}

	public function test_a_plain_server_reinstall_cannot_get_an_app_by_a_crafted_post(): void {
		$r = $this->plain();

		$this->assertSame( 'Choose one of the listed app versions.', AppReinstall::check( null, $r, array( 'app_version' => 'wordpress/0.0.1' ) ) );
		$this->assertSame( 'Choose one of the listed app versions.', AppReinstall::check( null, $r, array( 'app_version' => 'nope/1' ) ) );
		$this->assertSame( 'Choose one of the listed app versions.', AppReinstall::check( null, $r, array( 'app_version' => '../../x/1' ) ) );
		$this->assertSame( 'Choose one of the listed app versions.', AppReinstall::check( null, $r, array( 'app_version' => '7.1.4' ) ) );
		$this->assertSame( 'Choose one of the listed app versions.', AppReinstall::check( null, $r, array( 'app_version' => array( 'x' ) ) ) );
		$this->assertStringContainsString( 'needs Ubuntu 24.04', (string) AppReinstall::check( null, $r, array( 'app_version' => 'wordpress/7.1.4', 'os' => '9000' ) ) );
		$this->assertSame( 'already refused', AppReinstall::check( 'already refused', $r, array( 'app_version' => 'wordpress/7.1.4' ) ) );

		$small = $this->plain( array( 'cores' => 2, 'memory_mb' => 2048, 'disk_gb' => 25 ), 5002 );
		$this->assertSame( 'Coolify needs the Performance plan or larger. Choose a larger plan or no app.', AppReinstall::check( null, $small, array( 'app_version' => 'coolify/4.5.1' ) ) );
		$this->assertSame( array( 'wipe' => true ), AppReinstall::meta( array( 'wipe' => true ), $r, array( 'app_version' => 'nope/1' ) ), 'An invalid pick changes nothing.' );

		$this->cluster( array( 'apps_enabled' => array( 'docker' ) ) );
		$this->assertNotNull( AppReinstall::check( null, $r, array( 'app_version' => 'wordpress/7.1.4' ) ), 'Not enabled here.' );
		$this->cluster( array( 'apps_ready' => 0 ) );
		$this->assertNotNull( AppReinstall::check( null, $r, array( 'app_version' => 'docker/29' ) ) );
	}

	public function test_the_reinstall_action_still_needs_the_confirmation_for_an_app(): void {
		$r = $this->plain();
		$this->assertStringContainsString( 'REINSTALL', (string) Actions::check( $r, 'reinstall', array( 'app_version' => 'wordpress/7.1.4' ) ) );
		$this->assertNotSame( 'Choose one of the listed app versions.', (string) Actions::check( $r, 'reinstall', array( 'confirm' => Actions::CONFIRM, 'app_version' => 'wordpress/7.1.4' ) ) );
		$this->assertSame( 'Choose one of the listed app versions.', Actions::check( $r, 'reinstall', array( 'confirm' => Actions::CONFIRM, 'app_version' => 'wordpress/9.9' ) ) );
	}

	public function test_the_default_is_the_same_app_and_version_and_none_is_offered(): void {
		$r    = $this->with_app();
		$html = $this->reinstall_html( $r );

		$this->assertMatchesRegularExpression( '#<input[^>]*name="app_version" value="keep" checked=\'checked\'#', $html );
		$this->assertMatchesRegularExpression( '#<input[^>]*name="app_version" value="none" data-none="1"#', $html );
		$this->assertStringContainsString( '>No app</span>', $html );
		$this->assertStringContainsString( 'name="app_version"', $html );
		$this->assertStringNotContainsString( 'no longer be installed', $html );

		$both = $this->page( $r, 'reinstall' );
		$this->assertStringContainsString( 'data-app-reinstall', $both, 'The form prints the choice.' );
		$this->assertStringContainsString( 'data-tiles="os"', $both, 'The system is chosen with tiles too.' );
		$this->assertMatchesRegularExpression( '#<input[^>]*type="radio" name="os" value="9001" checked#', $both );
	}

	public function test_the_domain_notice_shows_in_every_state(): void {
		$vmid = 5100;
		foreach ( array( 'pending' => 'The app uses the server address instead.', 'installing' => 'The app uses the server address instead.', 'ready' => 'The app uses the server address instead.', 'failed' => '' ) as $state => $tail ) {
			$r    = $this->with_app( array( 'app_state' => $state, 'app_step' => 'packages', 'app_domain_want' => 'shop.mine.example', 'app_domain_error' => 'The name already has a DNS record.' ), ++$vmid );
			$pane = $this->pane( $this->page( $r, 'app' ) );
			$this->assertStringContainsString( 'The domain shop.mine.example could not be pointed at this server.', $pane, $state );
			if ( '' !== $tail ) {
				$this->assertStringContainsString( $tail, $pane, $state );
			} else {
				$this->assertStringNotContainsString( 'The app uses the server address', $pane, $state );
			}
		}

		$quiet = $this->with_app( array( 'app_state' => 'installing', 'app_step' => 'packages' ), ++$vmid );
		$this->assertStringNotContainsString( 'could not be pointed', $this->pane( $this->page( $quiet, 'app' ) ) );
	}

	public function test_the_refusal_says_what_is_wrong_and_the_system_wording_is_only_for_the_system(): void {
		$r = $this->with_app();
		$this->cluster( array( 'apps_ready' => 0 ) );
		$why = (string) AppReinstall::check( null, $r, array( 'app_version' => 'keep' ) );
		$this->assertSame( 'The app is not enabled on this cluster.', $why );
		$this->assertStringNotContainsString( 'Ubuntu', $why );
	}

	public function test_other_versions_are_listed_and_a_retired_server_version_preselects_the_current_one_with_a_note(): void {
		$dir = BELTOFT_SERVICES_PATH . 'apps/docker/28-test';
		mkdir( $dir );
		copy( BELTOFT_SERVICES_PATH . 'apps/docker/29/install.script', $dir . '/install.script' );
		$filter = static function ( $data, $id ) {
			if ( 'docker' === $id && is_array( $data ) ) {
				$old                = $data['versions'][0];
				$old['id']          = '28-test';
				$old['label']       = 'Docker Engine 28';
				$old['status']      = 'retired';
				$data['versions'][] = $old;
				$gone               = $old;
				$gone['id']         = '27-test';
				$gone['label']      = 'Docker Engine 27';
				$gone['status']     = 'retired';
				$data['versions'][] = $gone;
			}

			return $data;
		};
		add_filter( 'beltoft_services_apps', $filter, 10, 2 );

		try {
			$r = $this->with_app( array( 'app_id' => 'docker', 'app_version' => '28-test', 'app_state' => 'ready' ) );
			$options = AppReinstall::options( $r );
			$this->assertTrue( $options['retired'] );
			$this->assertSame( '29', $options['selected'], 'The current version is preselected.' );
			$this->assertSame( array( '29', 'none' ), array_map( 'strval', array_keys( $options['choices'] ) ), 'No same-version choice and no retired version.' );

			$html = $this->reinstall_html( $r );
			$this->assertStringContainsString( 'can no longer be installed', $html );
			$this->assertMatchesRegularExpression( '#<input[^>]*name="app_version" value="29" checked=\'checked\'[^>]*data-versions=\'?"[^"]*Current: Docker Engine 29#', $html );
			$this->assertStringNotContainsString( 'Docker Engine 27', $html );

			$this->assertNull( AppReinstall::check( null, $r, array( 'app_version' => '29' ) ) );
			$this->assertNotNull( AppReinstall::check( null, $r, array( 'app_version' => 'keep' ) ), 'A retired version cannot be kept.' );
			$this->assertNotNull( AppReinstall::check( null, $r, array( 'app_version' => '28-test' ) ) );
			$this->assertNotNull( AppReinstall::check( null, $r, array() ), 'No choice means keep, which is retired.' );
		} finally {
			remove_filter( 'beltoft_services_apps', $filter, 10 );
			unlink( $dir . '/install.script' );
			rmdir( $dir );
		}
	}

	public function test_the_choice_is_validated(): void {
		$r = $this->with_app();
		$this->assertNull( AppReinstall::check( null, $r, array() ), 'No choice is the same app and version.' );
		$this->assertNull( AppReinstall::check( null, $r, array( 'app_version' => 'keep', 'os' => '9001' ) ) );
		$this->assertNull( AppReinstall::check( null, $r, array( 'app_version' => 'none', 'os' => '9000' ) ), 'No app can use any system.' );
		$this->assertSame( 'Choose one of the listed app versions.', AppReinstall::check( null, $r, array( 'app_version' => '9.9' ) ) );
		$this->assertSame( 'Choose one of the listed app versions.', AppReinstall::check( null, $r, array( 'app_version' => '../x' ) ) );
		$this->assertStringContainsString( 'needs Ubuntu 24.04', (string) AppReinstall::check( null, $r, array( 'app_version' => 'keep', 'os' => '9000' ) ) );
		$this->assertSame( 'already refused', AppReinstall::check( 'already refused', $r, array() ), 'An earlier refusal stands.' );

		$small = $this->with_app( array( 'app_id' => 'coolify', 'app_version' => '4.5.1' ), 5004 );
		$this->assertSame( 'Coolify needs the Performance plan or larger. Choose a larger plan or no app.', AppReinstall::check( null, $small, array( 'app_version' => 'keep' ) ) );
		$this->assertNull( AppReinstall::check( null, $small, array( 'app_version' => 'none' ) ), 'Leaving the app is always allowed.' );

		$this->cluster( array( 'apps_ready' => 0 ) );
		$this->assertNotNull( AppReinstall::check( null, $r, array( 'app_version' => 'keep' ) ), 'Apps switched off on the cluster.' );
		$this->assertNull( AppReinstall::check( null, $r, array( 'app_version' => 'none' ) ) );
	}

	public function test_the_reinstall_action_refuses_through_the_same_check(): void {
		$r = $this->with_app();
		$why = Actions::check( $r, 'reinstall', array( 'confirm' => Actions::CONFIRM, 'app_version' => '9.9' ) );
		$this->assertSame( 'Choose one of the listed app versions.', $why );
		$this->assertNull( Actions::check( $r, 'reinstall', array( 'confirm' => Actions::CONFIRM, 'app_version' => 'keep' ) ) );
	}

	public function test_a_reinstall_starts_the_app_again_from_nothing(): void {
		$r = $this->with_app( array( 'app_state' => 'ready', 'app_step' => 'complete', 'app_request' => 'old-request', 'app_installed_at' => 5, 'app_reveal' => 'x', 'app_reveal_until' => time() + 5, 'app_domain' => 'shop.mine.example', 'app_zone' => 'mine.example' ) );
		$meta = AppReinstall::meta( array( 'wipe' => true ), $r, array( 'app_version' => 'keep' ) );

		$this->assertTrue( $meta['wipe'] );
		foreach ( array( 'app_state', 'app_step', 'app_request', 'app_installed_at', 'app_started_at', 'app_checked_at', 'app_queue_until', 'app_recipe' ) as $key ) {
			$this->assertArrayHasKey( $key, $meta );
			$this->assertNull( $meta[ $key ], $key );
		}
		$this->assertSame( '', $meta['app_reveal'] );
		$this->assertSame( '7.1.4', $meta['app_version'] );
		$this->assertArrayNotHasKey( 'app_id', $meta );
		$this->assertArrayNotHasKey( 'app_domain', $meta, 'The record already exists and stays.' );
	}

	public function test_choosing_no_app_removes_the_app_from_the_server(): void {
		$r = $this->with_app( array( 'app_state' => 'ready', 'app_domain' => 'shop.mine.example' ) );
		$meta = AppReinstall::meta( array( 'wipe' => true ), $r, array( 'app_version' => 'none' ) );
		$this->assertSame( '', $meta['app_id'] );
		$this->assertSame( '', $meta['app_version'] );
		$this->assertNull( $meta['app_domain'] );
		$this->assertNull( $meta['app_state'] );

		$this->assertTrue( $r->set( $meta ) );
		$fresh = Resource::find( $r->id() );
		$this->assertSame( '', $fresh->get( 'app_id' ) );
		$this->assertStringContainsString( 'data-tab-link="app"', $this->page( $fresh ), 'The tab keeps the notice for a legacy DNS record left in place.' );
		$this->assertStringContainsString( 'The DNS record for shop.mine.example was not made by us', $this->page( $fresh ) );
	}

	public function test_an_invalid_choice_changes_nothing_in_the_meta(): void {
		$r = $this->with_app();
		$meta = array( 'wipe' => true );
		$this->assertSame( $meta, AppReinstall::meta( $meta, $r, array( 'app_version' => '9.9' ) ) );
	}

	// -- The cluster controls ------------------------------------------------------------------

	public function test_the_cluster_form_has_the_app_controls_and_a_save_turns_them_on_and_off(): void {
		$this->cluster( array( 'apps_ready' => 0, 'apps_enabled' => array(), 'template_families_text' => '' ) );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$_GET['edit'] = 'lab';
		ob_start();
		ClustersScreen::render();
		$html = (string) ob_get_clean();
		unset( $_GET['edit'] );

		$this->assertStringContainsString( 'name="apps_ready"', $html );
		$this->assertDoesNotMatchRegularExpression( '#name="apps_ready" value="1" checked#', $html, 'Off by default.' );
		foreach ( array( 'docker', 'wordpress', 'coolify' ) as $id ) {
			$this->assertMatchesRegularExpression( '#name="apps_enabled\[\]" value="' . $id . '" >#', $html );
		}
		$this->assertStringContainsString( 'name="template_families_text"', $html );
		$this->assertStringContainsString( 'name="apps_form"', $html );

		$this->cluster( array( 'apps_form' => '1', 'apps_ready' => '1', 'apps_enabled' => array( 'wordpress' ), 'template_families_text' => "9001 = Ubuntu-24.04\nnonsense\n9999 = ubuntu-24.04" ) );
		$saved = Clusters::get( 'lab' );
		$this->assertSame( 1, $saved['apps_ready'] );
		$this->assertSame( array( 'wordpress' ), $saved['apps_enabled'] );
		$this->assertSame( array( 9001 => 'ubuntu-24.04' ), $saved['template_families'], 'Lowercased; a VMID that is not a listed template is dropped.' );

		$this->cluster( array( 'apps_form' => '1', 'apps_ready' => null, 'apps_enabled' => array() ) );
		$saved = Clusters::get( 'lab' );
		$this->assertSame( 0, $saved['apps_ready'] );
		$this->assertSame( array(), $saved['apps_enabled'] );
	}

	public function test_a_save_that_leaves_the_app_keys_out_keeps_them(): void {
		$input = array(
			'label'            => 'Lab', 'api_url' => 'https://pve.example.invalid:8006', 'token_id' => 'svc@pve!beltoft', 'client' => 'fake',
			'nodes'            => 'node-a', 'storage' => 'disk-store', 'cloudinit_storage' => 'ci-store', 'bridge' => 'vmbr9',
			'gateway'          => '192.0.2.1', 'prefix' => '24', 'vmid_min' => '5000', 'vmid_max' => '5099',
			'templates'        => "9000 = Debian 12\n9001 = Ubuntu 24.04",
			'vendor_snippet'   => 'local:snippets/vm-vendor-cloudinit-v2.yaml',
		);
		$this->assertTrue( Clusters::save( 'lab', $input ) );
		$saved = Clusters::get( 'lab' );
		$this->assertSame( 1, $saved['apps_ready'] );
		$this->assertSame( array( 9001 => 'ubuntu-24.04', 9000 => 'debian-12' ), $saved['template_families'] );
		$this->assertSame( 9001, Apps::os_template( $saved ) );
	}
}

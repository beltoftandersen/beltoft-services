<?php
/**
 * The plugin side of changing the domain of a running app: the gate in app.json, the refusals, the files written into the guest,
 * the run token and the status reader that takes only a fixed schema. Fake client only.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Apps;

use BeltoftServices\Apps\AppRename;
use BeltoftServices\Apps\Apps;
use BeltoftServices\Proxmox\Pve\Clusters;
use BeltoftServices\Proxmox\Vm\Operations;
use BeltoftServices\Proxmox\Vm\VmRecords;
use BeltoftServices\Resource;
use BeltoftServices\Tests\Proxmox\Network\MonitoringTestCase;

require_once __DIR__ . '/../Proxmox/Network/MonitoringTestCase.php';

final class AppRenameTest extends MonitoringTestCase {

	private const IP  = '192.0.2.10';
	private const NEW = 'shop.example.com';

	public function set_up(): void {
		parent::set_up();
		add_filter( 'beltoft_services_rename_wait', '__return_zero' );
		$this->assertTrue( Clusters::save( 'lab', array(
			'label' => 'Lab', 'api_url' => 'https://pve.example.invalid:8006',
			'token_id' => 'svc@pve!beltoft', 'token_secret' => 'secret-secret-secret-1',
			'client' => 'fake', 'nodes' => 'node-a', 'storage' => 'disk-store',
			'cloudinit_storage' => 'ci-store', 'bridge' => 'vmbr9',
			'gateway' => '192.0.2.1', 'nameservers' => '192.0.2.53', 'prefix' => '24',
			'vmid_min' => '5000', 'vmid_max' => '5099',
			'templates' => "9000 = Debian 12\n9001 = Ubuntu 24.04",
			'apps_ready' => 1, 'apps_enabled' => array( 'wordpress', 'n8n', 'docker' ),
			'template_families_text' => "9001 = ubuntu-24.04\n9000 = debian-12",
		) ) );
	}

	/** A ready server with an app. */
	private function server( string $app = 'wordpress', array $meta = array() ): Resource {
		$versions = array( 'wordpress' => '7.1.4', 'n8n' => '2', 'docker' => '29', 'odoo' => '20', 'nextcloud' => '35', 'easyappointments' => '1.6' );
		$r        = $this->ready_vm( 5000, 'christian.beltoft@gmail.com' );
		$this->assertTrue( VmRecords::move( $r->id(), 'node-a' ) );
		$this->assertTrue( $r->set( array_merge( array( 'ip' => self::IP, 'app_id' => $app, 'app_version' => $versions[ $app ], 'app_state' => 'ready', 'agent' => 'ok' ), $meta ) ) );

		return Resource::find( $r->id() );
	}

	private function want( string $app = 'wordpress', array $over = array() ): array {
		return array_merge( array( 'domain' => self::NEW, 'www' => '', 'old_domain' => 'old.example.com', 'old_www' => '', 'ip' => self::IP, 'app' => $app, 'recipe' => 1 ), $over );
	}

	// -- The gate ----------------------------------------------------------------------------

	public function test_every_domain_app_has_a_rename_gate_and_script_and_the_others_do_not(): void {
		$with = array();

		foreach ( Apps::ids() as $id ) {
			$app  = Apps::definition( $id );
			$gate = $app['rename'] ?? null;

			if ( true === ( $app['domain'] ?? false ) ) {
				$with[] = $id;
				$this->assertIsArray( $gate, $id );
				$this->assertSame( 1, $gate['since_recipe'], $id );
				$this->assertMatchesRegularExpression( '/^[a-z0-9.-]+\.script$/D', $gate['script'] );
				$this->assertFileExists( BELTOFT_SERVICES_PATH . 'apps/' . $id . '/' . $gate['script'], $id );
				$this->assertNotSame( '', AppRename::script( $id ), $id );
			} else {
				$this->assertNull( $gate, $id . ' has no domain support and no rename.' );
				$this->assertSame( '', AppRename::script( $id ) );
			}
		}

		$this->assertSame( array( 'easyappointments', 'n8n', 'nextcloud', 'odoo', 'wordpress' ), $with );
		$this->assertFileExists( BELTOFT_SERVICES_PATH . 'apps/rename-common.sh' );
	}

	public function test_the_install_scripts_and_their_checksums_are_not_changed_by_the_gate(): void {
		foreach ( Apps::ids() as $id ) {
			$app = Apps::definition( $id );

			foreach ( (array) $app['versions'] as $version ) {
				$this->assertSame( hash_file( 'sha256', BELTOFT_SERVICES_PATH . 'apps/' . $id . '/' . $version['id'] . '/' . $version['install'] ), $version['sha256'], $id );
				$this->assertStringContainsString( $version['sha256'] . '  ' . $id . '/' . $version['id'] . '/install.script', (string) file_get_contents( BELTOFT_SERVICES_PATH . 'apps/CHECKSUMS' ) );
			}
		}
	}

	public function test_supported_refuses_a_recipe_below_the_gate_and_a_stopped_server_and_a_silent_agent(): void {
		$r = $this->server();
		$this->assertNull( AppRename::supported( $r ) );

		Operations::remember_power( $r->id(), 'stopped' );
		$this->assertStringContainsString( 'not running', (string) AppRename::supported( $r ) );
		Operations::remember_power( $r->id(), 'running' );
		$this->assertNull( AppRename::supported( $r ) );

		$r->set( array( 'agent' => 'unknown' ) );
		$this->assertStringContainsString( 'cannot be reached', (string) AppRename::supported( Resource::find( $r->id() ) ) );
	}

	public function test_an_app_without_domain_support_is_refused_with_the_reinstall_text(): void {
		$r = $this->server( 'docker' );
		$this->assertStringContainsString( 'Reinstall', (string) AppRename::supported( $r ) );
		$r->set( array( 'app_id' => '' ) );
		$this->assertStringContainsString( 'Reinstall', (string) AppRename::supported( Resource::find( $r->id() ) ) );
	}

	public function test_a_recipe_below_the_gate_needs_a_reinstall(): void {
		$filter = static function ( $data, $id ) {
			if ( 'wordpress' === $id && is_array( $data ) ) {
				$data['rename']['since_recipe'] = 3;
			}

			return $data;
		};
		add_filter( 'beltoft_services_apps', $filter, 10, 2 );

		try {
			$r = $this->server( 'wordpress', array( 'app_recipe' => 2 ) );
			$this->assertStringContainsString( 'older version', (string) AppRename::supported( $r ) );
			$r->set( array( 'app_recipe' => 3 ) );
			$this->assertNull( AppRename::supported( Resource::find( $r->id() ) ) );
		} finally {
			remove_filter( 'beltoft_services_apps', $filter, 10 );
		}
	}

	// -- Push -------------------------------------------------------------------------------

	private function file( string $path ): ?string {
		$vm = $this->fake->vm( 5000 );

		return isset( $vm['guest_files'][ $path ] ) ? (string) $vm['guest_files'][ $path ] : null;
	}

	public function test_push_writes_script_and_request_then_execs_and_returns_a_run_token(): void {
		$r     = $this->server();
		$token = AppRename::push( $r, $this->want( 'wordpress', array( 'www' => 'www.' . self::NEW ) ) );

		$this->assertIsString( $token );
		$this->assertMatchesRegularExpression( '/^[0-9a-f]{24}$/', $token );
		$this->assertSame( AppRename::script( 'wordpress' ), $this->file( AppRename::SCRIPT ) );

		$want = json_decode( (string) $this->file( AppRename::WANT ), true );
		$this->assertSame( 1, $want['v'] );
		$this->assertSame( $token, $want['run'] );
		$this->assertSame( 'domain', $want['mode'] );
		$this->assertSame( self::NEW, $want['domain'] );
		$this->assertSame( 'www.' . self::NEW, $want['www'] );
		$this->assertSame( 'old.example.com', $want['old_domain'] );
		$this->assertSame( self::IP, $want['ip'] );
		$this->assertSame( hash( 'sha256', AppRename::script( 'wordpress' ) ), $want['sha256'] );

		$this->assertSame( array( array( 'systemd-run', '--no-block', '--collect', '--unit=beltoft-rename', '/bin/bash', AppRename::SCRIPT ) ), $this->fake->guest_execs( 5000 ) );
		$this->assertSame( array( 'run' => $token, 'domain' => self::NEW ), Resource::find( $r->id() )->get( AppRename::ASKED ) );
	}

	public function test_a_request_that_is_not_valid_writes_and_runs_nothing(): void {
		$r = $this->server();

		foreach ( array(
			array( 'domain' => 'shop' ),
			array( 'domain' => 'a b.example.com' ),
			array( 'domain' => 'x.example.com;id' ),
			array( 'www' => 'www.other.example.com' ),
			array( 'old_domain' => 'bad name' ),
			array( 'old_domain' => self::NEW ),
			array( 'old_www' => 'www.old.example.com', 'old_domain' => '' ),
			array( 'ip' => 'not-an-ip' ),
			array( 'app' => 'n8n' ),
		) as $over ) {
			$out = AppRename::push( $r, $this->want( 'wordpress', $over ) );
			$this->assertWPError( $out, wp_json_encode( $over ) );
		}

		$this->assertNull( $this->file( AppRename::SCRIPT ) );
		$this->assertSame( array(), $this->fake->guest_execs( 5000 ) );
	}

	public function test_an_agent_that_refuses_exec_or_is_down_gives_an_error_not_a_guess(): void {
		$r = $this->server();
		$this->fake->set_guest_rename( 5000, 'blocked' );
		$out = AppRename::push( $r, $this->want() );
		$this->assertWPError( $out );
		$this->assertNull( Resource::find( $r->id() )->get( AppRename::ASKED ), 'Nothing is remembered for a run that did not start.' );

		$this->fake->set_guest_rename( 5000, 'error' );
		$this->assertWPError( AppRename::push( $r, $this->want() ), 'A start command that exits with an error is an error.' );

		$this->fake->set_guest_rename( 5000, 'ok' );
		$this->fake->set_guest_agent_down( 5000, true );
		$this->assertWPError( AppRename::push( $r, $this->want() ) );
		$this->fake->set_guest_agent_down( 5000, false );
		$this->assertIsString( AppRename::push( $r, $this->want() ) );
	}

	public function test_a_stopped_server_gives_an_error(): void {
		$r = $this->server();
		$this->fake->request( 'POST', '/nodes/node-a/qemu/5000/status/stop' );
		$this->assertWPError( AppRename::push( $r, $this->want() ) );
	}

	// -- Read -------------------------------------------------------------------------------

	public function test_the_fake_guest_modes_give_the_statuses_the_flow_needs(): void {
		$r = $this->server();
		$expect = array(
			'ok'              => array( 'ready', 'done', 'none' ),
			'hang'            => array( 'running', 'certificate', 'none' ),
			'certificate'     => array( 'failed', 'done', 'certificate' ),
			'caddy'           => array( 'failed', 'done', 'caddy' ),
			'app'             => array( 'failed', 'done', 'app' ),
			'probe'           => array( 'failed', 'done', 'probe' ),
			'busy'            => array( 'failed', 'caddy', 'busy' ),
			'rollback_failed' => array( 'failed', 'rollback', 'app' ),
		);

		foreach ( $expect as $mode => $want ) {
			$this->fake->set_guest_rename( 5000, $mode );
			$token  = AppRename::push( $r, $this->want() );
			$status = AppRename::read( Resource::find( $r->id() ), (string) $token );
			$this->assertIsArray( $status, $mode );
			$this->assertSame( $want, array( $status['state'], $status['step'], $status['reason'] ), $mode );
			$this->assertSame( self::NEW, $status['domain'] );
		}

		$this->fake->set_guest_rename( 5000, 'other_run' );
		$token = AppRename::push( $r, $this->want() );
		$this->assertNull( AppRename::read( Resource::find( $r->id() ), (string) $token ), 'A status of another run is not ours.' );

		$this->fake->set_guest_rename( 5000, 'no_status' );
		$token = AppRename::push( $r, $this->want( 'wordpress', array( 'domain' => 'next.example.com' ) ) );
		$this->assertNull( AppRename::read( Resource::find( $r->id() ), (string) $token ), 'The old status is for another run and domain.' );
	}

	public function test_polls_make_the_status_read_running_first(): void {
		$r = $this->server();
		$this->fake->set_guest_rename( 5000, 'ok', array( 'polls' => 2 ) );
		$token = (string) AppRename::push( $r, $this->want() );
		$r     = Resource::find( $r->id() );
		$this->assertSame( 'running', AppRename::read( $r, $token )['state'] );
		$this->assertSame( 'running', AppRename::read( $r, $token )['state'] );
		$this->assertSame( 'ready', AppRename::read( $r, $token )['state'] );
	}

	private function good( array $over = array() ): array {
		return array_merge( array( 'v' => 1, 'run' => 'tok', 'state' => 'ready', 'step' => 'done', 'reason' => 'none', 'domain' => self::NEW, 'updated' => '2026-11-25T10:00:00Z' ), $over );
	}

	public function test_status_reader_accepts_only_the_fixed_schema(): void {
		$ok = AppRename::parse( (string) wp_json_encode( $this->good() ), 'tok', self::NEW );
		$this->assertSame( array( 'state' => 'ready', 'step' => 'done', 'reason' => 'none', 'domain' => self::NEW, 'updated' => '2026-11-25T10:00:00Z' ), $ok );

		$bad = array(
			'wrong run'          => $this->good( array( 'run' => 'other' ) ),
			'no run'             => array_diff_key( $this->good(), array( 'run' => 1 ) ),
			'wrong version'      => $this->good( array( 'v' => 2 ) ),
			'string version'     => $this->good( array( 'v' => '1' ) ),
			'unknown state'      => $this->good( array( 'state' => 'done' ) ),
			'unknown step'       => $this->good( array( 'step' => 'format-disk' ) ),
			'unknown reason'     => $this->good( array( 'reason' => 'because' ) ),
			'domain not asked'   => $this->good( array( 'domain' => 'evil.example.com' ) ),
			'bad date'           => $this->good( array( 'updated' => 'yesterday-ish' ) ),
			'array state'        => $this->good( array( 'state' => array( 'ready' ) ) ),
			'number date'        => $this->good( array( 'updated' => 5 ) ),
		);

		foreach ( $bad as $name => $data ) {
			$this->assertNull( AppRename::parse( (string) wp_json_encode( $data ), 'tok', self::NEW ), $name );
		}

		foreach ( array( '', 'not json', '[]', '"ready"', '{"v":1,', str_repeat( 'x', 70000 ), '{"v":1,"run":"tok","state":"ready","step":"done","reason":"none","domain":"' . self::NEW . '","updated":"2026-11-25T10:00:00Z","pad":"' . str_repeat( 'x', 70000 ) . '"}' ) as $raw ) {
			$this->assertNull( AppRename::parse( $raw, 'tok', self::NEW ), substr( $raw, 0, 30 ) );
		}

		$this->assertNull( AppRename::parse( (string) wp_json_encode( $this->good() ), '', self::NEW ), 'No token, no status.' );
		$this->assertNull( AppRename::parse( (string) wp_json_encode( $this->good() ), 'tok', '' ), 'No asked domain, no status.' );

		$extra = AppRename::parse( (string) wp_json_encode( $this->good( array( 'password' => 'x', 'note' => '<b>hi</b>' ) ) ), 'tok', self::NEW );
		$this->assertSame( array( 'state', 'step', 'reason', 'domain', 'updated' ), array_keys( $extra ), 'Any other key is dropped.' );
	}

	public function test_a_huge_or_truncated_file_in_the_guest_gives_null(): void {
		$r     = $this->server();
		$token = (string) AppRename::push( $r, $this->want() );
		$r     = Resource::find( $r->id() );
		$this->assertIsArray( AppRename::read( $r, $token ) );

		$this->fake->set_guest_file( 5000, AppRename::STATUS, (string) wp_json_encode( $this->good( array( 'run' => $token, 'pad' => str_repeat( 'x', 70000 ) ) ) ) );
		$this->assertNull( AppRename::read( $r, $token ), 'Bigger than the bounded read: not parsed.' );

		$this->fake->set_guest_file( 5000, AppRename::STATUS, '{"v":1,"run":"' . $token . '","state":"re' );
		$this->assertNull( AppRename::read( $r, $token ) );
	}
}

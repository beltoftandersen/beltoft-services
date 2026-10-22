<?php
/**
 * The customer's actions and the VM panels. Only the fake client is used: nothing here reaches a Proxmox host.
 *
 * @package BeltoftServices
 */

namespace BeltoftServices\Tests\Proxmox;

use BeltoftServices\Account\Panel;
use BeltoftServices\Alerts;
use BeltoftServices\Crypto;
use BeltoftServices\Drivers\Registry;
use BeltoftServices\Jobs;
use BeltoftServices\Product;
use BeltoftServices\Proxmox\Access;
use BeltoftServices\Proxmox\Actions;
use BeltoftServices\Proxmox\Clusters;
use BeltoftServices\Proxmox\FakeClient;
use BeltoftServices\Proxmox\IpPool;
use BeltoftServices\Proxmox\ProxmoxDriver;
use BeltoftServices\Proxmox\Schema;
use BeltoftServices\Proxmox\VmRecords;
use BeltoftServices\Resource;
use BeltoftServices\Tests\TestCase;

/**
 * A fake cluster that shows the raw parameters of every call to a callback (the recorded calls hide passwords).
 */
class SpyClient extends FakeClient {

	/**
	 * Called with ( method, path, params ) before the request is handled.
	 *
	 * @var callable|null
	 */
	public $spy = null;

	public function request( string $method, string $path, array $params = array() ) {
		if ( $this->spy ) {
			( $this->spy )( $method, $path, $params );
		}

		return parent::request( $method, $path, $params );
	}
}

/**
 * Actions, their checks and the panels, against the fake cluster.
 */
class ActionsTest extends TestCase {

	private const SECRET = 'secret-secret-secret-1';

	/**
	 * The fake cluster.
	 *
	 * @var SpyClient
	 */
	private $fake;

	/**
	 * Mails sent.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private $mails = array();

	public function set_up(): void {
		parent::set_up();

		$this->mails = array();
		add_filter(
			'pre_wp_mail',
			function ( $pre, $atts ) {
				$this->mails[] = $atts;
				return true;
			},
			10,
			2
		);

		$key = base64_encode( random_bytes( 32 ) );
		add_filter( 'beltoft_services_key', fn() => $key );

		$this->wipe_tables();
		delete_option( Clusters::OPTION );
		$this->settings( array( 'task_wait' => 5 ) );

		$this->fake = new SpyClient();
		add_filter( 'beltoft_services_proxmox_client', fn() => $this->fake );
		add_filter(
			'beltoft_services_drivers',
			static function ( $drivers ) {
				$drivers['proxmox'] = new ProxmoxDriver();
				return $drivers;
			}
		);

		$this->fake->add_node( 'node-a' );

		foreach ( array(
			9000 => 'ubuntu-26-template',
			9001 => 'ubuntu-24-template',
		) as $vmid => $name ) {
			$this->fake->add_vm(
				array(
					'vmid'     => $vmid,
					'node'     => 'node-a',
					'name'     => $name,
					'template' => 1,
					'config'   => array(
						'scsi0'  => 'disk-store:vm-' . $vmid . '-disk-0,size=10G',
						'net0'   => 'virtio=AA:BB:CC:DD:EE:FF,bridge=vmbr0',
						'agent'  => '1',
						'memory' => 512,
						'cores'  => 1,
					),
				)
			);
		}

		$this->assertTrue(
			Clusters::save(
				'lab',
				array(
					'label'             => 'Lab cluster',
					'api_url'           => 'https://pve.example.invalid:8006',
					'token_id'          => 'svc@pve!beltoft',
					'token_secret'      => self::SECRET,
					'client'            => 'fake',
					'pool_api'          => 'pools',
					'nodes'             => 'node-a',
					'storage'           => 'disk-store',
					'cloudinit_storage' => 'ci-store',
					'bridge'            => 'vmbr9',
					'gateway'           => '192.0.2.1',
					'nameservers'       => '192.0.2.53',
					'prefix'            => '24',
					'vmid_min'          => '5000',
					'vmid_max'          => '5099',
					'templates'         => "9000 = Ubuntu 26.04\n9001 = Ubuntu 24.04",
					'default_template'  => '9000',
					'block_smtp'        => '1',
				)
			)
		);

		foreach ( array( '192.0.2.10', '192.0.2.11', '192.0.2.12', '192.0.2.13', '192.0.2.14' ) as $address ) {
			IpPool::add( $address );
		}

		as_unschedule_all_actions( Jobs::SWEEP, array(), Jobs::GROUP );
	}

	public function tear_down(): void {
		remove_all_filters( 'beltoft_services_key' );
		remove_all_filters( 'beltoft_services_proxmox_client' );
		delete_option( Clusters::OPTION );
		$this->wipe_tables();
		wp_set_current_user( 0 );

		parent::tear_down();
	}

	private function wipe_tables(): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- test fixture reset.
		$wpdb->query( 'DELETE FROM ' . Schema::tables()['vms'] );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- test fixture reset.
		$wpdb->query( 'DELETE FROM ' . Schema::tables()['ips'] );
		delete_transient( IpPool::FREE_TRANSIENT );
	}

	/**
	 * A ready, running VM and its owner.
	 *
	 * @param array<string, mixed> $meta Initial meta.
	 * @return array{0: Resource, 1: int}
	 */
	private function ready( array $meta = array() ): array {
		$product  = $this->service_product( 'proxmox', array( 'cluster' => 'lab', 'template' => 9000, 'cores' => 2, 'memory_mb' => 4096, 'disk_gb' => 30 ), array( 'max' => 3, 'slug' => 'vm-' . wp_generate_password( 5, false ) ) );
		$license  = $this->service_license( $product, array( 'fire' => false ) );
		$resource = Resource::create( $license, Registry::get( 'proxmox' ), Product::plan( $product->get_id() ), $meta );

		$this->assertInstanceOf( Resource::class, $resource );
		Jobs::run( $resource->id() );

		$fresh = Resource::find( $resource->id() );
		$this->assertSame( 'ready', $fresh->get( 'state' ) );

		return array( $fresh, (int) $license['user_id'] );
	}

	/**
	 * Calls matching a method and a path ending.
	 *
	 * @param string $method Method.
	 * @param string $suffix Path ending.
	 * @return array<int, array<string, mixed>>
	 */
	private function calls( string $method, string $suffix ): array {
		return array_values(
			array_filter(
				$this->fake->calls(),
				static fn( $call ) => $call['method'] === $method && substr( $call['path'], -strlen( $suffix ) ) === $suffix
			)
		);
	}

	private function act( Resource $r, string $action, array $args = array() ): void {
		Jobs::act( $r->id(), $action, $args );
	}

	/**
	 * The action route, as the panel script calls it.
	 *
	 * @param int                  $id     Resource id.
	 * @param int                  $user   User id.
	 * @param string               $action Action.
	 * @param array<string, mixed> $args   Arguments.
	 */
	private function route( int $id, int $user, string $action, array $args = array() ): \WP_REST_Response {
		wp_set_current_user( $user );
		$request = new \WP_REST_Request( 'POST', '/beltoft-services/v1/resources/' . $id . '/actions/' . $action );
		$request->set_param( 'args', $args );

		return rest_get_server()->dispatch( $request );
	}

	private function rebuild_to_ready( int $id ): void {
		for ( $i = 0; $i < 12 && 'ready' !== Resource::find( $id )->get( 'state' ); $i++ ) {
			Resource::find( $id )->set( array( 'next_attempt_at' => time() - 1 ) );
			Jobs::run( $id );
			$this->run_jobs();
		}
	}

	// -- Reinstall ---------------------------------------------------------------------------------

	public function test_medusa_reinstall_ends_ready_on_the_same_vmid_and_ip(): void {
		list( $r ) = $this->ready();
		$vmid      = (int) $r->get( 'vmid' );
		$ip        = (string) $r->get( 'ip' );
		$old_uuid  = $this->fake->vm( $vmid )['config']['description'] ?? '';

		$this->act( $r, 'reinstall', array( 'confirm' => 'REINSTALL', 'os' => '9001' ) );

		$this->assertSame( 'provisioning', Resource::find( $r->id() )->get( 'state' ) );
		$this->assertTrue( (bool) Resource::find( $r->id() )->get( 'wipe' ) );

		$this->rebuild_to_ready( $r->id() );

		$fresh = Resource::find( $r->id() );
		$this->assertSame( 'ready', $fresh->get( 'state' ) );
		$this->assertSame( $vmid, (int) $fresh->get( 'vmid' ), 'The same VMID.' );
		$this->assertSame( $ip, (string) $fresh->get( 'ip' ), 'The same address.' );
		$this->assertSame( 'running', $this->fake->vm( $vmid )['status'] );
		$this->assertSame( 9001, (int) $fresh->get( 'os_template' ) );
		$this->assertNotSame( '', $old_uuid );

		$clones = $this->calls( 'POST', '/clone' );
		$this->assertSame( '/nodes/node-a/qemu/9001/clone', end( $clones )['path'], 'The chosen system was cloned.' );
		$this->assertGreaterThan( 0, (int) $fresh->get( 'last_reinstall_at' ) );
	}

	public function test_reinstall_wipe_purges_and_destroys_unreferenced_disks(): void {
		list( $r ) = $this->ready();

		$this->act( $r, 'reinstall', array( 'confirm' => 'REINSTALL' ) );
		$this->rebuild_to_ready( $r->id() );

		$deletes = $this->calls( 'DELETE', '/qemu/' . $r->get( 'vmid' ) );
		$this->assertCount( 1, $deletes );
		$this->assertSame( 1, (int) $deletes[0]['params']['purge'] );
		$this->assertSame( 1, (int) $deletes[0]['params']['destroy-unreferenced-disks'] );
	}

	public function test_reinstall_gives_a_new_password_stored_before_it_is_sent(): void {
		list( $r, $owner ) = $this->ready();
		$first             = Access::take_reveal( Resource::find( $r->id() ), $owner );
		$this->assertNotNull( $first );

		// An unread password of the first build must not be reused either.
		$second_before = Access::reset_password( Resource::find( $r->id() ) );
		Access::finish_reset( Resource::find( $r->id() ) );

		$seen = array();
		$this->fake->spy = function ( $method, $path, $params ) use ( $r, &$seen ) {
			if ( 'PUT' === $method && isset( $params['cipassword'] ) ) {
				$stored = (string) Resource::find( $r->id() )->get( 'reveal' );
				$seen[] = array(
					'sent'   => $params['cipassword'],
					'stored' => '' === $stored ? null : Crypto::decrypt( $stored ),
				);
			}
		};

		$this->act( $r, 'reinstall', array( 'confirm' => 'REINSTALL' ) );
		$this->assertSame( '', (string) Resource::find( $r->id() )->get( 'reveal' ), 'The old password is gone at once.' );

		$this->rebuild_to_ready( $r->id() );
		$this->fake->spy = null;

		$this->assertCount( 1, $seen );
		$this->assertSame( $seen[0]['sent'], $seen[0]['stored'], 'Stored encrypted before the call that sends it.' );
		$this->assertNotSame( $first, $seen[0]['sent'] );
		$this->assertNotSame( $second_before, $seen[0]['sent'] );
		$this->assertSame( $seen[0]['sent'], Access::take_reveal( Resource::find( $r->id() ), $owner ) );
	}

	public function test_reinstall_needs_confirmation_and_rate_limit(): void {
		list( $r, $owner ) = $this->ready();

		$refused = $this->route( $r->id(), $owner, 'reinstall', array() );
		$this->assertSame( 422, $refused->get_status() );
		$this->assertStringContainsString( 'REINSTALL', $refused->get_data()['message'] );

		$this->assertSame( 422, $this->route( $r->id(), $owner, 'reinstall', array( 'confirm' => 'reinstall' ) )->get_status(), 'Case matters.' );
		$this->assertSame( 422, $this->route( $r->id(), $owner, 'reinstall', array( 'confirm' => 'REINSTALL', 'os' => '9999' ) )->get_status(), 'An unlisted system.' );
		$this->assertSame( 'ready', Resource::find( $r->id() )->get( 'state' ), 'Nothing was queued.' );

		$this->assertSame( 202, $this->route( $r->id(), $owner, 'reinstall', array( 'confirm' => 'REINSTALL' ) )->get_status() );
		$this->run_jobs();
		$this->rebuild_to_ready( $r->id() );
		$this->assertSame( 'ready', Resource::find( $r->id() )->get( 'state' ) );

		// The limit lives in the resource, so a flushed cache and cleared transients do not lift it.
		wp_cache_flush();
		global $wpdb;
		$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '%transient%beltoft_services%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$again = $this->route( $r->id(), $owner, 'reinstall', array( 'confirm' => 'REINSTALL' ) );
		$this->assertSame( 422, $again->get_status() );
		$this->assertStringContainsString( 'once an hour', $again->get_data()['message'] );

		Resource::find( $r->id() )->set( array( 'last_reinstall_at' => time() - HOUR_IN_SECONDS - 5 ) );
		$this->assertSame( 202, $this->route( $r->id(), $owner, 'reinstall', array( 'confirm' => 'REINSTALL' ) )->get_status() );
	}

	public function test_reinstall_missing_confirmation_is_refused_without_alert(): void {
		list( $r ) = $this->ready();
		$seen      = count( $this->fake->calls() );
		$this->mails = array();

		$this->act( $r, 'reinstall', array() );
		$this->act( $r, 'reinstall', array( 'confirm' => 'yes' ) );

		$this->assertSame( 'ready', Resource::find( $r->id() )->get( 'state' ) );
		$this->assertFalse( (bool) Resource::find( $r->id() )->get( 'wipe' ) );
		$this->assertCount( $seen, $this->fake->calls(), 'Not one Proxmox call.' );
		$this->assertSame( array(), $this->mails, 'The admin is not alerted.' );
		$this->assertArrayNotHasKey( $r->id(), Alerts::problems() );

		$events = wp_json_encode( \BeltoftLicenses\Events::for_license( (int) $r->license()['id'] ) );
		$this->assertStringContainsString( 'Reinstall refused', (string) $events );
	}

	public function test_reinstall_sends_no_second_ready_email(): void {
		list( $r ) = $this->ready();
		$ready     = count( $this->mails );
		$this->assertGreaterThanOrEqual( 1, $ready, 'The first Ready email went out.' );

		$this->act( $r, 'reinstall', array( 'confirm' => 'REINSTALL' ) );
		$this->rebuild_to_ready( $r->id() );

		$this->assertSame( 'ready', Resource::find( $r->id() )->get( 'state' ) );
		$this->assertCount( $ready, $this->mails );
	}

	// -- Reset password ------------------------------------------------------------------------------

	public function test_reset_password_stores_before_sending_and_reveals_once(): void {
		list( $r, $owner ) = $this->ready();
		Access::take_reveal( Resource::find( $r->id() ), $owner ); // The first password is read.
		$agent_before = count( $this->calls( 'POST', '/agent/set-user-password' ) );

		$seen = array();
		$this->fake->spy = function ( $method, $path, $params ) use ( $r, &$seen ) {
			if ( isset( $params['cipassword'] ) || isset( $params['password'] ) ) {
				$fresh  = Resource::find( $r->id() );
				$stored = (string) $fresh->get( 'reveal' );
				$seen[] = array(
					'path'    => $path,
					'sent'    => $params['cipassword'] ?? $params['password'],
					'stored'  => '' === $stored ? null : Crypto::decrypt( $stored ),
					'pending' => (bool) $fresh->get( 'password_reset' ),
				);
			}
		};

		$this->act( $r, 'reset_password' );
		$this->fake->spy = null;

		$this->assertCount( 2, $seen, 'cipassword and the guest agent.' );

		foreach ( $seen as $one ) {
			$this->assertSame( $one['sent'], $one['stored'], 'Stored before it is sent: ' . $one['path'] );
			$this->assertTrue( $one['pending'], 'Marked not yet applied while it is sent.' );
		}

		$this->assertSame( $seen[0]['sent'], $seen[1]['sent'] );

		// Only cipassword is written: never ipconfig0, cicustom or any other cloud-init value.
		$put = $this->calls( 'PUT', '/qemu/' . $r->get( 'vmid' ) . '/config' );
		$this->assertSame( array( 'cipassword' ), array_keys( end( $put )['params'] ) );
		$this->assertCount( $agent_before + 1, $this->calls( 'POST', '/agent/set-user-password' ) );
		$this->assertSame( 'root', $this->calls( 'POST', '/agent/set-user-password' )[ $agent_before ]['params']['username'] );

		// Revealed once, by the owner only.
		$this->assertNull( Access::take_reveal( Resource::find( $r->id() ), $owner + 999 ) );
		$response = $this->reveal( $r->id(), $owner );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( $seen[0]['sent'], $response->get_data()['password'] );
		$this->assertSame( 409, $this->reveal( $r->id(), $owner )->get_status() );

		$haystack = (string) wp_json_encode( array( \BeltoftLicenses\Events::for_license( (int) $r->license()['id'] ), $this->fake->calls(), $this->mails, Resource::find( $r->id() )->get( 'last_error' ) ) );
		$this->assertStringNotContainsString( $seen[0]['sent'], $haystack );
	}

	private function reveal( int $id, int $user ): \WP_REST_Response {
		wp_set_current_user( $user );

		return rest_get_server()->dispatch( new \WP_REST_Request( 'POST', '/beltoft-services/v1/resources/' . $id . '/reveal' ) );
	}

	public function test_a_reset_password_the_agent_did_not_take_is_not_revealed_and_the_retry_reuses_it(): void {
		list( $r, $owner ) = $this->ready();
		Access::take_reveal( Resource::find( $r->id() ), $owner );
		$this->fake->fail_next( 'POST', '#/agent/set-user-password$#', array( 'status' => 500, 'message' => 'QEMU guest agent is not running' ) );

		$sent = array();
		$this->fake->spy = static function ( $method, $path, $params ) use ( &$sent ) {
			if ( 'PUT' === $method && isset( $params['cipassword'] ) ) {
				$sent[] = $params['cipassword'];
			}
		};

		$this->act( $r, 'reset_password' );
		$this->assertTrue( (bool) Resource::find( $r->id() )->get( 'password_reset' ), 'Not applied yet.' );
		$this->assertSame( 409, $this->reveal( $r->id(), $owner )->get_status(), 'A password the VM does not have is not shown.' );
		$this->assertFalse( Access::revealable( Resource::find( $r->id() ) ) );

		Resource::find( $r->id() )->set( array( 'act_attempts' => 0 ) );
		$this->run_jobs();
		$this->act( $r, 'reset_password' );
		$this->fake->spy = null;

		$this->assertGreaterThanOrEqual( 2, count( $sent ) );
		$this->assertSame( array( $sent[0] ), array_values( array_unique( $sent ) ), 'The same password on the retry.' );
		$this->assertFalse( (bool) Resource::find( $r->id() )->get( 'password_reset' ) );
		$this->assertSame( $sent[0], $this->reveal( $r->id(), $owner )->get_data()['password'] );
	}

	public function test_reset_password_on_a_stopped_vm_stores_cipassword_only(): void {
		list( $r, $owner ) = $this->ready();
		$this->fake->request( 'POST', '/nodes/node-a/qemu/' . $r->get( 'vmid' ) . '/status/stop' );
		$agent = count( $this->calls( 'POST', '/agent/set-user-password' ) );

		$this->act( $r, 'reset_password' );

		$this->assertCount( $agent, $this->calls( 'POST', '/agent/set-user-password' ), 'A stopped VM has no agent to ask.' );
		$this->assertNotEmpty( $this->calls( 'PUT', '/config' ) );
		$this->assertTrue( Access::revealable( Resource::find( $r->id() ) ) );
	}

	// -- SSH key -------------------------------------------------------------------------------------

	private static function key(): string {
		$type = 'ssh-ed25519';
		$str  = static fn( string $s ) => pack( 'N', strlen( $s ) ) . $s;

		return $type . ' ' . base64_encode( $str( $type ) . $str( random_bytes( 32 ) ) ) . ' me@host'; // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}

	public function test_replace_ssh_key_validated(): void {
		list( $r, $owner ) = $this->ready();

		foreach ( array( '', 'not a key', 'ssh-rsa AAAA' ) as $bad ) {
			$answer = $this->route( $r->id(), $owner, 'replace_ssh_key', array( 'key' => $bad ) );
			$this->assertSame( 422, $answer->get_status() );
			$this->assertStringNotContainsString( 'not a key', wp_json_encode( $answer->get_data() ) );
		}

		$puts = count( $this->calls( 'PUT', '/config' ) );
		$this->act( $r, 'replace_ssh_key', array( 'key' => 'garbage' ) );
		$this->assertCount( $puts, $this->calls( 'PUT', '/config' ), 'A bad key reaches no call.' );
		$this->assertNull( Resource::find( $r->id() )->get( 'ssh_key' ) );

		$good = self::key();
		$this->assertSame( 202, $this->route( $r->id(), $owner, 'replace_ssh_key', array( 'key' => $good ) )->get_status() );
		$this->run_jobs();

		$clean = \BeltoftServices\Proxmox\SshKeyField::normalise( $good );
		$put   = $this->calls( 'PUT', '/qemu/' . $r->get( 'vmid' ) . '/config' );
		$this->assertSame( array( 'sshkeys' ), array_keys( end( $put )['params'] ), 'Only the key is written.' );
		$this->assertSame( '***', end( $put )['params']['sshkeys'] );
		$this->assertSame( $clean, Resource::find( $r->id() )->get( 'ssh_key' ) );
		$this->assertSame( 'Replace SSH key', Actions::offered( Resource::find( $r->id() ) )['replace_ssh_key'] );
	}

	public function test_a_resource_without_a_key_offers_add_ssh_key(): void {
		list( $r ) = $this->ready();

		$this->assertSame( 'Add SSH key', Actions::offered( $r )['replace_ssh_key'] );
	}

	// -- Power ---------------------------------------------------------------------------------------

	public function test_start_only_offered_when_stopped(): void {
		list( $r ) = $this->ready();

		$running = array_keys( Actions::offered( Resource::find( $r->id() ) ) );
		$this->assertNotContains( 'start', $running );
		$this->assertContains( 'shutdown', $running );
		$this->assertContains( 'reboot', $running );

		$this->act( $r, 'shutdown' );
		$this->assertSame( 'stopped', $this->fake->vm( (int) $r->get( 'vmid' ) )['status'] );

		$stopped = array_keys( Actions::offered( Resource::find( $r->id() ) ) );
		$this->assertContains( 'start', $stopped );
		$this->assertNotContains( 'shutdown', $stopped );
		$this->assertNotContains( 'reboot', $stopped );

		$this->act( $r, 'start' );
		$this->assertSame( 'running', $this->fake->vm( (int) $r->get( 'vmid' ) )['status'] );
		$this->assertNotContains( 'start', array_keys( Actions::offered( Resource::find( $r->id() ) ) ) );

		// Unknown power: everything is offered and each action re-reads the VM.
		delete_transient( \BeltoftServices\Proxmox\Operations::STATUS_PREFIX . $r->id() );
		$this->assertContains( 'start', array_keys( Actions::offered( Resource::find( $r->id() ) ) ) );
	}

	public function test_shutdown_is_graceful_and_a_system_that_stays_up_is_not_plugged_out(): void {
		list( $r ) = $this->ready();
		$vmid      = (int) $r->get( 'vmid' );

		$this->fake->next_task( array( 'exitstatus' => 'VM quit/powerdown failed - got timeout', 'effect' => false ) );
		$this->act( $r, 'shutdown' );

		$this->assertEmpty( $this->calls( 'POST', '/status/stop' ), 'Never a plug pull for a customer.' );
		$this->assertSame( 'running', $this->fake->vm( $vmid )['status'] );
		$this->assertNotEmpty( $this->calls( 'POST', '/status/shutdown' ) );
		$this->assertArrayNotHasKey( $r->id(), Alerts::problems(), 'Not an error for the shop.' );

		$starts = count( $this->calls( 'POST', '/status/start' ) );
		$this->act( $r, 'start' ); // Already running: idempotent.
		$this->assertCount( $starts, $this->calls( 'POST', '/status/start' ) );
	}

	public function test_reboot_needs_a_running_server(): void {
		list( $r ) = $this->ready();
		$this->act( $r, 'reboot' );
		$this->assertCount( 1, $this->calls( 'POST', '/status/reboot' ) );

		$this->fake->request( 'POST', '/nodes/node-a/qemu/' . $r->get( 'vmid' ) . '/status/stop' );
		$this->act( $r, 'reboot' );
		$this->assertCount( 1, $this->calls( 'POST', '/status/reboot' ), 'A stopped server is not rebooted.' );
	}

	// -- Refusals ------------------------------------------------------------------------------------

	public function test_actions_refused_when_not_ready(): void {
		foreach ( array( 'paused', 'provisioning', 'failed', 'deleting' ) as $state ) {
			list( $r, $owner ) = $this->ready();
			$r->set( array( 'state' => $state ) );
			$seen = count( $this->fake->calls() );

			foreach ( array( 'start', 'shutdown', 'reboot', 'reset_password', 'replace_ssh_key', 'reinstall' ) as $action ) {
				$response = $this->route( $r->id(), $owner, $action, array( 'key' => self::key(), 'confirm' => 'REINSTALL' ) );
				$this->assertSame( 403, $response->get_status(), $state . ' ' . $action );
				$this->act( $r, $action, array( 'key' => self::key(), 'confirm' => 'REINSTALL' ) );
			}

			$this->assertCount( $seen, $this->fake->calls(), 'No Proxmox call while ' . $state );
		}
	}

	public function test_actions_refuse_visitors_strangers_and_other_customers_resources(): void {
		list( $r, $owner ) = $this->ready();
		$stranger          = self::factory()->user->create();

		$this->assertSame( 401, $this->route( $r->id(), 0, 'reboot' )->get_status() );
		$this->assertSame( 403, $this->route( $r->id(), $stranger, 'reboot' )->get_status() );
		$this->assertSame( 403, $this->route( $r->id() + 999, $owner, 'reboot' )->get_status() );
		$this->assertSame( 403, $this->route( $r->id(), $owner, 'nonsense' )->get_status() );
		$this->assertEmpty( $this->calls( 'POST', '/status/reboot' ) );
	}

	public function test_the_route_needs_the_rest_nonce(): void {
		list( $r, $owner ) = $this->ready();
		wp_set_current_user( $owner );
		unset( $_SERVER['HTTP_X_WP_NONCE'], $_REQUEST['_wpnonce'] );

		// WordPress's own cookie check: a cookie session without a nonce is an anonymous request, which the route refuses.
		$GLOBALS['wp_rest_auth_cookie'] = true;
		$result                         = rest_cookie_check_errors( null );
		unset( $GLOBALS['wp_rest_auth_cookie'] );

		$this->assertTrue( $result );
		$this->assertSame( 0, get_current_user_id() );
		$this->assertSame( 401, $this->route( $r->id(), 0, 'reboot' )->get_status() );
		$this->assertEmpty( $this->calls( 'POST', '/status/reboot' ) );
	}

	public function test_actions_have_a_rate_limit(): void {
		list( $r, $owner ) = $this->ready();
		$statuses          = array();

		for ( $i = 0; $i < 12; $i++ ) {
			$statuses[] = $this->route( $r->id(), $owner, 'reset_password' )->get_status();
		}

		$this->assertSame( 202, $statuses[0] );
		$this->assertContains( 429, $statuses );
	}

	public function test_sensitive_actions_keep_a_floor_when_the_route_limit_is_turned_off(): void {
		list( $r, $owner ) = $this->ready();
		add_filter( 'beltoft_services_rest_rate_limit', '__return_zero' );
		$statuses = array();

		for ( $i = 0; $i < 7; $i++ ) {
			$statuses[] = $this->route( $r->id(), $owner, 'reset_password' )->get_status();
		}

		remove_filter( 'beltoft_services_rest_rate_limit', '__return_zero' );

		$this->assertSame( array( 202, 202, 202, 202, 202, 429, 429 ), $statuses, 'Five an hour for a password reset.' );
	}

	public function test_a_failed_action_never_shows_the_proxmox_error_to_the_customer(): void {
		list( $r, $owner ) = $this->ready();
		$this->fake->fail_next( 'PUT', '#/config$#', array( 'status' => 500, 'message' => 'secret internal pve text: /etc/pve/qemu-server/5000.conf' ) );

		$response = $this->route( $r->id(), $owner, 'reset_password' );
		$this->assertStringNotContainsString( 'internal pve', (string) wp_json_encode( $response->get_data() ) );

		$this->run_jobs();
		$this->assertStringNotContainsString( 'internal pve', (string) wp_json_encode( Panel::message( 'ready' ) ) );
	}

	// -- Panels --------------------------------------------------------------------------------------

	/**
	 * The panel as the owner sees it.
	 *
	 * @param Resource $r     Resource.
	 * @param int      $owner User.
	 */
	private function panel( Resource $r, int $owner ): string {
		wp_set_current_user( $owner );
		ob_start();
		Panel::render( $r->license() );

		return (string) ob_get_clean();
	}

	public function test_panel_hides_generic_buttons_for_actions_with_arguments(): void {
		list( $r, $owner ) = $this->ready();
		$html              = $this->panel( $r, $owner );

		foreach ( array( 'reboot', 'shutdown', 'reset_password' ) as $action ) {
			$this->assertStringContainsString( 'class="button woocommerce-button beltoft-services-action" data-action="' . $action . '"', $html );
		}

		foreach ( array( 'replace_ssh_key', 'reinstall' ) as $action ) {
			$this->assertStringNotContainsString( 'beltoft-services-action" data-action="' . $action . '"', $html, 'No generic button.' );
			$this->assertStringContainsString( '<form class="beltoft-services-vm-form" data-action="' . $action . '">', $html );
		}

		$this->assertStringContainsString( 'name="confirm"', $html );
		$this->assertStringContainsString( 'Add SSH key', $html );
		$this->assertStringContainsString( 'name="os"', $html );
		$this->assertMatchesRegularExpression( '#<option value="9000"[^>]*selected#', $html, 'The current system is preselected.' );
	}

	public function test_reinstall_form_preselects_the_current_os(): void {
		list( $r, $owner ) = $this->ready( array( 'os_template' => 9001 ) );

		$this->assertMatchesRegularExpression( '#<option value="9001"[^>]*selected#', $this->panel( $r, $owner ) );
		$this->assertDoesNotMatchRegularExpression( '#<option value="9000"[^>]*selected#', $this->panel( $r, $owner ) );
	}

	public function test_panel_never_prints_the_password(): void {
		list( $r, $owner ) = $this->ready();
		$stored            = (string) $r->get( 'reveal' );
		$plain             = (string) Crypto::decrypt( $stored );
		$this->assertNotSame( '', $plain );

		$html = $this->panel( $r, $owner );

		$this->assertStringNotContainsString( $plain, $html );
		$this->assertStringNotContainsString( $stored, $html );
		$this->assertStringContainsString( 'data-vm-reveal', $html, 'The button that asks the server for it once.' );
		$this->assertStringContainsString( (string) $r->get( 'ip' ), $html );
		$this->assertStringContainsString( 'ssh root@' . $r->get( 'ip' ), $html );
		$this->assertStringContainsString( '2 vCPU, 4096 MB RAM, 30 GB disk', $html );

		// A read password has no button any more.
		Access::take_reveal( Resource::find( $r->id() ), $owner );
		$this->assertStringNotContainsString( 'data-vm-reveal', $this->panel( Resource::find( $r->id() ), $owner ) );

		// Nor does a password that is not on the VM yet.
		Access::reset_password( Resource::find( $r->id() ) );
		$this->assertStringNotContainsString( 'data-vm-reveal', $this->panel( Resource::find( $r->id() ), $owner ) );
	}

	public function test_render_account_makes_no_proxmox_call(): void {
		list( $r, $owner ) = $this->ready();
		$seen              = count( $this->fake->calls() );

		$html = $this->panel( $r, $owner );
		ob_start();
		( new ProxmoxDriver() )->render_admin( $r );
		$admin = (string) ob_get_clean();

		$this->assertNotSame( '', $html );
		$this->assertCount( $seen, $this->fake->calls() );
		$this->assertStringContainsString( 'beltoft-services:', $admin );
		$this->assertStringContainsString( (string) $r->get( 'vmid' ), $admin );
		$this->assertStringNotContainsString( (string) Crypto::decrypt( (string) $r->get( 'reveal' ) ), $admin );
	}

	public function test_the_panel_of_a_paused_service_has_no_forms_or_buttons(): void {
		list( $r, $owner ) = $this->ready();
		$r->set( array( 'state' => 'paused' ) );
		$r->desire( 'paused', 'test' );

		$html = $this->panel( Resource::find( $r->id() ), $owner );

		$this->assertStringNotContainsString( 'beltoft-services-vm-form', $html );
		$this->assertStringNotContainsString( 'beltoft-services-action"', $html );
	}

	public function test_the_vm_script_and_the_filters_are_registered(): void {
		$this->assertNotFalse( has_action( 'beltoft_services_account_assets' ) );
		$this->assertNotFalse( has_filter( 'beltoft_services_account_buttons' ) );
		$this->assertNotFalse( has_filter( 'beltoft_services_action_refusal' ) );
		$this->assertFileExists( BELTOFT_SERVICES_PATH . 'assets/vm.js' );
		$this->assertFileExists( BELTOFT_SERVICES_PATH . 'templates/account/vm.php' );
	}
}
